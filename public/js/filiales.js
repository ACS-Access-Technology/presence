/* ============================================================
   Presence — Gestion des filiales (SuperAdmin) : liste, création,
   renommage, activation/désactivation. Suit les conventions de
   settings.js (send/post/patch, modale scrim/modal).
   ============================================================ */
(function () {
    'use strict';
    var CFG = window.FILIALES || { filiales: [], urls: {} };
    var $ = function (s) { return document.querySelector(s); };
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function url(tpl, id) { return tpl.replace('__ID__', id); }
    function send(u, method, payload) {
        var body = new FormData();
        if (method !== 'POST') body.append('_method', method);
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(u, { method: 'POST', headers: { 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' }, body: body })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, ok: r.ok, data: d }; }).catch(function () { return { status: r.status, ok: r.ok, data: {} }; }); })
            .catch(function () { return { status: 0, ok: false, data: {} }; });
    }

    var Filiales = {
        list: CFG.filiales.slice(),
        editingId: null,

        render: function () {
            var body = $('#filiales-body'); if (!body) return;
            if (!this.list.length) { body.innerHTML = '<tr><td colspan="6" class="empty">Aucune filiale.</td></tr>'; return; }
            body.innerHTML = this.list.map(function (f) {
                var statusTag = f.is_active
                    ? '<span class="tag tag--live">Active</span>'
                    : '<span class="tag tag--done">Désactivée</span>';
                var toggleLabel = f.is_active ? 'Désactiver' : 'Réactiver';
                var toggleBtn = f.is_default
                    ? ''
                    : '<button class="btn btn--ghost btn--sm" type="button" onclick="Filiales.toggle(' + f.id + ')">' + toggleLabel + '</button>';
                var initials = f.name.trim().split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
                var subtitle = f.is_default
                    ? 'Filiale par défaut · non supprimable'
                    : (f.is_active
                        ? (f.admin_name ? 'Admin : ' + esc(f.admin_name) : 'Aucun admin de filiale')
                        : 'Aucune connexion possible');
                return '<tr>'
                    + '<td><div style="display:flex;align-items:center;gap:11px">'
                    + '<span class="fav">' + esc(initials) + '</span>'
                    + '<div><div style="font-weight:700">' + esc(f.name) + '</div><div class="mut" style="font-size:.78rem">' + subtitle + '</div></div>'
                    + '</div></td>'
                    + '<td>' + statusTag + '</td>'
                    + '<td>' + f.users_count + (f.admin_count ? ' <span class="mut">(' + f.admin_count + ' admin)</span>' : '') + '</td>'
                    + '<td>' + f.events_count + '</td>'
                    + '<td>' + esc(f.created_label || '—') + '</td>'
                    + '<td style="text-align:right;white-space:nowrap">'
                    + '<button class="btn btn--ghost btn--sm" type="button" onclick="Filiales.manage(' + f.id + ')">Gérer les comptes</button> '
                    + '<button class="btn btn--ghost btn--sm" type="button" onclick="Filiales.openEdit(' + f.id + ')">Renommer</button> '
                    + toggleBtn
                    + '</td>'
                    + '</tr>';
            }).join('');
        },

        manage: function (id) { this.goto(id, 'settings'); },
        goto: function (id, redirectTo) {
            $('#f-goto-id').value = id;
            $('#f-goto-redirect').value = redirectTo;
            $('#f-goto-form').submit();
        },

        openCreate: function () {
            this.editingId = null;
            $('#mf-title').textContent = 'Nouvelle filiale';
            $('#f-name').value = '';
            $('#f-name-err').textContent = '';
            this.open();
        },
        openEdit: function (id) {
            var f = this.list.find(function (x) { return x.id === id; });
            if (!f) return;
            this.editingId = id;
            $('#mf-title').textContent = 'Renommer la filiale';
            $('#f-name').value = f.name;
            $('#f-name-err').textContent = '';
            this.open();
        },
        open: function () {
            $('#f-scrim').hidden = false;
            $('#m-filiale').hidden = false;
            document.body.style.overflow = 'hidden';
            setTimeout(function () { $('#f-name').focus(); }, 30);
        },
        close: function () {
            $('#f-scrim').hidden = true;
            $('#m-filiale').hidden = true;
            document.body.style.overflow = '';
        },

        save: function () {
            var name = $('#f-name').value.trim();
            if (!name) { $('#f-name-err').textContent = 'Le nom est requis.'; return; }

            var wasCreate = !this.editingId;
            var btn = $('#f-save'); btn.disabled = true;
            var req = this.editingId
                ? send(url(CFG.urls.updateTpl, this.editingId), 'PATCH', { name: name })
                : send(CFG.urls.store, 'POST', { name: name });

            req.then(function (res) {
                btn.disabled = false;
                if (!res.ok) {
                    var errors = res.data && res.data.errors;
                    $('#f-name-err').textContent = (errors && errors.name && errors.name[0]) || 'Une erreur est survenue.';
                    return;
                }
                var saved = res.data;
                // Nouvelle filiale : bascule directement dans son contexte et
                // ouvre l'onglet Comptes (invitation prête) pour lui assigner un
                // admin — le branding (logo, couleur) se règle depuis Général.
                // Le flag est lu une seule fois par settings.js (sessionStorage,
                // pas un paramètre d'URL) : "Gérer les comptes" doit rester une
                // simple navigation, sans ouvrir l'invitation à chaque fois.
                if (wasCreate) {
                    sessionStorage.setItem('presence_invite_prompt', '1');
                    Filiales.goto(saved.id, 'settings');
                    return;
                }
                var idx = Filiales.list.findIndex(function (x) { return x.id === saved.id; });
                if (idx >= 0) { Filiales.list[idx] = saved; }
                Filiales.render();
                Filiales.close();
            });
        },

        toggle: function (id) {
            send(url(CFG.urls.toggleTpl, id), 'PATCH', {}).then(function (res) {
                if (!res.ok) return;
                var idx = Filiales.list.findIndex(function (x) { return x.id === id; });
                if (idx >= 0) { Filiales.list[idx] = res.data; Filiales.render(); }
            });
        }
    };

    window.Filiales = Filiales;
    document.addEventListener('DOMContentLoaded', function () {
        Filiales.render();
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') Filiales.close(); });
    });
})();
