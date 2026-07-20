{{-- Palette ⌘K --}}
<div class="cmdk-scrim" id="cmdk" hidden onclick="if(event.target===this)Detail.cmdk(false)">
    <div class="cmdk" role="dialog" aria-modal="true" aria-label="Recherche">
        <div class="cmdk__in">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="cmdk-input" type="text" placeholder="Rechercher un participant, une action…" autocomplete="off" aria-label="Recherche" oninput="Detail.cmdkRender()">
        </div>
        <div class="cmdk__list" id="cmdk-list"></div>
    </div>
</div>

<div class="scrim" id="scrim" hidden onclick="if(event.target===this)Detail.close()">
    {{-- Signature --}}
    <div class="modal" id="m-sig" role="dialog" aria-modal="true" aria-labelledby="sig-t" hidden>
        <div class="modal__hd"><h3 id="sig-t">Signature</h3><button class="modal__x" onclick="Detail.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <div class="modal__body" style="text-align:center">
            <p id="sig-who" class="mut" style="margin-bottom:10px"></p>
            <img id="sig-img" alt="Signature" style="max-width:100%;border:1px solid var(--border);border-radius:12px;background:var(--surface)">
        </div>
        <div class="modal__foot"><button class="btn btn--ghost" onclick="Detail.close()">Fermer</button></div>
    </div>

    {{-- Départ --}}
    <div class="modal" id="m-depart" role="dialog" aria-modal="true" aria-labelledby="dp-t" hidden>
        <div class="modal__hd"><h3 id="dp-t">Marquer un départ</h3><button class="modal__x" onclick="Detail.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <div class="modal__body"><p>Confirmez le départ de <strong id="dp-who">—</strong>. Son heure de départ sera enregistrée maintenant. Cette action est définitive et purement informative (sans effet sur les statistiques).</p></div>
        <div class="modal__foot"><button class="btn btn--ghost" onclick="Detail.close()">Annuler</button><button class="btn btn--primary" id="dp-confirm">Confirmer le départ</button></div>
    </div>

    {{-- Présence manuelle --}}
    <div class="modal modal--lg" id="m-manual" role="dialog" aria-modal="true" aria-labelledby="man-t" hidden>
        <div class="modal__hd"><h3 id="man-t">Ajouter une présence manuelle</h3><button class="modal__x" onclick="Detail.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form id="manual-form">
            <div class="modal__body">
                <div class="grid2">
                    <div class="field"><label for="m-last">Nom <span class="req">*</span></label><input class="control" id="m-last" name="last_name" required></div>
                    <div class="field"><label for="m-first">Prénom(s) <span class="req">*</span></label><input class="control" id="m-first" name="first_name" required></div>
                    <div class="field"><label for="m-email">Email <span class="opt">(facultatif)</span></label><input class="control" id="m-email" name="email" type="email"></div>
                    <div class="field"><label for="m-phone">Téléphone <span class="opt">(facultatif)</span></label><input class="control" id="m-phone" name="phone"></div>
                    <div class="field"><label for="m-company">Entité / Entreprise <span class="req">*</span></label><input class="control" id="m-company" name="company" required></div>
                    <div class="field"><label for="m-direction">Direction <span class="req">*</span></label><input class="control" id="m-direction" name="direction" required></div>
                    <div class="field"><label for="m-service">Service <span class="opt">(facultatif)</span></label><input class="control" id="m-service" name="service"></div>
                    <div class="field"><label for="m-position">Poste ou Fonction <span class="req">*</span></label><input class="control" id="m-position" name="position" required></div>
                </div>
                <label style="display:flex;gap:10px;align-items:flex-start;font-size:.86rem;color:var(--muted);margin-top:6px">
                    <input type="checkbox" id="m-confirm" name="manual_confirmed" style="width:20px;height:20px;accent-color:var(--accent);margin-top:1px">
                    <span>Je confirme manuellement la présence de cette personne (sans géolocalisation ni signature).</span>
                </label>
                <p class="mut" id="manual-error" style="color:var(--error);font-size:.82rem;margin-top:8px" hidden></p>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Detail.close()">Annuler</button><button type="submit" class="btn btn--primary">Ajouter la présence</button></div>
        </form>
    </div>

    {{-- Modifier (titre / type / lieu) --}}
    <div class="modal" id="m-edit" role="dialog" aria-modal="true" aria-labelledby="ed-t" hidden>
        <div class="modal__hd"><h3 id="ed-t">Modifier l'événement</h3><button class="modal__x" onclick="Detail.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form method="POST" action="{{ route('admin.events.update', $event) }}">
            @csrf
            @method('PATCH')
            <div class="modal__body">
                <p>Corrige le titre, le type ou le lieu (ex. faute de frappe). Les horaires se changent via « Reporter », le mode QR est verrouillé dès la première présence.</p>
                <div class="field {{ $errors->has('title') ? 'invalid' : '' }}">
                    <label for="ed-title">Titre <span class="req">*</span></label>
                    <input class="control" id="ed-title" name="title" value="{{ old('title', $event->title) }}" required>
                    <div class="err-msg">{{ $errors->first('title') }}</div>
                </div>
                <div class="field">
                    <label>Type d'événement <span class="req">*</span></label>
                    <div class="typepick" role="radiogroup" aria-label="Type d'événement">
                        @foreach ($types as $type)
                            <label class="typeopt" style="--tc:{{ $type->color }}">
                                <input type="radio" name="event_type_id" value="{{ $type->id }}" @checked(old('event_type_id', $event->event_type_id) == $type->id)>
                                <span class="typeopt__c">{{ $type->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="field"><label for="ed-lieu">Lieu <span class="opt">(facultatif)</span></label><input class="control" id="ed-lieu" name="location" value="{{ old('location', $event->location) }}"></div>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Detail.close()">Annuler</button><button type="submit" class="btn btn--primary">Enregistrer</button></div>
        </form>
    </div>

    {{-- Reporter l'événement --}}
    <div class="modal" id="m-reschedule" role="dialog" aria-modal="true" aria-labelledby="rs-t" hidden>
        <div class="modal__hd"><h3 id="rs-t">Reporter l'événement</h3><button class="modal__x" onclick="Detail.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form method="POST" action="{{ route('admin.events.reschedule', $event) }}">
            @csrf
            <div class="modal__body">
                <p>Choisissez le nouveau créneau. L'ancien horaire est conservé dans l'historique ; le mode QR et les présences existantes restent inchangés.</p>
                <div class="field"><label for="rs-date">Date <span class="req">*</span></label><input class="control" id="rs-date" type="date" name="date" value="{{ $event->starts_at->format('Y-m-d') }}" required></div>
                <div class="grid2">
                    <div class="field"><label for="rs-start">Heure de début <span class="req">*</span></label><input class="control" id="rs-start" type="time" name="start" value="{{ $event->starts_at->format('H:i') }}" required></div>
                    <div class="field"><label for="rs-end">Heure de fin <span class="req">*</span></label><input class="control" id="rs-end" type="time" name="end" value="{{ $event->ends_at->format('H:i') }}" required></div>
                </div>
                <div class="field"><label for="rs-reason">Motif <span class="opt">(facultatif)</span></label><input class="control" id="rs-reason" name="reason" maxlength="255"></div>
                @error('end')<div class="err-msg" style="display:block">{{ $message }}</div>@enderror
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Detail.close()">Annuler</button><button type="submit" class="btn btn--primary">Reporter</button></div>
        </form>
    </div>

    {{-- Annuler l'événement --}}
    <div class="modal" id="m-cancel" role="dialog" aria-modal="true" aria-labelledby="cn-t" hidden>
        <div class="modal__hd"><h3 id="cn-t">Annuler l'événement</h3><button class="modal__x" onclick="Detail.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form method="POST" action="{{ route('admin.events.cancel', $event) }}">
            @csrf
            <div class="modal__body">
                <p><strong>{{ $event->title }}</strong> passera au statut <strong>Annulé</strong>. Il reste visible mais n'est plus scannable ni émargeable. Les présences déjà enregistrées sont conservées. Cette action est réversible.</p>
                <div class="field"><label for="cn-reason">Motif <span class="opt">(facultatif)</span></label><input class="control" id="cn-reason" name="cancellation_reason" maxlength="255"></div>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Detail.close()">Revenir</button><button type="submit" class="btn btn--danger">Confirmer l'annulation</button></div>
        </form>
    </div>
</div>
