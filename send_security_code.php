<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(0);
session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'未登录']); exit; }
$user_id = (int)$_SESSION['user_id'];

require_once __DIR__ . '/db.php';
try {
    $pdo = function_exists('get_db_pdo') ? get_db_pdo() : ($pdo ?? null);
} catch (Throwable $e) { $pdo = ($pdo ?? null); }
if (!$pdo) { echo json_encode(['success'=>false,'message'=>'数据库不可用']); exit; }

// 读取用户当前邮箱
$stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user_id]);
$currentEmail = $stmt->fetchColumn();
if (!$currentEmail) { echo json_encode(['success'=>false,'message'=>'未找到当前邮箱']); exit; }

// 节流：检查最近 60s 内是否发送过
$now = time();
$throttleLimit = 60;
$chk = $pdo->prepare('SELECT sent_at FROM verification_codes WHERE email = :email ORDER BY sent_at DESC LIMIT 1');
$chk->execute([':email'=>$currentEmail]);
$last = $chk->fetchColumn();
if ($last && ($now - (int)$last) < $throttleLimit) {
    echo json_encode(['success'=>false,'message'=>'请稍后再试']); exit;
}

// 生成验证码并保存（6位）
$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expire = $now + 600; // 10分钟

$ins = $pdo->prepare('INSERT INTO verification_codes (email, code, sent_at, expire) VALUES (:email, :code, :sent_at, :expire)');
$ins->execute([':email'=>$currentEmail, ':code'=>$code, ':sent_at'=>$now, ':expire'=>$expire]);

// 发送邮件（简单 mail，生产环境请替换为可靠的邮件服务）
$subject = '安全验证码';
$body = "您的验证码为：{$code}，有效期 10 分钟。如非本人操作请忽略。";
$headers = "From: no-reply@{$_SERVER['SERVER_NAME']}\r\nContent-Type: text/plain; charset=utf-8\r\n";

$mailOk = @mail($currentEmail, $subject, $body, $headers);

echo json_encode(['success'=>true,'message'=>'验证码已发送','sent'=> $mailOk ? 1 : 0]);
exit;
