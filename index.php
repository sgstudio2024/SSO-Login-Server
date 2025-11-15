<?php

session_start();

// 如果用户已登录，检查是否有 SSO 请求需要处理，否则跳转到个人中心

if (isset($_SESSION['username']) && isset($_SESSION['user_id'])) {

    // 检查是否有 SSO 请求（来自 sso_authorize.php 的请求保存）

    if (!empty($_SESSION['sso_request']) && is_array($_SESSION['sso_request'])) {

        // 有 SSO 请求，需要处理授权码生成和重定向

        require_once __DIR__ . '/db.php';

        $pdo = get_db_pdo();

        ensure_sso_tables($pdo);

        

        $sreq = $_SESSION['sso_request'];

        $client_id = $sreq['client_id'];

        $redirect_uri = $sreq['redirect_uri'];

        $state = isset($sreq['state']) ? $sreq['state'] : '';



        // 验证客户端和重定向URI

        $stmt = $pdo->prepare('SELECT * FROM sso_clients WHERE client_id = :cid LIMIT 1');

        $stmt->execute([':cid' => $client_id]);

        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($client) {

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



                // 清除 session 中的请求（一次性）

                unset($_SESSION['sso_request']);



                // 构造 redirect URL

                $sep = (strpos($redirect_uri, '?') === false) ? '?' : '&';

                $redirect = $redirect_uri . $sep . 'code=' . rawurlencode($code);

                if ($state !== '') {

                    $redirect .= '&state=' . rawurlencode($state);

                }



                // 重定向到原网站

                header('Location: ' . $redirect);

                exit;

            } else {

                // 验证失败，跳转到 dashboard.php，但可以考虑返回错误信息给客户端

                // 验证失败可能是由于配置错误，不应重试

                header('Location: dashboard.php');

                exit;

            }

        } else {

            // 客户端不存在，跳转到 dashboard.php

            header('Location: dashboard.php');

            exit;

        }

    } else {

        // 没有 SSO 请求，直接跳转到个人中心

        header('Location: dashboard.php');

        exit;

    }

}

?>

<!DOCTYPE html>

<html lang="zh-CN">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>统一用户登录</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            background-image: url('https://files.sgstudio2025.xyz/bj/bj1.webp');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: Arial, sans-serif;
            position: relative;
            cursor: none;
        }
        
        .floating-window {
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            z-index: 10;
            width: 400px;
            text-align: center;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease, transform 0.5s ease, height 0.5s ease;
        }
        
        .logo {
            width: 100%;
            max-width: 200px;
            margin-bottom: 20px;
        }
        
        .login-title, .register-title {
            font-size: 24px;
            margin-bottom: 20px;
            color: #333;
        }
        
        .input-group {
            margin-bottom: 15px;
            text-align: left;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        
        .input-group input {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.8);
            box-sizing: border-box;
            font-size: 16px;
        }
        
        .verification-group {
            /* label 在上，输入和按钮在下一行的容器 */
        }
        
        /* 新增：输入+按钮水平行容器 */
        .verification-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .verification-input {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.8);
            box-sizing: border-box;
            font-size: 16px;
            min-height: 40px;
        }
        
        .verification-button {
            background: linear-gradient(45deg, #8a2be2, #9370db);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            cursor: pointer;
            transition: background 0.3s;
            white-space: nowrap;
            min-width: 90px;
            height: 40px;
            box-sizing: border-box;
        }
        
        .verification-button:hover {
            background: linear-gradient(45deg, #7a1ed2, #8360cb);
        }
        
        .login-button, .register-button {
            background: linear-gradient(45deg, #8a2be2, #9370db);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            transition: background 0.3s;
        }
        
        .login-button:hover, .register-button:hover {
            background: linear-gradient(45deg, #7a1ed2, #8360cb);
        }
        
        .switch-text {
            text-align: left;
            margin-top: 5px;
            background: linear-gradient(45deg, #8a2be2, #9370db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            cursor: pointer;
            text-decoration: underline;
        }
        
        .copyright {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            padding: 10px 15px;
            border-radius: 10px;
            font-size: 14px;
            color: #333;
        }
        
        .show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .hide {
            opacity: 0;
            transform: translateY(20px);
        }
        
        .register-form {
            display: none;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        
        .register-form.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        /* 新增：让登录表单也支持 show 过渡（与注册表单一致） */
        .login-form.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        /* 自定义鼠标指针 */
        .cursor {
            position: fixed;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.7);
            pointer-events: none;
            transform: translate(-50%, -50%);
            z-index: 9999;
            transition: transform 0.15s ease, background-color 0.15s ease, opacity 0.15s ease;
            opacity: 1;
        }
        
        .cursor.hover {
            transform: translate(-50%, -50%) scale(1.5);
            background-color: rgba(255, 255, 255, 1);
        }

        /* 新增：按下时的缩放（优先级由类控制，避免 inline style 冲突） */
        .cursor.active {
            transform: translate(-50%, -50%) scale(0.8);
        }

        /* 如果同时 hover 和 active（鼠标按下时），以 active 为准 */
        .cursor.hover.active {
            transform: translate(-50%, -50%) scale(0.8);
        }

        /* 按钮禁用状态 */
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        /* 消息提示样式 */
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            display: none;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* 新增：用户名检测状态样式 */
        .username-status {
            display: inline-block;
            margin-left: 8px;
            font-size: 13px;
            vertical-align: middle;
        }
        .username-status.available {
            color: #155724;
        }
        .username-status.taken {
            color: #721c24;
        }
    </style>
</head>
<body>
    <!-- 自定义鼠标指针 -->
    <div class="cursor" id="cursor"></div>
    
    <div class="floating-window" id="floatingWindow">
        <!-- 登录表单 -->
        <div class="login-form show" id="loginForm">
            <img src="https://files.sgstudio2025.xyz/sgstudiologo.png" alt="Logo" class="logo">
            <div class="login-title">用户登录</div>
            <div class="message" id="loginMessage"></div>
            <div class="input-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username">
            </div>
            <div class="input-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password">
            </div>
            <div class="switch-text" id="switchToRegister">无账户？去注册</div>
            <button class="login-button" id="loginButton">登录</button>
        </div>
        
        <!-- 注册表单 -->
        <div class="register-form" id="registerForm">
            <img src="https://files.sgstudio2025.xyz/sgstudiologo.png" alt="Logo" class="logo">
            <div class="register-title">用户注册</div>
            <div class="message" id="registerMessage"></div>
            <div class="input-group">
                <label for="reg-username">用户名</label>
                <input type="text" id="reg-username" name="reg-username" autocomplete="off">
                <span id="reg-username-status" class="username-status" aria-live="polite"></span>
            </div>
            <div class="input-group verification-group">
                <label for="reg-email">邮箱</label>
                <div class="verification-row">
                    <input type="email" id="reg-email" name="reg-email" class="verification-input" autocomplete="off" placeholder="example@domain.com">
                    <button type="button" class="verification-button" id="sendCode">发送验证码</button>
                </div>
            </div>
            <div class="input-group">
                <label for="reg-email-code">邮箱验证码</label>
                <input type="text" id="reg-email-code" name="reg-email-code" placeholder="输入收到的验证码">
            </div>
            <div class="input-group">
                <label for="reg-password">密码</label>
                <input type="password" id="reg-password" name="reg-password">
            </div>
            <div class="input-group">
                <label for="reg-confirm-password">确认密码</label>
                <input type="password" id="reg-confirm-password" name="reg-confirm-password">
            </div>
            <div class="switch-text" id="switchToLogin">已有账户？去登录</div>
            <button class="register-button" id="registerButton">注册</button>
        </div>
    </div>
    <div class="copyright">Copyright © 2025 Sg Workstation</div>
    
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                document.getElementById('floatingWindow').classList.add('show');
            }, 1000);
            
            // 切换到注册表单
            document.getElementById('switchToRegister').addEventListener('click', function() {
                const loginForm = document.getElementById('loginForm');
                const registerForm = document.getElementById('registerForm');
                const floatingWindow = document.getElementById('floatingWindow');
                
                // 先隐藏登录表单（淡出）
                loginForm.classList.add('hide');
                
                // 1秒后隐藏登录表单并显示注册表单
                setTimeout(function() {
                    loginForm.style.display = 'none';
                    loginForm.classList.remove('hide');
                    registerForm.style.display = 'block';
                    setTimeout(function() {
                        registerForm.classList.add('show');
                    }, 10);
                }, 1000);
            });
            
            // 切换到登录表单
            document.getElementById('switchToLogin').addEventListener('click', function() {
                const registerForm = document.getElementById('registerForm');
                const loginForm = document.getElementById('loginForm');
                const floatingWindow = document.getElementById('floatingWindow');
                
                // 先隐藏注册表单（淡出）
                registerForm.classList.remove('show');
                
                // 1秒后隐藏注册表单并显示登录表单
                setTimeout(function() {
                    registerForm.style.display = 'none';
                    loginForm.style.display = 'block';
                    setTimeout(function() {
                        loginForm.classList.add('show');
                    }, 10);
                }, 1000);
            });
            
            // 自定义鼠标指针
            const cursor = document.getElementById('cursor');
            
            // 跟随鼠标移动
            document.addEventListener('mousemove', function(e) {
                cursor.style.left = e.clientX + 'px';
                cursor.style.top = e.clientY + 'px';
            });
            
            // 在窗口进入/离开时显示或隐藏自定义光标（避免离开窗口后残留）
            window.addEventListener('mouseenter', function() {
                cursor.style.opacity = '1';
            });
            window.addEventListener('mouseleave', function() {
                cursor.style.opacity = '0';
            });
            
            // 按钮悬停效果
            const buttons = document.querySelectorAll('button');
            buttons.forEach(button => {
                // 鼠标进入按钮
                button.addEventListener('mouseenter', function() {
                    cursor.classList.add('hover');
                });
                
                // 鼠标离开按钮
                button.addEventListener('mouseleave', function() {
                    cursor.classList.remove('hover');
                    // 离开按钮同时移除 active 状态
                    cursor.classList.remove('active');
                });
                
                // 鼠标在按钮上点击
                button.addEventListener('mousedown', function() {
                    cursor.classList.add('active');
                });
                
                // 鼠标在按钮上释放
                button.addEventListener('mouseup', function() {
                    // 释放后去除 active（若仍在 hover 状态则回到 hover 的 scale）
                    cursor.classList.remove('active');
                });
            });
            
            // ********** 新增：统一安全解析 Response 的函数，避免 "body stream already read" 错误 **********
            function parseResponse(response) {
                return response.text().then(function(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        return { success: false, message: text || '服务器返回不可解析的数据' };
                    }
                });
            }
            
            // 登录表单提交
            document.getElementById('loginButton').addEventListener('click', function() {
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;
                const messageDiv = document.getElementById('loginMessage');
                const loginButton = document.getElementById('loginButton');
                
                // 简单验证
                if (!username || !password) {
                    showMessage(messageDiv, '用户名和密码不能为空', 'error');
                    return;
                }
                
                // 禁用按钮并显示加载状态
                loginButton.disabled = true;
                loginButton.textContent = '登录中...';
                
                // 发送登录请求
                const formData = new FormData();
                formData.append('username', username);
                formData.append('password', password);
                
                fetch('login.php', {
                    method: 'POST',
                    body: formData
                })
                .then(parseResponse)
                .then(data => {
                    if (data && data.success) {
                        showMessage(messageDiv, data.message || '登录成功', 'success');
                        // 若存在 SSO redirect，直接跳转（后端在 SSO 登录场景会返回 redirect 字段）
                        if (data.redirect) {
                            // 小延迟让提示可见
                            setTimeout(function() {
                                window.location.href = data.redirect;
                            }, 500);
                            return;
                        }
                         // 非 SSO 登录，跳转到个人中心
                         setTimeout(function() {
                             window.location.href = 'dashboard.php';
                         }, 500);
                     } else {
                         showMessage(messageDiv, data && data.message ? data.message : '登录失败', 'error');
                     }
                 })
                .catch(error => {
                    showMessage(messageDiv, '登录请求失败: ' + (error.message || error), 'error');
                })
                .finally(() => {
                    // 恢复按钮状态
                    loginButton.disabled = false;
                    loginButton.textContent = '登录';
                });
            });
            
            // 新增：用户名重复检测逻辑（防抖）
            (function() {
                const usernameInput = document.getElementById('reg-username');
                const statusEl = document.getElementById('reg-username-status');
                const registerButton = document.getElementById('registerButton');
                let checkTimer = null;
                let checking = false;
                // true = 可用， false = 已被占用， null = 未知/未检查
                let usernameAvailable = null;

                // 新增：用户名合法性校验：仅允许字母或数字，不允许中文或特殊字符
                function isValidUsername(name) {
                    if (!name) return false;
                    // 仅允许 ASCII 字母与数字，至少 2 个字符（长度限制由后续逻辑处理）
                    return /^[A-Za-z0-9]+$/.test(name);
                }

                function setStatusAvailable(msg) {
                    usernameAvailable = true;
                    statusEl.textContent = msg || '可用';
                    statusEl.classList.remove('taken');
                    statusEl.classList.add('available');
                    registerButton.disabled = false;
                }

                function setStatusTaken(msg) {
                    usernameAvailable = false;
                    statusEl.textContent = msg || '已存在';
                    statusEl.classList.remove('available');
                    statusEl.classList.add('taken');
                    registerButton.disabled = true;
                }

                function setStatusChecking() {
                    checking = true;
                    usernameAvailable = null;
                    statusEl.textContent = '检查中...';
                    statusEl.classList.remove('available', 'taken');
                    registerButton.disabled = true;
                }

                function clearStatus() {
                    checking = false;
                    usernameAvailable = null;
                    statusEl.textContent = '';
                    statusEl.classList.remove('available', 'taken');
                    registerButton.disabled = false;
                }

                function checkUsername(name) {
                    // 首先验证字符合法性（仅字母与数字）
                    if (!name || !isValidUsername(name)) {
                        if (name === '') {
                            clearStatus();
                            return;
                        } else {
                            setStatusTaken('仅允许字母或数字，不允许中文或特殊字符');
                            return;
                        }
                    }
                    if (name.length < 2) {
                        clearStatus();
                        return;
                    }
                    setStatusChecking();

                    fetch('check_username.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ username: name })
                    })
                    .then(parseResponse)
                    .then(data => {
                        checking = false;
                        if (data && data.success) {
                            if (data.available) {
                                setStatusAvailable(data.message || '可用');
                            } else {
                                setStatusTaken(data.message || '已存在');
                            }
                        } else {
                            // 出错时保持不可提交状态，并在 status 显示信息
                            setStatusTaken(data && data.message ? data.message : '检查失败');
                        }
                    })
                    .catch(err => {
                        checking = false;
                        setStatusTaken('检查失败');
                    });
                }

                // 防抖监听
                usernameInput.addEventListener('input', function() {
                    const name = usernameInput.value.trim();
                    if (checkTimer) clearTimeout(checkTimer);
                    checkTimer = setTimeout(() => checkUsername(name), 500);
                });

                // 在失焦时也立即检查
                usernameInput.addEventListener('blur', function() {
                    const name = usernameInput.value.trim();
                    if (checkTimer) clearTimeout(checkTimer);
                    checkUsername(name);
                });

                // 确保注册按钮受检测结果控制：在提交前再次验证
                // 在原有注册提交逻辑中增加了检查（见下）
            })();
            
            // 注册表单提交（修改：在提交前验证用户名合法性与可用性）
            document.getElementById('registerButton').addEventListener('click', function() {
                const username = document.getElementById('reg-username').value;
                const password = document.getElementById('reg-password').value;
                const confirmPassword = document.getElementById('reg-confirm-password').value;
                const email = document.getElementById('reg-email').value;
                const verificationCode = document.getElementById('reg-email-code').value;
                const messageDiv = document.getElementById('registerMessage');
                const registerButton = document.getElementById('registerButton');
                
                // 简单验证
                if (!username || !password || !confirmPassword || !email || !verificationCode) {
                    showMessage(messageDiv, '所有字段都不能为空', 'error');
                    return;
                }
                
                if (password !== confirmPassword) {
                    showMessage(messageDiv, '密码和确认密码不匹配', 'error');
                    return;
                }

                // 新增：用户名字符合法性检查（与实时检测一致）
                function isValidUsername(name) { return /^[A-Za-z0-9]+$/.test(name); }
                if (!isValidUsername(username)) {
                    showMessage(messageDiv, '用户名仅允许字母或数字，不允许中文或特殊字符', 'error');
                    return;
                }
                // 若用户名检测元素存在并显示为 taken，则阻止提交
                const statusEl = document.getElementById('reg-username-status');
                if (statusEl && statusEl.classList.contains('taken')) {
                    showMessage(messageDiv, '用户名已存在或包含不合法字符，请更换用户名后再试', 'error');
                    return;
                }
                // 若仍在检查中，提示等待
                if (statusEl && statusEl.textContent === '检查中...') {
                    showMessage(messageDiv, '正在检查用户名，请稍候', 'error');
                    return;
                }
                
                // 禁用按钮并显示加载状态
                registerButton.disabled = true;
                registerButton.textContent = '注册中...';
                
                // 发送注册请求
                const formData = new FormData();
                formData.append('username', username);
                formData.append('password', password);
                formData.append('confirm_password', confirmPassword);
                formData.append('email', email);
                formData.append('verification_code', verificationCode);
                
                fetch('register.php', {
                    method: 'POST',
                    body: formData
                })
                .then(parseResponse)
                .then(data => {
                    if (data && data.success) {
                        showMessage(messageDiv, data.message || '注册成功', 'success');
                        // 清空表单
                        document.getElementById('reg-username').value = '';
                        document.getElementById('reg-password').value = '';
                        document.getElementById('reg-confirm-password').value = '';
                        document.getElementById('reg-email').value = '';
                        document.getElementById('reg-email-code').value = '';
                        // 清除用户名状态
                        const statusEl2 = document.getElementById('reg-username-status');
                        if (statusEl2) { statusEl2.textContent = ''; statusEl2.className = 'username-status'; }
                    } else {
                        showMessage(messageDiv, data && data.message ? data.message : '注册失败', 'error');
                    }
                })
                .catch(error => {
                    showMessage(messageDiv, '注册请求失败: ' + (error.message || error), 'error');
                })
                .finally(() => {
                    // 恢复按钮状态
                    registerButton.disabled = false;
                    registerButton.textContent = '注册';
                });
            });
            
            // 发送验证码逻辑（点击发送邮件，后端 send_verification.php 负责 SMTP 发送）
            (function() {
                const sendBtn = document.getElementById('sendCode');
                const emailInput = document.getElementById('reg-email');
                const registerMsg = document.getElementById('registerMessage');
                let countdown = 0;
                let timer = null;

                function startCountdown(seconds) {
                    countdown = seconds;
                    sendBtn.disabled = true;
                    sendBtn.textContent = `${countdown}s 后重试`;
                    timer = setInterval(() => {
                        countdown--;
                        if (countdown <= 0) {
                            clearInterval(timer);
                            sendBtn.disabled = false;
                            sendBtn.textContent = '发送验证码';
                        } else {
                            sendBtn.textContent = `${countdown}s 后重试`;
                        }
                    }, 1000);
                }

                sendBtn.addEventListener('click', function() {
                    const email = emailInput.value.trim();
                    if (!email) {
                        showMessage(registerMsg, '请输入邮箱地址', 'error');
                        return;
                    }
                    // 简单邮箱格式检查
                    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!re.test(email)) {
                        showMessage(registerMsg, '邮箱格式不正确', 'error');
                        return;
                    }
                    // 禁用按钮避免重复点击
                    sendBtn.disabled = true;
                    sendBtn.textContent = '发送中...';

                    fetch('send_verification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ email })
                    })
                    .then(parseResponse)
                    .then(data => {
                        if (data && data.success) {
                            showMessage(registerMsg, data.message || '验证码已发送', 'success');
                            // 开始倒计时 60 秒
                            startCountdown(60);
                        } else {
                            showMessage(registerMsg, data && data.message ? data.message : '发送失败', 'error');
                            sendBtn.disabled = false;
                            sendBtn.textContent = '发送验证码';
                        }
                    })
                    .catch(err => {
                        showMessage(registerMsg, '发送请求失败', 'error');
                        sendBtn.disabled = false;
                        sendBtn.textContent = '发送验证码';
                    });
                });
            })();
            
            // 显示消息函数
            function showMessage(messageDiv, message, type) {
                messageDiv.textContent = message;
                messageDiv.className = 'message ' + type;
                messageDiv.style.display = 'block';
                
                // 3秒后自动隐藏消息
                setTimeout(function() {
                    messageDiv.style.display = 'none';
                }, 3000);
            }
        });
    </script>
</body>
</html>