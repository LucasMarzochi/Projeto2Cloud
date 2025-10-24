<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

$rows = db()->query("SELECT id,name,command,cpu_pct,mem_mb,io_class,unit_name,pid,status,created_at FROM ambientes ORDER BY created_at DESC")->fetchAll();

foreach ($rows as &$r) {
  $unit = $r['unit_name']; 
  $r['exit_code'] = null; // novo campo no payload
  if (!$unit) continue;

  // status atual da unit
  $out=[]; $rc=0;
  exec("sudo /usr/bin/systemctl is-active ".escapeshellarg($unit)." 2>&1", $out, $rc);
  $active = trim($out[0] ?? '');

  if ($active === 'active' || $active === 'activating') {
    // rodando
    $newStatus = 'running';
    $o=[]; $rcm=0;
    exec("sudo /usr/bin/systemctl show ".escapeshellarg($unit)." --property=MainPID --value 2>&1", $o, $rcm);
    $r['pid'] = (int)trim($o[0] ?? ($r['pid'] ?? 0));
  } else {
    // terminou: capturar exit code
    $o=[]; $rcm=0;
    exec("sudo /usr/bin/systemctl show ".escapeshellarg($unit)." --property=ExecMainStatus --value 2>&1", $o, $rcm);
    $code = (int)trim($o[0] ?? '0');
    $r['exit_code'] = $code;
    $newStatus = ($code === 0) ? 'finished' : 'error';
  }

  if ($newStatus !== $r['status']) {
    $stmt = db()->prepare("UPDATE ambientes SET status=?, pid=? WHERE id=?");
    $stmt->execute([$newStatus, $r['pid'], $r['id']]);
    $r['status'] = $newStatus;
  }
}

echo json_encode(['ok'=>true, 'data'=>$rows]);
