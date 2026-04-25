<?php
require_once __DIR__ . '/config.php';

$email = 'admin@anistream.local';
$password = 'admin123';

$stmt = db()->prepare('SELECT id, username, email, password, role FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

echo "<pre>";
echo "User found: " . ($user ? 'YES' : 'NO') . "\n";
if ($user) {
    echo "Username: " . $user['username'] . "\n";
    echo "Role: " . $user['role'] . "\n";
    echo "Password verify: " . (password_verify($password, $user['password']) ? 'YES ✅' : 'NO ❌') . "\n";
    echo "Hash in DB: " . $user['password'] . "\n";
}

// Also test the auth endpoint directly
echo "\n--- Testing auth API ---\n";
$ch = curl_init('http://localhost/Aninew/anime-platform/backend/api/auth.php?action=login');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'password' => $password]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
echo "API response: " . $response . "\n";
curl_close($ch);
echo "</pre>";
