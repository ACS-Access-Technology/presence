/**
 * Transfert d'un événement vers une autre filiale (modale SuperAdmin).
 *
 * Rôle unique : peupler le sélecteur de TYPE en fonction de la filiale de
 * destination choisie. Les types sont cloisonnés par filiale — l'ancien type ne
 * s'applique pas ; l'utilisateur doit en choisir un valide dans la cible. Si la
 * filiale choisie n'a aucun type actif, on bloque la soumission et on l'explique
 * (le serveur rejetterait de toute façon : ceinture + bretelles).
 *
 * XSS-safe : tout texte injecté passe par textContent, jamais innerHTML.
 */
(function () {
    'use strict';

    var data = window.EVENT_TRANSFER;
    if (!data || !Array.isArray(data.filiales)) {
        return;
    }

    var filialeSelect = document.getElementById('tr-filiale');
    var typeWrap = document.getElementById('tr-type-wrap');
    var submitBtn = document.getElementById('tr-submit');
    if (!filialeSelect || !typeWrap) {
        return;
    }

    var byId = {};
    data.filiales.forEach(function (f) {
        byId[String(f.id)] = f;
    });

    function setSubmitEnabled(enabled) {
        if (submitBtn) {
            submitBtn.disabled = !enabled;
        }
    }

    function renderTypes(filialeId) {
        typeWrap.textContent = '';
        var filiale = byId[String(filialeId)];
        var types = filiale && Array.isArray(filiale.types) ? filiale.types : [];

        if (types.length === 0) {
            var warning = document.createElement('p');
            warning.className = 'err-msg';
            warning.style.display = 'block';
            warning.textContent =
                "Cette filiale n'a aucun type d'événement actif. Créez-en un dans ses Paramètres avant de transférer.";
            typeWrap.appendChild(warning);
            setSubmitEnabled(false);
            return;
        }

        types.forEach(function (type, index) {
            var label = document.createElement('label');
            label.className = 'typeopt';
            if (type.color) {
                label.style.setProperty('--tc', type.color);
            }

            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'event_type_id';
            input.value = type.id;
            if (index === 0) {
                input.checked = true;
            }

            var span = document.createElement('span');
            span.className = 'typeopt__c';
            span.textContent = type.name;

            label.appendChild(input);
            label.appendChild(span);
            typeWrap.appendChild(label);
        });

        setSubmitEnabled(true);
    }

    filialeSelect.addEventListener('change', function () {
        renderTypes(filialeSelect.value);
    });

    // Initialisation sur la filiale présélectionnée (première option).
    renderTypes(filialeSelect.value);
})();
