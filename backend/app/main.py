import os
import uuid
import shlex
import pathlib
import shutil
import subprocess

from fastapi import FastAPI, HTTPException, Depends
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from sqlalchemy import text, inspect
from sqlalchemy.orm import Session

from .db import Base, engine, SessionLocal, Ambiente

app = FastAPI(title="Executor Web", version="1.3.1")

# CORS (ajuste origens em produção)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- Tabelas e migração leve ---
Base.metadata.create_all(bind=engine)

# Migração robusta: adiciona coluna 'name' se ainda não existir e NUNCA derruba o app
try:
    with engine.begin() as conn:
        insp = inspect(engine)
        cols = [c["name"] for c in insp.get_columns("ambientes")]
        if "name" not in cols:
            conn.execute(text("ALTER TABLE ambientes ADD COLUMN name VARCHAR(100) NULL"))
except Exception:
    # mantemos o serviço de pé mesmo que a migração falhe
    pass

# --- Paths ---
AMBIENTES_ROOT = pathlib.Path(os.getenv("AMBIENTES_ROOT", "/var/run/app/ambientes"))
AMBIENTES_ROOT.mkdir(parents=True, exist_ok=True)

# --- DB dependency ---
def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()

# -------- Schemas --------
class CriarAmbienteIn(BaseModel):
    name: str | None = Field(None, max_length=100, description="Nome do ambiente (opcional)")
    command: str = Field(..., min_length=1, description="Ex.: 'python3 script.py'")
    cpu_quota: str = Field("100%", description="Ex.: '50%'")
    mem_max:   str = Field("256M", description="Ex.: '512M'")

class AmbienteOut(BaseModel):
    id: str
    name: str | None
    command: str
    cpu_quota: str
    mem_max: str
    status: str
    exit_code: str | None = None
    class Config:
        from_attributes = True  # Pydantic v2

# -------- Helpers --------
def run_cmd(cmd: str) -> str:
    p = subprocess.run(cmd, shell=True, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if p.returncode != 0:
        raise RuntimeError(p.stderr.strip() or p.stdout.strip())
    return p.stdout.strip()

def systemd_show(unit: str) -> dict:
    out = run_cmd(f"sudo systemctl show {shlex.quote(unit)} --no-page")
    data = {}
    for line in out.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            data[k] = v
    return data

def unit_status(unit: str) -> tuple[str, str]:
    try:
        data = systemd_show(unit)
        return data.get("SubState", "unknown"), data.get("ExecMainStatus", "")
    except Exception:
        return "unknown", ""

def normalize_status(sub: str, code: str) -> str:
    if sub in ("running", "start"):
        return "running"
    if sub in ("dead", "exited"):
        return "finished" if code == "0" else "failed"
    if sub in ("failed",):
        return "failed"
    return sub or "unknown"

# -------- Endpoints --------
@app.get("/health")
def health(db: Session = Depends(get_db)):
    db.execute(text("SELECT 1"))
    return {"status": "ok"}

@app.post("/ambientes", response_model=AmbienteOut, status_code=201)
def criar_ambiente(payload: CriarAmbienteIn, db: Session = Depends(get_db)):
    aid = str(uuid.uuid4())
    unit = f"app-amb-{aid}"

    amb_dir = AMBIENTES_ROOT / aid
    amb_dir.mkdir(parents=True, exist_ok=True)
    stdout_path = amb_dir / "stdout.txt"

    inner = shlex.quote(payload.command)
    unshare_cmd = f"unshare -pf --mount-proc /usr/bin/bash -lc {inner}"
    full = (
        f"sudo systemd-run --unit={unit} "
        f"-p CPUQuota={payload.cpu_quota} -p MemoryMax={payload.mem_max} "
        f"-p PrivateTmp=yes -p PrivateNetwork=yes "
        f"-p StandardOutput=file:{stdout_path} -p StandardError=inherit "
        f"/usr/bin/bash -lc {shlex.quote(unshare_cmd)}"
    )
    try:
        run_cmd(full)
    except Exception as e:
        raise HTTPException(500, f"Falha ao iniciar ambiente: {e}")

    amb = Ambiente(
        id=aid,
        name=(payload.name or None),
        command=payload.command,
        cpu_quota=payload.cpu_quota,
        mem_max=payload.mem_max,
        unit_name=unit,
        status="running",
        stdout_path=str(stdout_path),
    )
    db.add(amb)
    db.commit()
    return AmbienteOut.from_orm(amb)

@app.get("/ambientes", response_model=list[AmbienteOut])
def listar_ambientes(db: Session = Depends(get_db)):
    itens: list[AmbienteOut] = []
    for a in db.query(Ambiente).order_by(Ambiente.created_at.desc()).all():
        sub, code = unit_status(a.unit_name)
        a.status = normalize_status(sub, code)
        a.exit_code = code or a.exit_code
        itens.append(AmbienteOut.from_orm(a))
    db.commit()
    return itens

@app.get("/ambientes/{amb_id}", response_model=AmbienteOut)
def obter_ambiente(amb_id: str, db: Session = Depends(get_db)):
    a = db.get(Ambiente, amb_id)
    if not a:
        raise HTTPException(404, "Ambiente não encontrado")
    sub, code = unit_status(a.unit_name)
    a.status = normalize_status(sub, code)
    a.exit_code = code or a.exit_code
    db.commit()
    return AmbienteOut.from_orm(a)

@app.get("/ambientes/{amb_id}/logs")
def logs_ambiente(amb_id: str, db: Session = Depends(get_db)):
    a = db.get(Ambiente, amb_id)
    if not a:
        raise HTTPException(404, "Ambiente não encontrado")
    p = pathlib.Path(a.stdout_path or "")
    if not p.is_file():
        return {"stdout": ""}
    return {"stdout": p.read_text(errors="replace")[-10000:]}

@app.delete("/ambientes/{amb_id}")
def parar_ambiente(amb_id: str, db: Session = Depends(get_db)):
    a = db.get(Ambiente, amb_id)
    if not a:
        raise HTTPException(404, "Ambiente não encontrado")
    try:
        run_cmd(f"sudo systemctl stop {shlex.quote(a.unit_name)}")
        run_cmd(f"sudo systemctl reset-failed {shlex.quote(a.unit_name)} || true")
    except Exception:
        pass
    a.status = "stopped"
    db.commit()
    return {"ok": True}

@app.delete("/ambientes/{amb_id}/purge")
def excluir_ambiente(amb_id: str, db: Session = Depends(get_db)):
    a = db.get(Ambiente, amb_id)
    if not a:
        raise HTTPException(404, "Ambiente não encontrado")

    # Para a unit (se existir)
    try:
        run_cmd(f"sudo systemctl stop {shlex.quote(a.unit_name)}")
        run_cmd(f"sudo systemctl reset-failed {shlex.quote(a.unit_name)} || true")
    except Exception:
        pass

    # Remove pasta/logs
    try:
        if a.stdout_path:
            amb_dir = pathlib.Path(a.stdout_path).parent
            if amb_dir.is_dir() and (AMBIENTES_ROOT in amb_dir.parents or amb_dir == AMBIENTES_ROOT):
                shutil.rmtree(amb_dir, ignore_errors=True)
    except Exception:
        pass

    db.delete(a)
    db.commit()
    return {"deleted": True}
