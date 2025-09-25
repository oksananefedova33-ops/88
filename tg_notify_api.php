<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$db = dirname(__DIR__) . '/data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Создаем таблицу для настроек
$pdo->exec("CREATE TABLE IF NOT EXISTS tg_notify_settings (
    key TEXT PRIMARY KEY,
    value TEXT
)");

$action = $_REQUEST['action'] ?? '';

switch($action) {
    case 'saveSettings':
        saveSettings($pdo);
        break;
        
    case 'getSettings':
        getSettings($pdo);
        break;
        
    case 'deleteSettings':
        deleteSettings($pdo);
        break;
        
    case 'test':
        testNotification($pdo);
        break;
        
    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

function saveSettings($pdo) {
    $settings = [
        'chat_id' => $_POST['chat_id'] ?? '',
        'bot_token' => $_POST['bot_token'] ?? '',
        'notify_visits' => $_POST['notify_visits'] ?? '0',
        'notify_downloads' => $_POST['notify_downloads'] ?? '0',
        'notify_links' => $_POST['notify_links'] ?? '0'
    ];
    
    foreach ($settings as $key => $value) {
        $pdo->prepare("INSERT OR REPLACE INTO tg_notify_settings (key, value) VALUES (?, ?)")
            ->execute([$key, $value]);
    }
    
    echo json_encode(['ok' => true]);
}

function getSettings($pdo) {
    $stmt = $pdo->query("SELECT key, value FROM tg_notify_settings");
    $settings = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    echo json_encode(['ok' => true, 'settings' => $settings]);
}

function deleteSettings($pdo) {
    $pdo->exec("DELETE FROM tg_notify_settings");
    echo json_encode(['ok' => true]);
}

function testNotification($pdo) {
    $settings = [];
    $stmt = $pdo->query("SELECT key, value FROM tg_notify_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    if (empty($settings['chat_id']) || empty($settings['bot_token'])) {
        echo json_encode(['ok' => false, 'error' => 'Настройки не заданы']);
        return;
    }
    
    $message = "🔔 *Тестовое уведомление*\n\n";
    $message .= "Настройки уведомлений работают корректно!\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "✅ Посещения: " . ($settings['notify_visits'] === '1' ? 'Вкл' : 'Выкл') . "\n";
    $message .= "✅ Скачивания: " . ($settings['notify_downloads'] === '1' ? 'Вкл' : 'Выкл') . "\n";
    $message .= "✅ Ссылки: " . ($settings['notify_links'] === '1' ? 'Вкл' : 'Выкл') . "\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "⏰ " . date('H:i:s d.m.Y');
    
    $result = sendTelegramMessage($settings['bot_token'], $settings['chat_id'], $message);
    
    if ($result) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Не удалось отправить уведомление']);
    }
}

function sendTelegramMessage($botToken, $chatId, $message) {
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}