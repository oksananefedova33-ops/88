<?php
header('Content-Type: text/html; charset=utf-8');
$VER = (string)time();
$file = __DIR__ . '/../data/linkbtn_diag.json';
$payload = @file_get_contents($file);
$payload = $payload === false ? '' : $payload;
?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Диагностика сохранения linkbtn</title>
<link rel="stylesheet" href="/ui/modules/button-link/button-link.css?v=<?=$VER?>">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:20px}
h1{font-size:20px;margin:0 0 10px}
.card{background:#111827;border:1px solid #374151;border-radius:10px;padding:16px;margin-bottom:16px}
pre{white-space:pre-wrap;word-wrap:break-word;background:#0b1020;border-radius:8px;padding:12px;max-height:70vh;overflow:auto}
button{padding:8px 12px;border-radius:8px;border:1px solid #334155;background:#1f2937;color:#e2e8f0;cursor:pointer}
</style>
</head><body>
<h1>Диагностика «кнопка - ссылка»</h1>
<div class="card">
  1) В редакторе добавь элемент и нажми <b>Сохранить</b>.<br>
  2) Открой/обнови эту страницу — ниже будет дамп, который модуль отправил при сохранении.
  <div style="margin-top:10px"><button onclick="location.reload()">Обновить дамп</button></div>
</div>
<div class="card">
  <div>Файл: <code>/data/linkbtn_diag.json</code></div>
  <pre id="dump"><?=htmlspecialchars($payload ?: 'Файл ещё не создан — нажми «Сохранить» в редакторе.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></pre>
</div>
<script defer src="/ui/modules/button-link/button-link.js?v=<?=$VER?>"></script>
</body></html>
