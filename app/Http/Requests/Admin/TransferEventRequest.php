<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Policies\EventPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transfert d'un événement vers une autre filiale (SuperAdmin, T-ME-14).
 *
 * L'autorisation réelle est portée par la route (`role:super_admin`) + la
 * {@see EventPolicy::transfer()} appelée dans le contrôleur
 * (défense en profondeur) ; ce FormRequest ne fait que valider les entrées.
 *
 * Règle clé (exigence produit) : le type d'événement DOIT exister DANS la filiale
 * de destination. Les types sont cloisonnés par filiale (Q-ME-10) : l'ancien type
 * n'a aucun sens dans la cible, un type compatible doit être choisi explicitement.
 * Si aucun type valide n'est fourni, la validation échoue (pas de transfert « à
 * l'aveugle »).
 */
final class TransferEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // La filiale cible est lue depuis l'entrée pour scoper la règle d'existence
        // du type. Un `filiale_id` invalide fait échouer sa propre règle ; la règle
        // du type retombe alors sur `where('filiale_id', 0)` → aucun match, donc
        // échec cohérent (jamais de faux positif).
        $targetFilialeId = (int) $this->input('filiale_id');

        return [
            'filiale_id' => ['required', 'integer', Rule::exists('filiales', 'id')],
            'event_type_id' => [
                'required',
                'integer',
                Rule::exists('event_types', 'id')->where('filiale_id', $targetFilialeId),
            ],
            // Pertinent seulement si l'événement fait partie d'une série. Toujours
            // borné aux deux valeurs autorisées ; le service ignore « series » si
            // l'événement n'appartient à aucune série.
            'scope' => ['required', Rule::in(['seance', 'series'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $event = $this->route('event');

            // Anti-no-op / anti-altération : on ne « transfère » pas vers la filiale
            // où l'événement se trouve déjà (l'UI exclut déjà la filiale courante).
            if ($event instanceof Event && (int) $this->input('filiale_id') === $event->filiale_id) {
                $validator->errors()->add('filiale_id', 'L\'événement est déjà rattaché à cette filiale.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'filiale_id.required' => 'Choisissez la filiale de destination.',
            'event_type_id.required' => 'Choisissez un type d\'événement dans la filiale de destination.',
            'event_type_id.exists' => 'Ce type n\'existe pas dans la filiale de destination : choisissez-en un qui lui appartient.',
        ];
    }
}
