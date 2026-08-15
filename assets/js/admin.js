function openPwdModal() {
        document.getElementById('pwdModal').classList.add('show');
        document.getElementById('pwdForm').querySelector('input[name="old_password"]').focus();
    }
    function closePwdModal() {
        document.getElementById('pwdModal').classList.remove('show');
        document.getElementById('pwdForm').reset();
    }
    // 点击遮罩层关闭
    document.getElementById('pwdModal').addEventListener('click', function(e) {
        if (e.target === this) closePwdModal();
    });
    // ESC 关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('pwdModal').classList.contains('show')) {
            closePwdModal();
        }
    });

    function toggleReset(userId) {
        // 关闭另一个表单
        const linkForm = document.getElementById('resetLinkForm_' + userId);
        if (linkForm) linkForm.classList.remove('show');
        const form = document.getElementById('resetForm_' + userId);
        if (form) {
            form.classList.toggle('show');
        }
    }
    function toggleResetLink(userId) {
        // 关闭另一个表单
        const form = document.getElementById('resetForm_' + userId);
        if (form) form.classList.remove('show');
        const linkForm = document.getElementById('resetLinkForm_' + userId);
        if (linkForm) {
            linkForm.classList.toggle('show');
        }
    }

    function copyText(btn) {
        const text = btn.getAttribute('data-copy');
        if (!text) return;
        navigator.clipboard.writeText(text).then(function() {
            const original = btn.textContent;
            btn.textContent = '✓ 已复制';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = original;
                btn.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            const input = document.createElement('textarea');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            alert('已复制到剪贴板');
        });
    }

    function copyLinkUrl(url, btn) {
        navigator.clipboard.writeText(url).then(function() {
            const original = btn.textContent;
            btn.textContent = '✓ 已复制';
            setTimeout(function() {
                btn.textContent = original;
            }, 2000);
        }).catch(function() {
            const input = document.createElement('textarea');
            input.value = url;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            alert('已复制到剪贴板');
        });
    }

    function copyInviteCode(btn) {
        const codeEl = btn.parentElement.querySelector('.invite-code-text');
        const code = codeEl.textContent;
        navigator.clipboard.writeText(code).then(function() {
            const originalText = btn.textContent;
            btn.textContent = '✓ 已复制';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = originalText;
                btn.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            // 兜底：选中文本
            const range = document.createRange();
            range.selectNode(codeEl);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            alert('请按 Ctrl+C 复制邀请码');
        });
    }

    // 重置链接倒计时
    function updateCountdowns() {
        var now = Date.now();
        var elements = document.querySelectorAll('.rl-countdown');
        elements.forEach(function(el) {
            var expireAt = parseInt(el.getAttribute('data-expire'));
            if (!expireAt) return;
            var remaining = expireAt - now;
            if (remaining <= 0) {
                el.textContent = '(已过期)';
                el.style.color = '#cf1322';
                return;
            }
            var mins = Math.floor(remaining / 60000);
            var secs = Math.floor((remaining % 60000) / 1000);
            el.textContent = '剩余 ' + mins + '分' + (secs < 10 ? '0' : '') + secs + '秒';
            if (remaining < 60000) {
                el.style.color = '#cf1322';
            } else if (remaining < 300000) {
                el.style.color = '#fa8c16';
            }
        });
    }
    updateCountdowns();
    setInterval(updateCountdowns, 1000);

    /* ===== 管理员双重认证（2FA，仅针对管理员账号） ===== */
    function open2faModal() {
        document.getElementById('fa2Modal').classList.add('show');
        loadAdmin2faStatus();
    }
    function close2faModal() {
        document.getElementById('fa2Modal').classList.remove('show');
    }
    // 点击遮罩层关闭
    document.getElementById('fa2Modal').addEventListener('click', function(e) {
        if (e.target === this) close2faModal();
    });
    // ESC 关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('fa2Modal').classList.contains('show')) {
            close2faModal();
        }
    });

    function adminCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }
    function adminToast(msg, isError) {
        var el = document.getElementById('adminToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'adminToast';
            el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:8px;font-size:14px;z-index:9999;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.2);transition:opacity .3s;';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.style.background = isError ? 'rgba(207,19,34,0.92)' : 'rgba(0,0,0,0.78)';
        clearTimeout(el._t);
        el._t = setTimeout(function() { el.style.opacity = '0'; setTimeout(function(){ el.style.display = 'none'; }, 300); }, 2600);
        el.style.display = 'block';
        el.style.opacity = '1';
    }

    function loadAdmin2faStatus() {
        var statusEl = document.getElementById('adminFa2Status');
        var bodyEl = document.getElementById('adminFa2Body');
        if (!statusEl || !bodyEl) return;
        fetch('api.php?action=get2faStatus').then(function(r) { return r.json(); }).then(function(data) {
            if (data.enabled) {
                statusEl.textContent = '已开启';
                statusEl.className = 'fa2-badge fa2-on';
                bodyEl.innerHTML =
                    '<div class="fa2-row"><span class="fa2-desc">双重认证已开启，登录后台时需输入密码和验证码。</span></div>' +
                    '<div class="fa2-row fa2-actions"><button type="button" class="fa2-danger" onclick="adminOpen2faDisable()">关闭双重认证</button></div>';
            } else {
                statusEl.textContent = '未开启';
                statusEl.className = 'fa2-badge fa2-off';
                bodyEl.innerHTML =
                    '<div class="fa2-row"><span class="fa2-desc">仅对管理员账号生效，普通账号不受影响。开启后登录后台时除密码外还需输入 6 位动态验证码。</span></div>' +
                    '<div class="fa2-row fa2-actions"><button type="button" class="btn-confirm" onclick="adminStart2faSetup()">开启双重认证</button></div>';
            }
        }).catch(function() {
            statusEl.textContent = '状态获取失败';
            statusEl.className = 'fa2-badge fa2-off';
            bodyEl.innerHTML = '';
        });
    }

    function adminStart2faSetup() {
        var bodyEl = document.getElementById('adminFa2Body');
        if (!bodyEl) return;
        bodyEl.innerHTML = '<div class="fa2-row"><span class="fa2-desc">正在生成密钥…</span></div>';
        var formData = new FormData();
        formData.append('action', 'setup2fa');
        formData.append('csrf_token', adminCsrf());
        fetch('api.php', { method: 'POST', body: formData }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) { adminToast(data.error, true); loadAdmin2faStatus(); return; }
            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=' + encodeURIComponent(data.otpauth_uri);
            bodyEl.innerHTML =
                '<div class="fa2-tip">使用身份验证器应用（Google Authenticator / Microsoft Authenticator / Authy）扫描下方二维码，或手动输入密钥添加。</div>' +
                '<div class="fa2-qr-wrap"><img class="fa2-qr" src="' + qrUrl + '" alt="二维码" onerror="this.style.display=\'none\';document.getElementById(\'adminFa2QrFallback\').style.display=\'block\';">' +
                '<div class="fa2-qr-fallback" id="adminFa2QrFallback" style="display:none;">二维码加载失败，请手动输入下方密钥添加。</div></div>' +
                '<div class="fa2-row"><span class="fa2-label">密钥</span><code class="fa2-secret">' + data.secret + '</code></div>' +
                '<div class="fa2-row"><span class="fa2-label">动态码</span><input type="text" id="adminFa2Code" class="fa2-code" maxlength="6" placeholder="输入 6 位验证码" inputmode="numeric" autocomplete="one-time-code"></div>' +
                '<div class="fa2-row fa2-actions"><button type="button" class="btn-sm" onclick="loadAdmin2faStatus()">取消</button><button type="button" class="btn-confirm" onclick="adminConfirm2faEnable()">确认绑定</button></div>';
            document.getElementById('adminFa2Code').focus();
        }).catch(function() {
            adminToast('网络错误，请重试。', true);
            loadAdmin2faStatus();
        });
    }

    function adminConfirm2faEnable() {
        var code = document.getElementById('adminFa2Code').value.trim();
        if (!/^\d{6}$/.test(code)) { adminToast('请输入 6 位数字验证码。', true); return; }
        var formData = new FormData();
        formData.append('action', 'enable2fa');
        formData.append('csrf_token', adminCsrf());
        formData.append('code', code);
        fetch('api.php', { method: 'POST', body: formData }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) { adminToast(data.error, true); return; }
            var codes = (data.recovery_codes || []).map(function(c) { return '<code class="fa2-rc">' + c + '</code>'; }).join('');
            var bodyEl = document.getElementById('adminFa2Body');
            bodyEl.innerHTML =
                '<div class="fa2-tip fa2-warn">双重认证已开启！请立即保存以下恢复码（每个仅可使用一次），手机丢失时用于恢复登录。</div>' +
                '<div class="fa2-recovery">' + codes + '</div>' +
                '<div class="fa2-row fa2-actions"><button type="button" class="btn-confirm" onclick="loadAdmin2faStatus()">我已保存</button></div>';
            document.getElementById('adminFa2Status').textContent = '已开启';
            document.getElementById('adminFa2Status').className = 'fa2-badge fa2-on';
            adminToast('双重认证已开启');
        }).catch(function() {
            adminToast('网络错误，请重试。', true);
        });
    }

    function adminOpen2faDisable() {
        var bodyEl = document.getElementById('adminFa2Body');
        if (!bodyEl) return;
        bodyEl.innerHTML =
            '<div class="fa2-tip">关闭双重认证需输入当前动态码验证。</div>' +
            '<div class="fa2-row"><span class="fa2-label">动态码</span><input type="text" id="adminFa2DisableCode" class="fa2-code" maxlength="6" placeholder="输入 6 位验证码" inputmode="numeric" autocomplete="one-time-code"></div>' +
            '<div class="fa2-row fa2-actions"><button type="button" class="btn-sm" onclick="loadAdmin2faStatus()">取消</button><button type="button" class="fa2-danger" onclick="adminConfirm2faDisable()">确认关闭</button></div>';
        document.getElementById('adminFa2DisableCode').focus();
    }

    function adminConfirm2faDisable() {
        var code = document.getElementById('adminFa2DisableCode').value.trim();
        if (!/^\d{6}$/.test(code)) { adminToast('请输入 6 位数字验证码。', true); return; }
        var formData = new FormData();
        formData.append('action', 'disable2fa');
        formData.append('csrf_token', adminCsrf());
        formData.append('code', code);
        fetch('api.php', { method: 'POST', body: formData }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) { adminToast(data.error, true); return; }
            adminToast('双重认证已关闭');
            loadAdmin2faStatus();
        }).catch(function() {
            adminToast('网络错误，请重试。', true);
        });
    }
