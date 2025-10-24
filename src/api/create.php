<?php
// JSON na resposta e DB helper
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

// Entrada do formulário
$name    = trim($_POST['name'] ?? '');
$command = trim($_POST['command'] ?? '');
$cpu     = isset($_POST['cpu_pct']) ? (int)$_POST['cpu_pct'] : null;
$mem     = isset($_POST['mem_mb'])  ? (int)$_POST['mem_mb']  : null;
$io      = trim($_POST['io_class'] ?? 'normal');

// Validação mínima
if ($name === '' || $command === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Nome e comando são obrigatórios.']); exit;
}

// Monta propriedades de cgroup (CPU/mem) para o systemd-run
$props = [];
if (!empty($cpu) && $cpu > 0 && $cpu <= 100) $props[] = "CPUQuota={$cpu}%";
if (!empty($mem) && $mem > 0)                 $props[] = "MemoryMax={$mem}M";

// Arquivo de log (um por ambiente)
$logDir  = '/var/cloudmgr/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir.'/'.preg_replace('/[^a-zA-Z0-9_-]/','_', $name).'_'.time().'.log';

// Nome da unit transitória do systemd (única por timestamp)
$unit = 'ambiente-'.preg_replace('/[^a-zA-Z0-9_-]/','-', $name).'-'.time().'.service';

// Comando real: unshare isola PID/UTS/IPC/MOUNT e redireciona logs
// (mantemos rede do host: sem --net)
$exec_cmd = "unshare --fork --pid --mount-proc --uts --ipc --mount ".
            "bash -lc ".escapeshellarg("hostname {$name}; exec {$command} >> {$logFile} 2>&1");

// Junta propriedades para o systemd-run
$propStr = '';
foreach ($props as $p) $propStr .= " --property=" . escapeshellarg($p);

// Lança como serviço transitório (systemd-run) e coleta saída/rc
$systemdRun = "sudo /usr/bin/systemd-run --unit=".escapeshellarg($unit)."{$propStr} --collect ".
              "/bin/bash -lc " . escapeshellarg($exec_cmd);

$out = []; $rc = 0;
exec($systemdRun." 2>&1", $out, $rc);
if ($rc !== 0) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>"Falha ao criar ambiente",'debug'=>implode("\n",$out)]);
  exit;
}

// Descobre o MainPID da unit (para ajustar IO e exibir)
$outPid = []; $rc2 = 0;
exec("sudo /usr/bin/systemctl show ".escapeshellarg($unit)." --property=MainPID --value 2>&1", $outPid, $rc2);
$pid = (int)trim($outPid[0] ?? '0');

// Ajusta prioridade de IO (ionice) conforme seleção
if ($pid > 0) {
  $ionCmd = 'sudo /usr/bin/ionice -c2 -n4 -p '.$pid;          // normal
  if     ($io === 'idle') $ionCmd = 'sudo /usr/bin/ionice -c3 -p '.$pid;
  elseif ($io === 'best') $ionCmd = 'sudo /usr/bin/ionice -c2 -n0 -p '.$pid;
  exec($ionCmd." 2>&1");
}

// Persiste no banco (começa como running)
$stmt = db()->prepare(
  "INSERT INTO ambientes (name, command, cpu_pct, mem_mb, io_class, unit_name, pid, status)
   VALUES (?,?,?,?,?,?,?, 'running')"
);
$stmt->execute([$name, $command, $cpu ?: null, $mem ?: null, $io ?: null, $unit, $pid]);

// Resposta OK
echo json_encode(['ok'=>true, 'id'=>db()->lastInsertId(), 'unit'=>$unit, 'pid'=>$pid, 'log'=>$logFile]);
