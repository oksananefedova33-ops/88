<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

// Путь к базе данных
$db = dirname(__DIR__) . '/data/zerro_blog.db';

try {
    // Подключение к базе данных
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Создаем таблицу настроек, если её нет
    $pdo->exec("CREATE TABLE IF NOT EXISTS translation_settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");
    
    // Переключаем на платную версию DeepL API
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO translation_settings (key, value) VALUES ('deepl_api_base', ?)");
    $stmt->execute(['https://api.deepl.com']);
    
    // Проверяем, есть ли сохраненный токен
    $tokenStmt = $pdo->query("SELECT value FROM translation_settings WHERE key='deepl_token'");
    $existingToken = $tokenStmt->fetchColumn();
    
    // Получаем текущие настройки для отображения
    $currentSettings = [];
    $settingsStmt = $pdo->query("SELECT * FROM translation_settings");
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $currentSettings[$row['key']] = $row['value'];
    }
    
    $success = true;
    $message = "✅ Успешно переключено на ПЛАТНУЮ версию DeepL API!";
    
} catch (Exception $e) {
    $success = false;
    $message = "❌ Ошибка: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Переключение DeepL API</title>
    <style>
        body {
            background: #0e141b;
            color: #e6f0fa;
            font-family: system-ui, -apple-system, sans-serif;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #0f1622;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,.5);
        }
        h1 {
            color: #fff;
            font-size: 24px;
            margin: 0 0 20px 0;
        }
        .status {
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 16px;
        }
        .status.success {
            background: rgba(16,185,129,.1);
            color: #10b981;
            border: 1px solid rgba(16,185,129,.3);
        }
        .status.error {
            background: rgba(239,68,68,.1);
            color: #ef4444;
            border: 1px solid rgba(239,68,68,.3);
        }
        .info-box {
            background: #111925;
            border: 1px solid #213247;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #213247;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #9fb2c6;
        }
        .info-value {
            color: #e4eef9;
            font-family: monospace;
        }
        .buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: 1px solid #2a3441;
            background: #1a2533;
            color: #e4eef9;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn:hover {
            background: #2a3441;
        }
        .btn.primary {
            background: #2ea8ff;
            border-color: #1e98ef;
            color: #ffffff;
        }
        .btn.primary:hover {
            background: #1e98ef;
        }
        .warning {
            background: rgba(245,158,11,.1);
            border: 1px solid rgba(245,158,11,.3);
            color: #f59e0b;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .token-hidden {
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Переключение DeepL API на платную версию</h1>
        
        <div class="status <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </div>
        
        <?php if ($success): ?>
        <div class="info-box">
            <h3 style="margin-top:0">Текущие настройки:</h3>
            <div class="info-row">
                <span class="info-label">API URL:</span>
                <span class="info-value"><?php echo $currentSettings['deepl_api_base'] ?? 'не установлен'; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Тип аккаунта:</span>
                <span class="info-value" style="color: #10b981; font-weight: bold;">ПЛАТНЫЙ (PRO)</span>
            </div>
            <div class="info-row">
                <span class="info-label">Токен:</span>
                <span class="info-value">
                    <?php if ($existingToken): ?>
                        <span class="token-hidden">***<?php echo substr($existingToken, -4); ?></span>
                    <?php else: ?>
                        <span style="color: #ef4444;">не установлен</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        
        <?php if (!$existingToken): ?>
        <div class="warning">
            ⚠️ <strong>Внимание:</strong> Токен DeepL не установлен! 
            Перейдите в редактор и нажмите кнопку "🌍 Переводы" чтобы добавить токен платной версии.
        </div>
        <?php else: ?>
        <div class="info-box" style="background: rgba(16,185,129,.05);">
            ✅ Токен уже сохранен. Если это токен от бесплатной версии, обязательно замените его на токен платной версии через интерфейс переводов в редакторе.
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3 style="margin-top:0">Что изменилось:</h3>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>API URL изменен с <code>api-free.deepl.com</code> на <code>api.deepl.com</code></li>
                <li>Увеличен лимит символов (500,000 в месяц → без ограничений)</li>
                <li>Доступна более быстрая обработка запросов</li>
                <li>Расширенные возможности API</li>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="buttons">
            <a href="/editor/editor.php" class="btn primary">Перейти в редактор</a>
            <button class="btn" onclick="location.reload()">Обновить статус</button>
        </div>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #213247; color: #6b7280; font-size: 13px;">
            <p><strong>Как переключиться обратно на бесплатную версию:</strong></p>
            <p>Создайте файл <code>switch_deepl_to_free.php</code> с таким же кодом, но замените URL на <code>https://api-free.deepl.com</code></p>
        </div>
    </div>
</body>
</html>