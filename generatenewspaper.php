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

sendTelegramMessage(
    $chatId,
    "✍️ Генерую статтю..."
);

// ======================
// PROMPT
// ======================

$promptPath = __DIR__ . '/generateNews/article_prompt.txt';
$promptTemplate = file_get_contents($promptPath);
if ($promptTemplate === false) {
    sendTelegramMessage($chatId, "❌ Не знайдено файл промпта");
    exit();
}
$prompt = str_replace('{TOPIC}', $topic, $promptTemplate);

// ======================
// OPENAI REQUEST
// ======================

$response = callOpenAI($prompt, $OPENAI_API_KEY);

if (!$response) {

    sendTelegramMessage(
        $chatId,
        "❌ Помилка генерації статті"
    );

    exit();
}

// ======================
// CLEAN JSON
// ======================

$response = cleanJson($response);

$data = json_decode($response, true);

if (!$data) {

    sendTelegramMessage(
        $chatId,
        "❌ GPT повернув некоректний JSON"
    );

    exit();
}

$imageQuery = trim($data['title'] ?? '') ?: $topic;
$articleImage = fetchUnsplashImage($imageQuery);

if (!$articleImage) {
    $articleImage = $data['image'] ?? null;
}

// ======================
// CREATE SLUG
// ======================

$slug = createSlug($data['title']);

$originalSlug = $slug;

$counter = 1;

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
}

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

// ======================
// SUCCESS MESSAGE
// ======================

sendTelegramMessage(
    $chatId,
    "✅ Стаття успішно створена!

📰 {$data['title']}

ID: {$articleId}"
);
 

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

    curl_close($ch);

    $decoded = json_decode($response, true);

    return $decoded['choices'][0]['message']['content'] ?? null;
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