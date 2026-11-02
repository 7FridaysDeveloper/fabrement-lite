/**
 * MCP Connection (templates/pages/mcp-connection.php). Strings come from PHP as
 * `fabrementMcpConnection.i18n`.
 */
(function () {
    var i18n = (window.fabrementMcpConnection && window.fabrementMcpConnection.i18n) || {};

    var mcpCopyStatusTimer = null;
    document.querySelectorAll('[data-mcp-copy]').forEach(function (copyButton) {
        copyButton.addEventListener('click', async function () {
            var text = document.getElementById(copyButton.getAttribute('data-mcp-copy'));
            if (!text) return;
            var status = document.getElementById('fabrement-oauth-copy-status');
            var copied = false;
            if (navigator.clipboard && window.isSecureContext) {
                try { await navigator.clipboard.writeText(text.value); copied = true; } catch (error) {}
            }
            if (!copied) {
                // A temporary text field copies where the Clipboard API is unavailable.
                var fallback = document.createElement('textarea');
                fallback.value = text.value;
                fallback.style.cssText = 'position:fixed;left:-9999px;top:0;';
                document.body.appendChild(fallback);
                fallback.select();
                try { copied = document.execCommand('copy'); } catch (error) {}
                fallback.remove();
                copyButton.focus();
            }
            if (status) {
                status.textContent = copied
                    ? i18n.copied
                    : i18n.copyFailed;
                clearTimeout(mcpCopyStatusTimer);
                mcpCopyStatusTimer = setTimeout(function () { status.textContent = ''; }, 1500);
            }
            var copyIcon = copyButton.querySelector('.dashicons');
            if (copyIcon) {
                copyIcon.className = copied ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-warning';
                copyButton.dataset.copyState = copied ? 'success' : 'error';
                clearTimeout(copyButton._fabrementIconTimer);
                copyButton._fabrementIconTimer = setTimeout(function () {
                    copyIcon.className = 'dashicons dashicons-admin-page';
                    delete copyButton.dataset.copyState;
                }, 1500);
            }
        });
    });

    function bindConnectionTabs(prefix) {
        var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-' + prefix + '-tab]'));
        var panels = Array.prototype.slice.call(document.querySelectorAll('[data-' + prefix + '-panel]'));
        function activate(tabName) {
            tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-' + prefix + '-tab') === tabName;
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-' + prefix + '-panel') !== tabName;
            });
        }
        tabs.forEach(function (tab, index) {
            if (tab.getAttribute('aria-selected') === 'true') activate(tab.getAttribute('data-' + prefix + '-tab'));
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                activate(tab.getAttribute('data-' + prefix + '-tab'));
            });
            tab.addEventListener('keydown', function (event) {
                if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) return;
                event.preventDefault();
                var offset = event.key === 'ArrowRight' ? 1 : -1;
                var next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs[tabs.length - 1]
                    : tabs[(index + offset + tabs.length) % tabs.length];
                next.focus();
                activate(next.getAttribute('data-' + prefix + '-tab'));
            });
        });
    }
    bindConnectionTabs('setup');

    // Actions that cannot be undone (turn off OAuth, disconnect) ask first. The question
    // rides on the control as data-fabrement-confirm; captured so it runs before the submit/link.
    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-fabrement-confirm]') : null;
        if (el && !window.confirm(el.getAttribute('data-fabrement-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
})();
