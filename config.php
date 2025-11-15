<?php
// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', '*****************');
define('DB_USER', '*****************');
define('DB_PASS', '**************');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}
?>