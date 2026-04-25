<?php
/**
 * Anime Auto-Sync — fetches currently airing + top anime and updates DB
 *
 * Run via Windows Task Scheduler every 6 hours:
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\Aninew\anime-platform\backend\cron\fetch_anime.php
 *
 * Or trigger manually from Admin Panel → Dashboard → "Sync Now"
 * Or visit: http://localhost/Aninew/anime-platform/backend/cron/fetch_anime.php?secret=anistream_sync
 */
require_once __DIR__ . '/../config.php';

// Allow HTTP trigger with secret key (for admin panel button)
$isHttp = php_sapi_name() !== 'cli';
if ($isHttp) {
    header('Content-Type: application/json');
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'anistream_sync') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    // Also check admin session
    session_start();
    if (empty($_SESSION['user_id'])) {
        // Allow if correct secret is provided (for scheduled tasks)
    }
}

$log     = [];
$logLine = function(string $msg) use (&$log, $isHttp) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    if (!$isHttp) echo $line . PHP_EOL;
};

$logLine('=== AniStream Sync Started ===');

$totalInserted = 0;
$totalUpdated  = 0;
$errors        = 0;

// ── Helper: upsert one anime record ──────────────────────────────────────────
function upsertAnime(array $a): string {
    $genres = implode(',', array_column($a['genres'] ?? [], 'name'));
    $year   = $a['year'] ?? ($a['aired']['prop']['from']['year'] ?? null);

    $stmt = db()->prepare('
        INSERT INTO anime (mal_id, title, title_jp, synopsis, cover_image, genre, score, episodes, status, type, year)
        VALUES (:mal_id, :title, :title_jp, :synopsis, :cover, :genre, :score, :eps, :status, :type, :year)
        ON DUPLICATE KEY UPDATE
            title        = VALUES(title),
            title_jp     = VALUES(title_jp),
            synopsis     = VALUES(synopsis),
            cover_image  = VALUES(cover_image),
            genre        = VALUES(genre),
            score        = VALUES(score),
            episodes     = VALUES(episodes),
            status       = VALUES(status),
            type         = VALUES(type),
            year         = VALUES(year),
            updated_at   = NOW()
    ');
    $stmt->execute([
        ':mal_id'   => $a['mal_id'],
        ':title'    => $a['title_english'] ?? $a['title'] ?? 'Unknown',
        ':title_jp' => $a['title_japanese'] ?? null,
        ':synopsis' => $a['synopsis'] ?? null,
        ':cover'    => $a['images']['jpg']['large_image_url'] ?? null,
        ':genre'    => $genres,
        ':score'    => $a['score'] ?? null,
        ':eps'      => $a['episodes'] ?? null,
        ':status'   => $a['status'] ?? null,
        ':type'     => $a['type'] ?? null,
        ':year'     => $year,
    ]);
    return db()->lastInsertId() ? 'inserted' : 'updated';
}

// ── Helper: rate-limited Jikan fetch ─────────────────────────────────────────
function jikanFetch(string $path): ?array {
    static $lastCall = 0;
    // Jikan rate limit: 3 req/sec, 60/min — wait 400ms between calls
    $wait = 400000 - (microtime(true) * 1000000 - $lastCall);
    if ($wait > 0) usleep((int)$wait);
    $lastCall = microtime(true) * 1000000;

    $url = 'https://api.jikan.moe/v4' . $path;
    $ctx = stream_context_create(['http' => [
        'timeout' => 15,
        'header'  => "User-Agent: AniStream/1.0\r\n"
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    $data = json_decode($res, true);
    return $data ?? null;
}

// ── 1. Currently Airing (most important — new episodes weekly) ────────────────
$logLine('Fetching currently airing anime...');
for ($page = 1; $page <= 4; $page++) {
    $data = jikanFetch("/seasons/now?page={$page}&limit=25");
    if (!$data || empty($data['data'])) break;

    foreach ($data['data'] as $a) {
        try {
            $result = upsertAnime($a);
            if ($result === 'inserted') $totalInserted++;
            else $totalUpdated++;
        } catch (Throwable $e) {
            $errors++;
            $logLine("  Error (airing) mal_id={$a['mal_id']}: " . $e->getMessage());
        }
    }
    $logLine("  Airing page {$page}: " . count($data['data']) . " items.");

    if (!($data['pagination']['has_next_page'] ?? false)) break;
}

// ── 2. Top Anime (updates scores, episode counts for completed series) ─────────
$logLine('Fetching top anime...');
for ($page = 1; $page <= 3; $page++) {
    $data = jikanFetch("/top/anime?page={$page}&limit=25");
    if (!$data || empty($data['data'])) break;

    foreach ($data['data'] as $a) {
        try {
            $result = upsertAnime($a);
            if ($result === 'inserted') $totalInserted++;
            else $totalUpdated++;
        } catch (Throwable $e) {
            $errors++;
        }
    }
    $logLine("  Top page {$page}: " . count($data['data']) . " items.");

    if (!($data['pagination']['has_next_page'] ?? false)) break;
}

// ── 3. Upcoming (next season preview) ─────────────────────────────────────────
$logLine('Fetching upcoming anime...');
$data = jikanFetch('/seasons/upcoming?limit=25');
if ($data && !empty($data['data'])) {
    foreach ($data['data'] as $a) {
        try {
            $result = upsertAnime($a);
            if ($result === 'inserted') $totalInserted++;
            else $totalUpdated++;
        } catch (Throwable $e) {
            $errors++;
        }
    }
    $logLine('  Upcoming: ' . count($data['data']) . ' items.');
}

// ── 4. Sync episode air dates for airing anime ────────────────────────────────
// Only fetch episodes for currently airing series — these change weekly
$logLine('Syncing episode air dates for airing series...');
$airingAnime = db()->query('
    SELECT id, mal_id, title, episodes AS total_eps
    FROM anime
    WHERE status = "Currently Airing"
    LIMIT 40
')->fetchAll();

$epInserted = 0;
$epUpdated  = 0;

foreach ($airingAnime as $anime) {
    // Fetch all episode pages for this anime
    $page = 1;
    do {
        $epData = jikanFetch("/anime/{$anime['mal_id']}/episodes?page={$page}");
        if (!$epData || empty($epData['data'])) break;

        foreach ($epData['data'] as $ep) {
            // Only store episodes that have already aired
            $airedDate = $ep['aired'] ?? null;
            if ($airedDate && strtotime($airedDate) > time()) {
                continue; // skip future episodes
            }

            try {
                $stmt = db()->prepare('
                    INSERT INTO episodes (anime_id, episode_number, title, aired)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        title  = VALUES(title),
                        aired  = VALUES(aired)
                ');
                $stmt->execute([
                    $anime['id'],
                    $ep['mal_id'],   // mal_id in episodes = episode number
                    $ep['title'] ?? null,
                    $airedDate ? date('Y-m-d', strtotime($airedDate)) : null,
                ]);
                if (db()->lastInsertId()) $epInserted++;
                else $epUpdated++;
            } catch (Throwable $e) {
                $errors++;
            }
        }

        $hasNext = $epData['pagination']['has_next_page'] ?? false;
        $page++;
        if ($page > 5) break; // safety cap
    } while ($hasNext);
}

// Also sync completed anime that have no episodes stored yet
$needsEps = db()->query('
    SELECT a.id, a.mal_id, a.title
    FROM anime a
    LEFT JOIN episodes e ON e.anime_id = a.id
    WHERE e.id IS NULL
      AND a.status IN ("Finished Airing", "Currently Airing")
      AND a.episodes > 0
    LIMIT 20
')->fetchAll();

foreach ($needsEps as $anime) {
    $epData = jikanFetch("/anime/{$anime['mal_id']}/episodes");
    if (!$epData || empty($epData['data'])) continue;

    foreach ($epData['data'] as $ep) {
        $airedDate = $ep['aired'] ?? null;
        if ($airedDate && strtotime($airedDate) > time()) continue;

        try {
            $stmt = db()->prepare('
                INSERT IGNORE INTO episodes (anime_id, episode_number, title, aired)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([
                $anime['id'],
                $ep['mal_id'],
                $ep['title'] ?? null,
                $airedDate ? date('Y-m-d', strtotime($airedDate)) : null,
            ]);
            if (db()->lastInsertId()) $epInserted++;
        } catch (Throwable $e) {}
    }
}

$logLine("  Episodes synced — Inserted:{$epInserted} Updated:{$epUpdated}");


// ── 5. Log sync run ───────────────────────────────────────────────────────────
try {
    db()->prepare('INSERT INTO activity_log (action, detail, ip) VALUES (?,?,?)')
        ->execute(['sync', "Inserted:{$totalInserted} Updated:{$totalUpdated} Errors:{$errors}", 'cron']);
} catch (Throwable $e) {}

$summary = "Done. Inserted:{$totalInserted} Updated:{$totalUpdated} Errors:{$errors}";
$logLine($summary);
$logLine('=== Sync Complete ===');

if ($isHttp) {
    echo json_encode([
        'success'  => true,
        'inserted' => $totalInserted,
        'updated'  => $totalUpdated,
        'errors'   => $errors,
        'log'      => $log,
    ]);
}
