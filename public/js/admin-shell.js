/* Presence — interactions globales du shell administrateur. */
(function () {
    'use strict';

    var cfg = window.ADMIN_SHELL || {};
    if (cfg.overlay || !cfg.createUrl) return;

    var sheet = document.getElementById('create-sheet');
    var frame = document.getElementById('create-sheet-frame');
    var lastTrigger = null;

    function open(trigger) {
        if (!sheet || !frame) return;
        lastTrigger = trigger;
        var url = new URL(cfg.createUrl, window.location.origin);
        url.searchParams.set('overlay', '1');
        frame.src = url.toString();
        sheet.hidden = false;
        document.body.classList.add('sheet-open');
        sheet.querySelector('.create-sheet__close').focus();
    }

    function close() {
        if (!sheet || sheet.hidden) return;
        sheet.hidden = true;
        document.body.classList.remove('sheet-open');
        frame.removeAttribute('src');
        if (lastTrigger) lastTrigger.focus();
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (link) {
            var target = new URL(link.href, window.location.origin);
            var create = new URL(cfg.createUrl, window.location.origin);
            if (target.origin === create.origin && target.pathname === create.pathname
                && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
                event.preventDefault();
                open(link);
                return;
            }
        }

        if (event.target.closest('.create-sheet__close') || event.target.closest('.create-sheet__backdrop')) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') close();
    });

    frame.addEventListener('load', function () {
        try {
            var current = new URL(frame.contentWindow.location.href);
            var create = new URL(cfg.createUrl, window.location.origin);
            if (current.pathname !== create.pathname) {
                window.location.href = current.href;
            }
        } catch (_) {
            // L'iframe reste same-origin dans le flux normal ; ne rien faire sinon.
        }
    });
})();
