<?php
/*
 * Textes affichés par public/assets/app.js (fonction tr(), clé sans le préfixe « js. »).
 * Les clés js.modal.*, js.unsaved.* et js.chat.jump sont dans common.php.
 */
return [
    // commun
    'js.common.error'         => 'Erreur : {message}',
    'js.common.unreadable'    => 'Réponse illisible.',
    'js.common.send_failed'   => 'Échec de l’envoi.',
    'js.common.delete_failed' => 'suppression impossible',
    'js.common.impossible'    => 'impossible',

    // consigne de jeu proposée par l'IA
    'js.directive.generating' => 'Génération…',
    'js.directive.unreadable' => 'réponse illisible',
    'js.directive.failed'     => 'échec de la génération',
    'js.directive.done'       => 'Consigne proposée — relis, ajuste, puis enregistre.',

    // copier un prompt
    'js.copy.copied' => 'Copié',
    'js.copy.toast'  => 'Prompt copié',

    // recherche
    'js.search.free' => 'Rechercher « {q} »',

    // fiche persona
    'js.persona.preview_alt'     => 'Aperçu',
    'js.persona.ai_fill_busy'    => 'Analyse des prompts…',
    'js.persona.ai_fill_failed'  => 'Échec de la complétion.',
    'js.persona.ai_fill_nothing' => 'Rien à compléter.',
    'js.persona.ai_fill_done'    => 'Fiche complétée.',
    'js.persona.photo_adding'    => 'Ajout de l’image…',
    'js.persona.photo_added'     => 'Image ajoutée.',

    // réglages : résumés des sections repliables (éléments séparés par « · »)
    'js.settings.sum_split_off'      => 'Désactivé',
    'js.settings.sum_split'          => '{max} bulles max · {words} mots',
    'js.settings.sum_facts_off'      => 'Désactivés',
    'js.settings.sum_facts_explicit' => 'Sur demande',
    'js.settings.sum_facts_every'    => 'Tous les {n} messages',
    'js.settings.sum_facts_each'     => 'Après chaque échange',
    'js.settings.sum_facts_idle'     => 'au calme',
    'js.settings.sum_facts_remote'   => 'distant',
    'js.settings.sum_facts_local'    => 'local',
    'js.settings.sum_titles_off'     => 'Désactivés',
    'js.settings.sum_titles_local'   => 'Modèle local',
    'js.settings.sum_titles_remote'  => 'Modèle distant',
    'js.settings.sum_followup'       => 'Après {idle} h de silence · de {from} h à {to} h',
    'js.settings.sum_followup_off'   => 'Désactivées',

    // réglages : test du modèle, purge de LM Studio
    'js.settings.test_running'    => 'Test en cours…',
    'js.settings.test_connecting' => 'Connexion au modèle…',
    'js.settings.test_failed'     => 'Échec du test.',
    'js.settings.test_ok'         => 'Connecté · {model} · {ms} ms',
    'js.settings.purging'         => 'Purge…',
    'js.settings.purge_failed'    => 'Échec de la purge.',
    'js.settings.purge_unloaded'  => ['one' => '{n} modèle déchargé : {list}', 'other' => '{n} modèles déchargés : {list}'],
    'js.settings.purge_none'      => 'Aucun modèle chargé en mémoire — rien à purger.',
    'js.settings.purge_done'      => 'Mémoire purgée ({n})',
    'js.settings.purge_nothing'   => 'Rien à purger',

    // chat
    'js.chat.load_failed'         => 'chargement impossible',
    'js.chat.photo_alt'           => 'Photo envoyée',
    'js.chat.percent'             => '{n} %',
    'js.chat.ctx_percent'         => '{n} % du contexte',
    'js.chat.ctx_tokens'          => '{used} / {window} tokens',
    'js.chat.compressing'         => 'Compression…',
    'js.chat.compressed'          => ['one' => '{n} message résumé', 'other' => '{n} messages résumés'],
    'js.chat.nothing_to_compress' => 'Rien à résumer',
    'js.chat.compress_failed'     => 'Compression impossible',
    'js.chat.memory_updated'      => [
        'one'   => 'Mémoire mise à jour — {n} message résumé.',
        'other' => 'Mémoire mise à jour — {n} messages résumés.',
    ],
    'js.chat.memory_phase'        => 'Mise à jour de la mémoire…',
    'js.chat.fb_not_loaded'       => 'Le modèle n’est pas chargé',
    'js.chat.fb_no_reply'         => 'LM Studio n’a pas répondu',
    'js.chat.fb_kept'             => 'Message gardé dans le champ — renvoie-le quand LM Studio est prêt.',
    'js.chat.lms_no_answer'       => 'LM Studio ne répond pas sur {url}.',
    'js.chat.configured_address'  => 'l’adresse configurée',
    'js.chat.regen'               => 'Régénérer la réponse',
    'js.chat.model_hint_on' => 'Modèle de test, pour cette conversation seulement. Le persona garde le sien.',
    'js.chat.model_default' => 'modèle par défaut',
    'js.prompt.chars' => ['one' => '{n} caractère', 'other' => '{n} caractères'],
    'js.prompt.modified' => 'modifié',
    'js.chat.versions_label' => 'Versions de la réponse',
    'js.chat.versions_count' => '{index} / {total}',
    'js.chat.version_prev' => 'Version précédente',
    'js.chat.version_next' => 'Version suivante',
    'js.chat.version_error' => 'Impossible de changer de version.',
    'js.chat.edit'                => 'Modifier ce message',
    'js.chat.delete'              => 'Supprimer ce message',
    'js.chat.save'                => 'Enregistrer',
    'js.chat.erase_error'         => 'Effacement impossible : {message}',
    'js.chat.empty_message'       => 'Le message ne peut pas être vide.',
    'js.chat.edit_failed'         => 'modification impossible',
    'js.chat.edited_replay'       => 'Message modifié, nouvelle réponse…',
    'js.chat.edited'              => 'Message modifié.',
    'js.chat.edit_error'          => 'Modification impossible : {message}',
    'js.chat.stop_label'          => 'Arrêter la génération',
    'js.chat.stop'                => 'Arrêter',
    'js.chat.send'                => 'Envoyer',
    'js.chat.choose_image'        => 'Choisis une image (jpg, png, webp).',
    'js.chat.interrupted'         => 'Réponse interrompue : {message}',
    'js.chat.empty_reply'         => 'Réponse vide.',
    'js.chat.aborted'             => 'Génération interrompue.',

    // état de LM Studio et chargement du modèle
    'js.lms.down'                 => 'LM Studio n’est pas lancé',
    'js.lms.down_url'             => 'LM Studio n’est pas lancé ({url})',
    'js.lms.down_no_answer'       => 'LM Studio n’est pas lancé — aucune réponse sur {url}',
    'js.lms.loaded'               => 'Modèle chargé : {model}',
    'js.lms.loaded_ctx'           => 'Modèle chargé : {model} · {n} tokens',
    'js.lms.not_loaded_title'     => '{model} n’est pas chargé (se charge au premier message)',
    'js.lms.not_loaded'           => '{model} n’est pas chargé — il se charge au premier message',
    'js.lms.loading'              => 'Chargement du modèle…',
    'js.lms.seconds'              => '{n} s',
    'js.lms.load_now'             => 'Charger le modèle maintenant',
    'js.lms.unreachable'          => 'LM Studio injoignable',
    'js.lms.already_loaded_timer' => 'déjà chargé',
    'js.lms.already_loaded'       => 'Déjà chargé',
    'js.lms.request_failed'       => 'Requête impossible.',
    'js.lms.load_failed'          => 'Échec du chargement',
    'js.lms.load_error'           => 'Chargement impossible : {message}',
    'js.lms.unknown_error'        => 'erreur inconnue',
    'js.lms.loaded_short'         => 'Modèle chargé',
    'js.lms.too_long'             => 'Chargement trop long',

    // souvenirs partagés et recherche dans les autres conversations
    'js.facts.empty'     => 'Aucun souvenir pour l’instant.',
    'js.facts.delete'    => 'Supprimer ce souvenir',
    'js.facts.add_failed' => 'ajout impossible',
    'js.facts.added'     => 'Souvenir ajouté — partagé avec toutes les conversations de ce persona.',
    'js.facts.added_one' => 'Souvenir ajouté : {fact}',
    'js.facts.enabled'   => 'Souvenirs activés pour cette conversation.',
    'js.facts.disabled'  => 'Souvenirs désactivés — ils restent en mémoire pour les autres conversations.',
    'js.recall.memorize'  => 'Mémoriser',
    'js.recall.memorized' => 'Ajouté aux souvenirs partagés.',
    'js.recall.searching' => 'Recherche…',
    'js.recall.none'      => 'Rien trouvé dans les autres conversations.',
    'js.recall.failed'    => 'Recherche impossible.',
    // partagés avec les vues (affichage initial côté serveur, mise à jour côté JS)
    'js.chat.load_older'               => ['one' => 'Charger {n} message plus ancien', 'other' => 'Charger {n} messages plus anciens'],
    'js.chat.ctx_auto_summary'   => [
        'one'   => 'Résumé auto au-delà de {threshold} tokens non résumés · {n} fait',
        'other' => 'Résumé auto au-delà de {threshold} tokens non résumés · {n} faits',
    ],
    // date et heure affichées sous un message touché ({date} : « Aujourd’hui », « 8 nov. »…)
    'js.chat.msg_time' => '{date} à {time}',
    // titres du panneau de la conversation (anneau / roue)
    'js.chat.sheet_memory' => 'Contexte et mémoire',
    'js.chat.sheet_conversation' => 'Conversation',
    // interrupteurs de la conversation enregistrés au changement
    'js.chat.toggle_saved' => 'Enregistré',
    'js.chat.toggle_failed' => 'Impossible d’enregistrer, réessaie.',
    // curseur à deux poignées des pauses (aussi utilisé côté serveur)
    'js.settings.pause_range' => '{min} s – {max} s',
];
