<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求']);
    exit;
}

require_once __DIR__ . '/db.php';

$username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$confirm = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
$emailRaw = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$verif = isset($_POST['verification_code']) ? trim((string)$_POST['verification_code']) : '';

// 基本校验
if ($username === '' || $password === '' || $confirm === '' || $emailRaw === '' || $verif === '') {
    echo json_encode(['success' => false, 'message' => '所有字段都不能为空']);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => '密码和确认密码不匹配']);
    exit;
}
if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 64) {
    echo json_encode(['success' => false, 'message' => '用户名长度不合法']);
    exit;
}
if (mb_strlen($password, 'UTF-8') < 6) {
    echo json_encode(['success' => false, 'message' => '密码长度至少为6位']);
    exit;
}
if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => '邮箱格式不正确']);
    exit;
}

$email = mb_strtolower($emailRaw, 'UTF-8');

$pdo = get_db_pdo();
ensure_verification_table($pdo);

// 验证验证码：查询数据库中是否存在未过期且匹配的记录
try {
    $stmt = $pdo->prepare('SELECT id, code, expire FROM verification_codes WHERE email = :email ORDER BY sent_at DESC LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => '请先获取验证码']);
        exit;
    }
    $now = time();
    if ($now > (int)$row['expire']) {
        echo json_encode(['success' => false, 'message' => '验证码已过期，请重新获取']);
        exit;
    }
    if ($verif === '' || $verif !== $row['code']) {
        echo json_encode(['success' => false, 'message' => '验证码错误']);
        exit;
    }
    $codeId = (int)$row['id'];
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '验证码校验失败: ' . $e->getMessage()]);
    exit;
}

// 使用数据库：检查用户名/邮箱是否已存在并插入新用户
try {
    // 检查用户名
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $stmt->execute([':username' => $username]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '用户名已存在']);
        exit;
    }
    // 检查邮箱
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute([':email' => $emailRaw]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '该邮箱已被使用']);
        exit;
    }

    // 插入用户
    $hash = password_hash($password, PASSWORD_DEFAULT);
    // 兼容 DATETIME 字段：使用格式化的时间字符串
    $now = time();
    $createdAt = date('Y-m-d H:i:s', $now);
    $stmt = $pdo->prepare('INSERT INTO users (username, email, password, created_at) VALUES (:username, :email, :password, :created_at)');
    $stmt->execute([
        ':username' => $username,
        ':email' => $emailRaw,
        ':password' => $hash,
        ':created_at' => $createdAt
    ]);

    // 注册成功后删除该验证码记录
    if (isset($codeId) && $codeId) {
        $del = $pdo->prepare('DELETE FROM verification_codes WHERE id = :id');
        $del->execute([':id' => $codeId]);
    }

    // 发送欢迎邮件


    echo json_encode(['success' => true, 'message' => '注册成功']);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '注册失败: ' . $e->getMessage()]);
    exit;
}