<?php
// 简单的管理脚本：用于向 sso_clients 注册客户端（仅用于部署时一次性使用）
// 说明：使用后请立即删除或限制访问。
require_once __DIR__ . '/db.php';

$pdo = get_db_pdo();
ensure_sso_tables($pdo);

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : 'create';
    if ($action === 'create') {
        $client_id = trim((string)($_POST['client_id'] ?? ''));
        $client_secret = trim((string)($_POST['client_secret'] ?? ''));
        $redirect_uri = trim((string)($_POST['redirect_uri'] ?? ''));
        $allow_sub = isset($_POST['allow_subdomains']) ? 1 : 0;
        $name = trim((string)($_POST['name'] ?? ''));

        if ($client_id === '' || $client_secret === '' || $redirect_uri === '') {
            $errors[] = 'client_id / client_secret / redirect_uri 都为必填';
        } else {
            // 检查是否已存在
            $stmt = $pdo->prepare('SELECT id FROM sso_clients WHERE client_id = :cid LIMIT 1');
            $stmt->execute([':cid' => $client_id]);
            if ($stmt->fetch()) {
                $errors[] = "client_id 已存在：{$client_id}";
            } else {
                $hash = password_hash($client_secret, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO sso_clients (client_id, client_secret, redirect_uri, allow_subdomains, name) VALUES (:cid, :csec, :ru, :allow, :name)');
                $ins->execute([
                    ':cid' => $client_id,
                    ':csec' => $hash,
                    ':ru' => $redirect_uri,
                    ':allow' => $allow_sub,
                    ':name' => $name
                ]);
                $success = "创建成功：{$client_id}";
            }
        }
    } elseif ($action === 'create_wildcard') {
        // 批量示例：为所有子域注册
        $client_id = trim((string)($_POST['wc_client_id'] ?? 'all_subdomains_client'));
        $client_secret = trim((string)($_POST['wc_client_secret'] ?? bin2hex(random_bytes(8))));
        $base = trim((string)($_POST['base_domain'] ?? 'sgstudio2025.xyz'));
        $redirect_path = trim((string)($_POST['redirect_path'] ?? '/callback'));
        $name = 'Wildcard Client';

        if ($client_id === '' || $client_secret === '' || $base === '') {
            $errors[] = '批量注册需要填写 client_id / client_secret / base_domain';
        } else {
            $redirect_uri = "https://*.{$base}{$redirect_path}";
            $stmt = $pdo->prepare('SELECT id FROM sso_clients WHERE client_id = :cid LIMIT 1');
            $stmt->execute([':cid' => $client_id]);
            if ($stmt->fetch()) {
                $errors[] = "client_id 已存在：{$client_id}";
            } else {
                $hash = password_hash($client_secret, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO sso_clients (client_id, client_secret, redirect_uri, allow_subdomains, name) VALUES (:cid, :csec, :ru, :allow, :name)');
                $ins->execute([
                    ':cid' => $client_id,
                    ':csec' => $hash,
                    ':ru' => $redirect_uri,
                    ':allow' => 1,
                    ':name' => $name
                ]);
                $success = "通配符客户端创建成功：{$client_id}，回调模式：{$redirect_uri}，密钥（明文，请保存）：{$client_secret}";
            }
        }
    }
}

// 输出简单表单界面
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>创建 SSO 客户端 - 辅助脚本</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f6f8fb;padding:24px;}
.container{max-width:820px;margin:0 auto;background:#fff;padding:18px;border-radius:8px;box-shadow:0 8px 20px rgba(0,0,0,0.06);}
h2{margin:0 0 12px 0}
label{display:block;margin:8px 0 4px;font-weight:600}
input[type=text], input[type=password]{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px}
.row{display:flex;gap:12px}
.col{flex:1}
.btn{display:inline-block;padding:8px 12px;background:#5e3bd8;color:#fff;border-radius:6px;text-decoration:none;border:none}
.notice{padding:8px;background:#eef2ff;border:1px solid #e0d9ff;border-radius:6px;color:#333}
.err{padding:8px;background:#fff0f0;border:1px solid #ffc9c9;border-radius:6px;color:#900}
.success{padding:8px;background:#f0fff4;border:1px solid #c9f2d0;border-radius:6px;color:#084}
small{color:#666}
</style>
<div class="container">
  <h2>SSO 客户端创建（辅助脚本）</h2>

  <?php if ($errors): ?>
    <div class="err"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="success"><?php echo nl2br(htmlspecialchars($success)); ?></div>
  <?php endif; ?>

  <h3>创建单个客户端</h3>
  <form method="post">
    <input type="hidden" name="action" value="create">
    <label>client_id</label>
    <input type="text" name="client_id" placeholder="例如 bbs_client" required>
    <label>client_secret（请保存）</label>
    <input type="password" name="client_secret" placeholder="例如 aVerySecretString" required>
    <label>redirect_uri（可使用完整 URL 或带通配符模式，如 https://*.sgstudio2025.xyz/callback ）</label>
    <input type="text" name="redirect_uri" placeholder="例如 https://bbs.sgstudio2025.xyz/client_demo/callback.php" required>
    <label><input type="checkbox" name="allow_subdomains" value="1"> 允许子域 (allow_subdomains)</label>
    <label>客户端名称（可选）</label>
    <input type="text" name="name" placeholder="例如 BBS 客户端">
    <div style="margin-top:12px">
      <button class="btn" type="submit">创建客户端</button>
      <span style="margin-left:12px"><small>创建后请保存 client_id 与 client_secret 并删除此脚本。</small></span>
    </div>
  </form>

  <hr>

  <h3>批量创建：为所有子域注册通配符客户端（示例）</h3>
  <form method="post">
    <input type="hidden" name="action" value="create_wildcard">
    <label>client_id（示例：all_subdomains_client）</label>
    <input type="text" name="wc_client_id" placeholder="all_subdomains_client">
    <label>client_secret（会明文显示一次，请保存）</label>
    <input type="text" name="wc_client_secret" placeholder="可留空自动生成">
    <label>base_domain（例如 sgstudio2025.xyz）</label>
    <input type="text" name="base_domain" value="sgstudio2025.xyz" required>
    <label>redirect_path（回调路径前缀，默认 /callback）</label>
    <input type="text" name="redirect_path" value="/callback">
    <div style="margin-top:12px">
      <button class="btn" type="submit">创建通配符客户端</button>
      <span style="margin-left:12px"><small>创建后回调将保存为 https://*.base_domain/redirect_path</small></span>
    </div>
  </form>

  <hr>
  <p class="notice"><strong>安全提醒：</strong>此脚本会输出明文 client_secret。创建完毕请立即删除文件或限制访问。</p>
</div>
</body>
</html>
