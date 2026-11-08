<?php
/*
 * English — Settings page (views/settings.php). Same keys as lang/fr/settings.php.
 */
return [
    // --- header and tabs
    'settings.subtitle'         => 'Connections, chats and appearance — one tab per topic.',
    'settings.tabs_label'       => 'Settings sections',
    'settings.tab.ia_long'      => 'Intelligence',
    'settings.tab.ia_short'     => 'AI',
    'settings.tab.chat_long'    => 'Chat',
    'settings.tab.chat_short'   => 'Chat',
    'settings.tab.look'         => 'Appearance',
    'settings.tab.prompts'      => 'Prompts',
    'settings.tab.backup'       => 'Backup',

    // --- shared by several tabs
    'settings.info_more'        => 'Learn more: {label}',
    'settings.dirty'            => 'Unsaved changes',
    'settings.save'             => 'Save',
    'settings.field.provider'   => 'Provider',
    'settings.field.base_url'   => 'Base URL',
    'settings.field.model'      => 'Model',
    'settings.field.model_used' => 'Model used',
    'settings.clear_key'        => 'Delete the saved key',
    'settings.model.remote'     => 'Profile model (remote)',
    'settings.model.chat'       => 'Chat model (local)',
    'settings.model.remote_not_ready' => 'The profile model is not configured (AI tab): the local model is used in the meantime.',
    'settings.provider.lmstudio' => 'LM Studio (local)',
    'settings.provider.custom'  => 'Other / OpenAI-compatible',
    'settings.provider.other'   => 'Other',

    // --- AI tab: profile creation
    'settings.ia.title'         => 'Profile creation',
    'settings.ia.subtitle'      => 'Model that fills in persona profiles (AI button).',
    'settings.ia.model_examples' => 'E.g. deepseek-chat, deepseek-reasoner',
    'settings.ia.api_key'       => 'API key',
    'settings.ia.api_key_keep'  => 'Leave empty to keep the key',
    'settings.ia.api_key_saved_help' => 'A key is already saved. Enter a new one to replace it.',
    'settings.ia.api_key_help'  => 'Stored in the app’s local database, never displayed in plain text.',
    'settings.ia.status_ready'  => 'Ready · {model}',
    'settings.ia.status_no_key' => 'No key saved',
    'settings.ia.test'          => 'Test connection',
    'settings.ia.how_title'     => 'How it works',
    'settings.ia.how_text_html' => 'On a profile, the <strong>Complete with AI</strong> button reads the persona’s prompts and
            fills in only what is missing: tagline, description, tags and attributes.
            Anything already entered is never overwritten, and <strong>your prompts are never modified</strong>:
            the AI only reads them.',
    'settings.ia.how_privacy'   => 'Nothing is sent unless you click: the app only sends data to the model when you trigger this action.',

    // --- Chat tab
    'settings.chat.title'       => 'Chat model',
    'settings.chat.subtitle'    => 'Used only to talk with personas. If LM Studio is off, the app switches to DeepSeek and lets you know.',
    'settings.chat.connection_title' => 'Model connection',
    'settings.chat.model_aria'  => 'Chat model',
    'settings.chat.models_detected' => [
        'one'   => '{n} model detected · ★ = recommended for chatting.',
        'other' => '{n} models detected · ★ = recommended for chatting.',
    ],
    'settings.chat.model_manual' => 'Server unreachable: type the model name by hand.',
    'settings.chat.api_key'     => 'API key (optional)',
    'settings.chat.api_key_saved_placeholder' => 'Key saved — leave empty',
    'settings.chat.api_key_placeholder' => 'empty for LM Studio',
    'settings.chat.memory_title' => 'Reasoning and memory',
    'settings.chat.reasoning'   => 'Model reasoning',
    'settings.chat.reasoning_none'   => 'Off (recommended)',
    'settings.chat.reasoning_low'    => 'Low',
    'settings.chat.reasoning_medium' => 'Medium',
    'settings.chat.reasoning_high'   => 'High',
    'settings.chat.reasoning_help' => 'Qwen3 / Gemma 4 models “think” before answering and can use up the budget without ever answering. Off = direct answer.',
    'settings.chat.threshold'   => 'Compression threshold (tokens)',
    'settings.chat.threshold_help' => 'Beyond this, older messages are summarized.',
    'settings.chat.target'      => 'Recent messages kept (tokens)',
    'settings.chat.target_help' => 'What stays verbatim after compression.',

    // --- Chat tab: replies in several bubbles
    'settings.split.title'      => 'Replies in several bubbles',
    'settings.split.hint'       => 'Splits a long reply into two messages, at the end of a sentence, with a short pause.',
    'settings.split.enable'     => 'Split replies into several bubbles',
    'settings.split.words'      => 'Maximum words per bubble',
    'settings.split.words_help' => 'The bubble is cut at the last sentence end before this limit.',
    'settings.split.bubbles'    => 'Maximum bubbles',
    'settings.split.bubbles_help' => '1 = never split.',
    'settings.split.delay_min'  => 'Min pause',
    'settings.split.delay_max'  => 'Max pause',
    'settings.split.delay_help' => 'Random pause between two bubbles, within this range.',

    // --- Chat tab: spontaneous messages
    'settings.followup.title'   => 'Spontaneous messages',
    'settings.followup.hint'    => 'The persona can write to you on its own after a long silence (can be turned off chat by chat).',
    'settings.followup.enable'  => 'Allow spontaneous follow-ups',
    'settings.followup.idle'    => 'Safety: minimum silence',
    'settings.followup.idle_help' => 'Hours of silence before they can follow up.',
    'settings.followup.hours'   => 'Time window',
    'settings.followup.hours_from' => 'from',
    'settings.followup.hours_to'   => 'h to',
    'settings.followup.hours_end'  => 'h',
    'settings.followup.from_aria'  => 'Start hour',
    'settings.followup.to_aria'    => 'End hour',
    'settings.followup.hours_help' => 'No follow-up outside this window.',

    // --- Chat tab: lasting memories
    'settings.facts.title'      => 'Lasting memories',
    'settings.facts.hint'       => 'What personas remember about you from one chat to the next. Choose when
                the app extracts them and with which model.',
    'settings.facts.frequency'  => 'Frequency',
    'settings.facts.frequency_aria' => 'Extraction frequency',
    'settings.facts.freq_each'  => 'After each exchange',
    'settings.facts.freq_every' => 'Every N messages',
    'settings.facts.freq_explicit' => 'Only on request (“remember that…”)',
    'settings.facts.freq_off'   => 'Never',
    'settings.facts.every'      => 'Number of new messages',
    'settings.facts.every_help' => 'One exchange = 2 messages (yours and the reply).',
    'settings.facts.timing'     => 'Timing',
    'settings.facts.timing_aria' => 'Extraction timing',
    'settings.facts.timing_now' => 'Right after the reply',
    'settings.facts.timing_idle' => 'When the chat is quiet',
    'settings.facts.idle'       => 'Quiet for (seconds)',
    'settings.facts.idle_help'  => 'No new message or typing during this delay.',
    'settings.facts.model_aria' => 'Model used for memories',

    // --- Chat tab: automatic titles
    'settings.titles.title'     => 'Chat titles',
    'settings.titles.hint'      => 'After the first exchanges, the chat gets a short title
                (as long as you haven’t renamed it yourself).',
    'settings.titles.model_aria' => 'Model for automatic titles',
    'settings.titles.off'       => 'No automatic title',

    // --- Chat tab: LM Studio maintenance
    'settings.purge.title'      => 'LM Studio maintenance',
    'settings.purge.text'       => 'Switching models already unloads the previous one automatically. This button forces
            video memory (VRAM) to be freed if LM Studio keeps models loaded.',
    'settings.purge.button'     => 'Purge LM Studio memory',

    // --- Appearance tab
    'settings.look.title'       => 'Chat appearance',
    'settings.look.subtitle'    => 'Applies to all chats. The preview updates live.',
    'settings.look.preview'     => 'Preview',
    'settings.look.preview_bot_html'  => 'Hi! Did you have a good day?',
    'settings.look.preview_user_html' => 'Yeah, and you?',
    'settings.look.tabbar'          => 'Navigation bar (phone)',
    'settings.look.tabbar_default'  => 'Current',
    'settings.look.tabbar_float'    => 'Floating capsule',
    'settings.look.tabbar_pill'     => 'Expanding pill',
    'settings.look.tabbar_classic'  => 'Refined classic',
    'settings.look.tabbar_help'     => 'Shown on phones and tablets; the preview applies right away at the bottom of the screen.',
    'settings.look.font_size'   => 'Text size',
    'settings.look.font_small'  => 'Small',
    'settings.look.font_normal' => 'Normal',
    'settings.look.font_large'  => 'Large',
    'settings.look.font_xlarge' => 'Extra large',
    'settings.look.bubble_style' => 'Bubble style',
    'settings.look.bubble_round' => 'Rounded bubbles',
    'settings.look.bubble_flat' => 'Flat',
    'settings.look.bubble_card' => 'Cards',
    'settings.look.density'     => 'Density',
    'settings.look.density_comfort' => 'Comfortable',
    'settings.look.density_compact' => 'Compact',
    'settings.look.width'       => 'Message width',
    'settings.look.width_normal' => 'Normal',
    'settings.look.width_large' => 'Wide',
    'settings.look.avatars'     => 'Show the character’s avatar next to their messages',
    'settings.look.save'        => 'Save appearance',

    // --- Prompts tab
    'settings.prompts.title'    => 'Internal prompts',
    'settings.prompts.subtitle' => 'The instructions the app sends to the model for memory, memories and follow-ups. Empty = default text.',
    'settings.prompts.memory_label'   => 'Conversation summary',
    'settings.prompts.memory_help'    => 'Compresses older messages. Must return a JSON with current_topic, conversation_goal, important_facts, decisions, open_questions, context_summary.',
    'settings.prompts.facts_label'    => 'Memory extraction',
    'settings.prompts.facts_help'     => 'Decides what becomes a lasting memory about the user (persona). Must return a JSON with memories / forget.',
    'settings.prompts.followup_label' => 'Spontaneous follow-up',
    'settings.prompts.followup_help'  => 'Instruction for the unsolicited message. Available placeholders: {persona} and {gap}.',
    'settings.prompts.custom'   => 'customized',
    'settings.prompts.reset'    => 'Reset',
    'settings.prompts.save'     => 'Save prompts',

    // --- Backup tab
    'settings.backup.title'     => 'Backup',
    'settings.backup.subtitle'  => 'One ZIP per backup: one folder per persona with its profile, prompts and photos.',
    'settings.backup.export'    => 'Export',
    'settings.backup.count_personas' => ['one' => '{n} persona', 'other' => '{n} personas'],
    'settings.backup.count_photos'   => ['one' => '{n} photo', 'other' => '{n} photos'],
    'settings.backup.count_prompts'  => ['one' => '{n} prompt', 'other' => '{n} prompts'],
    'settings.backup.count_conversations' => ['one' => '{n} conversation', 'other' => '{n} conversations'],
    'settings.backup.include_conversations' => 'Include conversations, messages and memories',
    'settings.backup.download'  => 'Download the backup',
    'settings.backup.last'      => 'Last backup: {date}',
    'settings.backup.size_mb'   => '{size} MB',
    'settings.backup.none'      => 'No backup downloaded yet.',
    'settings.backup.restore'   => 'Restore',
    'settings.backup.restore_hint' => 'Imports a backup ZIP. Existing personas are never overwritten: a unique slug is created.',
    'settings.backup.import_confirm' => 'Import this backup? The personas will be added to the library.',
    'settings.backup.import_confirm_title' => 'Import the backup',
    'settings.backup.import_confirm_ok' => 'Import',
    'settings.backup.choose_file' => 'Choose a ZIP file',
    'settings.backup.import_submit' => 'Import the archive',
    'settings.backup.tip'       => 'Tip: keep the ZIP off the server (external drive, cloud). It contains everything you need to start over from scratch.',

    // --- info bubbles (“i” button): button label, window title, content
    'settings.info.ia_model_label'    => 'profile model',
    'settings.info.ia_model_title'    => 'Profile model',
    'settings.info.ia_model_html'     => '<p>This model handles one-off tasks: <strong>completing a profile</strong> with the AI button,
            suggesting a role-play instruction, and extracting memories if you choose so.</p>
            <p>It is usually a remote service (DeepSeek, OpenAI, OpenRouter…). Data is only sent
            when you click an AI action.</p>',
    'settings.info.api_key_label'     => 'API key',
    'settings.info.api_key_html'      => '<p>The key is stored in the app’s local database and is <strong>never shown again</strong>
            on the page. Leave the field empty to keep the current key.</p>
            <p>The “Test connection” button only sends the saved key to the saved address: if you
            change the address, enter the key again.</p>',
    'settings.info.chat_model_label'  => 'chat model',
    'settings.info.chat_model_html'   => '<p>This is the model that makes the personas talk, usually <strong>LM Studio on your machine</strong>
            (address <code>http://localhost:1234/v1</code> by default). Nothing leaves your place.</p>
            <p>If the server is unreachable, the app asks you what to do: it never switches on its own
            to a remote service.</p>',
    'settings.info.reasoning_label'   => 'model reasoning',
    'settings.info.reasoning_html'    => '<p>Some models (Qwen3, Gemma…) “think” before answering. It is slower and
            uses up the token budget, sometimes to the point of never answering.</p>
            <p>For a natural conversation, leave it <strong>Off</strong>.</p>',
    'settings.info.compression_label' => 'conversation memory',
    'settings.info.compression_title' => 'Conversation memory',
    'settings.info.compression_html'  => '<p>The model can only read a limited number of tokens (its “window”). When the messages
            <strong>not yet summarized</strong> exceed the <strong>compression threshold</strong>, the oldest ones
            are summarized in a few lines (topic, facts, decisions).</p>
            <p>The model then receives this summary + the recent messages (about the
            “recent messages kept” value) instead of the whole history: faster replies and
            more room for what comes next.</p>
            <p>One token ≈ 3 to 4 characters of text.</p>',
    'settings.info.split_label'       => 'bubbles',
    'settings.info.split_html'        => '<p>A long reply is cut at the end of a sentence and shown as several messages, with a
            short pause between each, like in a real messaging app.</p>
            <p>“Maximum bubbles” set to 1 turns splitting off.</p>',
    'settings.info.followup_label'    => 'spontaneous messages',
    'settings.info.followup_html'     => '<p>After a long silence, the persona can write to you on its own, only within the chosen
            time window and at most once per period of silence.</p>
            <p>These follow-ups are triggered by a scheduled task on the server, for example every
            30 minutes: <code>php bin/followup.php</code> (cron). Without it, nothing is sent.</p>',
    'settings.info.facts_label'       => 'lasting memories',
    'settings.info.facts_html'        => '<p>After some exchanges, the app asks a model what is worth remembering about you
            (first name, tastes, plans…). These memories are shared across all of a persona’s conversations
            and visible on the <strong>Memories</strong> page.</p>
            <p>Extraction happens <strong>in the background</strong>: it never blocks the display.
            However, if it uses the local model, it keeps it busy for a few seconds.</p>',
    'settings.info.facts_frequency_label' => 'frequency',
    'settings.info.facts_frequency_html'  => '<p><strong>After each exchange</strong>: the most responsive, but one model call per reply.</p>
            <p><strong>Every N messages</strong>: groups several exchanges into a single call. Nothing is lost,
            all messages since the last extraction are analyzed.</p>
            <p><strong>Only on request</strong>: only when you write “remember that…”, “don’t forget…”.</p>
            <p><strong>Never</strong>: no new automatic memories (existing ones are still used).</p>',
    'settings.info.facts_timing_label' => 'extraction timing',
    'settings.info.facts_timing_html'  => '<p><strong>Right after the reply</strong>: runs just after each reply.</p>
            <p><strong>When the chat is quiet</strong>: waits until you haven’t written anything for the chosen
            delay. The local model thus stays available while you chat. If you leave the page
            before that, extraction will happen at the next opportunity, with nothing lost.</p>',
    'settings.info.facts_model_label' => 'memory model',
    'settings.info.facts_model_html'  => '<p><strong>Chat model (local)</strong>: everything stays on your machine.</p>
            <p><strong>Profile model (remote)</strong>: takes load off the local model, but the latest messages of
            the conversation are then <strong>sent to the remote service</strong> (DeepSeek, OpenAI…).
            Choose with full knowledge of this.</p>',
    'settings.info.titles_label'      => 'automatic titles',
    'settings.info.titles_html'       => '<p><strong>Profile model (remote)</strong>: the first 4 messages of the chat
            (300 characters each at most) are <strong>sent to the remote service</strong> to find a title.
            Fast, and the local model stays free.</p>
            <p><strong>Chat model (local)</strong>: nothing leaves your machine; the local model
            is busy for a second or two after the first exchange.</p>
            <p><strong>No automatic title</strong>: the chat keeps “Chat with …”
            until you rename it.</p>',
    'settings.info.purge_label'       => 'purge',
    'settings.info.purge_html'        => '<p>Unloads all models loaded in LM Studio to free up graphics card memory
            (VRAM). Useful if LM Studio keeps several models in memory and slows down.</p>
            <p>The chat model will be reloaded automatically with the next message.</p>',
    'settings.info.look_label'        => 'appearance',
    'settings.info.look_html'         => '<p>These settings only change how conversations are displayed, on all your devices.
            The preview at the top of the form shows the result before saving.</p>',
    'settings.info.prompts_label'     => 'internal prompts',
    'settings.info.prompts_html'      => '<p>These are the instructions the app sends to the model to <strong>summarize</strong> a conversation,
            <strong>extract memories</strong> and write <strong>follow-ups</strong>.</p>
            <p>The first two must make the model answer in JSON with the listed fields: if you
            edit them, keep this format. “Reset” restores the original text.</p>',
    'settings.info.backup_label'      => 'backup',
    'settings.info.backup_html'       => '<p>The ZIP contains one folder per persona: profile, prompts, portrait and photos. Tick the option to
            add conversations and memories.</p>
            <p>On import, nothing is overwritten: a persona that already exists is recreated under a new URL name.
            Keep your backups off the server.</p>',
];
