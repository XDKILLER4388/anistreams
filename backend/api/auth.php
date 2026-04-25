<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
session_start();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Register ──────────────────────────────────────────────────────────────────
if ($action === 'register' && $method === 'POST') {
    $username = trim($body['username'] ?? '');
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$email || !$password)
        json_response(['error' => 'All fields required'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        json_response(['error' => 'Invalid email'], 400);
    if (strlen($password) < 6)
        json_response(['error' => 'Password must be at least 6 characters'], 400);

    try {
        $stmt = db()->prepare('INSERT INTO users (username, email, password) VALUES (?,?,?)');
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT)]);
        $id = db()->lastInsertId();
        $_SESSION['user_id']   = $id;
        $_SESSION['username']  = $username;
        // Log registration (non-fatal)
        try {
            db()->prepare('INSERT INTO activity_log (user_id,username,action,detail,ip) VALUES (?,?,?,?,?)')
               ->execute([$id, $username, 'register', 'New account', $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (Exception $e) {}
        json_response(['success' => true, 'user' => ['id' => $id, 'username' => $username, 'email' => $email]]);
    } catch (PDOException $e) {
        json_response(['error' => 'Username or email already taken'], 409);
    }
}

// ── Login ─────────────────────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password']))
        json_response(['error' => 'Invalid email or password'], 401);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    // Update last_seen and log activity (non-fatal)
    try {
        db()->prepare('UPDATE users SET last_seen=NOW() WHERE id=?')->execute([$user['id']]);
        db()->prepare('INSERT INTO activity_log (user_id,username,action,detail,ip) VALUES (?,?,?,?,?)')
           ->execute([$user['id'], $user['username'], 'login', 'Login', $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) { /* ignore if columns/table missing */ }
    unset($user['password']);
    json_response(['success' => true, 'user' => $user]);
}

// ── Logout ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    if (!empty($_SESSION['user_id'])) {
        db()->prepare('INSERT INTO activity_log (user_id,username,action,ip) VALUES (?,?,?,?)')
           ->execute([$_SESSION['user_id'], $_SESSION['username'] ?? '', 'logout', $_SERVER['REMOTE_ADDR'] ?? '']);
    }
    session_destroy();
    json_response(['success' => true]);
}

// ── Me (check session) ────────────────────────────────────────────────────────
if ($action === 'me') {
    if (empty($_SESSION['user_id'])) {
        json_response(['user' => null]);
    }
    // Refresh last_seen (non-fatal)
    try { db()->prepare('UPDATE users SET last_seen=NOW() WHERE id=?')->execute([$_SESSION['user_id']]); } catch (Exception $e) {}
    $stmt = db()->prepare('SELECT id, username, email, role, avatar, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    json_response(['user' => $stmt->fetch()]);
}

json_response(['error' => 'Unknown action'], 400);
