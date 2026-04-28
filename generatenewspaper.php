<?php

require_once 'config.php';
require_once 'jwt_helper.php';

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

$prompt = "
Ти професійний журналіст дитячої онлайн-газети.

Пиши статті ТІЛЬКИ УКРАЇНСЬКОЮ мовою.

ВАЖЛИВО:

Стаття повинна бути у markdown форматі.

Стиль оформлення:

- заголовки через #
- підзаголовки через ##
- розділювачі ---
- emoji
- списки
- цікаві факти
- гарне форматування
- читабельність
- іноді iframe youtube якщо це доречно
- блок джерел в кінці

Стаття повинна бути дуже цікавою для дітей.

Стиль статей:
https://kidsweek.com.ua/posts/mars

Категорії:

- Спорт
- Навчання
- Творчість
- Жарти

Ти повинен сам вибрати правильну категорію.

Також:

- придумай SEO title
- знайди релевантну картинку з інтернету
- створи alt текст
- зроби сильний вступ
- додавай цікаві факти
- контент 3000-6000 символів

Поверни ТІЛЬКИ JSON.

Формат:

{
  \"title\": \"...\",
  \"content\": \"markdown content\",
  \"image\": \"https://...\",
  \"alt\": \"...\",
  \"category\": \"Навчання\"
}

Тема статті:

{$topic}
";

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
    'AI Writer',
    'published',
    $data['image'],
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

// ======================
// FUNCTIONS
// ======================

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