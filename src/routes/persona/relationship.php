<?php
declare(strict_types=1);

if ($action === 'relationship') {
    if ($method === 'POST') {
        csrf_check();
        $operation = $_POST['operation'] ?? '';
        if (in_array($operation, ['settings', 'save', 'delete'], true)) {
            try {
                relationship_edit($pid, $operation, $_POST);
                flash('ok', t('relationship.saved'));
            } catch (DomainException $e) {
                flash('error', $e->getMessage());
            }
        }
        redirect('/p/' . rawurlencode($persona['slug']) . '/relationship');
    }
    if ($method === 'GET') {
        render('relationship', [
            'title' => t('relationship.title'),
            'persona' => $persona,
            'state' => relationship_state($pid),
            'entries' => relationship_entries($pid),
        ]);
        exit;
    }
}
