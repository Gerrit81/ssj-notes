# 轻记 - JSBSerect

[![Version](https://img.shields.io/badge/version-1.35.0-blue.svg)](https://github.com)
[![PHP](https://img.shields.io/badge/php-%3E%3D7.4-purple.svg)](https://php.net)
[![SQLite](https://img.shields.io/badge/database-SQLite-orange.svg)](https://sqlite.org)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

一款轻量级、零依赖的个人记事本应用。基于 PHP + SQLite 构建，无需 MySQL、无需 Composer，开箱即用。适合**部署在内网服务器**（Windows Server+phpStudy / 群晖 NAS）或**外网 VPS**（Debian+宝塔），供团队多人使用。

> 🔒 **定位**：安全优先的记事工具——数据 AES-256-GCM 加密存储 + 数据目录外置 + 双重认证，即使数据库文件泄露也无法读取内容。

---

## ✨ 功能特性

### 核心功能
- **✏️ 笔记管理** — 新建、编辑、保存、删除笔记，支持自定义标题
- **🔍 全文搜索** — 侧边栏常驻搜索框，300ms 防抖，支持标题和内容搜索
- **🧹 回收站** — 软删除机制，笔记先移入回收站（默认保留 30 天），支持恢复和彻底删除
- **📌 笔记置顶** — 重要笔记一键置顶，置顶笔记在列表中优先显示
- **💾 定时自动保存** — 可设置 1/2/3/5/10 分钟间隔自动保存，默认每 3 分钟
- **📄 TXT 导出** — 一键导出为 UTF-8 编码的 .txt 文件

### 个性化
- **🖼️ 登录页双风格** — 默认毛玻璃（彩色渐变 + 磨砂卡片） + 备用紫色渐变（`index-alt.php`），轻松切换
- **🎨 10 套护眼皮肤** — 默认白、护眼绿、暖黄纸、暗夜黑、石墨灰、暗夜绿、暖夜色、樱花粉、薰衣草、蜜桃橘
- **🔤 字体切换** — 默认、宋体、楷体、仿宋、Consolas、Monaco 共 6 种字体
- **📐 字号调节** — 12px ~ 24px 自由调节
- **💡 即时 Tooltip** — 工具栏按钮鼠标悬停零延迟提示

### 快捷键
- **⌨️** `Ctrl+F` 搜索 | `Ctrl+S` 保存 | `Ctrl+D` 分隔符 | `Esc` 清空搜索

### 安全
- **🔒 数据加密（v1.35.0）** — 笔记标题、正文、TOTP 密钥以 AES-256-GCM 加密后入库；主密钥为 Web 目录外的随机密钥文件；备份同样加密存储
- **📂 数据目录外置** — 数据库/备份/会话/日志可配置到 Web 根目录之外，杜绝被 URL 直接下载
- **🛡️ 上传鉴权代理** — 图片/PDF 经 `file.php` 鉴权输出，不再有无鉴权静态 URL；已禁用 SVG（防存储型 XSS）
- **👥 多用户隔离** — 每个用户只能访问自己的笔记，数据完全隔离
- **🔐 CSRF 防护** — 所有写操作均验证 CSRF Token
- **🛡️ Cookie 安全** — HttpOnly + SameSite + Secure 属性
- **🔑 密码哈希** — 使用 `password_hash()` 安全存储密码
- **🔢 TOTP 二次认证** — 标准 RFC 6238 动态码，可绑定 Google/Microsoft Authenticator、微信小程序，支持恢复码
- **📋 登录日志** — 记录每次登录的用户名、IP、时间和状态

### 管理后台
- **👤 用户管理** — 查看所有用户列表、重置用户密码
- **⚙️ 回收站设置** — 配置回收站自动清理天数（1-365 天可调）
- **📊 统计面板** — 注册用户、笔记总数、回收站中、成功登录、失败登录
- **📜 登录日志** — 分页浏览登录记录，支持按日期或数量清理

### 运维
- **💿 数据库备份** — `backup.php` 脚本，支持命令行执行和定时任务
- **🪶 极致轻量** — 纯 PHP + SQLite，零 Composer 依赖，部署即用

---

## 🚀 快速开始

### 环境要求
- PHP >= 8.0（7.4 亦可，但不建议）
- SQLite3 扩展（PHP 默认自带）
- MBString、OpenSSL 扩展（OpenSSL 用于 AES-256-GCM 加密，必须启用）

### 部署步骤（通用）

**第 1 步：上传代码**
将所有文件上传到 Web 目录（如 `D:/phpStudy_Pro/WWW/jsbserect/` 或 `/www/wwwroot/jsbserect/`）。**不要上传 `data/` 目录**（运行时自动生成）。

**第 2 步：配置数据目录与密钥路径（关键！）**
编辑 `config.php`，把 `$config['data_dir']` 指向 **Web 根目录之外**：

| 环境 | 示例 |
|---|---|
| 内网 Windows Server + phpStudy | `$config['data_dir'] = 'D:/WebData/jsbserect';` |
| 群晖 NAS + WebStation | `$config['data_dir'] = '/volume1/web_data/jsbserect';` |
| 外网 Debian12 + 宝塔 | `$config['data_dir'] = '/www/wwwroot_data/jsbserect';` |

加密主密钥默认存放在 `data_dir/key/master.key`（**请务必妥善备份**，丢失后已加密数据无法解密）。如需单独指定密钥位置，设置 `$config['enc_key_path']`。

**第 3 步：Web 服务器防护**
详见 [`deploy/`](deploy/) 目录——提供了 Nginx / phpStudy / Apache / 群晖 / 宝塔的防护配置样例，用于禁止浏览器直接访问 `data/` 目录。

**第 4 步：访问**
浏览器访问 `http://your-server/jsbserect/`，首次访问自动初始化数据库并创建管理员账号，然后进入管理后台修改管理员密码、开启双重认证。

> 各环境的详细配置说明：`deploy/synology-webstation.md`（群晖）、`deploy/baota-nginx.md`（宝塔）、`deploy/phpstudy-nginx.txt`（phpStudy）。

---

## 📁 项目结构

```
SSJ/
├── index.php              # 登录/注册页（毛玻璃风格 · 默认）
├── index-alt.php          # 登录页备用（紫色渐变风格）
├── auth.php               # 登录页认证共享逻辑
├── notes.php              # 笔记主页面（编辑器 + 侧边栏）
├── api.php                # RESTful API（前后端数据交互）
├── admin.php              # 管理后台（用户管理、统计、日志）
├── admin/
│   └── changelog.php      # 更新日志展示页
├── crypto.php             # 数据加密模块（AES-256-GCM、密钥管理、备份加解密）
├── file.php               # 上传文件鉴权代理（图片/PDF 鉴权输出）
├── init.php               # 初始化（数据库建表、会话、公共函数、安全自检）
├── config.php             # 配置文件（版本号、数据目录、加密密钥路径）
├── backup.php             # 数据库备份脚本（命令行/定时任务，加密备份）
├── logout.php             # 退出登录
├── deploy/                # 三种生产环境的 Web 服务器防护配置样例
├── data/                  # 数据目录（程序自动创建，生产环境应外置）
│   ├── index.php          # 目录访问保护
│   ├── notes.db           # SQLite 数据库（笔记内容为密文）
│   ├── key/master.key     # 加密主密钥（务必妥善备份！）
│   └── app.log            # 操作日志
├── CHANGELOG.md           # 更新日志
└── README.md              # 项目说明（本文件）
```

---

## 🎨 皮肤预览

| 皮肤 | 描述 | 适用场景 |
|------|------|----------|
| 默认白 | 清爽白色背景 + 紫色主题 | 日常使用 |
| 护眼绿 | 淡绿色背景，降低屏幕刺激 | 长时间写作 |
| 暖黄纸 | 暖黄色背景，模拟纸张 | 文学创作 |
| 暗夜黑 | Catppuccin 风格暗色主题 | 夜间使用 |
| 石墨灰 | 中性灰暗色背景 + 细腻噪点 | 长时间写作 |
| 暗夜绿 | 终端风格暗绿 + 微妙光晕 | 护眼编程 |
| 暖夜色 | 深暖色调，类似夜间阅读模式 | 深夜写作 |
| 樱花粉 | 柔和粉调，甜而不腻 | 轻松随笔 |
| 薰衣草 | 淡紫优雅，温柔宁静 | 文艺创作 |
| 蜜桃橘 | 暖橘甜美，温暖惬意 | 心情日记 |

---

## ⬆️ 升级指南

### 升级到新版本

用新文件覆盖服务器上的旧文件即可，**注意以下规则**：

| 目录/文件 | 是否覆盖 | 说明 |
|-----------|----------|------|
| 所有 `.php` 文件 | ✅ 覆盖 | 程序文件 |
| `.md` 文档文件 | ✅ 覆盖 | 可选 |
| `data/` 目录 | ❌ 不要覆盖 | 含生产数据库和日志 |

> ⚠️ **`data/` 目录绝对不能上传覆盖**，否则会丢失所有用户笔记数据！

---


## 📝 更新日志

详见 [CHANGELOG.md](CHANGELOG.md)

---

## 📄 License

MIT License

---

## ⚠️ 注意事项

- **不要在生产环境开启 `display_errors`**（config.php 中已默认关闭）
- **加密主密钥（`key/master.key`）务必妥善备份**，丢失后所有已加密数据将无法解密；备份数据时应连同密钥一起备份
- **升级时 `data/` 目录绝对不能覆盖**；升级前建议先在旧版本上手动备份（`backup.php`），升级后旧明文备份依然可读，新备份自动为密文
- 管理员账号仅用于用户管理，**不能创建或编辑笔记**
- 删除的笔记在回收站保留 30 天后自动清理，可在管理后台调整
- 建议配置定时任务定期执行 `backup.php`（宝塔：计划任务 → Shell 脚本）
- 部署在外网时请务必配置 HTTPS（宝塔可一键申请免费证书）
- 数据目录外置 + 加密是两道防线，两者都做（见 `deploy/` 与 `config.php` 注释）
- `data/` 目录是运行时自动生成的，上传代码时不要包含此目录
