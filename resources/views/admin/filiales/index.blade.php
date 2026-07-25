@extends('layouts.admin', ['nav' => 'filiales'])

@section('title', 'Filiales')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Filiales de la holding</h1>
            <p>Créez une filiale, renommez-la ou désactivez-la. La filiale par défaut ne peut jamais être désactivée.</p>
        </div>
        <button type="button" class="btn btn--primary" onclick="Filiales.openCreate()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 5v14M5 12h14"/></svg>
            Nouvelle filiale
        </button>
    </div>

    <div class="kpis">
        <div class="kpi"><span class="ic" style="background:var(--accent-soft);color:var(--accent)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="7" height="16" rx="1"/><rect x="14" y="4" width="7" height="10" rx="1"/></svg></span><div><div class="kpi__val">{{ $kpis['total'] }}</div><div class="kpi__lbl">Filiales</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--success-soft);color:var(--success)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6 9 17l-5-5"/></svg></span><div><div class="kpi__val">{{ $kpis['active'] }}</div><div class="kpi__lbl">Actives</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--surface-3);color:var(--muted)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span><div><div class="kpi__val">{{ $kpis['users'] }}</div><div class="kpi__lbl">Comptes</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--brand-orange-soft);color:var(--brand-orange)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><div><div class="kpi__val">{{ $kpis['events'] }}</div><div class="kpi__lbl">Événements</div></div></div>
    </div>

    <div class="tablewrap">
        <table class="dt">
            <thead>
                <tr>
                    <th>Filiale</th>
                    <th>Statut</th>
                    <th>Comptes</th>
                    <th>Événements</th>
                    <th>Créée</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="filiales-body"></tbody>
        </table>
    </div>

    {{-- Bascule le contexte filiale (topbar) et redirige dans le périmètre scopé. --}}
    <form method="POST" action="{{ route('admin.filiale-context.update') }}" id="f-goto-form">
        @csrf
        <input type="hidden" name="filiale_id" id="f-goto-id">
        <input type="hidden" name="redirect_to" id="f-goto-redirect" value="dashboard">
    </form>

    <div class="scrim" id="f-scrim" hidden onclick="if(event.target===this)Filiales.close()">
        <div class="modal" id="m-filiale" role="dialog" aria-modal="true" aria-labelledby="mf-title" hidden>
            <div class="modal__head">
                <div class="modal__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="7" height="16" rx="1"/><rect x="14" y="4" width="7" height="10" rx="1"/></svg></div>
                <button class="modal__x" type="button" aria-label="Fermer" onclick="Filiales.close()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
            </div>
            <h2 id="mf-title">Nouvelle filiale</h2>
            <div class="field" style="margin-top:14px">
                <label for="f-name">Nom</label>
                <input class="control" id="f-name" placeholder="ACS Immobilier">
                <div class="err-msg" id="f-name-err"></div>
            </div>
            <div class="modal__foot">
                <button class="btn btn--ghost" type="button" onclick="Filiales.close()">Annuler</button>
                <button class="btn btn--primary" type="button" id="f-save" onclick="Filiales.save()">Enregistrer</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    window.FILIALES = {
        csrf: @json(csrf_token()),
        filiales: @json($filiales),
        urls: {
            store: @json(route('admin.filiales.store')),
            updateTpl: @json(route('admin.filiales.update', ['filiale' => '__ID__'])),
            toggleTpl: @json(route('admin.filiales.toggle', ['filiale' => '__ID__'])),
        },
    };
</script>
<script src="{{ versioned_asset('js/filiales.js') }}"></script>
@endpush
