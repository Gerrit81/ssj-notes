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
