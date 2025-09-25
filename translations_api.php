<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Долгие задачи перевода
@set_time_limit(0);
@ini_set('max_execution_time', '0');

$db = dirname(__DIR__) . '/data/zerro_blog.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensureSchema($pdo);

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'saveToken': {
        $token = trim($_POST['token'] ?? '');
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO translation_settings (key, value) VALUES ('deepl_token', ?)");
        $stmt->execute([$token]);
        echo json_encode(['ok' => true]);
        break;
    }

    case 'getToken': {
        $token = (string)($pdo->query("SELECT value FROM translation_settings WHERE key='deepl_token'")->fetchColumn() ?: '');
        echo json_encode(['ok' => true, 'token' => $token]);
        break;
    }

    case 'deleteToken': {
        $pdo->prepare("DELETE FROM translation_settings WHERE key='deepl_token'")->execute();
        echo json_encode(['ok' => true]);
        break;
    }

    case 'deleteTranslations': {
        $pdo->exec("DELETE FROM translations");
        echo json_encode(['ok' => true]);
        break;
    }

    case 'getTranslations': {
        $pageId = (int)($_GET['page_id'] ?? 0);
        $lang   = (string)($_GET['lang'] ?? 'ru');

        $stmt = $pdo->prepare("SELECT * FROM translations WHERE page_id=? AND lang=?");
        $stmt->execute([$pageId, $lang]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['element_id'] . '_' . $row['field']] = $row['content'];
        }
        echo json_encode(['ok' => true, 'translations' => $out]);
        break;
    }

    case 'translate': {
        // Настройки
        $token = (string)($pdo->query("SELECT value FROM translation_settings WHERE key='deepl_token'")->fetchColumn() ?: '');
        if (!$token) {
            echo json_encode(['ok' => false, 'error' => 'Токен DeepL не настроен']);
            break;
        }
        $apiBase = (string)($pdo->query("SELECT value FROM translation_settings WHERE key='deepl_api_base'")->fetchColumn() ?: 'https://api-free.deepl.com');
        $deeplUrl = rtrim($apiBase, '/') . '/v2/translate';

        $pageId      = (int)($_POST['page_id'] ?? 0);
        $targetLangs = json_decode((string)($_POST['languages'] ?? '[]'), true) ?: [];

        // Страница
        $stmt = $pdo->prepare("SELECT * FROM pages WHERE id=?");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) {
            echo json_encode(['ok' => false, 'error' => 'Страница не найдена']);
            break;
        }

        $data = json_decode((string)$page['data_json'], true) ?: [];
        $elements = (array)($data['elements'] ?? []);

        $results = [];
        $errors  = [];

        foreach ($targetLangs as $lang) {
            if ($lang === 'ru') continue; // исходный

            $deeplLang = mapToDeeplLang($lang);

            // Сбор сегментов (meta + элементы)
            $segments = []; // каждый сегмент: ['element_id','field','text','is_html','source_hash']

            // meta title/description
            if (!empty($page['meta_title'])) {
                $txt = (string)$page['meta_title'];
                $segments[] = [
                    'element_id'  => 'meta',
                    'field'       => 'title',
                    'text'        => $txt,
                    'is_html'     => false,
                    'source_hash' => sha1($txt),
                ];
            }
            if (!empty($page['meta_description'])) {
                $txt = (string)$page['meta_description'];
                $segments[] = [
                    'element_id'  => 'meta',
                    'field'       => 'description',
                    'text'        => $txt,
                    'is_html'     => false,
                    'source_hash' => sha1($txt),
                ];
            }

            // элементы
            foreach ($elements as $el) {
                $type = $el['type'] ?? '';
                $id   = (string)($el['id'] ?? '');

                if ($type === 'text' && !empty($el['html'])) {
                    $html = (string)$el['html'];
                    $segments[] = [
                        'element_id'  => $id,
                        'field'       => 'html',
                        'text'        => $html,
                        'is_html'     => true,
                        'source_hash' => sha1($html),
                    ];
                } elseif (in_array($type, ['linkbtn','filebtn'], true) && !empty($el['text'])) {
                    $txt = (string)$el['text'];
                    $segments[] = [
                        'element_id'  => $id,
                        'field'       => 'text',
                        'text'        => $txt,
                        'is_html'     => false,
                        'source_hash' => sha1($txt),
                    ];
                }
            }

            // Отсев неизменённого (по source_hash)
            $segmentsToTranslate = [];
            foreach ($segments as $seg) {
                $exists = $pdo->prepare("SELECT source_hash FROM translations WHERE page_id=? AND element_id=? AND lang=? AND field=?");
                $exists->execute([$pageId, $seg['element_id'], $lang, $seg['field']]);
                $prevHash = (string)($exists->fetchColumn() ?: '');
                if ($prevHash === $seg['source_hash']) {
                    $results[] = "↷ Пропуск {$seg['element_id']}: уже актуально для $lang";
                } else {
                    $segmentsToTranslate[] = $seg;
                }
            }

            if (!$segmentsToTranslate) continue;

            // Пакетный перевод с бэк‑оффом
            $batchResults = translateSegmentsBatch($deeplUrl, $token, $deeplLang, $segmentsToTranslate);

            // Сохранение
            foreach ($batchResults['done'] as $i => $translated) {
                $seg = $segmentsToTranslate[$i];
                $content = $seg['is_html'] ? normalizeSpacesAroundTags($translated) : $translated;
                saveTranslation($pdo, $pageId, $seg['element_id'], $lang, $seg['field'], $content, $seg['source_hash']);
                $pretty = $seg['element_id'] === 'meta' ? strtoupper($seg['field']) : $seg['element_id'];
                $results[] = "✔ {$pretty} → $lang";
            }

            foreach ($batchResults['errors'] as $err) {
                $errors[] = "✖ {$err}";
            }

            if ($batchResults['fatal']) {
                // Например, 456 — квота
                echo json_encode(['ok' => false, 'results' => $results, 'error' => $batchResults['fatal']]);
                exit;
            }
        }

        echo json_encode(['ok' => true, 'results' => $results, 'errors' => $errors]);
        break;
    }

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        break;
}

/* ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ==================== */

function ensureSchema(PDO $pdo): void {
    // добавляем source_hash, если его ещё нет
    $cols = $pdo->query("PRAGMA table_info(translations)")->fetchAll(PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) if (strcasecmp($c['name'], 'source_hash') === 0) { $has = true; break; }
    if (!$has) {
        $pdo->exec("ALTER TABLE translations ADD COLUMN source_hash TEXT DEFAULT ''");
    }
    // индекс для частых выборок
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_translations_page_lang ON translations(page_id, lang)");
}

function mapToDeeplLang(string $lang): string {
    $map = [
        'en' => 'EN-GB',
        'zh-Hans' => 'ZH',
        'es' => 'ES',
        'pt' => 'PT-PT',
        'de' => 'DE',
        'fr' => 'FR',
        'ja' => 'JA',
        'ar' => 'AR',
        'it' => 'IT',
        'ko' => 'KO',
        'nl' => 'NL',
        'pl' => 'PL',
        'tr' => 'TR',
        'cs' => 'CS',
        'da' => 'DA',
        'fi' => 'FI',
        'el' => 'EL',
        'he' => 'HE',
        'hi' => 'HI',
        'hu' => 'HU',
        'id' => 'ID',
        'no' => 'NB',
        'ro' => 'RO',
        'sv' => 'SV',
        'uk' => 'UK',
        'bg' => 'BG',
        'et' => 'ET',
        'lt' => 'LT',
        'lv' => 'LV',
        'sk' => 'SK',
        'sl' => 'SL'
    ];
    return $map[$lang] ?? strtoupper($lang);
}

// Нормализация пробелов вокруг тегов, безопасная для HTML
function normalizeSpacesAroundTags(string $html): string {
    $html = preg_replace('/\s*(<\/?[a-z0-9][^>]*>)\s*/i', '$1', $html);
    $html = preg_replace('/\s{2,}/', ' ', $html);
    return trim($html);
}

function saveTranslation(PDO $pdo, int $pageId, string $elementId, string $lang, string $field, string $content, string $sourceHash): void {
    $sql = "INSERT INTO translations(page_id, element_id, lang, field, content, source_hash)
            VALUES(?,?,?,?,?,?)
            ON CONFLICT(page_id, element_id, lang, field)
            DO UPDATE SET content=excluded.content, source_hash=excluded.source_hash, created_at=CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pageId, $elementId, $lang, $field, $content, $sourceHash]);
}

// Пакетный перевод (батч + ретраи)
// Возвращает: ['done' => [idx => text, ...], 'errors' => [..], 'fatal' => '...']
function translateSegmentsBatch(string $deeplUrl, string $token, string $targetLang, array $segments): array {
    // Лимиты батча (консервативно)
    $MAX_ITEMS = 30;
    $MAX_CHARS = 18000;

    $done   = [];
    $errors = [];
    $fatal  = '';

    // Разбивка на батчи
    $batch = [];
    $batchMeta = []; // индексы сегментов
    $chars = 0;

    $flush = function() use (&$batch, &$batchMeta, &$chars, $deeplUrl, $token, $targetLang, &$done, &$errors, &$fatal) {
        if (!$batch) return;

        // Пытаемся одним запросом с tag_handling=html (для plain‑текста это безопасно)
        $fields = [
            'auth_key'            => $token,
            'target_lang'         => $targetLang,
            'tag_handling'        => 'html',
            'preserve_formatting' => 1,
            'split_sentences'     => 'nonewlines',
            'text'                => $batch, // массив
        ];
        $resp = deeplRequest($deeplUrl, $fields);

        if ($resp['ok']) {
            $translations = $resp['data']['translations'] ?? [];
            if (count($translations) === count($batch)) {
                foreach ($batchMeta as $k => $segIndex) {
                    $done[$segIndex] = (string)$translations[$k]['text'];
                }
            } else {
                $errors[] = 'Несоответствие размеров батча и ответа DeepL';
                // Фолбэк: поштучно
                fallbackPerItem($deeplUrl, $token, $targetLang, $batch, $batchMeta, $done, $errors, $fatal);
            }
        } else {
            // 456 — фатально (квота)
            if ($resp['http'] === 456) {
                $fatal = 'Превышена месячная квота DeepL (HTTP 456).';
            } else {
                // Фолбэк: поштучно
                fallbackPerItem($deeplUrl, $token, $targetLang, $batch, $batchMeta, $done, $errors, $fatal);
            }
        }

        // Сброс
        $batch = [];
        $batchMeta = [];
        $chars = 0;
    };

    foreach ($segments as $i => $seg) {
        $t = (string)$seg['text'];
        $len = mb_strlen($t, 'UTF-8');
        if ($len === 0) continue;

        // Если текущий батч переполняется — отправляем
        if (count($batch) >= $MAX_ITEMS || ($chars + $len) > $MAX_CHARS) {
            $flush();
            if ($fatal) break;
        }

        $batch[] = $t;
        $batchMeta[] = $i;
        $chars += $len;
    }
    if (!$fatal) $flush();

    return ['done' => $done, 'errors' => $errors, 'fatal' => $fatal];
}

// Фолбэк: перевод по одному сегменту с бэк‑оффом
function fallbackPerItem(string $deeplUrl, string $token, string $targetLang, array $batch, array $batchMeta, array &$done, array &$errors, string &$fatal): void {
    foreach ($batch as $k => $text) {
        $fields = [
            'auth_key'            => $token,
            'target_lang'         => $targetLang,
            'tag_handling'        => 'html',
            'preserve_formatting' => 1,
            'split_sentences'     => 'nonewlines',
            'text'                => [$text],
        ];
        $r = deeplRequest($deeplUrl, $fields);
        if ($r['ok'] && !empty($r['data']['translations'][0]['text'])) {
            $done[$batchMeta[$k]] = (string)$r['data']['translations'][0]['text'];
        } else {
            if ($r['http'] === 456) {
                $fatal = 'Превышена месячная квота DeepL (HTTP 456).';
                return;
            }
            $errors[] = "Не удалось перевести сегмент #{$batchMeta[$k]} (HTTP {$r['http']})";
        }
    }
}

// Единая функция запроса к DeepL с ретраями и экспоненциальным бэк‑оффом
function deeplRequest(string $url, array $fields, int $maxAttempts = 6): array {
    $attempt = 0;
    $delay   = 1.0; // секунд
    while (true) {
        $attempt++;

        $body = buildFormBody($fields);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($http >= 200 && $http < 300) {
            $data = json_decode((string)$response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['ok' => true, 'data' => $data, 'http' => $http];
            }
            return ['ok' => false, 'error' => 'Неверный JSON от DeepL', 'http' => $http];
        }

        // Ретраи на 429/503
        if (in_array($http, [429, 503], true) && $attempt < $maxAttempts) {
            usleep((int)(($delay + mt_rand(0, 250) / 1000.0) * 1_000_000));
            $delay = min($delay * 2.0, 32.0);
            continue;
        }

        // 456 — квота (фатально)
        if ($http === 456) {
            return ['ok' => false, 'http' => 456, 'error' => 'Quota exceeded'];
        }

        // Прочие ошибки без ретрая
        return ['ok' => false, 'http' => $http, 'error' => $err ?: (string)$response];
    }
}

// Формируем application/x-www-form-urlencoded так, чтобы text=... повторялся
function buildFormBody(array $fields): string {
    $pairs = [];
    foreach ($fields as $k => $v) {
        if ($k === 'text' && is_array($v)) {
            foreach ($v as $item) {
                $pairs[] = 'text=' . urlencode((string)$item);
            }
        } elseif ($v !== null) {
            $pairs[] = $k . '=' . urlencode((string)$v);
        }
    }
    return implode('&', $pairs);
}
