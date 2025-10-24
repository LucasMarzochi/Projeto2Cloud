<?php
/**
 * Conexão PDO singleton (MySQL → cloudmgr/cloud).
 * Reutiliza a mesma conexão durante a requisição.
 */

function db() {
  static $pdo = null;                // cache local da conexão
  if ($pdo) return $pdo;             // já existe? retorna

  // DSN + credenciais + opções seguras/úteis
  $pdo = new PDO(
    'mysql:host=localhost;dbname=cloudmgr;charset=utf8mb4',
    'cloud',
    'cloud',
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // exceptions em erros
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch como array associativo
    ]
  );

  return $pdo;
}
