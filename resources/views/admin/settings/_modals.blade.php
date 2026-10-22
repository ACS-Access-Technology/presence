<div class="scrim" id="s-scrim" hidden onclick="if(event.target===this)Settings.close()">
    {{-- Ajouter / modifier un type --}}
    <div class="modal modal--create" id="m-type" role="dialog" aria-modal="true" aria-labelledby="mt-title" hidden>
        <div class="modal__hd"><span class="modal__ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></span><div class="modal__ttl"><h3 id="mt-title">Ajouter un type</h3><p class="modal__sub">Catégorie proposée à la création d'un événement</p></div><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form onsubmit="return Settings.saveType(event)">
            <div class="modal__body">
                <div class="field"><label for="mt-name">Nom du type <span class="req">*</span></label><input class="control" id="mt-name" placeholder="Ex. Séminaire" autocomplete="off"><div class="err-msg" id="mt-err"></div></div>
                @if($isSuper)
                    {{-- Rattachement explicite à une filiale (création uniquement) :
                         contexte topbar fixé → affiché en clair ; « Toutes les
                         filiales » → choix obligatoire. Même logique que l'invitation
                         de compte. --}}
                    <div class="field" id="mt-filiale-wrap">
                        <label>Filiale <span class="req">*</span></label>
                        <p class="control" id="mt-filiale-fixed" style="display:flex;align-items:center;color:var(--muted)"></p>
                        <select class="control" id="mt-filiale">
                            @foreach($filiales as $f)
                                <option value="{{ $f['id'] }}">{{ $f['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="field"><label>Couleur du badge</label><div class="swatches" id="mt-swatches"></div></div>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.close()">Annuler</button><button type="submit" class="btn btn--primary">Enregistrer</button></div>
        </form>
    </div>

    {{-- Inviter / modifier un compte --}}
    <div class="modal modal--create" id="m-account" role="dialog" aria-modal="true" aria-labelledby="ma-title" hidden>
        <div class="modal__hd"><span class="modal__ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg></span><div class="modal__ttl"><h3 id="ma-title">Inviter un compte</h3><p class="modal__sub">Donner accès à la plateforme</p></div><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form onsubmit="return Settings.saveAccount(event)">
            <div class="modal__body">
                <div class="grid2">
                    <div class="field"><label for="ma-name">Nom complet <span class="req">*</span></label><input class="control" id="ma-name" autocomplete="off"></div>
                    <div class="field"><label for="ma-email">Email <span class="req">*</span></label><input class="control" id="ma-email" type="email" autocomplete="off"></div>
                </div>
                <div class="field"><label for="ma-role">Rôle <span class="req">*</span></label>
                    <select class="control" id="ma-role">
                        <option value="organisateur">Organisateur — crée et gère ses événements</option>
                        @if($isSuper)
                            <option value="admin_filiale">AdminFiliale — gère les comptes et le paramétrage de sa filiale</option>
                        @endif
                    </select>
                </div>
                @if($isSuper)
                    <div class="field" id="ma-filiale-wrap">
                        <label>Filiale <span class="req">*</span></label>
                        {{-- Contexte déjà fixé (topbar) : pas de choix redondant. --}}
                        <p class="control" id="ma-filiale-fixed" style="display:flex;align-items:center;color:var(--muted)"></p>
                        {{-- Contexte « Toutes les filiales » : le choix est obligatoire. --}}
                        <select class="control" id="ma-filiale">
                            @foreach($filiales as $f)
                                <option value="{{ $f['id'] }}">{{ $f['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <label class="remember" id="ma-status-wrap" style="display:none;gap:9px;align-items:center;font-size:.88rem;color:var(--muted)"><input type="checkbox" id="ma-active" style="width:18px;height:18px;accent-color:var(--accent)"> Compte actif</label>
                <div class="err-msg" id="ma-err"></div>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.close()">Annuler</button><button type="submit" class="btn btn--primary" id="ma-submit">Créer le compte</button></div>
        </form>
    </div>

    {{-- Réassigner un compte à une autre filiale (SuperAdmin) --}}
    <div class="modal modal--edit" id="m-reassign" role="dialog" aria-modal="true" aria-labelledby="mr-title" hidden>
        <div class="modal__hd"><span class="modal__ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3l4 4-4 4"/><path d="M3 7h18"/><path d="M7 21l-4-4 4-4"/><path d="M21 17H3"/></svg></span><div class="modal__ttl"><h3 id="mr-title">Réassigner le compte</h3><p class="modal__sub">Changer la filiale de rattachement du compte</p></div><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <div class="modal__body">
            <p class="mut" id="mr-who" style="margin-bottom:12px"></p>
            <div class="field"><label for="mr-filiale">Nouvelle filiale <span class="req">*</span></label>
                <select class="control" id="mr-filiale">
                    @foreach($filiales as $f)
                        <option value="{{ $f['id'] }}">{{ $f['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="notice" style="margin-top:12px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg><span>Les événements déjà créés par ce compte restent dans leur filiale d'origine — ils ne suivent pas la réassignation.</span></div>
            <div class="err-msg" id="mr-err"></div>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.close()">Annuler</button><button type="button" class="btn btn--primary" onclick="Settings.saveReassign()">Réassigner</button></div>
    </div>

    {{-- Mot de passe temporaire --}}
    <div class="modal modal--read" id="m-password" role="dialog" aria-modal="true" aria-labelledby="mp-title" hidden>
        <div class="modal__hd"><span class="modal__ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M17 6l3 3M14 9l2 2"/></svg></span><div class="modal__ttl"><h3 id="mp-title">Mot de passe temporaire</h3><p class="modal__sub">À transmettre une seule fois à la personne concernée</p></div><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <div class="modal__body">
            <p>Transmettez ce mot de passe au membre par un canal sûr. Il ne sera plus affiché ensuite.</p>
            <div class="pw-reveal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><code id="mp-value">—</code></div>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.copyPassword()">Copier</button><button type="button" class="btn btn--primary" onclick="Settings.close()">J'ai transmis</button></div>
    </div>
</div>
