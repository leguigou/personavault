<?php
declare(strict_types=1);

/* Processus lancé par lms_load_async(). Écrit le résultat du chargement sur stdout. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$model = base64_decode($argv[1] ?? '', true);
if (!is_string($model) || $model === '') {
    echo "\n__HTTP:000\n__EXIT:1\n";
    exit(1);
}
try {
    require __DIR__ . '/../src/bootstrap.php';
    $result = lms_load_request($model, (int) ($argv[2] ?? 8192));
} catch (Throwable) {
    echo "\n__HTTP:000\n__EXIT:1\n";
    exit(1);
}
if ($result['error'] !== '') {
    echo json_encode(['error' => ['message' => $result['error']]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
printf("\n__HTTP:%03d\n__EXIT:%d\n", $result['http'], $result['exit']);
