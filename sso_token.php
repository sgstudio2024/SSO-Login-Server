<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
$pdo = get_db_pdo();
ensure_sso_tables($pdo);

// 仅允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'invalid_request','error_description'=>'仅支持 POST']);
    exit;
}

$grant_type = isset($_POST['grant_type']) ? $_POST['grant_type'] : '';
$client_id = isset($_POST['client_id']) ? trim((string)$_POST['client_id']) : '';
$client_secret = isset($_POST['client_secret']) ? trim((string)$_POST['client_secret']) : '';
$code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';

if ($grant_type !== 'authorization_code' || $client_id === '' || $client_secret === '' || $code === '') {
    echo json_encode(['success'=>false,'error'=>'invalid_request']);
    exit;
}

// 验证客户端（client_secret 存为 hash）
$stmt = $pdo->prepare('SELECT * FROM sso_clients WHERE client_id = :cid LIMIT 1');
$stmt->execute([':cid'=>$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client || !password_verify($client_secret, $client['client_secret'])) {
    echo json_encode(['success'=>false,'error'=>'invalid_client']);
    exit;
}

// 验证 code
$stmt = $pdo->prepare('SELECT * FROM sso_codes WHERE code = :code AND client_id = :client LIMIT 1');
$stmt->execute([':code'=>$code, ':client'=>$client_id]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rec) {
    echo json_encode(['success'=>false,'error'=>'invalid_grant','error_description'=>'无效的 code']);
    exit;
}
if ((int)$rec['used'] === 1 || time() > (int)$rec['expire']) {
    echo json_encode(['success'=>false,'error'=>'invalid_grant','error_description'=>'code 已过期或已使用']);
    exit;
}

// 生成 access token（随机）
$token = bin2hex(random_bytes(24));
$expire = time() + 3600; // 1 小时有效
$ins = $pdo->prepare('INSERT INTO sso_tokens (access_token, user_id, client_id, expire) VALUES (:token, :uid, :client, :expire)');
$ins->execute([':token'=>$token, ':uid'=>$rec['user_id'], ':client'=>$client_id, ':expire'=>$expire]);

// 将 code 标记为已用
$upd = $pdo->prepare('UPDATE sso_codes SET used=1 WHERE id = :id');
$upd->execute([':id'=>(int)$rec['id']]);

echo json_encode([
    'access_token'=>$token,
    'token_type'=>'bearer',
    'expires_in'=>3600
]);
exit;
