@extends('layouts.admin', ['nav' => 'events-create'])

@section('title', 'Nouvel événement')

@section('crumbs')
    <a href="{{ route('admin.events.index') }}">Événements</a><span class="sep">/</span><span class="cur">Nouvel événement</span>
@endsection

@section('content')
    <div class="pagehead">
        <div>
            <h1>Nouvel événement</h1>
            <p>Renseignez les informations, choisissez le mode de QR, puis créez l'événement.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.events.store') }}" novalidate>
        @csrf
        <div class="layout">
            <div>
                {{-- 1. Informations --}}
                <div class="card">
                    <div class="card__body">
                        <h2><span class="n">1</span> Informations</h2>
                        <p class="desc">Le titre et les horaires apparaîtront sur l'écran de projection et la page d'émargement.</p>

                        <div class="field {{ $errors->has('title') ? 'invalid' : '' }}">
                            <label for="title">Titre de l'événement <span class="req">*</span></label>
                            <input class="control" id="title" name="title" value="{{ old('title') }}" placeholder="Ex. Atelier Cybersécurité" aria-invalid="{{ $errors->has('title') ? 'true' : 'false' }}" required>
                            <div class="err-msg">{{ $errors->first('title') }}</div>
                        </div>

                        <div class="field">
                            <label>Type d'événement <span class="req">*</span></label>
                            <div class="typepick" role="radiogroup" aria-label="Type d'événement">
                                @foreach ($types as $type)
                                    <label class="typeopt" style="--tc:{{ $type->color }}">
                                        <input type="radio" name="event_type_id" value="{{ $type->id }}" @checked(old('event_type_id') == $type->id || (!old('event_type_id') && $loop->first)) required>
                                        <span class="typeopt__c">{{ $type->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="help">Catégorie affichée en badge coloré sur la fiche et la liste des événements.</div>
                        </div>

                        <div class="grid3">
                            <div class="field {{ $errors->has('date') ? 'invalid' : '' }}">
                                <label for="date">Date <span class="req">*</span></label>
                                <input class="control" id="date" name="date" type="date" value="{{ old('date', now()->toDateString()) }}" aria-invalid="{{ $errors->has('date') ? 'true' : 'false' }}" required>
                                <div class="err-msg">{{ $errors->first('date') }}</div>
                            </div>
                            <div class="field {{ $errors->has('start') ? 'invalid' : '' }}">
                                <label for="start">Heure de début <span class="req">*</span></label>
                                <input class="control" id="start" name="start" type="time" value="{{ old('start', '09:00') }}" aria-invalid="{{ $errors->has('start') ? 'true' : 'false' }}" required>
                                <div class="err-msg">{{ $errors->first('start') }}</div>
                            </div>
                            <div class="field {{ $errors->has('end') ? 'invalid' : '' }}">
                                <label for="end">Heure de fin <span class="req">*</span></label>
                                <input class="control" id="end" name="end" type="time" value="{{ old('end', '11:00') }}" aria-invalid="{{ $errors->has('end') ? 'true' : 'false' }}" required>
                                <div class="err-msg">{{ $errors->first('end') }}</div>
                            </div>
                        </div>
                        <p class="help">L'émargement n'est possible qu'entre ces horaires. En fin d'événement, un récapitulatif est envoyé par email aux participants.</p>

                        <div id="seances-extra"></div>
                        <button type="button" class="btn btn--ghost btn--sm" onclick="EventForm.addSeance()" style="margin-bottom:6px">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 5v14M5 12h14"/></svg>
                            Ajouter une séance
                        </button>
                        <p class="help" id="seances-help" hidden>Sessions multiples : même titre/type/lieu, une présence et un QR propres à chaque séance.</p>

                        <div class="field" style="margin-top:6px">
                            <label for="lieu">Lieu <span class="opt">(facultatif)</span></label>
                            <input class="control" id="lieu" name="location" value="{{ old('location') }}" placeholder="Ex. Salle Ébène, Cocody">
                            <div class="help">Indicatif pour les participants. La présence sur place est confirmée par la géolocalisation.</div>
                        </div>
                    </div>
                </div>

                {{-- 2. Mode QR --}}
                <div class="card">
                    <div class="card__body">
                        <h2><span class="n">2</span> Mode du QR code</h2>
                        <p class="desc">Deux façons de diffuser le QR d'émargement. Ce choix est fixé à la création.</p>

                        <div class="modes" role="radiogroup" aria-label="Mode du QR code">
                            <label class="mode">
                                <input type="radio" name="qr_mode" value="statique" @checked(old('qr_mode') === 'statique') required>
                                <div class="mode__card">
                                    <div class="mode__hd">
                                        <div class="mode__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v.01M17 21h.01M21 21v-4M14 21v.01"/></svg></div>
                                        <div><div class="mode__t">Statique</div><span class="mode__badge">Imprimable · sans écran</span></div>
                                        <div class="mode__check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div>
                                    </div>
                                    <ul class="mode__list">
                                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Un seul QR fixe, à imprimer et poser sur une table.</li>
                                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Aucun écran ni vidéoprojecteur nécessaire.</li>
                                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Idéal pour accueil, stand, petite salle.</li>
                                    </ul>
                                    <div class="mode__note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>Photographiable : la fraude est limitée par la géolocalisation obligatoire et la fenêtre horaire.</div>
                                </div>
                            </label>
                            <label class="mode">
                                <input type="radio" name="qr_mode" value="tournant" @checked(old('qr_mode', 'tournant') === 'tournant') required>
                                <div class="mode__card">
                                    <div class="mode__hd">
                                        <div class="mode__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2v6M12 22a10 10 0 1 1 6.9-17.2"/><path d="M12 2l3 3-3 3"/></svg></div>
                                        <div><div class="mode__t">Tournant</div><span class="mode__badge">Projeté · renouvelé 15 s</span></div>
                                        <div class="mode__check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div>
                                    </div>
                                    <ul class="mode__list">
                                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Le QR change toutes les 15 secondes à l'écran.</li>
                                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Une photo devient inutilisable après 15 s.</li>
                                        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Idéal pour conférence, séminaire, salle équipée.</li>
                                    </ul>
                                    <div class="mode__note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>Nécessite un écran ou vidéoprojecteur affichant la page en continu.</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                {{-- 3. Inviter des personnes --}}
                <div class="card card--overflow-visible">
                    <div class="card__body">
                        <h2><span class="n">3</span> Inviter des personnes <span class="opt" style="font-weight:400;font-size:.85rem">(facultatif)</span></h2>
                        <p class="desc">Cherchez des membres du référentiel « Personnel ACS Groupe » pour constituer la liste des attendus et les prévenir.</p>

                        <div class="field" style="margin-bottom:0">
                            <label for="inv-search">Rechercher une personne</label>
                            <div class="combo">
                                <div class="combo__in">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                                    <input id="inv-search" type="text" autocomplete="off" placeholder="Nom, e-mail, direction ou service…" aria-expanded="false" aria-controls="inv-results" role="combobox" aria-label="Rechercher une personne à inviter">
                                </div>
                                <div class="combo__pop" id="inv-results" role="listbox" hidden></div>
                            </div>
                            <div class="help">Le référentiel se gère dans Paramètres › Personnel ACS Groupe.</div>
                        </div>

                        <div class="infonote">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                            <span>Inviter <strong>n'enregistre pas</strong> la présence. Le jour J, chaque invité doit quand même scanner le QR et émarger. Cette liste sert uniquement d'attendus et de notification.</span>
                        </div>

                        <div class="invcount" id="inv-count" hidden><b>0</b> personne invitée</div>
                        <div class="invitees" id="inv-selected"></div>
                        <div id="inv-inputs"></div>
                    </div>
                </div>
            </div>

            {{-- Aperçu --}}
            <aside class="aside-preview">
                <div class="preview">
                    <div class="preview__hd">Aperçu</div>
                    <div class="preview__body">
                        <h3 class="pv-title empty" id="pv-title">Titre de l'événement</h3>
                        <div class="pv-meta">
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><span id="pv-date">—</span></span>
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg><span id="pv-time">—</span></span>
                            <span id="pv-lieu-wrap" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg><span id="pv-lieu"></span></span>
                            <span id="pv-inv-wrap" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg><span id="pv-inv"></span></span>
                        </div>
                        <div class="pv-tags">
                            <span class="tag tag--type" id="pv-type">—</span>
                            <span class="tag tag--soon">À venir</span>
                            <span class="tag tag--mode" id="pv-mode">QR tournant</span>
                        </div>
                    </div>
                    <div class="pv-hint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                        Après création, vous pourrez projeter ou imprimer le QR depuis le détail de l'événement.
                    </div>
                </div>
            </aside>
        </div>

        <div class="actionbar">
            <a class="cancel" href="{{ route('admin.events.index') }}">Annuler</a>
            <span class="sp"></span>
            <button type="submit" class="btn btn--primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20 6 9 17l-5-5"/></svg>
                Créer l'événement
            </button>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        window.EVENT_CREATE = {
            searchUrl: @json(route('admin.people.search')),
            types: @json($types->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])),
        };
    </script>
    <script src="{{ versioned_asset('js/event-create.js') }}"></script>
@endpush
