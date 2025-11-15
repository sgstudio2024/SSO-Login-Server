<?php
header('Content-Type: application/json; charset=utf-8');

session_start(); // 新增：支持使用会话中的用户邮箱作为发送目标

require_once __DIR__ . '/db.php';

// SMTP 
$smtpHost = '***************';
$smtpPort = 465;
$smtpUser = '***************';
$smtpPass = '******************'; // 授权码
$fromEmail = '*****************';
$fromName = 'sg workstation';

// 站点标题
$siteTitle = '统一用户登录';

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '仅支持 POST']);
    exit;
}

// 优先使用 POST 传入的 email；若未传则尝试使用当前登录用户的邮箱
$emailRaw = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
if ($emailRaw === '') {
    // 没有提供 email，尝试用 session 的 user_id 读取用户邮箱
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '未提供邮箱且未登录']);
        exit;
    }
    $user_id = (int)$_SESSION['user_id'];
    try {
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        $emailFromDb = $stmt->fetchColumn();
        if (!$emailFromDb) {
            echo json_encode(['success' => false, 'message' => '未找到绑定邮箱']);
            exit;
        }
        $emailRaw = $emailFromDb;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '服务器错误: 无法读取用户邮箱']);
        exit;
    }
}

if ($emailRaw === '' || !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => '邮箱无效']);
    exit;
}

$email = mb_strtolower($emailRaw, 'UTF-8');
$pdo = get_db_pdo();
ensure_verification_table($pdo);

// 频率限制：查找最近一次发送时间
try {
    $stmt = $pdo->prepare('SELECT sent_at FROM verification_codes WHERE email = :email ORDER BY sent_at DESC LIMIT 1');
    $stmt->execute([':email' => $email]);
    $last = $stmt->fetchColumn();
    $now = time();
    if ($last !== false && ($now - (int)$last) < 60) {
        echo json_encode(['success' => false, 'message' => '请稍候再发送']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()]);
    exit;
}

// 生成验证码并写入数据库
$code = str_pad(strval(rand(0, 999999)), 6, '0', STR_PAD_LEFT);
$sent_at = time();
$expire = $sent_at + 10 * 60; // 10 分钟有效

try {
    $insert = $pdo->prepare('INSERT INTO verification_codes (email, code, sent_at, expire) VALUES (:email, :code, :sent_at, :expire)');
    $insert->execute([':email' => $email, ':code' => $code, ':sent_at' => $sent_at, ':expire' => $expire]);
    $insertedId = $pdo->lastInsertId();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '无法写入验证码: ' . $e->getMessage()]);
    exit;
}

// 使用 PHPMailer 发送 HTML 邮件，主题与网站标题一致
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
$logPath = __DIR__ . '/mail_error.log';
try {
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    if ($smtpPort == 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->SMTPAutoTLS = true;
    }
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    $mail->CharSet    = 'UTF-8';
    $mail->SMTPDebug  = 0;

    // 发件人与收件人
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($emailRaw);

    // HTML 内容与主题（主题与网站标题一致）
    $mail->isHTML(true);
    $mail->Subject = $siteTitle;
    $htmlBody = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$siteTitle}</title>
</head>
<body style="margin:0;padding:20px;background-color:#f2f4f8;font-family:Arial,Helvetica,sans-serif;">
  <center>
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;margin:0 auto;">
      <tr>
        <td style="padding:20px 0;text-align:center;">
          <img src="https://files.sgstudio2025.xyz/sgstudiologo.png" alt="{$siteTitle}" width="140" style="display:block;margin:0 auto 10px auto;">
        </td>
      </tr>

      <tr>
        <td style="background:#ffffff;border-radius:12px;padding:28px;box-shadow:0 6px 20px rgba(0,0,0,0.06);">
          <h1 style="margin:0 0 12px 0;font-size:20px;color:#111;">{$siteTitle}</h1>
          <p style="margin:0 0 18px 0;color:#555;line-height:1.5;font-size:14px;">
            您好，感谢使用我们的服务。以下为本次操作的验证码：
          </p>

          <div style="text-align:center;margin:18px 0;">
            <div style="display:inline-block;background:linear-gradient(135deg,#7b2be2,#9b7af0);border-radius:12px;padding:18px 26px;color:#fff;">
              <div style="font-size:28px;font-weight:700;letter-spacing:4px;">{$code}</div>
              <div style="font-size:12px;opacity:0.9;margin-top:6px;">有效期 10 分钟</div>
            </div>
          </div>

          <p style="margin:18px 0 0 0;color:#666;font-size:13px;line-height:1.5;">
            如果您没有发起此操作，请忽略此邮件。为保证账户安全，请勿将验证码泄露给他人
          </p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;">
            <tr>
              <td style="text-align:left;font-size:12px;color:#999;">
                此邮件由SG-Workstation自动发送
              </td>
              <td style="text-align:right;font-size:12px;color:#999;">
                &copy; {$siteTitle}
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <tr>
        <td style="padding:14px 0;text-align:center;font-size:12px;color:#999;">
          若邮件显示异常，请在浏览器中打开或联系管理员
        </td>
      </tr>
    </table>
  </center>
</body>
</html>
HTML;

    $mail->setLanguage('zh');
    $mail->Encoding = 'base64';
    $mail->msgHTML($htmlBody);
    $mail->AltBody = "您的注册码为：{$code} 。此验证码有效期 10 分钟。如非本人操作请忽略。";
    $mail->ContentType = 'text/html; charset=UTF-8';

    $mail->send();
    echo json_encode(['success' => true, 'message' => '验证码发送成功', 'sent' => 1]);
    exit;
} catch (Exception $e) {
    // 记录错误并尝试回退 mail()
    $err = $mail->ErrorInfo ?: $e->getMessage();
    @file_put_contents($logPath, date('c') . " send error: {$err}\n", FILE_APPEND);

    // 回退：尝试使用 PHP mail() 发送（简陋回退）
    $headers = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $subject = $siteTitle;
    $bodyHtml = $htmlBody;
    $mailOk = false;
    try {
        $mailOk = @mail($emailRaw, $subject, $bodyHtml, $headers);
    } catch (Throwable $ex) {
        @file_put_contents($logPath, date('c') . " mail() fallback exception: " . $ex->getMessage() . "\n", FILE_APPEND);
        $mailOk = false;
    }

    if ($mailOk) {
        echo json_encode(['success' => true, 'message' => '验证码发送成功（通过 mail() 回退）', 'sent' => 1]);
        exit;
    } else {
        // 回退也失败，删除刚刚插入的验证码记录并返回错误
        try {
            if (isset($insertedId) && $insertedId) {
                $del = $pdo->prepare('DELETE FROM verification_codes WHERE id = :id');
                $del->execute([':id' => $insertedId]);
            }
        } catch (Exception $ex) {
            @file_put_contents($logPath, date('c') . " DB cleanup error: " . $ex->getMessage() . "\n", FILE_APPEND);
        }

        echo json_encode(['success' => false, 'message' => '发送失败: ' . $err . '. 详情见服务器日志。']);
        exit;
    }
}
