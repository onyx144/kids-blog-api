<?php
// articles.php
require_once 'config.php';
require_once 'jwt_helper.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Функция для создания slug из заголовка
function createSlug($title) {
    $slug = mb_strtolower($title);
    $slug = preg_replace('/[^a-zа-яіїєґ0-9\s-]/u', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// GET /articles.php - Получение статей
if ($method === 'GET') {
    $user = JWT::getUserFromRequest();
    
    // Публичный доступ к approved статьям без авторизации
    if (!$user) {
        $stmt = $db->prepare("
            SELECT id, slug, title, content, category, author_name, status, image, alt, created_at, updated_at 
            FROM articles 
            WHERE status = 'approved' 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        $articles = $stmt->fetchAll();
        
        echo json_encode(['articles' => $articles]);
        exit();
    }
    
    // Авторизованный доступ
    $status = $_GET['status'] ?? null;
    $articleId = $_GET['id'] ?? null;
    
    // Получение конкретной статьи
    if ($articleId) {
        if ($user['role'] === 'admin') {
            $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
            $stmt->execute([$articleId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM articles WHERE id = ? AND author_id = ?");
            $stmt->execute([$articleId, $user['id']]);
        }
        
        $article = $stmt->fetch();
        
        if (!$article) {
            http_response_code(404);
            echo json_encode(['error' => 'Article not found']);
            exit();
        }
        
        echo json_encode(['article' => $article]);
        exit();
    }
    
    // Получение списка статей
    if ($user['role'] === 'admin') {
        // Админ видит все статьи
        if ($status) {
            $stmt = $db->prepare("SELECT * FROM articles WHERE status = ? ORDER BY created_at DESC");
            $stmt->execute([$status]);
        } else {
            $stmt = $db->prepare("SELECT * FROM articles ORDER BY created_at DESC");
            $stmt->execute();
        }
    } else {
        // Редактор видит только свои статьи
        if ($status) {
            $stmt = $db->prepare("SELECT * FROM articles WHERE author_id = ? AND status = ? ORDER BY created_at DESC");
            $stmt->execute([$user['id'], $status]);
        } else {
            $stmt = $db->prepare("SELECT * FROM articles WHERE author_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user['id']]);
        }
    }
    
    $articles = $stmt->fetchAll();
    
    echo json_encode(['articles' => $articles]);
    exit();
}

// POST /articles.php - Создание статьи
if ($method === 'POST') {
    $user = JWT::getUserFromRequest();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    if (!isset($input['title']) || !isset($input['content']) || !isset($input['category'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Title, content and category are required']);
        exit();
    }
    
    $slug = createSlug($input['title']);
    $originalSlug = $slug;
    $counter = 1;
    
    // Проверка уникальности slug
    while (true) {
        $stmt = $db->prepare("SELECT id FROM articles WHERE slug = ?");
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) break;
        $slug = $originalSlug . '-' . $counter++;
    }
    
    // Редактор может создавать только pending статьи
    $status = ($user['role'] === 'admin' && isset($input['status'])) ? $input['status'] : 'pending';
    
    $stmt = $db->prepare("
        INSERT INTO articles (slug, title, content, category, author_id, author_name, status, image, alt) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $slug,
        $input['title'],
        $input['content'],
        $input['category'],
        $user['id'],
        $user['username'],
        $status,
        $input['image'] ?? null,
        $input['alt'] ?? null
    ]);
    
    $articleId = $db->lastInsertId();
    
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch();
    
    echo json_encode(['message' => 'Article created', 'article' => $article]);
    exit();
}

// PUT /articles.php - Обновление статьи
if ($method === 'PUT') {
    $user = JWT::getUserFromRequest();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Article ID is required']);
        exit();
    }
    
    // Проверка прав доступа
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$input['id']]);
    $article = $stmt->fetch();
    
    if (!$article) {
        http_response_code(404);
        echo json_encode(['error' => 'Article not found']);
        exit();
    }
    
    if ($user['role'] !== 'admin' && $article['author_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
    
    $updates = [];
    $params = [];
    
    if (isset($input['title'])) {
        $updates[] = "title = ?";
        $params[] = $input['title'];
        
        // Обновление slug при изменении title
        $slug = createSlug($input['title']);
        $originalSlug = $slug;
        $counter = 1;
        
        while (true) {
            $stmt = $db->prepare("SELECT id FROM articles WHERE slug = ? AND id != ?");
            $stmt->execute([$slug, $input['id']]);
            if (!$stmt->fetch()) break;
            $slug = $originalSlug . '-' . $counter++;
        }
        
        $updates[] = "slug = ?";
        $params[] = $slug;
    }
    
    if (isset($input['content'])) {
        $updates[] = "content = ?";
        $params[] = $input['content'];
    }
    
    if (isset($input['category'])) {
        $updates[] = "category = ?";
        $params[] = $input['category'];
    }
    
    if (isset($input['image'])) {
        $updates[] = "image = ?";
        $params[] = $input['image'];
    }
    
    if (isset($input['alt'])) {
        $updates[] = "alt = ?";
        $params[] = $input['alt'];
    }
    
    // Только админ может менять status
    if (isset($input['status']) && $user['role'] === 'admin') {
        $updates[] = "status = ?";
        $params[] = $input['status'];
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        exit();
    }
    
    $params[] = $input['id'];
    $sql = "UPDATE articles SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$input['id']]);
    $updatedArticle = $stmt->fetch();
    
    echo json_encode(['message' => 'Article updated', 'article' => $updatedArticle]);
    exit();
}

// DELETE /articles.php - Удаление статьи
if ($method === 'DELETE') {
    $user = JWT::getUserFromRequest();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    $articleId = $_GET['id'] ?? $input['id'] ?? null;
    
    if (!$articleId) {
        http_response_code(400);
        echo json_encode(['error' => 'Article ID is required']);
        exit();
    }
    
    // Проверка прав доступа
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch();
    
    if (!$article) {
        http_response_code(404);
        echo json_encode(['error' => 'Article not found']);
        exit();
    }
    
    if ($user['role'] !== 'admin' && $article['author_id'] != $user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }
    
    $stmt = $db->prepare("DELETE FROM articles WHERE id = ?");
    $stmt->execute([$articleId]);
    
    echo json_encode(['message' => 'Article deleted']);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);