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

// 检查工作室是否存在
$stmt = $pdo->prepare('SELECT COUNT(*) FROM studios WHERE id = ?');
$stmt->execute([$studio_id]);
if (((int)$stmt->fetchColumn()) === 0) { echo json_encode(['success'=>false,'message'=>'工作室不存在']); exit; }

// 新增：检查用户是否已属于某个工作室（不能同时加入多个）
$chkExisting = $pdo->prepare('SELECT studio_id FROM studio_members WHERE user_id = :uid LIMIT 1');
$chkExisting->execute([':uid' => $user_id]);
$existing = $chkExisting->fetchColumn();
if ($existing && (int)$existing !== $studio_id) {
    echo json_encode(['success'=>false,'message'=>'您已属于其他工作室，请先退出当前工作室后再加入新工作室']);
    exit;
}

// 检查是否已加入当前工作室
$chk = $pdo->prepare('SELECT COUNT(*) FROM studio_members WHERE studio_id = :sid AND user_id = :uid');
$chk->execute([':sid'=>$studio_id,':uid'=>$user_id]);
if (((int)$chk->fetchColumn()) > 0) { echo json_encode(['success'=>false,'message'=>'已是成员']); exit; }

// 插入
$ins = $pdo->prepare('INSERT INTO studio_members (studio_id,user_id,role,joined_at) VALUES (:sid,:uid,:role,NOW())');
try {
	$ins->execute([':sid'=>$studio_id,':uid'=>$user_id,':role'=>'member']);
	echo json_encode(['success'=>true,'message'=>'已加入','studio_id'=>$studio_id]);
} catch (Throwable $e) {
	// 如果数据库唯一约束导致失败，给友好提示
	$msg = $e->getMessage();
	echo json_encode(['success'=>false,'message'=>'加入失败: '.$msg]);
}
