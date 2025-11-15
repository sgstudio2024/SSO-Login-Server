<?php
header('Content-Type: application/json; charset=utf-8');

// 简单请求方法检查
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'available' => false, 'message' => '仅支持 POST 请求']);
    exit;
}

require_once __DIR__ . '/db.php';

// 获取并清理用户名
$username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
if ($username === '') {
    echo json_encode(['success' => false, 'available' => false, 'message' => '用户名为空']);
    exit;
}

// 基本校验：长度和字符（可按需调整）
if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 64) {
    echo json_encode(['success' => false, 'available' => false, 'message' => '用户名长度不合法']);
    exit;
}

// 数据库查询判断用户名是否存在
$pdo = get_db_pdo();
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $stmt->execute([':username' => $username]);
    $exists = (bool)$stmt->fetch();
    if ($exists) {
        echo json_encode(['success' => true, 'available' => false, 'message' => '用户名已被占用']);
    } else {
        echo json_encode(['success' => true, 'available' => true, 'message' => '用户名可用']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'available' => false, 'message' => '检查失败: ' . $e->getMessage()]);
}
