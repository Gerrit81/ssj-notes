// 状态
    let currentNoteId = null;
    let currentPage = 1;
    let isSearchMode = false;
    let searchKeyword = '';
    let saveTimer = null;
    let currentSkin = document.body.dataset.skin;
    let currentFontFamily = document.body.dataset.fontFamily;
    let currentFontSize = parseInt(document.body.dataset.fontSize) || 15;
    let currentAutoSaveInterval = parseInt(document.body.dataset.autoSaveInterval) || 3;
    let autoSaveTimer = null;
    let isDirty = false;
    let searchTimer = null;
    let currentPinState = false;
    let isPreviewMode = true;

    // 字体映射
    const fontMap = {
        'default': '-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif',
        'song': '"SimSun", "Songti SC", serif',
        'kai': '"KaiTi", "STKaiti", serif',
        'fangsong': '"FangSong", "STFangsong", serif',
        'consolas': '"Consolas", "Monaco", monospace',
        'monaco': '"Monaco", "Consolas", monospace'
    };

    // 关闭管理员重置密码通知
    function acknowledgeReset() {
        const notice = document.getElementById('resetNotice');
        if (notice) {
            notice.style.display = 'none';
        }
        fetch('api.php?action=acknowledgeReset');
    }

    // 打开修改密码弹窗
    function openChangePassword() {
        document.getElementById('pwdOverlay').classList.add('show');
        document.getElementById('pwdError').style.display = 'none';
        document.getElementById('oldPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
        document.getElementById('oldPassword').focus();
        load2faStatus();
    }

    // 关闭修改密码弹窗
    function closeChangePassword() {
        document.getElementById('pwdOverlay').classList.remove('show');
    }

    // 提交修改密码
    async function submitChangePassword() {
        const oldPwd = document.getElementById('oldPassword').value;
        const newPwd = document.getElementById('newPassword').value;
        const confirmPwd = document.getElementById('confirmPassword').value;
        const errEl = document.getElementById('pwdError');

        if (!oldPwd || !newPwd || !confirmPwd) {
            errEl.textContent = '请填写所有密码字段。';
            errEl.style.display = 'block';
            return;
        }
        const pwdMinLen = parseInt(document.body.dataset.passwordMinLength) || 6;
        if (newPwd.length < pwdMinLen) {
            errEl.textContent = '新密码长度不能少于' + pwdMinLen + '位。';
            errEl.style.display = 'block';
            return;
        }
        if (newPwd !== confirmPwd) {
            errEl.textContent = '两次输入的新密码不一致。';
            errEl.style.display = 'block';
            return;
        }
        if (oldPwd === newPwd) {
            errEl.textContent = '新密码不能与旧密码相同。';
            errEl.style.display = 'block';
            return;
        }

        const btn = document.getElementById('btnConfirmPwd');
        btn.disabled = true;
        btn.textContent = '处理中...';
        errEl.style.display = 'none';

        try {
            const formData = new FormData();
            formData.append('action', 'changePassword');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            formData.append('old_password', oldPwd);
            formData.append('new_password', newPwd);

            const resp = await fetch('api.php', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.error) {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
            } else {
                closeChangePassword();
                showToast('密码修改成功', false);
            }
        } catch {
            errEl.textContent = '网络错误，请重试。';
            errEl.style.display = 'block';
        }
        btn.disabled = false;
        btn.textContent = '修改密码';
    }

    // --- 双重认证(2FA) ---

    // 加载 2FA 状态
    async function load2faStatus() {
        const statusEl = document.getElementById('fa2Status');
        const bodyEl = document.getElementById('fa2Body');
        if (!statusEl || !bodyEl) return;
        try {
            const resp = await apiFetch('api.php?action=get2faStatus');
            const data = await resp.json();
            if (data.enabled) {
                statusEl.textContent = '已开启';
                statusEl.className = 'fa2-badge fa2-on';
                bodyEl.innerHTML =
                    '<div class="fa2-row"><span class="fa2-desc">双重认证已开启，登录时需输入密码和验证码。</span></div>' +
                    '<div class="fa2-row fa2-actions"><button class="btn-confirm-pwd fa2-danger" onclick="open2faDisable()">关闭双重认证</button></div>';
            } else {
                statusEl.textContent = '未开启';
                statusEl.className = 'fa2-badge fa2-off';
                bodyEl.innerHTML =
                    '<div class="fa2-row"><span class="fa2-desc">开启后，登录时除密码外还需输入 6 位动态验证码，有效保护账户安全。</span></div>' +
                    '<div class="fa2-row fa2-actions"><button class="btn-confirm-pwd" id="btnEnable2fa" onclick="start2faSetup()">开启双重认证</button></div>';
            }
        } catch {
            statusEl.textContent = '状态获取失败';
            statusEl.className = 'fa2-badge fa2-off';
            bodyEl.innerHTML = '';
        }
    }

    // 开始绑定 2FA（生成密钥 + 恢复码，暂存会话）
    async function start2faSetup() {
        const bodyEl = document.getElementById('fa2Body');
        const btn = document.getElementById('btnEnable2fa');
        if (btn) { btn.disabled = true; btn.textContent = '生成中...'; }
        try {
            const formData = new FormData();
            formData.append('action', 'setup2fa');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const resp = await fetch('api.php', { method: 'POST', body: formData });
            const data = await resp.json();
            if (!resp.ok) {
                showToast(data.error || '生成失败', true);
                load2faStatus();
                return;
            }
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=' + encodeURIComponent(data.otpauth_uri);
            bodyEl.innerHTML =
                '<div class="fa2-tip">使用身份验证器应用（如 Google Authenticator、Microsoft Authenticator）扫描下方二维码，或手动输入密钥添加。</div>' +
                '<div class="fa2-qr-wrap">' +
                '  <img class="fa2-qr" src="' + qrUrl + '" alt="二维码" onerror="this.style.display=\'none\';document.getElementById(\'fa2QrFallback\').style.display=\'block\';">' +
                '  <div class="fa2-qr-fallback" id="fa2QrFallback" style="display:none;">二维码加载失败，请手动输入下方密钥添加。</div>' +
                '</div>' +
                '<div class="fa2-row"><span class="fa2-label">密钥</span><code class="fa2-secret" id="fa2Secret">' + data.secret + '</code></div>' +
                '<div class="fa2-row"><span class="fa2-label">动态码</span><input type="text" id="fa2Code" class="fa2-code" maxlength="6" placeholder="输入 6 位验证码" inputmode="numeric" autocomplete="one-time-code"></div>' +
                '<div class="fa2-row fa2-actions"><button class="btn-cancel" onclick="load2faStatus()">取消</button><button class="btn-confirm-pwd" id="btnConfirm2fa" onclick="confirm2faEnable()">确认绑定</button></div>';
            document.getElementById('fa2Code').focus();
        } catch {
            showToast('网络错误，请重试。', true);
            load2faStatus();
        }
    }

    // 确认绑定 2FA
    async function confirm2faEnable() {
        const code = document.getElementById('fa2Code').value.trim();
        if (!/^\d{6}$/.test(code)) {
            showToast('请输入 6 位数字验证码。', true);
            return;
        }
        const btn = document.getElementById('btnConfirm2fa');
        if (btn) { btn.disabled = true; btn.textContent = '绑定中...'; }
        try {
            const formData = new FormData();
            formData.append('action', 'enable2fa');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            formData.append('code', code);
            const resp = await fetch('api.php', { method: 'POST', body: formData });
            const data = await resp.json();
            if (!resp.ok) {
                showToast(data.error || '绑定失败', true);
                if (btn) { btn.disabled = false; btn.textContent = '确认绑定'; }
                return;
            }
            // 显示恢复码（仅此一次）
            const codes = (data.recovery_codes || []).map(function (c) {
                return '<code class="fa2-rc">' + c + '</code>';
            }).join('');
            const bodyEl = document.getElementById('fa2Body');
            bodyEl.innerHTML =
                '<div class="fa2-tip fa2-warn">双重认证已开启！请立即保存以下恢复码（每个仅可使用一次），用于无法使用验证器时恢复账户登录。</div>' +
                '<div class="fa2-recovery">' + codes + '</div>' +
                '<div class="fa2-row fa2-actions"><button class="btn-confirm-pwd" onclick="load2faStatus()">我已保存</button></div>';
            document.getElementById('fa2Status').textContent = '已开启';
            document.getElementById('fa2Status').className = 'fa2-badge fa2-on';
            showToast('双重认证已开启', false);
        } catch {
            showToast('网络错误，请重试。', true);
            if (btn) { btn.disabled = false; btn.textContent = '确认绑定'; }
        }
    }

    // 打开关闭 2FA 确认
    function open2faDisable() {
        const bodyEl = document.getElementById('fa2Body');
        bodyEl.innerHTML =
            '<div class="fa2-tip">关闭双重认证需验证当前动态码，输入验证码后即可关闭。</div>' +
            '<div class="fa2-row"><span class="fa2-label">动态码</span><input type="text" id="fa2DisableCode" class="fa2-code" maxlength="6" placeholder="输入 6 位验证码" inputmode="numeric" autocomplete="one-time-code"></div>' +
            '<div class="fa2-row fa2-actions"><button class="btn-cancel" onclick="load2faStatus()">取消</button><button class="btn-confirm-pwd fa2-danger" id="btnConfirm2faDisable" onclick="confirm2faDisable()">确认关闭</button></div>';
        document.getElementById('fa2DisableCode').focus();
    }

    // 确认关闭 2FA
    async function confirm2faDisable() {
        const code = document.getElementById('fa2DisableCode').value.trim();
        if (!/^\d{6}$/.test(code)) {
            showToast('请输入 6 位数字验证码。', true);
            return;
        }
        const btn = document.getElementById('btnConfirm2faDisable');
        if (btn) { btn.disabled = true; btn.textContent = '处理中...'; }
        try {
            const formData = new FormData();
            formData.append('action', 'disable2fa');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            formData.append('code', code);
            const resp = await fetch('api.php', { method: 'POST', body: formData });
            const data = await resp.json();
            if (!resp.ok) {
                showToast(data.error || '关闭失败', true);
                if (btn) { btn.disabled = false; btn.textContent = '确认关闭'; }
                return;
            }
            load2faStatus();
            showToast('双重认证已关闭', false);
        } catch {
            showToast('网络错误，请重试。', true);
            if (btn) { btn.disabled = false; btn.textContent = '确认关闭'; }
        }
    }

    // 初始化
    document.addEventListener('DOMContentLoaded', async () => {
        // 排序选择器初始值已移除
        await loadNoteList();
        initSelectors();
        applyFontSettings();
        setupAutoSaveTimer();

        // 监听编辑内容变化，标记脏状态 + 更新字数统计
        const titleEl = document.getElementById('editorTitle');
        const contentEl = document.getElementById('editorContent');
        titleEl.addEventListener('input', () => { isDirty = true; });
        contentEl.addEventListener('input', () => { isDirty = true; updateWordCount(); updateLineNumbers(); });

        // 滚动同步：textarea 滚动时同步行号
        contentEl.addEventListener('scroll', () => {
            document.getElementById('lineNumbers').scrollTop = contentEl.scrollTop;
        });

        // 定时器自动保存相关内容
        titleEl.addEventListener('change', () => { isDirty = true; });
        contentEl.addEventListener('change', () => { isDirty = true; });

        // 默认打开最后编辑的笔记
        await openLastNote();

        // 启动空闲检测（保持登录用户跳过）
        if (!KEEP_LOGIN) {
            startIdleTimer();
        }
    });

    async function openLastNote() {
        try {
            const res = await apiFetch('api.php?action=list&page=1');
            const data = await res.json();
            const notes = data.notes || [];
            if (notes.length > 0) {
                await openNote(notes[0].id);
            }
        } catch (e) {
            // 静默失败
        }
    }

    // 应用字体设置
    function applyFontSettings() {
        const textarea = document.getElementById('editorContent');
        if (textarea) {
            textarea.style.fontFamily = fontMap[currentFontFamily];
            textarea.style.fontSize = currentFontSize + 'px';
            syncLineNumberStyles();
            updateLineNumbers();
        }
    }

    // 同步行号样式（背景色、字体、行高从 textarea 计算值拷贝）
    function syncLineNumberStyles() {
        const ta = document.getElementById('editorContent');
        const ln = document.getElementById('lineNumbers');
        if (!ta || !ln) return;
        const cs = getComputedStyle(ta);
        ln.style.backgroundColor = cs.backgroundColor;
        ln.style.fontSize = cs.fontSize;
        ln.style.lineHeight = cs.lineHeight;
        ln.style.paddingTop = cs.paddingTop;
    }

    // 更新行号（按回车计数，视觉折行部分插入空白行以保持对齐）
    function updateLineNumbers() {
        const ta = document.getElementById('editorContent');
        const ln = document.getElementById('lineNumbers');
        const body = document.querySelector('.editor-body');
        if (!ta || !ln) return;

        const lines = ta.value.split('\n');
        if (lines.length === 0) {
            ln.textContent = '1';
            body.classList.remove('has-content');
            return;
        }

        const cs = getComputedStyle(ta);
        const contentWidth = ta.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        if (contentWidth <= 0) {
            // 宽度尚未就绪，退化为简单计数
            const count = Math.max(lines.length, 1);
            ln.textContent = Array.from({length: count}, (_, i) => i + 1).join('\n');
            return;
        }

        // 用 canvas 测量文字宽度
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.font = cs.font;

        const result = [];
        let num = 1;

        for (const line of lines) {
            if (line === '') {
                result.push(num);
                num++;
            } else {
                const textWidth = ctx.measureText(line).width;
                const visualLines = Math.max(1, Math.ceil(textWidth / contentWidth));
                result.push(num);
                for (let i = 1; i < visualLines; i++) {
                    result.push('');
                }
                num++;
            }
        }

        if (result.length === 0) result.push('1');

        ln.textContent = result.join('\n');

        if (lines.length > 1 || ta.value.length > 0) {
            body.classList.add('has-content');
        } else {
            body.classList.remove('has-content');
        }
    }

    // 初始化选择器
    function initSelectors() {
        document.querySelectorAll('.skin-option').forEach(opt => {
            if (opt.dataset.skin === currentSkin) opt.classList.add('active');
        });
        document.querySelectorAll('.font-option').forEach(opt => {
            if (opt.dataset.font === currentFontFamily) opt.classList.add('active');
        });
        document.querySelectorAll('.size-option').forEach(opt => {
            if (parseInt(opt.dataset.size) === currentFontSize) opt.classList.add('active');
        });
        document.querySelectorAll('.auto-save-option').forEach(opt => {
            if (parseInt(opt.dataset.interval) === currentAutoSaveInterval) opt.classList.add('active');
        });

        document.addEventListener('click', (e) => {
            const selectors = ['fontSelector', 'sizeSelector', 'skinSelector', 'autoSaveSelector'];
            const buttons = ['fontBtn', 'sizeBtn', 'skinBtn', 'autoSaveBtn'];
            
            selectors.forEach((selectorId, index) => {
                const selector = document.getElementById(selectorId);
                const btn = document.getElementById(buttons[index]);
                if (!selector.contains(e.target) && !btn.contains(e.target)) {
                    selector.classList.remove('show');
                }
            });
        });
    }

    // 字体选择器
    function toggleFontSelector() {
        const selector = document.getElementById('fontSelector');
        const btn = document.getElementById('fontBtn');
        positionSelector(selector, btn);
        document.getElementById('sizeSelector').classList.remove('show');
        document.getElementById('skinSelector').classList.remove('show');
        document.getElementById('autoSaveSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    async function changeFont(font) {
        if (font === currentFontFamily) {
            document.getElementById('fontSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('font_family', font);
            formData.append('font_size', currentFontSize);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setFont', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            currentFontFamily = font;
            applyFontSettings();

            document.querySelectorAll('.font-option').forEach(opt => {
                opt.classList.remove('active');
                if (opt.dataset.font === font) opt.classList.add('active');
            });

            document.getElementById('fontSelector').classList.remove('show');
            showToast('字体已切换');
        } catch (e) {
            showToast('切换字体失败', true);
        }
    }

    // 字号选择器
    function toggleSizeSelector() {
        const selector = document.getElementById('sizeSelector');
        const btn = document.getElementById('sizeBtn');
        positionSelector(selector, btn);
        document.getElementById('fontSelector').classList.remove('show');
        document.getElementById('skinSelector').classList.remove('show');
        document.getElementById('autoSaveSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    async function changeSize(size) {
        if (size === currentFontSize) {
            document.getElementById('sizeSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('font_family', currentFontFamily);
            formData.append('font_size', size);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setFont', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            currentFontSize = size;
            applyFontSettings();

            document.querySelectorAll('.size-option').forEach(opt => {
                opt.classList.remove('active');
                if (parseInt(opt.dataset.size) === size) opt.classList.add('active');
            });

            document.getElementById('sizeSelector').classList.remove('show');
            showToast('字号已切换');
        } catch (e) {
            showToast('切换字号失败', true);
        }
    }

    // 皮肤选择器
    function toggleSkinSelector() {
        const selector = document.getElementById('skinSelector');
        const btn = document.getElementById('skinBtn');
        positionSelector(selector, btn);
        document.getElementById('fontSelector').classList.remove('show');
        document.getElementById('sizeSelector').classList.remove('show');
        document.getElementById('autoSaveSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    // 还原选择器到编辑器头部
    function restoreSelectorsHome() {
        var header = document.getElementById('editorHeader');
        ['fontSelector','sizeSelector','skinSelector','autoSaveSelector'].forEach(function (id) {
            var sel = document.getElementById(id);
            if (sel && sel.parentNode !== header) {
                if (sel._mobileMoved) {
                    header.appendChild(sel);
                    sel._mobileMoved = false;
                }
            }
        });
    }

    // 定位下拉选择器到按钮下方，自动检测右边界溢出
    function positionSelector(selector, btn) {
        // 桌面端：还原到 header，恢复定位
        if (window.innerWidth > 767) {
            restoreSelectorsHome();
            selector.style.position = 'absolute';
            selector.style.transform = 'none';
            selector.style.maxWidth = '';
            document.getElementById('selectorOverlay').classList.remove('show');
            const btnRect = btn.getBoundingClientRect();
            const headerRect = document.getElementById('editorHeader').getBoundingClientRect();
            const selWidth = selector.offsetWidth || 220;
            selector.style.top = 'calc(100% + 8px)';
            if (btnRect.right - headerRect.left + selWidth > headerRect.width - 4) {
                selector.style.right = '0';
                selector.style.left = 'auto';
            } else {
                selector.style.left = (btnRect.left - headerRect.left) + 'px';
                selector.style.right = 'auto';
            }
            return;
        }

        // 移动端：预测 toggle 结果（positionSelector 在 toggle 前被调用）
        var willShown = !selector.classList.contains('show');
        if (willShown) {
            // 移到 body 下，跳出 .editor-area 的层叠上下文（z-index: 10）
            if (selector.parentNode !== document.body) {
                selector._mobileMoved = true;
                document.body.appendChild(selector);
            }
            selector.style.position = 'fixed';
            selector.style.left = '50%';
            selector.style.top = '50%';
            selector.style.transform = 'translate(-50%, -50%)';
            selector.style.right = 'auto';
            selector.style.width = '';
            selector.style.maxWidth = 'calc(100vw - 40px)';
            document.getElementById('selectorOverlay').classList.add('show');
        } else {
            // 即将隐藏：收回遮罩
            document.getElementById('selectorOverlay').classList.remove('show');
        }
    }

    // 关闭所有选择器（也用于遮罩层点击）
    window.closeAllSelectors = function () {
        ['fontSelector','sizeSelector','skinSelector','autoSaveSelector'].forEach(function (id) {
            document.getElementById(id).classList.remove('show');
        });
        restoreSelectorsHome();
        document.getElementById('selectorOverlay').classList.remove('show');
    };

    async function changeSkin(skin) {
        if (skin === currentSkin) {
            document.getElementById('skinSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('skin', skin);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setSkin', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            document.body.className = 'skin-' + skin;
            currentSkin = skin;

            document.querySelectorAll('.skin-option').forEach(opt => {
                opt.classList.remove('active');
                if (opt.dataset.skin === skin) opt.classList.add('active');
            });

            document.getElementById('skinSelector').classList.remove('show');
            syncLineNumberStyles();
            loadNoteList();
            showToast('皮肤已切换');
        } catch (e) {
            showToast('切换皮肤失败', true);
        }
    }

    // 自动保存选择器
    function toggleAutoSaveSelector() {
        const selector = document.getElementById('autoSaveSelector');
        const btn = document.getElementById('autoSaveBtn');
        positionSelector(selector, btn);
        document.getElementById('fontSelector').classList.remove('show');
        document.getElementById('sizeSelector').classList.remove('show');
        document.getElementById('skinSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    async function changeAutoSave(interval) {
        if (interval === currentAutoSaveInterval) {
            document.getElementById('autoSaveSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('interval', interval);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setAutoSave', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            currentAutoSaveInterval = interval;
            setupAutoSaveTimer();

            document.querySelectorAll('.auto-save-option').forEach(opt => {
                opt.classList.remove('active');
                if (parseInt(opt.dataset.interval) === interval) opt.classList.add('active');
            });

            document.getElementById('autoSaveSelector').classList.remove('show');
            showToast(data.message || '自动保存设置成功');
        } catch (e) {
            showToast('设置失败', true);
        }
    }

    // 启动/重启自动保存定时器
    function setupAutoSaveTimer() {
        stopAutoSaveTimer();
        if (currentAutoSaveInterval > 0) {
            autoSaveTimer = setInterval(autoSaveTick, currentAutoSaveInterval * 60000);
        }
    }

    function stopAutoSaveTimer() {
        if (autoSaveTimer) {
            clearInterval(autoSaveTimer);
            autoSaveTimer = null;
        }
    }

    function autoSaveTick() {
        if (!isDirty) return;
        // 当前无笔记或有笔记但未保存过一次也允许自动保存（创建新笔记）
        if (!currentNoteId) {
            const content = document.getElementById('editorContent').value.trim();
            const title = document.getElementById('editorTitle').value.trim();
            if (!content && !title) return; // 空白不自动创建
        }
        saveNote(true);
    }

    // Toast
    function showToast(msg, isError = false) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast' + (isError ? ' error' : '') + ' show';
        clearTimeout(t._timeout);
        t._timeout = setTimeout(() => { t.className = 'toast'; }, 2000);
    }

    // 加载笔记列表
    async function loadNoteList() {
        try {
            const url = isSearchMode
                ? `api.php?action=search&q=${encodeURIComponent(searchKeyword)}`
                : `api.php?action=list&page=${currentPage}`;
            const res = await apiFetch(url);
            const data = await res.json();
            renderNoteList(data);
            renderPagination(data);
            updateSearchInfo(data);
        } catch (e) {
            showToast('加载失败: ' + e.message, true);
        }
    }

    function renderNoteList(data) {
        const container = document.getElementById('noteList');
        const notes = data.notes || [];

        if (notes.length === 0) {
            container.innerHTML = `<div class="note-item empty">
                ${isSearchMode ? '未找到匹配的笔记' : '暂无笔记，点击上方 + 新建'}
            </div>`;
            return;
        }

        container.innerHTML = notes.map(n => {
            const active = n.id == currentNoteId ? ' active' : '';
            const updated = n.updated_at || n.created_at;
            const time = updated ? updated.replace('T', ' ').substring(0, 16) : '';
            const hasTitle = n.title && trim(n.title).length > 0;
            const pinMark = (n.is_pinned == 1) ? '<span class="pin-badge" title="已置顶">📌 </span>' : '';
            let displayText = hasTitle 
                ? escapeHtml(trim(n.title))
                : escapeHtml(n.preview || n.content || '(空笔记)');
            if (isSearchMode && searchKeyword) {
                const re = new RegExp(escapeRegex(searchKeyword), 'gi');
                displayText = displayText.replace(re, '<mark>$&</mark>');
            }
            const titleClass = hasTitle ? 'note-title' : 'preview';
            return `<div class="note-item${active}${n.is_pinned == 1 ? ' pinned' : ''}" onclick="openNote(${n.id})">
                <div class="${titleClass}">${pinMark}${displayText}</div>
                <div class="meta">${time}</div>
            </div>`;
        }).join('');
    }

    function renderPagination(data) {
        const pagination = document.getElementById('pagination');
        const footer = document.querySelector('.sidebar-footer');
        const borderColor = document.body.classList.contains('skin-dark') ? '#313244' : '#f0f0f0';
        if (isSearchMode) {
            pagination.style.display = 'none';
            footer.style.borderTop = `1px solid ${borderColor}`;
            return;
        }
        const page = data.page || 1;
        const pages = data.pages || 1;
        if (pages <= 1) {
            pagination.style.display = 'none';
            footer.style.borderTop = `1px solid ${borderColor}`;
            return;
        }
        pagination.style.display = 'flex';
        footer.style.borderTop = 'none';
        pagination.innerHTML = `
            <button ${page <= 1 ? 'disabled' : ''} onclick="goPage(${page-1})">上一页</button>
            <span>${page} / ${pages}</span>
            <button ${page >= pages ? 'disabled' : ''} onclick="goPage(${page+1})">下一页</button>
        `;
    }

    function updateSearchInfo(data) {
        const info = document.getElementById('searchInfo');
        if (isSearchMode) {
            const count = (data.notes || []).length;
            info.textContent = `搜索 "${searchKeyword}"，共 ${count} 条结果`;
            info.classList.add('show');
        } else {
            info.classList.remove('show');
        }
    }

    function goPage(p) {
        currentPage = p;
        loadNoteList();
    }

    // 搜索（300ms 防抖）
    function doSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const kw = document.getElementById('searchInput').value.trim();
            const clearBtn = document.getElementById('searchClear');
            searchKeyword = kw;
            if (kw === '') {
                clearBtn.classList.remove('show');
                isSearchMode = false;
                currentPage = 1;
                loadNoteList();
            } else {
                clearBtn.classList.add('show');
                isSearchMode = true;
                loadNoteList();
            }
        }, 300);
    }

    function handleSearchKey(e) {
        if (e.key === 'Escape') {
            clearSearch();
        }
    }

    function clearSearch() {
        const input = document.getElementById('searchInput');
        input.value = '';
        document.getElementById('searchClear').classList.remove('show');
        isSearchMode = false;
        searchKeyword = '';
        currentPage = 1;
        loadNoteList();
    }

    // 新建笔记
    function createNote() {
        currentNoteId = null;
        isDirty = false;
        currentPinState = false;
        updatePinButton();
        document.getElementById('editorTitle').value = '';
        document.getElementById('editorContent').value = '';
        updateWordCount();
        updateLineNumbers();
        switchToEditMode();
        document.getElementById('editorContent').focus();
        document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
        // 移动端：切换到编辑器视图
        if (window.innerWidth <= 767) {
            document.querySelector('.app-body').classList.add('editor-active');
        }
    }

    // 移动端：返回侧边栏
    window.showSidebar = function () {
        // 自动保存后再切换
        if (isDirty && currentNoteId) saveNote(true);
        document.querySelector('.app-body').classList.remove('editor-active');
    };

    // 移动端：切换功能面板
    window.toggleMobilePanel = function () {
        const overlay = document.getElementById('mobileActionsOverlay');
        const panel = document.getElementById('mobileActionsPanel');
        if (!overlay || !panel) return;
        const isOpen = panel.classList.contains('show');
        if (isOpen) {
            overlay.classList.remove('show');
            panel.classList.remove('show');
        } else {
            overlay.classList.add('show');
            panel.classList.add('show');
        }
    };

    // 打开笔记
    async function openNote(id) {
        try {
            const res = await apiFetch(`api.php?action=get&id=${id}`);
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            currentNoteId = data.id;
            isDirty = false;
            currentPinState = (data.is_pinned == 1);
            updatePinButton();
            document.getElementById('editorTitle').value = data.title || '';
            document.getElementById('editorContent').value = data.content || '';
            updateWordCount();
            updateLineNumbers();
            switchToPreviewMode();
            // 移动端：切换到编辑器视图
            if (window.innerWidth <= 767) {
                document.querySelector('.app-body').classList.add('editor-active');
            }
            document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
            const items = document.querySelectorAll('.note-item');
            items.forEach(el => {
                if (el.onclick && el.onclick.toString().includes(`openNote(${id})`)) {
                    el.classList.add('active');
                }
            });
        } catch (e) {
            showToast('加载笔记失败', true);
        }
    }

    // 保存笔记（silent=true 表示自动保存，不弹 toast）
    async function saveNote(silent = false) {
        const title = document.getElementById('editorTitle').value.trim();
        const content = document.getElementById('editorContent').value;
        const formData = new FormData();
        formData.append('title', title);
        formData.append('content', content);
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        if (currentNoteId) {
            formData.append('id', currentNoteId);
        }

        try {
            const res = await apiFetch('api.php?action=save', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                if (!silent) showToast(data.error, true);
                return;
            }
            if (!currentNoteId) {
                currentNoteId = data.id;
            }
            isDirty = false;
            if (!silent) {
                showToast(data.message || '保存成功');
            } else {
                // 自动保存：精简 toast
                showToast('已自动保存');
            }
            loadNoteList();
        } catch (e) {
            if (!silent) showToast('保存失败: ' + e.message, true);
        }
    }

    // 键盘快捷键
    document.addEventListener('keydown', function(e) {
        // Ctrl+S / Cmd+S：保存
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveNote();
            return;
        }
        // Ctrl+F / Cmd+F：聚焦搜索框
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
            document.getElementById('searchInput').select();
            return;
        }
        // Ctrl+D / Cmd+D：插入分隔符
        if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
            e.preventDefault();
            insertSeparator();
            return;
        }
        // Ctrl+B：加粗
        if ((e.ctrlKey || e.metaKey) && e.key === 'b' && !isPreviewMode) {
            e.preventDefault();
            insertMd('**', '**');
            return;
        }
        // Ctrl+I：斜体
        if ((e.ctrlKey || e.metaKey) && e.key === 'i' && !isPreviewMode) {
            e.preventDefault();
            insertMd('*', '*');
            return;
        }
        // Esc：关闭弹窗/灯箱
        if (e.key === 'Escape') {
            var lb = document.getElementById('lightbox');
            var im = document.getElementById('imageModal');
            if (lb && lb.classList.contains('show')) {
                closeLightbox();
                return;
            }
            if (im && im.style.display === 'flex') {
                closeImageModal();
                return;
            }
        }
    });

    // 确认删除对话框
    let pendingDeleteId = null;

    function confirmDelete() {
        if (!currentNoteId) return;
        pendingDeleteId = currentNoteId;
        document.getElementById('confirmText').textContent = `确定删除这条笔记吗？删除后可在回收站中找回。`;
        document.getElementById('confirmOverlay').classList.add('show');
    }

    function closeConfirm() {
        pendingDeleteId = null;
        document.getElementById('confirmOverlay').classList.remove('show');
    }

    document.getElementById('confirmBtn').addEventListener('click', async function() {
        if (!pendingDeleteId) return;
        try {
            const formData = new FormData();
            formData.append('id', pendingDeleteId);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=delete', { method: 'POST', body: formData });
            const data = await res.json();
            closeConfirm();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('已移入回收站');
            currentNoteId = null;
            document.getElementById('editorTitle').value = '';
            document.getElementById('editorContent').value = '';
            loadNoteList();
        } catch (e) {
            closeConfirm();
            showToast('删除失败', true);
        }
    });

    // ===== 回收站 =====
    async function openTrash() {
        document.getElementById('trashOverlay').classList.add('show');
        await loadTrash();
    }

    function closeTrash() {
        document.getElementById('trashOverlay').classList.remove('show');
    }

    document.getElementById('trashOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeTrash();
    });

    // ===== 附件管理 =====
    var attachmentFiles = []; // 缓存附件列表

    async function openAttachPanel() {
        document.getElementById('attachOverlay').classList.add('show');
        await loadAttachments();
    }

    function closeAttachPanel() {
        document.getElementById('attachOverlay').classList.remove('show');
    }

    document.getElementById('attachOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeAttachPanel();
    });

    // ===== 分享链接管理 =====
    function openSharePanel() {
        document.getElementById('shareOverlay').classList.add('show');
    }

    function closeSharePanel() {
        document.getElementById('shareOverlay').classList.remove('show');
    }

    document.getElementById('shareOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeSharePanel();
    });

    function copyShareLink(btn) {
        var input = btn.parentElement.querySelector('input[type="text"]');
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 99999);
        var ok = false;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(function() {
                showToast('链接已复制到剪贴板');
            }).catch(function() { ok = false; fallbackCopyShare(input); });
        } else {
            fallbackCopyShare(input);
        }
        function fallbackCopyShare(el) {
            try {
                document.execCommand('copy');
                showToast('链接已复制到剪贴板');
            } catch (e) {
                showToast('复制失败，请手动选择复制', true);
            }
        }
    }

    // 生成/吊销后有提示时自动打开分享面板
    if (document.body.getAttribute('data-share-flash') === '1') {
        openSharePanel();
    }

    async function loadAttachments() {
        var body = document.getElementById('attachBody');
        body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#999;">加载中...</div>';
        try {
            var res = await apiFetch('api.php?action=listImages');
            var data = await res.json();
            attachmentFiles = data.files || [];
            renderAttachments();
        } catch (e) {
            body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#ff4d4f;">加载失败：' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderAttachments() {
        var body = document.getElementById('attachBody');
        if (attachmentFiles.length === 0) {
            body.innerHTML = '<div style="text-align:center;padding:40px 20px;color:#999;">' +
                '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' +
                '<p style="margin-top:12px;">还没有上传过文件</p></div>';
            return;
        }
        var html = '';
        attachmentFiles.forEach(function(file, i) {
            var isPdf = /\.pdf$/i.test(file.filename);
            var thumbHtml = '';
            if (isPdf) {
                // PDF 缩略图占位
                thumbHtml = '<div class="attach-thumb attach-pdf-thumb">' +
                    '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#e74c3c" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' +
                    '<span style="font-size:10px;">PDF</span></div>';
            } else {
                thumbHtml = '<img class="attach-thumb" src="' + escapeAttr(toFileUrl(file.path)) + '" alt="" loading="lazy" onclick="event.stopPropagation();openLightbox(\'' + escapeAttr(toFileUrl(file.path)) + '\', \'image\')">';
            }
            var refHtml = file.referenced
                ? '<span style="color:#52c41a;">已引用</span>'
                : '<span style="color:#fa8c16;">未引用</span>';
            var alt = isPdf ? file.filename.replace(/\.pdf$/i, '') : (file.filename.replace(/\.[^.]+$/, '') || '图片');
            html += '<div class="attach-item">' +
                '<div class="attach-preview">' + thumbHtml + '</div>' +
                '<div class="attach-info">' +
                '<div class="attach-name" title="' + escapeAttr(file.filename) + '">' + escapeHtml(file.filename) + '</div>' +
                '<div class="attach-meta">' + file.sizeStr + ' &middot; ' + file.time + ' &middot; ' + refHtml + '</div>' +
                '</div>' +
                '<div class="attach-actions" style="display:flex;gap:6px;">' +
                (isPdf
                    ? '<button class="btn-sm attach-insert-btn" onclick="insertAttachmentToEditor(' + i + ')" title="插入 PDF 到文章">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' +
                    '</button>'
                    : '<button class="btn-sm attach-insert-btn" onclick="showAttachSizePopup(event,' + i + ')" title="插入图片到文章（可选择尺寸）">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' +
                    '</button>') +
                '<button class="btn-sm attach-del-btn" onclick="deleteAttachment(' + i + ')" title="删除文件">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '</button>' +
                '</div>' +
                '</div>';
        });
        body.innerHTML = html;
    }

    // 将附件插入到当前编辑的文章
    // size: 'l'(大), 'm'(中/默认), 's'(小) — 仅对图片生效
    function insertAttachmentToEditor(i, size) {
        size = size || 'm';
        var file = attachmentFiles[i];
        if (!file) return;
        var isPdf = /\.pdf$/i.test(file.filename);
        var alt = isPdf
            ? file.filename.replace(/\.pdf$/i, '')
            : file.filename.replace(/\.[^.]+$/, '') || '图片';
        var sizeSuffix = !isPdf ? ('{' + size + '}') : '';
        var md = '![' + alt + '](' + file.path + ')' + sizeSuffix;
        var ta = document.getElementById('editorContent');
        if (!ta) { showToast('请先选择一篇笔记进入编辑模式', true); return; }
        var start = ta.selectionStart;
        var prefix = (start > 0 && ta.value.charAt(start - 1) !== '\n') ? '\n\n' : '';
        ta.value = ta.value.substring(0, start) + prefix + md + '\n' + ta.value.substring(start);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + prefix.length + md.length + 1;
        ta.dispatchEvent(new Event('input'));
        // 关闭面板
        closeAttachPanel();
        var sizeLabel = {l:'大', m:'中', s:'小'}[size] || '';
        showToast('已插入' + (isPdf ? ' PDF' : ('图片(' + sizeLabel + ')')));
    }

    // 附件面板中点击插入按钮时，对图片显示尺寸选择弹出层
    var attachPendingIndex = -1;

    function showAttachSizePopup(event, i) {
        event.stopPropagation();
        var btn = event.currentTarget;
        var popup = document.getElementById('attachSizePopup');
        attachPendingIndex = i;

        // 定位弹出层到按钮上方
        var rect = btn.getBoundingClientRect();
        var panelRect = document.getElementById('attachOverlay').getBoundingClientRect();
        popup.style.left = (rect.left - panelRect.left + rect.width / 2 - 80) + 'px';
        popup.style.top = (rect.top - panelRect.top - 46) + 'px';
        popup.classList.add('show');

        // 高亮当前默认尺寸
        popup.querySelectorAll('.attach-size-opt').forEach(function(opt) {
            opt.classList.toggle('attach-size-active', opt.dataset.size === 'm');
        });
    }

    function hideAttachSizePopup() {
        document.getElementById('attachSizePopup').classList.remove('show');
        attachPendingIndex = -1;
    }

    // 弹出层按钮点击 → 执行插入
    document.getElementById('attachSizePopup').addEventListener('click', function(e) {
        var opt = e.target.closest('.attach-size-opt');
        if (!opt || attachPendingIndex < 0) return;
        e.stopPropagation();
        insertAttachmentToEditor(attachPendingIndex, opt.dataset.size);
        hideAttachSizePopup();
    });

    // 点击弹出层外的任意位置关闭
    document.addEventListener('click', function(e) {
        var popup = document.getElementById('attachSizePopup');
        if (popup.classList.contains('show') && !popup.contains(e.target) && !e.target.closest('.attach-insert-btn')) {
            hideAttachSizePopup();
        }
    });

    async function deleteAttachment(i) {
        var file = attachmentFiles[i];
        if (!file) return;
        var warn = file.referenced ? '\n\n注意：该文件仍在笔记中被引用！' : '';
        if (!confirm('确定删除「' + file.filename + '」吗？此操作不可恢复。' + warn)) return;
        try {
            var formData = new FormData();
            formData.append('action', 'deleteImage');
            formData.append('path', file.path);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            var res = await fetch('api.php', { method: 'POST', body: formData });
            var data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('已删除');
            // 从列表中移除
            attachmentFiles.splice(i, 1);
            renderAttachments();
        } catch (e) {
            showToast('删除失败：' + e.message, true);
        }
    }

    async function loadTrash() {
        try {
            const res = await apiFetch('api.php?action=trash');
            const data = await res.json();
            renderTrash(data);
        } catch (e) {
            showToast('加载回收站失败', true);
        }
    }

    function renderTrash(data) {
        const notes = data.notes || [];
        const countEl = document.getElementById('trashCount');
        const body = document.getElementById('trashBody');

        countEl.textContent = `(${data.total || 0} 条)`;

        if (notes.length === 0) {
            body.innerHTML = `<div class="trash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>回收站空空如也</p>
            </div>`;
            return;
        }

        body.innerHTML = notes.map(n => {
            const title = escapeHtml(n.preview || '(空笔记)');
            const deletedTime = (n.deleted_at || '').replace('T', ' ').substring(0, 16);
            const remaining = n.remaining || '';
            const urgentClass = n.remaining_days > 0 && n.remaining_days <= 3 ? ' urgent' : '';
            return `<div class="trash-item" id="trashItem_${n.id}">
                <div class="trash-info">
                    <div class="trash-title">${title}</div>
                    <div class="trash-meta">
                        <span>删除于 ${deletedTime}</span>
                        <span class="remaining${urgentClass}">${remaining}</span>
                    </div>
                </div>
                <div class="trash-btns">
                    <button class="btn-restore" onclick="restoreNote(${n.id})">恢复</button>
                    <button class="btn-perm-delete" onclick="permanentDelete(${n.id})">彻底删除</button>
                </div>
            </div>`;
        }).join('');
    }

    async function restoreNote(id) {
        try {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=restore', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('笔记已恢复');
            // 移除该项
            const item = document.getElementById('trashItem_' + id);
            if (item) item.remove();
            // 更新计数
            const remaining = document.querySelectorAll('.trash-item').length;
            document.getElementById('trashCount').textContent = `(${remaining} 条)`;
            if (remaining === 0) {
                document.getElementById('trashBody').innerHTML = `<div class="trash-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p>回收站空空如也</p>
                </div>`;
            }
            loadNoteList();
        } catch (e) {
            showToast('恢复失败', true);
        }
    }

    async function permanentDelete(id) {
        if (!confirm('确定彻底删除这条笔记吗？此操作不可撤销。')) return;
        try {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=permanent_delete', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('已彻底删除');
            const item = document.getElementById('trashItem_' + id);
            if (item) item.remove();
            const remaining = document.querySelectorAll('.trash-item').length;
            document.getElementById('trashCount').textContent = `(${remaining} 条)`;
            if (remaining === 0) {
                document.getElementById('trashBody').innerHTML = `<div class="trash-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p>回收站空空如也</p>
                </div>`;
            }
        } catch (e) {
            showToast('操作失败', true);
        }
    }

    async function emptyTrash() {
        if (!confirm('确定清空回收站吗？所有笔记将被彻底删除，不可恢复。')) return;
        try {
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=emptyTrash', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast(data.message || '回收站已清空');
            document.getElementById('trashCount').textContent = '(0 条)';
            document.getElementById('trashBody').innerHTML = `<div class="trash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>回收站空空如也</p>
            </div>`;
        } catch (e) {
            showToast('操作失败', true);
        }
    }

    // ===== 工具函数 =====
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function trim(str) {
        return (str || '').replace(/^\s+|\s+$/g, '');
    }

    // ===== 字数统计 =====
    function updateWordCount() {
        const content = document.getElementById('editorContent').value;
        document.getElementById('charCount').textContent = content.length;
        document.getElementById('charCountNoSpace').textContent = content.replace(/\s/g, '').length;
    }

    // ===== 插入分隔符 =====
    function insertSeparator() {
        const ta = document.getElementById('editorContent');
        if (!ta) return;
        const sep = '---\n';
        const start = ta.selectionStart;
        const end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + sep + ta.value.substring(end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + sep.length;
        ta.dispatchEvent(new Event('input'));
    }

    // ===== 导出 TXT =====
    function exportTXT() {
        if (!currentNoteId) {
            showToast('请先选择或保存一条笔记', true);
            return;
        }
        const title = document.getElementById('editorTitle').value.trim() || '未命名笔记';
        const content = document.getElementById('editorContent').value;
        const text = title + '\n' + '='.repeat(40) + '\n\n' + content;
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = title.replace(/[\\/:*?"<>|]/g, '_') + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('已导出 TXT 文件');
    }

    // ===== 置顶/取消置顶 =====
    async function togglePin() {
        if (!currentNoteId) {
            showToast('请先选择一条笔记', true);
            return;
        }
        const newState = currentPinState ? 0 : 1;
        try {
            const formData = new FormData();
            formData.append('id', currentNoteId);
            formData.append('pinned', newState);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=togglePin', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            currentPinState = (newState === 1);
            updatePinButton();
            showToast(data.message);
            loadNoteList();
        } catch (e) {
            showToast('操作失败', true);
        }
    }

    function updatePinButton() {
        const btn = document.getElementById('pinBtn');
        if (currentPinState) {
            btn.classList.add('pinned');
            btn.setAttribute('data-tooltip', '取消置顶');
            btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>`;
        } else {
            btn.classList.remove('pinned');
            btn.setAttribute('data-tooltip', '置顶笔记');
            btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>`;
        }
    }

    // ===== 会话超时管理（客户端空闲计时器，秒级精度） =====

    const KEEP_LOGIN = document.body.dataset.keepLogin === '1';
    const SESSION_TIMEOUT_MINUTES = KEEP_LOGIN ? 0 : (parseInt(document.querySelector('meta[name="session-timeout"]').content) || 30);
    const IDLE_LIMIT = SESSION_TIMEOUT_MINUTES * 60; // 空闲秒数上限

    let sessionExpired = false;
    let idleSeconds = 0;
    let lastActivityTime = Date.now();
    let idleTimer = null;
    const countdownEl = document.getElementById('logoutCountdown');

    // 基于真实时间戳同步空闲秒数（不受后台限速影响）
    function syncIdle() {
        idleSeconds = Math.floor((Date.now() - lastActivityTime) / 1000);
    }

    // 更新倒计时显示
    function updateCountdown() {
        if (!countdownEl || SESSION_TIMEOUT_MINUTES <= 0) return;
        const remaining = IDLE_LIMIT - idleSeconds;
        if (remaining <= 0) {
            countdownEl.textContent = '空闲超时：0秒';
            countdownEl.className = 'logout-countdown danger';
            return;
        }
        if (remaining <= 300) { // 5分钟内警告
            countdownEl.className = 'logout-countdown warning';
        } else {
            countdownEl.className = 'logout-countdown';
        }
        if (remaining <= 60) {
            countdownEl.textContent = '空闲超时：' + remaining + '秒';
        } else {
            countdownEl.textContent = '空闲超时：' + Math.ceil(remaining / 60) + '分';
        }
    }

    // 任何键鼠/触屏操作 → 空闲计时归零
    function resetIdle() {
        lastActivityTime = Date.now();
        idleSeconds = 0;
        updateCountdown();
    }
    ['keydown', 'mousedown', 'mousemove', 'scroll', 'touchstart', 'input', 'click'].forEach(function(evt) {
        document.addEventListener(evt, resetIdle, { passive: true } );
    });

    // 会话过期：立即跳转，杜绝内容泄漏
    function handleSessionExpired() {
        if (sessionExpired) return;
        sessionExpired = true;
        stopAutoSaveTimer();
        stopIdleTimer();
        window.location.href = 'index.php?timeout=1';
    }

    // API 请求包装：自动检测 401
    async function apiFetch(url, options = {}) {
        const res = await fetch(url, options);
        if (res.status === 401) {
            handleSessionExpired();
            throw new Error('SESSION_EXPIRED');
        }
        return res;
    }

    // 每秒检查空闲计时（基于时间戳，不依赖定时器精度）
    function idleTick() {
        if (sessionExpired) return;
        syncIdle();
        updateCountdown();
        if (idleSeconds >= IDLE_LIMIT) {
            handleSessionExpired();
        }
    }

    // 启动空闲检测
    function startIdleTimer() {
        stopIdleTimer();
        lastActivityTime = Date.now();
        idleSeconds = 0;
        updateCountdown();
        idleTimer = setInterval(idleTick, 1000);
    }

    // 停止空闲检测
    function stopIdleTimer() {
        if (idleTimer) {
            clearInterval(idleTimer);
            idleTimer = null;
        }
    }

    // 窗口缩放时重新计算行号（折行宽度变化）
    let resizeDebounce = null;
    window.addEventListener('resize', function() {
        if (resizeDebounce) clearTimeout(resizeDebounce);
        resizeDebounce = setTimeout(() => updateLineNumbers(), 150);
    });

    // bfcache 恢复（保持登录用户跳过重载，恢复即可）
    window.addEventListener('pageshow', function(e) {
        if (e.persisted && !sessionExpired && !KEEP_LOGIN) {
            loadNoteList();
        }
    });

    // 标签页切回 → 基于真实时间戳同步，超时则立即登出（保持登录用户跳过）
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && !sessionExpired && !KEEP_LOGIN) {
            syncIdle();
            updateCountdown();
            if (idleSeconds >= IDLE_LIMIT) {
                handleSessionExpired();
            }
        }
    });

    // ===== Markdown 编辑/预览模式 =====

    function togglePreview() {
        if (isPreviewMode) {
            switchToEditMode();
        } else {
            switchToPreviewMode();
        }
    }

    var savedEditScrollTop = 0;

    function switchToEditMode() {
        isPreviewMode = false;
        var ta = document.getElementById('editorContent');
        ta.style.display = '';
        document.getElementById('previewContent').style.display = 'none';
        document.getElementById('lineNumbers').style.display = '';
        document.getElementById('mdToolbar').style.display = '';
        document.getElementById('insertImageBtn').style.display = '';
        document.getElementById('previewIconEdit').style.display = '';
        document.getElementById('previewIconView').style.display = 'none';
        document.getElementById('previewToggleBtn').setAttribute('data-tooltip', '切换预览');
        ta.scrollTop = savedEditScrollTop;
        ta.focus();
        updateLineNumbers();
    }

    function switchToPreviewMode() {
        isPreviewMode = true;
        var ta = document.getElementById('editorContent');
        savedEditScrollTop = ta.scrollTop;
        if (isDirty) saveNote(true);
        renderMarkdown();
        document.getElementById('editorContent').style.display = 'none';
        document.getElementById('previewContent').style.display = '';
        document.getElementById('lineNumbers').style.display = 'none';
        document.getElementById('mdToolbar').style.display = 'none';
        document.getElementById('insertImageBtn').style.display = 'none';
        document.getElementById('previewIconEdit').style.display = 'none';
        document.getElementById('previewIconView').style.display = '';
        document.getElementById('previewToggleBtn').setAttribute('data-tooltip', '切换编辑');
    }

    function renderMarkdown() {
        var container = document.getElementById('previewContent');
        var text = document.getElementById('editorContent').value;

        if (!text.trim()) {
            container.innerHTML = '<div class="preview-empty">暂无内容，点击 ✏ 编辑 开始写作</div>';
            return;
        }

        var html = text;
        html = escapeMd(html);

        // 代码块（先占位，避免其中的 URL 被裸 URL 识别误处理）
        var mdCodeBlocks = [];
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, function(m, lang, code) {
            mdCodeBlocks.push('<pre><code>' + code.trim() + '</code></pre>');
            return '\x01MDBLOCK' + (mdCodeBlocks.length - 1) + '\x01';
        });

        // 图片/PDF ![alt](url){s|m|l} → 块级展示，{s}=小 {m}=中 默认=大
        html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)(\{([sml])\})?/g, function(m, alt, url, sizeToken, size) {
            var a = escapeAttr(alt || '');
            var u = escapeAttr(toFileUrl(url));
            var sizeClass = '';
            if (size === 's') sizeClass = ' md-img-s';
            else if (size === 'm') sizeClass = ' md-img-m';
            // 'l' or no token = full width (default)
            var isPdf = /\.pdf(\?.*)?$/i.test(u);
            if (isPdf) {
                // PDF 渲染为图标块（尺寸对 PDF 不生效）
                var pdfName = a || decodeURIComponent((u.split('/').pop() || 'PDF文档').replace(/\?.*$/, ''));
                return '<div class="md-image-block md-pdf-block" data-pdf-url="' + u + '" data-file-type="pdf">' +
                    '<div class="md-pdf-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#e74c3c" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>' +
                    '<div class="md-pdf-name">' + escapeHtml(pdfName) + '</div>' +
                    '<div class="md-pdf-hint">点击预览 PDF</div>' +
                    '</div>';
            }
            // 图片
            return '<div class="md-image-block' + sizeClass + '">' +
                '<img src="' + u + '" alt="' + (a || '图片') + '" loading="lazy" onclick="event.stopPropagation();openLightbox(\'' + u + '\', \'image\')">' +
                (a ? '<div class="md-image-caption">' + escapeHtml(a) + '</div>' : '') +
                '</div>';
        });

        // 标题
        html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // 引用 — 先将每行标记为占位标签，再合并连续的为一个 blockquote
        html = html.replace(/^&gt;\s?(.+)$/gm, '<!--bq-->$1<!--/bq-->');
        html = html.replace(/((?:<!--bq-->.*?<!--\/bq-->\n?)+)/g, function(m) {
            var inner = m.replace(/<!--bq-->(.*?)<!--\/bq-->\n?/g, '$1<br>');
            return '<blockquote>' + inner.replace(/<br>$/, '') + '</blockquote>';
        });

        // 分隔线
        html = html.replace(/^(-{3,}|\*{3,})$/gm, '<hr>');

        // 无序列表
        html = html.replace(/^[\-\*]\s+(.+)$/gm, '<li>$1</li>');
        html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, function(m) {
            if (m.indexOf('<li>') !== -1) return '<ul>' + m + '</ul>';
            return m;
        });

        // 有序列表
        html = html.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');

        // 加粗 **text**
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

        // 斜体 *text*
        html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');

        // 行内代码 `code`（先占位，避免其中的 URL 被裸 URL 识别误处理）
        var mdCodes = [];
        html = html.replace(/`([^`]+)`/g, function(m, code) {
            mdCodes.push('<code>' + code + '</code>');
            return '\x01MDCODE' + (mdCodes.length - 1) + '\x01';
        });

        // 链接 [text](url)（先占位，避免 href 被裸 URL 识别二次处理）
        var mdLinks = [];
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(m, text, url) {
            mdLinks.push('<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener">' + text + '</a>');
            return '\x01MDLINK' + (mdLinks.length - 1) + '\x01';
        });

        // 临时保护已生成的 HTML 标签（如图片 src 外链），避免裸 URL 误匹配标签属性
        var mdTags = [];
        html = html.replace(/<[^>]+>/g, function(m) {
            mdTags.push(m);
            return '\x01MDTAG' + (mdTags.length - 1) + '\x01';
        });

        // 自动识别裸 URL（http/https/ftp、www. 开头），点击新窗口打开
        html = html.replace(/(?<![\w])https?:\/\/[^\s<>"')]+|(?<![\w])www\.[^\s<>"')]+/gi, function(m) {
            // 去掉尾部常见标点（句号/逗号/感叹号/问号/分号/冒号）
            m = m.replace(/[.,!?;:]+$/, '');
            if (!m) return '';
            var href = /^www\./i.test(m) ? 'http://' + m : m;
            return '<a href="' + escapeAttr(href) + '" target="_blank" rel="noopener">' + m + '</a>';
        });

        // 段落
        html = html.replace(/\n\n+/g, '</p><p>');
        html = '<p>' + html + '</p>';

        // 还原占位符（在修复嵌套之前，保证块级元素正确脱离 <p>）
        html = html.replace(/\x01MDTAG(\d+)\x01/g, function(m, i) { return mdTags[+i]; });
        html = html.replace(/\x01MDLINK(\d+)\x01/g, function(m, i) { return mdLinks[+i]; });
        html = html.replace(/\x01MDCODE(\d+)\x01/g, function(m, i) { return mdCodes[+i]; });
        html = html.replace(/\x01MDBLOCK(\d+)\x01/g, function(m, i) { return mdCodeBlocks[+i]; });

        // 修复嵌套
        html = html.replace(/<p><(h[1-3]|ul|ol|blockquote|pre|hr|div)/g, '<$1');
        html = html.replace(/<\/(h[1-3]|ul|ol|blockquote|pre|div)>(\n?)<\/p>/g, '</$1>');
        html = html.replace(/<hr>(\n?)<\/p>/g, '<hr>');
        html = html.replace(/<p>\s*<\/p>/g, '');
        html = html.replace(/\n/g, '<br>');

        container.innerHTML = html;
    }

    // Markdown 快捷插入
    function insertMd(before, after) {
        var ta = document.getElementById('editorContent');
        var start = ta.selectionStart;
        var end = ta.selectionEnd;
        var sel = ta.value.substring(start, end);

        // 块级标记（before 以 \n 开头，如引用 >、列表 -/1.）：多行选中时每行单独添加前缀
        if (before.indexOf('\n') === 0 && sel.includes('\n')) {
            var prefix = before.substring(1); // 去掉前导 \n，得到实际标记如 '> '、'- '、'1. '
            var lines = sel.split('\n');
            for (var i = 0; i < lines.length; i++) {
                lines[i] = prefix + lines[i] + after;
            }
            var result = lines.join('\n');
            ta.setRangeText(result, start, end, 'select');
            ta.setSelectionRange(start + result.length, start + result.length);
            ta.focus();
            ta.dispatchEvent(new Event('input'));
            return;
        }

        // 单行或行内操作（加粗、斜体、行内代码等）
        ta.setRangeText(before + sel + after, start, end, 'select');
        var offset = before.indexOf('\n') === 0 ? 1 : 0;
        var cursorPos = start + before.length + sel.length + after.length - offset;
        ta.setSelectionRange(cursorPos, cursorPos);
        ta.focus();
        ta.dispatchEvent(new Event('input'));
    }

    // ===== 图片插入弹窗 =====

    var localImagePreviewUrl = '';

    function openImageModal() {
        document.getElementById('imageModal').style.display = 'flex';
        document.getElementById('imgUrl').value = '';
        document.getElementById('imgAlt').value = '';
        document.getElementById('imgFile').value = '';
        document.getElementById('imgPreview').style.display = 'none';
        document.getElementById('fileName').textContent = '未选择文件';
        document.getElementById('fileName').classList.remove('has-file');
        localImagePreviewUrl = '';
        document.getElementById('imgUrl').focus();
    }

    function closeImageModal() {
        document.getElementById('imageModal').style.display = 'none';
    }

    document.getElementById('imgUrl').addEventListener('input', function() {
        var url = this.value.trim();
        var img = document.getElementById('imgPreview');
        if (url) {
            img.src = url;
            img.style.display = 'block';
        } else {
            img.style.display = 'none';
        }
    });

    document.getElementById('imgFile').addEventListener('change', function() {
        var file = this.files[0];
        var nameEl = document.getElementById('fileName');
        if (!file) {
            nameEl.textContent = '未选择文件';
            nameEl.classList.remove('has-file');
            return;
        }
        nameEl.textContent = file.name;
        nameEl.classList.add('has-file');
        // 先判断文件类型
        var isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
        localImagePreviewUrl = ''; // 先清除，PDF 无法预览
        var img = document.getElementById('imgPreview');
        if (isPdf) {
            // PDF：显示占位提示
            img.style.display = 'none';
        } else {
            var reader = new FileReader();
            reader.onload = function(e) {
                localImagePreviewUrl = e.target.result;
                img.src = localImagePreviewUrl;
                img.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
        // 自动上传到服务器
        uploadImageFile(file);
    });

    function insertImageMd() {
        var urlInput = document.getElementById('imgUrl');
        var url = urlInput.value.trim();
        // 禁止将 base64 写入文章（会导致数据库膨胀），必须等上传成功或手动输入 URL
        if (!url && localImagePreviewUrl) {
            showToast('图片正在上传中，请稍候再点插入', true);
            return;
        }
        if (!url && !localImagePreviewUrl) {
            showToast('请先输入图片 URL 或选择本地文件上传', true);
            return;
        }
        if (!url) {
            showToast('文件上传中，请等待上传完成后再插入', true);
            return;
        }
        var isPdf = /\.pdf(\?.*)?$/i.test(url);
        var alt = document.getElementById('imgAlt').value.trim() || (isPdf ? 'PDF文档' : '图片');
        // 读取尺寸选择
        var sizeRadio = document.querySelector('input[name="imgSize"]:checked');
        var sizeSuffix = '';
        if (!isPdf && sizeRadio) {
            sizeSuffix = '{' + sizeRadio.value + '}';
        }
        var md = '![' + alt + '](' + url + ')' + sizeSuffix;
        var ta = document.getElementById('editorContent');
        var start = ta.selectionStart;
        var prefix = (start > 0 && ta.value.charAt(start - 1) !== '\n') ? '\n\n' : '';
        ta.value = ta.value.substring(0, start) + prefix + md + '\n' + ta.value.substring(start);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + prefix.length + md.length + 1;
        ta.dispatchEvent(new Event('input'));
        closeImageModal();
        updateLineNumbers();
    }

    // 上传图片到服务器
    async function uploadImageFile(file) {
        var progress = document.getElementById('uploadProgress');
        var progressFill = document.getElementById('uploadProgressFill');
        var urlInput = document.getElementById('imgUrl');

        progress.style.display = 'block';
        progressFill.style.width = '20%';

        var formData = new FormData();
        formData.append('image', file);
        formData.append('action', 'uploadImage');
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

        try {
            var res = await fetch('api.php', {
                method: 'POST',
                body: formData,
            });
            progressFill.style.width = '80%';
            var data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                progress.style.display = 'none';
                progressFill.style.width = '0';
                // 上传失败后清除本地预览，防止用户误将 base64 插入文章
                localImagePreviewUrl = '';
                return;
            }
            progressFill.style.width = '100%';
            // 填入返回的 URL
            urlInput.value = data.url;
            if (data.type === 'pdf') {
                // PDF 上传成功，显示图标提示
                document.getElementById('imgPreview').style.display = 'none';
                showToast('PDF 上传成功');
            } else {
                document.getElementById('imgPreview').src = toFileUrl(data.url);
                document.getElementById('imgPreview').style.display = 'block';
                showToast('上传成功');
            }
            // 延迟隐藏进度条
            setTimeout(function() {
                progress.style.display = 'none';
                progressFill.style.width = '0';
            }, 800);
        } catch (e) {
            progress.style.display = 'none';
            progressFill.style.width = '0';
            // 上传失败后清除本地预览，防止用户误将 base64 插入文章
            localImagePreviewUrl = '';
            showToast('上传失败：' + e.message, true);
        }
    }

    // Esc 关闭弹窗和灯箱 - 已合并到上方键盘快捷键处理
    // （空 - 保留占位）

    // 弹窗遮罩点击关闭
    (function() {
        var modal = document.getElementById('imageModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeImageModal();
            });
        }
    })();

    // ===== 灯箱（图片 / PDF） =====
    var lbScale = 1, lbPanX = 0, lbPanY = 0;
    var lbPanning = false, lbPanStartX = 0, lbPanStartY = 0;
    var lbDragged = false;

    function openLightbox(url, fileType) {
        fileType = fileType || 'image';
        var img = document.getElementById('lightboxImg');
        var pdf = document.getElementById('lightboxPdf');
        var lb = document.getElementById('lightbox');
        var tb = document.getElementById('lightboxToolbar');

        // 重置缩放状态
        lbScale = 1; lbPanX = 0; lbPanY = 0;
        img.classList.remove('no-transition', 'zoomed', 'panning');

        if (fileType === 'pdf') {
            // 移动端：用新标签页打开，利用浏览器原生 PDF 阅读器
            if (window.innerWidth <= 767) {
                window.open(url, '_blank');
                return;
            }
            tb.style.display = 'none';
            img.style.display = 'none';
            img.style.transform = '';
            pdf.src = url;
            pdf.style.display = 'block';
        } else {
            tb.style.display = 'flex';
            pdf.style.display = 'none';
            pdf.src = '';
            img.src = url;
            img.style.display = 'block';

            // 入场缩放动画：0.7 → 1
            img.style.transform = 'scale(0.7)';
            img.getBoundingClientRect(); // 强制 reflow
            img.style.transform = 'scale(1)';
            updateLbScaleText();
        }

        lb.classList.add('show');
    }

    function closeLightbox() {
        var lb = document.getElementById('lightbox');
        var img = document.getElementById('lightboxImg');

        // 出场缩放动画：当前 → 0.7
        img.classList.remove('no-transition', 'zoomed', 'panning');
        img.style.transform = 'scale(0.7)';

        setTimeout(function () {
            lb.classList.remove('show');
            lbScale = 1; lbPanX = 0; lbPanY = 0;
            img.style.transform = '';
            img.src = '';
            document.getElementById('lightboxPdf').src = '';
        }, 360);
    }

    // ---- 缩放辅助函数 ----
    function applyLbTransform(img) {
        if (lbPanX === 0 && lbPanY === 0) {
            img.style.transform = 'scale(' + lbScale + ')';
        } else {
            // translate 在 scale 之后应用，需要除以 scale 补偿
            img.style.transform = 'scale(' + lbScale + ') translate(' + (lbPanX / lbScale) + 'px, ' + (lbPanY / lbScale) + 'px)';
        }
    }

    function updateLbScaleText() {
        var el = document.getElementById('lbScaleText');
        if (el) el.textContent = Math.round(lbScale * 100) + '%';
    }

    function updateLbZoomCursor() {
        var img = document.getElementById('lightboxImg');
        if (lbScale > 1.01) {
            img.classList.add('zoomed');
        } else {
            img.classList.remove('zoomed', 'panning');
        }
    }

    // ---- 工具按钮 ----
    window.lbZoomIn = function () {
        lbScale = Math.min(10, Math.round((lbScale + 0.5) * 10) / 10);
        var img = document.getElementById('lightboxImg');
        img.classList.add('no-transition');
        applyLbTransform(img);
        updateLbScaleText();
        updateLbZoomCursor();
    };

    window.lbZoomOut = function () {
        lbScale = Math.max(0.15, Math.round((lbScale - 0.5) * 10) / 10);
        var img = document.getElementById('lightboxImg');
        if (lbScale <= 1.01) { lbScale = 1; lbPanX = 0; lbPanY = 0; }
        img.classList.add('no-transition');
        applyLbTransform(img);
        updateLbScaleText();
        updateLbZoomCursor();
    };

    window.lbOneToOne = function () {
        var img = document.getElementById('lightboxImg');
        if (!img.naturalWidth || !img.width) return;
        lbScale = img.naturalWidth / img.width;
        lbPanX = 0; lbPanY = 0;
        img.classList.add('no-transition');
        applyLbTransform(img);
        updateLbScaleText();
        updateLbZoomCursor();
    };

    window.lbFit = function () {
        lbScale = 1; lbPanX = 0; lbPanY = 0;
        var img = document.getElementById('lightboxImg');
        img.classList.add('no-transition');
        img.style.transform = 'scale(1)';
        img.classList.remove('zoomed', 'panning');
        updateLbScaleText();
        // 下一帧恢复过渡，供后续动画使用
        requestAnimationFrame(function () { img.classList.remove('no-transition'); });
    };

    // ---- 滚轮缩放 ----
    document.addEventListener('wheel', function (e) {
        var lb = document.getElementById('lightbox');
        if (!lb || !lb.classList.contains('show')) return;
        var img = document.getElementById('lightboxImg');
        if (!img || img.style.display === 'none') return;

        var rect = lb.getBoundingClientRect();
        if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) return;

        e.preventDefault();
        e.stopPropagation();

        var delta = e.deltaY > 0 ? -0.1 : 0.1;
        lbScale = Math.max(0.15, Math.min(10, Math.round((lbScale + delta) * 10) / 10));
        if (lbScale <= 1.01) { lbScale = 1; lbPanX = 0; lbPanY = 0; }

        img.classList.add('no-transition');
        applyLbTransform(img);
        updateLbScaleText();
        updateLbZoomCursor();
    }, { passive: false });

    // ---- 拖拽平移 ----
    document.getElementById('lightboxImg').addEventListener('mousedown', function (e) {
        if (lbScale <= 1.01) return;
        e.preventDefault();
        lbPanning = true;
        lbDragged = false;
        lbPanStartX = e.clientX - lbPanX;
        lbPanStartY = e.clientY - lbPanY;
        this.classList.add('panning');
    });

    document.addEventListener('mousemove', function (e) {
        if (!lbPanning) return;
        var dx = e.clientX - lbPanStartX;
        var dy = e.clientY - lbPanStartY;
        if (Math.abs(dx) > 3 || Math.abs(dy) > 3) lbDragged = true;
        lbPanX = dx;
        lbPanY = dy;
        var img = document.getElementById('lightboxImg');
        applyLbTransform(img);
    });

    document.addEventListener('mouseup', function () {
        if (!lbPanning) return;
        lbPanning = false;
        var img = document.getElementById('lightboxImg');
        if (img) img.classList.remove('panning');
    });

    // 图片单击/双击区分：单击关闭灯箱，双击切换 1:1/适应
    var lbClickTimer = null;
    document.getElementById('lightboxImg').addEventListener('click', function (e) {
        var lb = document.getElementById('lightbox');
        if (!lb || !lb.classList.contains('show')) return;

        // 拖拽后忽略 click
        if (lbDragged) {
            lbDragged = false;
            e.stopPropagation();
            return;
        }

        if (lbClickTimer) {
            // 是双击
            clearTimeout(lbClickTimer);
            lbClickTimer = null;
            e.stopPropagation();
            if (lbScale > 1.01) {
                lbFit();
            } else {
                lbOneToOne();
            }
        } else {
            // 第一次点击，等 300ms 确认不是双击
            e.stopPropagation();
            lbClickTimer = setTimeout(function () {
                lbClickTimer = null;
                closeLightbox();
            }, 300);
        }
    });

    // ===== 预览区点击图片/PDF 弹窗 =====
    document.getElementById('previewContent').addEventListener('click', function(e) {
        // 图片
        var img = e.target.closest('.md-image-block img');
        if (img && img.src) {
            openLightbox(img.src, 'image');
            return;
        }
        // PDF
        var pdfBlock = e.target.closest('.md-pdf-block');
        if (pdfBlock) {
            var pdfUrl = pdfBlock.getAttribute('data-pdf-url');
            if (pdfUrl) {
                openLightbox(pdfUrl, 'pdf');
            }
        }
    });

    // ===== HTML 转义 =====

    function escapeMd(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escapeAttr(str) {
        return (str || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // v1.35.0：上传文件统一走 file.php 鉴权代理，防止无鉴权静态访问
    // 存储与插入编辑器始终使用原始相对路径 data/uploads/...，仅在渲染/预览时转换
    function toFileUrl(u) {
        if (!u) return u;
        if (u.indexOf('file.php?f=') === 0) return u;
        if (u.indexOf('data/uploads/') === 0) return 'file.php?f=' + u;
        return u;
    }
