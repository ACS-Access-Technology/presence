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

            if (!window.isSecureContext) { Geo.fail('insecure'); return; }
            if (!('geolocation' in navigator)) { Geo.fail('unsupported'); return; }
            navigator.geolocation.getCurrentPosition(
                function (pos) { Geo.success(pos); },
                function (err) { Geo.fail(err.code); },
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
        fail: function (reason) {
            State.geo = 'denied'; State.coords = null;
            var MESSAGES = {
                insecure: {
                    title: 'Connexion non sécurisée',
                    desc: "La géolocalisation nécessite une connexion sécurisée (https). Ce lien n'en est pas une — signalez-le à l'organisateur.",
                    tips: []
                },
                unsupported: {
                    title: 'Localisation indisponible',
                    desc: 'Votre navigateur ne prend pas en charge la géolocalisation.',
                    tips: ['Essayez avec un autre navigateur (Chrome, Safari récent).']
                },
                1: {
                    title: 'Localisation refusée',
                    desc: "Vous avez refusé l'accès à votre position pour ce site.",
                    tips: ['Ouvrez les réglages du site dans votre navigateur (icône cadenas ou (i) à côté de l\'adresse) et autorisez la localisation.', 'Puis réessayez ci-dessous.']
                },
                2: {
                    title: 'Position indisponible',
                    desc: "Votre appareil n'arrive pas à déterminer sa position.",
                    tips: ['Vérifiez que le GPS / la localisation est activé(e) sur votre appareil.', 'Rapprochez-vous d\'une fenêtre ou sortez à l\'extérieur si possible.']
                },
                3: {
                    title: 'Localisation trop longue',
                    desc: 'La recherche de votre position a pris trop de temps.',
                    tips: ['Vérifiez votre connexion et réessayez.']
                }
            };
            var m = MESSAGES[reason] || {
                title: 'Nous avons besoin de votre position',
                desc: "Votre position confirme votre présence sur place. Sans elle, l'émargement ne peut pas être validé.",
                tips: ['Autorisez la localisation dans votre navigateur.', 'Vérifiez que le GPS / la localisation est activé(e) sur votre appareil.']
            };
            $('#geoErrTitle').textContent = m.title;
            $('#geoErrDesc').textContent = m.desc;
            var tipsList = $('#geoErrTips');
            tipsList.textContent = '';
            m.tips.forEach(function (t) {
                var li = document.createElement('li');
                li.textContent = t;
                tipsList.appendChild(li);
            });
            tipsList.hidden = m.tips.length === 0;
            showScreen('error');
        },
        retry: function () { showScreen('form'); Geo.request(); }
    };

    /* ------------------------- Signature (canvas) ------------------------- */
    var Sig = {
        canvas: null, ctx: null, drawing: false, hasInk: false, last: null, lastMid: null,
        init: function () {
            this.canvas = $('#sigpad'); this.ctx = this.canvas.getContext('2d');
            this.resize();
            window.addEventListener('resize', function () { Sig.resize(); });
            var c = this.canvas;
            c.addEventListener('pointerdown', function (e) { Sig.start(e); });
            c.addEventListener('pointermove', function (e) { Sig.move(e); });
            c.addEventListener('pointerup', function () { Sig.end(); });
            c.addEventListener('pointercancel', function () { Sig.end(); });
            c.addEventListener('pointerleave', function () { Sig.end(); });
        },
        // Changer canvas.width/height EFFACE tout son contenu, même à valeur
        // identique (règle du standard HTML5) — et `resize` se déclenche très
        // souvent sur mobile sans rapport avec une vraie signature (clavier
        // qui s'ouvre/ferme, barre d'adresse qui apparaît/disparaît au scroll).
        // Sans cette sauvegarde/restauration, la signature déjà tracée
        // disparaissait silencieusement : `hasInk` restait vrai, le bouton
        // Valider restait actif, et un PNG blanc partait au serveur.
        resize: function () {
            var hadInk = this.hasInk;
            var saved = hadInk ? this.canvas.toDataURL('image/png') : null;

            var r = this.canvas.getBoundingClientRect();
            var dpr = window.devicePixelRatio || 1;
            this.canvas.width = r.width * dpr; this.canvas.height = r.height * dpr;
            this.ctx.scale(dpr, dpr);
            this.ctx.lineWidth = 2.5; this.ctx.lineCap = 'round'; this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = getComputedStyle(document.body).getPropertyValue('--text').trim() || '#12141a';

            if (saved) {
                var ctx = this.ctx, w = r.width, h = r.height;
                var img = new Image();
                img.onload = function () { ctx.drawImage(img, 0, 0, w, h); };
                img.src = saved;
            }
        },
        pos: function (e) {
            var r = this.canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        },
        start: function (e) {
            this.canvas.setPointerCapture(e.pointerId);
            this.drawing = true;
            this.last = this.pos(e);
            this.lastMid = this.last;
        },
        // Lissage par courbe quadratique entre midpoints successifs (algorithme
        // standard des pads de signature) : chaque segment va du MILIEU
        // précédent au NOUVEAU milieu, avec le point brut comme point de
        // contrôle. L'ancienne version dessinait du point précédent jusqu'au
        // milieu suivant SANS jamais parcourir l'autre moitié — la ligne
        // ratait donc systématiquement la moitié du trajet entre deux points,
        // visible comme des trous dès que le doigt bouge vite.
        move: function (e) {
            if (!this.drawing) return;
            e.preventDefault();
            var p = this.pos(e);
            var mid = { x: (this.last.x + p.x) / 2, y: (this.last.y + p.y) / 2 };
            this.ctx.beginPath();
            this.ctx.moveTo(this.lastMid.x, this.lastMid.y);
            this.ctx.quadraticCurveTo(this.last.x, this.last.y, mid.x, mid.y);
            this.ctx.stroke();
            this.lastMid = mid;
            this.last = p;
            if (!this.hasInk) { this.hasInk = true; $('#sigPh').style.opacity = '0'; Flow.validate(); }
        },
        end: function () {
            // Tap/point unique (pointerdown puis pointerup sans aucun
            // pointermove) : sans ça, une marque volontaire mais très brève
            // ne laissait aucune trace ni sur le canevas ni dans `hasInk`.
            if (this.drawing && !this.hasInk && this.last) {
                this.ctx.beginPath();
                this.ctx.arc(this.last.x, this.last.y, this.ctx.lineWidth / 2, 0, Math.PI * 2);
                this.ctx.fillStyle = this.ctx.strokeStyle;
                this.ctx.fill();
                this.hasInk = true; $('#sigPh').style.opacity = '0'; Flow.validate();
            }
            this.drawing = false;
        },
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
                if (!res.ok) {
                    // Échec de /recognize (ticket expiré, événement fermé/supprimé,
                    // réseau…) : rien à voir avec le format de l'email saisi, donc
                    // on ne marque pas le champ invalide — message dédié à la place.
                    alert((res.data && res.data.message) || 'Une erreur est survenue. Rechargez la page et réessayez.');
                    return;
                }
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
                if (res.status === 403) { alert(res.data.message || 'Vous devez être sur place pour émarger.'); btn.disabled = false; return; }
                if (res.status === 422) {
                    // markErrors() ne couvre que les champs texte requis (nom,
                    // téléphone…) : une erreur sur signature/consentement/géoloc/
                    // ticket serait sinon totalement silencieuse pour le visiteur.
                    var errors = (res.data && res.data.errors) || {};
                    var unhandled = Object.keys(errors).filter(function (k) { return REQUIRED.indexOf(k) === -1; });
                    if (unhandled.length) {
                        alert(errors[unhandled[0]][0] || (res.data && res.data.message) || 'Certaines informations sont invalides. Réessayez.');
                    }
                    Flow.expand(); Flow.markErrors(); btn.disabled = false; return;
                }
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
    function api(url, payload, attempt) {
        attempt = attempt || 0;
        var body = new FormData();
        Object.keys(payload).forEach(function (k) { if (payload[k] !== undefined && payload[k] !== null) body.append(k, payload[k]); });
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' },
            body: body
        }).then(function (r) {
            return r.json().then(function (data) { return { status: r.status, ok: r.ok, data: data }; })
                .catch(function () { return { status: r.status, ok: r.ok, data: {} }; });
        }).catch(function () {
            // Coupure réseau brève (wifi de salle instable) : quelques essais avant
            // d'abandonner. Le ticket de scan expire à 5 min, donc pas de file
            // d'attente longue durée — juste tolérer un accroc de quelques secondes.
            if (attempt < 2) {
                return new Promise(function (resolve) { setTimeout(resolve, 1500 * (attempt + 1)); })
                    .then(function () { return api(url, payload, attempt + 1); });
            }
            return { status: 0, ok: false, data: {} };
        });
    }

    /* ------------------------- Bandeau de grâce (post-clôture) ------------------------- */
    var Grace = {
        init: function () {
            var el = $('#graceBanner');
            if (!el) return;
            var endsAt = new Date(el.getAttribute('data-ends')).getTime();
            var out = $('#graceCountdown');
            function tick() {
                var remaining = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
                if (remaining <= 0) { window.location.reload(); return; }
                var m = Math.floor(remaining / 60);
                var s = remaining % 60;
                out.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }
            tick();
            setInterval(tick, 1000);
        }
    };

    /* ------------------------- Câblage ------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        Sig.init();
        Grace.init();

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
