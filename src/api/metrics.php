<?php
// Resposta em JSON
header('Content-Type: application/json; charset=utf-8');

// Nº de CPUs usados para normalizar o load (ajuste se a VM mudar)
$cores = 2; // igual ao provider

// CPU% aproximado via load average de 1 min
$load  = function_exists('sys_getloadavg') ? sys_getloadavg() : [0];
$cpu_percent = isset($load[0])
  ? min(100, max(0, ($load[0] / max(1, $cores)) * 100))
  : 0;

// Memória: lê /proc/meminfo (kB) e calcula usada/total (MB)
$meminfo = @file_get_contents('/proc/meminfo');
$total_kb = $avail_kb = 0;
if ($meminfo) {
  if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) $total_kb = (int)$m[1];
  if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) $avail_kb = (int)$m[1];
}
$used_mb  = max(0, (int)round(($total_kb - $avail_kb) / 1024));
$total_mb = (int)round($total_kb / 1024);

// Saída JSON simples para o dashboard
echo json_encode([
  'ok' => true,
  'cpu_percent' => round($cpu_percent, 1),
  'mem' => ['used_mb' => $used_mb, 'total_mb' => $total_mb]
]);
