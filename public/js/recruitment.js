(function () {
    'use strict';

    var body = document.body;
    var expiresAt = new Date(body.dataset.expiresAt).getTime();
    var chrono = document.getElementById('chrono');
    var form = document.getElementById('recrutement-form');

    /* Helper: null-safe field value read. Prevents TypeError if selector fails. */
    function fieldValue(fieldset, name) {
        var el = fieldset.querySelector('[name="' + name + '"]');
        return el ? (el.value || null) : null;
    }

    function tick() {
        var remaining = expiresAt - Date.now();
        if (remaining <= 0) {
            chrono.textContent = 'Temps écoulé — soumission en cours…';
            form.submit();
            return;
        }
        var minutes = Math.floor(remaining / 60000);
        var seconds = Math.floor((remaining % 60000) / 1000);
        chrono.textContent = 'Temps restant : ' + minutes + ' min ' + (seconds < 10 ? '0' : '') + seconds + ' s';
        window.setTimeout(tick, 1000);
    }

    tick();

    document.querySelectorAll('fieldset[data-answer-url]').forEach(function (fieldset) {
        var url = fieldset.dataset.answerUrl;

        fieldset.querySelectorAll('.js-champ').forEach(function (field) {
            field.addEventListener('change', function () {
                var payload = {
                    classement: fieldValue(fieldset, 'classement'),
                    ajustement_tva: fieldValue(fieldset, 'ajustement_tva'),
                    retenue_attendue: fieldValue(fieldset, 'retenue_attendue'),
                };

                fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': window.RECRUITMENT_CSRF,
                    },
                    body: JSON.stringify(payload),
                }).then(function (response) {
                    if (response.status === 410) {
                        window.location.reload();
                    }
                    if (!response.ok) {
                        console.error('Autosave failed (HTTP ' + response.status + ')');
                        field.setAttribute('data-unsaved', 'true');
                    } else {
                        field.removeAttribute('data-unsaved');
                    }
                }).catch(function (err) {
                    console.error('Autosave network error:', err.message);
                    field.setAttribute('data-unsaved', 'true');
                });
            });
        });
    });
})();
