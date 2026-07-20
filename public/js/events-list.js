/* Presence — liste d'événements : recherche + filtre statut (masquage de lignes). */
(function () {
    'use strict';
    var EvList = {
        term: '', status: 'all',
        filter: function () {
            this.term = (document.getElementById('evsearch').value || '').trim().toLowerCase();
            var rows = document.querySelectorAll('.evrow'), visible = 0;
            rows.forEach(function (r) {
                var okStatus = EvList.status === 'all' || r.dataset.status === EvList.status;
                var okTerm = EvList.term === '' || (r.dataset.search || '').indexOf(EvList.term) !== -1;
                var show = okStatus && okTerm;
                r.hidden = !show;
                if (show) visible++;
            });
            document.getElementById('ev-noresult').hidden = visible !== 0 || rows.length === 0;
        },
        status: function (s, btn) {
            this.status = s;
            document.querySelectorAll('.segbar button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
            this.filter();
        }
    };
    window.EvList = EvList;
})();
