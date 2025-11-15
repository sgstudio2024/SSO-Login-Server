<?php
// 在 session_start 之前设置 cookie domain/SameSite（与系统保持一致）
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_domain', '.sgstudio2025.xyz');
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '.sgstudio2025.xyz',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
}
session_start();

// 清除所有session数据
$_SESSION = array();

// 删除session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 销毁session
session_destroy();

// 返回成功响应
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => '退出登录成功']);
?>