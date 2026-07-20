/* ============================================================
   Presence — Paramètres : CRUD types d'événement et comptes,
   branding (form classique), conservation (lecture seule).
   ============================================================ */
(function () {
    'use strict';
    var CFG = window.SETTINGS || { types: [], accounts: [], urls: {}, palette: [] };
    var $ = function (s) { return document.querySelector(s); };
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(msg) {
        var t = $('#toast'); if (!t) return;
        t.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>' + esc(msg);
        t.classList.add('show'); clearTimeout(toast._t); toast._t = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }
    function url(tpl, id) { return tpl.replace('__ID__', id); }

    var Settings = {
        types: CFG.types.slice(),
        accounts: CFG.accounts.slice(),
        editingType: null,
        editingAccount: null,
        chosenColor: CFG.palette[0],
        lastPassword: '',

        tab: function (name) {
            ['types', 'comptes', 'general', 'data'].forEach(function (n) {
                $('#tab-' + n).setAttribute('aria-selected', String(n === name));
                $('#panel-' + n).hidden = n !== name;
            });
        },

        /* ---------- Types ---------- */
        renderTypes: function () {
            var body = $('#types-body'); if (!body) return;
            if (!this.types.length) { body.innerHTML = '<tr><td colspan="5" class="empty">Aucun type.</td></tr>'; return; }
            body.innerHTML = this.types.map(function (t) {
                var badge = '<span class="tag tag--type" style="--tc:' + esc(t.color) + ';color:var(--tc);background:color-mix(in srgb, var(--tc) 15%, transparent)">' + esc(t.name) + '</span>';
                var st = t.is_active ? '<span class="st st--on">Actif</span>' : '<span class="st st--off">Désactivé</span>';
                var toggleTitle = t.is_active ? 'Désactiver' : 'Activer';
                var toggleIcon = t.is_active
                    ? '<path d="M18 6 6 18M6 6l12 12"/>'
                    : '<path d="M20 6 9 17l-5-5"/>';
                return '<tr>'
                    + '<td>' + badge + '</td>'
                    + '<td><span class="mono mut">' + esc(t.color) + '</span></td>'
                    + '<td>' + st + '</td>'
                    + '<td class="mut">' + t.usage + ' événement' + (t.usage > 1 ? 's' : '') + '</td>'
                    + '<td class="r-actions">'
                    + '<button class="mini" title="Modifier" onclick="Settings.typeModal(' + t.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>'
                    + '<button class="mini" title="' + toggleTitle + '" onclick="Settings.toggleType(' + t.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' + toggleIcon + '</svg></button>'
                    + '<button class="mini mini--danger" title="Supprimer" onclick="Settings.deleteType(' + t.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>'
                    + '</td></tr>';
            }).join('');
        },
        renderSwatches: function () {
            var box = $('#mt-swatches'); var chosen = this.chosenColor;
            var html = CFG.palette.map(function (c) {
                return '<button type="button" class="swatch" style="background:' + esc(c) + '" aria-pressed="' + (c === chosen) + '" onclick="Settings.pickColor(' + JSON.stringify(c) + ',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></button>';
            }).join('');
            html += '<label class="swatch swatch--custom" title="Couleur personnalisée"><span class="plus">+</span><input type="color" value="' + esc(chosen) + '" oninput="Settings.pickColor(this.value)"></label>';
            box.innerHTML = html;
        },
        pickColor: function (c, btn) {
            this.chosenColor = c;
            document.querySelectorAll('#mt-swatches .swatch').forEach(function (s) { s.setAttribute('aria-pressed', 'false'); });
            if (btn) btn.setAttribute('aria-pressed', 'true');
        },
        typeModal: function (id) {
            var t = id ? this.types.filter(function (x) { return x.id === id; })[0] : null;
            this.editingType = t;
            $('#mt-title').textContent = t ? 'Modifier le type' : 'Ajouter un type';
            $('#mt-name').value = t ? t.name : '';
            $('#mt-err').style.display = 'none';
            this.chosenColor = t ? t.color : CFG.palette[0];
            this.renderSwatches();
            this.open('m-type'); setTimeout(function () { $('#mt-name').focus(); }, 40);
        },
        saveType: function (e) {
            e.preventDefault();
            var name = $('#mt-name').value.trim(), err = $('#mt-err');
            if (!name) { err.textContent = 'Le nom est requis.'; err.style.display = 'block'; return false; }
            var payload = { name: name, color: this.chosenColor };
            var req, isEdit = !!this.editingType;
            if (isEdit) { payload.is_active = this.editingType.is_active; req = patch(url(CFG.urls.typeTpl, this.editingType.id), payload); }
            else req = post(CFG.urls.typesStore, payload);
            req.then(function (res) {
                if (res.ok) {
                    if (isEdit) Settings.types = Settings.types.map(function (x) { return x.id === res.data.id ? res.data : x; });
                    else Settings.types.push(res.data);
                    Settings.renderTypes(); Settings.close(); toast(isEdit ? 'Type modifié' : 'Type ajouté');
                } else { err.textContent = firstError(res) || 'Enregistrement impossible.'; err.style.display = 'block'; }
            });
            return false;
        },
        toggleType: function (id) {
            var t = this.types.filter(function (x) { return x.id === id; })[0]; if (!t) return;
            patch(url(CFG.urls.typeTpl, id), { name: t.name, color: t.color, is_active: !t.is_active }).then(function (res) {
                if (res.ok) { Settings.types = Settings.types.map(function (x) { return x.id === id ? res.data : x; }); Settings.renderTypes(); }
                else toast('Action impossible');
            });
        },
        deleteType: function (id) {
            if (!confirm('Supprimer ce type ?')) return;
            del(url(CFG.urls.typeTpl, id)).then(function (res) {
                if (res.ok) { Settings.types = Settings.types.filter(function (x) { return x.id !== id; }); Settings.renderTypes(); toast('Type supprimé'); }
                else toast(res.data.message || 'Suppression impossible');
            });
        },

        /* ---------- Comptes ---------- */
        renderAccounts: function () {
            var body = $('#accounts-body'); if (!body) return;
            body.innerHTML = this.accounts.map(function (a) {
                var role = '<span class="role ' + (a.role === 'admin' ? 'role--admin' : '') + '">' + esc(a.role_label) + '</span>';
                var st = a.is_active ? '<span class="st st--on">Actif</span>' : '<span class="st st--off">Désactivé</span>';
                var del = a.is_self ? '' : '<button class="mini mini--danger" title="Supprimer" onclick="Settings.deleteAccount(' + a.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>';
                return '<tr>'
                    + '<td><div class="person__n">' + esc(a.name) + (a.is_self ? ' <span class="badge-new" style="color:var(--accent);background:var(--accent-soft)">Vous</span>' : '') + '</div><div class="person__e">' + esc(a.email) + '</div></td>'
                    + '<td>' + role + '</td>'
                    + '<td>' + st + '</td>'
                    + '<td class="mut">' + esc(a.last_login) + '</td>'
                    + '<td class="r-actions">'
                    + '<button class="mini" title="Modifier" onclick="Settings.accountModal(' + a.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>'
                    + '<button class="mini" title="Réinitialiser le mot de passe" onclick="Settings.resetAccount(' + a.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></button>'
                    + del + '</td></tr>';
            }).join('');
        },
        accountModal: function (id) {
            var a = id ? this.accounts.filter(function (x) { return x.id === id; })[0] : null;
            this.editingAccount = a;
            $('#ma-title').textContent = a ? 'Modifier le compte' : 'Inviter un compte';
            $('#ma-name').value = a ? a.name : '';
            $('#ma-email').value = a ? a.email : '';
            $('#ma-name').disabled = !!a; $('#ma-email').disabled = !!a;
            $('#ma-role').value = a ? a.role : 'organisateur';
            $('#ma-status-wrap').style.display = a ? 'flex' : 'none';
            $('#ma-active').checked = a ? a.is_active : true;
            $('#ma-submit').textContent = a ? 'Enregistrer' : 'Créer le compte';
            $('#ma-err').style.display = 'none';
            this.open('m-account'); setTimeout(function () { (a ? $('#ma-role') : $('#ma-name')).focus(); }, 40);
        },
        saveAccount: function (e) {
            e.preventDefault();
            var err = $('#ma-err'); err.style.display = 'none';
            if (this.editingAccount) {
                var a = this.editingAccount;
                patch(url(CFG.urls.accountTpl, a.id), { role: $('#ma-role').value, is_active: $('#ma-active').checked ? 1 : 0 }).then(function (res) {
                    if (res.ok) { Settings.accounts = Settings.accounts.map(function (x) { return x.id === res.data.id ? Object.assign({}, x, res.data) : x; }); Settings.renderAccounts(); Settings.close(); toast('Compte mis à jour'); }
                    else { err.textContent = res.data.message || firstError(res) || 'Erreur.'; err.style.display = 'block'; }
                });
            } else {
                post(CFG.urls.accountsStore, { name: $('#ma-name').value.trim(), email: $('#ma-email').value.trim(), role: $('#ma-role').value }).then(function (res) {
                    if (res.status === 201) { Settings.accounts.push(res.data.account); Settings.renderAccounts(); Settings.close(); Settings.showPassword(res.data.temp_password); }
                    else { err.textContent = firstError(res) || 'Création impossible.'; err.style.display = 'block'; }
                });
            }
            return false;
        },
        resetAccount: function (id) {
            if (!confirm('Réinitialiser le mot de passe de ce compte ?')) return;
            post(url(CFG.urls.accountResetTpl, id), {}).then(function (res) {
                if (res.ok) Settings.showPassword(res.data.temp_password);
                else toast(res.data.message || 'Impossible');
            });
        },
        deleteAccount: function (id) {
            if (!confirm('Supprimer définitivement ce compte ?')) return;
            del(url(CFG.urls.accountTpl, id)).then(function (res) {
                if (res.ok) { Settings.accounts = Settings.accounts.filter(function (x) { return x.id !== id; }); Settings.renderAccounts(); toast('Compte supprimé'); }
                else toast(res.data.message || 'Suppression impossible');
            });
        },

        /* ---------- Mot de passe temporaire ---------- */
        showPassword: function (pw) { this.lastPassword = pw; $('#mp-value').textContent = pw; this.open('m-password'); },
        copyPassword: function () {
            if (navigator.clipboard) navigator.clipboard.writeText(this.lastPassword).then(function () { toast('Copié'); });
        },

        open: function (id) {
            $('#s-scrim').hidden = false;
            ['m-type', 'm-account', 'm-password'].forEach(function (m) { $('#' + m).hidden = m !== id; });
            document.body.style.overflow = 'hidden';
        },
        close: function () { $('#s-scrim').hidden = true; document.body.style.overflow = ''; }
    };

    function firstError(res) {
        if (res.data && res.data.errors) { var k = Object.keys(res.data.errors)[0]; return res.data.errors[k][0]; }
        return null;
    }
    function send(url, method, payload) {
        var body = new FormData();
        if (method !== 'POST') body.append('_method', method);
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' }, body: body })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, ok: r.ok, data: d }; }).catch(function () { return { status: r.status, ok: r.ok, data: {} }; }); })
            .catch(function () { return { status: 0, ok: false, data: {} }; });
    }
    function post(u, p) { return send(u, 'POST', p); }
    function patch(u, p) { return send(u, 'PATCH', p); }
    function del(u) { return send(u, 'DELETE', {}); }

    window.Settings = Settings;
    document.addEventListener('DOMContentLoaded', function () {
        Settings.renderTypes();
        Settings.renderAccounts();
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') Settings.close(); });
    });
})();
