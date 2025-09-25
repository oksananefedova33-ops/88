<?php
$db = __DIR__ . '/../data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Проверяем, есть ли уже колонки
$cols = $pdo->query("PRAGMA table_info(pages)")->fetchAll(PDO::FETCH_COLUMN, 1);

if (!in_array('data_tablet', $cols)) {
    $pdo->exec("ALTER TABLE pages ADD COLUMN data_tablet TEXT DEFAULT '{}'");
    echo "Добавлена колонка data_tablet<br>";
}

if (!in_array('data_mobile', $cols)) {
    $pdo->exec("ALTER TABLE pages ADD COLUMN data_mobile TEXT DEFAULT '{}'");
    echo "Добавлена колонка data_mobile<br>";
}

echo "✅ База данных готова к адаптивной системе!";
?>