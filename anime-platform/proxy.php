<?php
/**
 * Reverse proxy — fetches a remote page and strips X-Frame-Options
 * so it can be embedded in an iframe on localhost.
 * Usage: proxy.php?url=https://www.animegg.org/...
 */

$url = $_GET['url'] ?? '';

// Whitelist — only allow these domains
$allowed = ['animegg.org', 'allanime.to', 'allanime.day'];
$host    = parse_url($url, PHP_URL_HOST);
$allowed_host = false;
foreach ($allowed as $a) {
    if ($host === $a || str_ends_with($host, '.' . $a)) {
        $allowed_host = true;
        break;
    }
}

if (!$url || !$allowed_host) {
    http_response_code(400);
    echo 'Invalid or disallowed URL.';
    exit;
}

// Fetch the remote page
$ctx = stream_context_create([
    'http' => [
        'timeout'        => 15,
        'follow_location' => 1,
        'max_redirects'  => 5,
        'header'         => implode("\r\n", [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Referer: https://www.animegg.org/',
        ])
    ]
]);

$body = @file_get_contents($url, false, $ctx);

if ($body === false) {
    http_response_code(502);
    echo 'Failed to fetch remote page.';
    exit;
}

// Fix relative URLs so assets (CSS, JS, images) load correctly
$base = parse_url($url, PHP_URL_SCHEME) . '://' . $host;
$body = preg_replace('/(src|href|action)=["\']\/(?!\/)/i', '$1="' . $base . '/', $body);

// Forward content-type, strip frame-blocking headers
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: ');

echo $body;
