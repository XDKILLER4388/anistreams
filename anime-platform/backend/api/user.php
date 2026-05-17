<?php
require_once __DIR__ . '/../config.php';

session_start();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- Register ---
if ($method === 'POST' && $action === 'register') {
    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$email || !$password) json_response(['error' => 'All fields required'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error' => 'Invalid email'], 400);
    if (strlen($password) < 8) json_response(['error' => 'Password must be 8+ chars'], 400);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    try {
        $stmt = db()->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
        $stmt->execute([$username, $email, $hash]);
        json_response(['success' => true, 'id' => db()->lastInsertId()]);
    } catch (PDOException $e) {
        json_response(['error' => 'Username or email already taken'], 409);
    }
}

// --- Login ---
if ($method === 'POST' && $action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true);
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_response(['error' => 'Invalid credentials'], 401);
    }

    $_SESSION['user_id'] = $user['id'];
    unset($user['password']);
    json_response(['success' => true, 'user' => $user]);
}

// --- Logout ---
if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    json_response(['success' => true]);
}

// --- Favorites ---
if ($method === 'GET' && $action === 'favorites') {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) json_response(['error' => 'Unauthorized'], 401);

    $stmt = db()->prepare('SELECT a.* FROM favorites f JOIN anime a ON f.anime_id = a.id WHERE f.user_id = ?');
    $stmt->execute([$uid]);
    json_response($stmt->fetchAll());
}

if ($method === 'POST' && $action === 'favorite') {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) json_response(['error' => 'Unauthorized'], 401);

    $body = json_decode(file_get_contents('php://input'), true);
    $animeId = (int)($body['anime_id'] ?? 0);

    $check = db()->prepare('SELECT 1 FROM favorites WHERE user_id=? AND anime_id=?');
    $check->execute([$uid, $animeId]);

    if ($check->fetch()) {
        db()->prepare('DELETE FROM favorites WHERE user_id=? AND anime_id=?')->execute([$uid, $animeId]);
        json_response(['action' => 'removed']);
    } else {
        db()->prepare('INSERT INTO favorites (user_id, anime_id) VALUES (?,?)')->execute([$uid, $animeId]);
        json_response(['action' => 'added']);
    }
}

// --- History ---
if ($method === 'GET' && $action === 'history') {
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) json_response(['error' => 'Unauthorized'], 401);

    $stmt = db()->prepare('
        SELECT h.*, e.episode_number, a.title, a.cover_image
        FROM history h
        JOIN episodes e ON h.episode_id = e.id
        JOIN anime a ON e.anime_id = a.id
        WHERE h.user_id = ?
        ORDER BY h.watched_at DESC LIMIT 50');
    $stmt->execute([$uid]);
    json_response($stmt->fetchAll());
}
