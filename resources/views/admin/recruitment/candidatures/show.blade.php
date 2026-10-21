@extends('layouts.admin', ['nav' => 'recrutement'])

@section('title', $candidature->prenom.' '.$candidature->nom.' — '.$test->titre)

@php
    $statutTag = [
        'en_cours' => 'tag--soon',
        'soumis' => 'tag--live',
        'expire' => 'tag--reported',
        'abandonnee' => 'tag--cancelled',
    ];
    $total = $test->questions()->count() * 3;
@endphp

@section('content')
    <div class="pagehead">
        <div>
            <a class="back" href="{{ route('admin.recrutement.candidatures.index', $test->slug) }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"><path d="M15 18l-6-6 6-6"/></svg>
                Retour aux candidatures
            </a>
            <h1>{{ $candidature->prenom }} {{ $candidature->nom }}</h1>
            <p>{{ $test->titre }} · {{ $candidature->email }}</p>
        </div>
    </div>

    <div class="rec-scorehead">
        <div class="rec-score">
            <span class="rec-score__val">{{ $candidature->score ?? '—' }} / {{ $total }}</span>
            <span class="rec-score__lbl">Score</span>
        </div>
        <span class="tag {{ $statutTag[$candidature->statut->value] }}">{{ $candidature->statut->label() }}</span>
    </div>

    <div class="tablewrap">
        <table class="dt" aria-label="Détail des réponses">
            <thead>
                <tr>
                    <th>Transaction</th>
                    <th>Classement candidat</th>
                    <th>Classement attendu</th>
                    <th>TVA candidat</th>
                    <th>TVA attendue</th>
                    <th>Retenue candidat</th>
                    <th>Retenue attendue</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($detail as $ligne)
                    @php($attendu = $ligne['question']->reponse_attendue)
                    @php($r = $ligne['resultat'])
                    <tr>
                        <td>{{ $ligne['question']->contexte['id'] }}</td>
                        <td class="{{ $r && $r['classement'] ? 'rec-cell-ok' : ($r ? 'rec-cell-ko' : '') }}">{{ $ligne['reponse']?->classement_saisi ?? '—' }}</td>
                        <td>{{ $attendu['classement'] }}</td>
                        <td class="{{ $r && $r['ajustement_tva'] ? 'rec-cell-ok' : ($r ? 'rec-cell-ko' : '') }}">{{ $ligne['reponse']?->ajustement_tva_saisi ?? '—' }}</td>
                        <td>{{ $attendu['ajustement_tva'] }}</td>
                        <td class="{{ $r && $r['retenue_attendue'] ? 'rec-cell-ok' : ($r ? 'rec-cell-ko' : '') }}">{{ $ligne['reponse']?->retenue_saisie ?? '—' }}</td>
                        <td>{{ $attendu['retenue_attendue'] }}</td>
                        <td>{{ $r['points'] ?? 0 }} / 3</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
