<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAgentDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // l'accès est déjà borné par le middleware role: (voir route).
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
        ];
    }
}
