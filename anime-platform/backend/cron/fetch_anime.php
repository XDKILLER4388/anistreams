<?php
/**
 * AniStream Auto-Sync
 * - Fetches currently airing, top, upcoming anime → upserts into DB
 * - Syncs ALL episodes for every anime in the DB
 * - For airing anime: re-checks every run (new episodes drop weekly)
 * - For finished anime: syncs once, skips on future runs
 *
 * Schedule (Windows Task Scheduler):
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\Aninew\anime-platform\backend\cron\fetch_anime.php
 *   Frequency: Every 1 hour (airing episodes update weekly but we check hourly)
 *
 * HTTP trigger (admin panel):
 *   GET /backend/cron/fetch_anime.php?secret=anistream_sync
 */
require_once __DIR__ . '/../config.php';

set_time_limit(0);
ini_set('memory_limit', '256M');

$isHttp = php_sapi_name() !== 'cli';
if ($isHttp) {
    header('Content-Type: application/json');
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'anistream_sync') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
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
$epInserted    = 0;
$epUpdated     = 0;
$errors        = 0;

// ── Rate-limited Jikan fetch (max 3 req/sec) ─────────────────────────────────
function jikanFetch(string $path, int $retries = 3): ?array {
    static $lastCall = 0;
    $minGap = 400000; // 400ms between calls
    $wait = $minGap - (int)((microtime(true) * 1000000) - $lastCall);
    if ($wait > 0) usleep($wait);
    $lastCall = (int)(microtime(true) * 1000000);

    $url = 'https://api.jikan.moe/v4' . $path;
    $ctx = stream_context_create(['http' => [
        'timeout' => 20,
        'header'  => "User-Agent: AniStream/1.0\r\n",
        'ignore_errors' => true,
    ]]);

    for ($i = 0; $i < $retries; $i++) {
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) { usleep(800000); continue; }

        // Check HTTP status
        $status = 200;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) $status = (int)$m[1];
        }
        if ($status === 429) { usleep(1500000 + $i * 500000); continue; } // rate limited
        if ($status >= 500)  { usleep(1000000); continue; }

        $data = json_decode($res, true);
        return $data ?? null;
    }
    return null;
}

// ── Upsert one anime record ───────────────────────────────────────────────────
function upsertAnime(array $a): string {
    $genres = implode(',', array_column($a['genres'] ?? [], 'name'));
    $year   = $a['year'] ?? ($a['aired']['prop']['from']['year'] ?? null);

    db()->prepare('
        INSERT INTO anime (mal_id, title, title_jp, synopsis, cover_image, genre, score, episodes, status, type, year)
        VALUES (:mal_id, :title, :title_jp, :synopsis, :cover, :genre, :score, :eps, :status, :type, :year)
        ON DUPLICATE KEY UPDATE
            title       = VALUES(title),
            title_jp    = VALUES(title_jp),
            synopsis    = VALUES(synopsis),
            cover_image = VALUES(cover_image),
            genre       = VALUES(genre),
            score       = VALUES(score),
            episodes    = VALUES(episodes),
            status      = VALUES(status),
            type        = VALUES(type),
            year        = VALUES(year),
            updated_at  = NOW()
    ')->execute([
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

    // Return whether it was a new insert or update
    return db()->query('SELECT ROW_COUNT()')->fetchColumn() == 1 ? 'inserted' : 'updated';
}

// ── Sync all episodes for a given anime ──────────────────────────────────────
function syncEpisodes(int $animeId, int $malId, string $title, bool $isAiring): array {
    global $epInserted, $epUpdated, $errors;
    $inserted = 0; $updated = 0;
    $now = time();

    for ($page = 1; $page <= 50; $page++) { // up to 50 pages = 1000 episodes
        $data = jikanFetch("/anime/{$malId}/episodes?page={$page}");
        if (!$data || empty($data['data'])) break;

        foreach ($data['data'] as $ep) {
            $epNum     = $ep['mal_id']; // episode number
            $epTitle   = $ep['title'] ?? null;
            $airedDate = $ep['aired'] ?? null;

            // Skip future episodes for airing shows
            if ($isAiring && $airedDate && strtotime($airedDate) > $now) continue;

            try {
                db()->prepare('
                    INSERT INTO episodes (anime_id, episode_number, title, aired)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        aired = VALUES(aired)
                ')->execute([
                    $animeId,
                    $epNum,
                    $epTitle,
                    $airedDate ? date('Y-m-d', strtotime($airedDate)) : null,
                ]);

                $rows = db()->query('SELECT ROW_COUNT()')->fetchColumn();
                if ($rows == 1) { $inserted++; $epInserted++; }
                else            { $updated++;  $epUpdated++;  }
            } catch (Throwable $e) {
                $errors++;
            }
        }

        if (!($data['pagination']['has_next_page'] ?? false)) break;
        usleep(200000); // extra pause between pages
    }

    return ['inserted' => $inserted, 'updated' => $updated];
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 1 — Fetch anime lists from Jikan
// ═══════════════════════════════════════════════════════════════════════════════

// 1a. Currently airing (up to 5 pages = 125 anime)
$logLine('Fetching currently airing anime...');
for ($page = 1; $page <= 5; $page++) {
    $data = jikanFetch("/seasons/now?page={$page}&limit=25");
    if (!$data || empty($data['data'])) break;
    foreach ($data['data'] as $a) {
        try {
            $r = upsertAnime($a);
            if ($r === 'inserted') $totalInserted++; else $totalUpdated++;
        } catch (Throwable $e) { $errors++; }
    }
    $logLine("  Airing page {$page}: " . count($data['data']) . " items");
    if (!($data['pagination']['has_next_page'] ?? false)) break;
}

// 1b. Top anime (up to 4 pages = 100 anime)
$logLine('Fetching top anime...');
for ($page = 1; $page <= 4; $page++) {
    $data = jikanFetch("/top/anime?page={$page}&limit=25");
    if (!$data || empty($data['data'])) break;
    foreach ($data['data'] as $a) {
        try {
            $r = upsertAnime($a);
            if ($r === 'inserted') $totalInserted++; else $totalUpdated++;
        } catch (Throwable $e) { $errors++; }
    }
    $logLine("  Top page {$page}: " . count($data['data']) . " items");
    if (!($data['pagination']['has_next_page'] ?? false)) break;
}

// 1c. Upcoming (next season)
$logLine('Fetching upcoming anime...');
for ($page = 1; $page <= 2; $page++) {
    $data = jikanFetch("/seasons/upcoming?page={$page}&limit=25");
    if (!$data || empty($data['data'])) break;
    foreach ($data['data'] as $a) {
        try {
            $r = upsertAnime($a);
            if ($r === 'inserted') $totalInserted++; else $totalUpdated++;
        } catch (Throwable $e) { $errors++; }
    }
    if (!($data['pagination']['has_next_page'] ?? false)) break;
}
$logLine("  Anime sync done — Inserted:{$totalInserted} Updated:{$totalUpdated}");

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2 — Sync episodes for ALL anime in the database
// ═══════════════════════════════════════════════════════════════════════════════
$logLine('Syncing episodes...');

// Priority 1: Currently airing — always re-sync (new episodes every week)
$airing = db()->query("
    SELECT id, mal_id, title, episodes AS total_eps
    FROM anime
    WHERE status = 'Currently Airing'
    ORDER BY score DESC
")->fetchAll();

$logLine("  Airing series to sync: " . count($airing));
foreach ($airing as $anime) {
    $r = syncEpisodes($anime['id'], $anime['mal_id'], $anime['title'], true);
    $logLine("    [{$anime['title']}] +{$r['inserted']} new, ~{$r['updated']} updated");
}

// Priority 2: Finished anime with NO episodes stored yet
$needsEps = db()->query("
    SELECT a.id, a.mal_id, a.title, a.episodes AS total_eps
    FROM anime a
    LEFT JOIN episodes e ON e.anime_id = a.id
    WHERE e.id IS NULL
      AND a.status IN ('Finished Airing', 'Currently Airing')
      AND a.episodes > 0
    ORDER BY a.score DESC
    LIMIT 60
")->fetchAll();

$logLine("  Finished anime missing episodes: " . count($needsEps));
foreach ($needsEps as $anime) {
    $r = syncEpisodes($anime['id'], $anime['mal_id'], $anime['title'], false);
    $logLine("    [{$anime['title']}] +{$r['inserted']} episodes");
}

// Priority 3: Finished anime where stored episode count < expected total
// (catches partial syncs from previous runs)
$incomplete = db()->query("
    SELECT a.id, a.mal_id, a.title, a.episodes AS total_eps, COUNT(e.id) AS stored
    FROM anime a
    LEFT JOIN episodes e ON e.anime_id = a.id
    WHERE a.status = 'Finished Airing'
      AND a.episodes > 0
    GROUP BY a.id
    HAVING stored < total_eps
    ORDER BY a.score DESC
    LIMIT 30
")->fetchAll();

$logLine("  Incomplete episode syncs: " . count($incomplete));
foreach ($incomplete as $anime) {
    $r = syncEpisodes($anime['id'], $anime['mal_id'], $anime['title'], false);
    $logLine("    [{$anime['title']}] had {$anime['stored']}/{$anime['total_eps']}, +{$r['inserted']} added");
}

$logLine("  Episode sync done — Inserted:{$epInserted} Updated:{$epUpdated}");

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 3 — Log the run
// ═══════════════════════════════════════════════════════════════════════════════
try {
    db()->prepare("INSERT INTO activity_log (action, detail, ip) VALUES (?,?,?)")
        ->execute(['sync', "Anime +{$totalInserted}/~{$totalUpdated} | Episodes +{$epInserted}/~{$epUpdated} | Errors:{$errors}", 'cron']);
} catch (Throwable $e) {}

$logLine("=== Sync Complete — Anime +{$totalInserted}/~{$totalUpdated} | Episodes +{$epInserted}/~{$epUpdated} | Errors:{$errors} ===");

if ($isHttp) {
    echo json_encode([
        'success'       => true,
        'anime_inserted'=> $totalInserted,
        'anime_updated' => $totalUpdated,
        'ep_inserted'   => $epInserted,
        'ep_updated'    => $epUpdated,
        'errors'        => $errors,
        'log'           => $log,
    ]);
}
