@extends('layouts.admin', ['nav' => 'statistics'])

@section('title', 'Statistiques')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Statistiques</h1>
            <p>Vue d'ensemble toutes activités confondues.</p>
        </div>
    </div>

    <div class="kpis">
        <div class="kpi"><span class="ic" style="background:var(--accent-soft);color:var(--accent)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><div><div class="kpi__val">{{ $kpis['total_events'] }}</div><div class="kpi__lbl">Événements</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--brand-orange-soft);color:var(--brand-orange)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span><div><div class="kpi__val">{{ number_format($kpis['total_attendances'], 0, ',', ' ') }}</div><div class="kpi__lbl">Présences cumulées</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--info-soft);color:var(--info)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6m7-3a4 4 0 1 0 0-8m6 17c0-3-2-5-5-6"/></svg></span><div><div class="kpi__val">{{ number_format($kpis['total_people'], 0, ',', ' ') }}</div><div class="kpi__lbl">Personnes distinctes</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--success-soft);color:var(--success)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg></span><div><div class="kpi__val">{{ $kpis['avg_per_event'] }}</div><div class="kpi__lbl">Présents / événement (moy.)</div></div></div>
    </div>

    <div class="grid2" style="gap:18px;margin-bottom:18px">
        <div class="tablewrap" style="padding:18px">
            <h3 style="margin:0 0 14px;font-size:1rem">Présences par mois (12 derniers mois)</h3>
            @php($maxMonth = $byMonth->max() ?: 1)
            @foreach ($byMonth as $label => $count)
                <div style="display:grid;grid-template-columns:70px 1fr auto;align-items:center;gap:10px;margin-bottom:8px">
                    <span class="mut" style="font-size:.8rem">{{ $label }}</span>
                    <span style="height:9px;border-radius:5px;background:var(--accent);width:{{ round($count / $maxMonth * 100) }}%;min-width:{{ $count > 0 ? 6 : 0 }}px"></span>
                    <b style="font-variant-numeric:tabular-nums">{{ $count }}</b>
                </div>
            @endforeach
        </div>

        <div class="tablewrap" style="padding:18px">
            <h3 style="margin:0 0 14px;font-size:1rem">Présences par type d'événement</h3>
            @php($maxType = $byType->max() ?: 1)
            @forelse ($byType as $label => $count)
                <div style="display:grid;grid-template-columns:130px 1fr auto;align-items:center;gap:10px;margin-bottom:8px">
                    <span class="mut" style="font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $label }}</span>
                    <span style="height:9px;border-radius:5px;background:var(--brand-orange);width:{{ round($count / $maxType * 100) }}%;min-width:{{ $count > 0 ? 6 : 0 }}px"></span>
                    <b style="font-variant-numeric:tabular-nums">{{ $count }}</b>
                </div>
            @empty
                <p class="mut" style="font-size:.85rem">Aucune donnée.</p>
            @endforelse
        </div>
    </div>

    <div class="grid2" style="gap:18px">
        <div class="tablewrap" style="padding:18px">
            <h3 style="margin:0 0 14px;font-size:1rem">Top entreprises (toutes activités)</h3>
            @php($maxCompany = $topCompanies->max() ?: 1)
            @forelse ($topCompanies as $label => $count)
                <div style="display:grid;grid-template-columns:160px 1fr auto;align-items:center;gap:10px;margin-bottom:8px">
                    <span class="mut" style="font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $label }}</span>
                    <span style="height:9px;border-radius:5px;background:var(--info);width:{{ round($count / $maxCompany * 100) }}%;min-width:{{ $count > 0 ? 6 : 0 }}px"></span>
                    <b style="font-variant-numeric:tabular-nums">{{ $count }}</b>
                </div>
            @empty
                <p class="mut" style="font-size:.85rem">Aucune donnée.</p>
            @endforelse
        </div>

        <div class="tablewrap" style="padding:18px">
            <h3 style="margin:0 0 14px;font-size:1rem">Personnes les plus récurrentes</h3>
            @forelse ($topRecurrent as $person)
                <div class="readpair" style="padding:9px 0">
                    <a class="k" style="width:auto;flex:1;color:var(--text);text-decoration:none;font-weight:650" href="{{ route('admin.participants.show', $person) }}">{{ $person->fullName() }}</a>
                    <span class="v">{{ $person->attendances_count }} événements</span>
                </div>
            @empty
                <p class="mut" style="font-size:.85rem">Aucune personne récurrente pour l'instant.</p>
            @endforelse
        </div>
    </div>
@endsection
