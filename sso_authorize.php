<?php
// 在创建/恢复 session 前设置 cookie 域和 SameSite（可选，用于跨子域 session）
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_domain', '.sgstudio2025.xyz');
// PHP 7.3+ 支持同站点参数数组
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

// 用户已登录
if (isset($_SESSION['username']) && isset($_SESSION['user_id'])) {
    // 获取当前 SSO 
    $client_id = isset($_GET['client_id']) ? trim((string)$_GET['client_id']) : '';
    $redirect_uri = isset($_GET['redirect_uri']) ? trim((string)$_GET['redirect_uri']) : '';
    $state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
    $response_type = isset($_GET['response_type']) ? trim((string)$_GET['response_type']) : 'code';

    if ($client_id === '' || $redirect_uri === '') {
        echo json_encode(['success'=>false,'message'=>'缺少 client_id 或 redirect_uri']);
        exit;
    }
    if ($response_type !== 'code') {
        echo json_encode(['success'=>false,'message'=>'仅支持 response_type=code']);
        exit;
    }

    require_once __DIR__ . '/db.php';
    $pdo = get_db_pdo();
    ensure_sso_tables($pdo);

    // 验证客户端和重定向URI
    $stmt = $pdo->prepare('SELECT * FROM sso_clients WHERE client_id = :cid LIMIT 1');
    $stmt->execute([':cid' => $client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        echo json_encode(['success'=>false,'message'=>'未知客户端']);
        exit;
    }

    // 验证 redirect_uri 是否匹配
    $client_redirect = trim($client['redirect_uri']);
    $allowSub = (isset($client['allow_subdomains']) && (int)$client['allow_subdomains'] === 1);
    $matched = false;
    
    if ($client_redirect === $redirect_uri) {
        $matched = true;
    } else {
        // 支持通配符模式
        if (strpos($client_redirect, '*') !== false) {
            $pattern = '#^' . str_replace(['\*','/'], ['[^/]+','\/'], preg_quote($client_redirect, '#')) . '$#i';
            if (preg_match($pattern, $redirect_uri)) {
                $matched = true;
            }
        }
        // 若 allow_subdomains 开启，只要回调 host 属于顶级域即认为匹配
        if (!$matched && $allowSub) {
            $parsedReq = parse_url($redirect_uri);
            $req_host = isset($parsedReq['host']) ? $parsedReq['host'] : '';
            $base = 'sgstudio2025.xyz';
            if ($req_host !== '' && substr($req_host, -strlen($base)) === $base) {
                $matched = true;
            }
        }
    }

    if ($matched) {
        // 生成 code 并写入 sso_codes
        $code = bin2hex(random_bytes(16));
        $expire = time() + 300; // 5 分钟有效
        $ins = $pdo->prepare('INSERT INTO sso_codes (code, user_id, client_id, expire, used) VALUES (:code, :uid, :client, :expire, 0)');
        $ins->execute([
            ':code' => $code,
            ':uid' => (int)$_SESSION['user_id'],
            ':client' => $client_id,
            ':expire' => $expire
        ]);

        // 构造 redirect URL
        $sep = (strpos($redirect_uri, '?') === false) ? '?' : '&';
        $redirect = $redirect_uri . $sep . 'code=' . rawurlencode($code);
        if ($state !== '') {
            $redirect .= '&state=' . rawurlencode($state);
        }

        header('Location: ' . $redirect);
        exit;
    } else {
        echo json_encode(['success'=>false,'message'=>'redirect_uri 不匹配']);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
$pdo = get_db_pdo();
ensure_sso_tables($pdo);

// 参数检查
$client_id = isset($_GET['client_id']) ? trim((string)$_GET['client_id']) : '';
$redirect_uri = isset($_GET['redirect_uri']) ? trim((string)$_GET['redirect_uri']) : '';
$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$response_type = isset($_GET['response_type']) ? trim((string)$_GET['response_type']) : 'code';

if ($client_id === '' || $redirect_uri === '') {
    echo json_encode(['success'=>false,'message'=>'缺少 client_id 或 redirect_uri']);
    exit;
}
if ($response_type !== 'code') {
    echo json_encode(['success'=>false,'message'=>'仅支持 response_type=code']);
    exit;
}

// 验证 client 和 redirect_uri 是否匹配（支持通配符和 allow_subdomains）
$stmt = $pdo->prepare('SELECT * FROM sso_clients WHERE client_id = :cid LIMIT 1');
$stmt->execute([':cid' => $client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    // 原先返回 JSON 错误，这里保持行为（客户端请求仍可接收 JSON）
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        echo json_encode(['success'=>false,'message'=>'未知客户端']);
        exit;
    }
    // 浏览器访问时显示友好页面
    http_response_code(400);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>未知客户端</title></head><body>";
    echo "<h2>未知客户端 (client_id)</h2>";
    echo "<p>未找到 client_id: <strong>" . htmlspecialchars($client_id) . "</strong></p>";
    echo "<p>请确认客户端已在服务端注册。可使用 <code>create_clients_helper.php</code> 创建。</p>";
    echo "</body></html>";
    exit;
}

$client_redirect = trim($client['redirect_uri']);
$allowSub = (isset($client['allow_subdomains']) && (int)$client['allow_subdomains'] === 1);

$matched = false;
// 精确匹配
if ($client_redirect === $redirect_uri) {
    $matched = true;
} else {
    // 支持数据库中保存的通配符模式
    if (strpos($client_redirect, '*') !== false) {
        $pattern = '#^' . str_replace(['\*','/'], ['[^/]+','\/'], preg_quote($client_redirect, '#')) . '$#i';
        if (preg_match($pattern, $redirect_uri)) {
            $matched = true;
        }
    }
    // 
    if (!$matched && $allowSub) {
        $parsedReq = parse_url($redirect_uri);
        $req_host = isset($parsedReq['host']) ? $parsedReq['host'] : '';
        $base = 'sgstudio2025.xyz';
        if ($req_host !== '' && substr($req_host, -strlen($base)) === $base) {
            $matched = true;
        }
    }
}

if (!$matched) {
    // 如果是 AJAX/客户端调用，返回 JSON 以便程序处理
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        echo json_encode(['success'=>false,'message'=>'redirect_uri 不匹配，请联系管理员', 'client_registered_redirect' => $client_redirect, 'allow_subdomains' => (int)$client['allow_subdomains']]);
        exit;
    }

    // 浏览器交互时，展示诊断页面，方便定位问题与修复
    http_response_code(400);
    $provided = htmlspecialchars($redirect_uri);
    $registered = htmlspecialchars($client_redirect);
    $allow = (isset($client['allow_subdomains']) && (int)$client['allow_subdomains'] === 1) ? '是' : '否';
    echo "错误登录errsso：Aa0001，这通常都是服务器问题，请联系管理员";
    exit;
}

// 保存请求到 session，跳转到登录页面
$_SESSION['sso_request'] = [
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'state' => $state
];

$loginPage = '/index.php?from_sso=1';
header('Location: ' . $loginPage);
exit;