<?php
declare(strict_types=1);

// Usage : php bin/relationships.php
require __DIR__ . '/../src/bootstrap.php';

$stmt = db()->prepare('SELECT r.persona_id FROM relationships r
    WHERE r.automatic = 1 AND r.last_activity_at > ?
      AND EXISTS (SELECT 1 FROM relationship_queue q WHERE q.persona_id = r.persona_id)
    ORDER BY r.last_activity_at DESC');
$stmt->execute([time() - RELATIONSHIP_IDLE_SECONDS]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
    relationship_run((int) $pid);
}
