{{-- En-tête officiel d'une rencontre avec un partenaire institutionnel :
     armoiries de la Côte d'Ivoire à gauche, logo du partenaire au centre, nom à
     droite (décision Férié 2026-10-21). Styles autonomes : inclus sur des pages
     qui ne partagent pas de feuille CSS (page publique, projection, impression). --}}
@if ($branding->isInstitutional)
    <div class="inst-hd" role="banner">
        <div class="inst-hd__state">
            <img class="inst-hd__arms" src="{{ asset(\App\Support\Branding::COAT_OF_ARMS) }}" alt="Armoiries de la République de Côte d'Ivoire">
            <span class="inst-hd__rep">République de Côte d'Ivoire</span>
            <span class="inst-hd__motto">Union – Discipline – Travail</span>
            <span class="inst-hd__flag" aria-hidden="true"><i></i><i></i><i></i></span>
        </div>
        <img class="inst-hd__logo" src="{{ $branding->logoUrl }}" alt="{{ $branding->orgName }}">
        <span class="inst-hd__name">{{ $branding->orgName }}</span>
    </div>
    <style>
        .inst-hd{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;background:#fff;color:#18201b;padding:10px 14px;border-bottom:3px solid {{ $branding->accentColorOrDefault() }}}
        .inst-hd__state{justify-self:start;display:flex;flex-direction:column;align-items:center;gap:2px;text-align:center}
        .inst-hd__arms{height:52px;width:auto}
        .inst-hd__rep{font-size:.62rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;line-height:1.2;white-space:nowrap}
        .inst-hd__motto{font-size:.6rem;font-style:italic;color:#4d5a52;white-space:nowrap}
        .inst-hd__flag{display:flex;width:54px;height:4px;border-radius:2px;overflow:hidden;margin-top:3px}
        .inst-hd__flag i{flex:1}.inst-hd__flag i:nth-child(1){background:#f77f00}.inst-hd__flag i:nth-child(2){background:#fff;box-shadow:inset 0 0 0 1px #d9ded9}.inst-hd__flag i:nth-child(3){background:#009e60}
        .inst-hd__logo{height:46px;width:auto;max-width:34vw;object-fit:contain;justify-self:center}
        .inst-hd__name{justify-self:end;text-align:right;font-weight:700;font-size:.82rem;line-height:1.25;color:{{ $branding->accentColorOrDefault() }}}
        @media print{.inst-hd{border-bottom-width:2px}}
        @media (max-width:420px){.inst-hd{gap:8px;padding:8px 10px}.inst-hd__arms{height:40px}.inst-hd__rep{font-size:.52rem}.inst-hd__motto{font-size:.5rem}.inst-hd__logo{height:34px}.inst-hd__name{font-size:.72rem}}
    </style>
@endif
