<?php
/**
 * Met à jour l'humeur et les ressentis d'un persona dans une conversation (src/feelings.php).
 * Lancé en arrière-plan après une réponse, toutes les quelques réponses.
 *
 * Usage : php bin/feelings.php <id de la conversation>
 */
require __DIR__ . '/../src/bootstrap.php';

$cid = (int) ($argv[1] ?? 0);
if ($cid <= 0) {
    fwrite(STDERR, "Usage : php bin/feelings.php <id>\n");
    exit(1);
}
@set_time_limit(300);
feel_analyze($cid);
