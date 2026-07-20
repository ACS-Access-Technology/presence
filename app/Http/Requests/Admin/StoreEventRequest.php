<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\QrMode;
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
            'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
            'date' => ['required', 'date'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'location' => ['nullable', 'string', 'max:190'],
            'qr_mode' => ['required', new Enum(QrMode::class)],
            'invitees' => ['sometimes', 'array'],
            'invitees.*' => ['integer', 'exists:people,id'],
        ];
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
        ];
    }
}
