/* ============================================================
   Presence — Compte-rendu d'un événement.
   Éditeur markdown léger, upload/suppression de documents et photos.
   ============================================================ */
(function () {
    'use strict';
    var EV = window.EVENT || {};
    var CFG = EV.report;
    if (!CFG || !CFG.canEdit) return; // onglet verrouillé (événement pas commencé)

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
    function humanSize(n) {
        if (!n) return '';
        var u = ['o', 'Ko', 'Mo'], i = 0; n = Number(n);
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return n.toFixed(i ? 1 : 0) + ' ' + u[i];
    }

    var Report = {
        docs: (CFG.documents || []).slice(),
        photos: (CFG.photos || []).slice(),

        ta: function () { return $('#cr-text'); },

        updateChars: function () { var c = $('#cr-count'); if (c && this.ta()) c.textContent = this.ta().value.length; },
        touch: function () { var s = $('#cr-save'); if (s) s.textContent = 'Modifié — non enregistré'; this.updateChars(); },

        wrap: function (token) {
            var ta = this.ta(); if (!ta) return;
            var s = ta.selectionStart, e = ta.selectionEnd, val = ta.value;
            var sel = val.slice(s, e) || 'texte';
            ta.value = val.slice(0, s) + token + sel + token + val.slice(e);
            ta.focus(); ta.selectionStart = s + token.length; ta.selectionEnd = s + token.length + sel.length;
            this.touch();
        },
        prefix: function (token) {
            var ta = this.ta(); if (!ta) return;
            var s = ta.selectionStart, val = ta.value;
            var lineStart = val.lastIndexOf('\n', s - 1) + 1;
            ta.value = val.slice(0, lineStart) + token + val.slice(lineStart);
            ta.focus(); ta.selectionStart = ta.selectionEnd = s + token.length;
            this.touch();
        },

        save: function () {
            var ta = this.ta(); if (!ta) return;
            var btn = $('#cr-savebtn'); btn.disabled = true;
            post(CFG.urls.saveText, { body: ta.value }).then(function (res) {
                btn.disabled = false;
                if (res.ok) { var s = $('#cr-save'); if (s) s.textContent = 'Enregistré à ' + (res.data.saved_at || ''); toast('Compte-rendu enregistré'); }
                else toast('Enregistrement impossible');
            });
        },

        renderDocs: function () {
            var list = $('#doc-list'); if (!list) return;
            $('#doc-cnt').textContent = this.docs.length;
            list.innerHTML = this.docs.map(function (d) {
                return '<div class="doc-item">'
                    + '<span class="doc-item__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg></span>'
                    + '<div><div class="doc-item__n">' + esc(d.name) + '</div><div class="doc-item__m">' + esc(humanSize(d.size)) + '</div></div>'
                    + '<a href="' + esc(d.url) + '" target="_blank" rel="noopener">Ouvrir</a>'
                    + '<button class="del" title="Supprimer" onclick="Report.removeDoc(' + d.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>'
                    + '</div>';
            }).join('');
            this.updateTabCount();
        },
        renderPhotos: function () {
            var grid = $('#photo-grid'); if (!grid) return;
            $('#photo-cnt').textContent = this.photos.length;
            grid.innerHTML = this.photos.map(function (p) {
                return '<div class="photo-cell"><img src="' + esc(p.url) + '" alt="Photo de l\'activité" loading="lazy">'
                    + '<button class="del" title="Supprimer" onclick="Report.removePhoto(' + p.id + ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>';
            }).join('');
            this.updateTabCount();
        },
        updateTabCount: function () { var c = $('#cnt-cr'); if (c) c.textContent = this.docs.length + this.photos.length; },

        uploadDocs: function (files) {
            if (!files.length) return;
            var fd = new FormData();
            for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
            post(CFG.urls.uploadDocuments, fd).then(function (res) {
                if (res.status === 201 && res.data.documents) { Report.docs = Report.docs.concat(res.data.documents); Report.renderDocs(); toast('Document(s) ajouté(s)'); }
                else toast(res.data.message || 'Import impossible (format ou taille).');
            });
        },
        uploadPhotos: function (files) {
            if (!files.length) return;
            var fd = new FormData();
            for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
            post(CFG.urls.uploadPhotos, fd).then(function (res) {
                if (res.status === 201 && res.data.photos) { Report.photos = Report.photos.concat(res.data.photos); Report.renderPhotos(); toast('Photo(s) ajoutée(s)'); }
                else toast(res.data.message || 'Import impossible (format ou taille).');
            });
        },
        removeDoc: function (id) {
            var d = this.docs.filter(function (x) { return x.id === id; })[0]; if (!d) return;
            del(d.delete_url).then(function () { Report.docs = Report.docs.filter(function (x) { return x.id !== id; }); Report.renderDocs(); toast('Document supprimé'); });
        },
        removePhoto: function (id) {
            var p = this.photos.filter(function (x) { return x.id === id; })[0]; if (!p) return;
            del(p.delete_url).then(function () { Report.photos = Report.photos.filter(function (x) { return x.id !== id; }); Report.renderPhotos(); toast('Photo supprimée'); });
        }
    };

    function request(url, method, body) {
        return fetch(url, {
            method: method,
            headers: { 'X-CSRF-TOKEN': EV.csrf, 'Accept': 'application/json' },
            body: body
        }).then(function (r) {
            return r.json().then(function (d) { return { status: r.status, ok: r.ok, data: d }; })
                .catch(function () { return { status: r.status, ok: r.ok, data: {} }; });
        }).catch(function () { return { status: 0, ok: false, data: {} }; });
    }
    function post(url, payload) {
        var body = payload instanceof FormData ? payload : (function () { var f = new FormData(); Object.keys(payload).forEach(function (k) { f.append(k, payload[k]); }); return f; })();
        return request(url, 'POST', body);
    }
    function del(url) { var f = new FormData(); f.append('_method', 'DELETE'); return request(url, 'POST', f); }

    function bindDrop(zoneId, inputId, handler) {
        var zone = document.getElementById(zoneId), input = document.getElementById(inputId);
        if (!zone || !input) return;
        input.addEventListener('change', function () { handler(input.files); input.value = ''; });
        ['dragenter', 'dragover'].forEach(function (e) { zone.addEventListener(e, function (ev) { ev.preventDefault(); zone.classList.add('drag'); }); });
        ['dragleave', 'drop'].forEach(function (e) { zone.addEventListener(e, function (ev) { ev.preventDefault(); zone.classList.remove('drag'); }); });
        zone.addEventListener('drop', function (ev) { if (ev.dataTransfer && ev.dataTransfer.files) handler(ev.dataTransfer.files); });
    }

    window.Report = Report;

    document.addEventListener('DOMContentLoaded', function () {
        if (!$('#cr-text')) return; // onglet non rendu
        Report.updateChars();
        Report.renderDocs();
        Report.renderPhotos();
        $('#cr-text').addEventListener('input', function () { Report.touch(); });
        bindDrop('doc-drop', 'doc-input', function (f) { Report.uploadDocs(f); });
        bindDrop('photo-drop', 'photo-input', function (f) { Report.uploadPhotos(f); });
    });
})();
