@extends('layouts.admin', ['nav' => 'recrutement'])

@section('title', 'Recrutement')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Recrutement</h1>
            <p>Postes ouverts au test de recrutement en ligne, cloisonnés par filiale.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    <div class="tablewrap">
        <table class="dt" aria-label="Postes de recrutement">
            <thead>
                <tr>
                    <th>Poste</th>
                    <th>Statut</th>
                    <th>Candidatures</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tests as $test)
                    <tr>
                        <td>{{ $test->titre }}</td>
                        <td>
                            @if ($test->actif)
                                <span class="tag tag--live">Actif</span>
                            @else
                                <span class="tag tag--done">Inactif</span>
                            @endif
                        </td>
                        <td>
                            @if (Route::has('admin.recrutement.candidatures.index'))
                                <a href="{{ route('admin.recrutement.candidatures.index', $test->slug) }}">{{ $test->candidatures_count }}</a>
                            @else
                                {{ $test->candidatures_count }}
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.recrutement.toggle', $test->slug) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn--ghost">{{ $test->actif ? 'Désactiver' : 'Activer' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">Aucun poste de recrutement pour l'instant.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
