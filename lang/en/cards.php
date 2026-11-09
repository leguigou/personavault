<?php
/*
 * English — exporting and importing persona cards.
 * Reference: lang/fr/cards.php (same keys, same {parameters}).
 */
return [
    // --- export (persona page)
    'cards.export'                     => 'Export',
    'cards.export_title'               => 'Export this persona',
    'cards.export_zip'                 => 'PersonaVault archive (.zip)',
    'cards.export_zip_hint'            => 'Everything: portrait, photos, prompts, attributes and AI settings. Re-imports without loss.',
    'cards.export_json'                => 'Character Card V2 (.json)',
    'cards.export_json_hint'           => 'Open format, read by most role-play apps.',
    'cards.export_png'                 => 'Character Card V2 (.png)',
    'cards.export_png_hint'            => 'The portrait, with the card hidden inside the image.',
    'cards.export_png_hint_initials'   => 'No portrait: an image with the initials will be generated, with the card hidden inside.',
    'cards.export_note'                => 'Gallery photos are only included in the PersonaVault archive. Conversations are never exported here.',
    'cards.export_badge_full' => 'Complete',

    // --- import (library, new persona)
    'cards.import_title'               => 'Import a card: PersonaVault archive (.zip), Character Card (.json or .png)',
    'cards.import_hint'                => 'Already have a card? Import a PersonaVault archive (.zip) or a Character Card (.json, .png).',
    'cards.import_file'                => 'Import a file',
    'cards.import_formats' => 'PersonaVault archive (.zip) or Character Card (.json, .png)',
    'cards.import_title_modal' => 'Import a card',

    // --- labels for card fields with no direct equivalent ("Other" prompts, attributes)
    'cards.field.personality'          => 'Personality',
    'cards.field.scenario'             => 'Scenario',
    'cards.field.first_mes'            => 'First message',
    'cards.field.alternate_greetings'  => 'Alternate greeting',
    'cards.field.mes_example'          => 'Example dialogues',
    'cards.field.creator_notes'        => 'Creator notes',
    'cards.field.creator'              => 'Creator',
    'cards.field.character_version'    => 'Card version',

    // --- messages
    'cards.flash.imported'             => '{name} has been imported.',
    'cards.flash.import_failed'        => 'Import failed: {error}',
    'cards.flash.export_failed'        => 'Export failed: {error}',
    'cards.error.too_big'              => 'card too large (maximum {max}).',
    'cards.error.invalid_json'         => 'the file is not valid JSON.',
    'cards.error.not_a_card'           => 'this is not a Character Card.',
    'cards.error.unsupported'          => 'unsupported card format ({spec}).',
    'cards.error.no_name'              => 'the card has no name.',
    'cards.error.png_invalid'          => 'invalid PNG image.',
    'cards.error.png_no_card'          => 'this PNG image does not contain a card.',
    'cards.error.png_write'            => 'could not create the image.',
    'cards.error.unknown_format'       => 'unrecognized format: choose a .zip, .json or .png file.',
    'cards.error.nothing'              => 'no persona found in the file.',
];
