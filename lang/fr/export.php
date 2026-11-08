<?php
/*
 * Français — langue de référence. Export d'une conversation : texte, Markdown, page imprimable
 * (src/routes/conversation/export*.php, views/chat_print.php, section « Exporter » de views/chat.php).
 * Toute clé ajoutée ici doit exister dans lang/en/export.php et lang/es/export.php.
 */
return [
    // --- contenu exporté
    'export.me'             => 'Moi',
    'export.says'           => '{name} :',
    'export.with'           => 'Conversation avec {name}',
    'export.exported_at'    => 'Exportée le {date}',
    'export.count'          => ['one' => '{n} message', 'other' => '{n} messages'],
    'export.datetime'       => '{date} à {time}',
    'export.time_format'    => 'H:i', // format PHP date() de l'heure
    'export.photo'          => '[Photo]',
    'export.empty'          => 'Cette conversation ne contient encore aucun message.',

    // --- page imprimable
    'export.print.button'   => 'Imprimer / Enregistrer en PDF',
    'export.print.back'     => 'Retour à la conversation',
    'export.print.tip'      => 'Pour un PDF : choisis « Enregistrer en PDF » comme imprimante (sur téléphone : Partager › Imprimer, puis Enregistrer).',

    // --- feuille de réglages de la conversation
    'export.sheet.title'    => 'Exporter',
    'export.sheet.hint'     => 'Toute la conversation, à garder ou à partager.',
    'export.sheet.txt'      => 'Texte',
    'export.sheet.md'       => 'Markdown',
    'export.sheet.pdf'      => 'PDF',
    'export.sheet.pdf_title'=> 'Ouvre une page à imprimer ou à enregistrer en PDF',
];
