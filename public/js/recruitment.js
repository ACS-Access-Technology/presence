(function () {
    'use strict';

    var body = document.body;
    var expiresAt = new Date(body.dataset.expiresAt).getTime();
    var chrono = document.getElementById('chrono');
    var chronoText = chrono ? chrono.querySelector('span') : null;
    var form = document.getElementById('recrutement-form');

    /* Helper: null-safe field value read. Prevents TypeError if selector fails. */
    function fieldValue(fieldset, name) {
        var el = fieldset.querySelector('[name="' + name + '"]');
        return el ? (el.value || null) : null;
    }

    function setChronoText(text) {
        (chronoText || chrono).textContent = text;
    }

    function tick() {
        var remaining = expiresAt - Date.now();
        if (remaining <= 0) {
            setChronoText('Temps écoulé — soumission en cours…');
            form.submit();
            return;
        }
        var minutes = Math.floor(remaining / 60000);
        var seconds = Math.floor((remaining % 60000) / 1000);
        setChronoText('Temps restant : ' + minutes + ' min ' + (seconds < 10 ? '0' : '') + seconds + ' s');
        chrono.setAttribute('data-low', remaining < 5 * 60000 ? 'true' : 'false');
        window.setTimeout(tick, 1000);
    }

    tick();

    /* Wizard : une transaction affichée à la fois (navigation Précédent/Suivant),
       aucune requête serveur — la clôture par rechargement (registerPageOpen)
       ne se déclenche que sur un VRAI rechargement de page, pas un changement
       d'étape. */
    var steps = Array.prototype.slice.call(document.querySelectorAll('.rec-q[data-step]'));
    var totalSteps = steps.length;
    var currentStep = 1;
    var stepLabel = document.getElementById('stepLabel');
    var stepFill = document.getElementById('stepFill');
    var btnPrev = document.getElementById('stepPrev');
    var btnNext = document.getElementById('stepNext');
    var btnSubmit = document.getElementById('stepSubmit');

    function showStep(n) {
        currentStep = n;
        steps.forEach(function (fieldset) {
            fieldset.hidden = Number(fieldset.dataset.step) !== n;
        });
        if (stepLabel) {
            stepLabel.textContent = 'Transaction ' + n + ' / ' + totalSteps;
        }
        if (stepFill) {
            stepFill.style.width = (n / totalSteps * 100) + '%';
        }
        if (btnPrev) {
            btnPrev.hidden = n === 1;
        }
        if (btnNext) {
            btnNext.hidden = n === totalSteps;
        }
        if (btnSubmit) {
            btnSubmit.hidden = n !== totalSteps;
        }
    }

    if (totalSteps > 0) {
        showStep(1);
        if (btnPrev) {
            btnPrev.addEventListener('click', function () {
                showStep(Math.max(1, currentStep - 1));
            });
        }
        if (btnNext) {
            btnNext.addEventListener('click', function () {
                showStep(Math.min(totalSteps, currentStep + 1));
            });
        }
    }

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
