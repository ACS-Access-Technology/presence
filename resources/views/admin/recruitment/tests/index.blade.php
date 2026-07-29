@extends('layouts.admin')

@section('content')
<h1>Recrutement</h1>
@if (session('status'))
    <p>{{ session('status') }}</p>
@endif
<table>
    <thead>
        <tr><th>Poste</th><th>Statut</th><th>Candidatures</th><th></th></tr>
    </thead>
    <tbody>
        @foreach ($tests as $test)
            <tr>
                <td>{{ $test->titre }}</td>
                <td>{{ $test->actif ? 'Actif' : 'Inactif' }}</td>
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
                        <button type="submit">{{ $test->actif ? 'Désactiver' : 'Activer' }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
