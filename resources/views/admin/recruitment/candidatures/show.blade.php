@extends('layouts.admin')

@section('content')
<h1>{{ $candidature->prenom }} {{ $candidature->nom }} — {{ $test->titre }}</h1>
<p>Score : {{ $candidature->score }} / {{ $test->questions()->count() * 3 }} — Statut : {{ $candidature->statut->label() }}</p>
<table>
    <thead>
        <tr><th>Transaction</th><th>Classement candidat</th><th>Classement attendu</th><th>TVA candidat</th><th>TVA attendue</th><th>Retenue candidat</th><th>Retenue attendue</th><th>Points</th></tr>
    </thead>
    <tbody>
        @foreach ($detail as $ligne)
            @php($attendu = $ligne['question']->reponse_attendue)
            <tr>
                <td>{{ $ligne['question']->contexte['id'] }}</td>
                <td>{{ $ligne['reponse']?->classement_saisi ?? '—' }}</td>
                <td>{{ $attendu['classement'] }}</td>
                <td>{{ $ligne['reponse']?->ajustement_tva_saisi ?? '—' }}</td>
                <td>{{ $attendu['ajustement_tva'] }}</td>
                <td>{{ $ligne['reponse']?->retenue_saisie ?? '—' }}</td>
                <td>{{ $attendu['retenue_attendue'] }}</td>
                <td>{{ $ligne['resultat']['points'] ?? 0 }} / 3</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
