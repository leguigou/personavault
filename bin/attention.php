<?php
/**
 * Petite attention (src/followup.php) : lancé en arrière-plan après une réponse du persona.
 * Attend 3 à 7 minutes puis envoie un petit mot spontané, sauf si la conversation a bougé.
 *
 * Usage : php bin/attention.php <id de conversation>
 * Réglage caché chat_attention_delay (secondes) : impose l'attente (banc de tests).
 */
require __DIR__ . '/../src/bootstrap.php';

$cid = (int) ($argv[1] ?? 0);
if ($cid <= 0) {
    fwrite(STDERR, "Usage : php bin/attention.php <id de conversation>\n");
    exit(1);
}
$lastId = attention_last_id($cid);
$forced = setting('chat_attention_delay', '');
$delay  = $forced !== '' ? max(0, (int) $forced) : random_int(180, 420);
if ($delay > 0) {
    sleep($delay);
}
echo attention_run($cid, $lastId), PHP_EOL;
