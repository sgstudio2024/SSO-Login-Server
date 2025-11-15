<?php
// 增加：生产环境下禁止显示错误与调试信息（可选）
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);

session_start();
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/db.php'; // 确保 db.php 会提供 PDO($pdo) 或 mysqli($conn)

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];

// 读取当前 avatar（如果有）
$avatar = null;
$is_admin = 0;
if (isset($pdo) && $pdo instanceof PDO) {
    $stmt = $pdo->prepare('SELECT avatar, IFNULL(is_admin,0) as is_admin FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $avatar = $row['avatar'] ?? null;
        $is_admin = (int)($row['is_admin'] ?? 0);
    }
} elseif (isset($conn) && $conn instanceof mysqli) {
    if ($stmt = $conn->prepare('SELECT avatar, IFNULL(is_admin,0) as is_admin FROM users WHERE id = ? LIMIT 1')) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($avatar, $is_admin);
        $stmt->fetch();
        $stmt->close();
        $is_admin = (int)$is_admin;
    }
}

// 新增：读取所有工作室与当前用户的归属状态
$studios = [];
$memberMap = []; // studio_id => true/false
$currentStudioId = 0; // 新增：记录用户当前所属的工作室（0 表示未加入）
try {
	// 使用 PDO 或 mysqli 查询 studios 与 membership
	if (isset($pdo) && $pdo instanceof PDO) {
		$q = $pdo->query('SELECT id, name, url, avatar, description, visible FROM studios ORDER BY id DESC');
		$studios = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
		$stm = $pdo->prepare('SELECT studio_id FROM studio_members WHERE user_id = :uid LIMIT 1');
		$stm->execute([':uid' => $user_id]);
		$first = $stm->fetch(PDO::FETCH_ASSOC);
		if ($first && isset($first['studio_id'])) {
			$currentStudioId = (int)$first['studio_id'];
			$memberMap[$currentStudioId] = true;
		}
		// 若希望列出所有可能的重复（兼容旧数据），可以再查询所有：
		$stmAll = $pdo->prepare('SELECT studio_id FROM studio_members WHERE user_id = :uid');
		$stmAll->execute([':uid' => $user_id]);
		while ($r = $stmAll->fetch(PDO::FETCH_ASSOC)) {
			$memberMap[(int)$r['studio_id']] = true;
		}
	} else {
		global $conn;
		if ($conn) {
			$res = $conn->query('SELECT id, name, url, avatar, description, visible FROM studios ORDER BY id DESC');
			if ($res) {
				while ($r = $res->fetch_assoc()) $studios[] = $r;
				$res->free();
			}
			$res1 = $conn->query('SELECT studio_id FROM studio_members WHERE user_id = ' . (int)$user_id . ' LIMIT 1');
			if ($res1 && ($row = $res1->fetch_assoc())) {
				$currentStudioId = (int)$row['studio_id'];
				$memberMap[$currentStudioId] = true;
				$res1->free();
			}
			$res2 = $conn->query('SELECT studio_id FROM studio_members WHERE user_id = ' . (int)$user_id);
			if ($res2) {
				while ($r = $res2->fetch_assoc()) $memberMap[(int)$r['studio_id']] = true;
				$res2->free();
			}
		}
	}
} catch (Throwable $e) {
	// 忽略错误，前端显示为空列表
	$studios = [];
	$memberMap = [];
	$currentStudioId = 0;
}

// 在读取 $studios、$memberMap 和 $currentStudioId 之后，增加：定位当前工作室信息
$currentStudio = null;
if (!empty($currentStudioId) && !empty($studios)) {
	foreach ($studios as $s) {
		if ((int)$s['id'] === (int)$currentStudioId) {
			$currentStudio = $s;
			break;
		}
	}
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人中心 - 统一用户登录</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-image: url('https://files.sgstudio2025.xyz/bj/bj1.webp');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            flex-direction: column;
            align-items: center;
            font-family: Arial, sans-serif;
            position: relative;
            cursor: none;
        }
        
        .header {
            width: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        
        .logo {
            height: 40px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .username {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            background: linear-gradient(135deg,#8a2be2,#9370db);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 14px;
            cursor: pointer;
            box-shadow: 0 6px 18px rgba(147,112,219,0.18);
            transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(138,43,226,0.18); }
        .btn:active { transform: translateY(0); }
        .btn[disabled] { opacity: .7; cursor: not-allowed; transform: none; box-shadow: none; }
        
        .logout-btn {
            /* 复用统一样式 */
            /* 通过更高优先级添加基础样式 */
            /* 仅在这里引用 .btn 的视觉风格 */
            background: linear-gradient(135deg,#8a2be2,#9370db);
            color:#fff;
            border-radius:10px;
            padding:8px 14px;
            border: none;
            box-shadow: 0 6px 18px rgba(147,112,219,0.12);
        }
        
        .logout-btn:hover {
            background: linear-gradient(45deg, #7a1ed2, #8360cb);
        }
        
        .main-content {
            margin-top: 80px;
            width: 100%;
            max-width: 1200px;
            padding: 20px;
            display: flex;
            gap: 20px;
        }
        
        .sidebar {
            width: 250px;
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            height: fit-content;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 10px;
        }
        
        .sidebar-menu a {
            display: block;
            padding: 12px 15px;
            text-decoration: none;
            color: #333;
            border-radius: 8px;
            transition: background 0.3s;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: linear-gradient(45deg, #8a2be2, #9370db);
            color: white;
        }
        
        .content-area {
            flex: 1;
            background-color: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            font-size: 24px;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #8a2be2;
            padding-bottom: 10px;
        }
        
        .user-card {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(45deg, #8a2be2, #9370db);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .user-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .detail-item {
            background: rgba(255, 255, 255, 0.9);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #8a2be2;
        }
        
        .detail-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: bold;
            color: #333;
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
        
        .cursor.active {
            transform: translate(-50%, -50%) scale(0.8);
        }
        
        .cursor.hover.active {
            transform: translate(-50%, -50%) scale(0.8);
        }
        
        /* 按钮禁用状态 */
        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        /* 快速操作卡片 */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .action-card {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .action-icon {
            font-size: 32px;
            margin-bottom: 10px;
            color: #8a2be2;
        }
        
        .action-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .action-desc {
            font-size: 12px;
            color: #666;
        }
        
        /* 统一文件选择控件样式 */
        .file-input-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .file-input-label {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: 8px 14px;
            border-radius: 10px;
            background: linear-gradient(135deg,#8a2be2,#9370db);
            color: #fff;
            font-size: 14px;
            box-shadow: 0 6px 18px rgba(147,112,219,0.12);
            text-decoration: none;
        }
        .file-input-label:hover { box-shadow: 0 10px 28px rgba(138,43,226,0.16); transform: translateY(-2px); }
        .file-name {
            color: #555;
            font-size: 13px;
            max-width: 420px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        /* 隐藏原生输入，但保持可访问性 */
        #avatarInput { position: absolute !important; left: -9999px; width: 1px; height: 1px; opacity: 0; pointer-events: none; }

        /* 头像预览样式 */
        .avatar-preview-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .avatar-preview-box {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(45deg,#8a2be2,#9370db);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-size:28px;
            flex-shrink:0;
            border: 3px solid rgba(255,255,255,0.6);
            box-shadow: 0 6px 14px rgba(0,0,0,0.08);
        }
        .avatar-preview-box img {
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }
        .avatar-preview-label {
            font-size:13px;
            color:#666;
        }

        /* 新增：工作室徽章样式（用于头部显示） */
        .studio-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(255,255,255,0.85), rgba(255,255,255,0.65));
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
            margin-right: 8px;
        }
        .studio-badge .studio-thumb {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            overflow: hidden;
            display:flex;
            align-items:center;
            justify-content:center;
            background: linear-gradient(45deg,#8a2be2,#9370db);
            color: #fff;
            font-weight:700;
            font-size:14px;
            flex-shrink:0;
        }
        .studio-badge .studio-name {
            font-size: 14px;
            color: #333;
            font-weight:600;
            max-width:180px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        
        /* 手机端适配样式 */
        @media (max-width: 768px) {
            .main-content {
                margin-top: 80px;
                flex-direction: column;
                padding: 10px;
            }
            
            .sidebar {
                width: 100%;
                margin-bottom: 20px;
                padding: 15px;
            }
            
            .content-area {
                padding: 20px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 10px;
                padding: 10px;
            }
            
            .user-info {
                width: 100%;
                justify-content: space-between;
            }
            
            .username {
                font-size: 14px;
            }
            
            .logout-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
            
            .section-title {
                font-size: 20px;
            }
            
            .user-details {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .detail-item, .action-card {
                padding: 12px;
            }
            
            .studio-badge {
                padding: 4px 8px;
            }
            
            .studio-badge .studio-thumb {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
            
            .studio-badge .studio-name {
                font-size: 12px;
                max-width: 120px;
            }
            
            
            
            @media (max-width: 768px) {
                 {
                    display: block;
                }
            }
            
            /* 手机端隐藏logo */
            .logo {
                display: none;
            }
            
            /* 减小手机端顶栏高度 */
            .header {
                padding: 8px 0;
            }
        }
        
        
    </style>
</head>
<body>
    <!-- 自定义鼠标指针 -->
    <div class="cursor" id="cursor"></div>
    
    <!-- 头部导航 -->
    <div class="header">
        <div class="header-content">
            <img src="https://files.sgstudio2025.xyz/sgstudiologoa.png" alt="Logo" class="logo">
            <!-- 手机端显示侧边栏按钮 -->
            <div class="user-info">
                <!-- 新增：若用户属于工作室，在头部显示工作室徽章 -->
                <?php if (!empty($currentStudio)): ?>
                    <div class="studio-badge" title="<?php echo htmlspecialchars($currentStudio['name']); ?>">
                        <div class="studio-thumb" aria-hidden="true">
                            <?php if (!empty($currentStudio['avatar'])): ?>
                                <img src="<?php echo htmlspecialchars($currentStudio['avatar']); ?>" alt="<?php echo htmlspecialchars($currentStudio['name']); ?>" style="width:100%;height:100%;object-fit:cover">
                            <?php else: ?>
                                <?php echo htmlspecialchars(mb_substr($currentStudio['name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="studio-name"><?php echo htmlspecialchars($currentStudio['name']); ?></div>
                    </div>
                <?php endif; ?>

                <span class="username">欢迎，<?php echo htmlspecialchars($username); ?></span>
                <button class="logout-btn" onclick="logout()">退出登录</button>
            </div>
        </div>
    </div>
    
    <!-- 主要内容区域 -->
    <div class="main-content">
        <!-- 侧边栏菜单 -->
        <div class="sidebar" id="sidebar">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0;">菜单</h3>
                </div>
            <ul class="sidebar-menu">
                <li><a href="#" class="active" onclick="showSection('overview')">个人概览</a></li>
                <li><a href="#" onclick="showSection('profile')">个人资料</a></li>
                <li><a href="#" onclick="showSection('security')">安全设置</a></li>
                <li><a href="#" onclick="showSection('applications')">工作室管理</a></li>
                <?php if (!empty($is_admin)): ?>
                    <li><a href="admin.php">管理员面板</a></li>
                <?php endif; ?>
                
            </ul>
        </div>
        
        <!-- 内容区域 -->
        <div class="content-area">
            <!-- 个人概览页面 -->
            <div id="overview" class="content-section">
                <h2 class="section-title">个人概览</h2>
                
                <div class="user-card">
                    <div class="user-avatar">
                        <?php if (!empty($avatar)): ?>
                            <img id="currentAvatar" src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <span id="currentAvatarLetter"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <h3>欢迎回来，<?php echo htmlspecialchars($username); ?>！</h3>
                    <p>欢迎来到用户中心</p>
                </div>
                
                <div class="user-details">
                    <div class="detail-item">
                        <div class="detail-label">用户ID</div>
                        <div class="detail-value">#<?php echo htmlspecialchars($user_id); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">用户名</div>
                        <div class="detail-value"><?php echo htmlspecialchars($username); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">当前时间</div>
                        <div class="detail-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">登录状态</div>
                        <div class="detail-value">已登录</div>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <div class="action-card" onclick="showSection('profile')">
                        <div class="action-icon">👤</div>
                        <div class="action-title">编辑资料</div>
                        <div class="action-desc">更新个人信息</div>
                    </div>
                    <div class="action-card" onclick="showSection('security')">
                        <div class="action-icon">🔒</div>
                        <div class="action-title">安全设置</div>
                        <div class="action-desc">修改密码等安全选项</div>
                    </div>
                    <?php if (!empty($is_admin)): ?>
                        <div class="action-card" onclick="window.location.href='admin.php'">
                            <div class="action-icon">🛠️</div>
                            <div class="action-title">管理员面板</div>
                            <div class="action-desc">管理用户与权限</div>
                        </div>
                    <?php endif; ?>
                    <div class="action-card" onclick="showSection('applications')">
                        <div class="action-icon">🏢</div>
                        <div class="action-title">归属工作室</div>
                        <div class="action-desc">管理工作室与资源</div>
                    </div>
                    
                </div>
            </div>
            
            <!-- 个人资料页面 -->
            <div id="profile" class="content-section" style="display: none;">
                <h2 class="section-title">个人资料</h2>
                <div class="user-card">
                    <h3>· 基本信息 ·</h3>
                    <!-- 新增：头像预览（当前头像 + 待上传预览） -->
                    <div class="avatar-preview-wrap">
                        <div>
                            <div class="avatar-preview-label">当前头像</div>
                            <div class="avatar-preview-box" id="currentAvatarBox">
                                <?php if (!empty($avatar)): ?>
                                    <img id="currentAvatarSmall" src="<?php echo htmlspecialchars($avatar); ?>" alt="当前头像">
                                <?php else: ?>
                                    <span id="currentAvatarLetterSmall"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <div class="avatar-preview-label">已选择（预览）</div>
                            <div class="avatar-preview-box" id="selectedAvatarBox">
                                <span id="selectedAvatarPlaceholder" style="font-size:14px;color:rgba(255,255,255,0.9);">无</span>
                            </div>
                        </div>
                    </div>

                    <!-- 新增：上传头像表单 -->
                    <form id="avatarForm" enctype="multipart/form-data" style="margin-top:15px;">
                        <div class="file-input-wrapper">
                            <input type="file" id="avatarInput" name="avatar" accept="image/*">
                            <label for="avatarInput" class="file-input-label" aria-hidden="false">选择头像</label>
                            <span id="fileName" class="file-name">未选择文件</span>
                            <button type="submit" id="uploadBtn" class="btn">上传并更换</button>
                            <span id="uploadMsg" style="margin-left:10px;color:#666;"></span>
                        </div>
                    </form>

                    <!-- 新增：修改别称 -->
                    <form id="nicknameForm" style="margin-top:15px;">
                        <label for="nicknameInput">用户别称（公开）</label><br>
                        <input type="text" id="nicknameInput" name="nickname" placeholder="输入新的别称，2-32 字符" style="padding:8px;margin-top:6px;width:60%;border-radius:6px;border:1px solid #ddd;">
                        <button type="submit" id="updateNicknameBtn" class="btn" style="margin-left:10px;">更新别称</button>
                        <span id="nicknameMsg" style="margin-left:10px;color:#666;"></span>
                    </form>

                </div>
            </div>
            
            <!-- 安全设置页面 -->
            <div id="security" class="content-section" style="display: none;">
                <h2 class="section-title">安全设置</h2>
                <div class="user-card">
                    <h3>· 账户安全 ·</h3>
                    <p>通过向您原先绑定的邮箱发送验证码来验证身份后，可重设密码</p>

                    <!-- 更改密码 -->
                    <div style="margin-top:6px;">
                        <h4 style="margin:6px 0;">更改密码</h4>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input id="newPasswordInput" type="password" placeholder="新密码（8-64字符）" style="padding:8px;border-radius:6px;border:1px solid #ddd;width:320px;">
                            <button id="sendSecCodeBtn2" class="btn">发送验证码到原邮箱</button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                            <input id="passwordCodeInput" type="text" placeholder="输入验证码" style="padding:8px;border-radius:6px;border:1px solid #ddd;width:180px;">
                            <button id="updatePasswordBtn" class="btn">确认更新密码</button>
                            <span id="passwordChangeMsg" style="margin-left:8px;color:#666;"></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 工作室管理页面 -->
            <div id="applications" class="content-section" style="display: none;">
                <h2 class="section-title">归属工作室</h2>
                <div class="user-card">
                    <h3>所属工作室</h3>
                    <p>您可以选择加入或退出下面的工作室</p>

                    <div id="studioGridWrap" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
                        <?php if (empty($studios)): ?>
                            <div class="detail-item">暂无工作室</div>
                        <?php else: ?>
                            <?php foreach ($studios as $s): 
                                $sid = (int)$s['id'];
                                $joined = !empty($memberMap[$sid]);
                            ?>
                            <div class="detail-item" style="display:flex;flex-direction:column;gap:8px;">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div style="width:56px;height:56px;border-radius:8px;overflow:hidden;background:linear-gradient(45deg,#8a2be2,#9370db);flex-shrink:0;">
                                        <?php if (!empty($s['avatar'])): ?>
                                            <img src="<?php echo htmlspecialchars($s['avatar']); ?>" style="width:100%;height:100%;object-fit:cover;">
                                        <?php else: ?>
                                            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;"><?php echo htmlspecialchars(mb_substr($s['name'],0,1)); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex:1">
                                        <div style="font-weight:700;"><?php echo htmlspecialchars($s['name']); ?> <?php if (empty($s['visible'])): ?><span style="color:#999;font-size:12px">（隐藏）</span><?php endif; ?></div>
                                        <div style="color:#666;font-size:13px;"><?php echo htmlspecialchars($s['url']); ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="margin-bottom:6px;">
                                            <?php
                                            // 如果用户已属于其他工作室，禁止在其它工作室上点击加入
                                            $isCurrent = ($currentStudioId === $sid);
                                            $disabledAttr = '';
                                            $btnText = $joined ? '退出' : '加入';
                                            $btnStyle = $joined ? 'background:#e05656' : '';
                                            if ($currentStudioId && !$isCurrent && !$joined) {
                                                // 已加入其他工作室，且当前卡片不是所属工作室
                                                $disabledAttr = 'disabled';
                                                $btnText = '已加入其它';
                                                $btnStyle = 'background:#ccc;color:#666;cursor:not-allowed';
                                            }
                                            ?>
                                            <button class="btn studio-action-btn" data-id="<?php echo $sid; ?>" data-joined="<?php echo $joined ? '1' : '0'; ?>" <?php echo $disabledAttr; ?> style="<?php echo $btnStyle; ?>">
                                                <?php echo $btnText; ?>
                                            </button>
                                        </div>
                                        <div style="font-size:12px;color:#999;">ID: <?php echo $sid; ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($s['description'])): ?>
                                    <div style="color:#555;font-size:13px;"><?php echo htmlspecialchars($s['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    
                </div>
            </div>
            
            
            
        </div>
    </div>
    
    <div class="copyright">Copyright © 2025 sg workstation</div>
    
    <script>
        // 自定义鼠标指针
        const cursor = document.getElementById('cursor');
        
        document.addEventListener('mousemove', function(e) {
            cursor.style.left = e.clientX + 'px';
            cursor.style.top = e.clientY + 'px';
        });
        
        window.addEventListener('mouseenter', function() {
            cursor.style.opacity = '1';
        });
        window.addEventListener('mouseleave', function() {
            cursor.style.opacity = '0';
        });
        
        const buttons = document.querySelectorAll('button');
        buttons.forEach(button => {
            button.addEventListener('mouseenter', function() {
                cursor.classList.add('hover');
            });
            
            button.addEventListener('mouseleave', function() {
                cursor.classList.remove('hover');
                cursor.classList.remove('active');
            });
            
            button.addEventListener('mousedown', function() {
                cursor.classList.add('active');
            });
            
            button.addEventListener('mouseup', function() {
                cursor.classList.remove('active');
            });
        });
        
        // 侧边栏菜单交互
        const menuItems = document.querySelectorAll('.sidebar-menu a');
        menuItems.forEach(item => {
            item.addEventListener('mouseenter', function() {
                cursor.classList.add('hover');
            });
            
            item.addEventListener('mouseleave', function() {
                cursor.classList.remove('hover');
            });
        });
        
        // 快速操作卡片交互
        const actionCards = document.querySelectorAll('.action-card');
        actionCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                cursor.classList.add('hover');
            });
            
            card.addEventListener('mouseleave', function() {
                cursor.classList.remove('hover');
            });
        });
        
        // 显示不同区域
        function showSection(sectionId) {
            // 隐藏所有区域
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => {
                section.style.display = 'none';
            });
            
            // 显示选中的区域
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.style.display = 'block';
            }
            
            // 更新菜单激活状态
            menuItems.forEach(item => {
                item.classList.remove('active');
            });
            
            // 设置当前菜单项为激活状态
            const currentMenuItem = document.querySelector(`.sidebar-menu a[onclick="showSection('${sectionId}')"]`);
            if (currentMenuItem) {
                currentMenuItem.classList.add('active');
            }
            
            // 在手机端点击菜单项后隐藏侧边栏
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').style.display = 'none';
                
            }
        }
        
        // 页面加载完成后检查屏幕宽度，手机端默认隐藏侧边栏
        window.addEventListener('load', function() {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').style.display = 'none';
                
            }
        });
        
        // 退出登录
        function logout() {
            if (confirm('确定要退出登录吗？')) {
                fetch('logout.php', {
                    method: 'POST'
                })
                .then(response => response.text())
                .then(() => {
                    window.location.href = 'index.php';
                })
                .catch(error => {
                    console.error('退出登录失败:', error);
                    window.location.href = 'index.php';
                });
            }
        }

        // 当选择文件时显示文件名并预览所选图片
        if (avatarInput) {
            avatarInput.addEventListener('change', function() {
                const fnEl = document.getElementById('fileName');
                const selectedBox = document.getElementById('selectedAvatarBox');
                const selectedPlaceholder = document.getElementById('selectedAvatarPlaceholder');
                const currentBox = document.getElementById('currentAvatarBox');

                const f = avatarInput.files && avatarInput.files[0];
                fnEl.textContent = f ? f.name : '未选择文件';

                // 清除之前预览
                if (selectedBox) {
                    selectedBox.innerHTML = '';
                }

                if (f) {
                    const allowedTypes = ['image/jpeg','image/png','image/gif','image/webp'];
                    if (allowedTypes.indexOf(f.type) === -1) {
                        // 非法类型，仅显示文件名并不渲染预览
                        if (selectedPlaceholder) selectedPlaceholder.textContent = '不支持的格式';
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // 在预览框显示所选图片
                        if (selectedBox) {
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.alt = '所选头像预览';
                            selectedBox.innerHTML = '';
                            selectedBox.appendChild(img);
                        }
                    };
                    reader.readAsDataURL(f);
                } else {
                    // 无文件，恢复占位
                    if (selectedBox && selectedPlaceholder) {
                        selectedBox.innerHTML = '';
                        selectedBox.appendChild(selectedPlaceholder);
                    }
                }
            });
        }

        // 头像上传逻辑（保留原有上传并在成功后更新当前头像和清空预览）
        if (avatarForm) {
            avatarForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!avatarInput.files || !avatarInput.files[0]) {
                    uploadMsg.textContent = '请选择图片文件';
                    return;
                }
                const file = avatarInput.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    uploadMsg.textContent = '文件过大，最大 5MB';
                    return;
                }
                uploadBtn.disabled = true;
                uploadMsg.textContent = '上传中...';

                const fd = new FormData();
                fd.append('avatar', file);

                fetch('profile_upload.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }).then(res => res.json())
                .then(data => {
                    uploadBtn.disabled = false;
                    if (data && data.success) {
                        uploadMsg.style.color = 'green';
                        uploadMsg.textContent = '上传成功';
                        const newUrl = data.avatar + '?t=' + Date.now();

                        // 更新概览中的大头像
                        let imgEl = document.getElementById('currentAvatar');
                        if (!imgEl) {
                            const avatarWrap = document.querySelector('.user-avatar');
                            if (avatarWrap) {
                                avatarWrap.innerHTML = '<img id="currentAvatar" src="' + newUrl + '" alt="avatar" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">';
                            }
                        } else {
                            imgEl.src = newUrl;
                        }

                        // 更新资料页的当前小头像
                        const currentSmall = document.getElementById('currentAvatarSmall');
                        const currentLetter = document.getElementById('currentAvatarLetterSmall');
                        if (currentSmall) {
                            currentSmall.src = newUrl;
                        } else if (currentLetter) {
                            const box = document.getElementById('currentAvatarBox');
                            if (box) box.innerHTML = '<img id="currentAvatarSmall" src="' + newUrl + '" alt="当前头像">';
                        }

                        // 清除所选预览与文件输入
                        const selectedBox = document.getElementById('selectedAvatarBox');
                        const selectedPlaceholder = document.createElement('span');
                        selectedPlaceholder.id = 'selectedAvatarPlaceholder';
                        selectedPlaceholder.style.cssText = 'font-size:14px;color:rgba(255,255,255,0.9);';
                        selectedPlaceholder.textContent = '无';
                        if (selectedBox) {
                            selectedBox.innerHTML = '';
                            selectedBox.appendChild(selectedPlaceholder);
                        }
                        avatarInput.value = '';
                        document.getElementById('fileName').textContent = '未选择文件';
                    } else {
                        uploadMsg.style.color = 'red';
                        uploadMsg.textContent = (data && data.message) ? data.message : '上传失败';
                    }
                })
                .catch(err => {
                    uploadBtn.disabled = false;
                    uploadMsg.style.color = 'red';
                    uploadMsg.textContent = '上传出错';
                    console.error(err);
                });
            });
        }

        // 新增：别称更新逻辑
        (function() {
            const nicknameForm = document.getElementById('nicknameForm');
            const nicknameInput = document.getElementById('nicknameInput');
            const nicknameMsg = document.getElementById('nicknameMsg');
            const updateNicknameBtn = document.getElementById('updateNicknameBtn');

            if (nicknameForm) {
                // 如果后端已返回别称，可以在页面加载时填充（避免重复请求）
                // ...existing code...

                nicknameForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const val = nicknameInput.value.trim();
                    if (!val) {
                        nicknameMsg.style.color = 'red';
                        nicknameMsg.textContent = '别称不能为空';
                        return;
                    }
                    if (val.length < 2 || val.length > 32) {
                        nicknameMsg.style.color = 'red';
                        nicknameMsg.textContent = '别称长度需在 2-32 字符';
                        return;
                    }

                    updateNicknameBtn.disabled = true;
                    nicknameMsg.style.color = '#666';
                    nicknameMsg.textContent = '更新中...';

                    fetch('update_nickname.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ nickname: val })
                    })
                    .then(res => res.json())
                    .then(data => {
                        updateNicknameBtn.disabled = false;
                        if (data && data.success) {
                            nicknameMsg.style.color = 'green';
                            nicknameMsg.textContent = '更新成功';
                            // 更新页面显示的用户名（如需显示别称，优先显示别称）
                            const usernameDisplay = document.querySelector('.username');
                            if (usernameDisplay) {
                                usernameDisplay.textContent = '欢迎，' + (data.nickname_display || val);
                            }
                        } else {
                            nicknameMsg.style.color = 'red';
                            nicknameMsg.textContent = (data && data.message) ? data.message : '更新失败';
                        }
                    })
                    .catch(err => {
                        updateNicknameBtn.disabled = false;
                        nicknameMsg.style.color = 'red';
                        nicknameMsg.textContent = '请求失败';
                        console.error(err);
                    });
                });
            }
        })();

        // 安全设置：发送验证码（用于更改密码），并倒计时
        function startSecCountdown(btn, seconds) {
            let remaining = seconds;
            btn.disabled = true;
            const orig = btn.textContent;
            btn.textContent = `${remaining}s 后重试`;
            const t = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(t);
                    btn.disabled = false;
                    btn.textContent = orig;
                } else {
                    btn.textContent = `${remaining}s 后重试`;
                }
            }, 1000);
        }

        document.getElementById('sendSecCodeBtn2').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            fetch('send_verification.php', { method: 'POST' })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                if (d && d.success) {
                    startSecCountdown(btn, 60);
                    document.getElementById('passwordChangeMsg').style.color = 'green';
                    document.getElementById('passwordChangeMsg').textContent = d.message || '验证码已发送';
                } else {
                    document.getElementById('passwordChangeMsg').style.color = 'red';
                    document.getElementById('passwordChangeMsg').textContent = d && d.message ? d.message : '发送失败';
                }
            }).catch(e => { btn.disabled=false; document.getElementById('passwordChangeMsg').textContent='请求失败'; });
        });

        // 提交更改密码
        document.getElementById('updatePasswordBtn').addEventListener('click', function() {
            const newPwd = document.getElementById('newPasswordInput').value;
            const code = document.getElementById('passwordCodeInput').value.trim();
            const msgEl = document.getElementById('passwordChangeMsg');
            if (!newPwd || !code) { msgEl.style.color='red'; msgEl.textContent='请填写新密码和验证码'; return; }
            if (newPwd.length < 8 || newPwd.length > 64) { msgEl.style.color='red'; msgEl.textContent='密码长度需为 8-64 字符'; return; }
            this.disabled = true; msgEl.style.color='#666'; msgEl.textContent='提交中...';
            fetch('update_password.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({ new_password: newPwd, code }) })
            .then(r=>r.json()).then(d=>{
                this.disabled=false;
                if (d && d.success) { msgEl.style.color='green'; msgEl.textContent='密码已更新'; } else { msgEl.style.color='red'; msgEl.textContent = d && d.message ? d.message : '更新失败'; }
            }).catch(e=>{ this.disabled=false; msgEl.style.color='red'; msgEl.textContent='请求出错'; });
        });

        // 加入/退出工作室逻辑
        document.querySelectorAll('.studio-action-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const joined = this.getAttribute('data-joined') === '1';
                const self = this;
                self.disabled = true;
                self.textContent = joined ? '退出中...' : '加入中...';
                const url = joined ? 'leave_studio.php' : 'join_studio.php';
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ studio_id: id })
                }).then(res => res.json()).then(data => {
                    self.disabled = false;
                    if (data && data.success) {
                        // 切换状态
                        if (joined) {
                            self.setAttribute('data-joined', '0');
                            self.textContent = '加入';
                            self.style.background = '';
                        } else {
                            self.setAttribute('data-joined', '1');
                            self.textContent = '退出';
                            self.style.background = '#e05656';
                        }
                    } else {
                        alert('操作失败：' + (data && data.message ? data.message : '未知错误'));
                        self.textContent = joined ? '退出' : '加入';
                    }
                }).catch(err => {
                    console.error(err);
                    alert('请求失败');
                    self.disabled = false;
                    self.textContent = joined ? '退出' : '加入';
                });
            });
        });
        
        
        

        
        
    </script>
</body>
</html>