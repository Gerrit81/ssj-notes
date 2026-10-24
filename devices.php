<?php
/**
 * 轻记 - 我的设备（v1.44.0）
 * 列出当前用户在 WEB 与 APP 上的所有设备会话，可一键撤销。
 * 撤销不会物理清除未加密的 session 文件，只把 user_devices 行标记 revoked_at，
 * 下次请求走 init.php 校验时因 revoked_at IS NULL 不再通过而被踢下线。
 */
$pageTitleSuffix = '我的设备';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/header.php';

// 仅普通用户有此页面（管理员用 admin 端更高权限的管理界面）
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}
if (isAdmin()) {
    // 管理员可借后台用户详情查看设备
    header('Location: admin.php');
    exit;
}

$userId = currentUserId();
$csrf_token = generateCSRF();
$devices = listUserDevices($userId);
// listUserDevices 已用 session_token 标记 is_current，但 session_token 不直接显示
?>
<link rel="stylesheet" href="assets/css/notes.css?v=1.44.0">
<style>
/* 覆盖 notes.css 的应用壳布局（body 是 flex+overflow:hidden 的全屏壳，会导致本页内容被居中且不可滚动） */
body.devices-body {
    display: block;
    height: auto;
    min-height: 100vh;
    overflow: auto;
    background: #f2f3f7;
    color: #333;
}
.devices-wrap {
    max-width: 880px;
    margin: 40px auto;
    padding: 0 20px;
    color: #333;
}
.devices-wrap h1 {
    font-size: 22px;
    margin-bottom: 8px;
    color: #222;
    font-weight: 600;
}
.devices-wrap .desc {
    color: #666;
    font-size: 13px;
    margin-bottom: 22px;
    line-height: 1.7;
}
.device-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.device-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: border-color .15s;
}
.device-card:hover { border-color: #d5d8e0; }
.device-card.current {
    border-color: rgba(102, 126, 234, 0.55);
    background: #f5f6ff;
}
.device-card.revoked { opacity: 0.55; }
.device-info { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
.device-icon {
    width: 44px; height: 44px; flex-shrink: 0;
    border-radius: 10px;
    background: #f0f2ff;
    display: flex; align-items: center; justify-content: center;
}
.device-meta { min-width: 0; }
.device-name {
    font-size: 14px;
    font-weight: 600;
    color: #222;
    margin-bottom: 4px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.device-detail {
    font-size: 12px;
    color: #888;
    line-height: 1.6;
    word-break: break-all;
}
.badge {
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 500;
}
.badge-current { background: #667eea; color: #fff; }
.badge-revoked { background: #fee2e2; color: #b91c1c; }
.badge-type-web { background: #dcfce7; color: #15803d; }
.badge-type-mobile { background: #ede9fe; color: #6d28d9; }
.device-action { flex-shrink: 0; }
.btn-revoke {
    padding: 7px 14px;
    border: 1px solid #fca5a5;
    background: #fff5f5;
    color: #b91c1c;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
    transition: all .15s;
}
.btn-revoke:hover {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
}
.btn-revoke:disabled { opacity: 0.4; cursor: not-allowed; }
.empty-tip {
    text-align: center;
    padding: 60px 0;
    color: #888;
    font-size: 14px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
}
.back-link {
    display: inline-block;
    margin-top: 24px;
    color: #667eea;
    text-decoration: none;
    font-size: 13px;
}
.back-link:hover { color: #5a6fd8; }
.toast {
    position: fixed;
    top: 28px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(34, 197, 94, 0.95);
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 9999;
    opacity: 0;
    transition: opacity .25s;
    pointer-events: none;
}
.toast.error { background: rgba(220, 38, 38, 0.95); }
.toast.show { opacity: 1; }
</style>
</head>
<body class="devices-body">

<div class="devices-wrap">
    <h1>我的设备</h1>
    <p class="desc">
        这里展示了您在 <strong style="color:#fff;">网页</strong> 与 <strong style="color:#fff;">APP</strong> 上所有已登录的设备会话。
        如果有陌生设备，可点击「撤销」将其强制下线。撤销后该设备需要重新登录才能继续使用。
    </p>
    <div class="device-list" id="deviceList">
        <?php if (empty($devices)): ?>
            <div class="empty-tip">暂无已登录设备</div>
        <?php else: ?>
            <?php foreach ($devices as $d):
                $type = (string)($d['device_type'] ?? 'mobile');
                $isCurrent = !empty($d['is_current']);
                $isRevoked = !empty($d['revoked_at']);
                $ua = (string)$d['user_agent'];
                $ip = (string)$d['ip'];
                $created = $d['created_at'];
                $last = (string)$d['last_active_at'];
                $deviceName = (string)$d['device_name'];
                if ($deviceName === '') $deviceName = $type === 'web' ? '未知浏览器' : '未知设备';
            ?>
            <div class="device-card <?= $isCurrent ? 'current' : '' ?> <?= $isRevoked ? 'revoked' : '' ?>" data-id="<?= (int)$d['id'] ?>">
                <div class="device-info">
                    <div class="device-icon">
                        <?php if ($type === 'web'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="3" width="20" height="14" rx="2"/>
                                <line x1="8" y1="21" x2="16" y2="21"/>
                                <line x1="12" y1="17" x2="12" y2="21"/>
                            </svg>
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="5" y="2" width="14" height="20" rx="2"/>
                                <line x1="12" y1="18" x2="12" y2="18"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div class="device-meta">
                        <div class="device-name">
                            <?= htmlspecialchars($deviceName) ?>
                            <span class="badge badge-type-<?= htmlspecialchars($type) ?>"><?= $type === 'web' ? '网页' : 'APP' ?></span>
                            <?php if ($isCurrent): ?><span class="badge badge-current">当前设备</span><?php endif; ?>
                            <?php if ($isRevoked): ?><span class="badge badge-revoked">已撤销</span><?php endif; ?>
                        </div>
                        <div class="device-detail">
                            IP：<?= htmlspecialchars($ip !== '' ? $ip : '未知') ?>　·　登录于 <?= htmlspecialchars($created) ?>　·　最后活跃 <?= htmlspecialchars($last) ?>
                            <?php if ($ua !== ''): ?><br><span style="opacity:0.7;">UA：<?= htmlspecialchars(mb_substr($ua, 0, 110)) ?><?= mb_strlen($ua) > 110 ? '…' : '' ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="device-action">
                    <?php if (!$isRevoked && !$isCurrent): ?>
                        <button class="btn-revoke" onclick="revokeDevice(<?= (int)$d['id'] ?>, this)">撤销</button>
                    <?php elseif ($isCurrent): ?>
                        <span style="font-size:12px;color:#888;">当前设备不可撤销</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <a href="notes.php" class="back-link">← 返回笔记</a>
</div>
<div class="toast" id="toast"></div>
<script>
function showToast(msg, isError) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (isError ? ' error' : '');
    setTimeout(function () { t.className = 'toast' + (isError ? ' error' : ''); }, 2200);
}
function revokeDevice(id, btn) {
    if (!confirm('确定撤销该设备？撤销后该设备需要重新登录。')) return;
    btn.disabled = true;
    var csrf = '<?= htmlspecialchars($csrf_token ?? '') ?>';
    fetch('api.php?action=revokeDevice', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: 'device_id=' + encodeURIComponent(id)
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.message) {
            showToast('已撤销');
            var card = btn.closest('.device-card');
            if (card) {
                card.classList.add('revoked');
                card.querySelector('.device-action').innerHTML = '<span class="badge badge-revoked" style="font-size:12px;">已撤销</span>';
            }
        } else {
            btn.disabled = false;
            showToast(d.error || '撤销失败', true);
        }
    })
    .catch(function () {
        btn.disabled = false;
        showToast('网络错误', true);
    });
}
</script>
<?php
// 复用 notes 的页脚（含 v 版本号）
echo '<div style="text-align:center;padding:30px 0 20px;color:#999;font-size:12px;">轻记 v' . htmlspecialchars(getVersion()) . '</div>';
?>
</body></html>