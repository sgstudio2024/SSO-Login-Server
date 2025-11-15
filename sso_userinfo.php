<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
$pdo = get_db_pdo();
ensure_sso_tables($pdo);

// 获取 access_token：优先 Authorization header
$auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim($_SERVER['HTTP_AUTHORIZATION']) : '';
$token = '';
if ($auth && stripos($auth, 'Bearer ') === 0) {
    $token = substr($auth, 7);
} elseif (isset($_GET['access_token'])) {
    $token = trim((string)$_GET['access_token']);
}

if ($token === '') {
    echo json_encode(['success'=>false,'error'=>'invalid_request']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM sso_tokens WHERE access_token = :token LIMIT 1');
$stmt->execute([':token'=>$token]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rec || time() > (int)$rec['expire']) {
    echo json_encode(['success'=>false,'error'=>'invalid_token']);
    exit;
}

// 查询用户信息
$stmt = $pdo->prepare('SELECT id, username, email, created_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => (int)$rec['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(['success'=>false,'error'=>'invalid_token']);
    exit;
}

echo json_encode([
    'success'=>true,
    'data' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'email' => $user['email']
    ]
]);
exit;
