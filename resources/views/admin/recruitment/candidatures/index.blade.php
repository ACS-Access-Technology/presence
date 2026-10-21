@extends('layouts.admin', ['nav' => 'recrutement'])

@section('title', $test->titre.' — Candidatures')

@php
    $statutTag = [
        'en_cours' => 'tag--soon',
        'soumis' => 'tag--live',
        'expire' => 'tag--reported',
        'abandonnee' => 'tag--cancelled',
    ];
@endphp

@section('content')
    <div class="pagehead">
        <div>
            <a class="back" href="{{ route('admin.recrutement.index') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"><path d="M15 18l-6-6 6-6"/></svg>
                Retour aux postes
            </a>
            <h1>{{ $test->titre }}</h1>
            <p>{{ $candidatures->count() }} candidature(s).</p>
        </div>
    </div>

    <div class="tablewrap">
        <table class="dt" aria-label="Candidatures">
            <thead>
                <tr>
                    <th>Candidat</th>
                    <th>Email</th>
                    <th>Statut</th>
                    <th>Score</th>
                    <th>Soumis le</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($candidatures as $candidature)
                    <tr>
                        <td><a class="rowlink" href="{{ route('admin.recrutement.candidatures.show', [$test->slug, $candidature->id]) }}">{{ $candidature->prenom }} {{ $candidature->nom }}</a></td>
                        <td>{{ $candidature->email }}</td>
                        <td><span class="tag {{ $statutTag[$candidature->statut->value] }}">{{ $candidature->statut->label() }}</span></td>
                        <td>{{ $candidature->score !== null ? $candidature->score.' / '.($test->questions()->count() * 3) : '—' }}</td>
                        <td>{{ $candidature->submitted_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Aucune candidature pour ce poste.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
