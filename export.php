<?php
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? 'export';

if ($action === 'export') {
    exportSite();
} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

function exportSite() {
    try {
        $db = dirname(__DIR__) . '/data/zerro_blog.db';
        $pdo = new PDO('sqlite:' . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Создаем временную директорию для экспорта
        $exportDir = __DIR__ . '/temp_export_' . time();
        @mkdir($exportDir, 0777, true);
        @mkdir($exportDir . '/assets', 0777, true);
        @mkdir($exportDir . '/assets/uploads', 0777, true);
        @mkdir($exportDir . '/assets/js', 0777, true);
        
        // Получаем все страницы с их URL
        $pages = getPages($pdo);
        
        // Получаем настройки языков из langbadge элементов
        $languages = getLanguages($pdo);
        
        // Получаем переводы
        $translations = getTranslations($pdo);
        
        // Проверяем наличие английских переводов
        $hasEnglishTranslations = false;
        if (in_array('en', $languages)) {
            try {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM translations WHERE lang = 'en' LIMIT 1");
                $checkStmt->execute();
                $hasEnglishTranslations = ($checkStmt->fetchColumn() > 0);
            } catch(Exception $e) {
                // Игнорируем если таблицы нет
            }
        }
        
        // Определяем основной язык для экспорта
        $primaryLang = $hasEnglishTranslations ? 'en' : 'ru';
        
        // Собираем все используемые файлы
        $usedFiles = [];
        
        // Генерируем CSS и JavaScript
        generateAssets($exportDir);
        
        /* Структура файлов в корне:
         * index.html - главная на русском
         * index-en.html - главная на английском
         * about.html - страница "О нас" на русском
         * about-en.html - страница "О нас" на английском
         * и т.д.
         */
        
        // Генерируем страницы для всех языков в корневой папке
        foreach ($pages as $page) {
            // Генерируем для всех языков
            foreach ($languages as $lang) {
                if ($lang === $primaryLang) {
                    // Основной язык без суффикса
                    $pageTrans = $lang === 'ru' ? null : ($translations[$page['id']][$lang] ?? []);
                    $html = generatePageHTML($pdo, $page, $lang, $pageTrans, $usedFiles, $languages, $primaryLang);
                    $filename = getPageFilename($page, $lang, $primaryLang);
                    file_put_contents($exportDir . '/' . $filename, $html);
                } else {
                    // Другие языки с суффиксом
                    $pageTrans = $lang === 'ru' ? null : ($translations[$page['id']][$lang] ?? []);
                    $html = generatePageHTML($pdo, $page, $lang, $pageTrans, $usedFiles, $languages, $primaryLang);
                    $filename = getPageFilename($page, $lang, $primaryLang);
                    file_put_contents($exportDir . '/' . $filename, $html);
                }
            }
        }
        
        // Копируем используемые файлы
        copyUsedFiles($usedFiles, $exportDir);
        
        // Создаем .htaccess для красивых URL (Apache)
        generateHtaccess($exportDir);
        
        // Создаем nginx.conf для Nginx серверов
        generateNginxConfig($exportDir);
        
        // Создаем README с инструкциями
        generateReadme($exportDir, $languages);
        // Создаем API для удаленного управления
generateRemoteAPI($exportDir);
        
        // Создаем robots.txt и sitemap.xml
        generateRobots($exportDir);
        generateSitemap($exportDir, $pages, $languages, $primaryLang);
        
        // Создаем ZIP архив
        $zipFile = createZipArchive($exportDir);
        
        // Удаляем временную директорию
        deleteDirectory($exportDir);
        
        // Отправляем архив
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="website_export_' . date('Y-m-d_His') . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function getPages($pdo) {
    $sql = "SELECT p.id, p.name, p.data_json, p.data_tablet, p.data_mobile, 
                   p.meta_title, p.meta_description, u.slug,
                   CASE WHEN p.id=(SELECT MIN(id) FROM pages) THEN 1 ELSE 0 END AS is_home
            FROM pages p
            LEFT JOIN urls u ON u.page_id = p.id
            ORDER BY p.id";
    
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getLanguages($pdo) {
    // Получаем языки из langbadge элементов
    $stmt = $pdo->query("SELECT data_json FROM pages");
    $languages = ['ru']; // Русский всегда включен как основной
    
    while ($row = $stmt->fetch()) {
        $data = json_decode($row['data_json'], true);
        foreach ($data['elements'] ?? [] as $element) {
            if ($element['type'] === 'langbadge' && !empty($element['langs'])) {
                $langs = explode(',', $element['langs']);
                $languages = array_merge($languages, array_map('trim', $langs));
            }
        }
    }
    
    return array_unique($languages);
}

function getTranslations($pdo) {
    $trans = [];
    
    // Проверяем существование таблицы
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='translations'")->fetchAll();
    if (empty($tables)) {
        return $trans;
    }
    
    $stmt = $pdo->query("SELECT * FROM translations");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pageId = $row['page_id'];
        $lang = $row['lang'];
        $elementId = $row['element_id'];
        $field = $row['field'];
        
        if (!isset($trans[$pageId])) $trans[$pageId] = [];
        if (!isset($trans[$pageId][$lang])) $trans[$pageId][$lang] = [];
        
        $trans[$pageId][$lang][$elementId . '_' . $field] = $row['content'];
    }
    
    return $trans;
}

function getPageFilename($page, $lang = 'ru', $primaryLang = 'ru') {
    $basename = '';
    
    if ($page['is_home']) {
        $basename = 'index';
    } elseif (!empty($page['slug'])) {
        $basename = $page['slug'];
    } else {
        $basename = 'page_' . $page['id'];
    }
    
    // Добавляем языковой суффикс для всех языков кроме основного
    if ($lang !== $primaryLang) {
        $basename .= '-' . $lang;
    }
    
    return $basename . '.html';
}

function generatePageHTML($pdo, $page, $lang, $translations, &$usedFiles, $allLanguages, $primaryLang = 'ru') {
    $data = json_decode($page['data_json'], true) ?: [];
    $dataTablet = json_decode($page['data_tablet'], true) ?: [];
    $dataMobile = json_decode($page['data_mobile'], true) ?: [];
    
    // Получаем мета-данные с учетом перевода
    $title = $page['meta_title'] ?: $page['name'];
    $description = $page['meta_description'] ?: '';
    
    if ($translations && $lang !== 'ru') {
        if (isset($translations['meta_title'])) {
            $title = $translations['meta_title'];
        }
        if (isset($translations['meta_description'])) {
            $description = $translations['meta_description'];
        }
    }
    
    // Получаем цвет фона страницы
    $bgColor = $data['bgColor'] ?? '#0e141b';
    $pageHeight = $data['height'] ?? 2000;
    
    // Все страницы в корне, поэтому путь к assets всегда относительный
    $assetsPath = 'assets';

    // Динамический хост основного домена для Telegram‑трекера
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $notifyBase = $host ? "{$scheme}://{$host}" : '';
    $notifyApi  = $notifyBase ? "{$notifyBase}/tg_notify_track.php" : "/tg_notify_track.php";
    $notifyJs   = $notifyBase ? "{$notifyBase}/ui/tg-notify/tracker.js" : "/ui/tg-notify/tracker.js";
    
    // Начало HTML
    $html = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <meta name="description" content="{$description}">
    <link rel="stylesheet" href="{$assetsPath}/style.css">
</head>
<body style="background-color: {$bgColor};">
    <div class="wrap" style="min-height: {$pageHeight}px;">
HTML;
    
    // Генерируем элементы
    foreach ($data['elements'] ?? [] as $element) {
        $html .= generateElement($element, $lang, $translations, $usedFiles, $dataTablet, $dataMobile, $page, $allLanguages, $primaryLang);
    }
    
  $html .= <<<HTML
    </div>
    <!-- Telegram notify -->
    <script>window.TG_NOTIFY_API = '{$notifyApi}';</script>
    <script src="{$notifyJs}" defer></script>
    <script src="{$assetsPath}/js/main.js"></script>
</body>
</html>
HTML;
    
    return $html;
}

function generateElement($element, $lang, $translations, &$usedFiles, $dataTablet, $dataMobile, $page, $allLanguages, $primaryLang = 'ru') {
    $type = $element['type'] ?? '';
    $id = $element['id'] ?? '';
    
    // Получаем адаптивные стили
    $tabletElement = null;
    $mobileElement = null;
    
    foreach ($dataTablet['elements'] ?? [] as $te) {
        if (($te['id'] ?? '') === $id) {
            $tabletElement = $te;
            break;
        }
    }
    
    foreach ($dataMobile['elements'] ?? [] as $me) {
        if (($me['id'] ?? '') === $id) {
            $mobileElement = $me;
            break;
        }
    }
    
    // Базовые стили с улучшенной обработкой
    $left = $element['left'] ?? 0;
    $top = $element['top'] ?? 0;
    $width = $element['width'] ?? 30;
    $height = $element['height'] ?? 25;
    $zIndex = $element['z'] ?? 1;
    $radius = $element['radius'] ?? 0;
    $rotate = $element['rotate'] ?? 0;
    $opacity = $element['opacity'] ?? 1;
    
    // Пересчитываем вертикальные единицы управления как в index.php редактора
    $DESKTOP_W = 1200; // ширина сцены редактора
    $EDITOR_H  = 1500; // высота сцены редактора
    $topVW    = round(($top / $DESKTOP_W) * 100, 4);
    $heightVW = ($type === 'text') ? null : round(((($height / 100) * $EDITOR_H) / $DESKTOP_W) * 100, 4);

    $style = sprintf(
        'left:%s%%;top:%svw;width:%s%%;%sz-index:%d;border-radius:%dpx;transform:rotate(%sdeg);opacity:%s',
        $left,
        $topVW,
        $width,
        ($type === 'text' ? 'height:auto;' : 'height:' . $heightVW . 'vw;'),
        $zIndex,
        $radius,
        $rotate,
        $opacity
    );
    
    // Добавляем дополнительные стили если есть
    if (!empty($element['shadow'])) {
        $style .= ';box-shadow:' . $element['shadow'];
    }
    
    $html = '';
    
    switch ($type) {
        case 'text':
    $content = $element['html'] ?? $element['text'] ?? '';
    if ($translations && isset($translations[$id . '_html'])) {
        $content = $translations[$id . '_html'];
    } elseif ($translations && isset($translations[$id . '_text'])) {
        $content = $translations[$id . '_text'];
    }
    
    // Обработка стилей текста
    $fontSize = $element['fontSize'] ?? 20;
    $color = $element['color'] ?? '#e8f2ff';
    $bg = $element['bg'] ?? 'transparent';
    $padding = $element['padding'] ?? 8;
    $textAlign = $element['textAlign'] ?? 'left';
    $fontWeight = $element['fontWeight'] ?? 'normal';
    $lineHeight = $element['lineHeight'] ?? '1.5';
    
    $textStyle = sprintf(
        'font-size:%dpx;color:%s;background:%s;padding:%dpx;text-align:%s;font-weight:%s;line-height:%s;min-height:30px;word-wrap:break-word;overflow-wrap:break-word',
        $fontSize,
        $color,
        $bg,
        $padding,
        $textAlign,
        $fontWeight,
        $lineHeight
    );
    
    $html = sprintf(
        '<div class="el text" style="%s;%s" id="%s" data-type="text" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
        $style,
        $textStyle,
        $id,
        json_encode($tabletElement ?: [], JSON_HEX_APOS),
        json_encode($mobileElement ?: [], JSON_HEX_APOS),
        $content
    );
    break;
            
        case 'image':
            $src = processMediaPath($element['src'] ?? '', $usedFiles);
            $alt = $element['alt'] ?? '';
            $objectFit = $element['objectFit'] ?? 'contain';
            
            if (!empty($element['html'])) {
                $html = sprintf(
                    '<div class="el image" style="%s" id="%s" data-type="image" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    processHtmlContent($element['html'], $usedFiles)
                );
            } else {
                $html = sprintf(
                    '<div class="el image" style="%s" id="%s" data-type="image" data-tablet=\'%s\' data-mobile=\'%s\'><img src="%s" alt="%s" style="width:100%%;height:100%%;object-fit:%s;"></div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    $src,
                    htmlspecialchars($alt),
                    $objectFit
                );
            }
            break;
            
        case 'video':
            $src = processMediaPath($element['src'] ?? '', $usedFiles);
            $poster = isset($element['poster']) ? processMediaPath($element['poster'], $usedFiles) : '';
            
            if (!empty($element['html'])) {
                $html = sprintf(
                    '<div class="el video" style="%s" id="%s" data-type="video" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    processHtmlContent($element['html'], $usedFiles)
                );
            } else {
                $controls = ($element['controls'] ?? true) ? 'controls' : '';
                $autoplay = ($element['autoplay'] ?? false) ? 'autoplay' : '';
                $loop = ($element['loop'] ?? false) ? 'loop' : '';
                $muted = ($element['muted'] ?? false) ? 'muted' : '';
                $posterAttr = $poster ? 'poster="' . $poster . '"' : '';
                
                $html = sprintf(
                    '<div class="el video" style="%s" id="%s" data-type="video" data-tablet=\'%s\' data-mobile=\'%s\'><video src="%s" %s %s %s %s %s style="width:100%%;height:100%%;object-fit:cover;"></video></div>',
                    $style,
                    $id,
                    json_encode($tabletElement ?: [], JSON_HEX_APOS),
                    json_encode($mobileElement ?: [], JSON_HEX_APOS),
                    $src,
                    $controls,
                    $autoplay,
                    $loop,
                    $muted,
                    $posterAttr
                );
            }
            break;
            
        case 'box':
            $bg = $element['bg'] ?? 'rgba(95,179,255,0.12)';
            $border = $element['border'] ?? '1px solid rgba(95,179,255,0.35)';
            $blur = isset($element['blur']) ? 'backdrop-filter:blur(' . $element['blur'] . 'px);' : '';
            
            $boxStyle = sprintf(
                'background:%s;border:%s;%s',
                $bg,
                $border,
                $blur
            );
            
            $html = sprintf(
                '<div class="el box" style="%s;%s" id="%s" data-type="box" data-tablet=\'%s\' data-mobile=\'%s\'></div>',
                $style,
                $boxStyle,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS)
            );
            break;
            
        case 'linkbtn':
            $text = $element['text'] ?? 'Кнопка';
            if ($translations && isset($translations[$id . '_text'])) {
                $text = $translations[$id . '_text'];
            }
            
            $url = $element['url'] ?? '#';
            $bg = $element['bg'] ?? '#3b82f6';
            $color = $element['color'] ?? '#ffffff';
            $fontSize = $element['fontSize'] ?? 16;
            $target = ($element['target'] ?? '_blank');
            
            $btnStyle = 'display:flex;align-items:center;justify-content:center;width:100%;height:100%;';
            $btnStyle .= sprintf('background:%s;color:%s;border-radius:inherit;text-decoration:none;font-weight:600;font-size:%dpx;transition:all 0.3s;', $bg, $color, $fontSize);
            
            $html = sprintf(
                '<div class="el linkbtn" style="%s" id="%s" data-type="linkbtn" data-tablet=\'%s\' data-mobile=\'%s\'>
                    <a href="%s" style="%s" target="%s">%s</a>
                </div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $url,
                $btnStyle,
                $target,
                htmlspecialchars($text)
            );
            break;
            
        case 'filebtn':
            $text = $element['text'] ?? 'Скачать файл';
            if ($translations && isset($translations[$id . '_text'])) {
                $text = $translations[$id . '_text'];
            }
            
            $fileUrl = processMediaPath($element['fileUrl'] ?? '#', $usedFiles);
            $fileName = $element['fileName'] ?? '';
            $bg = $element['bg'] ?? '#10b981';
            $color = $element['color'] ?? '#ffffff';
            $fontSize = $element['fontSize'] ?? 16;
            
            $btnStyle = 'display:flex;align-items:center;justify-content:center;width:100%;height:100%;gap:8px;';
            $btnStyle .= sprintf('background:%s;color:%s;border-radius:inherit;text-decoration:none;font-weight:600;font-size:%dpx;transition:all 0.3s;', $bg, $color, $fontSize);
            
            // Определяем иконку файла
            $icon = getFileIcon($fileName);
            
            $html = sprintf(
                '<div class="el filebtn" style="%s" id="%s" data-type="filebtn" data-tablet=\'%s\' data-mobile=\'%s\'>
                    <a href="%s" download="%s" style="%s">%s %s</a>
                </div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $fileUrl,
                $fileName,
                $btnStyle,
                $icon,
                htmlspecialchars($text)
            );
            break;
            
        
        
        case 'langbadge':
            // Языковой переключатель (как в редакторе)
            $langs = !empty($element['langs']) ? explode(',', $element['langs']) : $allLanguages;
            $langs = array_map('trim', $langs);
            $badgeColor = $element['badgeColor'] ?? '#2ea8ff';
            $fontSize = $element['fontSize'] ?? 14;

            $langMap = [
                'en' => '🇬🇧 English',
                'zh-Hans' => '🇨🇳 中文',
                'es' => '🇪🇸 Español',
                'hi' => '🇮🇳 हिन्दी',
                'ar' => '🇸🇦 العربية',
                'pt' => '🇵🇹 Português',
                'ru' => '🇷🇺 Русский',
                'de' => '🇩🇪 Deutsch',
                'fr' => '🇫🇷 Français',
                'it' => '🇮🇹 Italiano',
                'ja' => '🇯🇵 日本語',
                'ko' => '🇰🇷 한국어',
                'tr' => '🇹🇷 Türkçe',
                'uk' => '🇺🇦 Українська',
                'pl' => '🇵🇱 Polski',
                'nl' => '🇳🇱 Nederlands',
                'sv' => '🇸🇪 Svenska',
                'fi' => '🇫🇮 Suomi',
                'no' => '🇳🇴 Norsk',
                'da' => '🇩🇰 Dansk',
                'cs' => '🇨🇿 Čeština',
                'hu' => '🇭🇺 Magyar',
                'ro' => '🇷🇴 Română',
                'bg' => '🇧🇬 Български',
                'el' => '🇬🇷 Ελληνικά',
                'id' => '🇮🇩 Indonesia',
                'vi' => '🇻🇳 Tiếng Việt',
                'th' => '🇹🇭 ไทย',
                'he' => '🇮🇱 עברית',
                'fa' => '🇮🇷 فارسی',
                'ms' => '🇲🇾 Bahasa Melayu',
                'et' => '🇪🇪 Eesti',
                'lt' => '🇱🇹 Lietuvių',
                'lv' => '🇱🇻 Latviešu',
                'sk' => '🇸🇰 Slovenčina',
                'sl' => '🇸🇮 Slovenščina'
            ];

            $currentLang = $lang;
            $currentDisplay = $langMap[$currentLang] ?? ('🌐 ' . strtoupper($currentLang));
            $currentParts = explode(' ', $currentDisplay, 2);
            $currentFlag = $currentParts[0] ?? '🌐';
            $currentName = $currentParts[1] ?? strtoupper($currentLang);

            $optionsHtml = '';
            foreach ($langs as $l) {
                $l = trim($l);
                if ($l === '') continue;
                $display = $langMap[$l] ?? ('🌐 ' . strtoupper($l));
                $parts = explode(' ', $display, 2);
                $flag = $parts[0] ?? '🌐';
                $name = $parts[1] ?? strtoupper($l);

                $pageFilename = getPageFilename($page, $l, $primaryLang); // напр.: index.html или index-ru.html
                $active = ($l == $currentLang) ? ' active' : '';
                $optionsHtml .= sprintf(
                    '<a class="lang-option%s" href="%s"><span class="lang-flag">%s</span><span class="lang-name">%s</span></a>',
                    $active,
                    htmlspecialchars($pageFilename, ENT_QUOTES, 'UTF-8'),
                    $flag,
                    htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                );
            }

            $chipStyle = sprintf(' style="background:%s;border:1px solid %s;color:#fff;font-size:%dpx;"',
                $badgeColor,
                $badgeColor,
                $fontSize
            );

            $html = sprintf(
                '<div class="el langbadge" style="%s" id="%s" data-type="langbadge" data-tablet=\'%s\' data-mobile=\'%s\'>' .
                '<div class="lang-selector" onclick="this.querySelector(\'.lang-dropdown\').classList.toggle(\'show\')">' .
                '<div class="lang-chip"%s><span class="lang-flag">%s</span><span class="lang-name">%s</span></div>' .
                '<div class="lang-dropdown">%s</div>' .
                '</div>' .
                '</div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $chipStyle,
                $currentFlag,
                htmlspecialchars($currentName, ENT_QUOTES, 'UTF-8'),
                $optionsHtml
            );
            break;


            
        case 'embed':
            // Встраиваемый контент (iframe, embed code)
            $embedCode = $element['embedCode'] ?? '';
            if ($translations && isset($translations[$id . '_embedCode'])) {
                $embedCode = $translations[$id . '_embedCode'];
            }
            
            $html = sprintf(
                '<div class="el embed" style="%s" id="%s" data-type="embed" data-tablet=\'%s\' data-mobile=\'%s\'>%s</div>',
                $style,
                $id,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS),
                $embedCode
            );
            break;
            
        default:
            // Для неизвестных типов элементов
            $html = sprintf(
                '<div class="el %s" style="%s" id="%s" data-type="%s" data-tablet=\'%s\' data-mobile=\'%s\'></div>',
                $type,
                $style,
                $id,
                $type,
                json_encode($tabletElement ?: [], JSON_HEX_APOS),
                json_encode($mobileElement ?: [], JSON_HEX_APOS)
            );
            break;
    }
    
    return $html;
}

function getFileIcon($fileName) {
    if (!$fileName) return '📄';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $icons = [
        'zip' => '📦', 'rar' => '📦', '7z' => '📦', 'tar' => '📦', 'gz' => '📦',
        'pdf' => '📕',
        'doc' => '📘', 'docx' => '📘', 'odt' => '📘',
        'xls' => '📗', 'xlsx' => '📗', 'ods' => '📗', 'csv' => '📗',
        'ppt' => '📙', 'pptx' => '📙', 'odp' => '📙',
        'mp3' => '🎵', 'wav' => '🎵', 'ogg' => '🎵', 'flac' => '🎵', 'm4a' => '🎵',
        'mp4' => '🎬', 'avi' => '🎬', 'mkv' => '🎬', 'mov' => '🎬', 'webm' => '🎬',
        'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️', 'svg' => '🖼️', 'webp' => '🖼️',
        'txt' => '📝', 'md' => '📝',
        'html' => '🌐', 'css' => '🎨', 'js' => '⚡', 'json' => '📋',
        'exe' => '⚙️', 'dmg' => '⚙️', 'apk' => '📱',
    ];
    
    return $icons[$ext] ?? '📄';
}

function processMediaPath($path, &$usedFiles) {
    if (!$path || $path === '#') return $path;
    
    // Если это внешняя ссылка
    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }
    
    // Если это data URL
    if (strpos($path, 'data:') === 0) {
        return $path;
    }
    
    // Если это локальный файл из uploads
    if (strpos($path, '/editor/uploads/') === 0 || strpos($path, 'editor/uploads/') === 0) {
        $filename = basename($path);
        $sourcePath = dirname(__DIR__) . '/' . ltrim($path, '/');
        
        if (file_exists($sourcePath)) {
            $usedFiles[] = [
                'source' => $sourcePath,
                'dest' => 'assets/uploads/' . $filename
            ];
            return 'assets/uploads/' . $filename;
        }
    }
    
    return $path;
}

function processHtmlContent($html, &$usedFiles) {
    // Обрабатываем все src и href в HTML
    $html = preg_replace_callback(
        '/(src|href)=["\']([^"\']+)["\']/i',
        function($matches) use (&$usedFiles) {
            $path = processMediaPath($matches[2], $usedFiles);
            return $matches[1] . '="' . $path . '"';
        },
        $html
    );
    
    // Обрабатываем background в style
    $html = preg_replace_callback(
        '/background(-image)?:\s*url\(["\']?([^"\')]+)["\']?\)/i',
        function($matches) use (&$usedFiles) {
            $path = processMediaPath($matches[2], $usedFiles);
            return 'background' . $matches[1] . ':url(' . $path . ')';
        },
        $html
    );
    
    return $html;
}

function generateAssets($exportDir) {
    // CSS файл
    $css = <<<CSS
* { 
    margin: 0; 
    padding: 0; 
    box-sizing: border-box; 
}

body {
    background: #0e141b;
    color: #e6f0fa;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    margin: 0;
    overflow-x: hidden;
}

.wrap {
    position: relative;
    min-height: 100vh;
    overflow-x: hidden;
    width: 100%;
}
/* iOS/Android: корректная динамическая высота вьюпорта */
@supports (height: 100dvh) {
    .wrap { min-height: 100dvh; }
}


.el {
    position: absolute;
    box-sizing: border-box;
    transition: none;
}

/* Текстовые элементы */
.el.text {
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}

.el.text p,
.el.text h1,
.el.text h2,
.el.text h3,
.el.text h4,
.el.text h5,
.el.text h6,
.el.text ul,
.el.text ol {
    margin: 0;
    padding: 0;
}

.el.text li {
    margin: 0 0 0.35em;
}

.el.text p + p {
    margin-top: 0.35em;
}

.el.text a {
    color: inherit;
}

/* Изображения */
.el.image {
    overflow: hidden;
}

.el img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center;
    border-radius: inherit;
    display: block;
}

/* Видео */
.el.video {
    overflow: hidden;
}

.el video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
    display: block;
}

/* Блоки */
.el.box {
    pointer-events: none;
}

/* Кнопки */
.el.linkbtn,
.el.filebtn {
    overflow: hidden;
    cursor: pointer;
}

.el.linkbtn a, 
.el.filebtn a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    gap: 8px;
}

.el.linkbtn a:hover, 
.el.filebtn a:hover {
    opacity: 0.9;
    transform: scale(1.02);
}

.el.linkbtn a:active,
.el.filebtn a:active {
    transform: scale(0.98);
}

/* Языковой переключатель (как в редакторе) */
.el.langbadge { background: transparent !important; border: none !important; padding: 0 !important; }
.lang-selector { position: relative; cursor: pointer; display: inline-block; }
.lang-chip { padding: 8px 16px; border-radius: 12px; border: 1px solid #2ea8ff; background: #0f1723; color: #fff; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; }
.lang-chip:hover { background: #2ea8ff; transform: scale(1.05); }
.lang-flag { font-size: 20px; line-height: 1; }
.lang-dropdown { position: absolute; top: calc(100% + 8px); left: 0; display: none; min-width: 220px; max-height: 280px; overflow-y: auto; background: rgba(12, 18, 26, 0.96); border: 1px solid rgba(46,168,255,0.25); border-radius: 12px; padding: 10px; box-shadow: 0 8px 24px rgba(46,168,255,0.2); backdrop-filter: blur(8px); z-index: 9999; }
.lang-dropdown.show { display: block !important; }
.lang-option { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; text-decoration: none; color: #e8f2ff; transition: background .2s ease; }
.lang-option:hover { background: rgba(46, 168, 255, 0.12); }
.lang-option.active { background: #2ea8ff; color: #fff; }
.lang-dropdown::-webkit-scrollbar { width: 8px; }
.lang-dropdown::-webkit-scrollbar-track { background: #0b111a; border-radius: 4px; }
.lang-dropdown::-webkit-scrollbar-thumb { background: #2a3f5f; border-radius: 4px; }
.lang-dropdown::-webkit-scrollbar-thumb:hover { background: #3a5070; }
/* Встраиваемый контент */
.el.embed {
    overflow: hidden;
}

.el.embed iframe {
    width: 100%;
    height: 100%;
    border: none;
    border-radius: inherit;
}

/* Адаптивность для планшетов */
@media (max-width: 768px) and (min-width: 481px) {
    .wrap {
        min-height: 100vh;
    }
    
    .el.text {
        font-size: calc(100% - 2px) !important;
    }
}

/* Адаптивность для мобильных устройств */
@media (max-width: 480px) {
    .wrap {
        min-height: 100vh;
    }
    
    .el {
        transition: none !important;
    }
    
    .el.text {
        font-size: max(14px, calc(100% - 4px)) !important;
        line-height: 1.4 !important;
    }
    
    .el.langbadge .lang-chip {
        font-size: 14px !important;
        padding: 6px 12px !important;
    }
}

/* Анимации при загрузке */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.el {
    animation: fadeIn 0.5s ease-out;
}

/* Печать */
@media print {
    .el.langbadge {
        display: none !important;
    }
    
    .wrap {
        min-height: auto;
    }
}
CSS;
    
    file_put_contents($exportDir . '/assets/style.css', $css);
    
    // JavaScript файл
    $js = <<<JS
(function() {
    'use strict';
    const DESKTOP_W = 1200, TABLET_W = 768, MOBILE_W = 375, EDITOR_H = 1500;
    
    // Функция для применения адаптивных стилей
    function applyResponsive() {
        const width = window.innerWidth;
        const elements = document.querySelectorAll('.el[data-tablet], .el[data-mobile]');
        
        elements.forEach(el => {
            try {
                let styles = {};
                let baseW = DESKTOP_W;
                
                if (width <= 480 && el.dataset.mobile) {
                    // Мобильные устройства
                    styles = JSON.parse(el.dataset.mobile);
                    baseW = MOBILE_W;
                } else if (width <= 768 && width > 480 && el.dataset.tablet) {
                    // Планшеты
                    styles = JSON.parse(el.dataset.tablet);
                } else {
                    // Десктоп - восстанавливаем оригинальные стили
                    if (el.dataset.originalStyle) {
                        el.setAttribute('style', el.dataset.originalStyle);
                    }
                    return;
                }
                
                // Сохраняем оригинальные стили
                if (!el.dataset.originalStyle) {
                    el.dataset.originalStyle = el.getAttribute('style');
                }
                
                // Применяем адаптивные стили
                if (styles.left !== undefined) el.style.left = styles.left + '%';
                if (styles.top !== undefined) { el.style.top = ((styles.top / baseW) * 100).toFixed(4) + 'vw'; }
                if (styles.width !== undefined) el.style.width = styles.width + '%';
                if (styles.height !== undefined && el.dataset.type !== 'text') {
                    el.style.height = ((((styles.height / 100) * EDITOR_H) / baseW) * 100).toFixed(4) + 'vw';
                }
                if (styles.fontSize !== undefined) {
                    const textEl = el.querySelector('a, span, div');
                    if (textEl) textEl.style.fontSize = styles.fontSize + 'px';
                }
                if (styles.padding !== undefined) {
                    const padTarget = el.querySelector('a, span, div') || el;
                    padTarget.style.padding = styles.padding + 'px';
                }
                if (styles.radius !== undefined) {
                    el.style.borderRadius = styles.radius + 'px';
                    const rEl = el.querySelector('a');
                    if (rEl) rEl.style.borderRadius = styles.radius + 'px';
                }
                if (styles.rotate !== undefined) {
                    el.style.transform = 'rotate(' + styles.rotate + 'deg)';
                }
            } catch(e) {
                console.error('Error applying responsive styles:', e);
            }
        });
    }
    
    // Функция для обработки высоты контейнера
    function adjustWrapHeight() {
        const wrap = document.querySelector('.wrap');
        if (!wrap) return;
        
        const elements = document.querySelectorAll('.el');
        let maxBottom = 0;
        
        elements.forEach(el => {
            const rect = el.getBoundingClientRect();
            const bottom = el.offsetTop + rect.height;
            if (bottom > maxBottom) {
                maxBottom = bottom;
            }
        });
        
        if (maxBottom > 0) {
            wrap.style.minHeight = Math.max(maxBottom + 100, window.innerHeight) + 'px';
        }
    }
    
    // Функция для плавной прокрутки к якорям
    function initSmoothScroll() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }
    
    // Функция для обработки ленивой загрузки изображений
    function initLazyLoad() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                            observer.unobserve(img);
                        }
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
    }
    
    // Инициализация при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        applyResponsive();
        adjustWrapHeight();
        initSmoothScroll();
        initLazyLoad();
    });
    
    // Обработка изменения размера окна
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            applyResponsive();
            adjustWrapHeight();
        }, 250);
    });
    
    // Обработка изменения ориентации устройства
    window.addEventListener('orientationchange', function() {
        setTimeout(function() {
            applyResponsive();
            adjustWrapHeight();
        }, 100);
    });
    
    // Автоопределение языка браузера при первом посещении
    if (!localStorage.getItem('site_lang_set')) {
        const browserLang = navigator.language.substring(0, 2);
        const currentLang = document.documentElement.lang;
        
        // Если язык браузера английский, а страница не английская
        if (browserLang === 'en' && currentLang !== 'en') {
            // Пытаемся найти английскую версию
            const currentPath = window.location.pathname;
            const currentFile = currentPath.split('/').pop() || 'index.html';
            
            // Определяем имя английской версии
            let enFile;
            if (currentFile === 'index.html' || currentFile === '') {
                enFile = '/index.html'; // Если английский основной
            } else if (currentFile.includes('-ru.html')) {
                enFile = currentFile.replace('-ru.html', '.html');
            } else {
                enFile = currentFile.replace('.html', '-en.html');
            }
            
            // Проверяем существование английской версии
            fetch(enFile, { method: 'HEAD' })
                .then(response => {
                    if (response.ok) {
                        localStorage.setItem('site_lang_set', 'true');
                        window.location.href = enFile;
                    }
                })
                .catch(() => {
                    localStorage.setItem('site_lang_set', 'true');
                });
        } else {
            localStorage.setItem('site_lang_set', 'true');
        }
    }
})();
JS;
    
    file_put_contents($exportDir . '/assets/js/main.js', $js);
}

function copyUsedFiles($usedFiles, $exportDir) {
    foreach ($usedFiles as $file) {
        if (file_exists($file['source'])) {
            $destPath = $exportDir . '/' . $file['dest'];
            $destDir = dirname($destPath);
            
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0777, true);
            }
            
            @copy($file['source'], $destPath);
        }
    }
}

function generateHtaccess($exportDir) {
    $htaccess = <<<HTACCESS
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /

# Защита от доступа к служебным файлам
<FilesMatch "\\.(htaccess|htpasswd|ini|log|sh)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Автоопределение языка браузера (только для главной страницы)
RewriteCond %{HTTP:Accept-Language} ^en [NC]
RewriteCond %{REQUEST_URI} ^/?$
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^$ /index.html [L,R=302]

# Убираем .html из URL для всех страниц включая языковые версии
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([^/]+)/?$ $1.html [L]

# Редирект с .html на без .html
RewriteCond %{THE_REQUEST} \\s/+([^/]+)\\.html[\\s?] [NC]
RewriteRule ^ /%1 [R=301,L]

# Кеширование статических файлов
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/webp "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType text/javascript "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
</IfModule>

# Сжатие
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>
</IfModule>
HTACCESS;
    
    file_put_contents($exportDir . '/.htaccess', $htaccess);
}

function generateNginxConfig($exportDir) {
    $nginx = <<<NGINX
# Nginx конфигурация для экспортированного сайта
# Добавьте эти правила в блок server {} вашей конфигурации

# Убираем .html из URL
location / {
    try_files \$uri \$uri.html \$uri/ =404;
}

# Редирект с .html на без .html
location ~ \\.html$ {
    if (\$request_uri ~ ^(.*)\\.html$) {
        return 301 \$1;
    }
}

# Кеширование статических файлов
location ~* \\.(jpg|jpeg|png|gif|webp|ico|css|js)$ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}

# Сжатие
gzip on;
gzip_comp_level 6;
gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml+rss;
gzip_vary on;

# Защита от доступа к служебным файлам
location ~ /\\. {
    deny all;
}

location ~ \\.(htaccess|htpasswd|ini|log|sh)$ {
    deny all;
}
NGINX;
    
    file_put_contents($exportDir . '/nginx.conf.example', $nginx);
}

function generateRobots($exportDir) {
    $robots = <<<ROBOTS
User-agent: *
Allow: /
Disallow: /assets/uploads/

Sitemap: /sitemap.xml
ROBOTS;
    
    file_put_contents($exportDir . '/robots.txt', $robots);
}

function generateSitemap($exportDir, $pages, $languages, $primaryLang = 'ru') {
    $sitemap = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $sitemap .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    
    foreach ($pages as $page) {
        // URL для каждого языка (все в корне)
        foreach ($languages as $lang) {
            $filename = getPageFilename($page, $lang, $primaryLang);
            $loc = '/' . str_replace('.html', '', $filename);
            
            $sitemap .= "  <url>\n";
            $sitemap .= "    <loc>{$loc}</loc>\n";
            
            // Добавляем альтернативные языковые версии
            foreach ($languages as $altLang) {
                $altFilename = getPageFilename($page, $altLang, $primaryLang);
                $href = '/' . str_replace('.html', '', $altFilename);
                $sitemap .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$altLang}\" href=\"{$href}\"/>\n";
            }
            
            $sitemap .= "    <changefreq>weekly</changefreq>\n";
            $priority = $page['is_home'] ? '1.0' : '0.8';
            if ($lang !== 'ru') {
                $priority = $page['is_home'] ? '0.9' : '0.7';
            }
            $sitemap .= "    <priority>{$priority}</priority>\n";
            $sitemap .= "  </url>\n";
        }
    }
    
    $sitemap .= '</urlset>';
    
    file_put_contents($exportDir . '/sitemap.xml', $sitemap);
}

function createZipArchive($sourceDir) {
    $zipFile = sys_get_temp_dir() . '/export_' . time() . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Cannot create ZIP archive');
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourceDir) + 1);
        
        // Нормализуем путь для ZIP
        $relativePath = str_replace('\\', '/', $relativePath);
        
        $zip->addFile($filePath, $relativePath);
    }
    
    $zip->close();
    return $zipFile;
}

function generateReadme($exportDir, $languages) {
    $langList = implode(', ', $languages);
    $readme = <<<README
# Экспортированный сайт

## Структура файлов

Все страницы находятся в корневой папке с языковыми суффиксами:
- `index.html` - главная страница (русский язык)
- `index-en.html` - главная страница (английский язык)
- `about.html` - страница "О нас" (русский язык)
- `about-en.html` - страница "О нас" (английский язык)
- и т.д.

## Языковые версии

Поддерживаемые языки: {$langList}

Русский язык является основным и не имеет суффикса в именах файлов.
Остальные языки добавляют суффикс `-код_языка` к имени файла.

## Структура папок

```
/
├── assets/
│   ├── style.css         # Основные стили
│   ├── js/
│   │   └── main.js       # JavaScript для адаптивности
│   └── uploads/          # Загруженные файлы
├── index.html            # Главная страница (RU)
├── index-en.html         # Главная страница (EN)
├── .htaccess             # Конфигурация Apache
├── nginx.conf.example    # Пример конфигурации Nginx
├── robots.txt            # Для поисковых роботов
└── sitemap.xml           # Карта сайта
```

## Установка на хостинг

### Apache
Файл `.htaccess` уже настроен. Просто загрузите все файлы на хостинг.

### Nginx
Используйте настройки из файла `nginx.conf.example`, добавив их в конфигурацию сервера.

## Особенности

1. **Красивые URL**: расширение .html автоматически скрывается
2. **Адаптивность**: сайт адаптирован для мобильных устройств и планшетов
3. **Многоязычность**: встроенный переключатель языков
4. **SEO-оптимизация**: sitemap.xml с поддержкой hreflang
5. **Производительность**: настроено кеширование и сжатие

## Поддержка

Сайт создан с помощью конструктора Zerro Blog.
README;
    
    file_put_contents($exportDir . '/README.md', $readme);
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    rmdir($dir);
}
function generateRemoteAPI($exportDir) {
    $apiContent = '<?php
// Remote Management API for exported site
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$action = $_REQUEST["action"] ?? "";

switch($action) {
    case "ping":
        echo json_encode(["ok" => true, "version" => "1.0"]);
        break;
        
    case "list_files":
        $files = [];
        foreach(glob("*.html") as $htmlFile) {
            $content = file_get_contents($htmlFile);
            
            // Ищем кнопки-файлы (класс filebtn) - ВАЖНО: порядок атрибутов может меняться!
            // Паттерн 1: href перед download
            preg_match_all(\'/<div[^>]+class="[^"]*filebtn[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"[^>]*download="([^"]*)"[^>]*>/is\', $content, $matches1);
            
            for($i = 0; $i < count($matches1[0]); $i++) {
                $url = $matches1[1][$i];
                $fileName = $matches1[2][$i] ?: basename($url);
                
                if ($url !== "#") {
                    $files[] = [
                        "name" => $fileName,
                        "url" => $url,
                        "type" => "filebtn"
                    ];
                }
            }
            
            // Паттерн 2: download перед href
            preg_match_all(\'/<div[^>]+class="[^"]*filebtn[^"]*"[^>]*>.*?<a[^>]+download="([^"]*)"[^>]*href="([^"]+)"[^>]*>/is\', $content, $matches2);
            
            for($i = 0; $i < count($matches2[0]); $i++) {
                $url = $matches2[2][$i];
                $fileName = $matches2[1][$i] ?: basename($url);
                
                if ($url !== "#") {
                    $files[] = [
                        "name" => $fileName,
                        "url" => $url,
                        "type" => "filebtn"
                    ];
                }
            }
            
            // Паттерн 3: Простые ссылки с download
            preg_match_all(\'/<a[^>]+download="([^"]+)"[^>]*href="([^"]+)"/i\', $content, $matches3);
            
            for($i = 0; $i < count($matches3[0]); $i++) {
                $files[] = [
                    "name" => $matches3[1][$i],
                    "url" => $matches3[2][$i],
                    "type" => "simple"
                ];
            }
        }
        
        // Удаляем дубликаты по URL
        $unique = [];
        foreach($files as $file) {
            $key = $file["url"];
            if (!isset($unique[$key])) {
                $unique[$key] = $file;
            }
        }
        
        echo json_encode(["ok" => true, "items" => array_values($unique)]);
        break;
        
    case "list_links":
        $links = [];
        foreach(glob("*.html") as $htmlFile) {
            $content = file_get_contents($htmlFile);
            
            // Ищем кнопки-ссылки
            preg_match_all(\'/<div[^>]+class="[^"]*linkbtn[^"]*"[^>]*>.*?<a[^>]+href="([^"]+)"/is\', $content, $matches);
            
            foreach($matches[1] as $url) {
                if ($url !== "#") {
                    $links[] = ["url" => $url];
                }
            }
        }
        
        // Удаляем дубликаты
        $unique = [];
        foreach($links as $link) {
            $key = $link["url"];
            if (!isset($unique[$key])) {
                $unique[$key] = $link;
            }
        }
        
        echo json_encode(["ok" => true, "items" => array_values($unique)]);
        break;
        
    case "replace_file":
        $oldUrl = $_POST["old_url"] ?? "";
        $fileName = $_POST["file_name"] ?? "";
        $fileContent = $_POST["file_content"] ?? "";
        
        if (!$oldUrl || !$fileName || !$fileContent) {
            echo json_encode(["ok" => false, "error" => "Missing parameters"]);
            break;
        }
        
        // Сохраняем новый файл
        $uploadDir = "assets/uploads/";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
        
        $newFileName = basename($fileName);
        $newPath = $uploadDir . $newFileName;
        
        // Декодируем и сохраняем файл
        $decodedContent = base64_decode($fileContent);
        if ($decodedContent === false) {
            echo json_encode(["ok" => false, "error" => "Failed to decode file content"]);
            break;
        }
        
        file_put_contents($newPath, $decodedContent);
        
        if (!file_exists($newPath)) {
            echo json_encode(["ok" => false, "error" => "Failed to save file"]);
            break;
        }
        
        // СПЕЦИАЛЬНАЯ ЛОГИКА ДЛЯ ЗАМЕНЫ ФАЙЛОВ
        $replaced = 0;
        $totalFiles = 0;
        $debugInfo = [];
        
        // Экранируем специальные символы для регулярных выражений
        $oldUrlEscaped = preg_quote($oldUrl, \'/\');
        $oldFileName = basename($oldUrl);
        $oldFileNameEscaped = preg_quote($oldFileName, \'/\');
        
        foreach(glob("*.html") as $htmlFile) {
            $totalFiles++;
            $content = file_get_contents($htmlFile);
            $originalContent = $content;
            $localReplacements = 0;
            
            // МЕТОД 1: Замена в кнопках-файлах (filebtn) - ищем весь блок и заменяем href и download
            $pattern = \'/<div[^>]+class="[^"]*filebtn[^"]*"[^>]*>(.*?)<\\/div>/is\';
            $content = preg_replace_callback($pattern, function($matches) use ($oldUrl, $newPath, $newFileName, &$localReplacements) {
                $block = $matches[0];
                $innerHtml = $matches[1];
                
                // Проверяем, содержит ли этот блок наш старый URL
                if (strpos($innerHtml, $oldUrl) !== false || strpos($innerHtml, basename($oldUrl)) !== false) {
                    // Заменяем href
                    $innerHtml = preg_replace(\'/href="[^"]*"/\', \'href="\' . $newPath . \'"\', $innerHtml);
                    // Заменяем download
                    $innerHtml = preg_replace(\'/download="[^"]*"/\', \'download="\' . $newFileName . \'"\', $innerHtml);
                    $localReplacements++;
                    return str_replace($matches[1], $innerHtml, $block);
                }
                return $block;
            }, $content);
            
            // МЕТОД 2: Прямая замена старого URL на новый во всем документе
            if (strpos($content, $oldUrl) !== false) {
                $content = str_replace($oldUrl, $newPath, $content);
                $localReplacements++;
            }
            
            // МЕТОД 3: Замена по имени файла в href (если путь отличается)
            $content = preg_replace(
                \'/href="[^"]*\' . $oldFileNameEscaped . \'"/i\',
                \'href="\' . $newPath . \'"\',
                $content
            );
            
            // МЕТОД 4: Замена в download атрибутах
            $content = preg_replace(
                \'/download="[^"]*\' . $oldFileNameEscaped . \'"/i\',
                \'download="\' . $newFileName . \'"\',
                $content
            );
            
            // МЕТОД 5: Супер агрессивная замена - ищем любое упоминание файла и заменяем весь атрибут
            // Для href
            $content = preg_replace(
                \'/(href=")([^"]*)\' . $oldFileNameEscaped . \'([^"]*")/i\',
                \'$1\' . $newPath . \'$3\',
                $content
            );
            
            // Сохраняем файл если были изменения
            if ($content !== $originalContent) {
                file_put_contents($htmlFile, $content);
                $replaced++;
                $debugInfo[] = [
                    "file" => $htmlFile,
                    "replacements" => $localReplacements,
                    "old_found" => strpos($originalContent, $oldUrl) !== false,
                    "old_filename_found" => strpos($originalContent, $oldFileName) !== false
                ];
            } else {
                // Даже если не заменили, проверяем наличие старого URL для отладки
                $debugInfo[] = [
                    "file" => $htmlFile,
                    "replacements" => 0,
                    "old_found" => strpos($originalContent, $oldUrl) !== false,
                    "old_filename_found" => strpos($originalContent, $oldFileName) !== false,
                    "filebtn_found" => strpos($originalContent, "filebtn") !== false
                ];
            }
        }
        
        // Если первый проход не дал результатов, пробуем еще более агрессивный подход
        if ($replaced === 0 && $totalFiles > 0) {
            foreach(glob("*.html") as $htmlFile) {
                $content = file_get_contents($htmlFile);
                $originalContent = $content;
                
                // Находим ВСЕ href атрибуты и проверяем каждый
                $content = preg_replace_callback(
                    \'/href="([^"]+)"/i\',
                    function($matches) use ($oldFileName, $newPath) {
                        $currentHref = $matches[1];
                        // Если текущий href содержит имя старого файла - заменяем весь href
                        if (strpos($currentHref, $oldFileName) !== false) {
                            return \'href="\' . $newPath . \'"\';
                        }
                        return $matches[0];
                    },
                    $content
                );
                
                // То же самое для download атрибутов
                $content = preg_replace_callback(
                    \'/download="([^"]+)"/i\',
                    function($matches) use ($oldFileName, $newFileName) {
                        $currentDownload = $matches[1];
                        if (strpos($currentDownload, $oldFileName) !== false) {
                            return \'download="\' . $newFileName . \'"\';
                        }
                        return $matches[0];
                    },
                    $content
                );
                
                if ($content !== $originalContent) {
                    file_put_contents($htmlFile, $content);
                    $replaced++;
                }
            }
        }
        
        echo json_encode([
            "ok" => true,
            "replaced" => $replaced,
            "new_path" => $newPath,
            "total_files" => $totalFiles,
            "debug" => [
                "old_url" => $oldUrl,
                "old_filename" => $oldFileName,
                "new_file" => $newFileName,
                "new_path" => $newPath,
                "file_saved" => file_exists($newPath),
                "file_size" => file_exists($newPath) ? filesize($newPath) : 0,
                "details" => $debugInfo
            ]
        ]);
        break;
        
    case "replace_link":
        $oldUrl = $_POST["old_url"] ?? "";
        $newUrl = $_POST["new_url"] ?? "";
        
        if (!$oldUrl || !$newUrl) {
            echo json_encode(["ok" => false, "error" => "Missing parameters"]);
            break;
        }
        
        // Заменяем во всех HTML файлах
        $replaced = 0;
        foreach(glob("*.html") as $htmlFile) {
            $content = file_get_contents($htmlFile);
            $originalContent = $content;
            
            // Метод 1: Прямая замена
            $content = str_replace(\'href="\' . $oldUrl . \'"\', \'href="\' . $newUrl . \'"\', $content);
            
            // Метод 2: Замена в кнопках-ссылках
            $pattern = \'/<div[^>]+class="[^"]*linkbtn[^"]*"[^>]*>(.*?)<\\/div>/is\';
            $content = preg_replace_callback($pattern, function($matches) use ($oldUrl, $newUrl) {
                $block = $matches[0];
                if (strpos($block, $oldUrl) !== false) {
                    return str_replace($oldUrl, $newUrl, $block);
                }
                return $block;
            }, $content);
            
            if ($content !== $originalContent) {
                file_put_contents($htmlFile, $content);
                $replaced++;
            }
        }
        
        echo json_encode(["ok" => true, "replaced" => $replaced]);
        break;
        
    default:
        echo json_encode(["ok" => false, "error" => "Unknown action"]);
}
?>';
    
    file_put_contents($exportDir . '/remote-api.php', $apiContent);
}