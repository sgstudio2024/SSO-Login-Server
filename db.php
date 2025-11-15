<?php
// 简单的 PDO 连接工厂，请根据你的环境修改下面配置
$DB_HOST = '127.0.0.1';
$DB_NAME = 'userdb';
$DB_USER = 'userdb';
$DB_PASS = 'YEHEr6byyMj3PakH';
$DB_CHAR = 'utf8mb4';

function get_db_pdo() {
	global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHAR;
	try {
		$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHAR}";
		$pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		return $pdo;
	} catch (Exception $e) {
		// 连接失败，返回 JSON 并终止（便于前端接收错误）
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['success' => false, 'message' => '数据库连接失败: ' . $e->getMessage()]);
		exit;
	}
}

// 新增：确保验证码表存在（可安全多次调用）
function ensure_verification_table(PDO $pdo) {
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `verification_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `sent_at` INT UNSIGNED NOT NULL,
  `expire` INT UNSIGNED NOT NULL,
  INDEX (`email`),
  INDEX (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($sql);
}

// 修改：ensure_sso_tables 增加 allow_subdomains 字段并示例插入支持子域的客户端
function ensure_sso_tables(PDO $pdo) {
    $sql1 = <<<SQL
CREATE TABLE IF NOT EXISTS `sso_clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `client_id` VARCHAR(128) NOT NULL UNIQUE,
  `client_secret` VARCHAR(256) NOT NULL,
  `redirect_uri` TEXT NOT NULL,
  `allow_subdomains` TINYINT(1) NOT NULL DEFAULT 0,
  `name` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $sql2 = <<<SQL
CREATE TABLE IF NOT EXISTS `sso_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(128) NOT NULL UNIQUE,
  `user_id` INT UNSIGNED NOT NULL,
  `client_id` VARCHAR(128) NOT NULL,
  `expire` INT UNSIGNED NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX (`code`),
  INDEX (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $sql3 = <<<SQL
CREATE TABLE IF NOT EXISTS `sso_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `access_token` VARCHAR(128) NOT NULL UNIQUE,
  `user_id` INT UNSIGNED NOT NULL,
  `client_id` VARCHAR(128) NOT NULL,
  `expire` INT UNSIGNED NOT NULL,
  INDEX (`access_token`),
  INDEX (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
    $pdo->exec($sql1);
    $pdo->exec($sql2);
    $pdo->exec($sql3);

    // 可选：插入一个示例 client（只在表为空时插入，便于测试）
    $stmt = $pdo->query("SELECT COUNT(*) FROM sso_clients");
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) {
        $ins = $pdo->prepare("INSERT INTO sso_clients (client_id, client_secret, redirect_uri, allow_subdomains, name) VALUES (:cid, :csec, :ru, :allow, :name)");
        $ins->execute([
            ':cid' => 'demo_client',
            ':csec' => password_hash('demo_secret', PASSWORD_DEFAULT),
            ':ru' => 'https://login.sgstudio2025.xyz/callback',
            ':allow' => 0,
            ':name' => 'Demo Client'
        ]);

        // 示例：注册一个允许子域的客户端（allow_subdomains = 1）
        $ins2 = $pdo->prepare("INSERT INTO sso_clients (client_id, client_secret, redirect_uri, allow_subdomains, name) VALUES (:cid, :csec, :ru, :allow, :name)");
        $ins2->execute([
            ':cid' => 'all_subdomains_client',
            ':csec' => password_hash('all_subdomains_secret', PASSWORD_DEFAULT),
            ':ru' => 'https://*.sgstudio2025.xyz/callback', // 示例通配符保存
            ':allow' => 1,
            ':name' => 'All Subdomains Client'
        ]);
    }
}

// 提供全局 $pdo 和 $conn 供旧脚本使用（优先 PDO，失败时回退到 mysqli）
$pdo = null;
$conn = null;

try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHAR}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    // 静默失败，避免在生产环境输出错误信息
    $pdo = null;
}

// 如果 PDO 不可用，尝试使用 mysqli 回退
if (!$pdo) {
    $mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($mysqli && !$mysqli->connect_errno) {
        $conn = $mysqli;
    } else {
        $conn = null;
    }
}

// 现在全局作用域中有 $pdo 或 $conn（或都为 null），其他脚本可直接使用

/*
 建表示例（已通过 ensure_verification_table 创建）：

 CREATE TABLE `verification_codes` (
   `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
   `email` VARCHAR(255) NOT NULL,
   `code` VARCHAR(20) NOT NULL,
   `sent_at` INT UNSIGNED NOT NULL,
   `expire` INT UNSIGNED NOT NULL,
   INDEX (`email`),
   INDEX (`sent_at`)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/
