/* Presence — Portfolio : recherche + filtre par type (masquage de cartes). */
(function () {
    'use strict';
    var Portfolio = {
        term: '', activeType: 'all',
        filter: function () {
            this.term = (document.getElementById('pfsearch').value || '').trim().toLowerCase();
            var cards = document.querySelectorAll('.pf'), visible = 0;
            cards.forEach(function (c) {
                var okType = Portfolio.activeType === 'all' || c.dataset.type === Portfolio.activeType;
                var okTerm = Portfolio.term === '' || (c.dataset.search || '').indexOf(Portfolio.term) !== -1;
                var show = okType && okTerm;
                c.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            var nr = document.getElementById('pf-noresult');
            if (nr) nr.hidden = visible !== 0 || cards.length === 0;
        },
        type: function (t, btn) {
            this.activeType = t;
            document.querySelectorAll('.chips .chip').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
            this.filter();
        }
    };
    window.Portfolio = Portfolio;
})();
