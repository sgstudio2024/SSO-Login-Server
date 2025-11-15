<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors',0); error_reporting(0);
session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'未登录']); exit; }
$user_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/db.php';

try { $pdo = function_exists('get_db_pdo') ? get_db_pdo() : ($pdo ?? null); } catch (Throwable $e) { $pdo = ($pdo ?? null); }
$has_admin = 0;
if ($pdo) {
    $stmt = $pdo->prepare('SELECT IFNULL(is_admin,0) FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $has_admin = (int)$stmt->fetchColumn();
} elseif (isset($conn) && $conn instanceof mysqli) {
    $stmt = $conn->prepare('SELECT IFNULL(is_admin,0) FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i',$user_id);
    $stmt->execute();
    $stmt->bind_result($has_admin);
    $stmt->fetch();
    $stmt->close();
    $has_admin = (int)$has_admin;
}
if (!$has_admin) { echo json_encode(['success'=>false,'message'=>'权限不足']); exit; }

// 获取与验证输入
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$url = isset($_POST['url']) ? trim($_POST['url']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$visible = isset($_POST['visible']) ? (int)$_POST['visible'] : 1;

if ($name === '') { echo json_encode(['success'=>false,'message'=>'名称不能为空']); exit; }
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) { echo json_encode(['success'=>false,'message'=>'网址格式不正确']); exit; }

// 处理头像（可选）
$avatar_web = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['avatar'];
    $maxSize = 5 * 1024 * 1024;
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if ($file['size'] > $maxSize) { echo json_encode(['success'=>false,'message'=>'头像文件过大']); exit; }
    $finfo = @getimagesize($file['tmp_name']);
    if (!$finfo || !in_array($finfo['mime'],$allowed,true)) { echo json_encode(['success'=>false,'message'=>'不支持的头像格式']); exit; }

    $uploadDir = __DIR__ . '/uploads/studios';
    if (!is_dir($uploadDir)) @mkdir($uploadDir,0755,true);
    $filename = 'studio_' . time() . '_' . bin2hex(random_bytes(6)) . '.webp';
    $destPath = $uploadDir . '/' . $filename;

    // 使用 GD 转换为 webp
    $image = null;
    switch ($finfo['mime']) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png': $image = @imagecreatefrompng($file['tmp_name']); break;
        case 'image/gif': $image = @imagecreatefromgif($file['tmp_name']); break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) $image = @imagecreatefromwebp($file['tmp_name']);
            else $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
            break;
        default: $image = @imagecreatefromstring(file_get_contents($file['tmp_name'])); break;
    }
    if (!$image) { echo json_encode(['success'=>false,'message'=>'无法解析图片']); exit; }
    if (!function_exists('imagewebp')) { imagedestroy($image); echo json_encode(['success'=>false,'message'=>'服务器不支持 WebP 转换']); exit; }
    $ok = imagewebp($image, $destPath, 80);
    imagedestroy($image);
    if (!$ok) { echo json_encode(['success'=>false,'message'=>'保存头像失败']); exit; }
    $avatar_web = 'uploads/studios/' . $filename;
}

// 插入数据库
try {
    if ($pdo) {
        $ins = $pdo->prepare('INSERT INTO studios (name, url, avatar, description, visible, created_by, created_at) VALUES (:name,:url,:avatar,:desc,:vis,:cb,:ca)');
        $ins->execute([
            ':name'=>$name, ':url'=>$url ?: null, ':avatar'=>$avatar_web, ':desc'=>$description ?: null,
            ':vis'=>$visible, ':cb'=>$user_id, ':ca'=>date('Y-m-d H:i:s')
        ]);
        $id = $pdo->lastInsertId();
    } else {
        $avatar_sql = $conn->real_escape_string($avatar_web ?? '');
        $name_sql = $conn->real_escape_string($name);
        $url_sql = $conn->real_escape_string($url);
        $desc_sql = $conn->real_escape_string($description);
        $created_at = date('Y-m-d H:i:s');
        $visible_i = (int)$visible;
        $sql = "INSERT INTO studios (name,url,avatar,description,visible,created_by,created_at) VALUES ('{$name_sql}','{$url_sql}','{$avatar_sql}','{$desc_sql}',{$visible_i},{$user_id},'{$created_at}')";
        if (!$conn->query($sql)) throw new Exception($conn->error);
        $id = $conn->insert_id;
    }
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'数据库写入失败: '.$e->getMessage()]); exit;
}

$studio = [
    'id' => (int)$id,
    'name' => $name,
    'url' => $url,
    'avatar' => $avatar_web,
    'description' => $description,
    'visible' => (int)$visible,
    'created_by' => $user_id,
    'created_at' => date('Y-m-d H:i:s')
];

echo json_encode(['success'=>true,'message'=>'创建成功','studio'=>$studio]);
exit;
