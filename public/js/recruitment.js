(function () {
    'use strict';

    var body = document.body;
    var expiresAt = new Date(body.dataset.expiresAt).getTime();
    var chrono = document.getElementById('chrono');
    var form = document.getElementById('recrutement-form');

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
                    classement: fieldset.querySelector('[name="classement"]').value || null,
                    ajustement_tva: fieldset.querySelector('[name="ajustement_tva"]').value || null,
                    retenue_attendue: fieldset.querySelector('[name="retenue_attendue"]').value || null,
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
                });
            });
        });
    });
})();
