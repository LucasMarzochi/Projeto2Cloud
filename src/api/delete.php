<?php
// Resposta JSON + conexão
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

// Valida ID recebido
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { 
  http_response_code(400); 
  echo json_encode(['ok'=>false,'error'=>'id inválido']); 
  exit; 
}

// Busca dados do ambiente (nome + unit do systemd)
$stmt = db()->prepare("SELECT name, unit_name FROM ambientes WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { 
  http_response_code(404); 
  echo json_encode(['ok'=>false,'error'=>'ambiente não encontrado']); 
  exit; 
}

$unit = $row['unit_name'];
$name = $row['name'];

// Tenta parar a unit do systemd (se existir). Se já terminou, ignoramos erro.
if ($unit) {
  $out=[]; $rc=0;
  exec("sudo /usr/bin/systemctl stop ".escapeshellarg($unit)." 2>&1", $out, $rc);
  // mesmo com $rc!=0 seguimos adiante
}

// Remove arquivos de log do ambiente (por prefixo baseado no nome)
$base   = '/var/cloudmgr/logs';
$prefix = preg_replace('/[^a-zA-Z0-9_-]/','_', $name).'_';
if (is_dir($base) && ($h = opendir($base))) {
  while (($f = readdir($h)) !== false) {
    if (strpos($f, $prefix) === 0) @unlink($base.'/'.$f);
  }
  closedir($h);
}

// Apaga o registro no banco
db()->prepare("DELETE FROM ambientes WHERE id=?")->execute([$id]);

// OK
echo json_encode(['ok'=>true]);
