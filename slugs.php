<?php
declare(strict_types=1);
ini_set('display_errors','1'); error_reporting(E_ALL);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

$db = __DIR__.'/../data/zerro_blog.db';
@mkdir(dirname($db),0775,true);

try{
  $pdo = new PDO('sqlite:'.$db);
  $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

  $pdo->exec("CREATE TABLE IF NOT EXISTS urls(
    page_id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL DEFAULT '',
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
  )");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_urls_slug ON urls(slug) WHERE slug<>''");

  $has_updated = false;
  foreach($pdo->query("PRAGMA table_info(pages)") as $r){
    if (strcasecmp((string)$r['name'],'updated_at')===0){ $has_updated = true; break; }
  }
  $order = $has_updated ? 'ORDER BY p.updated_at DESC, p.id DESC' : 'ORDER BY p.id DESC';

  if ($action==='list'){
    $sql = "SELECT p.id, p.name, IFNULL(u.slug,'') AS slug,
                   CASE WHEN p.id=(SELECT MIN(id) FROM pages) THEN 1 ELSE 0 END AS is_home
            FROM pages p
            LEFT JOIN urls u ON u.page_id=p.id
            $order";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'pages'=>$rows], JSON_UNESCAPED_UNICODE); exit;
  }

  if ($action==='update'){
    $id = (int)($_POST['id'] ?? 0);
    $slug = (string)($_POST['slug'] ?? '');

    $slug = strtolower(preg_replace('/[^a-z0-9\-]/','',$slug));
    $slug = trim($slug,'-');

    if ($slug==='editor' || $slug==='admin') { echo json_encode(['ok'=>false,'error'=>'Этот URL зарезервирован']); exit; }

    $st = $pdo->prepare("SELECT page_id FROM urls WHERE slug=:s");
    $st->execute(['s'=>$slug]); $existsId = (int)($st->fetchColumn() ?: 0);
    if ($slug!=='' && $existsId && $existsId !== $id){
      echo json_encode(['ok'=>false,'error'=>'URL уже используется']); exit;
    }

    $st = $pdo->prepare("INSERT INTO urls(page_id,slug,updated_at) VALUES(:id,:s,CURRENT_TIMESTAMP)
                         ON CONFLICT(page_id) DO UPDATE SET slug=excluded.slug, updated_at=CURRENT_TIMESTAMP");
    $st->execute(['id'=>$id,'s'=>$slug]);
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Неизвестное действие']); exit;
}catch(Throwable $e){
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
}
