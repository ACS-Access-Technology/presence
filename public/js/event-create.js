/* ============================================================
   Presence — Nouvel événement : aperçu live + combobox d'invitation
   (recherche du référentiel Personnel ACS Groupe, débattue côté serveur).
   ============================================================ */
(function () {
    'use strict';
    var CFG = window.EVENT_CREATE || { searchUrl: '', types: [] };
    var $ = function (s) { return document.querySelector(s); };
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    function fmtDate(v) {
        if (!v) return null;
        var p = v.split('-').map(Number);
        return p[2] + ' ' + MONTHS[p[1] - 1] + ' ' + p[0];
    }

    function sync() {
        var title = $('#title').value.trim();
        var pvt = $('#pv-title');
        if (title) { pvt.textContent = title; pvt.classList.remove('empty'); }
        else { pvt.textContent = 'Titre de l’événement'; pvt.classList.add('empty'); }

        $('#pv-date').textContent = fmtDate($('#date').value) || '—';
        var s = $('#start').value, e = $('#end').value;
        $('#pv-time').textContent = (s && e) ? (s + ' – ' + e) : (s || e || '—');

        var lieu = $('#lieu').value.trim();
        var lw = $('#pv-lieu-wrap');
        if (lieu) { lw.hidden = false; $('#pv-lieu').textContent = lieu; } else { lw.hidden = true; }

        var checked = document.querySelector('input[name=event_type_id]:checked');
        var pt = $('#pv-type');
        if (checked) {
            var type = CFG.types.find(function (t) { return String(t.id) === checked.value; });
            if (type) {
                pt.style.setProperty('--tc', type.color);
                pt.style.color = 'var(--tc)';
                pt.style.background = 'color-mix(in srgb, var(--tc) 14%, transparent)';
                pt.textContent = type.name;
            }
        }

        var mode = document.querySelector('input[name=qr_mode]:checked');
        $('#pv-mode').textContent = (mode && mode.value === 'statique') ? 'QR statique' : 'QR tournant';
    }

    ['input', 'change'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            if (e.target.closest('form')) sync();
        });
    });
    sync();

    /* ---------- Séances additionnelles (sessions multiples) ---------- */
    var EventForm = {
        count: 0,
        addSeance: function () {
            var i = this.count++;
            var box = $('#seances-extra');
            var row = document.createElement('div');
            row.className = 'grid3';
            row.id = 'seance-' + i;
            row.style.marginTop = '10px';
            row.innerHTML =
                '<div class="field"><label>Date <span class="req">*</span></label>' +
                '<input class="control" type="date" name="extra_seances[' + i + '][date]" value="' + esc($('#date').value) + '" required></div>' +
                '<div class="field"><label>Heure de début <span class="req">*</span></label>' +
                '<input class="control" type="time" name="extra_seances[' + i + '][start]" value="' + esc($('#start').value) + '" required></div>' +
                '<div class="field" style="position:relative"><label>Heure de fin <span class="req">*</span></label>' +
                '<input class="control" type="time" name="extra_seances[' + i + '][end]" value="' + esc($('#end').value) + '" required>' +
                '<button type="button" class="mini mini--danger" style="position:absolute;top:0;right:0" title="Retirer cette séance" onclick="EventForm.removeSeance(' + i + ')">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>';
            box.appendChild(row);
            $('#seances-help').hidden = false;
        },
        removeSeance: function (i) {
            var row = $('#seance-' + i);
            if (row) row.remove();
            if (!$('#seances-extra').children.length) $('#seances-help').hidden = true;
        }
    };
    window.EventForm = EventForm;

    /* ---------- Combobox d'invitation ---------- */
    var Invites = {
        selected: [],
        timer: null,

        filter: function (q) {
            var pop = $('#inv-results');
            clearTimeout(this.timer);
            if (!CFG.searchUrl) return;
            this.timer = setTimeout(function () {
                var url = CFG.searchUrl + '?q=' + encodeURIComponent(q || '');
                fetch(url, { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { Invites.render(data.people || [], q); })
                    .catch(function () { pop.innerHTML = '<div class="combo__empty">Recherche indisponible.</div>'; pop.hidden = false; });
            }, 200);
        },

        render: function (people, q) {
            var pop = $('#inv-results');
            var pool = people.filter(function (p) { return !Invites.selected.some(function (s) { return s.id === p.id; }); });
            if (!pool.length) {
                pop.innerHTML = '<div class="combo__empty">' + (q ? 'Aucune personne ne correspond à « ' + esc(q) + ' ».' : 'Toutes les personnes du référentiel sont déjà invitées.') + '</div>';
            } else {
                pop.innerHTML = pool.map(function (p) {
                    return '<button type="button" class="combo__opt" role="option" data-id="' + p.id + '">' +
                        '<span class="av">' + esc(p.initials) + '</span>' +
                        '<span><span class="combo__opt__t">' + esc(p.name) + '</span><br><span class="combo__opt__d">' + esc(p.detail) + '</span></span>' +
                        '</button>';
                }).join('');
                pop.querySelectorAll('.combo__opt').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var p = people.find(function (x) { return String(x.id) === btn.dataset.id; });
                        if (p) Invites.add(p);
                    });
                });
            }
            pop.hidden = false;
            $('#inv-search').setAttribute('aria-expanded', 'true');
        },

        add: function (p) {
            this.selected.push(p);
            var inp = $('#inv-search'); inp.value = ''; inp.focus();
            this.filter('');
            this.paint();
        },

        remove: function (id) {
            this.selected = this.selected.filter(function (x) { return x.id !== id; });
            this.paint();
            this.filter($('#inv-search').value);
        },

        paint: function () {
            var box = $('#inv-selected');
            box.innerHTML = this.selected.map(function (p) {
                return '<span class="chip-p"><span class="av">' + esc(p.initials) + '</span>' +
                    '<span><span class="chip-p__t">' + esc(p.name) + '</span><br><span class="chip-p__d">' + esc(p.detail) + '</span></span>' +
                    '<button type="button" class="chip-p__x" data-id="' + p.id + '" aria-label="Retirer ' + esc(p.name) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg></button></span>';
            }).join('');
            box.querySelectorAll('.chip-p__x').forEach(function (btn) {
                btn.addEventListener('click', function () { Invites.remove(Number(btn.dataset.id)); });
            });

            var n = this.selected.length;
            var c = $('#inv-count'); c.hidden = n === 0;
            c.innerHTML = '<b>' + n + '</b> personne' + (n > 1 ? 's' : '') + ' invitée' + (n > 1 ? 's' : '');
            var pw = $('#pv-inv-wrap');
            if (n > 0) { pw.hidden = false; $('#pv-inv').textContent = n + ' invité' + (n > 1 ? 's' : ''); } else pw.hidden = true;

            $('#inv-inputs').innerHTML = this.selected.map(function (p) {
                return '<input type="hidden" name="invitees[]" value="' + p.id + '">';
            }).join('');
        }
    };

    var searchInput = $('#inv-search');
    if (searchInput) {
        searchInput.addEventListener('input', function () { Invites.filter(this.value); });
        searchInput.addEventListener('focus', function () { Invites.filter(this.value); });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.combo')) {
            var pop = $('#inv-results');
            if (pop) { pop.hidden = true; searchInput.setAttribute('aria-expanded', 'false'); }
        }
    });
})();
