<?php
header('Content-Type: text/plain; charset=utf-8');

$db = dirname(__DIR__) . '/data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);

echo "=== СТАТИСТИКА ПЕРЕВОДОВ ===\n\n";

// Количество переводов по языкам
$stats = $pdo->query("SELECT lang, COUNT(*) as cnt FROM translations GROUP BY lang")->fetchAll();
foreach($stats as $stat) {
    echo "Язык {$stat['lang']}: {$stat['cnt']} переводов\n";
}

echo "\n=== НАСТРОЙКИ ===\n\n";
$settings = $pdo->query("SELECT * FROM translation_settings")->fetchAll();
foreach($settings as $setting) {
    $value = $setting['key'] === 'deepl_token' ? '***СКРЫТ***' : $setting['value'];
    echo "{$setting['key']}: {$value}\n";
}

echo "\n=== ПРИМЕРЫ ПЕРЕВОДОВ (первые 5) ===\n\n";
$samples = $pdo->query("SELECT * FROM translations LIMIT 5")->fetchAll();
foreach($samples as $sample) {
    echo "Page {$sample['page_id']}, Element {$sample['element_id']}, Lang {$sample['lang']}\n";
    echo "Field: {$sample['field']}\n";
    echo "Content: " . substr($sample['content'], 0, 100) . "...\n\n";
}