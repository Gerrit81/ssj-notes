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

    // 定位下拉选择器到按钮下方，自动检测右边界溢出
    function positionSelector(selector, btn) {
        const btnRect = btn.getBoundingClientRect();
        const headerRect = document.getElementById('editorHeader').getBoundingClientRect();
        const selWidth = selector.offsetWidth || 220;
        selector.style.top = 'calc(100% + 8px)';
        // 检测右侧是否溢出容器，若溢出则改为右对齐
        if (btnRect.right - headerRect.left + selWidth > headerRect.width - 4) {
            selector.style.right = '0';
            selector.style.left = 'auto';
        } else {
            selector.style.left = (btnRect.left - headerRect.left) + 'px';
            selector.style.right = 'auto';
        }
    }

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
        document.getElementById('editorContent').focus();
        document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
    }

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
        const sep = '\u2500'.repeat(36) + '\n';
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
