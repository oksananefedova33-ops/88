<?php
declare(strict_types=1);
ini_set('display_errors','1'); 
error_reporting(E_ALL);

$db = __DIR__.'/data/zerro_blog.db';
@mkdir(dirname($db), 0775, true);

try {
  $pdo = new PDO('sqlite:'.$db, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch(Throwable $e) {
  http_response_code(500);
  echo "<pre>DB error: ".htmlspecialchars($e->getMessage())."</pre>";
  exit;
}

/* Базовая схема с адаптивными колонками */
$pdo->exec("CREATE TABLE IF NOT EXISTS pages(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL DEFAULT 'Страница',
  data_json TEXT NOT NULL DEFAULT '{}',
  data_tablet TEXT DEFAULT '{}',
  data_mobile TEXT DEFAULT '{}',
  meta_title TEXT NOT NULL DEFAULT '',
  meta_description TEXT NOT NULL DEFAULT ''
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS urls(
  page_id INTEGER PRIMARY KEY,
  slug TEXT NOT NULL DEFAULT '',
  updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_urls_slug ON urls(slug) WHERE slug<>''");

/* Проверяем и добавляем адаптивные колонки если их нет */
function hasColumn($pdo, $table, $column) {
  $result = $pdo->query("PRAGMA table_info($table)");
  foreach($result as $row) {
    if($row['name'] === $column) return true;
  }
  return false;
}

if(!hasColumn($pdo, 'pages', 'data_tablet')) {
  $pdo->exec("ALTER TABLE pages ADD COLUMN data_tablet TEXT DEFAULT '{}'");
}
if(!hasColumn($pdo, 'pages', 'data_mobile')) {
  $pdo->exec("ALTER TABLE pages ADD COLUMN data_mobile TEXT DEFAULT '{}'");
}

/* Определяем, какую страницу показать */
$pageId = (int)($_GET['id'] ?? 0);
$slug = (string)($_GET['slug'] ?? '');

if(!$pageId) {
  if($slug !== '') {
    $st = $pdo->prepare("SELECT page_id FROM urls WHERE slug=:s");
    $st->execute(['s' => strtolower($slug)]);
    $pageId = (int)($st->fetchColumn() ?: 0);
  } else {
    $pageId = (int)$pdo->query("SELECT MIN(id) FROM pages")->fetchColumn();
  }
}

if(!$pageId) {
  $pageId = (int)$pdo->query("SELECT id FROM pages ORDER BY id DESC LIMIT 1")->fetchColumn();
}

if(!$pageId) {
  echo "<pre>Нет страниц</pre>";
  exit;
}

/* Данные страницы с адаптивными версиями */
$st = $pdo->prepare("SELECT * FROM pages WHERE id=:id");
$st->execute(['id' => $pageId]);
$row = $st->fetch();

if(!$row) {
  echo "<pre>Страница не найдена</pre>";
  exit;
}

// Декодируем данные для всех устройств
$desktop = json_decode($row['data_json'] ?? '{}', true) ?: ['elements' => []];
$tablet = json_decode($row['data_tablet'] ?? '{}', true) ?: ['elements' => []];
$mobile = json_decode($row['data_mobile'] ?? '{}', true) ?: ['elements' => []];

// Получаем язык из параметров или куки
// Проверяем наличие переводов для английского языка
$hasEnglishTranslation = false;
try {
    $transPdo = new PDO('sqlite:' . $db);
    $checkStmt = $transPdo->prepare("SELECT COUNT(*) FROM translations WHERE page_id = ? AND lang = 'en' LIMIT 1");
    $checkStmt->execute([$pageId]);
    $hasEnglishTranslation = ($checkStmt->fetchColumn() > 0);
} catch(Exception $e) {
    // Если таблицы нет, используем русский
}

// Определяем язык по умолчанию
$defaultLang = $hasEnglishTranslation ? 'en' : 'ru';
$currentLang = $_GET['lang'] ?? $_COOKIE['site_lang'] ?? $defaultLang;

// Базовые значения
$title = htmlspecialchars(($row['meta_title'] ?: $row['name']), ENT_QUOTES, 'UTF-8');
$desc = htmlspecialchars($row['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');

// Загружаем все переводы для текущей страницы
$translations = [];
if ($currentLang !== 'ru') {
    try {
        $transPdo = new PDO('sqlite:' . $db);
        $stmt = $transPdo->prepare("SELECT * FROM translations WHERE page_id = ? AND lang = ?");
        $stmt->execute([$pageId, $currentLang]);
        
        while ($trans = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($trans['element_id'] === 'meta') {
                if ($trans['field'] === 'title' && !empty($trans['content'])) {
                    $title = htmlspecialchars($trans['content'], ENT_QUOTES, 'UTF-8');
                } elseif ($trans['field'] === 'description' && !empty($trans['content'])) {
                    $desc = htmlspecialchars($trans['content'], ENT_QUOTES, 'UTF-8');
                }
            } else {
                $key = $trans['element_id'] . '_' . $trans['field'];
                $translations[$key] = $trans['content'];
            }
        }
    } catch(Exception $e) {
        // Используем оригинальные значения при ошибке
    }
}

// Создаем карту элементов для быстрого поиска
$tabletMap = [];
$mobileMap = [];

foreach($tablet['elements'] as $e) {
  if(isset($e['id'])) {
    $tabletMap[$e['id']] = $e;
  }
}

foreach($mobile['elements'] as $e) {
  if(isset($e['id'])) {
    $mobileMap[$e['id']] = $e;
  }
}
?>
<!doctype html>
<?php /* dynamic html lang */ ?>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?></title>
<meta name="description" content="<?= $desc ?>">
<title><?= $title ?></title>
<meta name="description" content="<?= $desc ?>">
<link rel="alternate" hreflang="en" href="?<?= http_build_query(array_merge($_GET, ['lang' => 'en'])) ?>" />
<link rel="alternate" hreflang="ru" href="?<?= http_build_query(array_merge($_GET, ['lang' => 'ru'])) ?>" />
<link rel="alternate" hreflang="x-default" href="?<?= http_build_query(array_merge($_GET, ['lang' => $defaultLang])) ?>" />
<style>
/* Основные стили */
html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; } /* фикс iOS-увеличения */

body {
  margin: 0;
  background: #0e141b;
  color: #e6f0fa;
  font: 16px/1.4 system-ui, Segoe UI, Roboto;
}


.wrap {
  position: relative;
  min-height: 100vh;
  overflow-x: hidden;
}
/* iOS/Android: корректная динамическая высота вьюпорта */
@supports (height: 100dvh) {
  .wrap { min-height: 100dvh; }
}

.el {
  position: absolute;
  box-sizing: border-box;
}

/* Общие правила */
.el img, .el video {
  width: 100%;
  height: 100%;
  border-radius: inherit;
  display: block;
}

/* Для изображений */
.el[data-type="image"] img {
  object-fit: contain;
  object-position: center;
}

/* Для видео */
.el[data-type="video"] video {
  object-fit: cover;
}
/* Текстовые блоки — авто-высота и переносы длинных слов */
.el[data-type="text"] {
  height: auto;
  min-height: 30px;
  padding: 8px;
  line-height: 1.3;
  white-space: normal;
  word-wrap: break-word;
  overflow-wrap: anywhere;
  box-sizing: border-box;
}

/* типографика как в редакторе — чтобы не «расползались» отступы */
.el[data-type="text"] p,
.el[data-type="text"] h1,
.el[data-type="text"] h2,
.el[data-type="text"] h3,
.el[data-type="text"] h4,
.el[data-type="text"] h5,
.el[data-type="text"] h6,
.el[data-type="text"] ul,
.el[data-type="text"] ol { margin: 0; padding: 0; }

.el[data-type="text"] li { margin: 0 0 .35em; }
.el[data-type="text"] p + p { margin-top: .35em; }


/* Стили для кнопок */
.el.linkbtn a, .el.filebtn a {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  text-decoration: none;
  font-weight: 600;
  /* фиксируем к 1rem (обычно 16px), но оставляем переменную для переопределения */
  font-size: var(--btn-font-size, 1rem);
  line-height: 1.15;
  /* паддинги через переменные — можно на элементе задать style="--btn-py:6px;--btn-px:12px" */
  padding: var(--btn-py, 10px) var(--btn-px, 16px);
  /* не заставляем кнопку «толстеть» */
  min-height: 0;
  transition: all 0.3s ease;
}

.el.linkbtn a:hover {
  transform: scale(1.05);
  filter: brightness(1.2);
}

.el.filebtn a:hover {
  transform: scale(1.05);
  filter: brightness(1.2);
}
/* Стили для langbadge */
.el.langbadge {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 4px 6px;
  background: transparent;
  border: none;
}

.el.langbadge .lang-chip {
  padding: 6px 10px;
  border-radius: 10px;
  border: 1px solid #2ea8ff;
  background: #2ea8ff;
  color: #ffffff;
  font-size: 13px;
  cursor: default;
}

/* АДАПТИВНЫЕ СТИЛИ ДЛЯ DESKTOP (базовые) */
<?php foreach($desktop['elements'] as $e): 
  $id = $e['id'] ?? uniqid('el_');
  $left = (float)($e['left'] ?? 0);
  $top = (float)($e['top'] ?? 0);
  $width = (float)($e['width'] ?? 30);
  $height = (float)($e['height'] ?? 25);
  $zIndex = (int)($e['z'] ?? 1);
  $radius = (int)($e['radius'] ?? 8);
  $rotate = (float)($e['rotate'] ?? 0);
  $autoHeight = ($e['type'] === 'text');

  /* Приводим вертикальную систему координат к базе редактора:
     Desktop ширина сцены = 1200px, высота сцены = 1500px */
  $DESKTOP_W = 1200;
  $EDITOR_H  = 1500;

  $topVW    = round($top / $DESKTOP_W * 100, 4);
  $heightVW = !$autoHeight ? round((($height / 100) * $EDITOR_H) / $DESKTOP_W * 100, 4) : 0;
?>
#el-<?= $id ?> {
  left: <?= $left ?>%;
  top: <?= $topVW ?>vw;
  width: <?= $width ?>%;
  <?php if(!$autoHeight): ?>height: <?= $heightVW ?>vw;<?php endif; ?>
  z-index: <?= $zIndex ?>;
  border-radius: <?= $radius ?>px;
  transform: rotate(<?= $rotate ?>deg);
}
<?php endforeach; ?>

/* АДАПТИВНЫЕ СТИЛИ ДЛЯ TABLET */
@media (max-width: 768px) and (min-width: 481px) {
  <?php foreach($desktop['elements'] as $e): 
    $id = $e['id'] ?? '';
    if(!$id) continue;

    if(isset($tabletMap[$id])) {
      $te = $tabletMap[$id];
      $left = (float)($te['left'] ?? 0);
      $top = (float)($te['top'] ?? 0);
      $width = (float)($te['width'] ?? 45);
      $height = (float)($te['height'] ?? 25);
      $autoHeight = ($te['type'] === 'text');

      /* Tablet: база редактора = 768px по ширине, высота сцены = 1500px */
      $TABLET_W = 768;
      $EDITOR_H = 1500;
      $topVW    = round($top / $TABLET_W * 100, 4);
      $heightVW = !$autoHeight ? round((($height / 100) * $EDITOR_H) / $TABLET_W * 100, 4) : 0;
    ?>
    #el-<?= $id ?> {
      left: <?= $left ?>% !important;
      top: <?= $topVW ?>vw !important;
      width: <?= $width ?>% !important;
      <?php if(!$autoHeight): ?>height: <?= $heightVW ?>vw !important;<?php endif; ?>
    }
    <?php
    }
  endforeach; ?>
}

/* АДАПТИВНЫЕ СТИЛИ ДЛЯ MOBILE */
@media (max-width: 480px) {
  <?php foreach($desktop['elements'] as $e): 
    $id = $e['id'] ?? '';
    if(!$id) continue;

    if(isset($mobileMap[$id])) {
      $me = $mobileMap[$id];
      $left = (float)($me['left'] ?? 0);
      $top = (float)($me['top'] ?? 0);
      $width = (float)($me['width'] ?? 90);
      $height = (float)($me['height'] ?? 25);
      $autoHeight = ($me['type'] === 'text');

      /* Mobile: база редактора = 375px по ширине, высота сцены = 1500px */
      $MOBILE_W = 375;
      $EDITOR_H = 1500;
      $topVW    = round($top / $MOBILE_W * 100, 4);
      $heightVW = !$autoHeight ? round((($height / 100) * $EDITOR_H) / $MOBILE_W * 100, 4) : 0;
    ?>
    #el-<?= $id ?> {
      left: <?= $left ?>% !important;
      top: <?= $topVW ?>vw !important;
      width: <?= $width ?>% !important;
      <?php if(!$autoHeight): ?>height: <?= $heightVW ?>vw !important;<?php endif; ?>
    }
    <?php
    }
  endforeach; ?>

  /* Дополнительные адаптивные правила для мобильных */
  .el[data-type="text"] {
    font-size: 16px !important;
  }

  /* Не фиксируем высоту кнопок — размер задаёт сам элемент */
  .el.linkbtn,
  .el.filebtn {
    min-height: 0 !important;
  }

  /* Компактнее шрифт и паддинги на телефонах */
  .el.linkbtn a,
  .el.filebtn a {
    font-size: var(--btn-font-size-mobile, 0.875rem) !important; /* ≈14px */
    padding: var(--btn-py-mobile, 8px) var(--btn-px-mobile, 12px) !important;
    line-height: 1.2; /* для визуального баланса */
  }

}
</style>

</style>
<script>
// Оставляем переменные для совместимости, но убираем клиентский 'перевод'.
window.siteTranslations = <?= json_encode($translations) ?>;
window.currentLang = '<?= $currentLang ?>';
</script>

</head>
<body>
<div class="wrap">
<?php 
// Выводим элементы с уникальными ID
foreach($desktop['elements'] as $e):
  $type = (string)($e['type'] ?? '');
  $id = $e['id'] ?? uniqid('el_');
  
  if($type === 'text'):
    // Сначала выводим сохранённый html (с форматированием), иначе безопасный text для старых данных
    $html  = (string)($e['html'] ?? '');
    $fs    = (int)($e['fontSize'] ?? 20);
    $color = htmlspecialchars($e['color'] ?? '#e8f2ff', ENT_QUOTES, 'UTF-8');
    $bg    = trim((string)($e['bg'] ?? ''));
    $bgStyle = $bg !== '' ? "background:{$bg};" : "";

    // SSR-перевод: подменяем контент на сервере, чтобы не было вспышки русского
    if ($currentLang !== 'ru') {
        $trKeyHtml = $id . '_html';
        $trKeyText = $id . '_text';
        if (!empty($translations[$trKeyHtml])) {
            $html = $translations[$trKeyHtml];
        } elseif ($html === '' && !empty($translations[$trKeyText])) {
            // На случай старых записей, где текст хранится в поле text
            $e['text'] = $translations[$trKeyText];
        }
    }
    ?>
    <div id="el-<?= $id ?>" class="el" data-type="text"
         style="<?= $bgStyle ?>color:<?= $color ?>;font-size:<?= $fs ?>px;line-height:1.3;height:auto;">
      <?= $html !== '' ? $html : nl2br(htmlspecialchars($e['text'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
    </div>
    <?php

    
  elseif($type === 'box'):
    $bg = trim((string)($e['bg'] ?? ''));
    $bd = trim((string)($e['border'] ?? ''));
    $bgStyle = $bg !== '' ? "background:{$bg};" : "";
    $bdStyle = $bd !== '' ? "border:{$bd};" : "";
    ?>
    <div id="el-<?= $id ?>" class="el" data-type="box" style="<?= $bgStyle . $bdStyle ?>"></div>
    <?php
    
  elseif($type === 'image'):
    if(!empty($e['html'])):
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="image">
        <div style="width:100%;height:100%"><?= $e['html'] ?></div>
      </div>
      <?php
    else:
      $src = htmlspecialchars($e['src'] ?? '', ENT_QUOTES, 'UTF-8');
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="image">
        <img src="<?= $src ?>" alt="" loading="lazy">
      </div>
      <?php
    endif;
    
  elseif($type === 'video'):
    if(!empty($e['html'])):
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="video">
        <div style="width:100%;height:100%"><?= $e['html'] ?></div>
      </div>
      <?php
    else:
      $src = htmlspecialchars($e['src'] ?? '', ENT_QUOTES, 'UTF-8');
      $controls = !isset($e['controls']) || $e['controls'] ? ' controls' : '';
      $autoplay = !empty($e['autoplay']) ? ' autoplay' : '';
      $loop = !empty($e['loop']) ? ' loop' : '';
      $muted = !empty($e['muted']) ? ' muted' : '';
      ?>
      <div id="el-<?= $id ?>" class="el" data-type="video">
        <video src="<?= $src ?>"<?= $controls . $autoplay . $loop . $muted ?> playsinline></video>
      </div>
      <?php
    endif;
    
  elseif(strtolower($type) === 'linkbtn'):
    // Подставляем перевод текста кнопки на сервере (если есть)
    $btnTextRaw = $e['text'] ?? 'Кнопка';
    if ($currentLang !== 'ru' && !empty($translations[$id . '_text'])) {
        $btnTextRaw = $translations[$id . '_text'];
    }
    $text = htmlspecialchars($btnTextRaw, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars($e['url'] ?? '#', ENT_QUOTES, 'UTF-8');
    $bg = htmlspecialchars(trim((string)($e['bg'] ?? '#3b82f6')), ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars(trim((string)($e['color'] ?? '#ffffff')), ENT_QUOTES, 'UTF-8');
    $radius = (int)($e['radius'] ?? 8);
    ?>
    <div id="el-<?= $id ?>" class="el linkbtn" data-type="linkbtn">
      <a href="<?= $url ?>" 
         style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:<?= $radius ?>px" 
         target="_blank">
        <?= $text ?>
      </a>
    </div>
    <?php

    
  elseif(strtolower($type) === 'filebtn'):
    // Подставляем перевод текста кнопки скачивания на сервере (если есть)
    $btnTextRaw = $e['text'] ?? 'Скачать файл';
    if ($currentLang !== 'ru' && !empty($translations[$id . '_text'])) {
        $btnTextRaw = $translations[$id . '_text'];
    }
    $text = htmlspecialchars($btnTextRaw, ENT_QUOTES, 'UTF-8');
    $fileUrl = htmlspecialchars($e['fileUrl'] ?? '#', ENT_QUOTES, 'UTF-8');
    $fileName = htmlspecialchars($e['fileName'] ?? '', ENT_QUOTES, 'UTF-8');
    $bg = htmlspecialchars(trim((string)($e['bg'] ?? '#10b981')), ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars(trim((string)($e['color'] ?? '#ffffff')), ENT_QUOTES, 'UTF-8');
    $radius = (int)($e['radius'] ?? 8);
    
    // Определяем иконку
    $icon = '📄';
    if($fileName) {
      $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
      if(in_array($ext, ['zip','rar','7z','tar','gz','bz2'])) $icon = '📦';
      elseif($ext === 'pdf') $icon = '📕';
      elseif(in_array($ext, ['doc','docx'])) $icon = '📘';
      elseif(in_array($ext, ['xls','xlsx'])) $icon = '📗';
      elseif(in_array($ext, ['ppt','pptx'])) $icon = '📙';
      elseif(in_array($ext, ['mp3','wav','ogg','aac','flac'])) $icon = '🎵';
      elseif(in_array($ext, ['mp4','avi','mkv','mov','webm'])) $icon = '🎬';
      elseif(in_array($ext, ['jpg','jpeg','png','gif','svg','webp'])) $icon = '🖼️';
      elseif(in_array($ext, ['js','json','xml','html','css','php','py'])) $icon = '💻';
      elseif(in_array($ext, ['exe','apk','dmg','deb'])) $icon = '💿';
      elseif(in_array($ext, ['txt','md','csv'])) $icon = '📝';
    }
    ?>
    <div id="el-<?= $id ?>" class="el filebtn" data-type="filebtn">
      <a href="<?= $fileUrl ?>" 
         download="<?= $fileName ?>" 
         style="background:<?= $bg ?>;color:<?= $color ?>;border-radius:<?= $radius ?>px" 
         target="_blank">
        <span style="margin-right:8px"><?= $icon ?></span><?= $text ?>
      </a>
    </div>
    <?php

  elseif ($type === 'langbadge'):
    $langs = htmlspecialchars($e['langs'] ?? 'ru,en', ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($e['label'] ?? 'Языки', ENT_QUOTES, 'UTF-8');
    $badgeColor = htmlspecialchars($e['badgeColor'] ?? '', ENT_QUOTES, 'UTF-8');

    $langsArray = array_filter(array_map('trim', explode(',', $langs)));
    $currentLang = $_GET['lang'] ?? $_COOKIE['site_lang'] ?? ($langsArray[0] ?? 'ru');

    // Сохраняем выбранный язык в куку
    if (isset($_GET['lang'])) {
        setcookie('site_lang', $_GET['lang'], time() + (365 * 24 * 60 * 60), '/');
    }

    // Мапинг языков на флаги и названия (полный список DeepL)
    $langMap = [
        'ru' => ['flag' => '🇷🇺', 'name' => 'Русский'],
        'en' => ['flag' => '🇬🇧', 'name' => 'English'],
        'zh-Hans' => ['flag' => '🇨🇳', 'name' => '中文'],
        'es' => ['flag' => '🇪🇸', 'name' => 'Español'],
        'fr' => ['flag' => '🇫🇷', 'name' => 'Français'],
        'de' => ['flag' => '🇩🇪', 'name' => 'Deutsch'],
        'it' => ['flag' => '🇮🇹', 'name' => 'Italiano'],
        'pt' => ['flag' => '🇵🇹', 'name' => 'Português'],
        'ja' => ['flag' => '🇯🇵', 'name' => '日本語'],
        'ko' => ['flag' => '🇰🇷', 'name' => '한국어'],
        'nl' => ['flag' => '🇳🇱', 'name' => 'Nederlands'],
        'pl' => ['flag' => '🇵🇱', 'name' => 'Polski'],
        'tr' => ['flag' => '🇹🇷', 'name' => 'Türkçe'],
        'ar' => ['flag' => '🇸🇦', 'name' => 'العربية'],
        'cs' => ['flag' => '🇨🇿', 'name' => 'Čeština'],
        'da' => ['flag' => '🇩🇰', 'name' => 'Dansk'],
        'el' => ['flag' => '🇬🇷', 'name' => 'Ελληνικά'],
        'fi' => ['flag' => '🇫🇮', 'name' => 'Suomi'],
        'hu' => ['flag' => '🇭🇺', 'name' => 'Magyar'],
        'id' => ['flag' => '🇮🇩', 'name' => 'Indonesia'],
        'no' => ['flag' => '🇳🇴', 'name' => 'Norsk'],
        'ro' => ['flag' => '🇷🇴', 'name' => 'Română'],
        'sv' => ['flag' => '🇸🇪', 'name' => 'Svenska'],
        'uk' => ['flag' => '🇺🇦', 'name' => 'Українська'],
        'bg' => ['flag' => '🇧🇬', 'name' => 'Български'],
        'et' => ['flag' => '🇪🇪', 'name' => 'Eesti'],
        'lt' => ['flag' => '🇱🇹', 'name' => 'Lietuvių'],
        'lv' => ['flag' => '🇱🇻', 'name' => 'Latviešu'],
        'sk' => ['flag' => '🇸🇰', 'name' => 'Slovenčina'],
        'sl' => ['flag' => '🇸🇮', 'name' => 'Slovenščina'],
        'hi' => ['flag' => '🇮🇳', 'name' => 'हिन्दी']
    ];
    // Текущий язык (флаг + название)
    $currentLangData = $langMap[trim($currentLang)] ?? ['flag' => '🌐', 'name' => strtoupper(trim($currentLang))];

    // Базовая часть query‑строки без параметра lang — чтобы не терять id/slug и другие параметры
    $baseQuery = $_GET;
    unset($baseQuery['lang']);
    $baseQueryStr = http_build_query($baseQuery);
    $baseHrefPrefix = ($baseQueryStr !== '' ? '?' . $baseQueryStr . '&' : '?');

    ?>
    <div id="el-<?= $id ?>" class="el langbadge" data-type="langbadge" data-langs="<?= $langs ?>">
      <div class="langbadge__wrap">
        <div class="lang-selector" onclick="this.querySelector('.lang-dropdown').classList.toggle('show')">
          <div class="lang-chip"<?php if ($badgeColor) { echo ' style="background:'.$badgeColor.'; border: 1px solid '.$badgeColor.'; color:#fff"'; } ?>>
            <span class="lang-flag"><?= $currentLangData['flag'] ?></span>
            <span class="lang-name"><?= $currentLangData['name'] ?></span>
          </div>
          <div class="lang-dropdown">
            <?php foreach($langsArray as $lang): 
                $code = trim($lang);
                $langData = $langMap[$code] ?? ['flag' => '🌐', 'name' => strtoupper($code)];
                $active = ($code === $currentLang) ? ' active' : '';
                $href = $baseHrefPrefix . 'lang=' . urlencode($code);
            ?>
              <a class="lang-option<?= $active ?>" href="<?= $href ?>">
                <span class="lang-flag"><?= $langData['flag'] ?></span>
                <span class="lang-name"><?= $langData['name'] ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <style>
      .el.langbadge { background: transparent !important; border: none !important; padding: 0 !important; }
      .lang-selector { position: relative; cursor: pointer; display: inline-block; }
      .lang-chip { padding: 8px 16px; border-radius: 12px; border: 1px solid #2ea8ff; display: inline-flex; align-items: center; gap: 8px; font-size: 14px; transition: all 0.3s ease; background: #0f1723; color: #fff; }
      .lang-chip:hover { background: #2ea8ff; transform: scale(1.05); }
      .lang-flag { font-size: 20px; line-height: 1; }
      .lang-dropdown { position: absolute; top: calc(100% + 8px); left: 0; background: #1a2533; border: 1px solid #2ea8ff; border-radius: 12px; padding: 8px; z-index: 10000; display: none; min-width: 200px; width: max-content; max-height: 380px; overflow-y: auto; overflow-x: hidden; box-shadow: 0 4px 20px rgba(46, 168, 255, 0.3); }
      .lang-dropdown.show { display: block !important; }
      .lang-option { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: #e8f2ff; }
      .lang-option:hover { background: rgba(46, 168, 255, 0.12); }
      .lang-option.active { background: #2ea8ff; color: #fff; }
      .lang-dropdown::-webkit-scrollbar { width: 8px; }
      .lang-dropdown::-webkit-scrollbar-track { background: #0b111a; border-radius: 4px; }
      .lang-dropdown::-webkit-scrollbar-thumb { background: #2a3f5f; border-radius: 4px; }
      .lang-dropdown::-webkit-scrollbar-thumb:hover { background: #3a5070; }
    </style>
    <?php
  endif;
endforeach; 
?>
</div>
<!-- Трекер событий для Telegram -->
<script src="/ui/tg-notify/tracker.js?v=<?php echo time(); ?>"></script>

</body>
</html>