<?php
/*
 * Français — langue de référence. Export et import de fiches (src/cards.php,
 * views/detail.php, views/library.php, views/form.php).
 */
return [
    // --- export (fiche persona)
    'cards.export'                     => 'Exporter',
    'cards.export_title'               => 'Exporter la fiche',
    'cards.export_zip'                 => 'Archive PersonaVault (.zip)',
    'cards.export_zip_hint'            => 'Tout : portrait, photos, prompts, attributs et réglages IA. Se réimporte sans perte.',
    'cards.export_json'                => 'Character Card V2 (.json)',
    'cards.export_json_hint'           => 'Format ouvert, lu par la plupart des applis de jeu de rôle.',
    'cards.export_png'                 => 'Character Card V2 (.png)',
    'cards.export_png_hint'            => 'Le portrait, avec la fiche cachée dans l’image.',
    'cards.export_png_hint_initials'   => 'Pas de portrait : une image avec les initiales sera générée, avec la fiche cachée dedans.',
    'cards.export_note'                => 'Les photos de la galerie ne sont incluses que dans l’archive PersonaVault. Les conversations ne sont jamais exportées ici.',
    'cards.export_badge_full' => 'Complet',

    // --- import (bibliothèque, création)
    'cards.import_tile'                => 'Importer une fiche',
    'cards.import_title'               => 'Importer une fiche : archive PersonaVault (.zip), Character Card (.json ou .png)',
    'cards.import_hint'                => 'Tu as déjà une fiche ? Importe une archive PersonaVault (.zip) ou une Character Card (.json, .png).',
    'cards.import_file'                => 'Importer un fichier',
    'cards.import_formats' => 'Archive PersonaVault (.zip) ou Character Card (.json, .png)',
    'cards.import_title_modal' => 'Importer une fiche',

    // --- intitulés des champs de carte sans équivalent (prompts « Autre », attributs)
    'cards.field.personality'          => 'Personnalité',
    'cards.field.scenario'             => 'Scénario',
    'cards.field.first_mes'            => 'Premier message',
    'cards.field.alternate_greetings'  => 'Autre premier message',
    'cards.field.mes_example'          => 'Exemples de dialogue',
    'cards.field.creator_notes'        => 'Notes du créateur',
    'cards.field.creator'              => 'Créateur',
    'cards.field.character_version'    => 'Version de la fiche',

    // --- messages
    'cards.flash.imported'             => '{name} a été importé.',
    'cards.flash.import_failed'        => 'Import impossible : {error}',
    'cards.flash.export_failed'        => 'Export impossible : {error}',
    'cards.error.too_big'              => 'fiche trop volumineuse (maximum {max}).',
    'cards.error.invalid_json'         => 'le fichier n’est pas un JSON valide.',
    'cards.error.not_a_card'           => 'ce n’est pas une Character Card.',
    'cards.error.unsupported'          => 'format de fiche non pris en charge ({spec}).',
    'cards.error.no_name'              => 'la fiche n’a pas de nom.',
    'cards.error.png_invalid'          => 'image PNG invalide.',
    'cards.error.png_no_card'          => 'cette image PNG ne contient pas de fiche.',
    'cards.error.png_write'            => 'impossible de créer l’image.',
    'cards.error.unknown_format'       => 'format non reconnu : choisis un fichier .zip, .json ou .png.',
    'cards.error.nothing'              => 'aucun persona trouvé dans le fichier.',
];
