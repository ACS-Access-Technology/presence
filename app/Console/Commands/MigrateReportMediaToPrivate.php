<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Migration ponctuelle : déplace les médias de compte-rendu (documents + photos)
 * du disque PUBLIC vers le disque PRIVÉ `local`.
 *
 * Contexte : les documents et photos de compte-rendu étaient historiquement stockés
 * sur le disque `public` (exposé sans authentification via le symlink `storage`).
 * Le correctif de sécurité les sert désormais depuis le disque privé `local`, via
 * une route admin scopée par filiale. Les fichiers déjà en prod doivent donc être
 * physiquement déplacés, sinon leur accès (via la nouvelle route) renverrait 404.
 *
 * À lancer UNE fois après déploiement : `php artisan reports:migrate-media-to-private`.
 * Le chemin en base (`report_documents.path` / `report_photos.path`) reste identique
 * (`reports/{eventId}/...`) : seul le disque de rattachement change, donc AUCUNE
 * mise à jour de base n'est nécessaire.
 *
 * Idempotente : un fichier déjà présent sur `local` (ou absent de `public`) est
 * ignoré. Relançable sans risque. `--dry-run` pour auditer sans rien déplacer.
 */
class MigrateReportMediaToPrivate extends Command
{
    protected $signature = 'reports:migrate-media-to-private {--dry-run : Liste les fichiers à déplacer sans rien modifier}';

    protected $description = 'Déplace les documents/photos de compte-rendu du disque public vers le disque privé.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        // Tout ce qui vit sous `reports/` sur le disque public (documents + photos).
        $files = $public->allFiles('reports');

        if ($files === []) {
            $this->info('Aucun média de compte-rendu à migrer sur le disque public.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;

        foreach ($files as $path) {
            if ($local->exists($path)) {
                // Déjà migré : on ne réécrit pas (évite d'écraser un fichier privé plus récent).
                $this->line("skip (déjà privé) : {$path}");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("à déplacer : {$path}");
                $moved++;

                continue;
            }

            $stream = $public->readStream($path);
            if ($stream === null) {
                $this->warn("illisible, ignoré : {$path}");
                $skipped++;

                continue;
            }

            $local->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // On ne supprime la source publique qu'après écriture confirmée sur le privé.
            $public->delete($path);
            $this->line("déplacé : {$path}");
            $moved++;
        }

        $verb = $dryRun ? 'à migrer' : 'migré(s)';
        $this->info("{$moved} fichier(s) {$verb}, {$skipped} ignoré(s).");

        return self::SUCCESS;
    }
}
