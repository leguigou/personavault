<?php
/**
 * Mène une discussion entre deux personas en arrière-plan, réplique après réplique,
 * jusqu'à la fin, une pause ou un arrêt demandés depuis la page (src/duo.php).
 * Lancé par l'application ; relancé par bin/followup.php s'il s'est arrêté.
 *
 * Usage : php bin/duo-worker.php <id de la discussion>
 */
require __DIR__ . '/../src/bootstrap.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage : php bin/duo-worker.php <id>\n");
    exit(1);
}
@set_time_limit(0);
ignore_user_abort(true);

$deadline = time() + 3 * 3600; // garde-fou : la tâche planifiée relance au besoin
$busy = 0;
while (time() < $deadline) {
    $result = duo_step($id);
    if ($result === 'idle') {
        break;
    }
    if ($result === 'busy') { // un autre processus mène déjà cette discussion
        if (++$busy >= 3) {
            break;
        }
        sleep(2);
        continue;
    }
    $busy = 0;
    if ($result === 'wait') {
        $s = duo_session($id);
        sleep(max(1, min(10, (int) ($s['next_at'] ?? 0) - time())));
    }
}
