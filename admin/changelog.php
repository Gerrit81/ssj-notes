<?php
/**
 * 内网记事本 - 更新日志（后台展示）
 */
$changelog = [
    [
        'version' => '1.9.0',
        'date' => '2026-07-04',
        'changes' => [
            '新增自动每日数据库备份功能（24小时触发一次），备份文件存储在 data/backups/，保留最近 30 个',
            '管理后台新增「数据库备份」模块：支持查看备份状态、备份文件列表、一键手动备份',
        ],
    ],
    [
        'version' => '1.8.1',
        'date' => '2026-07-04',
        'changes' => [
            '工具栏按钮重新分组：内容编辑｜笔记操作｜工具外观，三组用分隔线区隔，操作逻辑更清晰',
        ],
    ],
    [
        'version' => '1.8.0',
        'date' => '2026-07-04',
        'changes' => [
            '新增「插入分隔符」按钮，使用 Unicode 制表符 ─ 生成连续横线，视觉上接近真正分割线',
            '新增 Ctrl+D 快捷键快速插入分隔符，提示已加入状态栏右下角',
        ],
    ],
    [
        'version' => '1.7.4',
        'date' => '2026-07-04',
        'changes' => [
            '重写 README.md，新增手动上传 GitHub 教程（无需 Git 工具）、升级指南、完整皮肤预览表',
        ],
    ],
    [
        'version' => '1.7.3',
        'date' => '2026-07-04',
        'changes' => [
            '修复 4 套新增深色皮肤（暗夜绿/暗海蓝/暖夜色/暗网格）切换时后端返回"无效的皮肤选项"的问题',
        ],
    ],
    [
        'version' => '1.7.2',
        'date' => '2026-07-04',
        'changes' => [
            '修复 tooltip 被容器 overflow:hidden 裁剪导致不可见的问题，改为向下弹出并添加三角箭头',
            '补全自动保存、删除笔记、回收站 3 个按钮的自定义 tooltip，所有工具栏按钮统一使用即时提示',
        ],
    ],
    [
        'version' => '1.7.1',
        'date' => '2026-07-04',
        'changes' => [
            '修复下拉菜单在最右侧按钮弹出时被遮挡的问题（智能溢出检测 + 自动右对齐）',
            '工具栏悬停提示改用 CSS 自定义 tooltip，移除原生 title 延迟，鼠标移入即时显示',
        ],
    ],
    [
        'version' => '1.7.0',
        'date' => '2026-07-04',
        'changes' => [
            '新增4套深色护眼皮肤：暗夜绿（终端风格）、暗海蓝（VS Code 风格）、暖夜色（夜间阅读友好）、暗网格（淡紫网格纹理）',
            '编辑器工具栏按钮改为纯图标 + 悬停提示（title），界面更简洁清爽',
            '底部状态栏（字数统计 + 快捷键提示）改为右对齐布局',
            '移除侧边栏排序下拉框，简化侧边栏操作区域',
        ],
    ],
    [
        'version' => '1.6.0',
        'date' => '2026-07-04',
        'changes' => [
            '笔记置顶：重要笔记可置顶，置顶笔记在列表中优先排列并显示 📌 标记',
            '笔记排序：支持按更新时间、创建时间、标题三种排序方式，偏好自动记忆',
            '单篇 TXT 导出：点击「导出」按钮将当前笔记下载为 UTF-8 编码的 .txt 文件',
            '编辑器字数统计：编辑区底部实时显示总字符数和不计空格字符数',
            '键盘快捷键增强：新增 Ctrl+N 新建笔记、Ctrl+F 聚焦搜索框（Ctrl+S 保存）',
            '编辑器底部新增快捷键提示栏',
        ],
    ],
    [
        'version' => '1.5.1',
        'date' => '2026-07-04',
        'changes' => [
            '删除按钮改为常驻红色，危险操作更醒目',
            '编辑器按钮重新排序：字体、字号、自动保存、保存、删除 | 回收站、皮肤',
            '删除与回收站之间增加竖线分隔符',
        ],
    ],
    [
        'version' => '1.5.0',
        'date' => '2026-07-04',
        'changes' => [
            '新增定时自动保存：用户可设置 1/2/3/5/10 分钟间隔或关闭，默认每 3 分钟',
            '自动保存仅保存有变更的笔记（脏状态追踪），未改动不触发请求',
            '自动保存可自动创建新笔记，避免新建后未手动保存导致数据丢失',
            '编辑器头部新增「自动保存」按钮，与字体/字号/皮肤设置并列',
            '自动保存 toast 提示为精简样式，与手动保存视觉区分',
            '所有皮肤（含暗色模式）适配自动保存选择器样式',
        ],
    ],
    [
        'version' => '1.4.0',
        'date' => '2026-07-04',
        'changes' => [
            '新增登录访问统计：记录每次登录的用户名、IP、时间、成功/失败状态',
            '管理后台新增登录访问日志面板，展示最近50条登录记录（时间/用户名/IP/状态/详情）',
            '管理后台新增登录成功/失败统计卡片',
            '支持代理/负载均衡 IP 识别（X-Forwarded-For）',
            '管理后台布局优化：密码修改+回收站设置双列并排，不再各占整行',
            '统计卡片精简为紧凑风格，一排放5个提升信息密度',
            '表单改为更紧凑的横向布局，减少垂直空间浪费',
            '主容器宽度增至 1100px，适配更多内容展示',
        ],
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-07-03',
        'changes' => [
            '搜索框改为侧边栏常驻显示，无需点击按钮即可随时搜索',
            '搜索输入支持 300ms 防抖，减少频繁请求',
            '搜索框新增清除按钮（×）和 Escape 键清空搜索',
            '搜索框 UI 优化：添加搜索图标、更美观的输入框样式',
            '所有皮肤（含暗色模式）适配新搜索框样式',
        ],
    ],
    [
        'version' => '1.2.0',
        'date' => '2026-07-03',
        'changes' => [
            '笔记回收站：删除改为软删除，先移入回收站而非直接删除',
            '回收站面板：查看已删除笔记，显示删除时间和剩余保留天数',
            '回收站操作：支持逐条恢复、逐条彻底删除、一键清空回收站',
            '管理员回收站设置：可配置自动清空天数（默认30天）',
            '过期回收站自动清理：系统随机概率触发巡检清理',
            '登录后默认自动打开最后编辑的笔记',
            '数据库备份脚本 backup.php：定时备份 + 自动清理旧备份',
            '管理后台新增回收站统计卡片',
        ],
    ],
    [
        'version' => '1.1.0',
        'date' => '2026-07-02',
        'changes' => [
            '新增7套护眼皮肤（默认白、护眼绿、暖黄纸、深色模式、牛皮纸、网格白底、网格绿底）',
            '新增字体设置功能（默认、宋体、楷体、仿宋、Consolas、Monaco）',
            '新增字号调整功能（12px-24px）',
            '新增笔记自定义标题功能，侧边栏显示标题名',
            '布局优化：居中显示、左右留白、添加阴影效果',
            '设置按钮移至右上角保存按钮前面',
            '修复侧边栏底部双横线问题',
            '安全加固：会话Cookie添加HttpOnly、Secure、SameSite属性',
            '安全加固：API接口添加CSRF防护',
            '搜索功能支持标题搜索',
        ],
    ],
    [
        'version' => '1.0.1',
        'date' => '2026-07-02',
        'changes' => [
            '管理后台容器宽度加宽至 1000px，布局更舒展',
            '操作列宽度增加，按钮文字不再折行',
            '统计卡片字号加大，数字 28px → 36px',
            '表格单元格内边距增大，行高更舒适',
            '卡片增加边框与阴影层次感',
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-07-02',
        'changes' => [
            '用户注册与登录系统，支持多用户数据隔离',
            '记事本核心功能：新建、编辑、保存、删除笔记',
            '笔记列表分页展示，按更新时间排序',
            '笔记内容搜索功能',
            '管理员后台：查看用户列表、重置用户密码',
            '管理员账号不参与记事，仅负责用户管理',
            '全部图标使用 SVG 绘制，无外部依赖',
            'SQLite 数据库存储，轻量部署',
            'PKCE 安全：CSRF Token、密码哈希、会话管理',
            '操作日志记录',
            '美观的渐变 UI 设计，响应式适配',
        ],
    ],
];

// 版本号与 config.php 保持一致
require_once __DIR__ . '/../config.php';
$current_version = $config['app_version'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>更新日志 - <?= $config['app_name'] ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23667eea'/><rect x='20' y='25' width='60' height='12' rx='2' fill='white' opacity='0.9'/><rect x='20' y='42' width='50' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='54' width='40' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='66' width='55' height='8' rx='2' fill='white' opacity='0.7'/></svg>" type="image/svg+xml">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 40px 24px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 6px;
            color: #667eea;
        }
        .sub {
            color: #888;
            font-size: 14px;
            margin-bottom: 32px;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }
        .version-block {
            position: relative;
            margin-bottom: 32px;
        }
        .version-dot {
            position: absolute;
            left: -26px;
            top: 4px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #667eea;
            border: 3px solid #f5f6fa;
            z-index: 1;
        }
        .version-header {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .version-date {
            font-size: 13px;
            color: #999;
            margin-bottom: 12px;
        }
        .change-list {
            list-style: none;
            padding: 0;
        }
        .change-list li {
            padding: 6px 0;
            font-size: 14px;
            color: #555;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .change-list li::before {
            content: '';
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #667eea;
            margin-top: 7px;
            flex-shrink: 0;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .back-link:hover { opacity: 0.7; }
    </style>
</head>
<body>
<div class="container">
    <a href="javascript:history.back()" class="back-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        返回
    </a>
    <h1>更新日志</h1>
    <p class="sub">当前版本：v<?= $current_version ?></p>

    <div class="timeline">
        <?php foreach ($changelog as $entry): ?>
        <div class="version-block">
            <div class="version-dot"></div>
            <div class="version-header">v<?= $entry['version'] ?></div>
            <div class="version-date"><?= $entry['date'] ?></div>
            <ul class="change-list">
                <?php foreach ($entry['changes'] as $change): ?>
                <li><?= htmlspecialchars($change) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
