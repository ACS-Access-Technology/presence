<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autosave d'une réponse. Tous les champs sont `nullable` : le candidat peut
 * vider un champ (annuler une saisie) et enregistrer un état partiel.
 */
class SaveRecruitmentReponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classement' => ['nullable', 'string', Rule::in(['Conforme', 'Modéré', 'Majeur', 'Critique'])],
            'ajustement_tva' => ['nullable', 'integer'],
            'retenue_attendue' => ['nullable', 'integer'],
        ];
    }
}
