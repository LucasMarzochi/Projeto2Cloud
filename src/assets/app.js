const API_BASE = '/api/';

async function fetchJSON(path, opts) {
  const r = await fetch(API_BASE + path, opts);
  const text = await r.text();
  let j;
  try { j = JSON.parse(text); } catch {
    throw new Error(`Resposta inválida: ${text.slice(0, 300)}...`);
  }
  if (!r.ok || j.ok === false) {
    const msg = j.error ? (j.debug ? `${j.error}\n\n${j.debug}` : j.error) : 'Falha';
    throw new Error(msg);
  }
  return j;
}

function brDate(s){ const d=new Date(s.replace(' ','T')); return d.toLocaleString('pt-BR'); }

async function loadMetrics(){
  try{
    const j = await fetchJSON('metrics.php');
    const cpuPct = Math.min(100, Math.max(0, j.cpu_percent));
    const memPct = j.mem.total_mb ? (j.mem.used_mb / j.mem.total_mb * 100) : 0;
    cpuBar.style.width = cpuPct.toFixed(1) + '%';
    cpuTxt.textContent = cpuPct.toFixed(1) + '%';
    memBar.style.width = memPct.toFixed(1) + '%';
    memTxt.textContent = `${j.mem.used_mb} MB / ${j.mem.total_mb} MB`;
  }catch{}
}

function statusBadge(row){
  const s = row.status, ec = row.exit_code;
  if (s==='running')  return '<span class="badge run">Running</span>';
  if (s==='finished') return '<span class="badge ok">Finished</span>';
  // erro: mostra código, se houver
  return `<span class="badge err">Error${Number.isInteger(ec) ? ` (${ec})` : ''}</span>`;
}

async function loadTable(){
  const tbody = document.querySelector('#envTable tbody');
  const errBox = document.getElementById('errBox');
  errBox.style.display='none';
  tbody.innerHTML = '<tr><td colspan="9">Carregando...</td></tr>';
  try{
    const j = await fetchJSON('status.php');
    tbody.innerHTML='';
    if(!j.data.length){ tbody.innerHTML = '<tr><td colspan="9">Nenhum ambiente ainda.</td></tr>'; }
    j.data.forEach(r=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `
        <td>${r.name}</td>
        <td>${r.pid || '-'}</td>
        <td>${statusBadge(r)}</td>
        <td><code>${r.command}</code></td>
        <td>${r.cpu_pct ? r.cpu_pct + '%' : 'N/A'}</td>
        <td>${r.mem_mb ? r.mem_mb + ' MB' : 'N/A'}</td>
        <td>${r.io_class || 'N/A'}</td>
        <td>${brDate(r.created_at)}</td>
        <td class="actions">
          <button data-act="log" data-id="${r.id}" data-name="${r.name}" data-status="${r.status}" data-exit="${r.exit_code ?? ''}">Log</button>
          <button data-act="stop" data-id="${r.id}" ${r.status!=='running'?'disabled':''}>Parar</button>
          <button data-act="delete" data-id="${r.id}" class="danger">Excluir</button>
        </td>`;
      tbody.appendChild(tr);
    });
  }catch(e){
    errBox.textContent = "Erro: " + e.message;
    errBox.style.display='block';
    tbody.innerHTML='';
  }
}

/* Drawer de log */
let logTimer = null;
function openLog(id, name, status, exitCode){
  const title = document.getElementById('logTitle');
  const statusLabel = status === 'error'
    ? `Error${exitCode !== '' ? ` (${exitCode})` : ''}`
    : (status === 'finished' ? 'Finished' : 'Running');
  title.textContent = `Log – ${name} (id ${id}) • ${statusLabel}`;

  document.getElementById('logBody').textContent = '(carregando...)';
  document.getElementById('logDrawer').classList.remove('hidden');
  document.getElementById('backdrop').classList.remove('hidden');

  const pull = async () => {
    try{
      const r = await fetch(API_BASE + 'log.php?id=' + encodeURIComponent(id));
      const t = await r.text();
      const el = document.getElementById('logBody');
      el.textContent = t || '(sem conteúdo)';
      el.scrollTop = el.scrollHeight;
    }catch(e){
      document.getElementById('logBody').textContent = 'Erro ao ler log: ' + e.message;
    }
  };
  pull();
  logTimer = setInterval(pull, 2000);
}
function closeLog(){
  clearInterval(logTimer); logTimer=null;
  document.getElementById('logDrawer').classList.add('hidden');
  document.getElementById('backdrop').classList.add('hidden');
}
document.getElementById('logClose').addEventListener('click', closeLog);
document.getElementById('backdrop').addEventListener('click', closeLog);

/* Ações */
document.addEventListener('click', async ev=>{
  const btn = ev.target.closest('button[data-act]');
  if(!btn) return;
  const id = btn.getAttribute('data-id');
  const act = btn.getAttribute('data-act');
  if(act==='log'){
    openLog(id, btn.getAttribute('data-name') || 'ambiente', btn.getAttribute('data-status') || '', btn.getAttribute('data-exit') || '');
    return;
  }
  if(act==='stop'){
    btn.disabled=true;
    try{ await fetchJSON('stop.php',{method:'POST', body:new URLSearchParams({id})}); await loadTable(); }
    catch(e){ alert(e.message); }
    btn.disabled=false; return;
  }
  if(act==='delete'){
    if(!confirm('Excluir este ambiente? Isso irá remover o registro e os logs.')) return;
    btn.disabled=true;
    try{ await fetchJSON('delete.php',{method:'POST', body:new URLSearchParams({id})}); await loadTable(); closeLog(); }
    catch(e){ alert('Falha ao excluir: ' + e.message); }
    btn.disabled=false; return;
  }
});

document.getElementById('createEnv').addEventListener('submit', async e=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  try{
    await fetchJSON('create.php', { method:'POST', body: fd });
    e.target.reset();
    await loadTable();
  }catch(e2){ alert('Erro ao criar ambiente: ' + e2.message); }
});

loadMetrics(); loadTable();
setInterval(loadMetrics, 5000);
setInterval(loadTable, 5000);
