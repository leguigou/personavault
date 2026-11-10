<?php
/*
 * Texts shown by public/assets/app.js (tr() function, key without the "js." prefix).
 * The js.modal.*, js.unsaved.* and js.chat.jump keys are in common.php.
 */
return [
    // common
    'js.common.error'         => 'Error: {message}',
    'js.common.unreadable'    => 'Unreadable response.',
    'js.common.send_failed'   => 'Sending failed.',
    'js.common.delete_failed' => 'could not delete',
    'js.common.impossible'    => 'failed',

    // play directive suggested by the AI
    'js.directive.generating' => 'Generating…',
    'js.directive.unreadable' => 'unreadable response',
    'js.directive.failed'     => 'generation failed',
    'js.directive.done'       => 'Directive suggested — review it, adjust it, then save.',

    // copy a prompt
    'js.copy.copied' => 'Copied',
    'js.copy.toast'  => 'Prompt copied',

    // search
    'js.search.free' => 'Search for “{q}”',

    // persona page
    'js.persona.preview_alt'     => 'Preview',
    'js.persona.ai_fill_busy'    => 'Analyzing prompts…',
    'js.persona.ai_fill_failed'  => 'Completion failed.',
    'js.persona.ai_fill_nothing' => 'Nothing to complete.',
    'js.persona.ai_fill_done'    => 'Profile completed.',
    'js.persona.photo_adding'    => 'Adding the image…',
    'js.persona.photo_added'     => 'Image added.',

    // settings: summaries of collapsible sections (parts separated by "·")
    'js.settings.sum_split_off'      => 'Off',
    'js.settings.sum_split'          => '{max} bubbles max · {words} words',
    'js.settings.sum_facts_off'      => 'Off',
    'js.settings.sum_facts_explicit' => 'On request',
    'js.settings.sum_facts_every'    => 'Every {n} messages',
    'js.settings.sum_facts_each'     => 'After each exchange',
    'js.settings.sum_facts_idle'     => 'when idle',
    'js.settings.sum_facts_remote'   => 'remote',
    'js.settings.sum_facts_local'    => 'local',
    'js.settings.sum_titles_off'     => 'Off',
    'js.settings.sum_titles_local'   => 'Local model',
    'js.settings.sum_titles_remote'  => 'Remote model',
    'js.settings.sum_followup'       => 'After {idle} h of silence · from {from} h to {to} h',
    'js.settings.sum_followup_off'   => 'Off',

    // settings: model test, LM Studio purge
    'js.settings.test_running'    => 'Testing…',
    'js.settings.test_connecting' => 'Connecting to the model…',
    'js.settings.test_failed'     => 'Test failed.',
    'js.settings.test_ok'         => 'Connected · {model} · {ms} ms',
    'js.settings.purging'         => 'Purging…',
    'js.settings.purge_failed'    => 'Purge failed.',
    'js.settings.purge_unloaded'  => ['one' => '{n} model unloaded: {list}', 'other' => '{n} models unloaded: {list}'],
    'js.settings.purge_none'      => 'No model loaded in memory — nothing to purge.',
    'js.settings.purge_done'      => 'Memory purged ({n})',
    'js.settings.purge_nothing'   => 'Nothing to purge',

    // chat
    'js.chat.load_failed'         => 'could not load',
    'js.chat.photo_alt'           => 'Photo sent',
    'js.chat.percent'             => '{n}%',
    'js.chat.ctx_percent'         => '{n}% of context',
    'js.chat.ctx_tokens'          => '{used} / {window} tokens',
    'js.chat.compressing'         => 'Compressing…',
    'js.chat.compressed'          => ['one' => '{n} message summarized', 'other' => '{n} messages summarized'],
    'js.chat.nothing_to_compress' => 'Nothing to summarize',
    'js.chat.compress_failed'     => 'Compression failed',
    'js.chat.memory_updated'      => [
        'one'   => 'Memory updated — {n} message summarized.',
        'other' => 'Memory updated — {n} messages summarized.',
    ],
    'js.chat.memory_phase'        => 'Updating memory…',
    'js.chat.fb_not_loaded'       => 'The model is not loaded',
    'js.chat.fb_no_reply'         => 'LM Studio did not respond',
    'js.chat.fb_kept'             => 'Message kept in the input — send it again when LM Studio is ready.',
    'js.chat.lms_no_answer'       => 'LM Studio is not responding at {url}.',
    'js.chat.configured_address'  => 'the configured address',
    'js.chat.regen'               => 'Regenerate the reply',
    'js.chat.model_hint_on' => 'Test model, for this conversation only. The persona keeps its own.',
    'js.chat.model_default' => 'default model',
    'js.chat.model_changed' => 'Model changed for this conversation',
    'js.chat.model_back' => 'Back to the persona\'s model',
    'js.chat.vision_model' => 'Vision model: can also read images',
    'js.prompt.chars' => ['one' => '{n} character', 'other' => '{n} characters'],
    'js.prompt.modified' => 'edited',
    'js.chat.versions_label' => 'Reply versions',
    'js.chat.versions_count' => '{index} / {total}',
    'js.chat.version_prev' => 'Previous version',
    'js.chat.version_next' => 'Next version',
    'js.chat.version_error' => 'Could not switch version.',
    'js.chat.edit'                => 'Edit this message',
    'js.chat.delete'              => 'Delete this message',
    'js.chat.save'                => 'Save',
    'js.chat.erase_error'         => 'Could not delete: {message}',
    'js.chat.empty_message'       => 'The message cannot be empty.',
    'js.chat.edit_failed'         => 'could not edit',
    'js.chat.edited_replay'       => 'Message edited, new reply…',
    'js.chat.edited'              => 'Message edited.',
    'js.chat.edit_error'          => 'Could not edit: {message}',
    'js.chat.stop_label'          => 'Stop generating',
    'js.chat.stop'                => 'Stop',
    'js.chat.send'                => 'Send',
    'js.chat.choose_image'        => 'Choose an image (jpg, png, webp).',
    'js.chat.interrupted'         => 'Reply interrupted: {message}',
    'js.chat.empty_reply'         => 'Empty reply.',
    'js.chat.aborted'             => 'Generation stopped.',

    // LM Studio status and model loading
    'js.lms.down'                 => 'LM Studio is not running',
    'js.lms.down_url'             => 'LM Studio is not running ({url})',
    'js.lms.down_no_answer'       => 'LM Studio is not running — no response at {url}',
    'js.lms.loaded'               => 'Model loaded: {model}',
    'js.lms.loaded_ctx'           => 'Model loaded: {model} · {n} tokens',
    'js.lms.not_loaded_title'     => '{model} is not loaded (it loads with the first message)',
    'js.lms.not_loaded'           => '{model} is not loaded — it loads with the first message',
    'js.lms.loading'              => 'Loading the model…',
    'js.lms.seconds'              => '{n} s',
    'js.lms.load_now'             => 'Load the model now',
    'js.lms.unreachable'          => 'LM Studio unreachable',
    'js.lms.already_loaded_timer' => 'already loaded',
    'js.lms.already_loaded'       => 'Already loaded',
    'js.lms.request_failed'       => 'Request failed.',
    'js.lms.load_failed'          => 'Loading failed',
    'js.lms.load_error'           => 'Could not load: {message}',
    'js.lms.unknown_error'        => 'unknown error',
    'js.lms.loaded_short'         => 'Model loaded',
    'js.lms.too_long'             => 'Loading took too long',

    // shared memories and search in other conversations
    'js.facts.empty'      => 'No memories yet.',
    'js.facts.delete'     => 'Delete this memory',
    'js.facts.add_failed' => 'could not add',
    'js.facts.added'      => 'Memory added — shared with all of this persona’s conversations.',
    'js.facts.added_one'  => 'Memory added: {fact}',
    'js.facts.enabled'    => 'Memories enabled for this conversation.',
    'js.facts.disabled'   => 'Memories disabled — they stay in memory for the other conversations.',
    'js.recall.memorize'  => 'Remember',
    'js.recall.memorized' => 'Added to shared memories.',
    'js.recall.searching' => 'Searching…',
    'js.recall.none'      => 'Nothing found in the other conversations.',
    'js.recall.failed'    => 'Search failed.',
    // partagés avec les vues (affichage initial côté serveur, mise à jour côté JS)
    'js.chat.load_older'               => ['one' => 'Load {n} older message', 'other' => 'Load {n} older messages'],
    'js.chat.ctx_auto_summary'   => [
        'one'   => 'Auto-summary beyond {threshold} unsummarized tokens · {n} done',
        'other' => 'Auto-summary beyond {threshold} unsummarized tokens · {n} done',
    ],
    // date et heure affichées sous un message touché ({date} : « Aujourd’hui », « 8 nov. »…)
    'js.chat.msg_time' => '{date} at {time}',
    // titres du panneau de la conversation (anneau / roue)
    'js.chat.sheet_memory' => 'Context and memory',
    'js.chat.sheet_conversation' => 'Conversation',
    // interrupteurs de la conversation enregistrés au changement
    'js.chat.toggle_saved' => 'Saved',
    'js.chat.toggle_failed' => 'Couldn’t save, please try again.',
    // curseur à deux poignées des pauses (aussi utilisé côté serveur)
    'js.settings.pause_range' => '{min} s – {max} s',
];
