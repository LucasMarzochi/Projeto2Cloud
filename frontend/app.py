import os
import requests
import streamlit as st

API = os.getenv("FASTAPI_URL", "http://localhost:8000")

st.set_page_config(page_title="Executor Web", layout="wide")
st.title("Executor Web • Namespaces + Cgroups (systemd)")

# Sidebar: Health
with st.sidebar:
    st.subheader("Status da API")
    try:
        resp = requests.get(f"{API}/health", timeout=3)
        ok = resp.ok and resp.json().get("status") == "ok"
        if ok:
            st.success("API OK")
        else:
            st.error("API fora do ar")
    except Exception as e:
        st.error(f"Falha: {e}")

def fetch_ambientes():
    try:
        r = requests.get(f"{API}/ambientes", timeout=5)
        if r.ok:
            return r.json()
    except Exception:
        pass
    return []

# Criar novo ambiente
st.header("Criar novo ambiente")
with st.form("new-amb"):
    name = st.text_input("Nome do ambiente (opcional)", value="")
    cmd_default = "python3 -c \"import time; [print(i) or time.sleep(1) for i in range(5)]\""
    command = st.text_input("Comando", value=cmd_default)
    col1, col2 = st.columns(2)
    with col1:
        cpu = st.text_input("CPUQuota", value="50%")
    with col2:
        mem = st.text_input("MemoryMax", value="256M")
    if st.form_submit_button("Executar"):
        try:
            payload = {"name": (name or None), "command": command, "cpu_quota": cpu, "mem_max": mem}
            r = requests.post(f"{API}/ambientes", json=payload, timeout=15)
            if r.ok:
                st.success(f"Ambiente criado: {r.json()['id']}")
                st.rerun()
            else:
                st.error(r.text)
        except Exception as e:
            st.error(f"Erro: {e}")

# Listar ambientes
st.header("Ambientes")
ambientes = fetch_ambientes()
if not ambientes:
    st.info("Nenhum ambiente encontrado.")
else:
    for a in ambientes:
        display_name = a.get("name") or a["id"]
        label = f"{display_name} • {a['status']} • exit={a.get('exit_code')} (id={a['id']})"
        with st.expander(label):
            if a.get("name"):
                st.text(f"Nome: {a['name']}")
            st.code(a["command"])
            c1, c2, c3 = st.columns(3)
            c1.metric("CPUQuota", a["cpu_quota"])
            c2.metric("MemoryMax", a["mem_max"])
            c3.metric("Status", a["status"])

            colA, colB, colC = st.columns(3)

            if colA.button("Ver logs", key=f"log-{a['id']}"):
                try:
                    r = requests.get(f"{API}/ambientes/{a['id']}/logs", timeout=10)
                    if r.ok:
                        st.text(r.json().get("stdout", ""))
                    else:
                        st.error(r.text)
                except Exception as e:
                    st.error(f"Erro ao buscar logs: {e}")

            if colB.button("Encerrar", type="secondary", key=f"stop-{a['id']}"):
                try:
                    r = requests.delete(f"{API}/ambientes/{a['id']}", timeout=10)
                    if r.ok:
                        st.success("Encerrado!")
                        st.rerun()
                    else:
                        st.error(r.text)
                except Exception as e:
                    st.error(f"Erro ao encerrar: {e}")

            if colC.button("Excluir (apagar tudo)", key=f"purge-{a['id']}"):
                try:
                    r = requests.delete(f"{API}/ambientes/{a['id']}/purge", timeout=15)
                    if r.ok and r.json().get("deleted"):
                        st.success("Excluído com sucesso.")
                        st.rerun()
                    else:
                        st.error(r.text)
                except Exception as e:
                    st.error(f"Erro ao excluir: {e}")

st.caption(f"Backend: {API}")
