<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'未登录']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/db.php';

// 获取 PDO 或 mysqli
try { $pdo = function_exists('get_db_pdo') ? get_db_pdo() : ($pdo ?? null); } catch (Throwable $e) { $pdo = ($pdo ?? null); }
$conn = $conn ?? null;

// 检查管理员权限
$is_admin = 0;
if ($pdo) {
    $stmt = $pdo->prepare('SELECT IFNULL(is_admin,0) FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $is_admin = (int)$stmt->fetchColumn();
} elseif ($conn) {
    if ($stmt = $conn->prepare('SELECT IFNULL(is_admin,0) FROM users WHERE id = ? LIMIT 1')) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($is_admin);
        $stmt->fetch();
        $stmt->close();
        $is_admin = (int)$is_admin;
    }
}
if (!$is_admin) {
    echo json_encode(['success'=>false,'message'=>'权限不足']);
    exit;
}

// 获取并验证 id
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success'=>false,'message'=>'ID 不合法']);
    exit;
}

// 先查询记录（以便删除头像文件）
$avatar = null;
try {
    if ($pdo) {
        $stmt = $pdo->prepare('SELECT avatar FROM studios WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $avatar = $stmt->fetchColumn();
        // 删除记录
        $del = $pdo->prepare('DELETE FROM studios WHERE id = ?');
        $ok = $del->execute([$id]);
    } elseif ($conn) {
        if ($stmt = $conn->prepare('SELECT avatar FROM studios WHERE id = ? LIMIT 1')) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->bind_result($avatar);
            $stmt->fetch();
            $stmt->close();
        }
        $ok = false;
        if ($stmt = $conn->prepare('DELETE FROM studios WHERE id = ?')) {
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
        }
    } else {
        echo json_encode(['success'=>false,'message'=>'数据库不可用']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'数据库错误: '.$e->getMessage()]);
    exit;
}

if (!$ok) {
    echo json_encode(['success'=>false,'message'=>'删除失败']);
    exit;
}

// 尝试删除头像文件（相对路径 uploads/...）
if (!empty($avatar)) {
    $path = __DIR__ . '/' . ltrim($avatar, '/\\');
    if (file_exists($path) && is_file($path)) {
        @unlink($path);
    }
    // 可能还有同名的不同尺寸文件，视具体实现可在此扩展清理逻辑
}

echo json_encode(['success'=>true,'message'=>'已删除']);
exit;
