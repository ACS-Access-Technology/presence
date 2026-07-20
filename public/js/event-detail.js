/* ============================================================
   Presence — Détail événement : liste de présence temps quasi réel.
   Tri, filtre, ⌘K, départs, présence manuelle, signature, polling.
   Les noms sont des données utilisateur → toujours échappés (esc).
   ============================================================ */
(function () {
    'use strict';
    var CFG = window.EVENT || { rows: [], urls: {} };
    var $ = function (s) { return document.querySelector(s); };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg) {
        var t = $('#toast');
        t.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>' + esc(msg);
        t.classList.add('show');
        clearTimeout(toast._t); toast._t = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    var Detail = {
        rows: CFG.rows || [],
        term: '', activeChip: 'all', sortKey: 'time_sort', sortDir: 'desc',
        pending: null,

        render: function () {
            this.term = ($('#pfilter').value || '').trim().toLowerCase();
            var rows = this.rows.filter(function (r) {
                if (Detail.activeChip === 'new' && r.recurrent) return false;
                if (Detail.activeChip === 'rec' && !r.recurrent) return false;
                if (Detail.term === '') return true;
                return (r.name + ' ' + (r.company || '') + ' ' + (r.direction || '') + ' ' + (r.email || ''))
                    .toLowerCase().indexOf(Detail.term) !== -1;
            });
            rows.sort(this.comparator());

            var body = $('#pbody');
            if (rows.length === 0) {
                body.innerHTML = '<tr><td colspan="7" class="empty">Aucune présence à afficher.</td></tr>';
                return;
            }
            body.innerHTML = rows.map(function (r) { return Detail.rowHtml(r); }).join('');
        },

        comparator: function () {
            var key = this.sortKey, dir = this.sortDir === 'asc' ? 1 : -1;
            return function (a, b) {
                var va = a[key], vb = b[key];
                if (key === 'time_sort') { return ((va || 0) - (vb || 0)) * dir; }
                if (key === 'left') { // nulls en dernier
                    if (!va && !vb) return 0;
                    if (!va) return 1; if (!vb) return -1;
                    return va.localeCompare(vb) * dir;
                }
                return String(va || '').localeCompare(String(vb || ''), 'fr') * dir;
            };
        },

        rowHtml: function (r) {
            var badges = '';
            if (!r.recurrent) badges += '<span class="badge-new">Nouveau</span>';
            if (r.manual) badges += '<span class="badge-manual"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Manuelle</span>';

            var sig = r.signature_url
                ? '<button class="sig-thumb" title="Voir la signature" onclick="Detail.signature(' + r.id + ')"><img src="' + esc(r.signature_url) + '" alt="Signature de ' + esc(r.name) + '"></button>'
                : '<span class="mut">—</span>';

            var depart = r.left
                ? '<span class="badge-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>Parti ' + esc(r.left) + '</span>'
                : '<span class="mut">—</span>';

            var action = r.left
                ? '<button class="btn btn--ghost btn--sm" onclick="Detail.undo(' + r.id + ')">Annuler départ</button>'
                : '<button class="btn btn--ghost btn--sm" onclick="Detail.depart(' + r.id + ')">Marquer départ</button>';

            return '<tr class="' + (r.left ? 'is-left' : '') + '">'
                + '<td><div class="person"><span class="av" style="background:' + esc(r.color) + '">' + esc(r.initials) + '</span>'
                + '<div><button type="button" class="linkbtn person__n" style="text-decoration:none" onclick="Detail.info(' + r.id + ')">' + esc(r.name) + '</button>' + badges
                + '<div class="person__e">' + esc(r.email || '') + '</div></div></div></td>'
                + '<td>' + esc(r.company || '—') + '</td>'
                + '<td class="mut">' + esc(r.direction || '—') + '</td>'
                + '<td class="hh">' + esc(r.time) + '</td>'
                + '<td>' + depart + '</td>'
                + '<td>' + sig + '</td>'
                + '<td style="text-align:right">' + action + '</td></tr>';
        },

        sort: function (key, th) {
            if (this.sortKey === key) { this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; }
            else { this.sortKey = key; this.sortDir = key === 'time_sort' ? 'desc' : 'asc'; }
            document.querySelectorAll('.dt thead th.sortable').forEach(function (h) { h.setAttribute('aria-sort', 'none'); });
            th.setAttribute('aria-sort', this.sortDir === 'asc' ? 'ascending' : 'descending');
            this.render();
        },
        chip: function (f, btn) {
            this.activeChip = f;
            document.querySelectorAll('.chips .chip').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
            this.render();
        },
        tab: function (name) {
            ['liste', 'stats', 'cr'].forEach(function (n) {
                var tab = $('#tab-' + n), panel = $('#panel-' + n);
                if (!tab || !panel) return;
                tab.setAttribute('aria-selected', String(n === name));
                panel.hidden = n !== name;
            });
        },

        find: function (id) { return this.rows.filter(function (r) { return r.id === id; })[0]; },

        depart: function (id) {
            var r = this.find(id); if (!r) return;
            this.pending = r;
            $('#dp-who').textContent = r.name;
            this.open('m-depart');
        },
        confirmDepart: function () {
            var r = this.pending; if (!r) return;
            this.close();
            post(r.departure_url).then(function (res) {
                if (res.ok) { r.left = res.data.left; Detail.render(); toast('Départ de ' + r.name + ' enregistré'); }
                else { toast('Action impossible'); }
            });
        },
        undo: function (id) {
            var r = this.find(id); if (!r) return;
            post(r.undo_url).then(function (res) {
                if (res.ok) { r.left = null; Detail.render(); toast('Départ annulé'); }
            });
        },
        signature: function (id) {
            var r = this.find(id); if (!r || !r.signature_url) return;
            $('#sig-who').textContent = r.name + (r.company ? ' · ' + r.company : '');
            $('#sig-img').src = r.signature_url;
            this.open('m-sig');
        },

        manual: function (open) { if (open) { this.open('m-manual'); setTimeout(function () { $('#m-last').focus(); }, 40); } else this.close(); },
        submitManual: function (e) {
            e.preventDefault();
            var form = $('#manual-form'), err = $('#manual-error');
            err.hidden = true;
            var fd = new FormData(form);
            if (!$('#m-confirm').checked) fd.delete('manual_confirmed'); else fd.set('manual_confirmed', '1');
            post(CFG.urls.manual, fd).then(function (res) {
                if (res.status === 201) { Detail.close(); form.reset(); toast('Présence ajoutée'); Detail.poll(); }
                else if (res.status === 422) {
                    var msgs = res.data.errors ? Object.values(res.data.errors)[0][0] : (res.data.message || 'Vérifiez les champs.');
                    err.textContent = msgs; err.hidden = false;
                } else { err.textContent = 'Une erreur est survenue.'; err.hidden = false; }
            });
        },

        poll: function () {
            fetch(CFG.urls.feed, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    Detail.rows = d.rows || [];
                    Detail.render();
                    $('#livecount').textContent = d.stats.total + ' présents';
                    $('#cnt-liste').textContent = d.stats.total;
                    $('#kpi-total').textContent = d.stats.total;
                }).catch(function () {});
        },

        /* ⌘K */
        cmdk: function (open) {
            $('#cmdk').hidden = !open;
            if (open) { $('#cmdk-input').value = ''; this.cmdkRender(); setTimeout(function () { $('#cmdk-input').focus(); }, 30); }
        },
        cmdkRender: function () {
            var q = ($('#cmdk-input').value || '').trim().toLowerCase();
            var actions = [
                { t: 'Exporter la liste en CSV', run: function () { Detail.cmdk(false); window.location = CFG.urls.feed.replace('/feed', '/export'); } },
                { t: 'Voir les statistiques', run: function () { Detail.cmdk(false); Detail.tab('stats'); } }
            ];
            if (CFG.isOpen) actions.unshift({ t: 'Ajouter une présence manuelle', run: function () { Detail.cmdk(false); Detail.manual(true); } });

            var people = this.rows.filter(function (r) {
                return q === '' || (r.name + ' ' + (r.company || '')).toLowerCase().indexOf(q) !== -1;
            }).slice(0, 8);

            Detail._cmdkItems = [];
            var html = '';
            var acts = actions.filter(function (a) { return q === '' || a.t.toLowerCase().indexOf(q) !== -1; });
            if (acts.length) {
                html += '<div class="cmdk__grp">Actions</div>';
                acts.forEach(function (a) {
                    var i = Detail._cmdkItems.push(a.run) - 1;
                    html += '<div class="cmdk__it" onclick="Detail.cmdkRun(' + i + ')">' + esc(a.t) + '<span class="go">↵</span></div>';
                });
            }
            if (people.length) {
                html += '<div class="cmdk__grp">Participants</div>';
                people.forEach(function (r) {
                    var i = Detail._cmdkItems.push(function () { Detail.cmdk(false); $('#pfilter').value = r.name; Detail.render(); }) - 1;
                    html += '<div class="cmdk__it" onclick="Detail.cmdkRun(' + i + ')"><span class="av" style="width:26px;height:26px;font-size:.7rem;border-radius:50%;display:grid;place-items:center;color:#fff;background:' + esc(r.color) + '">' + esc(r.initials) + '</span>' + esc(r.name) + '<span class="go">' + esc(r.company || '') + '</span></div>';
                });
            }
            if (!Detail._cmdkItems.length) html = '<div class="cmdk__empty">Aucun résultat.</div>';
            $('#cmdk-list').innerHTML = html;
        },
        cmdkRun: function (i) { var fn = this._cmdkItems[i]; if (fn) fn(); },

        exportAs: function (format) {
            var base = { csv: CFG.urls.exportCsv, xlsx: CFG.urls.exportXlsx, pdf: CFG.urls.exportPdf }[format];
            var params = new URLSearchParams({ chip: this.activeChip, q: this.term });
            window.location.href = base + '?' + params.toString();
        },

        info: function (id) {
            var r = this.rows.find(function (x) { return x.id === id; });
            if (!r) return;
            $('#info-av').textContent = r.initials; $('#info-av').style.background = r.color;
            $('#info-name').textContent = r.name;
            var fields = [
                ['Email', r.email], ['Téléphone', r.phone], ['Entreprise', r.company],
                ['Direction', r.direction], ['Service', r.service], ['Poste', r.position],
                ['Arrivée', r.time], ['Départ', r.left],
            ];
            $('#info-fields').innerHTML = fields.map(function (f) {
                return '<div class="info-pair"><span class="info-pair__k">' + esc(f[0]) + '</span><span class="info-pair__v">' + esc(f[1] || '—') + '</span></div>';
            }).join('');
            var sigBtn = $('#info-sig-btn');
            if (r.signature_url) { sigBtn.hidden = false; sigBtn.onclick = function () { Detail.signature(r.id); }; }
            else { sigBtn.hidden = true; }
            this.open('m-info');
        },

        open: function (id) {
            $('#scrim').hidden = false;
            ['m-sig', 'm-depart', 'm-manual', 'm-edit', 'm-reschedule', 'm-cancel', 'm-info'].forEach(function (m) {
                var el = $('#' + m); if (el) el.hidden = m !== id;
            });
            document.body.style.overflow = 'hidden';
        },
        close: function () {
            $('#scrim').hidden = true; this.pending = null;
            document.body.style.overflow = '';
        }
    };

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: Object.assign({ 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' }, body instanceof FormData ? {} : {}),
            body: body || new FormData()
        }).then(function (r) {
            return r.json().then(function (d) { return { status: r.status, ok: r.ok, data: d }; })
                .catch(function () { return { status: r.status, ok: r.ok, data: {} }; });
        }).catch(function () { return { status: 0, ok: false, data: {} }; });
    }

    window.Detail = Detail;

    document.addEventListener('DOMContentLoaded', function () {
        Detail.render();
        $('#dp-confirm').addEventListener('click', function () { Detail.confirmDepart(); });
        $('#manual-form').addEventListener('submit', function (e) { Detail.submitManual(e); });
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); Detail.cmdk($('#cmdk').hidden); }
            if (e.key === 'Escape') { Detail.cmdk(false); Detail.close(); }
        });
        // Ouverture directe sur un onglet via l'ancre (#cr depuis le portfolio, #stats…).
        var hash = (window.location.hash || '').replace('#', '');
        if (hash && $('#tab-' + hash)) Detail.tab(hash);

        if (CFG.isOpen) { setInterval(function () { Detail.poll(); }, 12000); }
    });
})();
