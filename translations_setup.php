<?php
declare(strict_types=1);

$db = dirname(__DIR__) . '/data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Таблица для хранения переводов
$pdo->exec("CREATE TABLE IF NOT EXISTS translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL,
    element_id TEXT,
    lang TEXT NOT NULL,
    field TEXT NOT NULL,
    content TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(page_id, element_id, lang, field)
)");

// Таблица для настроек переводов
$pdo->exec("CREATE TABLE IF NOT EXISTS translation_settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");

echo "✅ Таблицы для переводов созданы!";