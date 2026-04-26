<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;

// ── Aired episodes for a specific anime ──────────────────────────────────────
// GET /anime.php?action=aired_episodes&id=<mal_id>
// Returns only episodes that have aired (aired date <= today or aired IS NULL for completed)
if ($action === 'aired_episodes' && $id) {
    try {
        // Look up anime by mal_id
        $anime = db()->prepare('SELECT id, status, episodes FROM anime WHERE mal_id = ? LIMIT 1');
        $anime->execute([$id]);
        $anime = $anime->fetch();

        if (!$anime) {
            // Anime not in DB yet — return empty so frontend falls back to Jikan
            json_response(['episodes' => [], 'source' => 'not_cached']);
        }

        // Get episodes from DB that have aired
        $stmt = db()->prepare('
            SELECT episode_number, title, aired
            FROM episodes
            WHERE anime_id = ?
              AND (aired IS NULL OR aired <= CURDATE())
            ORDER BY episode_number ASC
        ');
        $stmt->execute([$anime['id']]);
        $eps = $stmt->fetchAll();

        // If DB has no episodes yet, tell frontend to use Jikan directly
        if (empty($eps)) {
            json_response([
                'episodes' => [],
                'source'   => 'not_cached',
                'status'   => $anime['status'],
                'total'    => (int)$anime['episodes'],
            ]);
        }

        json_response([
            'episodes' => $eps,
            'source'   => 'db',
            'status'   => $anime['status'],
            'total'    => (int)$anime['episodes'],
        ]);
    } catch (Exception $e) {
        json_response(['error' => $e->getMessage()], 500);
    }
}

if ($method === 'GET') {
    if ($id) {
        // Single anime
        $stmt = db()->prepare('SELECT * FROM anime WHERE mal_id = ? OR id = ? LIMIT 1');
        $stmt->execute([$id, $id]);
        $anime = $stmt->fetch();
        if (!$anime) json_response(['error' => 'Not found'], 404);

        // Include episodes
        $eps = db()->prepare('SELECT * FROM episodes WHERE anime_id = ? ORDER BY episode_number');
        $eps->execute([$anime['id']]);
        $anime['episodes_list'] = $eps->fetchAll();

        json_response($anime);
    } else {
        // List with filters
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(48, (int)($_GET['limit'] ?? 24));
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];

        if (!empty($_GET['genre'])) {
            $where[] = 'genre LIKE ?';
            $params[] = '%' . $_GET['genre'] . '%';
        }
        if (!empty($_GET['year'])) {
            $where[] = 'year = ?';
            $params[] = (int)$_GET['year'];
        }
        if (!empty($_GET['status'])) {
            $where[] = 'status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['q'])) {
            $where[] = 'title LIKE ?';
            $params[] = '%' . $_GET['q'] . '%';
        }

        $sql = 'SELECT * FROM anime WHERE ' . implode(' AND ', $where)
             . ' ORDER BY score DESC LIMIT ? OFFSET ?';

        $stmt = db()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();

        $countStmt = db()->prepare('SELECT COUNT(*) FROM anime WHERE ' . implode(' AND ', $where));
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        json_response([
            'data' => $results,
            'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit]
        ]);
    }
}
