<?php
/*
 * Español — exportar e importar fichas de persona.
 * Referencia: lang/fr/cards.php (mismas claves, mismos {parámetros}). Tuteo, como en francés.
 */
return [
    // --- exportar (ficha del persona)
    'cards.export'                     => 'Exportar',
    'cards.export_title'               => 'Exportar la ficha',
    'cards.export_close'               => 'Cerrar',
    'cards.export_zip'                 => 'Archivo PersonaVault (.zip)',
    'cards.export_zip_hint'            => 'Todo: retrato, fotos, prompts, atributos y ajustes de IA. Se vuelve a importar sin pérdidas.',
    'cards.export_json'                => 'Character Card V2 (.json)',
    'cards.export_json_hint'           => 'Formato abierto, compatible con la mayoría de las apps de rol.',
    'cards.export_png'                 => 'Character Card V2 (.png)',
    'cards.export_png_hint'            => 'El retrato, con la ficha escondida dentro de la imagen.',
    'cards.export_png_hint_initials'   => 'Sin retrato: se generará una imagen con las iniciales, con la ficha escondida dentro.',
    'cards.export_note'                => 'Las fotos de la galería solo van en el archivo PersonaVault. Las conversaciones nunca se exportan aquí.',
    'cards.download'                   => 'Descargar',

    // --- importar (biblioteca, creación)
    'cards.import_tile'                => 'Importar una ficha',
    'cards.import_title'               => 'Importar una ficha: archivo PersonaVault (.zip), Character Card (.json o .png)',
    'cards.import_hint'                => '¿Ya tienes una ficha? Importa un archivo PersonaVault (.zip) o una Character Card (.json, .png).',
    'cards.import_file'                => 'Importar un archivo',

    // --- nombres de los campos de la ficha sin equivalente (prompts «Otro», atributos)
    'cards.field.personality'          => 'Personalidad',
    'cards.field.scenario'             => 'Escenario',
    'cards.field.first_mes'            => 'Primer mensaje',
    'cards.field.alternate_greetings'  => 'Saludo alternativo',
    'cards.field.mes_example'          => 'Ejemplos de diálogo',
    'cards.field.creator_notes'        => 'Notas del creador',
    'cards.field.creator'              => 'Creador',
    'cards.field.character_version'    => 'Versión de la ficha',

    // --- mensajes
    'cards.flash.imported'             => '{name} se ha importado.',
    'cards.flash.import_failed'        => 'No se pudo importar: {error}',
    'cards.flash.export_failed'        => 'No se pudo exportar: {error}',
    'cards.error.too_big'              => 'ficha demasiado grande (máximo {max}).',
    'cards.error.invalid_json'         => 'el archivo no es un JSON válido.',
    'cards.error.not_a_card'           => 'no es una Character Card.',
    'cards.error.unsupported'          => 'formato de ficha no compatible ({spec}).',
    'cards.error.no_name'              => 'la ficha no tiene nombre.',
    'cards.error.png_invalid'          => 'imagen PNG no válida.',
    'cards.error.png_no_card'          => 'esta imagen PNG no contiene ninguna ficha.',
    'cards.error.png_write'            => 'no se pudo crear la imagen.',
    'cards.error.unknown_format'       => 'formato no reconocido: elige un archivo .zip, .json o .png.',
    'cards.error.nothing'              => 'no se encontró ningún persona en el archivo.',
];
