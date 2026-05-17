<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
session_start();

$method = $_SERVER['REQUEST_METHOD'];

// Use session user if logged in, otherwise use a guest identifier
$uid = $_SESSION['user_id'] ?? 0;

// ── Add download ──────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $mal_id    = (int)($body['mal_id'] ?? 0);
    $ep        = (int)($body['episode'] ?? 0);
    $title     = trim($body['title'] ?? '');
    $ep_title  = trim($body['ep_title'] ?? '');
    $cover     = trim($body['cover'] ?? '');
    $dl_url    = trim($body['download_url'] ?? '');

    if (!$mal_id || !$ep || !$title)
        json_response(['error' => 'Missing required fields'], 400);

    // Ensure anime row exists
    $stmt = db()->prepare('INSERT IGNORE INTO anime (mal_id, title, cover_image) VALUES (?,?,?)');
    $stmt->execute([$mal_id, $title, $cover]);

    $stmt = db()->prepare('SELECT id FROM anime WHERE mal_id = ?');
    $stmt->execute([$mal_id]);
    $anime = $stmt->fetch();
    if (!$anime) json_response(['error' => 'Anime not found'], 404);

    // Upsert download record
    $stmt = db()->prepare('
        INSERT INTO user_downloads (user_id, anime_id, mal_id, episode, title, ep_title, cover, download_url, added_at)
        VALUES (?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE added_at=NOW()
    ');
    $stmt->execute([$uid, $anime['id'], $mal_id, $ep, $title, $ep_title, $cover, $dl_url]);
    json_response(['success' => true]);
}

// ── List downloads ────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = db()->prepare('
        SELECT * FROM user_downloads WHERE user_id = ? ORDER BY added_at DESC
    ');
    $stmt->execute([$uid]);
    json_response($stmt->fetchAll());
}

// ── Delete download ───────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('DELETE FROM user_downloads WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $uid]);
    json_response(['success' => true]);
}

json_response(['error' => 'Method not allowed'], 405);
