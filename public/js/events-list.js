/* Presence — liste d'événements : recherche + filtre statut + bascule grille/tableau. */
(function () {
    'use strict';
    var CFG = window.EVENTS_LIST || { userId: null };
    var EvList = {
        term: '', currentStatus: 'all', currentView: 'grid', mineOnly: false,
        filter: function () {
            this.term = (document.getElementById('evsearch').value || '').trim().toLowerCase();
            var rows = document.querySelectorAll('.ev, .evrow'), visible = 0;
            rows.forEach(function (r) {
                var okStatus = EvList.currentStatus === 'all' || r.dataset.status === EvList.currentStatus;
                var okTerm = EvList.term === '' || (r.dataset.search || '').indexOf(EvList.term) !== -1;
                var okOwner = !EvList.mineOnly || String(r.dataset.owner) === String(CFG.userId);
                var show = okStatus && okTerm && okOwner;
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
        toggleMine: function (btn) {
            this.mineOnly = !this.mineOnly;
            btn.setAttribute('aria-pressed', String(this.mineOnly));
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
