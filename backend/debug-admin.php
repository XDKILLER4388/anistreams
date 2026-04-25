<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

echo "=== DB Connection Test ===\n";
try {
    $pdo = db();
    echo "DB: OK\n\n";
} catch (Exception $e) {
    die("DB FAILED: " . $e->getMessage() . "\n");
}

echo "=== Table Checks ===\n";
$tables = ['users', 'user_downloads', 'activity_log'];
foreach ($tables as $t) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: OK ($n rows)\n";
    } catch (Exception $e) {
        echo "$t: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Stats Queries ===\n";
$queries = [
    'total_users'     => 'SELECT COUNT(*) FROM users WHERE role="user"',
    'online_users'    => 'SELECT COUNT(*) FROM users WHERE last_seen > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND role="user"',
    'total_downloads' => 'SELECT COUNT(*) FROM user_downloads',
    'today_logins'    => 'SELECT COUNT(*) FROM activity_log WHERE action="login" AND DATE(created_at)=CURDATE()',
    'banned_users'    => 'SELECT COUNT(*) FROM users WHERE is_banned=1',
];
foreach ($queries as $key => $sql) {
    try {
        echo "$key: " . $pdo->query($sql)->fetchColumn() . "\n";
    } catch (Exception $e) {
        echo "$key: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Session ===\n";
session_start();
echo "session_id: " . session_id() . "\n";
echo "user_id in session: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";

echo "\n=== Admin API Test ===\n";
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    echo "Logged in as: " . ($u ? $u['username'] . ' (role: ' . $u['role'] . ')' : 'NOT FOUND') . "\n";
} else {
    echo "No session — not logged in\n";
}
