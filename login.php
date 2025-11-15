<?php
// 在 session_start 之前设置 cookie domain/SameSite（与 sso_authorize 保持一致）
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

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

// 在此处新增：GET 登录配置（默认禁用免密 key，但允许通过 GET 提交 username+password）
// 若需要允许无需密码的 GET 自动登录，请设置为一个随机长密钥（例如在生产环境中放在安全位置并修改此值）
// 例如： $GET_LOGIN_KEY = 'change_this_to_a_secret_value';
$GET_LOGIN_KEY = 'iaushdfikujashdfikuhsdfikuhsd'; // 留空表示禁用基于 key 的免密 GET 登录
$ALLOW_GET_PASSWORD_LOGIN = true; // 是否允许通过 GET 传递 password 进行登录（请确保使用 HTTPS）

// 替换原来的请求方法检查与参数读取：允许 POST 或 GET（受 $GET_LOGIN_KEY 控制免密登录）
// if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//     echo json_encode(['success'=>false,'message'=>'仅支持 POST']);
//     exit;
// }
//
// $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
// $password = isset($_POST['password']) ? $_POST['password'] : '';
// 支持 POST 或 GET
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $via = 'POST';
} else {
    // GET 请求：支持两种方式
    // 1) ?username=xxx&password=yyy  （如果 $ALLOW_GET_PASSWORD_LOGIN 为 true，则允许）
    // 2) ?username=xxx&key=SECRET    （免密登录，仅当 $GET_LOGIN_KEY 非空且匹配时允许）
    $username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
    $password = isset($_GET['password']) ? $_GET['password'] : null;
    $key = isset($_GET['key']) ? (string)$_GET['key'] : '';
    $via = 'GET';

    // 如果提供了 password，只要配置允许则接受（注意安全）
    if ($password !== null && $password !== '') {
        if (!$ALLOW_GET_PASSWORD_LOGIN) {
            echo json_encode(['success'=>false,'message'=>'GET 密码登录被服务器禁用']);
            exit;
        }
        // 使用 GET 提供的密码进行常规模拟登录（下方会校验）
    } else {
        // 未提供密码时考虑免密 key 模式
        if ($username !== '') {
            if ($GET_LOGIN_KEY === '' || !hash_equals((string)$GET_LOGIN_KEY, (string)$key)) {
                echo json_encode(['success'=>false,'message'=>'GET 登录未启用或 key 无效']);
                exit;
            }
            // 标记为免密登录
            $password = null; // 明确无密码
        }
    }
}

if ($username === '') {
    echo json_encode(['success'=>false,'message'=>'用户名不能为空']);
    exit;
}
// 如果是标准登录请求（有密码），仍然要求密码非空
if ($password !== null && $password === '') {
    echo json_encode(['success'=>false,'message'=>'密码不能为空']);
    exit;
}

$pdo = get_db_pdo();
try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success'=>false,'message'=>'用户名或密码错误']);
        exit;
    }
    // 免密 GET 登录（通过 key 验证）允许直接登录，不再校验密码
    if ($via === 'GET' && $password === null && $GET_LOGIN_KEY !== '') {
        // 免密登录，已由上游 key 校验保障；继续
    } else {
        // 标准登录：需要验证密码
        if (!isset($user['password']) || !password_verify($password, $user['password'])) {
            echo json_encode(['success'=>false,'message'=>'用户名或密码错误']);
            exit;
        }
    }

    // 登录成功：建立 session
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_id'] = (int)$user['id'];

    // 如果存在 SSO 请求，则生成一次性 code 并返回 redirect 字段
    if (!empty($_SESSION['sso_request']) && is_array($_SESSION['sso_request'])) {
        $sreq = $_SESSION['sso_request'];
        $client_id = $sreq['client_id'];
        $redirect_uri = $sreq['redirect_uri'];
        $state = isset($sreq['state']) ? $sreq['state'] : '';

        // 生成 code 并写入 sso_codes
        ensure_sso_tables($pdo);
        $code = bin2hex(random_bytes(16));
        $expire = time() + 300; // 5 分钟有效
        $ins = $pdo->prepare('INSERT INTO sso_codes (code, user_id, client_id, expire, used) VALUES (:code, :uid, :client, :expire, 0)');
        $ins->execute([
            ':code' => $code,
            ':uid' => (int)$user['id'],
            ':client' => $client_id,
            ':expire' => $expire
        ]);

        // 清除 session 中的请求（一次性）
        unset($_SESSION['sso_request']);

        // 构造 redirect URL
        $sep = (strpos($redirect_uri, '?') === false) ? '?' : '&';
        $redirect = $redirect_uri . $sep . 'code=' . rawurlencode($code);
        if ($state !== '') {
            $redirect .= '&state=' . rawurlencode($state);
        }

        echo json_encode(['success'=>true,'message'=>'登录成功','redirect'=>$redirect]);
        exit;
    }

    // 非 SSO 登录：正常返回
    if (isset($via) && $via === 'GET') {
        // GET 登录额外回传用户名与邮箱
        echo json_encode([
            'success' => true,
            'message' => '登录成功',
            'username' => $user['username'],
            'email' => isset($user['email']) ? $user['email'] : ''
        ]);
    } else {
        echo json_encode(['success'=>true,'message'=>'登录成功']);
    }
    exit;
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'登录失败: '.$e->getMessage()]);
    exit;
}
?>