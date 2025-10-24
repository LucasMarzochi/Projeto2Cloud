const API_BASE = '/api/';

/**
 * Faz fetch de um endpoint, tenta parsear como JSON,
 * trata erros HTTP e também erros "lógicos" retornados pela API (ok=false).
 * Lança Error com mensagem amigável quando algo dá errado.
 */

async function fetchJSON(path, opts) {
  const r = await fetch(API_BASE + path, opts);
  const text = await r.text();   // lemos o texto para poder inspecionar em caso de erro
  let j;
  try { 
    j = JSON.parse(text);        // tenta converter a resposta em JSON
  } catch {
    // se não for JSON válido, lança um erro
    throw new Error(`Resposta inválida: ${text.slice(0, 300)}...`);
  }

  // trata erros de transporte (HTTP não-OK) ou erros de negócio (ok=false)
  if (!r.ok || j.ok === false) {
    // se a API mandou 'error', montamos a mensagem
    const msg = j.error ? (j.debug ? `${j.error}\n\n${j.debug}` : j.error) : 'Falha';
    throw new Error(msg);
  }
  return j;
}

// Converte "YYYY-MM-DD HH:mm:ss" em data local pt-BR (útil para created_at)
function brDate(s) { 
  const d = new Date(s.replace(' ', 'T')); // troca espaço por T para compatibilidade
  return d.toLocaleString('pt-BR'); 
}

/**
 * Carrega métricas de CPU/Memória da VM (não por ambiente),
 * atualiza as barras/labels do dashboard superior.
 */

async function loadMetrics() {
  try {
    const j = await fetchJSON('metrics.php');
    // normaliza CPU para 0..100
    const cpuPct = Math.min(100, Math.max(0, j.cpu_percent));
    // calcula % de memória usada com base em MB
    const memPct = j.mem.total_mb ? (j.mem.used_mb / j.mem.total_mb * 100) : 0;

    // Atualiza DOM das barras e textos (CPU)
    cpuBar.style.width = cpuPct.toFixed(1) + '%';
    cpuTxt.textContent = cpuPct.toFixed(1) + '%';

    // Atualiza DOM das barras e textos (Memória)
    memBar.style.width = memPct.toFixed(1) + '%';
    memTxt.textContent = `${j.mem.used_mb} MB / ${j.mem.total_mb} MB`;
  } catch {
    // Silencioso: se der erro, só não atualiza (evita "piscar" de erro no dashboard)
  }
}

/**
 * Gera o HTML do "badge" de status para cada linha da tabela.
 * - running  → badge verde
 * - finished → badge ok
 * - error    → badge vermelha com exit code (se houver)
 */

function statusBadge(row) {
  const s = row.status, ec = row.exit_code;
  if (s === 'running')  return '<span class="badge run">Running</span>';
  if (s === 'finished') return '<span class="badge ok">Finished</span>';
  // Qualquer outro estado é considerado erro/failed
  return `<span class="badge err">Error${Number.isInteger(ec) ? ` (${ec})` : ''}</span>`;
}

/**
 * Carrega a tabela de ambientes:
 * - mostra "Carregando..."
 * - busca /api/status.php
 * - popula linhas com (nome, pid, status, comando, cpu req, mem req, io, data, ações)
 * - trata erro exibindo uma caixa vermelha no topo do painel
 */

async function loadTable() {
  const tbody = document.querySelector('#envTable tbody');
  const errBox = document.getElementById('errBox');
  errBox.style.display = 'none';
  tbody.innerHTML = '<tr><td colspan="9">Carregando...</td></tr>';

  try {
    const j = await fetchJSON('status.php');
    tbody.innerHTML = '';

    // Se não há ambientes, mostra linha vazia informativa
    if (!j.data.length) {
      tbody.innerHTML = '<tr><td colspan="9">Nenhum ambiente ainda.</td></tr>';
    }

    // Para cada registro recebido, cria uma <tr> preenchida
    j.data.forEach(r => {
      const tr = document.createElement('tr');
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
          <button data-act="stop" data-id="${r.id}" ${r.status!=='running' ? 'disabled' : ''}>Parar</button>
          <button data-act="delete" data-id="${r.id}" class="danger">Excluir</button>
        </td>`;
      tbody.appendChild(tr);
    });
  } catch (e) {
    // Exibe erro no painel e limpa a tabela
    errBox.textContent = "Erro: " + e.message;
    errBox.style.display = 'block';
    tbody.innerHTML = '';
  }
}

/** Lateral do log */
let logTimer = null;

/** Abre o painel de log do ambiente e inicia um polling a cada 2s. */

function openLog(id, name, status, exitCode) {
  const title = document.getElementById('logTitle');

  // Monta label do status para o título do drawer
  const statusLabel = status === 'error'
    ? `Error${exitCode !== '' ? ` (${exitCode})` : ''}`
    : (status === 'finished' ? 'Finished' : 'Running');

  title.textContent = `Log – ${name} (id ${id}) • ${statusLabel}`;

  // Mostra drawer + backdrop e coloca placeholder de "carregando"
  document.getElementById('logBody').textContent = '(carregando...)';
  document.getElementById('logDrawer').classList.remove('hidden');
  document.getElementById('backdrop').classList.remove('hidden');

  // Função que busca o log bruto e faz autoscroll para o final
  const pull = async () => {
    try {
      const r = await fetch(API_BASE + 'log.php?id=' + encodeURIComponent(id));
      const t = await r.text();
      const el = document.getElementById('logBody');
      el.textContent = t || '(sem conteúdo)';
      el.scrollTop = el.scrollHeight; // rola para o fim
    } catch (e) {
      document.getElementById('logBody').textContent = 'Erro ao ler log: ' + e.message;
    }
  };

  pull();                     // primeira carga imediata
  logTimer = setInterval(pull, 2000); // polling a cada 2s
}

/** Fecha o drawer de log e cancela o polling */
function closeLog() {
  clearInterval(logTimer); 
  logTimer = null;
  document.getElementById('logDrawer').classList.add('hidden');
  document.getElementById('backdrop').classList.add('hidden');
}

// Botões de fechar (X e backdrop)
document.getElementById('logClose').addEventListener('click', closeLog);
document.getElementById('backdrop').addEventListener('click', closeLog);

document.addEventListener('click', async ev => {
  // Usa event delegation para capturar cliques em qualquer botão com data-act
  const btn = ev.target.closest('button[data-act]');
  if (!btn) return;

  const id  = btn.getAttribute('data-id');
  const act = btn.getAttribute('data-act');

  // Ação: abrir log (não mexe no backend; apenas abre o drawer com polling)
  if (act === 'log') {
    openLog(
      id, 
      btn.getAttribute('data-name') || 'ambiente', 
      btn.getAttribute('data-status') || '', 
      btn.getAttribute('data-exit') || ''
    );
    return;
  }

  // Ação: parar ambiente
  if (act === 'stop') {
    btn.disabled = true;
    try {
      await fetchJSON('stop.php', { method: 'POST', body: new URLSearchParams({ id }) });
      await loadTable(); // recarrega a tabela para refletir o novo status
    } catch (e) {
      alert(e.message);
    }
    btn.disabled = false;
    return;
  }

  // Ação: excluir ambiente (confirmação + chamada + refresh + fecha drawer)
  if (act === 'delete') {
    if (!confirm('Excluir este ambiente? Isso irá remover o registro e os logs.')) return;
    btn.disabled = true;
    try {
      await fetchJSON('delete.php', { method: 'POST', body: new URLSearchParams({ id }) });
      await loadTable();
      closeLog(); // se o drawer estiver aberto, fecha
    } catch (e) {
      alert('Falha ao excluir: ' + e.message);
    }
    btn.disabled = false;
    return;
  }
});

// Manipulador do formulário de criação de ambiente
document.getElementById('createEnv').addEventListener('submit', async e => {
  e.preventDefault();                 // evita submit tradicional
  const fd = new FormData(e.target);  // coleta campos (name, command, cpu_pct, mem_mb, io_class)

  try {
    await fetchJSON('create.php', { method: 'POST', body: fd });
    e.target.reset();                 // limpa formulário
    await loadTable();                // atualiza a lista
  } catch (e2) {
    alert('Erro ao criar ambiente: ' + e2.message);
  }
});

// Carrega uma vez no início
loadMetrics(); 
loadTable();

// Atualiza a cada 5s (dashboard + tabela)
// Obs: se quiser “live” mais rápido, reduza para 1000 ou use requestAnimationFrame com backoff.
setInterval(loadMetrics, 5000);
setInterval(loadTable,   5000);
