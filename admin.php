<?php
// 管理员面板：仅管理员可访问
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/db.php';

// 获取当前用户 is_admin 权限
$user_id = (int)$_SESSION['user_id'];
$is_admin = 0;
try {
    $pdo = function_exists('get_db_pdo') ? get_db_pdo() : ($pdo ?? null);
} catch (Throwable $e) { $pdo = ($pdo ?? null); }

if ($pdo) {
    $stmt = $pdo->prepare('SELECT IFNULL(is_admin,0) FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $is_admin = (int)$stmt->fetchColumn();
} elseif (isset($conn) && $conn instanceof mysqli) {
    $stmt = $conn->prepare('SELECT IFNULL(is_admin,0) FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->bind_result($is_admin);
    $stmt->fetch();
    $stmt->close();
    $is_admin = (int)$is_admin;
}

if (!$is_admin) {
    http_response_code(403);
    echo '403 Forbidden — 仅管理员可访问';
    exit;
}

// 读取用户列表（限制数量，若用户较多请在 SQL 中分页）
$users = [];
try {
    if ($pdo) {
        $q = $pdo->query('SELECT id, username, email, IFNULL(is_admin,0) as is_admin FROM users ORDER BY id ASC LIMIT 1000');
        $users = $q->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $res = $conn->query('SELECT id, username, email, IFNULL(is_admin,0) as is_admin FROM users ORDER BY id ASC LIMIT 1000');
        if ($res) {
            while ($r = $res->fetch_assoc()) $users[] = $r;
            $res->free();
        }
    }
} catch (Throwable $e) {
    // ignore
}

// 在已读取用户列表之后，尝试读取 studios 表（若不存在则为空）
$studios = [];
try {
    if ($pdo) {
        try {
            $q2 = $pdo->query('SELECT id, name, url, avatar, description, visible, created_by, created_at FROM studios ORDER BY id DESC LIMIT 1000');
            $studios = $q2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // 表可能不存在，忽略
            $studios = [];
        }
    } else {
        try {
            $res2 = $conn->query('SELECT id, name, url, avatar, description, visible, created_by, created_at FROM studios ORDER BY id DESC LIMIT 1000');
            if ($res2) {
                while ($r = $res2->fetch_assoc()) $studios[] = $r;
                $res2->free();
            }
        } catch (Throwable $e) { $studios = []; }
    }
} catch (Throwable $e) {
    $studios = [];
}
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理员面板 - 统一用户登录</title>
<style>
/* 风格：与 index 保持一致并优化间距、可读性 */
:root{
    --accent1:#8a2be2;
    --accent2:#9370db;
    --bg-card:rgba(255,255,255,0.92);
    --muted:#747b83;
    --radius:12px;
}

*{box-sizing:border-box}
body {
    margin: 0;
    padding: 0;
    min-height: 100vh;
    background-image: url('https://files.sgstudio2025.xyz/bj/bj1.webp');
    background-size: cover;
    background-position: center;
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    position: relative;
    color:#222;
    cursor: none;
}
.cursor {
    position: fixed;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background-color: rgba(255,255,255,0.85);
    pointer-events: none;
    transform: translate(-50%,-50%);
    z-index: 9999;
    transition: transform .12s ease, background-color .12s ease, opacity .12s ease;
    opacity: 1;
}
.cursor.hover { transform: translate(-50%,-50%) scale(1.45); background-color: rgba(255,255,255,1); }
.cursor.active { transform: translate(-50%,-50%) scale(0.85); }

.container {
    max-width: 1100px;
    margin: 40px auto;
    padding: 20px;
}

.floating-window {
    display:flex;
    gap:20px;
    background: var(--bg-card);
    backdrop-filter: blur(10px);
    border-radius: var(--radius);
    box-shadow: 0 18px 40px rgba(0,0,0,0.14);
    overflow: hidden;
}

/* Sidebar */
.sidebar{
    width: 220px;
    padding:16px;
    background: rgba(255,255,255,0.96);
    border-right:1px solid rgba(0,0,0,0.04);
}
.sidebar .logo { height:44px; display:block; margin:0 auto 10px; }
.sidebar h3{ font-size:15px; margin:8px 0 12px; color:#333; text-align:center; }
.sidebar a{ display:block; padding:10px 12px; margin-bottom:8px; border-radius:10px; color:#333; text-decoration:none; font-weight:500; transition:all .12s ease; }
.sidebar a:hover{ background: linear-gradient(135deg,var(--accent1),var(--accent2)); color:#fff; transform:translateY(-2px); }
.sidebar a.active{ background: linear-gradient(135deg,var(--accent1),var(--accent2)); color:#fff; box-shadow: 0 8px 22px rgba(138,43,226,0.12); }

/* Content */
.content{ flex:1; padding:18px; }
.header-row{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:14px; }
.title{ font-size:20px; font-weight:700; color:#222; }
.controls{ display:flex; gap:10px; align-items:center; }

/* Button */
.btn{
    display:inline-flex; align-items:center; gap:8px;
    background: linear-gradient(135deg,var(--accent1),var(--accent2));
    color:#fff; border:none; padding:8px 12px; border-radius:10px; cursor:pointer; font-weight:600;
    box-shadow: 0 10px 24px rgba(147,112,219,0.08);
}
.btn:disabled{ opacity:.7; cursor:not-allowed; transform:none; box-shadow:none; }

/* Card and Table */
.card{ background:#fff; border-radius:10px; padding:12px; margin-bottom:14px; box-shadow: 0 6px 18px rgba(0,0,0,0.04); }
.table{ width:100%; border-collapse:collapse; margin-top:8px; }
.table th, .table td{ padding:10px 12px; border-bottom:1px solid #f2f2f2; font-size:13px; vertical-align:middle; }
.table thead th{ background:transparent; font-weight:700; color:#333; text-align:left; }
.table tbody tr:hover{ background: linear-gradient(90deg, rgba(138,43,226,0.02), rgba(147,112,219,0.02)); }

/* Studio list */
.studio-row{ display:flex; gap:12px; align-items:center; padding:12px 0; border-bottom:1px dashed #f3f3f3; }
.studio-thumb{ width:64px; height:64px; border-radius:10px; overflow:hidden; display:flex; align-items:center; justify-content:center; background: linear-gradient(45deg,var(--accent1),var(--accent2)); color:#fff; font-weight:700; font-size:20px; flex-shrink:0; }
.studio-meta{ flex:1; }
.studio-meta .name{ font-weight:700; color:#222; }
.studio-meta .meta{ color:var(--muted); font-size:13px; margin-top:6px; }

/* 替换：工作室列表从纵向行改为卡片网格 */
.studio-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    align-items: start;
}
@media (max-width:800px){
    .studio-grid { grid-template-columns: 1fr; }
}
.studio-card {
    background:#fff;
    border-radius:12px;
    padding:12px;
    display:flex;
    gap:12px;
    align-items:flex-start;
    box-shadow:0 8px 18px rgba(0,0,0,0.04);
    transition:transform .12s ease, box-shadow .12s ease;
}
.studio-card:hover{ transform: translateY(-4px); box-shadow:0 14px 30px rgba(0,0,0,0.06); }
.studio-card .thumb { width:72px;height:72px;border-radius:10px;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(45deg,var(--accent1),var(--accent2));color:#fff;font-weight:700;font-size:20px; }
.studio-card .info { flex:1; }
.studio-card .info .name { font-weight:700;color:#222;margin-bottom:6px;display:flex;align-items:center;gap:8px; }
.studio-card .info .meta { color:var(--muted); font-size:13px; line-height:1.35; }
.studio-card .meta-right { text-align:right;color:var(--muted);font-size:12px;min-width:88px; }

/* Forms */
.form-row{ display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
.input, textarea, select{ padding:10px; border-radius:8px; border:1px solid #e6e6e6; font-size:14px; color:#222; background:#fff; }
textarea{ min-height:72px; width:100%; resize:vertical; }

/* small helpers */
.muted{ color:var(--muted); font-size:13px; }
.badge{ display:inline-block; padding:4px 8px; border-radius:8px; font-size:12px; color:#fff; background:rgba(0,0,0,0.06); }

/* responsive */
@media (max-width:1000px){
    .floating-window{ flex-direction:column; }
    .sidebar{ width:100%; display:flex; gap:8px; flex-wrap:wrap; justify-content:center; }
    .content{ padding:12px; }
}
</style>
</head>
<body>
<div class="cursor" id="cursor"></div>

<div class="container">
<div class="floating-window" role="main" aria-label="管理员面板">
    <aside class="sidebar" aria-label="侧边栏">
        <img src="https://files.sgstudio2025.xyz/sgstudiologoa.png" alt="Logo" class="logo">
        <h3>管理员面板</h3>
        <a href="#" class="active" data-section="users">用户管理</a>
        <a href="#" data-section="studios">工作室管理</a>
        <a href="dashboard.php" style="margin-top:8px; display:block; text-align:center; color:var(--muted);">返回控制面板</a>
    </aside>

    <section class="content" aria-live="polite">
        <div class="header-row">
            <div>
                <div class="title">平台管理控制台</div>
                <div class="muted">概览 · 用户与工作室管理</div>
            </div>
            <div class="controls">
                <button class="btn" id="refreshBtn" title="刷新页面">刷新</button>
            </div>
        </div>

        <!-- 用户管理 -->
        <div id="section-users" class="card section-panel">
            <h4 style="margin:0 0 8px">用户列表</h4>
            <div class="muted" style="margin-bottom:10px">显示最多 1000 条记录。使用示例 SQL 手动调整用户管理员权限。</div>
            <div style="overflow:auto">
            <table class="table" role="table" aria-label="用户表">
                <thead><tr><th style="min-width:60px">ID</th><th>用户名</th><th>邮箱</th><th style="min-width:90px">是否管理员</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?php echo (int)$u['id']; ?></td>
                    <td><?php echo htmlspecialchars($u['username'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                    <td><?php echo !empty($u['is_admin']) ? '<span class="badge" style="background:linear-gradient(135deg,var(--accent1),var(--accent2))">是</span>' : '<span class="muted">否</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- 工作室管理 -->
        <div id="section-studios" class="card section-panel" style="display:none">
            <h4 style="margin:0 0 8px">新建工作室</h4>
            <div class="card" style="margin-bottom:12px">
                <div class="form-row">
                    <input id="studioName" class="input" placeholder="名称（必填）" style="width:260px">
                    <input id="studioUrl" class="input" placeholder="网站" style="width:320px">
                    <label for="studioAvatarInput" class="btn" style="margin-left:6px;cursor:pointer">选择头像</label>
                    <input type="file" id="studioAvatarInput" accept="image/*" style="display:none">
                </div>
                <div style="display:flex;gap:12px;align-items:center">
                    <div style="flex:1">
                        <textarea id="studioDesc" placeholder="简介（可选）" class="input" ></textarea>
                        <div style="margin-top:8px;display:flex;align-items:center;gap:12px">
                            <label style="display:inline-flex;align-items:center;gap:8px"><input type="checkbox" id="studioVisible" checked> 显示</label>
                            <button class="btn" id="createStudioBtn">创建工作室</button>
                            <span id="studioMsg" class="muted"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 改为网格展示 -->
            <div id="studioGrid" class="studio-grid">
                <?php if (empty($studios)): ?>
                    <div class="muted">暂无工作室（请运行升级脚本创建 studios 表）</div>
                <?php else: ?>
                    <!-- 替换每个 studio-card 的渲染，增加删除按钮（在 meta-right 中） -->
                    <?php foreach ($studios as $s): ?>
                        <div class="studio-card" data-id="<?php echo (int)$s['id']; ?>">
                            <div class="thumb">
                                <?php if (!empty($s['avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars($s['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                                <?php else: ?>
                                    <?php echo htmlspecialchars(mb_substr($s['name'],0,1)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="info">
                                <div class="name">
                                    <?php echo htmlspecialchars($s['name']); ?>
                                    <?php if (empty($s['visible'])): ?><span class="muted" style="font-size:12px">（隐藏）</span><?php endif; ?>
                                </div>
                                <div class="meta"><?php echo htmlspecialchars($s['url']); ?></div>
                                <div class="meta" style="margin-top:6px"><?php echo htmlspecialchars($s['description']); ?></div>
                            </div>
                            <div class="meta-right">
                                ID: <?php echo (int)$s['id']; ?><br><?php echo htmlspecialchars($s['created_at'] ?? ''); ?>
                                <!-- 新增：删除按钮 -->
                                <div style="margin-top:8px;">
                                    <button class="btn delete-studio-btn" data-id="<?php echo (int)$s['id']; ?>" style="background:#e05656;border-radius:8px;padding:6px 8px;font-size:13px;">删除</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </section>
</div>
</div>

<script>
// 初始化侧栏与面板，增强体验
document.addEventListener('DOMContentLoaded', function(){
    // 指针
    const cursor = document.getElementById('cursor');
    document.addEventListener('mousemove', e => { cursor.style.left = e.clientX + 'px'; cursor.style.top = e.clientY + 'px'; });

    // 侧栏切换逻辑（初始激活 users）
    const sidebarLinks = document.querySelectorAll('.sidebar a[data-section]');
    function showSection(name){
        document.querySelectorAll('.section-panel').forEach(p=>p.style.display='none');
        const panel = document.getElementById('section-' + name);
        if (panel) panel.style.display = 'block';
        sidebarLinks.forEach(a=>a.classList.toggle('active', a.getAttribute('data-section')===name));
    }
    // bind
    sidebarLinks.forEach(a=>{
        a.addEventListener('click', function(e){
            e.preventDefault();
            showSection(this.getAttribute('data-section'));
        });
    });
    // 默认显示用户管理
    showSection('users');

    // 鼠标样式交互（按钮与链接）
    document.querySelectorAll('.btn, a').forEach(el=>{
        el.addEventListener('mouseenter', ()=> cursor.classList.add('hover'));
        el.addEventListener('mouseleave', ()=> cursor.classList.remove('hover'));
        el.addEventListener('mousedown', ()=> cursor.classList.add('active'));
        el.addEventListener('mouseup', ()=> cursor.classList.remove('active'));
    });

    // 刷新
    document.getElementById('refreshBtn').addEventListener('click', ()=> location.reload());

    // 工作室头像预览
    const avatarInput = document.getElementById('studioAvatarInput');
    const avatarPreview = document.getElementById('studioAvatarPreview');
    const studioMsg = document.getElementById('studioMsg');

    avatarInput.addEventListener('change', function(){
        const f = this.files && this.files[0];
        if (!f) { avatarPreview.innerHTML='预览'; return; }
        const allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!allowed.includes(f.type)) { studioMsg.style.color='red'; studioMsg.textContent='不支持的图片格式'; return; }
        studioMsg.textContent = '';
        const reader = new FileReader();
        reader.onload = function(ev){
            avatarPreview.innerHTML = '<img src="'+ev.target.result+'" style="width:100%;height:100%;object-fit:cover">';
        };
        reader.readAsDataURL(f);
    });

    // 创建工作室（AJAX，创建成功后局部插入，不强制刷新）
    const createBtn = document.getElementById('createStudioBtn');
    createBtn.addEventListener('click', function(){
        const name = document.getElementById('studioName').value.trim();
        const url = document.getElementById('studioUrl').value.trim();
        const desc = document.getElementById('studioDesc').value.trim();
        const visible = document.getElementById('studioVisible') ? (document.getElementById('studioVisible').checked?1:0) : 1;
        studioMsg.style.color='#666'; studioMsg.textContent='';
        if (!name){ studioMsg.style.color='red'; studioMsg.textContent='名称为必填'; return; }
        createBtn.disabled = true; createBtn.textContent = '创建中...';
        const fd = new FormData();
        fd.append('name', name); fd.append('url', url); fd.append('description', desc); fd.append('visible', visible);
        if (avatarInput.files && avatarInput.files[0]) fd.append('avatar', avatarInput.files[0]);
        fetch('admin_create_studio.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(r => r.json())
        .then(d => {
            createBtn.disabled = false; createBtn.textContent = '创建工作室';
            if (d && d.success) {
                studioMsg.style.color='green'; studioMsg.textContent='创建成功';
                // 插入新项到 studioGrid 顶部
                const s = d.studio;
                const grid = document.getElementById('studioGrid');
                const card = document.createElement('div');
                card.className = 'studio-card';
                card.setAttribute('data-id', s.id);
                card.innerHTML = '<div class="thumb">'+(s.avatar?'<img src="'+s.avatar+'" style="width:100%;height:100%;object-fit:cover">':(s.name.charAt(0)||''))+'</div>'
                    +'<div class="info"><div class="name">'+escapeHtml(s.name)+(s.visible==0?'<span class="muted" style="font-size:12px">（隐藏）</span>':'')+'</div>'
                    +'<div class="meta">'+escapeHtml(s.url)+'</div><div class="meta" style="margin-top:6px">'+escapeHtml(s.description||'')+'</div></div>'
                    +'<div class="meta-right">ID: '+s.id+'<br>'+escapeHtml(s.created_at)+'</div>';
                if (grid) grid.insertBefore(card, grid.firstChild);
                // 清空表单
                document.getElementById('studioName').value=''; document.getElementById('studioUrl').value=''; document.getElementById('studioDesc').value=''; if (avatarInput) avatarInput.value=''; avatarPreview.innerHTML='预览';
            } else {
                studioMsg.style.color='red'; studioMsg.textContent = d && d.message ? d.message : '创建失败';
            }
        })
        .catch(err=>{
            createBtn.disabled = false; createBtn.textContent = '创建工作室';
            studioMsg.style.color='red'; studioMsg.textContent='请求失败';
            console.error(err);
        });
    });

    // 删除工作室（事件委托）
    document.addEventListener('click', function(e) {
        const btn = e.target.closest && e.target.closest('.delete-studio-btn');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        if (!id) return;
        if (!confirm('确定要删除此工作室？此操作不可恢复。')) return;

        btn.disabled = true;
        const originalText = btn.textContent;
        btn.textContent = '删除中...';

        fetch('admin_delete_studio.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ id: id })
        }).then(res => res.json())
        .then(data => {
            if (data && data.success) {
                // 从页面移除卡片
                const card = document.querySelector('.studio-card[data-id="'+id+'"]');
                if (card) card.remove();
                alert('删除成功');
            } else {
                alert('删除失败：' + (data && data.message ? data.message : '未知错误'));
                btn.disabled = false;
                btn.textContent = originalText;
            }
        }).catch(err => {
            console.error(err);
            alert('请求失败');
            btn.disabled = false;
            btn.textContent = originalText;
        });
    });

    // HTML escape helper
    function escapeHtml(s){ return (s+'').replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); }
});
</script>

</body>
</html>
