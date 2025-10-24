<?php /* Dashboard + Criar Ambiente + Log viewer */ ?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <title>Projeto 2 - Cloud</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <aside class="sidebar">
    <h2>Projeto 2 - Cloud</h2>
    <nav>
      <a class="active" href="#">Dashboard</a>
      <a href="#create">+ Criar Ambiente</a>
    </nav>
  </aside>

  <main class="content">
    <h1>Dashboard de Performance</h1>

    <section class="cards">
      <div class="card">
        <h3>Uso Total de CPU</h3>
        <div class="meter"><div id="cpuBar" class="bar" style="width:0%"></div></div>
        <div class="hint"><span id="cpuTxt">0.0%</span> da VM</div>
      </div>
      <div class="card">
        <h3>Uso Total de Memória</h3>
        <div class="meter"><div id="memBar" class="bar" style="width:0%"></div></div>
        <div class="hint"><span id="memTxt">0 MB / 0 MB</span></div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h2>Ambientes Ativos</h2>
        <div id="errBox" class="error" style="display:none"></div>
      </div>
      <table class="grid" id="envTable">
        <thead>
          <tr>
            <th>Nome</th><th>PID</th><th>Status</th><th>Comando</th>
            <th>CPU (req)</th><th>Mem (req)</th><th>IO</th><th>Data</th><th>Ações</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </section>

    <section id="create" class="panel">
      <h2>Criar Ambiente</h2>
      <form id="createEnv" class="form">
        <label>Nome
          <input name="name" maxlength="80" required>
        </label>
        <label>Comando
          <input name="command" placeholder="ex: ping -c 30 google.com" required>
        </label>

        <div class="grid-3">
          <label>CPU (%)
            <input type="number" name="cpu_pct" min="1" max="100" placeholder="ex: 50">
          </label>
          <label>Memória (MB)
            <input type="number" name="mem_mb" min="32" step="32" placeholder="ex: 256">
          </label>
          <label>IO
            <select name="io_class">
              <option value="normal" selected>Normal</option>
              <option value="best">Best Effort</option>
              <option value="idle">Idle</option>
            </select>
          </label>
        </div>

        <button type="submit">Criar Ambiente</button>
      </form>
    </section>
  </main>

  <!-- Log viewer (painel lateral) -->
  <div id="logDrawer" class="drawer hidden">
    <div class="drawer-head">
      <strong id="logTitle">Log</strong>
      <button id="logClose">Fechar</button>
    </div>
    <pre id="logBody" class="logpre">(sem dados)</pre>
  </div>
  <div id="backdrop" class="backdrop hidden"></div>

  <script src="/assets/app.js"></script>
</body>
</html>
