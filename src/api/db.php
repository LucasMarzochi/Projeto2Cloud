<?php
function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('mysql:host=localhost;dbname=cloudmgr;charset=utf8mb4', 'cloud', 'cloud', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
