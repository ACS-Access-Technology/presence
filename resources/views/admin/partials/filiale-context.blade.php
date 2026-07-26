{{--
    Contexte filiale en topbar (Q-ME-6).
    - SuperAdmin : menu déroulant custom, persistant (session) — « Toutes les
      filiales » par défaut. Un simple <select> ne permettait pas d'afficher le
      nombre d'événements / l'état désactivé par filiale comme la maquette
      l'exige — d'où ce composant bouton + panneau.
    - AdminFiliale / Organisateur : badge figé de leur filiale (pas de bascule).
--}}
@php($ctxUser = auth()->user())
@if ($ctxUser->isSuperAdmin())
    @php($ctxId = (int) session(\App\Http\Middleware\ApplyFilialeScope::CONTEXT_SESSION_KEY))
    @php($ctxName = $ctxId ? \App\Models\Filiale::find($ctxId)?->name : null)
    @php($filiales = \App\Models\Filiale::withCount(['events' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\FilialeScope::class)])->orderBy('name')->get())
    <form method="POST" action="{{ route('admin.filiale-context.update') }}" id="filctx-form">
        @csrf
        <input type="hidden" name="filiale_id" id="filctx-input" value="{{ $ctxId ?: '' }}">
    </form>
    <div class="filctx-wrap">
        <button type="button" class="filctx" id="filctx-btn" aria-haspopup="menu" aria-expanded="false" onclick="Filctx.toggle()">
            <span class="dot" aria-hidden="true"></span>
            <span class="lbl">Périmètre</span>
            <span class="val" id="filctx-val">{{ $ctxName ?: 'Toutes les filiales' }}</span>
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="filctx-pop" id="filctx-pop" role="menu" hidden>
            <div class="navlbl">Périmètre d'affichage</div>
            <button type="button" class="filctx-opt {{ $ctxId === 0 ? 'is-selected' : '' }}" onclick="Filctx.choose(null)">
                <span>Toutes les filiales</span>
                <span class="filctx-opt__r">holding @if($ctxId === 0)<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>@endif</span>
            </button>
            <div class="navlbl">Filiales</div>
            @foreach ($filiales as $f)
                <button type="button" class="filctx-opt {{ $ctxId === $f->id ? 'is-selected' : '' }}" @disabled(! $f->is_active) onclick="Filctx.choose({{ $f->id }})">
                    <span>{{ $f->name }}</span>
                    <span class="filctx-opt__r">
                        @if(! $f->is_active) désactivée
                        @else {{ $f->events_count }} évt{{ $f->events_count > 1 ? 's' : '' }} @endif
                        @if($ctxId === $f->id)<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>@endif
                    </span>
                </button>
            @endforeach
        </div>
    </div>
    <script>
        window.Filctx = {
            toggle: function () {
                var pop = document.getElementById('filctx-pop');
                var btn = document.getElementById('filctx-btn');
                var open = pop.hidden;
                pop.hidden = !open;
                btn.setAttribute('aria-expanded', String(open));
            },
            choose: function (id) {
                document.getElementById('filctx-input').value = id || '';
                document.getElementById('filctx-form').submit();
            }
        };
        document.addEventListener('click', function (e) {
            var wrap = document.querySelector('.filctx-wrap');
            var pop = document.getElementById('filctx-pop');
            if (wrap && !wrap.contains(e.target)) { pop.hidden = true; document.getElementById('filctx-btn').setAttribute('aria-expanded', 'false'); }
        });
    </script>
@elseif ($ctxUser->filiale)
    <span class="filctx">
        <span class="dot" aria-hidden="true"></span>
        <span class="lbl">Filiale</span>
        <span class="val">{{ $ctxUser->filiale->name }}</span>
    </span>
@endif
