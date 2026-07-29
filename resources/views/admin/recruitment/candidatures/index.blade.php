@extends('layouts.admin')

@section('content')
<h1>{{ $test->titre }} — Candidatures</h1>
<a href="{{ route('admin.recrutement.index') }}">← Retour aux postes</a>
<table>
    <thead>
        <tr><th>Candidat</th><th>Email</th><th>Statut</th><th>Score</th><th>Soumis le</th></tr>
    </thead>
    <tbody>
        @foreach ($candidatures as $candidature)
            <tr>
                <td><a href="{{ route('admin.recrutement.candidatures.show', [$test->slug, $candidature->id]) }}">{{ $candidature->prenom }} {{ $candidature->nom }}</a></td>
                <td>{{ $candidature->email }}</td>
                <td>{{ $candidature->statut->label() }}</td>
                <td>{{ $candidature->score !== null ? $candidature->score.' / '.($test->questions()->count() * 3) : '—' }}</td>
                <td>{{ $candidature->submitted_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
