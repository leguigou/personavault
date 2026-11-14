<?php
/*
 * Français — langue de référence. Discussions : liste (views/chat_list.php) et conversation (views/chat.php).
 * Toute clé ajoutée ici doit exister dans lang/en/chat.php et lang/es/chat.php (vérifié par bin/i18n-check.php).
 */
return [
    'chat.list.retitle' => 'Réécrire le titre',
    'chat.emoji.like' => 'D’accord',
    'chat.emoji.heart' => 'Affection',
    'chat.emoji.laugh' => 'Ça me fait rire',
    'chat.emoji.hug' => 'Un câlin',
    'chat.emoji.dislike' => 'Pas d’accord',
    'chat.emoji.angry' => 'En colère',
    'chat.emoji.tender' => 'Tendresse',
    'chat.emoji.kiss' => 'Un bisou',
    'chat.emoji.love' => 'Amoureux ou admiratif',
    'chat.emoji.celebrate' => 'À célébrer',
    'chat.emoji.smile' => 'Sourire',
    'chat.emoji.surprise' => 'Surprise',
    'chat.emoji.sad' => 'Tristesse',

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
    'chat.list.pinned' => 'Épinglées',
    'chat.list.recent' => 'Récentes',
    'chat.list.photo' => '📷 Photo',
    'chat.list.you' => 'Vous :',
    'chat.list.new_badge' => 'Nouveau',
    'chat.list.pin' => 'Épingler en haut',
    'chat.list.unpin' => 'Retirer des épinglées',
    'chat.list.message_count' => ['one' => '{n} msg', 'other' => '{n} msg'],
    'chat.list.actions' => 'Actions sur la conversation',
    'chat.list.more' => 'Plus d\'actions',
    'chat.list.delete' => 'Supprimer la conversation',
    'chat.list.delete_confirm' => 'Supprimer « {title} » ? Les messages seront effacés définitivement (les souvenirs du persona sont gardés).',
    'chat.list.long_press_hint' => 'Astuce : reste appuyé sur une conversation pour l\'épingler ou la supprimer.',
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
    'chat.head.settings'            => 'Réglages de la conversation',

    // --- conversation : messages et composeur
    // {count} est remplacé par un <span> (le JavaScript met le nombre à jour) ; {n} choisit la forme
    'chat.intro.first_message'      => 'Écris le premier message.',
    'chat.intro.starters_label' => 'Idées pour commencer',
    'chat.intro.starters_more' => 'Autres idées',
    'chat.intro.starters' => 'Salut {name} ! Tu fais quoi de beau aujourd’hui ?|Qu’est-ce qui te met de bonne humeur en ce moment ?|Si tu pouvais m’emmener dans ton endroit préféré, on irait où ?'
        . '|Salut {name} ! Qu’est-ce qui t’a fait sourire aujourd’hui ?|Tu as une petite habitude à laquelle tu tiens ?|Qu’est-ce que tu aimerais que je sache de toi ?'
        . '|Salut {name} ! Tu as une anecdote à me raconter ?|À quoi ressemble une journée idéale pour toi ?|Qu’est-ce qui te passionne au point d’en oublier l’heure ?'
        . '|Salut {name} ! À quoi tu pensais juste avant que j’arrive ?|Si on avait une heure devant nous, tu proposerais quoi ?|Il y a quelque chose que tu rêves d’essayer un jour ?',
    'chat.msg.photo_sent'           => 'Photo envoyée',
    'chat.composer.attach_preview'  => 'Aperçu de la photo jointe',
    'chat.composer.attach_remove'   => 'Retirer la photo',
    'chat.composer.attach'          => 'Joindre une photo',
    'chat.composer.placeholder'     => 'Écris un message…',
    'chat.composer.label'           => 'Message',
    'chat.composer.send'            => 'Envoyer',
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
    'chat.lms.step_purge' => 'Mémoire',
    'chat.lms.step_request'         => 'Requête',
    'chat.lms.step_loading'         => 'Chargement',
    'chat.lms.step_ready'           => 'Prêt',
    'chat.lms.timer_zero'           => '0,0 s',

    // --- réglages du personnage (résumé)
    'chat.persona.title'            => 'Réglages du personnage',
    'chat.persona.edit'             => 'Modifier',
    'chat.persona.model_global'     => 'modèle global',
    'chat.model.label' => 'Modèle de cette conversation',
    'chat.model.persona_default' => 'Celui du persona · {model}',
    'chat.model.reset' => 'Revenir au persona',
    'chat.model.vision_legend' => '👁 = lit aussi les images (photo jointe possible).',
    'chat.lms.error_reset' => 'Revenir au modèle du persona',
    'chat.lms.error_detail' => 'Message de LM Studio :',
    'chat.model.hint_off' => 'Pour essayer un autre modèle ici seulement : le persona garde le sien.',
    'chat.model.hint_on' => 'Modèle de test, pour cette conversation seulement. Le persona garde le sien.',
    'chat.model.test_badge' => 'Modèle choisi pour cette conversation seulement',
    'chat.persona.temperature'      => 'température {value}',
    'chat.persona.max_tokens'       => '{count} tokens',
    'chat.persona.window'           => 'fenêtre {size}',
    'chat.persona.directive'        => '« {text} »',

    // --- options de la conversation
    'chat.toggles.memory'           => 'Garder la mémoire (résumé glissant)',
    'chat.toggles.pinned' => 'Épingler en haut de la liste',
    'chat.toggles.followup_settings' => 'Régler le délai et les horaires des relances',
    'chat.toggles.followup_global_off' => 'Les relances spontanées sont désactivées dans les Réglages : cet interrupteur n\'a pas d\'effet pour l\'instant.',
    'chat.toggles.followup_enable' => 'Les activer',
    'chat.toggles.followup_no_cron' => 'Le planificateur des relances n\'a encore jamais tourné sur ce serveur : aucun message spontané ne partira.',
    'chat.toggles.followup_how' => 'Comment l\'activer',
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
    // consigne propre à la conversation
    'chat.directive.label'       => 'Consigne pour cette conversation',
    'chat.directive.placeholder' => 'Ex. Nous sommes en vacances à Lisbonne, elle me fait visiter la ville.',
    'chat.directive.help'        => 'S’ajoute à la consigne du personnage, uniquement dans cette conversation.',
    'chat.directive.save'        => 'Enregistrer la consigne',
];
