<?php
declare(strict_types=1);

/*
 * Humeur et ressentis du persona dans la conversation (src/feelings.php).
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona).
 *   GET  /chat/{id}/feelings?after=N   état, notes du fil postérieures à N
 *   POST /chat/{id}/feelings           réglage à la main (humeur, intensité, amitié, amour, colère)
 *   POST /chat/{id}/feelings-analyze   analyse menée par la page (serveur sans processus d'arrière-plan)
 */

if ($action === 'feelings' || $action === 'feelings-analyze') {
    header('Content-Type: application/json; charset=utf-8');
    $after = (int) ($_GET['after'] ?? $_POST['after'] ?? 0);
    if ($method === 'GET' && $action === 'feelings') {
        session_write_close();
        echo json_encode(feel_state($cid, $persona, $after), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST') {
        csrf_check_json();
        session_write_close();
        if ($action === 'feelings') {
            $values = array_intersect_key($_POST, array_flip(['friendship', 'love', 'anger', 'emotion', 'intensity']));
            feel_set($cid, $persona, $values);
        } elseif (feel_due($cid)) {
            @set_time_limit(200);
            feel_analyze($cid);
        }
        echo json_encode(feel_state($cid, $persona, $after), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
