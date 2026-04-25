<?php
/**
 * Run this ONCE to set up the admin account and add missing columns.
 * Visit: http://localhost/Aninew/anime-platform/backend/setup-admin.php
 * DELETE this file after running it.
 */
require_once __DIR__ . '/config.php';

$results = [];

// Add columns if missing
$cols = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('user','admin') DEFAULT 'user'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_banned TINYINT(1) DEFAULT 0",
];
foreach ($cols as $sql) {
    try { db()->exec($sql); $results[] = "✅ $sql"; }
    catch (Exception $e) { $results[] = "⚠️ " . $e->getMessage(); }
}

// Create activity_log table
try {
    db()->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED,
        username VARCHAR(50),
        action VARCHAR(50) NOT NULL,
        detail VARCHAR(255),
        ip VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    )");
    $results[] = "✅ activity_log table ready";
} catch (Exception $e) { $results[] = "⚠️ " . $e->getMessage(); }

// Create user_downloads table
try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_downloads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        anime_id INT UNSIGNED,
        mal_id INT UNSIGNED NOT NULL,
        episode SMALLINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        ep_title VARCHAR(255),
        cover VARCHAR(512),
        download_url VARCHAR(512),
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_ep (user_id, mal_id, episode),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $results[] = "✅ user_downloads table ready";
} catch (Exception $e) { $results[] = "⚠️ " . $e->getMessage(); }

// Create admin account
$adminPass = password_hash('admin123', PASSWORD_BCRYPT);
try {
    $stmt = db()->prepare("INSERT INTO users (username, email, password, role) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role='admin', password=VALUES(password)");
    $stmt->execute(['admin', 'admin@anistream.local', $adminPass, 'admin']);
    $results[] = "✅ Admin account ready — username: admin / password: admin123";
    $results[] = "✅ Hash: " . $adminPass;
} catch (Exception $e) { $results[] = "⚠️ " . $e->getMessage(); }

echo "<pre style='font-family:monospace;background:#111;color:#eee;padding:2rem;line-height:2'>";
echo "<strong>AniStream Setup</strong>\n\n";
foreach ($results as $r) echo $r . "\n";
echo "\n<strong style='color:#4ade80'>Done! Delete this file now for security.</strong>";
echo "\n<a href='../admin.html' style='color:#60a5fa'>→ Go to Admin Panel</a>";
echo "</pre>";
