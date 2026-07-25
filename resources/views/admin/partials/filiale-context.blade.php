{{--
    Contexte filiale en topbar (Q-ME-6).
    - SuperAdmin : sélecteur persistant (session) — « Toutes les filiales » par défaut.
    - AdminFiliale / Organisateur : badge figé de leur filiale (pas de bascule).
--}}
@php($ctxUser = auth()->user())
@if ($ctxUser->isSuperAdmin())
    @php($ctxId = (int) session(\App\Http\Middleware\ApplyFilialeScope::CONTEXT_SESSION_KEY))
    <form method="POST" action="{{ route('admin.filiale-context.update') }}" class="filctx-form">
        @csrf
        <label class="filctx" title="Périmètre d'affichage">
            <span class="dot" aria-hidden="true"></span>
            <span class="lbl">Périmètre</span>
            <select name="filiale_id" class="filctx__sel" onchange="this.form.submit()" aria-label="Filiale affichée">
                <option value="">Toutes les filiales</option>
                @foreach (\App\Models\Filiale::orderBy('name')->get() as $f)
                    {{-- Une filiale désactivée reste visible (le SuperAdmin sait
                         qu'elle existe) mais n'est pas sélectionnable comme
                         périmètre (`disabled`) — cohérent avec le refus serveur. --}}
                    <option value="{{ $f->id }}" @selected($ctxId === $f->id) @disabled(! $f->is_active)>{{ $f->name }}@unless ($f->is_active) · désactivée @endunless</option>
                @endforeach
            </select>
        </label>
    </form>
@elseif ($ctxUser->filiale)
    <span class="filctx">
        <span class="dot" aria-hidden="true"></span>
        <span class="lbl">Filiale</span>
        <span class="val">{{ $ctxUser->filiale->name }}</span>
    </span>
@endif
