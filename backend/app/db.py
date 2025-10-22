import os
import datetime as dt
from sqlalchemy import create_engine, Integer, String, DateTime, Text
from sqlalchemy.orm import sessionmaker, declarative_base, Mapped, mapped_column

DATABASE_URL = os.getenv(
    "DATABASE_URL",
    "mysql+pymysql://appuser:appsecret@127.0.0.1:3306/appdb",
)

engine = create_engine(DATABASE_URL, pool_pre_ping=True, future=True)
SessionLocal = sessionmaker(bind=engine, autocommit=False, autoflush=False, future=True)
Base = declarative_base()

class Ambiente(Base):
    __tablename__ = "ambientes"

    id: Mapped[str] = mapped_column(String(36), primary_key=True)  # UUID
    created_at: Mapped[dt.datetime] = mapped_column(DateTime, default=dt.datetime.utcnow, nullable=False)

    # Nome amigável (opcional)
    name: Mapped[str | None] = mapped_column(String(100), nullable=True)

    command: Mapped[str] = mapped_column(Text, nullable=False)
    cpu_quota: Mapped[str] = mapped_column(String(16), default="100%")
    mem_max:   Mapped[str] = mapped_column(String(32), default="256M")
    unit_name: Mapped[str] = mapped_column(String(64), index=True)
    status: Mapped[str] = mapped_column(String(24), default="queued")
    exit_code: Mapped[str | None] = mapped_column(String(8), nullable=True)
    stdout_path: Mapped[str | None] = mapped_column(String(255), nullable=True)
