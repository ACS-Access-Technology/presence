@extends('layouts.public')

@section('content')
    <section class="screen">
        <div class="errscreen">
            <div class="errscreen__ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M17 21h.01M21 21v-4"/></svg>
            </div>
            <h1>QR expiré</h1>
            <p>Ce QR code n'est plus valide : il change toutes les 15 secondes pour des raisons de sécurité. Rescannez le QR affiché à l'écran pour continuer.</p>
        </div>
    </section>
@endsection
