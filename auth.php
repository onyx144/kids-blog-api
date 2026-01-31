<?php
// auth.php
require_once 'config.php';
require_once 'jwt_helper.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// POST /auth.php - Вход
if ($method === 'POST' && !isset($_GET['action'])) {
    if (!isset($input['username']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password are required']);
        exit();
    }

    $stmt = $db->prepare("SELECT id, username, email, password, role FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$input['username'], $input['username']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($input['password'], $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }

    $payload = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'exp' => time() + JWT_EXPIRATION
    ];

    $token = JWT::encode($payload);

    echo json_encode([
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);
    exit();
}

// POST /auth.php?action=register - Регистрация
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'register') {
    if (!isset($input['username']) || !isset($input['email']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Username, email and password are required']);
        exit();
    }

    // Проверка существования пользователя
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$input['username'], $input['email']]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'User already exists']);
        exit();
    }

    $hashedPassword = password_hash($input['password'], PASSWORD_BCRYPT);
    $role = $input['role'] ?? 'editor'; // По умолчанию editor
    
    // Только админ может создавать других админов
    $currentUser = JWT::getUserFromRequest();
    if ($role === 'admin' && (!$currentUser || $currentUser['role'] !== 'admin')) {
        $role = 'editor';
    }

    $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->execute([$input['username'], $input['email'], $hashedPassword, $role]);

    $userId = $db->lastInsertId();

    $payload = [
        'id' => $userId,
        'username' => $input['username'],
        'email' => $input['email'],
        'role' => $role,
        'exp' => time() + JWT_EXPIRATION
    ];

    $token = JWT::encode($payload);

    echo json_encode([
        'token' => $token,
        'user' => [
            'id' => $userId,
            'username' => $input['username'],
            'email' => $input['email'],
            'role' => $role
        ]
    ]);
    exit();
}

// GET /auth.php - Получение текущего пользователя
if ($method === 'GET') {
    $user = JWT::getUserFromRequest();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    echo json_encode(['user' => $user]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);