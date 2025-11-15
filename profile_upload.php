<?php
// 简短响应，JSON 格式
header('Content-Type: application/json; charset=utf-8');

// 禁止在生产环境输出错误（可选）
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '未检测到上传文件或上传失败']);
    exit;
}

$file = $_FILES['avatar'];
$maxSize = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => '文件过大，最大 5MB']);
    exit;
}

// 验证为图片
$imgInfo = @getimagesize($file['tmp_name']);
if (!$imgInfo || !isset($imgInfo['mime'])) {
    echo json_encode(['success' => false, 'message' => '只允许上传图片']);
    exit;
}
$allowed = ['image/jpeg','image/png','image/gif','image/webp'];
if (!in_array($imgInfo['mime'], $allowed, true)) {
    echo json_encode(['success' => false, 'message' => '不支持的图片格式']);
    exit;
}

// 目标目录
$uploadDir = __DIR__ . '/uploads/avatars';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// 生成文件名
$filename = 'user_' . $user_id . '_' . time() . '.webp';
$destPath = $uploadDir . '/' . $filename;

// 使用 GD 转换为 webp（优先使用 imagecreatefrom*）
$image = null;
switch ($imgInfo['mime']) {
    case 'image/jpeg':
        $image = @imagecreatefromjpeg($file['tmp_name']);
        break;
    case 'image/png':
        $image = @imagecreatefrompng($file['tmp_name']);
        // 保持透明度
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        break;
    case 'image/gif':
        $image = @imagecreatefromgif($file['tmp_name']);
        break;
    case 'image/webp':
        if (function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($file['tmp_name']);
        } else {
            // 如果没有 webp 支持，用 imagecreatefromstring 作为回退
            $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        }
        break;
    default:
        $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        break;
}

if (!$image) {
    echo json_encode(['success' => false, 'message' => '无法解析图片']);
    exit;
}

// 检查 imagewebp 可用性
if (!function_exists('imagewebp')) {
    // 释放资源
    imagedestroy($image);
    echo json_encode(['success' => false, 'message' => '服务器不支持将图片转换为 WebP（缺少 GD 的 webp 支持）']);
    exit;
}

// 保存为 webp（质量 80）
$quality = 80;
if (!imagewebp($image, $destPath, $quality)) {
    imagedestroy($image);
    echo json_encode(['success' => false, 'message' => '保存文件失败']);
    exit;
}
imagedestroy($image);

// 设置 Web 可访问路径（相对当前目录）
$webPath = 'uploads/avatars/' . $filename;

// 更新数据库 users.avatar 字段（兼容 PDO / mysqli）
require_once __DIR__ . '/db.php';
$updated = false;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
        $updated = $stmt->execute([$webPath, $user_id]);
    } catch (Exception $e) {
        // ignore
    }
} elseif (isset($conn) && $conn instanceof mysqli) {
    if ($stmt = $conn->prepare('UPDATE users SET avatar = ? WHERE id = ?')) {
        $stmt->bind_param('si', $webPath, $user_id);
        $updated = $stmt->execute();
        $stmt->close();
    }
}

// 返回结果（即使数据库未更新，也返回文件路径以便前端展示）
if ($updated) {
    echo json_encode(['success' => true, 'avatar' => $webPath]);
} else {
    echo json_encode(['success' => true, 'avatar' => $webPath, 'message' => '文件已上传，但未能更新数据库（请手动检查）']);
}
exit;
