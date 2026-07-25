/* Presence — Annuaire participants : recherche et fiche en modal / bottom sheet. */
(function () {
    'use strict';
    var sheet = document.getElementById('participant-sheet');
    var sheetBody = document.getElementById('participant-sheet-body');
    var lastTrigger = null;

    var Annuaire = {
        filter: function () {
            var term = (document.getElementById('psearch').value || '').trim().toLowerCase();
            var cards = document.querySelectorAll('.pcard'), visible = 0;
            cards.forEach(function (c) {
                var show = term === '' || (c.dataset.search || '').indexOf(term) !== -1;
                c.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            var count = document.getElementById('dir-count');
            if (count) count.textContent = visible + ' personne' + (visible > 1 ? 's' : '');
            var nr = document.getElementById('p-noresult');
            if (nr) nr.hidden = visible !== 0 || cards.length === 0;
        },
        open: function (card) {
            if (!sheet || !sheetBody) return;
            lastTrigger = card;
            sheetBody.innerHTML = '<div class="participant-sheet__loading">Chargement de la fiche…</div>';
            sheet.hidden = false;
            document.body.classList.add('sheet-open');
            sheet.querySelector('.participant-sheet__close').focus();

            fetch(card.href, { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var detail = doc.querySelector('.participant-detail');
                    if (!detail) throw new Error('Fiche introuvable');
                    sheetBody.replaceChildren(detail);
                    var name = detail.querySelector('.profile__id h2');
                    document.getElementById('participant-sheet-title').textContent = name ? name.childNodes[0].textContent.trim() : 'Fiche participant';
                })
                .catch(function () {
                    sheetBody.innerHTML = '<div class="participant-sheet__error">Impossible de charger cette fiche. <a href="' + card.href + '">Ouvrir la page complète</a></div>';
                });
        },
        close: function () {
            if (!sheet || sheet.hidden) return;
            sheet.hidden = true;
            document.body.classList.remove('sheet-open');
            sheetBody.textContent = '';
            if (lastTrigger) lastTrigger.focus();
        }
    };
    window.Annuaire = Annuaire;

    document.addEventListener('click', function (event) {
        var card = event.target.closest('.pcard');
        if (card && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            event.preventDefault();
            Annuaire.open(card);
            return;
        }
        if (event.target.closest('.participant-sheet__close') || event.target.closest('.participant-sheet__backdrop')) {
            Annuaire.close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') Annuaire.close();
    });
})();
