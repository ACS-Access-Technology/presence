<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction du titre, du type ou du lieu d'un événement existant (ex. faute
 * de frappe). N'inclut PAS les horaires (→ Reporter) ni le mode QR (verrouillé
 * dès qu'une présence existe) : ce sont des actions distinctes, tracées.
 */
class UpdateEventRequest extends FormRequest
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
            'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
            'location' => ['nullable', 'string', 'max:190'],

            // Périmètre anti-fraude (facultatif) : les trois champs vont ensemble.
            'geofence_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:geofence_longitude,geofence_radius_m'],
            'geofence_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:geofence_latitude,geofence_radius_m'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:10', 'max:5000', 'required_with:geofence_latitude,geofence_longitude'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est requis.',
            'event_type_id.required' => 'Le type d\'événement est requis.',
        ];
    }
}
