<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id inválido']); exit; }

// Busca info do ambiente
$stmt = db()->prepare("SELECT name, unit_name FROM ambientes WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'ambiente não encontrado']); exit; }

$unit = $row['unit_name'];
$name = $row['name'];

// Tenta parar a unit (se existir)
if ($unit) {
  $out=[];$rc=0; exec("sudo /usr/bin/systemctl stop ".escapeshellarg($unit)." 2>&1", $out, $rc);
  // mesmo se rc!=0, seguimos; é comum ela já ter finalizado
}

// Remove logs relacionados (mesma heurística do log.php)
$base = '/var/cloudmgr/logs';
$prefix = preg_replace('/[^a-zA-Z0-9_-]/','_', $name).'_';
if (is_dir($base) && ($h = opendir($base))) {
  while (($f = readdir($h)) !== false) {
    if (strpos($f, $prefix) === 0) @unlink($base.'/'.$f);
  }
  closedir($h);
}

// Remove do banco
db()->prepare("DELETE FROM ambientes WHERE id=?")->execute([$id]);

echo json_encode(['ok'=>true]);
