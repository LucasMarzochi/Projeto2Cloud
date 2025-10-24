<?php
// Resposta em JSON + conexão
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

// ID obrigatório
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { 
  http_response_code(400); 
  echo json_encode(['ok'=>false,'error'=>'id inválido']); 
  exit; 
}

// Busca a unit do systemd para este ambiente
$stmt = db()->prepare("SELECT unit_name FROM ambientes WHERE id=?");
$stmt->execute([$id]);
$unit = $stmt->fetchColumn();
if (!$unit) { 
  http_response_code(404); 
  echo json_encode(['ok'=>false,'error'=>'ambiente não encontrado']); 
  exit; 
}

// Tenta parar a unit (systemctl stop)
// OBS: se já estiver parada, rc pode ser !=0 (hoje tratamos como erro)
$out=[]; $rc=0;
exec("sudo /usr/bin/systemctl stop ".escapeshellarg($unit)." 2>&1", $out, $rc);
if ($rc !== 0) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'falha ao parar','debug'=>implode("\n",$out)]);
  exit;
}

// Marca como 'finished' no banco e retorna OK
db()->prepare("UPDATE ambientes SET status='finished' WHERE id=?")->execute([$id]);
echo json_encode(['ok'=>true]);
