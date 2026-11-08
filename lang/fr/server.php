<?php
/*
 * Français — langue de référence. Textes produits côté serveur (src/*.php, src/routes/) :
 * messages flash, erreurs (JSON, exceptions montrées à l'utilisateur), dates, libellés.
 * Les prompts et consignes envoyés aux modèles de langage restent dans le code, en français :
 * ils ne dépendent pas de la langue de l'interface.
 * Paramètres entre accolades : {name}. Pluriels : ['one' => …, 'other' => …] avec {n}.
 */
return [
    // --- messages flash
    'flash.backup.export_failed'  => 'Sauvegarde impossible : {error}',
    'flash.backup.imported'       => 'Import terminé : {personas}, {photos}, {prompts}.',
    'flash.backup.import_failed'  => 'Import impossible : {error}',
    'flash.chat.pick_persona'     => 'Choisis un persona pour démarrer la discussion.',
    'flash.chat.toggles_saved'    => 'Réglages de la conversation enregistrés.',
    'flash.chat.deleted'          => 'Conversation supprimée.',
    'flash.chat.memory_cleared'   => 'Mémoire effacée : la prochaine compression repartira de zéro.',
    'flash.chat.summary_saved'    => 'Résumé enregistré.',
    'flash.memory.empty'          => 'Le souvenir est vide.',
    'flash.memory.added'          => 'Souvenir ajouté.',
    'flash.memory.deleted'        => 'Souvenir supprimé.',
    'flash.memory.updated'        => 'Souvenir mis à jour.',
    'flash.persona.name_required' => 'Il faut au moins un nom.',
    'flash.persona.name_empty'    => 'Le nom ne peut pas être vide.',
    'flash.persona.avatar_error'  => 'Avatar : {error}',
    'flash.persona.created'       => '{name} rejoint la bibliothèque.',
    'flash.persona.updated'       => 'Fiche mise à jour.',
    'flash.persona.deleted'       => '{name} a été supprimé.',
    'flash.persona.restored'      => 'Persona restauré.',
    'flash.persona.archived'      => 'Persona archivé.',
    'flash.persona.photos_added'  => ['one' => '{n} photo ajoutée.', 'other' => '{n} photos ajoutées.'],
    'flash.persona.no_photo'      => 'Aucune photo ajoutée.',
    'flash.prompt.empty'          => 'Le prompt est vide.',
    'flash.prompt.saved'          => 'Prompt enregistré.',
    'flash.settings.prompts_saved' => 'Prompts enregistrés.',
    'flash.settings.model_missing' => 'Il manque le nom du modèle.',
    'flash.settings.saved'        => 'Réglages enregistrés.',
    'flash.settings.chat_saved'   => 'Réglages de discussion enregistrés.',
    'flash.settings.server_purged' => 'Mémoire du serveur purgée ({models}).',

    // --- erreurs générales
    'error.internal'              => 'Erreur interne (détail dans le journal du serveur).',
    'error.internal_short'        => 'erreur interne',
    'error.internal_logged'       => 'Erreur interne. Le détail a été écrit dans le journal du serveur.',
    'error.csrf.expired_page'     => 'Session expirée, merci de recharger la page.',
    'error.csrf.expired'          => 'Session expirée, recharge la page.',

    // --- erreurs : envoi de fichiers et images
    'error.upload.post_too_large' => 'Envoi trop volumineux : le serveur accepte au plus {size} par envoi (réglage PHP post_max_size).',
    'error.upload.ini_size'       => 'Fichier trop lourd : le serveur accepte au plus {size} par fichier (réglage PHP upload_max_filesize).',
    'error.upload.form_size'      => 'Fichier trop lourd pour ce formulaire.',
    'error.upload.partial'        => 'Envoi interrompu, réessaie.',
    'error.upload.no_file'        => 'Aucun fichier reçu.',
    'error.upload.cant_write'     => 'Erreur serveur : impossible d’écrire le fichier temporaire.',
    'error.upload.extension'      => 'Envoi bloqué par une extension PHP.',
    'error.upload.unknown'        => 'Envoi impossible (code {code}).',
    'error.upload.no_image'       => 'Aucune image reçue.',
    'error.image.invalid'         => 'Ce fichier n’est pas une image valide.',
    'error.image.format'          => 'Format accepté : JPG, PNG, WEBP ou GIF.',
    'error.image.too_big'         => 'Image trop grande ({w}×{h} px, {max} Mpx max).',
    'error.image.unreadable'      => 'Image illisible.',
    'error.image.too_heavy'       => 'Image trop lourde ({max} max).',
    'error.image.refused'         => 'Image refusée : {error}',

    // --- erreurs : réglages, souvenirs, messages
    'error.settings.url_scheme'     => 'L’URL doit commencer par http:// ou https://',
    'error.settings.test_other_url' => 'Adresse différente de celle enregistrée : saisis la clé API pour la tester.',
    'error.memory.empty'          => 'Souvenir vide.',
    'error.memory.not_found'      => 'Souvenir introuvable.',
    'error.memory.other_persona'  => 'Ce souvenir appartient à un autre persona.',
    'error.message.empty'         => 'Le message ne peut pas être vide.',
    'error.message.not_found'     => 'Message introuvable.',
    'error.message.not_yours'     => 'Seuls tes propres messages sont modifiables.',
    'error.message.empty_send'    => 'Message vide.',
    'error.message.nothing_to_regen' => 'Aucun message à régénérer.',

    // --- erreurs : modèles (LM Studio, modèle distant)
    'error.lms.down'              => 'LM Studio ne répond pas : le serveur n’est pas lancé (ou l’adresse est fausse).',
    'error.lms.not_downloaded'    => 'Ce modèle n’est pas téléchargé dans LM Studio.',
    'error.lms.load_failed'       => 'Impossible de lancer le chargement.',
    'error.chat.no_fallback'      => 'Aucun modèle de secours configuré (réglages → IA).',
    'error.chat.no_model'         => 'Aucun modèle de discussion sélectionné.',
    'error.chat.lms_down'         => 'LM Studio ne répond pas : le serveur n’est pas lancé ou l’adresse est fausse.',
    'error.ai.configure_settings' => 'Configure d’abord le LLM (Réglages → Intelligence).',
    'error.ai.configure_gear'     => 'Configure d’abord le LLM (roue crantée → Réglages).',
    'error.ai.need_prompt'        => 'Ajoute au moins un prompt : c’est la matière première de l’IA.',
    'error.ai.no_system_prompt'   => 'Aucun prompt système à analyser pour ce persona.',
    'error.ai.no_model'           => 'Aucun modèle configuré dans les réglages IA.',
    'error.ai.empty_reply'        => 'Réponse du modèle vide.',
    'error.llm.no_model'          => 'Modèle non configuré.',
    'error.llm.connect'           => 'Connexion impossible : {error}',
    'error.llm.api'               => 'Erreur API ({code}) : {message}',
    'error.llm.model_error'       => 'Erreur du modèle : {message}',
    'error.llm.empty'             => 'Réponse vide du modèle.',
    'error.llm.no_key'            => 'Aucune clé API enregistrée.',
    'error.llm.no_json'           => 'Le modèle n’a pas renvoyé de JSON exploitable.',
    'error.llm.bad_json'          => 'JSON invalide renvoyé par le modèle.',

    // --- erreurs : sauvegarde (export / import ZIP)
    'error.backup.create'         => 'Impossible de créer l’archive.',
    'error.backup.entry_too_big_size' => 'entrée trop volumineuse ({size})',
    'error.backup.entry_unreadable' => 'entrée illisible',
    'error.backup.entry_too_big'  => 'entrée trop volumineuse',
    'error.backup.unreadable'     => 'Archive illisible.',
    'error.backup.too_many_files' => 'Archive refusée : trop de fichiers ({count}, {max} max).',
    'error.backup.too_large'      => 'Archive refusée : {size} une fois décompressée ({max} max).',
    'error.backup.not_a_backup'   => 'Ce fichier n’est pas une sauvegarde PersonaVault (manifest.json manquant).',
    'error.backup.item'           => '{item} : {error}',
    'error.backup.invalid_persona' => 'fiche invalide',
    'error.backup.avatar_skipped' => 'portrait ignoré ({error})',
    'error.backup.photo_skipped'  => '{file} ignorée ({error})',

    // --- dates (fr_date) : mois abrégés, puis ordre jour / mois / année
    'date.month_short.1'          => 'janv.',
    'date.month_short.2'          => 'févr.',
    'date.month_short.3'          => 'mars',
    'date.month_short.4'          => 'avr.',
    'date.month_short.5'          => 'mai',
    'date.month_short.6'          => 'juin',
    'date.month_short.7'          => 'juil.',
    'date.month_short.8'          => 'août',
    'date.month_short.9'          => 'sept.',
    'date.month_short.10'         => 'oct.',
    'date.month_short.11'         => 'nov.',
    'date.month_short.12'         => 'déc.',
    'date.day_month_format'       => 'd/m', // format PHP date() : jour/mois
    'date.short'                  => '{day} {month} {year}',

    // --- nombres et tailles
    'labels.number.decimal'       => ',',
    'labels.number.thousands'     => ' ',
    'labels.bytes.mb'             => '{n} Mo',
    'labels.bytes.kb'             => '{n} Ko',
    'labels.bytes.b'              => '{n} o',
    'labels.list_separator'       => ', ',

    // --- compteurs (pluriels)
    'labels.count.personas'       => ['one' => '{n} persona', 'other' => '{n} personas'],
    'labels.count.photos'         => ['one' => '{n} photo', 'other' => '{n} photos'],
    'labels.count.prompts'        => ['one' => '{n} prompt', 'other' => '{n} prompts'],
    'labels.count.compressions'   => ['one' => '{n} compression', 'other' => '{n} compressions'],
    'labels.count.models_unloaded' => ['one' => '{n} modèle déchargé', 'other' => '{n} modèles déchargés'],

    // --- libellés renvoyés par des fonctions et affichés dans les vues
    'labels.prompt_kind.image'    => 'Image',
    'labels.prompt_kind.negative' => 'Négatif',
    'labels.prompt_kind.video'    => 'Vidéo',
    'labels.prompt_kind.system'   => 'Système',
    'labels.prompt_kind.other'    => 'Autre',
    'labels.fact_category.preference' => 'Préférence',
    'labels.fact_category.fait'   => 'Fait',
    'labels.fact_category.projet' => 'Projet',
    'labels.fact_category.contrainte' => 'Contrainte',
    'labels.fact_category.decision' => 'Décision',
    'labels.fact_confidence.confirme' => 'Confirmé',
    'labels.fact_confidence.hypothese' => 'Hypothèse',
    'labels.fact_confidence.temporaire' => 'Temporaire',
    'labels.ring.title'           => 'Contexte {used} / {window} tokens ({pct} %) · résumé {summary} tokens · {compressions} · résumé auto au-delà de {threshold} tokens non résumés',

    // --- titres de page
    'labels.title.memories'       => 'Souvenirs',
    'labels.title.edit_persona'   => 'Modifier {name}',

    // --- discussion : textes enregistrés en base (titre par défaut, notices)
    'labels.chat.default_title'   => 'Discussion avec {name}',
    'labels.chat.memory_updated'  => 'Mémoire mise à jour : {facts}',
    'labels.chat.fallback_notice' => 'Réponse générée par {model} ({provider}), LM Studio non utilisé.',

    // --- mémoire : raisons d'une compression non faite (affichées dans une bulle)
    'labels.compress.disabled'    => 'mémoire désactivée',
    'labels.compress.nothing'     => 'rien à résumer',
    'labels.compress.below_threshold' => 'sous le seuil',
    'labels.compress.unreadable'  => 'réponse du modèle illisible ({excerpt})',
    'labels.compress.empty'       => 'résumé vide, mémoire précédente conservée',
    'labels.compress.write_failed' => 'écriture impossible : {error}',

    // --- souvenirs durables : raison d'une extraction reportée
    'labels.facts_skip.disabled_here' => 'souvenirs désactivés pour cette conversation',
    'labels.facts_skip.no_new'    => 'pas de nouveau message',
    'labels.facts_skip.off'       => 'extraction automatique désactivée dans les réglages',
    'labels.facts_skip.explicit_only' => 'extraction seulement sur demande (« retiens que… »)',
    'labels.facts_skip.waiting'   => 'en attente ({count}/{every} messages)',
    'labels.facts_skip.not_enough' => 'pas assez de nouveaux messages',

    // --- complétion de fiche par l'IA
    'labels.ai_field.tagline'     => 'accroche',
    'labels.ai_field.description' => 'description',
    'labels.ai_field.tags'        => 'tags',
    'labels.ai_field.meta'        => 'attributs',
    'labels.ai.nothing_to_fill'   => 'Rien à compléter : la fiche est déjà bien renseignée.',
    'labels.ai.filled'            => 'Complété : {fields}.',

    // --- README.txt de l'archive de sauvegarde
    'labels.backup_readme.title'  => 'PersonaVault — sauvegarde',
    'labels.backup_readme.date_format' => 'd/m/Y H:i',
    'labels.backup_readme.exported' => 'Exportée le {date}',
    'labels.backup_readme.counts' => 'Personas : {personas} · photos : {photos} · prompts : {prompts}',
    'labels.backup_readme.conversations' => 'conversations : {conversations} ({messages} messages)',
    'labels.backup_readme.structure' => 'Structure',
    'labels.backup_readme.persona_json' => 'fiche complète (nom, accroche, description, tags, attributs,',
    'labels.backup_readme.persona_json_2' => 'réglages IA, consigne de jeu)',
    'labels.backup_readme.prompts_json' => 'prompts (type, titre, texte)',
    'labels.backup_readme.photos' => 'galerie',
    'labels.backup_readme.avatar' => 'portrait',
    'labels.backup_readme.conversations_json' => 'discussions, messages et souvenirs',
    'labels.backup_readme.restore' => 'Restauration',
    'labels.backup_readme.restore_how' => 'Réglages → Sauvegarde → Restaurer, en choisissant ce fichier ZIP.',
    'labels.backup_readme.restore_slug' => 'Les personas importés reçoivent un slug unique si le leur existe déjà :',
    'labels.backup_readme.restore_safe' => 'rien n\'est écrasé.',
];
