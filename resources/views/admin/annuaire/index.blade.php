@extends('layouts.admin', ['nav' => 'annuaire'])

@section('title', 'Annuaire des agents')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Annuaire des agents</h1>
            <p>{{ auth()->user()->isSuperAdmin() ? 'Vue transversale : personnel de toutes les filiales.' : 'Personnel de votre filiale.' }}</p>
        </div>
    </div>

    <div class="tablewrap">
        <table class="dt" aria-label="Annuaire des agents">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Poste</th>
                    <th>Direction</th>
                    <th>Service</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    @if(auth()->user()->isSuperAdmin())
                        <th>Filiale</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $agent)
                    <tr>
                        <td>{{ $agent->name }}</td>
                        <td>{{ $agent->position ?? '—' }}</td>
                        <td>{{ $agent->direction ?? '—' }}</td>
                        <td>{{ $agent->service ?? '—' }}</td>
                        <td>{{ $agent->phone ?? '—' }}</td>
                        <td>{{ $agent->email }}</td>
                        @if(auth()->user()->isSuperAdmin())
                            <td>{{ $agent->filiale?->name ?? '—' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ auth()->user()->isSuperAdmin() ? 7 : 6 }}">Aucun agent dans cette filiale.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
