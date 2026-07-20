<div class="scrim" id="s-scrim" hidden onclick="if(event.target===this)Settings.close()">
    {{-- Ajouter / modifier un type --}}
    <div class="modal" id="m-type" role="dialog" aria-modal="true" aria-labelledby="mt-title" hidden>
        <div class="modal__hd"><h3 id="mt-title">Ajouter un type</h3><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form onsubmit="return Settings.saveType(event)">
            <div class="modal__body">
                <div class="field"><label for="mt-name">Nom du type <span class="req">*</span></label><input class="control" id="mt-name" placeholder="Ex. Séminaire" autocomplete="off"><div class="err-msg" id="mt-err"></div></div>
                <div class="field"><label>Couleur du badge</label><div class="swatches" id="mt-swatches"></div></div>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.close()">Annuler</button><button type="submit" class="btn btn--primary">Enregistrer</button></div>
        </form>
    </div>

    {{-- Inviter / modifier un compte --}}
    <div class="modal" id="m-account" role="dialog" aria-modal="true" aria-labelledby="ma-title" hidden>
        <div class="modal__hd"><h3 id="ma-title">Inviter un compte</h3><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <form onsubmit="return Settings.saveAccount(event)">
            <div class="modal__body">
                <div class="grid2">
                    <div class="field"><label for="ma-name">Nom complet <span class="req">*</span></label><input class="control" id="ma-name" autocomplete="off"></div>
                    <div class="field"><label for="ma-email">Email <span class="req">*</span></label><input class="control" id="ma-email" type="email" autocomplete="off"></div>
                </div>
                <div class="field"><label for="ma-role">Rôle <span class="req">*</span></label>
                    <select class="control" id="ma-role">
                        <option value="organisateur">Organisateur — crée et gère ses événements</option>
                        <option value="admin">Administrateur — accès complet (paramètres inclus)</option>
                    </select>
                </div>
                <label class="remember" id="ma-status-wrap" style="display:none;gap:9px;align-items:center;font-size:.88rem;color:var(--muted)"><input type="checkbox" id="ma-active" style="width:18px;height:18px;accent-color:var(--accent)"> Compte actif</label>
                <div class="err-msg" id="ma-err"></div>
            </div>
            <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.close()">Annuler</button><button type="submit" class="btn btn--primary" id="ma-submit">Créer le compte</button></div>
        </form>
    </div>

    {{-- Mot de passe temporaire --}}
    <div class="modal" id="m-password" role="dialog" aria-modal="true" aria-labelledby="mp-title" hidden>
        <div class="modal__hd"><h3 id="mp-title">Mot de passe temporaire</h3><button class="modal__x" onclick="Settings.close()" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <div class="modal__body">
            <p>Transmettez ce mot de passe au membre par un canal sûr. Il ne sera plus affiché ensuite.</p>
            <div class="pw-reveal"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><code id="mp-value">—</code></div>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--ghost" onclick="Settings.copyPassword()">Copier</button><button type="button" class="btn btn--primary" onclick="Settings.close()">J'ai transmis</button></div>
    </div>
</div>
