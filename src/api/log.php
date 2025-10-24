<?php
// Retorna texto puro (mostramos o log diretamente)
header('Content-Type: text/plain; charset=utf-8');
require __DIR__.'/db.php';

// Valida ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "id inválido\n"; exit; }

// Pega o nome do ambiente (para localizar o arquivo de log)
$r = db()->prepare("SELECT name FROM ambientes WHERE id=?");
$r->execute([$id]);
$row = $r->fetch();
if (!$row) { http_response_code(404); echo "ambiente não encontrado\n"; exit; }

// Descobre o arquivo de log mais recente com prefixo baseado no nome
$base   = '/var/cloudmgr/logs';
$prefix = preg_replace('/[^a-zA-Z0-9_-]/','_', $row['name']).'_';
$files  = array_values(array_filter(@scandir($base) ?: [], fn($f)=> str_starts_with($f,$prefix)));
sort($files);
$logFile = $files ? $base.'/'.end($files) : null;

if (!$logFile || !is_readable($logFile)) { echo "log não encontrado\n"; exit; }

// Mostra as últimas N linhas (tail) do log
$lines = 300;
echo shell_exec('tail -n '.intval($lines).' '.escapeshellarg($logFile));
