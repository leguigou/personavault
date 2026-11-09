<?php
/*
 * English — chats: list (views/chat_list.php) and conversation (views/chat.php).
 * Same keys as lang/fr/chat.php (reference).
 */
return [
    // --- shared by both screens
    'chat.unread'                   => 'New message',

    // --- chat list
    'chat.list.title'               => 'My conversations',
    'chat.list.new'                 => 'New',
    'chat.list.status_local'        => 'Local chat · {model}',
    'chat.list.model_auto'          => 'auto model',
    'chat.list.no_model'            => 'No chat model reachable',
    'chat.list.empty_title'         => 'No conversations yet',
    'chat.list.empty_text'          => 'Pick a persona and start talking.',
    'chat.list.pick_persona'        => 'Choose a persona',
    'chat.list.no_message'          => 'No messages yet',
    'chat.list.message_count'       => ['one' => '{n} msg', 'other' => '{n} msgs'],
    'chat.list.search_persona'      => 'Search for a persona…',
    'chat.list.no_persona'          => 'No personas in your library. Create a profile first.',

    // --- conversation: sidebar and header
    'chat.side.all'                 => 'All',
    'chat.side.new'                 => 'New conversation',
    'chat.head.back'                => 'All conversations',
    'chat.head.open_persona'        => 'Open the character’s profile',
    'chat.head.model_state'         => 'Model status',
    'chat.head.model_default'       => 'default model',
    'chat.head.context_memory'      => 'Context and memory',
    'chat.head.settings'            => 'Conversation settings',

    // --- conversation: messages and composer
    'chat.intro.first_message'      => 'Write the first message.',
    'chat.msg.photo_sent'           => 'Photo sent',
    'chat.composer.attach_preview'  => 'Preview of the attached photo',
    'chat.composer.attach_remove'   => 'Remove the photo',
    'chat.composer.attach'          => 'Attach a photo',
    'chat.composer.placeholder'     => 'Write a message…',
    'chat.composer.label'           => 'Message',
    'chat.composer.send'            => 'Send',
    'chat.foot.context'             => 'context {used} / {window}',
    'chat.foot.compressions'        => ['one' => '{n} compression', 'other' => '{n} compressions'],
    'chat.foot.no_model'            => 'No chat model reachable — check LM Studio in the settings.',

    // --- “context and settings” panel
    'chat.sheet.label'              => 'Conversation context and settings',
    'chat.sheet.eyebrow'            => 'Conversation',
    'chat.sheet.ctx_percent'        => '{percent}% of context',
    'chat.sheet.ctx_tokens'         => '{used} / {window} tokens',
    'chat.sheet.save'               => 'Save',

    // --- local server status and model loading
    'chat.lms.checking'             => 'Checking the server…',
    'chat.lms.refresh'              => 'Refresh status',
    'chat.lms.step_server'          => 'Server',
    'chat.lms.step_request'         => 'Request',
    'chat.lms.step_loading'         => 'Loading',
    'chat.lms.step_ready'           => 'Ready',
    'chat.lms.timer_zero'           => '0.0 s',

    // --- character settings (summary)
    'chat.persona.title'            => 'Character settings',
    'chat.persona.edit'             => 'Edit',
    'chat.persona.model_global'     => 'global model',
    'chat.persona.temperature'      => 'temperature {value}',
    'chat.persona.max_tokens'       => '{count} tokens',
    'chat.persona.window'           => 'window {size}',
    'chat.persona.directive'        => '“{text}”',

    // --- conversation options
    'chat.toggles.memory'           => 'Keep memory (rolling summary)',
    'chat.toggles.followup'         => 'Spontaneous messages after a long silence',

    // --- memory (rolling summary)
    'chat.memory.title'             => 'Memory',
    'chat.memory.percent'           => '{percent}%',
    'chat.memory.stat_messages'     => 'messages',
    'chat.memory.stat_unsummarized' => 'unsummarized',
    'chat.memory.stat_compressions' => 'compressions',
    'chat.memory.stat_tokens'       => 'tokens',
    'chat.memory.summary_label'     => 'Current summary',
    'chat.memory.summary_placeholder' => 'Empty for now: it fills in automatically once the conversation gets long.',
    'chat.memory.clear'             => 'Clear',
    'chat.memory.compress'          => 'Compress',
    'chat.memory.manage'            => 'Manage long-term memories',

    // --- shared memories
    'chat.facts.title'              => 'Shared memories',
    'chat.facts.manage_all'         => 'Manage all',
    'chat.facts.hint'               => 'Apply to every conversation with {name}.',
    'chat.facts.use'                => 'Use these memories in this conversation',
    'chat.facts.delete'             => 'Delete this memory',
    'chat.facts.empty'              => 'No memories yet.',
    'chat.facts.add_placeholder'    => 'Add a memory…',
    'chat.facts.add_label'          => 'New memory',
    'chat.facts.add'                => 'Add',

    // --- search across the persona's conversations
    'chat.recall.title'             => 'Search their memories',
    'chat.recall.hint'              => 'Across every conversation with {name}.',
    'chat.recall.placeholder'       => 'E.g. Le Pradet, their job…',
    'chat.recall.label'             => 'Search',
    'chat.recall.submit'            => 'Search',

    // --- rename / delete
    'chat.rename.label'             => 'Conversation title',
    'chat.rename.submit'            => 'Rename',
    'chat.delete.confirm'           => 'Delete this conversation and all its messages?',
    'chat.delete.submit'            => 'Delete conversation',

    // --- switch to the remote model (#fb-modal)
    'chat.fallback.title'           => 'LM Studio isn’t responding',
    'chat.fallback.hint'            => 'You can wait for it to come back — the message will be resent as is — or have DeepSeek generate the reply.',
    'chat.fallback.wait'            => 'Wait and retry',
    'chat.fallback.use'             => 'Use DeepSeek',
];
