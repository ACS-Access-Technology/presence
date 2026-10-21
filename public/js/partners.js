/* Rencontres partenaires — fiche partenaire.
   Pipette : les couleurs dominantes du logo sont extraites DANS LE NAVIGATEUR
   (canvas), aucun traitement serveur. La couleur principale est assombrie si
   besoin pour que du texte blanc reste lisible dessus (contraste WCAG ≥ 4,5:1),
   puisqu'elle sert de fond (bandeau, bouton) sur le téléphone du participant. */
(function () {
    'use strict';

    var form = document.getElementById('pt-form');
    var formEl = document.getElementById('pt-form-el');
    if (!form || !formEl) {
        return;
    }

    var $ = function (id) { return document.getElementById(id); };
    var logoInput = $('pt-logo');
    var swatchBox = $('pt-swatches');
    var primary = $('pt-primary');
    var secondary = $('pt-secondary');
    var primaryPicker = $('pt-primary-picker');
    var secondaryPicker = $('pt-secondary-picker');
    var contrastNote = $('pt-contrast-note');
    var preview = $('pt-preview');
    var pvLogo = $('pt-pv-logo');
    var pvName = $('pt-pv-name');
    var nameInput = $('pt-name');

    var HEX = /^#[0-9a-f]{6}$/i;

    function toHex(r, g, b) {
        return '#' + [r, g, b].map(function (v) { return ('0' + v.toString(16)).slice(-2); }).join('');
    }
    function fromHex(hex) {
        return [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); });
    }
    function luminance(rgb) {
        var c = rgb.map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }
    function contrastWithWhite(hex) {
        return 1.05 / (luminance(fromHex(hex)) + 0.05);
    }
    /* Assombrit par pas de 5 % jusqu'à atteindre 4,5:1 contre le blanc. */
    function readable(hex) {
        var rgb = fromHex(hex);
        var guard = 0;
        while (contrastWithWhite(toHex(rgb[0], rgb[1], rgb[2])) < 4.5 && guard < 40) {
            rgb = rgb.map(function (v) { return Math.round(v * 0.95); });
            guard++;
        }
        return toHex(rgb[0], rgb[1], rgb[2]);
    }

    function setColor(which, hex) {
        if (!HEX.test(hex)) {
            return;
        }
        hex = hex.toLowerCase();
        if (which === 'primary') {
            var fixed = readable(hex);
            primary.value = fixed;
            primaryPicker.value = fixed;
            contrastNote.textContent = fixed !== hex
                ? 'Couleur légèrement assombrie (' + hex + ' → ' + fixed + ') pour que le texte blanc reste lisible.'
                : '';
        } else {
            secondary.value = hex;
            secondaryPicker.value = hex;
        }
        refreshPreview();
    }

    function refreshPreview() {
        if (HEX.test(primary.value)) {
            preview.style.setProperty('--pt-primary', primary.value);
        }
        pvName.textContent = nameInput.value.trim() || 'Nom du partenaire';
    }

    /* Couleurs dominantes : image réduite à 64 px, pixels regroupés par
       quantification (4 bits/canal), blancs/noirs/transparents ignorés, puis
       couleurs trop proches fusionnées. */
    function extractColors(img) {
        var size = 64;
        var canvas = document.createElement('canvas');
        var ratio = Math.min(size / img.naturalWidth, size / img.naturalHeight, 1);
        canvas.width = Math.max(1, Math.round(img.naturalWidth * ratio));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * ratio));
        var ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        var data;
        try {
            data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
        } catch (e) {
            return [];
        }
        var buckets = new Map();
        for (var i = 0; i < data.length; i += 4) {
            var r = data[i], g = data[i + 1], b = data[i + 2], a = data[i + 3];
            if (a < 128) { continue; }
            var max = Math.max(r, g, b), min = Math.min(r, g, b);
            if (max > 240 && min > 240) { continue; }   // blanc / fond
            if (max < 18) { continue; }                 // noir pur
            var key = (r >> 4) + ',' + (g >> 4) + ',' + (b >> 4);
            var bucket = buckets.get(key) || { n: 0, r: 0, g: 0, b: 0 };
            bucket.n++; bucket.r += r; bucket.g += g; bucket.b += b;
            buckets.set(key, bucket);
        }
        var sorted = Array.from(buckets.values()).sort(function (x, y) { return y.n - x.n; });
        var picked = [];
        sorted.forEach(function (bk) {
            if (picked.length >= 6) { return; }
            var rgb = [Math.round(bk.r / bk.n), Math.round(bk.g / bk.n), Math.round(bk.b / bk.n)];
            var tooClose = picked.some(function (p) {
                return Math.abs(p[0] - rgb[0]) + Math.abs(p[1] - rgb[1]) + Math.abs(p[2] - rgb[2]) < 60;
            });
            if (!tooClose) { picked.push(rgb); }
        });
        return picked.map(function (rgb) { return toHex(rgb[0], rgb[1], rgb[2]); });
    }

    function target() {
        var checked = formEl.querySelector('input[name="_target"]:checked');
        return checked ? checked.value : 'primary';
    }

    function showSwatches(colors, autoApply) {
        swatchBox.innerHTML = '';
        if (colors.length === 0) {
            swatchBox.innerHTML = '<span class="mut">Aucune couleur nette trouvée : choisissez-la à la main.</span>';
            return;
        }
        colors.forEach(function (hex) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pt-swatch';
            btn.style.setProperty('--c', hex);
            btn.title = hex;
            btn.setAttribute('aria-label', 'Appliquer la couleur ' + hex);
            btn.addEventListener('click', function () { setColor(target(), hex); });
            swatchBox.appendChild(btn);
        });
        if (autoApply) {
            setColor('primary', colors[0]);
            if (colors[1]) { setColor('secondary', colors[1]); }
        }
    }

    function loadLogo(src, autoApply) {
        var img = new Image();
        img.onload = function () {
            pvLogo.src = src;
            pvLogo.hidden = false;
            showSwatches(extractColors(img), autoApply);
        };
        img.src = src;
    }

    logoInput.addEventListener('change', function () {
        var file = logoInput.files && logoInput.files[0];
        if (!file) { return; }
        var reader = new FileReader();
        reader.onload = function () { loadLogo(String(reader.result), true); };
        reader.readAsDataURL(file);
    });

    primaryPicker.addEventListener('input', function () { setColor('primary', primaryPicker.value); });
    secondaryPicker.addEventListener('input', function () { setColor('secondary', secondaryPicker.value); });
    primary.addEventListener('change', function () { setColor('primary', primary.value.trim()); });
    secondary.addEventListener('change', function () {
        if (secondary.value.trim() === '') { return; }
        setColor('secondary', secondary.value.trim());
    });
    nameInput.addEventListener('input', refreshPreview);

    function open(partner) {
        form.hidden = false;
        $('pt-form-title').textContent = partner ? 'Modifier le partenaire' : 'Nouveau partenaire';
        $('pt-logo-req').hidden = !!partner;
        formEl.action = partner ? partner.update_url : formEl.dataset.storeUrl;
        $('pt-id').value = partner ? partner.id : '';
        nameInput.value = partner ? partner.name : '';
        $('pt-contact-name').value = partner ? (partner.contact_name || '') : '';
        $('pt-contact-email').value = partner ? (partner.contact_email || '') : '';
        logoInput.value = '';
        var filialeField = $('pt-filiale-field');
        if (filialeField) { filialeField.hidden = !!partner; }
        swatchBox.innerHTML = '<span class="mut">Déposez un logo pour voir ses couleurs.</span>';
        pvLogo.hidden = true;
        setColor('primary', partner ? partner.primary_color : '#1e2a78');
        if (partner && partner.secondary_color) {
            setColor('secondary', partner.secondary_color);
        } else {
            secondary.value = '';
        }
        if (partner) { loadLogo(partner.logo_url, false); }
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        nameInput.focus({ preventScroll: true });
    }

    $('pt-new').addEventListener('click', function () { open(null); });
    $('pt-cancel').addEventListener('click', function () { form.hidden = true; });
    document.querySelectorAll('.js-pt-edit').forEach(function (btn) {
        btn.addEventListener('click', function () { open(JSON.parse(btn.dataset.partner)); });
    });

    refreshPreview();
})();
