<?php
/**
 * stream.php — Download handler
 *
 * action=sources  → returns stream URLs + yt-dlp command
 * action=download → triggers yt-dlp server-side to download the file,
 *                   then streams it to the browser
 * action=status   → checks if a download job is done
 */
require_once __DIR__ . '/../config.php';

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');

$action = $_GET['action'] ?? '';

// ── Sources ───────────────────────────────────────────────────────────────────
if ($action === 'sources') {
    header('Content-Type: application/json');

    $malId = (int)($_GET['mal_id'] ?? 0);
    $ep    = (int)($_GET['ep']     ?? 1);
    $title = trim($_GET['title']   ?? 'Anime');
    if (!$malId) { echo json_encode(['error' => 'Missing mal_id']); exit; }

    $safe    = preg_replace('/[^\w\s\-]/', '', $title);
    $subUrl  = "https://megaplay.buzz/stream/mal/{$malId}/{$ep}/sub";
    $dubUrl  = "https://megaplay.buzz/stream/mal/{$malId}/{$ep}/dub";

    $sources = [
        ['label' => 'SUB', 'url' => $subUrl, 'type' => 'embed'],
        ['label' => 'DUB', 'url' => $dubUrl, 'type' => 'embed'],
    ];

    // Try Consumet for direct HLS
    $consumet = [];
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $sr  = @file_get_contents("http://localhost:3000/anime/hianime/" . urlencode($title), false, $ctx);
        if ($sr) {
            $sd = json_decode($sr, true);
            $results = $sd['animes'] ?? $sd['results'] ?? [];
            $clean   = strtolower(explode(':', $title)[0]);
            $match   = null;
            foreach ($results as $r) {
                if (str_contains(strtolower($r['name'] ?? $r['title'] ?? ''), $clean)) { $match = $r; break; }
            }
            if (!$match && $results) $match = $results[0];
            if ($match) {
                $ir = @file_get_contents("http://localhost:3000/anime/hianime/info?id=" . urlencode($match['id']), false, $ctx);
                if ($ir) {
                    $id   = json_decode($ir, true);
                    $eps  = $id['seasons'][0]['episodes'] ?? $id['episodes'] ?? [];
                    $epObj = null;
                    foreach ($eps as $e) { if (($e['number'] ?? 0) == $ep) { $epObj = $e; break; } }
                    if (!$epObj && isset($eps[$ep-1])) $epObj = $eps[$ep-1];
                    if ($epObj) {
                        $wr = @file_get_contents("http://localhost:3000/anime/hianime/watch?episodeId=" . urlencode($epObj['id']), false,
                            stream_context_create(['http' => ['timeout' => 8]]));
                        if ($wr) {
                            $wd = json_decode($wr, true);
                            foreach ($wd['sources'] ?? [] as $s) {
                                $consumet[] = ['label' => ($s['quality'] ?? 'Auto') . ' HLS', 'url' => $s['url'], 'type' => 'hls'];
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {}

    // Check if yt-dlp is installed
    $ytdlpPath = null;
    $candidates = [
        'C:/xampp/yt-dlp.exe',
        'C:/Windows/System32/yt-dlp.exe',
        'yt-dlp',
    ];
    foreach ($candidates as $p) {
        if (file_exists($p)) { $ytdlpPath = $p; break; }
        $out = @shell_exec('where ' . escapeshellarg($p) . ' 2>nul');
        if ($out) { $ytdlpPath = trim($out); break; }
    }
    if (!$ytdlpPath) {
        $out = @shell_exec('yt-dlp --version 2>nul');
        if ($out) $ytdlpPath = 'yt-dlp';
    }

    echo json_encode([
        'sources'       => array_merge($consumet, $sources),
        'ytdlp_cmd'     => "yt-dlp \"{$subUrl}\" -o \"{$safe} - Episode {$ep}.mp4\"",
        'ytdlp_url'     => $subUrl,
        'ytdlp_found'   => (bool)$ytdlpPath,
        'ytdlp_path'    => $ytdlpPath,
        'safe_title'    => $safe,
        'mal_id'        => $malId,
        'episode'       => $ep,
    ]);
    exit;
}

// ── Server-side yt-dlp download ───────────────────────────────────────────────
// Triggers yt-dlp on the server, saves to a temp file, streams to browser
if ($action === 'ytdlp') {
    header('Content-Type: application/json');

    $url      = $_GET['url']      ?? '';
    $filename = $_GET['filename'] ?? 'episode.mp4';
    $filename = preg_replace('/[^\w\s\-\.]/', '', $filename);
    if (!str_ends_with(strtolower($filename), '.mp4')) $filename .= '.mp4';

    if (!$url) { echo json_encode(['error' => 'Missing URL']); exit; }

    // Save to XAMPP temp dir
    $tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anistream';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
    $outFile = $tmpDir . DIRECTORY_SEPARATOR . $filename;

    // Run yt-dlp
    $ytdlpBin = file_exists('C:/xampp/yt-dlp.exe') ? 'C:/xampp/yt-dlp.exe' : 'yt-dlp';
    $cmd = escapeshellarg($ytdlpBin) . ' ' . escapeshellarg($url) . ' -o ' . escapeshellarg($outFile) . ' --no-playlist 2>&1';
    $output = shell_exec($cmd);

    if (file_exists($outFile)) {
        echo json_encode(['success' => true, 'file' => basename($outFile), 'token' => md5($outFile)]);
    } else {
        echo json_encode(['error' => 'yt-dlp failed', 'output' => $output]);
    }
    exit;
}

// ── Serve downloaded file ─────────────────────────────────────────────────────
if ($action === 'serve') {
    $filename = preg_replace('/[^\w\s\-\.]/', '', $_GET['file'] ?? '');
    if (!$filename) { http_response_code(400); exit; }

    $tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anistream';
    $path    = $tmpDir . DIRECTORY_SEPARATOR . $filename;

    if (!file_exists($path)) { http_response_code(404); echo 'File not found'; exit; }

    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Accel-Buffering: no');
    readfile($path);
    // Clean up after serving
    @unlink($path);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
