<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input') ?: '{"error":"empty"}';
$dir = dirname(__DIR__) . '/data';
$file = $dir . '/linkbtn_diag.json';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
file_put_contents($file, $raw);
@chmod($file, 0664);
echo json_encode(['ok'=>true,'file'=>'/data/linkbtn_diag.json','bytes'=>strlen($raw)], JSON_UNESCAPED_UNICODE);
