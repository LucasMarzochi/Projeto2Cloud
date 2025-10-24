<?php
// JSON na resposta + conexão
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

// Lê todos os ambientes (mais recentes primeiro)
$rows = db()->query("
  SELECT id,name,command,cpu_pct,mem_mb,io_class,unit_name,pid,status,created_at
  FROM ambientes
  ORDER BY created_at DESC
")->fetchAll();

foreach ($rows as &$r) {
  $unit = $r['unit_name'];
  $r['exit_code'] = null; // incluímos no payload para o front
  if (!$unit) continue;

  // Consulta estado da unit no systemd
  $out=[]; $rc=0;
  exec("sudo /usr/bin/systemctl is-active ".escapeshellarg($unit)." 2>&1", $out, $rc);
  $active = trim($out[0] ?? '');

  if ($active === 'active' || $active === 'activating') {
    // Ainda rodando → garante PID atualizado
    $newStatus = 'running';
    $o=[]; $rcm=0;
    exec("sudo /usr/bin/systemctl show ".escapeshellarg($unit)." --property=MainPID --value 2>&1", $o, $rcm);
    $r['pid'] = (int)trim($o[0] ?? ($r['pid'] ?? 0));
  } else {
    // Finalizado → pega exit code (0=sucesso, ≠0=erro)
    $o=[]; $rcm=0;
    exec("sudo /usr/bin/systemctl show ".escapeshellarg($unit)." --property=ExecMainStatus --value 2>&1", $o, $rcm);
    $code = (int)trim($o[0] ?? '0');
    $r['exit_code'] = $code;
    $newStatus = ($code === 0) ? 'finished' : 'error';
  }

  // Sincroniza status/PID no banco se mudou
  if ($newStatus !== $r['status']) {
    $stmt = db()->prepare("UPDATE ambientes SET status=?, pid=? WHERE id=?");
    $stmt->execute([$newStatus, $r['pid'], $r['id']]);
    $r['status'] = $newStatus;
  }
}

// Retorna a lista completa para o dashboard
echo json_encode(['ok'=>true, 'data'=>$rows]);
