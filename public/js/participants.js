/* Presence — Annuaire participants : recherche par nom (masquage de cartes). */
(function () {
    'use strict';
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
        }
    };
    window.Annuaire = Annuaire;
})();
