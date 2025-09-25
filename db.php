<?php
declare(strict_types=1);

$dbPath = dirname(__DIR__) . '/data/zerro_blog.db';
$dir = dirname($dbPath);
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE IF NOT EXISTS pages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  data_json TEXT NOT NULL DEFAULT "{}",
  updated_at TEXT NOT NULL
)');

// Гарантируем стартовую страницу
$count = (int)$pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
if ($count === 0) {
    $stmt = $pdo->prepare('INSERT INTO pages (name, data_json, updated_at) VALUES (:n, :d, :u)');
    $stmt->execute([
        ':n' => 'Главная',
        ':d' => json_encode(['elements' => []], JSON_UNESCAPED_UNICODE),
        ':u' => gmdate('c'),
    ]);
}
