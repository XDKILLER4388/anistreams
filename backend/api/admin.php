<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
session_start();

// Admin only
if (empty($_SESSION['user_id'])) json_response(['error' => 'Unauthorized'], 401);

$stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch();
if (!$me || $me['role'] !== 'admin') json_response(['error' => 'Forbidden'], 403);

$action = $_GET['action'] ?? '';

// ── Dashboard stats ───────────────────────────────────────────────────────────
if ($action === 'stats') {
    try {
        $total_users   = db()->query('SELECT COUNT(*) FROM users WHERE role="user"')->fetchColumn();
        $online_users  = db()->query('SELECT COUNT(*) FROM users WHERE last_seen > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND role="user"')->fetchColumn();
        $total_dl      = db()->query('SELECT COUNT(*) FROM user_downloads')->fetchColumn();
        $today_logins  = db()->query('SELECT COUNT(*) FROM activity_log WHERE action="login" AND DATE(created_at)=CURDATE()')->fetchColumn();
        $banned        = db()->query('SELECT COUNT(*) FROM users WHERE is_banned=1')->fetchColumn();

        json_response([
            'total_users'     => (int)$total_users,
            'online_users'    => (int)$online_users,
            'total_downloads' => (int)$total_dl,
            'today_logins'    => (int)$today_logins,
            'banned_users'    => (int)$banned,
        ]);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ── User list ─────────────────────────────────────────────────────────────────
if ($action === 'users') {
    try {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $search = '%' . ($_GET['q'] ?? '') . '%';

        $stmt = db()->prepare('
            SELECT u.id, u.username, u.email, u.role, u.is_banned, u.created_at, u.last_seen,
                   COALESCE((SELECT COUNT(*) FROM user_downloads d WHERE d.user_id=u.id), 0) AS downloads,
                   COALESCE((SELECT COUNT(*) FROM activity_log l WHERE l.user_id=u.id AND l.action="login"), 0) AS login_count
            FROM users u
            WHERE (u.username LIKE ? OR u.email LIKE ?)
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->bindValue(1, $search, PDO::PARAM_STR);
        $stmt->bindValue(2, $search, PDO::PARAM_STR);
        $stmt->bindValue(3, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        $total = db()->prepare('SELECT COUNT(*) FROM users WHERE username LIKE ? OR email LIKE ?');
        $total->execute([$search, $search]);

        json_response(['users' => $users, 'total' => (int)$total->fetchColumn(), 'page' => $page]);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ── Activity log ──────────────────────────────────────────────────────────────
if ($action === 'activity') {
    try {
        $limit  = (int)($_GET['limit'] ?? 50);
        $filter = $_GET['filter'] ?? '';
        $uid    = (int)($_GET['user_id'] ?? 0);

        $where  = [];
        $params = [];
        if ($filter) { $where[] = 'action = ?'; $params[] = $filter; }
        if ($uid)    { $where[] = 'user_id = ?'; $params[] = $uid; }

        $sql  = 'SELECT * FROM activity_log'
              . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
              . ' ORDER BY created_at DESC LIMIT ?';

        $stmt = db()->prepare($sql);
        // bind string params first, then LIMIT as INT
        $i = 1;
        foreach ($params as $p) { $stmt->bindValue($i++, $p, PDO::PARAM_STR); }
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
        json_response($stmt->fetchAll());
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ── Online users (active in last 15 min) ─────────────────────────────────────
if ($action === 'online') {
    try {
        $stmt = db()->query('
            SELECT id, username, email, last_seen
            FROM users
            WHERE last_seen > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND role="user"
            ORDER BY last_seen DESC
        ');
        json_response($stmt->fetchAll());
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ── Ban / unban user ──────────────────────────────────────────────────────────
if ($action === 'ban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $uid    = (int)($body['user_id'] ?? 0);
    $banned = (int)($body['banned'] ?? 0);
    if (!$uid) json_response(['error' => 'Missing user_id'], 400);
    db()->prepare('UPDATE users SET is_banned=? WHERE id=? AND role="user"')->execute([$banned, $uid]);
    log_activity($uid, null, $banned ? 'banned' : 'unbanned', 'By admin');
    json_response(['success' => true]);
}

// ── Delete user ───────────────────────────────────────────────────────────────
if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $uid  = (int)($body['user_id'] ?? 0);
    if (!$uid) json_response(['error' => 'Missing user_id'], 400);
    db()->prepare('DELETE FROM users WHERE id=? AND role="user"')->execute([$uid]);
    json_response(['success' => true]);
}

// ── Trigger anime sync ────────────────────────────────────────────────────────
if ($action === 'sync') {
    $script = realpath(__DIR__ . '/../cron/fetch_anime.php');
    $php    = PHP_BINARY;
    pclose(popen("start /B \"\" \"{$php}\" \"{$script}\"", 'r'));
    try {
        db()->prepare("INSERT INTO activity_log (action, detail, ip) VALUES (?,?,?)")
            ->execute(['sync_triggered', 'Manual sync triggered by admin', $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Throwable $e) {}
    json_response(['success' => true, 'message' => 'Sync started in background']);
}

// ── Sync status ───────────────────────────────────────────────────────────────
if ($action === 'sync_status') {
    try {
        $last = db()->query("
            SELECT detail, created_at FROM activity_log
            WHERE action = 'sync'
            ORDER BY created_at DESC LIMIT 1
        ")->fetch();
        $animeCount   = db()->query('SELECT COUNT(*) FROM anime')->fetchColumn();
        $episodeCount = db()->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
        $airingCount  = db()->query("SELECT COUNT(*) FROM anime WHERE status='Currently Airing'")->fetchColumn();
        json_response([
            'last_sync'     => $last ? $last['created_at'] : null,
            'last_detail'   => $last ? $last['detail'] : null,
            'anime_count'   => (int)$animeCount,
            'episode_count' => (int)$episodeCount,
            'airing_count'  => (int)$airingCount,
        ]);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

json_response(['error' => 'Unknown action'], 400);

// ── Helper ────────────────────────────────────────────────────────────────────
function log_activity($user_id, $username, $action, $detail = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    db()->prepare('INSERT INTO activity_log (user_id,username,action,detail,ip) VALUES (?,?,?,?,?)')
       ->execute([$user_id, $username, $action, $detail, $ip]);
}
