(function () {
    'use strict';

    /* Échéance calculée depuis le temps restant fourni par le SERVEUR, mesuré
       ensuite avec l'horloge monotone (performance.now) : une horloge système
       du candidat mal réglée ne doit ni soumettre le test dès le chargement
       (page chargeable une seule fois = score 0 sans recours), ni afficher un
       temps que le serveur refusera (410). */
    var body = document.body;
    var deadline = performance.now() + (Number(body.dataset.remaining) || 0) * 1000;
    var chrono = document.getElementById('chrono');
    var chronoText = chrono ? chrono.querySelector('span') : null;
    var form = document.getElementById('recrutement-form');
    var submitting = false;

    /* Helper: null-safe field value read. Prevents TypeError if selector fails. */
    function fieldValue(fieldset, name) {
        var el = fieldset.querySelector('[name="' + name + '"]');
        return el ? (el.value || null) : null;
    }

    function setChronoText(text) {
        (chronoText || chrono).textContent = text;
    }

    function tick() {
        if (submitting) {
            return;
        }
        var remaining = deadline - performance.now();
        if (remaining <= 0) {
            setChronoText('Temps écoulé — soumission en cours…');
            submitAfterSaves();
            return;
        }
        var minutes = Math.floor(remaining / 60000);
        var seconds = Math.floor((remaining % 60000) / 1000);
        setChronoText('Temps restant : ' + minutes + ' min ' + (seconds < 10 ? '0' : '') + seconds + ' s');
        chrono.setAttribute('data-low', remaining < 5 * 60000 ? 'true' : 'false');
        window.setTimeout(tick, 1000);
    }

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

    /* Autosave. Le serveur ne note QUE les réponses déjà enregistrées (il
       ignore les champs postés à la soumission) : avant de soumettre, il faut
       donc enregistrer les étapes modifiées mais pas encore sauvegardées (champ
       encore focalisé, timer) et attendre les PATCH en vol — sinon la
       navigation les annule et la dernière réponse est perdue. */
    var dirty = [];
    var pending = [];

    function markDirty(fieldset) {
        if (dirty.indexOf(fieldset) === -1) {
            dirty.push(fieldset);
        }
    }

    function save(fieldset) {
        var i = dirty.indexOf(fieldset);
        if (i !== -1) {
            dirty.splice(i, 1);
        }
        var payload = {
            classement: fieldValue(fieldset, 'classement'),
            ajustement_tva: fieldValue(fieldset, 'ajustement_tva'),
            retenue_attendue: fieldValue(fieldset, 'retenue_attendue'),
        };

        var box = fieldset.querySelector('.rec-q__fields') || fieldset;
        var request = fetch(fieldset.dataset.answerUrl, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': window.RECRUITMENT_CSRF,
            },
            body: JSON.stringify(payload),
        }).then(function (response) {
            if (response.status === 410 && !submitting) {
                window.location.reload();
            }
            if (!response.ok) {
                console.error('Autosave failed (HTTP ' + response.status + ')');
                box.setAttribute('data-unsaved', 'true');
            } else {
                box.removeAttribute('data-unsaved');
            }
        }).catch(function (err) {
            console.error('Autosave network error:', err.message);
            box.setAttribute('data-unsaved', 'true');
        }).then(function () {
            pending.splice(pending.indexOf(request), 1);
        });
        pending.push(request);

        return request;
    }

    function submitAfterSaves() {
        if (submitting) {
            return;
        }
        submitting = true;
        dirty.slice().forEach(save);
        // Les échecs sont déjà absorbés par save() : on soumet quoi qu'il arrive
        // (au pire, la réponse non enregistrée n'est pas notée — jamais de blocage).
        Promise.all(pending.slice()).then(function () {
            form.submit();
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (btnSubmit) {
            btnSubmit.disabled = true;
        }
        submitAfterSaves();
    });

    document.querySelectorAll('fieldset[data-answer-url]').forEach(function (fieldset) {
        fieldset.querySelectorAll('.js-champ').forEach(function (field) {
            field.addEventListener('input', function () {
                markDirty(fieldset);
            });
            field.addEventListener('change', function () {
                save(fieldset);
            });
        });
    });

    tick();
})();
