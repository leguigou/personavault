<?php
/*
 * Français — langue de référence. Discussions : liste (views/chat_list.php) et conversation (views/chat.php).
 * Toute clé ajoutée ici doit exister dans lang/en/chat.php et lang/es/chat.php (vérifié par bin/i18n-check.php).
 */
return [
    // --- commun aux deux écrans
    'chat.unread'                   => 'Nouveau message',

    // --- liste des discussions
    'chat.list.title'               => 'Mes conversations',
    'chat.list.new'                 => 'Nouvelle',
    'chat.list.status_local'        => 'Discussion locale · {model}',
    'chat.list.model_auto'          => 'modèle auto',
    'chat.list.no_model'            => 'Aucun modèle de discussion joignable',
    'chat.list.empty_title'         => 'Aucune discussion',
    'chat.list.empty_text'          => 'Choisis un persona et commence à lui parler.',
    'chat.list.pick_persona'        => 'Choisir un persona',
    'chat.list.no_message'          => 'Pas encore de message',
    'chat.list.message_count'       => ['one' => '{n} msg', 'other' => '{n} msg'],
    'chat.list.search_persona'      => 'Chercher un persona…',
    'chat.list.no_persona'          => 'Aucun persona en bibliothèque. Crée une fiche d’abord.',

    // --- conversation : colonne latérale et en-tête
    'chat.side.all'                 => 'Toutes',
    'chat.side.new'                 => 'Nouvelle discussion',
    'chat.head.back'                => 'Toutes les discussions',
    'chat.head.open_persona'        => 'Ouvrir la fiche du personnage',
    'chat.head.model_state'         => 'État du modèle',
    'chat.head.model_default'       => 'modèle par défaut',
    'chat.head.context_memory'      => 'Contexte et mémoire',
    'chat.head.settings'            => 'Contexte et réglages',

    // --- conversation : messages et composeur
    // {count} est remplacé par un <span> (le JavaScript met le nombre à jour) ; {n} choisit la forme
    'chat.intro.first_message'      => 'Écris le premier message.',
    'chat.msg.photo_sent'           => 'Photo envoyée',
    'chat.composer.attach_preview'  => 'Aperçu de la photo jointe',
    'chat.composer.attach_remove'   => 'Retirer la photo',
    'chat.composer.attach'          => 'Joindre une photo',
    'chat.composer.placeholder'     => 'Écris un message…',
    'chat.composer.label'           => 'Message',
    'chat.composer.send'            => 'Envoyer',
    'chat.foot.context'             => 'contexte {used} / {window}',
    'chat.foot.compressions'        => ['one' => '{n} compression', 'other' => '{n} compressions'],
    'chat.foot.no_model'            => 'Aucun modèle de discussion joignable — vérifie LM Studio dans les réglages.',

    // --- panneau « contexte et réglages »
    'chat.sheet.label'              => 'Contexte et réglages de la conversation',
    'chat.sheet.eyebrow'            => 'Conversation',
    'chat.sheet.ctx_percent'        => '{percent} % du contexte',
    'chat.sheet.ctx_tokens'         => '{used} / {window} tokens',
    'chat.sheet.save'               => 'Enregistrer',

    // --- état du serveur local et chargement du modèle
    'chat.lms.checking'             => 'Vérification du serveur…',
    'chat.lms.refresh'              => 'Actualiser l’état',
    'chat.lms.step_server'          => 'Serveur',
    'chat.lms.step_request'         => 'Requête',
    'chat.lms.step_loading'         => 'Chargement',
    'chat.lms.step_ready'           => 'Prêt',
    'chat.lms.timer_zero'           => '0,0 s',

    // --- réglages du personnage (résumé)
    'chat.persona.title'            => 'Réglages du personnage',
    'chat.persona.edit'             => 'Modifier',
    'chat.persona.model_global'     => 'modèle global',
    'chat.persona.temperature'      => 'température {value}',
    'chat.persona.max_tokens'       => '{count} tokens',
    'chat.persona.window'           => 'fenêtre {size}',
    'chat.persona.directive'        => '« {text} »',

    // --- options de la conversation
    'chat.toggles.memory'           => 'Garder la mémoire (résumé glissant)',
    'chat.toggles.followup'         => 'Messages spontanés après un long silence',

    // --- mémoire (résumé glissant)
    'chat.memory.title'             => 'Mémoire',
    'chat.memory.percent'           => '{percent} %',
    'chat.memory.stat_messages'     => 'messages',
    'chat.memory.stat_unsummarized' => 'non résumés',
    'chat.memory.stat_compressions' => 'compressions',
    'chat.memory.stat_tokens'       => 'tokens',
    'chat.memory.summary_label'     => 'Résumé courant',
    'chat.memory.summary_placeholder' => 'Vide pour l’instant : il se remplit automatiquement quand la conversation devient longue.',
    'chat.memory.clear'             => 'Effacer',
    'chat.memory.compress'          => 'Compresser',
    'chat.memory.manage'            => 'Gérer les souvenirs durables',

    // --- souvenirs partagés
    'chat.facts.title'              => 'Souvenirs partagés',
    'chat.facts.manage_all'         => 'Tout gérer',
    'chat.facts.hint'               => 'Valables pour toutes les conversations avec {name}.',
    'chat.facts.use'                => 'Utiliser ces souvenirs dans cette conversation',
    'chat.facts.delete'             => 'Supprimer ce souvenir',
    'chat.facts.empty'              => 'Aucun souvenir pour l’instant.',
    'chat.facts.add_placeholder'    => 'Ajouter un souvenir…',
    'chat.facts.add_label'          => 'Nouveau souvenir',
    'chat.facts.add'                => 'Ajouter',

    // --- recherche dans les conversations du persona
    'chat.recall.title'             => 'Chercher dans ses souvenirs',
    'chat.recall.hint'              => 'Dans toutes les conversations avec {name}.',
    'chat.recall.placeholder'       => 'Ex. le Pradet, son travail…',
    'chat.recall.label'             => 'Rechercher',
    'chat.recall.submit'            => 'Chercher',

    // --- renommer / supprimer
    'chat.rename.label'             => 'Titre de la conversation',
    'chat.rename.submit'            => 'Renommer',
    'chat.delete.confirm'           => 'Supprimer cette conversation et tous ses messages ?',
    'chat.delete.submit'            => 'Supprimer la conversation',

    // --- fenêtre de bascule vers le modèle distant (#fb-modal)
    'chat.fallback.title'           => 'LM Studio ne répond pas',
    'chat.fallback.hint'            => 'Tu peux attendre qu’il revienne — le message sera renvoyé tel quel — ou faire générer la réponse par DeepSeek.',
    'chat.fallback.wait'            => 'Attendre et réessayer',
    'chat.fallback.use'             => 'Utiliser DeepSeek',
];
