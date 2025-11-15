<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'未登录']); exit; }
$user_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/db.php';
$studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
if ($studio_id <= 0) { echo json_encode(['success'=>false,'message'=>'参数错误']); exit; }

try {
	$pdo = function_exists('get_db_pdo') ? get_db_pdo() : ($pdo ?? null);
} catch (Throwable $e) { $pdo = ($pdo ?? null); }

if (!$pdo) { echo json_encode(['success'=>false,'message'=>'数据库不可用']); exit; }

// 删除 membership（若存在）
$del = $pdo->prepare('DELETE FROM studio_members WHERE studio_id = :sid AND user_id = :uid');
try {
	$del->execute([':sid'=>$studio_id,':uid'=>$user_id]);
	if ($del->rowCount() > 0) {
		echo json_encode(['success'=>true,'message'=>'已退出']);
	} else {
		echo json_encode(['success'=>false,'message'=>'您并非该工作室成员']);
	}
} catch (Throwable $e) {
	echo json_encode(['success'=>false,'message'=>'操作失败: '.$e->getMessage()]);
}
