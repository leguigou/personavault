<?php
declare(strict_types=1);

/*
 * Fiche : affichage (toute action non reconnue retombe ici).
 * Inclus en dernier par src/routes/persona.php.
 */

/* --- détail --- */
$photos  = fetch_photos($pid);
$prompts = fetch_prompts($pid);
render('detail', [
    'title'   => $persona['name'],
    'persona' => $persona,
    'photos'  => $photos,
    'prompts' => $prompts,
]);
exit;
