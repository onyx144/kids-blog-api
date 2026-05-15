<?php
 
// Загрузка переменных из .env
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// Конфигурация БД из .env
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: '');

header('Content-Type: text/html; charset=UTF-8');

echo "<!DOCTYPE html>
<html lang='uk'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Установка Kids Weekly API</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .step {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }
        .step.success {
            border-left-color: #10b981;
            background: #ecfdf5;
        }
        .step.error {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        .step-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: #1f2937;
        }
        .step-desc {
            color: #6b7280;
            font-size: 14px;
        }
        .success-icon { color: #10b981; }
        .error-icon { color: #ef4444; }
        .info-icon { color: #667eea; }
        .icon { margin-right: 8px; font-size: 18px; }
        .credentials {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        .credentials-title {
            font-weight: 700;
            color: #92400e;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .credential-item {
            background: white;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .credential-label {
            font-weight: 600;
            color: #78350f;
        }
        .credential-value {
            font-family: 'Courier New', monospace;
            color: #1f2937;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .warning {
            background: #fef2f2;
            border: 2px solid #f87171;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            color: #991b1b;
            font-weight: 600;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🦊 Kids Weekly API</h1>
        <p class='subtitle'>Установка бази даних</p>";

try {
    // Шаг 1: Подключение к БД
    echo "<div class='step'>";
    echo "<div class='step-title'><span class='icon info-icon'>📡</span>Крок 1: Підключення до бази даних</div>";
    echo "<div class='step-desc'>Хост: <code>" . DB_HOST . "</code></div>";
    
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]
    );
    
    echo "</div>";
    echo "<div class='step success'>";
    echo "<div class='step-title'><span class='icon success-icon'>✅</span>Підключення успішне!</div>";
    echo "</div>";
    
    // Шаг 2: Создание таблицы users
    echo "<div class='step'>";
    echo "<div class='step-title'><span class='icon info-icon'>👥</span>Крок 2: Створення таблиці користувачів</div>";
    
    $pdo->exec("DROP TABLE IF EXISTS articles");
    $pdo->exec("DROP TABLE IF EXISTS users");
    
    $pdo->exec("
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'editor') DEFAULT 'editor',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "</div>";
    echo "<div class='step success'>";
    echo "<div class='step-title'><span class='icon success-icon'>✅</span>Таблиця users створена</div>";
    echo "</div>";
    
    // Шаг 3: Создание таблицы articles
    echo "<div class='step'>";
    echo "<div class='step-title'><span class='icon info-icon'>📝</span>Крок 3: Створення таблиці статей</div>";
    
    $pdo->exec("
        CREATE TABLE articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(255) UNIQUE NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            category ENUM('спорт', 'навчання', 'творчість', 'жарти') NOT NULL,
            author_id INT NOT NULL,
            author_name VARCHAR(100) NOT NULL,
            status ENUM('pending', 'approved') DEFAULT 'pending',
            image VARCHAR(255),
            alt VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_slug (slug),
            INDEX idx_status (status),
            INDEX idx_category (category),
            INDEX idx_author_id (author_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "</div>";
    echo "<div class='step success'>";
    echo "<div class='step-title'><span class='icon success-icon'>✅</span>Таблиця articles створена</div>";
    echo "</div>";
    
    // Шаг 4: Создание тестовых пользователей
    echo "<div class='step'>";
    echo "<div class='step-title'><span class='icon info-icon'>🔑</span>Крок 4: Створення тестових користувачів</div>";
    
    // Пароль: admin123
    $adminPassword = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->exec("
        INSERT INTO users (username, email, password, role) 
        VALUES ('admin', 'admin@kidsweekly.com', '$adminPassword', 'admin')
    ");
    
    // Пароль: editor123
    $editorPassword = password_hash('editor123', PASSWORD_BCRYPT);
    $pdo->exec("
        INSERT INTO users (username, email, password, role) 
        VALUES ('editor', 'editor@kidsweekly.com', '$editorPassword', 'editor')
    ");
    
    echo "</div>";
    echo "<div class='step success'>";
    echo "<div class='step-title'><span class='icon success-icon'>✅</span>Тестові користувачі створені</div>";
    echo "</div>";
    
    // Шаг 5: Создание тестовой статьи
    echo "<div class='step'>";
    echo "<div class='step-title'><span class='icon info-icon'>📰</span>Крок 5: Створення тестової статті</div>";
    
    $testArticle = [
        'slug' => 'pershyi-futbolnyi-match',
        'title' => 'Перший футбольний матч сезону',
        'content' => "## Захоплююча гра!\n\nСьогодні відбувся перший футбольний матч нашої шкільної команди. Це була неймовірна гра!\n\n### Рахунок матчу\n\nНаша команда перемогла з рахунком **3:2**. Всі гравці показали чудову гру.\n\n> Футбол - це не просто гра, це справжня командна робота!\n\n**Найкращі моменти:**\n- Перший гол на 15 хвилині\n- Феноменальний сейв воротаря\n- Переможний гол на останній хвилині\n\nВітаємо нашу команду з перемогою! 🎉",
        'category' => 'спорт',
        'status' => 'approved',
        'image' => '/images/football.jpg',
        'alt' => 'Футбольний матч'
    ];
    
    $stmt = $pdo->prepare("
        INSERT INTO articles (slug, title, content, category, author_id, author_name, status, image, alt) 
        VALUES (:slug, :title, :content, :category, 1, 'admin', :status, :image, :alt)
    ");
    $stmt->execute($testArticle);
    
    echo "</div>";
    echo "<div class='step success'>";
    echo "<div class='step-title'><span class='icon success-icon'>✅</span>Тестова стаття створена</div>";
    echo "</div>";
    
    // Информация о доступах
    echo "<div class='credentials'>";
    echo "<div class='credentials-title'>🔐 Облікові дані для входу</div>";
    
    echo "<div class='credential-item'>";
    echo "<span class='credential-label'>👨‍💼 Адміністратор:</span>";
    echo "<span class='credential-value'>admin / admin123</span>";
    echo "</div>";
    
    echo "<div class='credential-item'>";
    echo "<span class='credential-label'>✍️ Редактор:</span>";
    echo "<span class='credential-value'>editor / editor123</span>";
    echo "</div>";
    
    echo "</div>";
    
    // Предупреждение
    echo "<div class='warning'>";
    echo "⚠️ <strong>ВАЖЛИВО!</strong> Видаліть файл <code>install.php</code> з сервера після успішної установки!";
    echo "</div>";
    
    echo "<div class='footer'>";
    echo "API готове до використання! 🚀<br>";
    echo "Документація: <code>README.md</code>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='step error'>";
    echo "<div class='step-title'><span class='icon error-icon'>❌</span>Помилка!</div>";
    echo "<div class='step-desc'>" . htmlspecialchars($e->getMessage()) . "</div>";
    echo "</div>";
    
    echo "<div class='warning'>";
    echo "Перевірте правильність даних підключення до бази даних в файлі <code>config.php</code>";
    echo "</div>";
}

echo "
    </div>
</body>
</html>";
?>