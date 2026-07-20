/* ============================================================
   Presence — Parcours public d'émargement (logique réelle)
   Géoloc navigateur, reconnaissance/soumission via fetch, signature PNG,
   anti-chevauchement. Config injectée via window.PRESENCE.
   ============================================================ */
(function () {
    'use strict';

    var CFG = window.PRESENCE || {};
    var $ = function (s) { return document.querySelector(s); };
    var REQUIRED = ['last_name', 'first_name', 'phone', 'company', 'direction', 'position'];
    var SCREENS = { email: '#s-email', form: '#s-form', confirm: '#s-confirm', error: '#s-error' };

    var State = { geo: 'idle', coords: null, recurrent: false, overlap: null, departConfirmed: false };

    function showScreen(name) {
        Object.keys(SCREENS).forEach(function (k) { $(SCREENS[k]).hidden = true; });
        $(SCREENS[name]).hidden = false;
        window.scrollTo({ top: 0, behavior: 'auto' });
        // Le canvas de signature est dimensionné à 0×0 s'il est initialisé pendant
        // que son écran est encore `hidden` (getBoundingClientRect renvoie 0). On
        // le redimensionne à chaque affichage de l'écran formulaire pour corriger ça.
        if (name === 'form' && typeof Sig !== 'undefined' && Sig.canvas) { Sig.resize(); }
    }

    /* ------------------------- Géolocalisation ------------------------- */
    var Geo = {
        request: function () {
            State.geo = 'pending';
            var box = $('#geo'), t = $('#geoT'), d = $('#geoD'), ic = $('#geoIc');
            box.className = 'geo geo--pending';
            t.textContent = 'Localisation en cours…';
            d.textContent = 'Merci de patienter quelques instants.';
            ic.innerHTML = '<span class="spin" aria-hidden="true"></span>';

            if (!('geolocation' in navigator)) { Geo.fail(); return; }
            navigator.geolocation.getCurrentPosition(
                function (pos) { Geo.success(pos); },
                function () { Geo.fail(); },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        },
        success: function (pos) {
            State.geo = 'ok';
            State.coords = {
                latitude: pos.coords.latitude,
                longitude: pos.coords.longitude,
                accuracy: pos.coords.accuracy
            };
            var box = $('#geo'), t = $('#geoT'), d = $('#geoD'), ic = $('#geoIc');
            box.className = 'geo geo--ok';
            ic.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
            t.textContent = 'Position enregistrée';
            d.textContent = 'Vous pouvez valider votre présence.';
            Flow.validate();
        },
        fail: function () { State.geo = 'denied'; State.coords = null; showScreen('error'); },
        retry: function () { showScreen('form'); Geo.request(); }
    };

    /* ------------------------- Signature (canvas) ------------------------- */
    var Sig = {
        canvas: null, ctx: null, drawing: false, hasInk: false, last: null,
        init: function () {
            this.canvas = $('#sigpad'); this.ctx = this.canvas.getContext('2d');
            this.resize();
            window.addEventListener('resize', function () { Sig.resize(); });
            var c = this.canvas;
            c.addEventListener('pointerdown', function (e) { Sig.start(e); });
            c.addEventListener('pointermove', function (e) { Sig.move(e); });
            c.addEventListener('pointerup', function () { Sig.end(); });
            c.addEventListener('pointerleave', function () { Sig.end(); });
        },
        resize: function () {
            var r = this.canvas.getBoundingClientRect();
            var dpr = window.devicePixelRatio || 1;
            this.canvas.width = r.width * dpr; this.canvas.height = r.height * dpr;
            this.ctx.scale(dpr, dpr);
            this.ctx.lineWidth = 2.5; this.ctx.lineCap = 'round'; this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = getComputedStyle(document.body).getPropertyValue('--text').trim() || '#12141a';
        },
        pos: function (e) {
            var r = this.canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        },
        start: function (e) { this.canvas.setPointerCapture(e.pointerId); this.drawing = true; this.last = this.pos(e); },
        move: function (e) {
            if (!this.drawing) return;
            e.preventDefault();
            var p = this.pos(e), l = this.last, mx = (l.x + p.x) / 2, my = (l.y + p.y) / 2;
            this.ctx.beginPath(); this.ctx.moveTo(l.x, l.y); this.ctx.quadraticCurveTo(l.x, l.y, mx, my); this.ctx.stroke();
            this.last = p;
            if (!this.hasInk) { this.hasInk = true; $('#sigPh').style.opacity = '0'; Flow.validate(); }
        },
        end: function () { this.drawing = false; },
        clear: function () {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.hasInk = false; $('#sigPh').style.opacity = '1'; Flow.validate();
        },
        dataUrl: function () { return this.canvas.toDataURL('image/png'); }
    };

    /* ------------------------- Flow / validation ------------------------- */
    var Flow = {
        fieldsFilled: function () {
            return REQUIRED.every(function (id) { return $('#' + id).value.trim() !== ''; });
        },
        validate: function () {
            var ok = State.geo === 'ok' && Sig.hasInk && $('#consent').checked && this.fieldsFilled();
            $('#submit').disabled = !ok;
            return ok;
        },
        continueFromEmail: function () {
            var input = $('#email'), field = input.closest('.field');
            var email = (input.value || '').trim();
            if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                field.classList.add('invalid'); input.setAttribute('aria-invalid', 'true'); input.focus(); return;
            }
            field.classList.remove('invalid'); input.removeAttribute('aria-invalid');

            api(CFG.urls.recognize, { email: email, ticket: CFG.ticket }).then(function (res) {
                if (!res.ok) { field.classList.add('invalid'); return; }
                var data = res.data;
                if (data.overlap) { Overlap.show(data.overlap); return; }
                Flow.proceed(email, data, false);
            });
        },
        proceed: function (email, data, fromOverlap) {
            $('#email').value = email;
            if (data && data.known && data.person) { Flow.collapse(data.person); } else { Flow.expand(); }
            var note = $('#departNote');
            if (fromOverlap && State.overlap) { $('#departNoteEv').textContent = State.overlap.event_title; note.hidden = false; }
            else { note.hidden = true; }
            showScreen('form');
            Geo.request();
        },
        collapse: function (person) {
            fillFields(person);
            if (!Flow.fieldsFilled()) { Flow.expand(); return; } // profil incomplet → à compléter
            State.recurrent = true;
            $('#fullFields').hidden = true;
            $('#recogInit').textContent = initials(person);
            $('#recogHello').textContent = 'On vous reconnaît, ' + (person.first_name || '');
            $('#recogInfo').textContent = [person.company, person.direction].filter(Boolean).join(' · ');
            $('#recog').hidden = false;
            Flow.validate();
        },
        expand: function () {
            State.recurrent = false;
            $('#fullFields').hidden = false;
            $('#recog').hidden = true;
            Flow.validate();
        },
        markErrors: function () {
            var firstBad = null;
            REQUIRED.forEach(function (id) {
                var el = $('#' + id), f = el.closest('.field');
                if (el.value.trim() === '') { f.classList.add('invalid'); el.setAttribute('aria-invalid', 'true'); if (!firstBad) firstBad = el; }
                else { f.classList.remove('invalid'); el.removeAttribute('aria-invalid'); }
            });
            if (firstBad) firstBad.focus();
        },
        trySubmit: function () {
            if (!this.validate()) { if (State.recurrent) this.expand(); this.markErrors(); return; }
            var who = ($('#first_name').value + ' ' + $('#last_name').value).trim();
            var company = $('#company').value.trim();
            $('#m-sub-who').textContent = company ? (who + ' · ' + company) : who;
            Modal.open('submit');
        },
        submit: function () {
            Modal.close();
            var btn = $('#submit'); btn.disabled = true;
            var payload = {
                email: $('#email').value.trim(),
                last_name: $('#last_name').value.trim(),
                first_name: $('#first_name').value.trim(),
                phone: $('#phone').value.trim(),
                company: $('#company').value.trim(),
                direction: $('#direction').value.trim(),
                service: $('#service').value.trim(),
                position: $('#position').value.trim(),
                latitude: State.coords ? State.coords.latitude : '',
                longitude: State.coords ? State.coords.longitude : '',
                accuracy: State.coords ? State.coords.accuracy : '',
                signature: Sig.dataUrl(),
                ticket: CFG.ticket,
                consent: '1'
            };
            if (State.departConfirmed) payload.confirm_departure = '1';

            api(CFG.urls.store, payload).then(function (res) {
                if (res.status === 200) { Flow.confirmed(res.data); return; }
                if (res.status === 409 && res.data.overlap) { State.departConfirmed = false; Overlap.show(res.data.overlap); return; }
                if (res.status === 419) { alert('Votre session de scan a expiré. Rescannez le QR affiché pour continuer.'); return; }
                if (res.status === 422) { Flow.expand(); Flow.markErrors(); btn.disabled = false; return; }
                alert('Une erreur est survenue. Veuillez réessayer.');
                btn.disabled = false;
            });
        },
        confirmed: function (data) {
            $('#confirmMsg').textContent = 'Merci ' + (data.first_name || '') + ', votre présence à ' + data.event_title + ' est confirmée.';
            $('#confirmTs').textContent = data.checked_in_at || '';
            $('#confirmRef').textContent = 'Référence : ' + data.reference;
            showScreen('confirm');
        }
    };

    /* ------------------------- Anti-chevauchement ------------------------- */
    var Overlap = {
        show: function (ov) {
            State.overlap = ov;
            $('#ov-ev').textContent = ov.event_title;
            $('#ov-when').textContent = ov.when || '';
            var lieuWrap = $('#ov-lieu-wrap');
            if (ov.location) { lieuWrap.hidden = false; $('#ov-lieu').textContent = ov.location; } else { lieuWrap.hidden = true; }
            Modal.open('overlap');
        },
        confirm: function () {
            Modal.close();
            State.departConfirmed = true;
            var email = $('#email').value.trim();
            // On poursuit le flux : reconnaissance déjà connue (l'email a un profil).
            api(CFG.urls.recognize, { email: email, ticket: CFG.ticket }).then(function (res) {
                var data = res.ok ? res.data : { known: true, person: null };
                Flow.proceed(email, data, true);
            });
        }
    };

    /* ------------------------- Modales ------------------------- */
    var Modal = {
        current: null, lastFocus: null,
        open: function (id) {
            this.lastFocus = document.activeElement;
            $('#scrim').hidden = false;
            ['submit', 'mentions', 'help', 'overlap'].forEach(function (m) { $('#modal-' + m).hidden = (m !== id); });
            this.current = id;
            document.body.style.overflow = 'hidden';
            var dlg = $('#modal-' + id);
            var target = dlg.querySelector('.modal__x') || dlg;
            setTimeout(function () { target.focus(); }, 30);
            document.addEventListener('keydown', Modal._esc);
        },
        close: function () {
            $('#scrim').hidden = true; this.current = null;
            document.body.style.overflow = '';
            document.removeEventListener('keydown', Modal._esc);
            if (Modal.lastFocus) Modal.lastFocus.focus();
        },
        _esc: function (e) { if (e.key === 'Escape') Modal.close(); }
    };

    /* ------------------------- Helpers ------------------------- */
    function initials(p) {
        return ((p.first_name || ' ')[0] + (p.last_name || ' ')[0]).toUpperCase().trim() || '?';
    }
    function fillFields(p) {
        var map = { last_name: p.last_name, first_name: p.first_name, phone: p.phone, company: p.company, direction: p.direction, service: p.service, position: p.position };
        Object.keys(map).forEach(function (id) { if (map[id]) $('#' + id).value = map[id]; });
    }
    function api(url, payload) {
        var body = new FormData();
        Object.keys(payload).forEach(function (k) { if (payload[k] !== undefined && payload[k] !== null) body.append(k, payload[k]); });
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' },
            body: body
        }).then(function (r) {
            return r.json().then(function (data) { return { status: r.status, ok: r.ok, data: data }; })
                .catch(function () { return { status: r.status, ok: r.ok, data: {} }; });
        }).catch(function () { return { status: 0, ok: false, data: {} }; });
    }

    /* ------------------------- Câblage ------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        Sig.init();

        $('#emailContinue').addEventListener('click', function () { Flow.continueFromEmail(); });
        $('#email').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); Flow.continueFromEmail(); } });
        $('#recogEdit').addEventListener('click', function () { Flow.expand(); });
        $('#sigClear').addEventListener('click', function () { Sig.clear(); });
        $('#consent').addEventListener('change', function () { Flow.validate(); });
        $('#submit').addEventListener('click', function () { Flow.trySubmit(); });
        $('#m-confirm').addEventListener('click', function () { Flow.submit(); });
        $('#ov-confirm').addEventListener('click', function () { Overlap.confirm(); });
        $('#geoRetry').addEventListener('click', function () { Geo.retry(); });

        document.querySelectorAll('[data-modal]').forEach(function (b) {
            b.addEventListener('click', function () { Modal.open(b.getAttribute('data-modal')); });
        });
        document.querySelectorAll('[data-close]').forEach(function (b) {
            b.addEventListener('click', function () { Modal.close(); });
        });
        $('#scrim').addEventListener('click', function (e) { if (e.target === $('#scrim')) Modal.close(); });

        REQUIRED.concat(['service']).forEach(function (id) {
            $('#' + id).addEventListener('input', function () { Flow.validate(); });
        });

        showScreen('email');
        $('#email').focus();
    });
})();
