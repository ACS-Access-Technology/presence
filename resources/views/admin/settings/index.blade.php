@extends('layouts.admin', ['nav' => 'settings'])

@section('title', 'Paramètres')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Paramètres</h1>
            <p>Référentiels et configuration de la plateforme Presence.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>{{ session('status') }}</div>
    @endif

    <div class="tabs" role="tablist" aria-label="Sections des paramètres">
        <button class="tab" role="tab" id="tab-types" aria-selected="true" onclick="Settings.tab('types')">Types d'événement</button>
        <button class="tab" role="tab" id="tab-comptes" aria-selected="false" onclick="Settings.tab('comptes')">Comptes organisateurs</button>
        <button class="tab" role="tab" id="tab-general" aria-selected="false" onclick="Settings.tab('general')">Général &amp; branding</button>
        <button class="tab" role="tab" id="tab-data" aria-selected="false" onclick="Settings.tab('data')">Conservation des données</button>
    </div>

    {{-- ===== Types ===== --}}
    <section class="panel" id="panel-types" role="tabpanel">
        <div class="card">
            <div class="card__hd">
                <div><h2>Types d'événement</h2><p>Catégories proposées à la création, avec leur couleur de badge.</p></div>
                <button class="btn btn--primary" onclick="Settings.typeModal(null)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 5v14M5 12h14"/></svg>Ajouter un type</button>
            </div>
            <div class="tscroll">
                <table class="dt" aria-label="Types d'événement">
                    <thead><tr><th>Type</th><th>Couleur</th><th>Statut</th><th>Utilisation</th><th class="r-actions">Actions</th></tr></thead>
                    <tbody id="types-body"></tbody>
                </table>
            </div>
        </div>
        <div class="notice"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><span>Un type <strong>désactivé</strong> reste sur les événements qui l'utilisent mais n'est plus proposé. La suppression n'est possible que si aucun événement ne l'utilise.</span></div>
    </section>

    {{-- ===== Comptes ===== --}}
    <section class="panel" id="panel-comptes" role="tabpanel" hidden>
        <div class="card">
            <div class="card__hd">
                <div><h2>Comptes organisateurs internes</h2><p>Membres d'ACS Groupe autorisés à accéder au tableau de bord.</p></div>
                <button class="btn btn--primary" onclick="Settings.accountModal(null)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>Inviter un compte</button>
            </div>
            <div class="tscroll">
                <table class="dt" aria-label="Comptes organisateurs">
                    <thead><tr><th>Membre</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th class="r-actions">Actions</th></tr></thead>
                    <tbody id="accounts-body"></tbody>
                </table>
            </div>
        </div>
        <div class="notice"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span>Pas d'inscription publique : seul un <strong>administrateur</strong> crée les comptes. Pas de « mot de passe oublié » en libre-service — la réinitialisation se fait ici, puis le mot de passe temporaire est transmis au membre.</span></div>
    </section>

    {{-- ===== Général & branding ===== --}}
    <section class="panel" id="panel-general" role="tabpanel" hidden>
        <form class="card" method="POST" action="{{ route('admin.settings.branding') }}" enctype="multipart/form-data">
            @csrf
            <div class="card__hd"><div><h2>Général &amp; branding</h2><p>Identité de l'organisation affichée sur les écrans, e-mails et pages publiques.</p></div></div>
            <div class="card__body">
                <div class="field">
                    <label>Logo de l'organisation</label>
                    <div class="logorow">
                        <div class="logobox"><img src="{{ $branding['logo_path'] ? \Illuminate\Support\Facades\Storage::disk('public')->url($branding['logo_path']) : asset('assets/logo-acs-groupe.png') }}" alt="Logo actuel"></div>
                        <div>
                            <input class="control" type="file" name="logo" accept="image/png,image/svg+xml,image/jpeg,image/webp">
                            <div class="help">PNG ou SVG, fond transparent recommandé. Max 2 Mo.</div>
                            @error('logo')<div class="err-msg" style="display:block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
                <div class="grid2" style="margin-top:14px">
                    <div class="field"><label for="org">Nom de l'organisation</label><input class="control" id="org" name="org_name" value="{{ old('org_name', $branding['org_name']) }}" required></div>
                    <div class="field"><label for="tz">Fuseau horaire</label>
                        <select class="control" id="tz" name="timezone">
                            @foreach (['Africa/Abidjan' => 'Abidjan · GMT (UTC+00:00)', 'Africa/Lagos' => 'Lagos · WAT (UTC+01:00)', 'Europe/Paris' => 'Paris · CET (UTC+01:00/+02:00)'] as $v => $lbl)
                                <option value="{{ $v }}" @selected($branding['timezone'] === $v)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field"><label for="fmt">Format de date</label>
                        <select class="control" id="fmt" name="date_format">
                            <option value="j M Y" @selected($branding['date_format'] === 'j M Y')>19 juil. 2026 (jj mois aaaa)</option>
                            <option value="d/m/Y" @selected($branding['date_format'] === 'd/m/Y')>19/07/2026 (jj/mm/aaaa)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card__body" style="border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
                <button type="submit" class="btn btn--primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20 6 9 17l-5-5"/></svg>Enregistrer</button>
            </div>
        </form>
    </section>

    {{-- ===== Conservation (lecture seule) ===== --}}
    <section class="panel" id="panel-data" role="tabpanel" hidden>
        <div class="card">
            <div class="card__hd"><div><h2>Conservation des données</h2><p>Politique appliquée aux présences, participants et exports.</p></div></div>
            <div class="card__body">
                <div class="readpair"><span class="k">Durée de conservation</span><span class="v">Indéfinie <span class="st st--on" style="margin-left:8px">Active</span></span></div>
                <div class="readpair"><span class="k">Données concernées</span><span class="v">Présences, coordonnées, historique des événements, exports</span></div>
                <div class="readpair"><span class="k">Suppression automatique</span><span class="v">Aucune (conservation jusqu'à suppression manuelle)</span></div>
                <div class="readpair"><span class="k">Cadre légal applicable</span><span class="v">Loi ivoirienne n°2013-450 (ARTCI)</span></div>
                <div class="notice warn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg><span><strong>Zone « à vérifier ».</strong> La conservation indéfinie est la décision produit actuelle. Sa conformité au regard de la Loi n°2013-450 (durée proportionnée, droit à l'effacement, information des personnes) <strong>doit être confirmée avec un conseil juridique / DPO</strong> avant mise en production.</span></div>
            </div>
        </div>
        <div class="notice"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><span>Section en <strong>lecture seule</strong>. La modification de la politique sera ouverte une fois le cadre légal validé.</span></div>
    </section>

    @include('admin.settings._modals')
@endsection

@push('scripts')
<script>
    window.SETTINGS = {
        csrf: @json(csrf_token()),
        currentUserId: {{ auth()->id() }},
        palette: ['#7c3aed', '#2563eb', '#d6336c', '#0e9e86', '#e0620d', '#1e2a78', '#b8770f', '#0d9488'],
        types: @json($types),
        accounts: @json($accounts),
        urls: {
            typesStore: @json(route('admin.settings.types.store')),
            typeTpl: @json(route('admin.settings.types.update', ['type' => '__ID__'])),
            accountsStore: @json(route('admin.settings.accounts.store')),
            accountTpl: @json(route('admin.settings.accounts.update', ['account' => '__ID__'])),
            accountResetTpl: @json(route('admin.settings.accounts.reset', ['account' => '__ID__'])),
        },
    };
</script>
<script src="{{ asset('js/settings.js') }}"></script>
@endpush
