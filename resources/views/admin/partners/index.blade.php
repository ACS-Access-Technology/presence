@extends('layouts.admin', ['nav' => 'partners'])

@section('title', 'Rencontres partenaires')

@push('head')
    <link rel="stylesheet" href="{{ versioned_asset('css/partners.css') }}">
@endpush

@section('content')
    <div class="pagehead">
        <div>
            <h1>Rencontres partenaires</h1>
            <p>Réunions organisées pour le compte d'un partenaire : QR code, page d'émargement et feuille PDF à ses couleurs, envoyée automatiquement à la personne choisie à la fin de la réunion.</p>
        </div>
        <div>
            <button type="button" class="btn btn--primary" id="pt-new">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Nouveau partenaire
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    {{-- Fiche partenaire (création / modification), en modale comme le reste de la
         plateforme. Ouverte d'office si la validation a échoué (saisie conservée). --}}
    <div class="scrim" id="pt-form" @unless ($errors->any()) hidden @endunless>
        <div class="modal pt-modal" role="dialog" aria-modal="true" aria-labelledby="pt-form-title">
            <div class="modal__hd">
                <h3 id="pt-form-title">{{ old('_partner_id') ? 'Modifier le partenaire' : 'Nouveau partenaire' }}</h3>
                <button type="button" class="modal__x" id="pt-close" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="pt-form-el"
                  action="{{ old('_partner_id') ? route('admin.partners.update', old('_partner_id')) : route('admin.partners.store') }}"
                  data-store-url="{{ route('admin.partners.store') }}" novalidate>
                @csrf
                <input type="hidden" name="_partner_id" id="pt-id" value="{{ old('_partner_id') }}">
                <div class="modal__body pt-modal__body">
                <div class="pt-grid">
                    <div>
                        @if ($mustChooseFiliale)
                            <div class="field {{ $errors->has('filiale_id') ? 'invalid' : '' }}" id="pt-filiale-field">
                                <label for="pt-filiale">Filiale <span class="req">*</span></label>
                                <select class="control" id="pt-filiale" name="filiale_id">
                                    <option value="">— Choisissez une filiale —</option>
                                    @foreach ($filiales as $f)
                                        <option value="{{ $f->id }}" @selected(old('filiale_id') == $f->id)>{{ $f->name }}</option>
                                    @endforeach
                                </select>
                                <div class="err-msg">{{ $errors->first('filiale_id') }}</div>
                            </div>
                        @endif

                        <div class="field {{ $errors->has('name') ? 'invalid' : '' }}">
                            <label for="pt-name">Nom du partenaire <span class="req">*</span></label>
                            <input class="control" id="pt-name" name="name" value="{{ old('name') }}" placeholder="Ex. Direction générale des finances" maxlength="150" required>
                            <div class="err-msg">{{ $errors->first('name') }}</div>
                        </div>

                        <label class="pt-check" for="pt-institutional">
                            <input type="checkbox" id="pt-institutional" name="is_institutional" value="1" @checked(old('is_institutional'))>
                            <span>
                                <strong>Partenaire institutionnel</strong>
                                <span class="pt-check__help">Administration ivoirienne : toutes ses rencontres portent l'en-tête officiel, avec les armoiries de la Côte d'Ivoire à gauche, son logo au centre et son nom à droite.</span>
                            </span>
                        </label>

                        <div class="field {{ $errors->has('logo') ? 'invalid' : '' }}">
                            <span class="pt-label" id="pt-logo-label">Logo <span class="req" id="pt-logo-req">*</span></span>
                            {{-- Zone de dépôt : l'input natif reste dans le label (clic, clavier,
                                 lecteurs d'écran) mais est masqué visuellement. --}}
                            <label class="pt-drop" id="pt-drop" for="pt-logo">
                                <input class="pt-drop__input" id="pt-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-labelledby="pt-logo-label" aria-describedby="pt-logo-help">
                                <span class="pt-drop__empty" id="pt-drop-empty">
                                    <span class="pt-drop__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                                    </span>
                                    <span class="pt-drop__txt"><strong>Déposez le logo ici</strong> ou <span class="pt-drop__link">parcourir</span></span>
                                </span>
                                <span class="pt-drop__filled" id="pt-drop-filled" hidden>
                                    <img class="pt-drop__thumb" id="pt-drop-thumb" alt="">
                                    <span class="pt-drop__meta">
                                        <span class="pt-drop__name" id="pt-drop-name"></span>
                                        <span class="pt-drop__size" id="pt-drop-size"></span>
                                    </span>
                                    <span class="btn btn--ghost btn--sm pt-drop__change" aria-hidden="true">Changer</span>
                                </span>
                            </label>
                            <div class="help" id="pt-logo-help">PNG, JPG ou WebP · 2 Mo maximum. Les couleurs du logo sont proposées automatiquement.</div>
                            <div class="err-msg">{{ $errors->first('logo') }}</div>
                        </div>

                        <div class="field">
                            <label>Couleurs trouvées dans le logo</label>
                            <div class="pt-swatches" id="pt-swatches" aria-live="polite">
                                <span class="mut">Déposez un logo pour voir ses couleurs.</span>
                            </div>
                            <div class="help">Cliquez une couleur pour l'appliquer à la couleur sélectionnée ci-dessous.</div>
                        </div>

                        <div class="grid2">
                            <div class="field {{ $errors->has('primary_color') ? 'invalid' : '' }}">
                                <label for="pt-primary">
                                    <input type="radio" name="_target" value="primary" checked> Couleur principale <span class="req">*</span>
                                </label>
                                <div class="pt-color">
                                    <input type="color" id="pt-primary-picker" value="{{ old('primary_color', '#1e2a78') }}" aria-label="Choisir la couleur principale">
                                    <input class="control mono" id="pt-primary" name="primary_color" value="{{ old('primary_color', '#1e2a78') }}" maxlength="7" required>
                                </div>
                                <div class="help" id="pt-contrast-note"></div>
                                <div class="err-msg">{{ $errors->first('primary_color') }}</div>
                            </div>
                            <div class="field {{ $errors->has('secondary_color') ? 'invalid' : '' }}">
                                <label for="pt-secondary">
                                    <input type="radio" name="_target" value="secondary"> Couleur secondaire
                                </label>
                                <div class="pt-color">
                                    <input type="color" id="pt-secondary-picker" value="{{ old('secondary_color', '#f2a900') }}" aria-label="Choisir la couleur secondaire">
                                    <input class="control mono" id="pt-secondary" name="secondary_color" value="{{ old('secondary_color') }}" maxlength="7" placeholder="facultatif">
                                </div>
                                <div class="err-msg">{{ $errors->first('secondary_color') }}</div>
                            </div>
                        </div>

                        <div class="grid2">
                            <div class="field {{ $errors->has('contact_name') ? 'invalid' : '' }}">
                                <label for="pt-contact-name">Contact habituel</label>
                                <input class="control" id="pt-contact-name" name="contact_name" value="{{ old('contact_name') }}" placeholder="Nom et prénom" maxlength="150">
                                <div class="err-msg">{{ $errors->first('contact_name') }}</div>
                            </div>
                            <div class="field {{ $errors->has('contact_email') ? 'invalid' : '' }}">
                                <label for="pt-contact-email">Email du contact</label>
                                <input class="control" id="pt-contact-email" name="contact_email" type="email" value="{{ old('contact_email') }}" maxlength="191">
                                <div class="err-msg">{{ $errors->first('contact_email') }}</div>
                            </div>
                        </div>
                        <div class="help">Proposé par défaut comme destinataire de la feuille d'émargement ; modifiable à chaque rencontre.</div>
                    </div>

                    {{-- Aperçu en direct : en-tête de la feuille + page du participant. --}}
                    <aside class="pt-preview" id="pt-preview" aria-label="Aperçu">
                        <div class="pt-preview__lbl">Aperçu de la feuille d'émargement</div>
                        <div class="pt-sheet">
                            <div class="pt-sheet__head">
                                <span class="pt-sheet__state" id="pt-pv-state" hidden>
                                    <img src="{{ asset(\App\Support\Branding::COAT_OF_ARMS) }}" alt="">
                                    <small>République de Côte d'Ivoire</small>
                                    <em>Union – Discipline – Travail</em>
                                    <span class="pt-flag" aria-hidden="true"><i></i><i></i><i></i></span>
                                </span>
                                <img id="pt-pv-logo" alt="" hidden>
                                <span id="pt-pv-name">Nom du partenaire</span>
                            </div>
                            <div class="pt-sheet__title">Titre de la rencontre</div>
                            <div class="pt-sheet__rows"><i></i><i></i><i></i></div>
                        </div>
                        <div class="pt-preview__lbl">Aperçu sur le téléphone du participant</div>
                        <div class="pt-phone">
                            <div class="pt-phone__bar"></div>
                            <div class="pt-phone__btn">Je signe ma présence</div>
                        </div>
                    </aside>
                </div>
                </div>

                <div class="modal__foot">
                    <button type="button" class="btn btn--ghost" id="pt-cancel">Annuler</button>
                    <button type="submit" class="btn btn--primary">Enregistrer le partenaire</button>
                </div>
            </form>
        </div>
    </div>

    <h2 class="pt-section">Partenaires</h2>
    <div class="tablewrap">
        <table class="dt" aria-label="Partenaires">
            <thead>
                <tr><th>Partenaire</th><th>Couleurs</th><th>Contact</th><th>Rencontres</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($partners as $partner)
                    <tr>
                        <td>
                            <span class="pt-name">
                                <img src="{{ asset('storage/'.$partner->logo_path) }}" alt="">
                                {{ $partner->name }}
                                @if ($partner->is_institutional)<span class="tag tag--type" style="--tc:#009e60;color:var(--tc);background:color-mix(in srgb, var(--tc) 14%, transparent)">Institutionnel</span>@endif
                            </span>
                        </td>
                        <td>
                            <span class="pt-dot" style="--c:{{ $partner->primary_color }}" title="{{ $partner->primary_color }}"></span>
                            @if ($partner->secondary_color)<span class="pt-dot" style="--c:{{ $partner->secondary_color }}" title="{{ $partner->secondary_color }}"></span>@endif
                        </td>
                        <td class="mut">{{ $partner->contact_name ?? '—' }}@if ($partner->contact_email)<br><span class="mono">{{ $partner->contact_email }}</span>@endif</td>
                        <td class="mut">{{ $partner->events_count }}</td>
                        <td class="r-actions">
                            <a class="btn btn--primary btn--sm" href="{{ route('admin.events.create', ['partner' => $partner->id]) }}">Nouvelle rencontre</a>
                            <button type="button" class="btn btn--ghost btn--sm js-pt-edit"
                                    data-partner="{{ json_encode([
                                        'id' => $partner->id,
                                        'name' => $partner->name,
                                        'primary_color' => $partner->primary_color,
                                        'secondary_color' => $partner->secondary_color,
                                        'is_institutional' => $partner->is_institutional,
                                        'contact_name' => $partner->contact_name,
                                        'contact_email' => $partner->contact_email,
                                        'logo_url' => asset('storage/'.$partner->logo_path),
                                        'update_url' => route('admin.partners.update', $partner),
                                    ]) }}">Modifier</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut">Aucun partenaire pour l'instant. Commencez par « Nouveau partenaire ».</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="pt-section">Dernières rencontres</h2>
    <div class="tablewrap">
        <table class="dt" aria-label="Rencontres partenaires">
            <thead>
                <tr><th>Rencontre</th><th>Partenaire</th><th>Date</th><th>Feuille envoyée à</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($meetings as $meeting)
                    <tr>
                        <td>{{ $meeting->title }}</td>
                        <td>{{ $meeting->partner?->name }}</td>
                        <td class="mut">{{ $meeting->starts_at->translatedFormat('j M Y · H:i') }}</td>
                        <td class="mut">
                            {{ $meeting->partner_recipient_name ?? '' }} <span class="mono">{{ $meeting->partner_recipient_email }}</span>
                            @if ($meeting->partner_sheet_sent_at)
                                <span class="tag tag--live">Envoyée le {{ $meeting->partner_sheet_sent_at->translatedFormat('j M à H:i') }}</span>
                            @else
                                <span class="tag tag--soon">À la clôture</span>
                            @endif
                        </td>
                        <td class="r-actions"><a class="btn btn--ghost btn--sm" href="{{ route('admin.events.show', $meeting) }}">Ouvrir</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut">Aucune rencontre partenaire.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@push('scripts')
    <script src="{{ versioned_asset('js/partners.js') }}"></script>
@endpush
