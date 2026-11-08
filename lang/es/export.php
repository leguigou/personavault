<?php
/*
 * Español (tuteo). Exportación de una conversación: texto, Markdown, página imprimible.
 */
return [
    // --- contenido exportado
    'export.me'             => 'Yo',
    'export.says'           => '{name}:',
    'export.with'           => 'Conversación con {name}',
    'export.exported_at'    => 'Exportada el {date}',
    'export.count'          => ['one' => '{n} mensaje', 'other' => '{n} mensajes'],
    'export.datetime'       => '{date}, {time}',
    'export.time_format'    => 'H:i', // formato PHP date() de la hora
    'export.photo'          => '[Foto]',
    'export.empty'          => 'Esta conversación aún no tiene mensajes.',

    // --- página imprimible
    'export.print.button'   => 'Imprimir / Guardar en PDF',
    'export.print.back'     => 'Volver a la conversación',
    'export.print.tip'      => 'Para un PDF, elige «Guardar como PDF» como impresora (en el móvil: Compartir › Imprimir y luego Guardar).',

    // --- hoja de ajustes de la conversación
    'export.sheet.title'    => 'Exportar',
    'export.sheet.hint'     => 'Toda la conversación, para guardarla o compartirla.',
    'export.sheet.txt'      => 'Texto',
    'export.sheet.md'       => 'Markdown',
    'export.sheet.pdf'      => 'PDF',
    'export.sheet.pdf_title'=> 'Abre una página para imprimir o guardar en PDF',
];
