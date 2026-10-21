<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Filiale;
use App\Models\Partner;
use App\Models\Scopes\FilialeScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Création / modification d'un partenaire. Le logo est obligatoire à la création
 * (c'est lui qui porte la marque blanche) et facultatif en modification.
 *
 * Formats raster uniquement : un SVG peut embarquer du <script> exécuté sous le
 * domaine de l'application quand son URL publique est ouverte (XSS stockée).
 * Les couleurs sont des hex stricts : elles sont injectées dans du CSS inline.
 */
class SavePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('partner') === null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'logo' => [$creating ? 'required' : 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'primary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_institutional' => ['sometimes', 'boolean'],
            // Fond de scène : raster uniquement (même raison que le logo), plus
            // lourd qu'un logo puisqu'il couvre l'écran.
            'background' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'remove_background' => ['sometimes', 'boolean'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email:rfc', 'max:191'],
            'filiale_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $filialeId = $this->targetFilialeId();
            if ($filialeId === null) {
                $v->errors()->add('filiale_id', $this->user()?->isSuperAdmin()
                    ? 'Sélectionnez la filiale à laquelle rattacher ce partenaire.'
                    : 'Votre compte n\'est rattaché à aucune filiale.');

                return;
            }

            if (! Filiale::whereKey($filialeId)->where('is_active', true)->exists()) {
                $v->errors()->add('filiale_id', 'La filiale sélectionnée est introuvable ou désactivée.');

                return;
            }

            $duplicate = Partner::withoutGlobalScope(FilialeScope::class)
                ->where('filiale_id', $filialeId)
                ->where('name', (string) $this->input('name'))
                ->when($this->route('partner'), fn ($q, Partner $p) => $q->whereKeyNot($p->id))
                ->exists();
            if ($duplicate) {
                $v->errors()->add('name', 'Un partenaire porte déjà ce nom.');
            }
        });
    }

    /**
     * Filiale du partenaire : en modification, celle du partenaire (jamais
     * déplacé) ; en création, même résolution que pour un événement.
     */
    public function targetFilialeId(): ?int
    {
        $partner = $this->route('partner');
        if ($partner instanceof Partner) {
            return $partner->filiale_id;
        }

        $requested = $this->input('filiale_id');

        return Filiale::resolveForWebCreation($requested !== null && $requested !== '' ? (int) $requested : null);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du partenaire est requis.',
            'logo.required' => 'Le logo du partenaire est requis.',
            'logo.mimes' => 'Le logo doit être une image PNG, JPG ou WebP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
            'background.mimes' => "L'image de fond doit être un PNG, JPG ou WebP.",
            'background.max' => "L'image de fond ne doit pas dépasser 5 Mo.",
            'primary_color.required' => 'La couleur principale est requise.',
            'primary_color.regex' => 'Couleur invalide (format #rrggbb).',
            'secondary_color.regex' => 'Couleur invalide (format #rrggbb).',
            'contact_email.email' => 'Adresse email invalide.',
        ];
    }
}
