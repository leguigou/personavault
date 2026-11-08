<?php
/*
 * English. Conversation export: plain text, Markdown, printable page.
 */
return [
    // --- exported content
    'export.me'             => 'Me',
    'export.says'           => '{name}:',
    'export.with'           => 'Conversation with {name}',
    'export.exported_at'    => 'Exported on {date}',
    'export.count'          => ['one' => '{n} message', 'other' => '{n} messages'],
    'export.datetime'       => '{date}, {time}',
    'export.time_format'    => 'g:i A', // PHP date() format for the time
    'export.photo'          => '[Photo]',
    'export.empty'          => 'This conversation has no messages yet.',

    // --- printable page
    'export.print.button'   => 'Print / Save as PDF',
    'export.print.back'     => 'Back to the conversation',
    'export.print.tip'      => 'For a PDF, choose “Save as PDF” as the printer (on a phone: Share › Print, then Save).',

    // --- conversation settings sheet
    'export.sheet.title'    => 'Export',
    'export.sheet.hint'     => 'The whole conversation, to keep or share.',
    'export.sheet.txt'      => 'Text',
    'export.sheet.md'       => 'Markdown',
    'export.sheet.pdf'      => 'PDF',
    'export.sheet.pdf_title'=> 'Opens a page to print or save as PDF',
];
