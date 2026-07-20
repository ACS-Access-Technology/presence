/* Presence — liste d'événements : recherche + filtre statut + bascule grille/tableau. */
(function () {
    'use strict';
    var EvList = {
        term: '', currentStatus: 'all', currentView: 'grid',
        filter: function () {
            this.term = (document.getElementById('evsearch').value || '').trim().toLowerCase();
            var rows = document.querySelectorAll('.ev, .evrow'), visible = 0;
            rows.forEach(function (r) {
                var okStatus = EvList.currentStatus === 'all' || r.dataset.status === EvList.currentStatus;
                var okTerm = EvList.term === '' || (r.dataset.search || '').indexOf(EvList.term) !== -1;
                var show = okStatus && okTerm;
                r.hidden = !show;
                if (show) visible++;
            });
            document.getElementById('ev-noresult').hidden = visible !== 0 || rows.length === 0;
        },
        setStatus: function (s, btn) {
            this.currentStatus = s;
            document.querySelectorAll('.segbar button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
            this.filter();
        },
        setView: function (v) {
            this.currentView = v;
            document.getElementById('ev-grid').hidden = v !== 'grid';
            document.getElementById('ev-tablewrap').hidden = v !== 'list';
            document.getElementById('ev-v-grid').setAttribute('aria-pressed', String(v === 'grid'));
            document.getElementById('ev-v-list').setAttribute('aria-pressed', String(v === 'list'));
            this.filter();
        }
    };
    window.EvList = EvList;
})();
