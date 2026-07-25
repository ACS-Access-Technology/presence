<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\QrMode;
use App\Http\Requests\Concerns\ValidatesEventTypeInScope;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correction du titre, du type ou du lieu d'un événement existant (ex. faute
 * de frappe). N'inclut PAS les horaires (→ Reporter) ni le mode QR (verrouillé
 * dès qu'une présence existe) : ce sont des actions distinctes, tracées.
 */
class UpdateEventRequest extends FormRequest
{
    use ValidatesEventTypeInScope;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Le mode QR n'est PAS éditable ici (action distincte, verrouillée dès la
        // 1re présence) : il n'est donc pas soumis. On lit le mode réel de
        // l'événement en cours pour décider si le périmètre devient obligatoire.
        $event = $this->route('event');
        $isStatique = $event instanceof Event && $event->qr_mode === QrMode::Statique;

        return [
            'title' => ['required', 'string', 'max:150'],
            'event_type_id' => ['required', 'integer', $this->eventTypeInScopeRule()],
            'location' => ['nullable', 'string', 'max:190'],

            // Délai de grâce d'émargement post-clôture (voir Event::checkInClosesAt()).
            'grace_check_in_enabled' => ['sometimes', 'boolean'],

            // Périmètre anti-fraude : les trois champs vont ensemble (`required_with`)
            // et deviennent OBLIGATOIRES quand l'événement est en mode QR statique
            // (photo du QR fixe rejouable à distance → la géoloc est l'unique barrière).
            'geofence_latitude' => ['nullable', 'numeric', 'between:-90,90', Rule::requiredIf($isStatique), 'required_with:geofence_longitude,geofence_radius_m'],
            'geofence_longitude' => ['nullable', 'numeric', 'between:-180,180', Rule::requiredIf($isStatique), 'required_with:geofence_latitude,geofence_radius_m'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:10', 'max:5000', Rule::requiredIf($isStatique), 'required_with:geofence_latitude,geofence_longitude'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est requis.',
            'event_type_id.required' => 'Le type d\'événement est requis.',
            // `Rule::requiredIf` échoue sous la clé générique `.required`.
            'geofence_latitude.required' => 'En mode QR statique, le périmètre anti-fraude est obligatoire (le QR fixe est photographiable) : renseignez la latitude.',
            'geofence_longitude.required' => 'En mode QR statique, le périmètre anti-fraude est obligatoire : renseignez la longitude.',
            'geofence_radius_m.required' => 'En mode QR statique, le périmètre anti-fraude est obligatoire : renseignez le rayon (en mètres).',
        ];
    }
}
