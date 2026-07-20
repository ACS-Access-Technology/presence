<?php

declare(strict_types=1);

if (! function_exists('versioned_asset')) {
    /**
     * Comme asset(), mais ajoute ?v=<mtime du fichier> pour casser le cache
     * navigateur à chaque déploiement qui modifie le fichier. Sans ça, un
     * visiteur qui a déjà chargé une page garde l'ancien JS/CSS en cache tant
     * qu'il ne fait pas un rechargement forcé — un bug déjà livré en
     * production peut donc rester "vivant" indéfiniment côté client.
     */
    function versioned_asset(string $path): string
    {
        $full = public_path($path);
        $version = is_file($full) ? (string) filemtime($full) : (string) time();

        return asset($path).'?v='.$version;
    }
}
