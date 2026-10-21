<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\QrMode;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Partner;
use App\Models\Scopes\FilialeScope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            // Le format seul ici ; l'appartenance du type à la filiale CIBLE est
            // vérifiée dans withValidator() (le global scope ne suffit pas en
            // contexte SuperAdmin « Toutes », où il ne filtre rien).
            'event_type_id' => ['required', 'integer'],
            // Filiale cible : facultative dans le payload (imposée par le compte /
            // le contexte pour les cas non-ambigus) ; son obligation et sa validité
            // sont arbitrées dans withValidator() selon le rôle et le contexte.
            'filiale_id' => ['nullable', 'integer'],
            'date' => ['required', 'date'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'location' => ['nullable', 'string', 'max:190'],

            // Délai de grâce : laisse l'émargement ouvert 15 min après l'heure de fin
            // (voir Event::checkInClosesAt()). Case à cocher → absente si décochée.
            'grace_check_in_enabled' => ['sometimes', 'boolean'],

            // Périmètre anti-fraude. Les trois champs vont toujours ensemble
            // (`required_with`) et deviennent OBLIGATOIRES en mode QR statique
            // (`required_if`) : sans token tournant, la géoloc est la seule barrière
            // qui empêche un émargement à distance depuis une photo du QR fixe.
            'geofence_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_if:qr_mode,statique', 'required_with:geofence_longitude,geofence_radius_m'],
            'geofence_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_if:qr_mode,statique', 'required_with:geofence_latitude,geofence_radius_m'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:10', 'max:5000', 'required_if:qr_mode,statique', 'required_with:geofence_latitude,geofence_longitude'],

            'qr_mode' => ['required', new Enum(QrMode::class)],
            // Rencontre partenaire : le destinataire de la feuille d'émargement est
            // obligatoire dès qu'un partenaire est choisi (sinon l'envoi automatique
            // n'aurait nulle part où aller). Appartenance à la filiale vérifiée
            // dans withValidator().
            'partner_id' => ['nullable', 'integer'],
            'partner_recipient_name' => ['nullable', 'string', 'max:150'],
            'partner_recipient_email' => ['nullable', 'required_with:partner_id', 'email:rfc', 'max:191'],
            'invitees' => ['sometimes', 'array'],
            'invitees.*' => ['integer', 'exists:people,id'],
            // Séances additionnelles (sessions multiples, ex. formation sur 3 jours).
            // La séance "principale" (date/start/end ci-dessus) est toujours la 1ère ;
            // chaque entrée ici crée un Event de plus, lié à la même série.
            'extra_seances' => ['sometimes', 'array'],
            'extra_seances.*.date' => ['required', 'date'],
            'extra_seances.*.start' => ['required', 'date_format:H:i'],
            'extra_seances.*.end' => ['required', 'date_format:H:i', 'after:extra_seances.*.start'],
        ];
    }

    /**
     * Résout et VALIDE la filiale cible + la cohérence du type (anomalie P1).
     *
     * Trois garanties :
     *  - un rattachement à une filiale doit toujours être déterminable (pas de
     *    repli silencieux « ACS Groupe ») → sinon erreur explicite sur `filiale_id` ;
     *  - la filiale cible doit exister ET être active (anti-injection d'une filiale
     *    désactivée via le payload) ;
     *  - le type choisi doit appartenir à CETTE filiale (empêche un rattachement
     *    croisé inter-filiale, y compris en contexte « Toutes » non filtré).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $target = $this->targetFilialeId();

            if ($target === null) {
                $v->errors()->add('filiale_id', $this->user()?->isSuperAdmin()
                    ? 'Sélectionnez la filiale dans laquelle créer cet événement.'
                    : 'Votre compte n\'est rattaché à aucune filiale : création impossible.');

                return;
            }

            if (! Filiale::whereKey($target)->where('is_active', true)->exists()) {
                $v->errors()->add('filiale_id', 'La filiale sélectionnée est introuvable ou désactivée.');

                return;
            }

            $typeId = $this->input('event_type_id');
            if ($typeId !== null
                && ! EventType::withoutGlobalScope(FilialeScope::class)
                    ->whereKey($typeId)
                    ->where('filiale_id', $target)
                    ->exists()) {
                $v->errors()->add('event_type_id', "Ce type d'événement n'appartient pas à la filiale sélectionnée.");
            }

            $partnerId = $this->input('partner_id');
            if ($partnerId !== null && $partnerId !== ''
                && ! Partner::withoutGlobalScope(FilialeScope::class)
                    ->whereKey($partnerId)
                    ->where('filiale_id', $target)
                    ->exists()) {
                $v->errors()->add('partner_id', "Ce partenaire n'appartient pas à la filiale sélectionnée.");
            }
        });
    }

    /**
     * Filiale cible résolue pour la création. Non-null garanti APRÈS une validation
     * réussie (le contrôleur ne s'exécute que dans ce cas) ; utilisée pour
     * renseigner explicitement `filiale_id` sur chaque séance créée.
     */
    public function resolvedFilialeId(): ?int
    {
        return $this->targetFilialeId();
    }

    private function targetFilialeId(): ?int
    {
        $requested = $this->input('filiale_id');

        return Filiale::resolveForWebCreation(
            $requested !== null && $requested !== '' ? (int) $requested : null,
        );
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est requis.',
            'event_type_id.required' => 'Le type d\'événement est requis.',
            'date.required' => 'La date est requise.',
            'start.required' => 'L\'heure de début est requise.',
            'end.required' => 'L\'heure de fin est requise.',
            'end.after' => 'La fin doit être après le début.',
            'partner_recipient_email.required_with' => "Indiquez l'email de la personne qui recevra la feuille d'émargement.",
            'partner_recipient_email.email' => 'Adresse email invalide.',
            'geofence_latitude.required_if' => 'En mode QR statique, le périmètre anti-fraude est obligatoire (le QR fixe est photographiable) : renseignez la latitude.',
            'geofence_longitude.required_if' => 'En mode QR statique, le périmètre anti-fraude est obligatoire : renseignez la longitude.',
            'geofence_radius_m.required_if' => 'En mode QR statique, le périmètre anti-fraude est obligatoire : renseignez le rayon (en mètres).',
        ];
    }
}
