<?php

require_once 'config.php';
require_once 'jwt_helper.php';
require_once __DIR__ . '/generateNews/image.php';

// ======================
// DATABASE
// ======================

$database = new Database();
$db = $database->getConnection();

// ======================
// ENV
// ======================

$OPENAI_API_KEY = $_ENV['API_CHATGPT'];
$TELEGRAM_BOT_TOKEN = $_ENV['TELEGRAM_BOT_TOKEN'];

// ======================
// GET TELEGRAM UPDATE
// ======================

$update = json_decode(file_get_contents("php://input"), true);

if (!$update || !isset($update['message'])) {
    exit();
}

$message = $update['message'];

$chatId = $message['chat']['id'] ?? null;

$text = $message['text'] ?? null;

// ======================
// VOICE SUPPORT
// ======================

if (!$text && isset($message['voice'])) {

    sendTelegramMessage($chatId, "🎤 Розпізнаю голосове повідомлення...");

    $fileId = $message['voice']['file_id'];

    $text = transcribeTelegramVoice($fileId);

    if (!$text) {

        sendTelegramMessage(
            $chatId,
            "❌ Не вдалося розпізнати голосове повідомлення"
        );

        exit();
    }
}

// ======================
// CHECK COMMAND
// ======================

if (
    mb_stripos($text, 'напиши статтю') === false &&
    mb_stripos($text, 'напиши статью') === false
) {
    exit();
}

// ======================
// EXTRACT TOPIC
// ======================

$topic = trim(
    str_ireplace(
        [
            'напиши статтю про',
            'напиши статтю',
            'напиши статью о',
            'напиши статью'
        ],
        '',
        mb_strtolower($text)
    )
);

if (!$topic) {

    sendTelegramMessage(
        $chatId,
        "❌ Напиши тему статті"
    );

    exit();
}

generationStoryBegin([
    'chat_id' => (string) $chatId,
    'topic' => $topic,
    'user_message' => (string) $text,
    'voice_used' => isset($message['voice']) ? 'yes' : 'no'
]);

sendTelegramMessage(
    $chatId,
    "✍️ Генерую статтю..."
);

generationStoryAppend('Надіслано в Telegram: «Генерую статтю...»');

// ======================
// PROMPT
// ======================

$promptPath = __DIR__ . '/generateNews/article_prompt.txt';
$promptTemplate = file_get_contents($promptPath);
if ($promptTemplate === false) {
    generationStoryAppend('ПОМИЛКА: не знайдено файл промпта ' . $promptPath);
    sendTelegramMessage($chatId, "❌ Не знайдено файл промпта");
    exit();
}
$prompt = str_replace('{TOPIC}', $topic, $promptTemplate);

generationStoryAppend('Шлях промпта: ' . $promptPath);
generationStoryAppend('Довжина промпту: ' . mb_strlen($prompt) . ' символів');
generationStoryBlock('Повний промпт', $prompt);

// ======================
// OPENAI REQUEST
// ======================

generationStoryAppend('Запит до OpenAI (gpt-4.1-mini)...');

$response = callOpenAI($prompt, $OPENAI_API_KEY);

if (!$response) {

    generationStoryAppend('ПОМИЛКА: порожня відповідь OpenAI або помилка API');
    sendTelegramMessage(
        $chatId,
        "❌ Помилка генерації статті"
    );

    exit();
}

generationStoryAppend('OpenAI повернув ' . mb_strlen($response) . ' символів');
generationStoryBlock('Сира відповідь моделі (до cleanJson)', $response);

// ======================
// CLEAN JSON
// ======================

$response = cleanJson($response);

generationStoryAppend('Після cleanJson: ' . mb_strlen($response) . ' символів');
generationStoryBlock('Рядок JSON після cleanJson', $response);

$data = json_decode($response, true);

if (!$data) {

    generationStoryAppend(
        'ПОМИЛКА: json_decode не розпізнав дані (' . json_last_error_msg() . ')'
    );
    sendTelegramMessage(
        $chatId,
        "❌ GPT повернув некоректний JSON"
    );

    exit();
}

generationStoryAppend('JSON розпізнано, ключі: ' . implode(', ', array_keys($data)));

$data['title'] = cleanArticleQuestionMarksTitle($data['title'] ?? '');
$data['content'] = cleanArticleQuestionMarksContent($data['content'] ?? '');

generationStoryAppend('Заголовок після правок: ' . mb_substr($data['title'], 0, 300)
    . (mb_strlen($data['title']) > 300 ? '…' : ''));
generationStoryAppend('Довжина контенту після правок: ' . mb_strlen($data['content']) . ' символів');

$categoryResolution = resolveArticleCategoryWithLogging($topic, $data['title'], $data);
$data['category'] = $categoryResolution['final_category'];
generationStoryLogCategoryResolution($topic, $categoryResolution);
generationStoryAppend('Категорія для БД / Unsplash: ' . $data['category']);

$unsplashCandidates = buildUnsplashCandidateQueries(
    trim($data['image_search_en'] ?? ''),
    $data['category'] ?? ''
);
generationStoryAppend('Кандидати Unsplash: ' . json_encode($unsplashCandidates, JSON_UNESCAPED_UNICODE));

$articleImage = fetchUnsplashImageFirstMatch($unsplashCandidates);

if (!$articleImage) {
    $articleImage = $data['image'] ?? null;
    generationStoryAppend('Unsplash не дав URL, fallback image з GPT: '
        . ($articleImage ?: '(немає)'));
} else {
    generationStoryAppend('Обрано зображення: ' . $articleImage);
}

// ======================
// CREATE SLUG
// ======================

$slug = createSlug($data['title']);

$originalSlug = $slug;

$counter = 1;

generationStoryAppend('Початковий slug: ' . $slug);

while (true) {

    $stmt = $db->prepare("
        SELECT id
        FROM articles
        WHERE slug = ?
    ");

    $stmt->execute([$slug]);

    if (!$stmt->fetch()) {
        break;
    }

    $slug = $originalSlug . '-' . $counter++;
    generationStoryAppend('Slug зайнятий, новий варіант: ' . $slug);
}

generationStoryAppend('Фінальний slug: ' . $slug);

// ======================
// INSERT ARTICLE
// ======================

$stmt = $db->prepare("
    INSERT INTO articles (
        slug,
        title,
        content,
        category,
        author_id,
        author_name,
        status,
        image,
        alt
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    $slug,
    $data['title'],
    $data['content'],
    $data['category'],
    1,
    'Добра Собака',
    'approved',
    $articleImage,
    $data['alt']
]);

$articleId = $db->lastInsertId();

generationStoryAppend('INSERT успішний, article id: ' . $articleId);
generationStoryAppend(
    'Підсумок: category=' . ($data['category'] ?? '')
    . ', alt=' . mb_substr((string) ($data['alt'] ?? ''), 0, 200)
);
generationStoryBlock('Фінальний контент статті', $data['content']);

// ======================
// SUCCESS MESSAGE
// ======================

sendTelegramMessage(
    $chatId,
    "✅ Стаття успішно створена!

📰 {$data['title']}

ID: {$articleId}"
);

generationStoryAppend('ГОТОВО: повідомлення успіху надіслано в Telegram');
 

function createSlug($title)
{
    $slug = mb_strtolower($title);

    $slug = preg_replace(
        '/[^a-zа-яіїєґ0-9\\s-]/u',
        '',
        $slug
    );

    $slug = preg_replace(
        '/[\\s-]+/',
        '-',
        $slug
    );

    return trim($slug, '-');
}

function cleanJson($text)
{
    $text = preg_replace('/```json/i', '', $text);

    $text = preg_replace('/```/', '', $text);

    return trim($text);
}

function generationStoryPath()
{
    return __DIR__ . '/story.txt';
}

/**
 * Нова генерація — перезаписує story.txt і далі всі події лише дописуються.
 *
 * @param array<string, string> $fields
 */
function generationStoryBegin(array $fields)
{
    $path = generationStoryPath();
    $lines = str_repeat('=', 72) . "\n";
    $lines .= '[' . date('c') . "] Генерація статті (generatenewspaper)\n";

    foreach ($fields as $key => $value) {
        $lines .= sprintf("  %-14s %s\n", $key . ':', $value);
    }

    $lines .= str_repeat('-', 72) . "\n";
    file_put_contents($path, $lines, LOCK_EX);
    error_log('[generatenewspaper] Початок генерації, story → ' . $path);
}

function generationStoryAppend(string $message)
{
    $path = generationStoryPath();
    $line = '[' . date('c') . '] ' . $message . "\n";
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    error_log('[generatenewspaper] ' . $message);
}

function generationStoryBlock(string $title, string $body)
{
    $path = generationStoryPath();
    $block = "\n--- " . $title . ' ---' . "\n" . $body . "\n";
    file_put_contents($path, $block, FILE_APPEND | LOCK_EX);
    error_log(sprintf('[generatenewspaper] %s (%d bytes)', $title, strlen($body)));
}

function cleanArticleQuestionMarksTitle($title)
{
    return cleanArticleQuestionMarksPlainSegment(trim((string) $title));
}

function cleanArticleQuestionMarksPlainSegment($text)
{
    $text = trim($text);
    if ($text === '') {
        return $text;
    }
    if (preg_match('/^[\?\s]+$/u', $text)) {
        return '?';
    }
    $text = preg_replace('/\?{2,}/u', '?', $text);
    $text = preg_replace('/^[\?\s]+/u', '', $text);

    return trim($text);
}

function cleanArticleQuestionMarksContent($content)
{
    $content = (string) $content;
    $content = preg_replace('/(^|(?<=[.!?])\s+|\R)([\?\s]+)(\p{L})/um', '$1$3', $content);
    $content = preg_replace('/\?{2,}/u', '?', $content);

    return $content;
}

function normalizeGptArticleCategory($category)
{
    $c = trim((string) $category);
    if ($c === '') {
        return null;
    }

    $allowed = ['Спорт', 'Навчання', 'Творчість', 'Жарти'];

    foreach ($allowed as $a) {
        if ($c === $a) {
            return $a;
        }
    }

    $lower = mb_strtolower($c, 'UTF-8');
    $aliases = [
        'навчання' => 'Навчання',
        'обучение' => 'Навчання',
        'освіта' => 'Навчання',
        'education' => 'Навчання',
        'learning' => 'Навчання',
        'спорт' => 'Спорт',
        'sport' => 'Спорт',
        'sports' => 'Спорт',
        'творчість' => 'Творчість',
        'творчество' => 'Творчість',
        'творчесть' => 'Творчість',
        'creativity' => 'Творчість',
        'creative' => 'Творчість',
        'жарти' => 'Жарти',
        'юмор' => 'Жарти',
        'humor' => 'Жарти',
        'jokes' => 'Жарти',
        'joke' => 'Жарти'
    ];

    if (isset($aliases[$lower])) {
        return $aliases[$lower];
    }

    return null;
}

/**
 * Пріоритет: Жарти → Спорт → Творчість; інакше «нема збігу» (DEFAULT_LEARNING).
 *
 * @return array{
 *     category: string,
 *     rule_id: int,
 *     label: string,
 *     matched_keyword: string
 * }
 */
function classifyArticleCategoryByKeywordRules($haystack)
{
    static $rules = [
        [
            'category' => 'Жарти',
            'rule_id' => 1,
            'label' => 'HUMOR_FUN',
            'keywords' => [
                'жарт', 'жарти', 'смішн', 'смішко', 'прикол', 'розіграш', 'розыгрыш',
                'курйоз', 'сміх', 'загадк', 'анекдот', 'розважальн', 'розважа',
                'юмор', 'шутк', 'шутки', 'смешн', 'приколы',
                'funny', 'joke', 'jokes', 'humor', 'prank', 'riddle', 'riddles',
                'meme', 'comedy', 'laugh', 'laughter'
            ]
        ],
        [
            'category' => 'Спорт',
            'rule_id' => 2,
            'label' => 'SPORTS',
            'keywords' => [
                'спорт', 'футбол', 'баскетбол', 'волейбол', 'хокей', 'теніс',
                'футболіст', 'стадіон', 'олімп', 'олимп', 'олімпій', 'олимпий',
                'чемпіон', 'турнір', 'турнир', 'матч', 'змаган', 'змагання',
                'чемпіонат', 'тренування', 'тренер', 'команда', 'гребля', 'плаван',
                'легкоатлет', 'бокс', 'дзюдо', 'карате',
                'sport', 'sports', 'football', 'soccer', 'basketball', 'volleyball',
                'tennis', 'hockey', 'olymp', 'stadium', 'athlete', 'championship'
            ]
        ],
        [
            'category' => 'Творчість',
            'rule_id' => 3,
            'label' => 'CREATIVITY',
            'keywords' => [
                'творч', 'малюв', 'мальов', 'малюнок', 'живопис', 'гуаш', 'акварел',
                'музик', 'музык', 'пісн', 'пісня', 'песн', 'спів', 'рок-груп',
                'театр', 'балет', 'танець', 'танці', 'хореограф',
                'орігамі', 'оригамі', 'рукоділ', 'робимо своїми', 'комікс', 'лепка',
                'письмен', 'вірш', 'верлібр',
                'art', 'arts', 'draw', 'drawing', 'paint', 'music', 'theater',
                'theatre', 'creative', 'craft', 'origami', 'diy', 'dance', 'singing'
            ]
        ]
    ];

    foreach ($rules as $rule) {
        foreach ($rule['keywords'] as $kw) {
            $needle = mb_strtolower($kw, 'UTF-8');
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return [
                    'category' => $rule['category'],
                    'rule_id' => $rule['rule_id'],
                    'label' => $rule['label'],
                    'matched_keyword' => $kw
                ];
            }
        }
    }

    return [
        'category' => 'Навчання',
        'rule_id' => 4,
        'label' => 'DEFAULT_LEARNING',
        'matched_keyword' => ''
    ];
}

/**
 * @param array<string, mixed> $gptData
 *
 * @return array{
 *     final_category: string,
 *     decision_summary_uk: string,
 *     keyword_scan: array,
 *     gpt_category_raw: string,
 *     gpt_category_normalized: string,
 *     gpt_rationale_uk: string,
 *     haystack_preview: string
 * }
 */
function resolveArticleCategoryWithLogging($userTopic, $articleTitle, array $gptData)
{
    $gptRaw = trim((string) ($gptData['category'] ?? ''));
    $rationale = trim((string) ($gptData['category_rationale_uk'] ?? ''));

    $haystack = mb_strtolower(
        trim((string) $userTopic) . ' ' . trim((string) $articleTitle),
        'UTF-8'
    );

    $kw = classifyArticleCategoryByKeywordRules($haystack);
    $gptNorm = normalizeGptArticleCategory($gptRaw);

    if ($kw['label'] !== 'DEFAULT_LEARNING') {
        $final = $kw['category'];
        $decision = 'Є збіг за ключовими словами (тема + заголовок) — застосовано правило пріоритету '
            . 'Жарти → Спорт → Творчість. Категорія з моделі ігнорується, якщо суперечить.';
        if ($gptNorm !== null && $gptNorm !== $final) {
            $decision .= ' Модель запропонувала «' . $gptRaw . '», але сервер ставить «'
                . $final . '» через ключове слово «' . $kw['matched_keyword'] . '».';
        } elseif ($gptNorm === null && $gptRaw !== '') {
            $decision .= ' Поле category від моделі не пройшло нормалізацію («' . $gptRaw . '»), '
                . 'але ключові слова однозначні — залишаємо «' . $final . '».';
        }
    } elseif ($gptNorm !== null) {
        $final = $gptNorm;
        $decision = 'Ключових збігів для Жарти/Спорт/Творчість не знайдено — використано category '
            . 'з відповіді моделі (після перевірки дозволеного списку).';
        if ($rationale !== '') {
            $decision .= ' Обґрунтування з JSON: «' . $rationale . '».';
        }
    } else {
        $final = 'Навчання';
        $decision = 'Немає ключового збігу й некоректне або порожнє category від моделі — '
            . 'принудово «Навчання».';
        if ($rationale !== '') {
            $decision .= ' rationale моделі лишено для довідки: «' . $rationale . '».';
        }
    }

    return [
        'final_category' => $final,
        'decision_summary_uk' => $decision,
        'keyword_scan' => $kw,
        'gpt_category_raw' => $gptRaw === '' ? '(порожньо)' : $gptRaw,
        'gpt_category_normalized' => $gptNorm ?? '(немає / не підтверджено)',
        'gpt_rationale_uk' => $rationale === '' ? '(немає)' : $rationale,
        'haystack_preview' => mb_substr($haystack, 0, 800)
            . (mb_strlen($haystack) > 800 ? '…' : '')
    ];
}

function generationStoryLogCategoryResolution($userTopic, array $res)
{
    $kw = $res['keyword_scan'];
    $body = <<<TXT
ЯК ПРАЦЮЄ ВИБІР КАТЕГОРІЇ (на сервері, окремо від промпту):

1) Збирається одна текстова стрічка: «тема від користувача» + пробіл + «заголовок статті після генерації», у нижньому регістрі.

2) Послідовно перевіряються три набори ключових слів (англ./укр./частину рос. для теми користувача):
   спочатку Жарти → потім Спорт → потім Творчість.
   Перше входження будь-якого ключового підрядка з набору визначає категорію й зупиняє перевірку.

3) Якщо жоден набір не спрацював (умовний default):
   - якщо в JSON моделі поле «category» після нормалізації дорівнює одній з рядків: Спорт | Навчання | Творчість | Жарти —
     береться воно;
   - інакше категорія примусово «Навчання».

4) Поле «category_rationale_uk» з JSON не змінює категорію автоматично — воно лише пояснення в лог; фінальне рішення завжди за правилами п.2–3.

---
Тема користувача: {$userTopic}

Початок стрічки для пошуку ключових слів:
{$res['haystack_preview']}

Скан ключових слів:
  rule_id: {$kw['rule_id']}
  label: {$kw['label']}
  категорія за цим кроком: {$kw['category']}
  збіг (ключ): {$kw['matched_keyword']}

Модель (JSON):
  category (як прийшло): {$res['gpt_category_raw']}
  category (нормалізовано): {$res['gpt_category_normalized']}
  category_rationale_uk: {$res['gpt_rationale_uk']}

Підсумок рішення сервера:
{$res['decision_summary_uk']}

ФІНАЛЬНА КАТЕГОРІЯ: {$res['final_category']}
TXT;

    generationStoryBlock('Категорія статті — логіка та результат', $body);
}

function unsplashCategoryFallbackQueries($category)
{
    static $map = [
        'Спорт' => [
            'children outdoor sports playful',
            'kids team ball game sunlight'
        ],
        'Навчання' => [
            'curious children reading books',
            'colorful classroom learning supplies'
        ],
        'Творчість' => [
            'children painting crafting art',
            'creative kids colorful hands hobby'
        ],
        'Жарти' => [
            'kids laughing cheerful playground',
            'playful cheerful children fun outdoor'
        ]
    ];

    $category = trim((string) $category);

    return $map[$category] ?? ['children cheerful colorful playful outdoor'];
}

function buildUnsplashCandidateQueries($imageSearchEn, $category)
{
    $candidates = [];
    $imageSearchEn = trim((string) $imageSearchEn);

    if ($imageSearchEn !== '') {
        $candidates[] = $imageSearchEn;
        $words = preg_split('/\s+/u', $imageSearchEn, -1, PREG_SPLIT_NO_EMPTY);
        if ($words !== [] && count($words) > 6) {
            $candidates[] = implode(' ', array_slice($words, 0, 6));
        }
    }

    foreach (unsplashCategoryFallbackQueries($category) as $fallback) {
        $candidates[] = $fallback;
    }

    $seen = [];
    $unique = [];

    foreach ($candidates as $query) {
        $query = trim((string) $query);
        if ($query === '' || isset($seen[$query])) {
            continue;
        }
        $seen[$query] = true;
        $unique[] = $query;
    }

    return $unique;
}

function callOpenAI($prompt, $apiKey)
{
    $payload = [
        "model" => "gpt-4.1-mini",
        "messages" => [
            [
                "role" => "system",
                "content" => "Ти професійний автор дитячих статей."
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ],
        "temperature" => 0.9
    ];

    $ch = curl_init(
        "https://api.openai.com/v1/chat/completions"
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response = curl_exec($ch);

    $curlError = curl_error($ch);

    curl_close($ch);

    if ($curlError !== '') {
        generationStoryAppend('OpenAI cURL помилка: ' . $curlError);

        return null;
    }

    $decoded = json_decode($response, true);

    $content = $decoded['choices'][0]['message']['content'] ?? null;

    if ($content === null) {
        generationStoryAppend('OpenAI: у відповіді немає content (перевірте ключ або квоту)');
        generationStoryBlock('OpenAI сира HTTP-відповідь', (string) $response);
    }

    return $content;
}

function sendTelegramMessage($chatId, $text)
{
    global $TELEGRAM_BOT_TOKEN;

    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";

    $payload = [
        'chat_id' => $chatId,
        'text' => $text
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload
    ]);

    curl_exec($ch);

    curl_close($ch);
}

function transcribeTelegramVoice($fileId)
{
    global $TELEGRAM_BOT_TOKEN;
    global $OPENAI_API_KEY;

    // ======================
    // GET FILE PATH
    // ======================

    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/getFile?file_id={$fileId}";

    $response = file_get_contents($url);

    $data = json_decode($response, true);

    if (!isset($data['result']['file_path'])) {
        return null;
    }

    $filePath = $data['result']['file_path'];

    // ======================
    // DOWNLOAD FILE
    // ======================

    $downloadUrl = "https://api.telegram.org/file/bot{$TELEGRAM_BOT_TOKEN}/{$filePath}";

    $tempFile = sys_get_temp_dir() . '/' . uniqid() . '.ogg';

    file_put_contents(
        $tempFile,
        file_get_contents($downloadUrl)
    );

    // ======================
    // OPENAI WHISPER
    // ======================

    $ch = curl_init(
        "https://api.openai.com/v1/audio/transcriptions"
    );

    $postFields = [
        'file' => new CURLFile($tempFile),
        'model' => 'whisper-1'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$OPENAI_API_KEY}"
        ],
        CURLOPT_POSTFIELDS => $postFields
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    unlink($tempFile);

    $decoded = json_decode($response, true);

    return $decoded['text'] ?? null;
}