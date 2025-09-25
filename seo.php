<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);                 // корень сайта
$db   = $root . '/data/zerro_blog.db';

try {
  $pdo = new PDO('sqlite:' . $db);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db: '.$e->getMessage()]);
  exit;
}

$action = $_REQUEST['action'] ?? 'get';

if ($action === 'get') {
  $id = (int)($_GET['id'] ?? 0);
  $st = $pdo->prepare('SELECT id, meta_title, meta_description FROM pages WHERE id = ?');
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true,'data'=>$row ?: ['id'=>$id,'meta_title'=>'','meta_description'=>'']]);
  exit;
}

if ($action === 'save') {
  $id    = (int)($_POST['id'] ?? 0);
  $title = array_key_exists('meta_title', $_POST) ? trim((string)$_POST['meta_title']) : null;
  $descr = array_key_exists('meta_description', $_POST) ? trim((string)$_POST['meta_description']) : null;
  if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'no id']); exit; }
  $st = $pdo->prepare(
    'UPDATE pages SET
       meta_title       = CASE WHEN :t IS NULL OR :t = "" THEN meta_title       ELSE :t END,
       meta_description = CASE WHEN :d IS NULL OR :d = "" THEN meta_description ELSE :d END
     WHERE id = :id'
  );
  $st->execute([':t'=>$title, ':d'=>$descr, ':id'=>$id]);
  echo json_encode(['ok'=>true]);
  exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'unknown action']);
