@extends('layouts.public')

@section('content')
    {{-- ÉCRAN : identification par email --}}
    <section class="screen" id="s-email">
        <div class="section-label">Votre email</div>
        <div class="field" data-req data-type="email">
            <label for="email">Email <span class="req">*</span></label>
            <input class="control" id="email" name="email" type="email" inputmode="email" autocomplete="email" placeholder="nom@entreprise.ci">
            <div class="err-msg">Entrez un email valide, ex. nom@entreprise.ci</div>
        </div>
        <button type="button" class="btn btn--primary btn--block btn--lg" id="emailContinue">Continuer</button>
    </section>

    {{-- ÉCRAN : formulaire (complet ou reconnaissance) --}}
    <section class="screen" id="s-form" hidden>
        <div id="departNote" class="flash" role="status" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <div>Votre départ de <b id="departNoteEv">l'événement précédent</b> a été enregistré. Vous pouvez maintenant vous enregistrer ici.</div>
        </div>

        <div id="geo" class="geo" role="status" aria-live="polite">
            <div class="geo__ic" id="geoIc">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
            </div>
            <div class="geo__body">
                <div class="geo__t" id="geoT">Localisation en cours…</div>
                <p class="geo__d" id="geoD">Confirme automatiquement que vous êtes bien sur place. Elle n'est pas utilisée à d'autres fins.</p>
            </div>
        </div>

        <div id="recog" class="recog" hidden>
            <div class="avatar" id="recogInit">?</div>
            <div>
                <div class="recog__t" id="recogHello">On vous reconnaît</div>
                <div class="recog__d" id="recogInfo"></div>
                <div class="recog__edit"><button type="button" class="linkbtn" id="recogEdit">Ce ne sont pas vos infos ? Modifier</button></div>
            </div>
        </div>

        <form id="form" novalidate>
            <div id="fullFields" hidden>
                <div class="section-label">Vos coordonnées</div>
                <div class="field" data-req>
                    <label for="last_name">Nom <span class="req">*</span></label>
                    <input class="control" id="last_name" name="last_name" autocomplete="family-name" placeholder="Koné">
                    <div class="err-msg">Ce champ est requis.</div>
                </div>
                <div class="field" data-req>
                    <label for="first_name">Prénom(s) <span class="req">*</span></label>
                    <input class="control" id="first_name" name="first_name" autocomplete="given-name" placeholder="Awa">
                    <div class="err-msg">Ce champ est requis.</div>
                </div>
                <div class="field" data-req>
                    <label for="phone">Téléphone <span class="req">*</span></label>
                    <input class="control" id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+225 07 00 00 00 00">
                    <div class="err-msg">Ce champ est requis.</div>
                </div>

                <div class="section-label">Votre structure</div>
                <div class="field" data-req>
                    <label for="company">Entité / Entreprise <span class="req">*</span></label>
                    <input class="control" id="company" name="company" autocomplete="organization" placeholder="Votre entreprise">
                    <div class="err-msg">Ce champ est requis.</div>
                </div>
                <div class="field" data-req>
                    <label for="direction">Direction <span class="req">*</span></label>
                    <input class="control" id="direction" name="direction" placeholder="Direction des Systèmes d'Information">
                    <div class="err-msg">Ce champ est requis.</div>
                </div>
                <div class="field">
                    <label for="service">Service <span class="opt">(facultatif)</span></label>
                    <input class="control" id="service" name="service" placeholder="Sécurité applicative">
                </div>
                <div class="field" data-req>
                    <label for="position">Poste ou Fonction <span class="req">*</span></label>
                    <input class="control" id="position" name="position" autocomplete="organization-title" placeholder="Analyste sécurité">
                    <div class="err-msg">Ce champ est requis.</div>
                </div>
            </div>

            <div class="section-label">Votre signature <span class="req" aria-hidden="true">*</span></div>
            <div class="sigwrap">
                <canvas id="sigpad" role="img" aria-label="Zone de signature manuscrite. Signez avec le doigt."></canvas>
                <div class="sig-ph" id="sigPh">Signez avec le doigt</div>
            </div>
            <div class="sig-actions">
                <span class="help">Utilisez votre doigt pour signer dans le cadre.</span>
                <button type="button" class="btn btn--ghost" id="sigClear">Effacer</button>
            </div>

            <label class="consent">
                <input type="checkbox" id="consent">
                <span>Je reconnais avoir pris connaissance du traitement de mes données.
                    <button type="button" class="linkbtn" data-modal="mentions">Détails</button></span>
            </label>

            <button type="button" class="btn btn--primary btn--block btn--lg" id="submit" disabled>Valider ma présence</button>

            <div class="foot">
                <button type="button" class="linkbtn" data-modal="mentions">Vos données</button> · ACS Groupe
            </div>
        </form>
    </section>

    {{-- ÉCRAN : confirmation --}}
    <section class="screen" id="s-confirm" hidden>
        <div class="confirm" role="status">
            <div class="confirm__badge">
                <svg viewBox="0 0 52 52" aria-hidden="true"><path class="check-path" d="M14 27 l8 8 l16 -18"/></svg>
            </div>
            <h1>Présence enregistrée</h1>
            <p id="confirmMsg">Votre présence est confirmée.</p>
            <p class="ts" id="confirmTs"></p>
            <div class="ref" id="confirmRef"></div>
            <p style="margin-top:18px">Vous pouvez fermer cette page.</p>
            <div class="foot"><button type="button" class="linkbtn" data-modal="mentions">Vos données · mentions</button></div>
        </div>
    </section>

    {{-- ÉCRAN : erreur géoloc --}}
    <section class="screen" id="s-error" hidden>
        <div class="errscreen">
            <div class="errscreen__ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><line x1="9.5" y1="9.5" x2="14.5" y2="14.5"/><line x1="14.5" y1="9.5" x2="9.5" y2="14.5"/></svg>
            </div>
            <h1>Nous avons besoin de votre position</h1>
            <p>Votre position confirme votre présence sur place. Sans elle, l'émargement ne peut pas être validé.</p>
            <ul class="tips">
                <li>Autorisez la localisation dans votre navigateur.</li>
                <li>Vérifiez que le GPS / la localisation est activé(e) sur votre appareil.</li>
            </ul>
            <div class="stack">
                <button type="button" class="btn btn--primary btn--block" id="geoRetry">Réessayer la localisation</button>
                <button type="button" class="btn btn--ghost btn--block" data-modal="help">Comment autoriser ?</button>
            </div>
        </div>
    </section>

    {{-- Modals --}}
    <div class="scrim" id="scrim" hidden>
        <div class="modal" id="modal-submit" role="dialog" aria-modal="true" aria-labelledby="m-sub-t" hidden>
            <div class="modal__head">
                <div class="modal__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></div>
                <button class="modal__x" aria-label="Fermer" data-close><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" width="20" height="20" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button>
            </div>
            <h2 id="m-sub-t">Confirmer votre présence ?</h2>
            <p class="who" id="m-sub-who">—</p>
            <p>Votre position et votre signature vont être enregistrées pour cet événement.</p>
            <hr class="modal__sep">
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-close>Annuler</button>
                <button type="button" class="btn btn--primary" id="m-confirm">Confirmer ma présence</button>
            </div>
        </div>

        <div class="modal" id="modal-mentions" role="dialog" aria-modal="true" aria-labelledby="m-men-t" hidden>
            <div class="modal__head">
                <div class="modal__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg></div>
                <button class="modal__x" aria-label="Fermer" data-close><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" width="20" height="20" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button>
            </div>
            <h2 id="m-men-t">Traitement de vos données</h2>
            <p>ACS Groupe collecte ces informations (identité, coordonnées, structure, position et signature) dans le seul but d'établir la liste de présence de cet événement.</p>
            <p style="margin-top:8px">Conservation, base légale et droits selon le cadre ivoirien (Loi n°2013-450, ARTCI). <i>(Contenu à finaliser.)</i></p>
            <hr class="modal__sep">
            <div class="modal__foot"><button type="button" class="btn btn--primary" data-close>J'ai compris</button></div>
        </div>

        <div class="modal" id="modal-help" role="dialog" aria-modal="true" aria-labelledby="m-help-t" hidden>
            <div class="modal__head">
                <div class="modal__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg></div>
                <button class="modal__x" aria-label="Fermer" data-close><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" width="20" height="20" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button>
            </div>
            <h2 id="m-help-t">Autoriser la localisation</h2>
            <p><b>iPhone (Safari)</b> : Réglages › Safari › Localisation › Autoriser, puis rechargez.</p>
            <p style="margin-top:8px"><b>Android (Chrome)</b> : icône cadenas › Autorisations › Localisation › Autoriser.</p>
            <p style="margin-top:8px"><b>Ordinateur</b> : cadenas à gauche de l'adresse › Localisation › Autoriser.</p>
            <hr class="modal__sep">
            <div class="modal__foot"><button type="button" class="btn btn--primary" data-close>Fermer</button></div>
        </div>

        <div class="modal" id="modal-overlap" role="dialog" aria-modal="true" aria-labelledby="m-ov-t" hidden>
            <div class="modal__head">
                <div class="modal__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h13l-3-3M20 17H7l3 3"/></svg></div>
                <button class="modal__x" aria-label="Fermer" data-close><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" width="20" height="20" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button>
            </div>
            <h2 id="m-ov-t">Vous êtes déjà à un autre événement</h2>
            <p>D'après votre email, vous êtes actuellement enregistré(e) à cet événement :</p>
            <div class="ov-card">
                <div class="ov-card__t" id="ov-ev">—</div>
                <div class="ov-card__row"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span id="ov-when"></span></div>
                <div class="ov-card__row" id="ov-lieu-wrap" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg><span id="ov-lieu"></span></div>
            </div>
            <p>Pour vous enregistrer ici, nous enregistrerons votre départ de l'événement ci-dessus. Vous ne pouvez être présent(e) qu'à un seul événement à la fois.</p>
            <hr class="modal__sep">
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-close>Annuler</button>
                <button type="button" class="btn btn--primary" id="ov-confirm">Confirmer mon départ et continuer</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    window.PRESENCE = {
        csrf: @json(csrf_token()),
        ticket: @json($ticket),
        urls: {
            recognize: @json(route('public.attendance.recognize', ['event' => $event->public_slug])),
            store: @json(route('public.attendance.store', ['event' => $event->public_slug])),
        },
        eventTitle: @json($event->title),
    };
</script>
<script src="{{ versioned_asset('js/participant.js') }}"></script>
@endpush
