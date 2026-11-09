<?php
/*
 * Français — langue de référence. Page Réglages (views/settings.php).
 * Toute clé ajoutée ici doit exister dans lang/en/ et lang/es/ (vérifié par bin/i18n-check.php).
 * Les clés « settings.lang.* » et « settings.look.saved » sont dans common.php.
 * Certains textes sur plusieurs lignes gardent l'indentation de la page (sortie HTML identique).
 */
return [
    // --- en-tête et onglets
    'settings.subtitle'         => 'Connexions, discussions et apparence — un onglet par sujet.',
    'settings.tabs_label'       => 'Sections des réglages',
    'settings.tab.ia_long'      => 'Intelligence',
    'settings.tab.ia_short'     => 'IA',
    'settings.tab.chat_long'    => 'Discussion',
    'settings.tab.chat_short'   => 'Chat',
    'settings.tab.look'         => 'Apparence',
    'settings.tab.prompts'      => 'Prompts',
    'settings.tab.backup'       => 'Sauvegarde',

    // --- éléments communs à plusieurs onglets
    'settings.info_more'        => 'En savoir plus : {label}',
    'settings.dirty'            => 'Modifications non enregistrées',
    'settings.save'             => 'Enregistrer',
    'settings.field.provider'   => 'Fournisseur',
    'settings.field.base_url'   => 'URL de base',
    'settings.field.model'      => 'Modèle',
    'settings.field.model_used' => 'Modèle utilisé',
    'settings.clear_key'        => 'Supprimer la clé enregistrée',
    'settings.model.remote'     => 'Modèle des fiches (distant)',
    'settings.model.chat'       => 'Modèle de discussion (local)',
    'settings.model.remote_not_ready' => 'Le modèle des fiches n’est pas configuré (onglet IA) : le modèle local est utilisé en attendant.',
    'settings.provider.lmstudio' => 'LM Studio (local)',
    'settings.provider.custom'  => 'Autre / compatible OpenAI',
    'settings.provider.other'   => 'Autre',

    // --- onglet IA : création de fiches
    'settings.ia.title'         => 'Création de fiches',
    'settings.ia.subtitle'      => 'Modèle qui remplit les fiches persona (bouton IA).',
    'settings.ia.model_examples' => 'Ex. deepseek-chat, deepseek-reasoner',
    'settings.ia.api_key'       => 'Clé API',
    'settings.ia.api_key_keep'  => 'Laisser vide pour conserver la clé',
    'settings.ia.api_key_saved_help' => 'Une clé est déjà enregistrée. Saisis-en une nouvelle pour la remplacer.',
    'settings.ia.api_key_help'  => 'Stockée dans la base locale de l’app, jamais affichée en clair.',
    'settings.ia.status_ready'  => 'Prêt · {model}',
    'settings.ia.status_no_key' => 'Aucune clé enregistrée',
    'settings.ia.test'          => 'Test de connexion',
    'settings.ia.how_title'     => 'Comment ça marche',
    'settings.ia.how_text_html' => 'Sur une fiche, le bouton <strong>Compléter avec l’IA</strong> lit les prompts du persona et
            remplit uniquement ce qui manque : accroche, description, tags et attributs.
            Ce qui est déjà saisi n’est jamais écrasé, et <strong>tes prompts ne sont jamais modifiés</strong> :
            l’IA ne fait que les lire.',
    'settings.ia.how_privacy'   => 'Rien ne part sans que tu cliques : l’app n’envoie les données au modèle qu’au moment de cette action.',

    // --- onglet Discussion
    'settings.chat.title'       => 'Modèle de chat',
    'settings.chat.subtitle'    => 'Utilisé uniquement pour parler aux personas. Si LM Studio est éteint, l’app bascule sur DeepSeek en te prévenant.',
    'settings.chat.connection_title' => 'Connexion au modèle',
    'settings.chat.model_aria'  => 'Modèle de discussion',
    'settings.chat.models_detected' => [
        'one'   => '{n} modèle détecté · ★ = recommandés pour la discussion.',
        'other' => '{n} modèles détectés · ★ = recommandés pour la discussion.',
    ],
    'settings.chat.model_manual' => 'Serveur injoignable : saisis le nom du modèle à la main.',
    'settings.chat.api_key'     => 'Clé API (facultative)',
    'settings.chat.api_key_saved_placeholder' => 'Clé enregistrée — laisser vide',
    'settings.chat.api_key_placeholder' => 'vide pour LM Studio',
    'settings.chat.memory_title' => 'Réflexion et mémoire',
    'settings.chat.reasoning'   => 'Réflexion du modèle',
    'settings.chat.reasoning_none'   => 'Désactivée (recommandé)',
    'settings.chat.reasoning_low'    => 'Faible',
    'settings.chat.reasoning_medium' => 'Moyenne',
    'settings.chat.reasoning_high'   => 'Élevée',
    'settings.chat.reasoning_help' => 'Les modèles Qwen3 / Gemma 4 « réfléchissent » avant de répondre et peuvent épuiser le budget sans jamais répondre. Désactivée = réponse directe.',
    'settings.chat.threshold'   => 'Seuil de compression (tokens)',
    'settings.chat.threshold_help' => 'Au-delà, les vieux messages sont résumés.',
    'settings.chat.target'      => 'Messages récents gardés (tokens)',
    'settings.chat.target_help' => 'Ce qui reste en clair après compression.',

    // --- onglet Discussion : réponses en plusieurs bulles
    'settings.split.title'      => 'Réponses en plusieurs bulles',
    'settings.split.hint'       => 'Coupe une longue réponse en deux messages, à la fin d’une phrase, avec une petite pause.',
    'settings.split.enable'     => 'Découper les réponses en plusieurs bulles',
    'settings.split.words'      => 'Mots maximum par bulle',
    'settings.split.words_help' => 'La bulle se coupe à la dernière fin de phrase avant cette limite.',
    'settings.split.bubbles'    => 'Bulles maximum',
    'settings.split.bubbles_help' => '1 = jamais de découpage.',
    'settings.split.delay_min'  => 'Pause min',
    'settings.split.delay_max'  => 'Pause max',
    'settings.split.delay_help' => 'Pause aléatoire entre deux bulles, dans cette plage.',

    // --- onglet Discussion : messages spontanés
    'settings.followup.title'   => 'Messages spontanés',
    'settings.followup.hint'    => 'Le persona peut t’écrire de lui-même après un long silence (désactivable conversation par conversation).',
    'settings.followup.enable'  => 'Autoriser les relances spontanées',
    'settings.followup.idle'    => 'Relancer après (heures de silence)',
    'settings.followup.idle_help' => 'Heures de silence avant qu’il/elle puisse relancer.',
    'settings.followup.hours'   => 'Plage horaire',
    'settings.followup.hours_from' => 'de',
    'settings.followup.hours_to'   => 'h à',
    'settings.followup.hours_end'  => 'h',
    'settings.followup.from_aria'  => 'Heure de début',
    'settings.followup.to_aria'    => 'Heure de fin',
    'settings.followup.hours_help' => 'Aucune relance en dehors de cette plage.',

    // --- onglet Discussion : souvenirs durables
    'settings.facts.title'      => 'Souvenirs durables',
    'settings.facts.hint'       => 'Ce que les personas retiennent de toi d’une conversation à l’autre. Choisis quand
                l’app les extrait et avec quel modèle.',
    'settings.facts.frequency'  => 'Fréquence',
    'settings.facts.frequency_aria' => 'Fréquence de l’extraction',
    'settings.facts.freq_each'  => 'Après chaque échange',
    'settings.facts.freq_every' => 'Tous les N messages',
    'settings.facts.freq_explicit' => 'Seulement sur demande (« retiens que… »)',
    'settings.facts.freq_off'   => 'Jamais',
    'settings.facts.every'      => 'Nombre de nouveaux messages',
    'settings.facts.every_help' => 'Un échange = 2 messages (le tien et la réponse).',
    'settings.facts.timing'     => 'Moment',
    'settings.facts.timing_aria' => 'Moment de l’extraction',
    'settings.facts.timing_now' => 'Dès la réponse',
    'settings.facts.timing_idle' => 'Quand la discussion est calme',
    'settings.facts.idle'       => 'Calme depuis (secondes)',
    'settings.facts.idle_help'  => 'Sans nouveau message ni frappe pendant ce délai.',
    'settings.facts.model_aria' => 'Modèle utilisé pour les souvenirs',

    // --- onglet Discussion : titres automatiques
    'settings.titles.title'     => 'Titres des discussions',
    'settings.titles.hint'      => 'Après les premiers échanges, un titre court est donné à la discussion
                (tant que tu ne l’as pas renommée toi-même).',
    'settings.titles.model_aria' => 'Modèle des titres automatiques',
    'settings.titles.off'       => 'Pas de titre automatique',

    // --- onglet Discussion : maintenance LM Studio
    'settings.purge.title'      => 'Maintenance LM Studio',
    'settings.purge.text'       => 'Changer de modèle décharge déjà l’ancien automatiquement. Ce bouton force la libération
            de la mémoire vidéo (VRAM) si LM Studio garde des modèles chargés.',
    'settings.purge.button'     => 'Purger la mémoire LM Studio',

    // --- onglet Apparence
    'settings.look.title'       => 'Apparence des conversations',
    'settings.look.subtitle'    => 'S’applique à toutes les discussions. L’aperçu réagit en direct.',
    'settings.look.preview'     => 'Aperçu',
    'settings.look.preview_bot_html'  => 'Salut&nbsp;! Tu as passé une bonne journée&nbsp;?',
    'settings.look.preview_user_html' => 'Ouais, et toi&nbsp;?',
    'settings.look.tabbar'          => 'Barre de navigation (téléphone)',
    'settings.look.tabbar_float'    => 'Capsule flottante',
    'settings.look.tabbar_pill'     => 'Pilule qui se déplie',
    'settings.look.tabbar_classic'  => 'Classique soignée',
    'settings.look.tabbar_help'     => 'Visible sur téléphone et tablette ; l’aperçu s’applique tout de suite en bas de l’écran.',
    'settings.look.font_size'   => 'Taille du texte',
    'settings.look.font_small'  => 'Petit',
    'settings.look.font_normal' => 'Normal',
    'settings.look.font_large'  => 'Grand',
    'settings.look.font_xlarge' => 'Très grand',
    'settings.look.bubble_style' => 'Style des bulles',
    'settings.look.bubble_round' => 'Bulles arrondies',
    'settings.look.bubble_flat' => 'Plates',
    'settings.look.bubble_card' => 'Cartes',
    'settings.look.density'     => 'Densité',
    'settings.look.density_comfort' => 'Confort',
    'settings.look.density_compact' => 'Compact',
    'settings.look.width'       => 'Largeur des messages',
    'settings.look.width_normal' => 'Normale',
    'settings.look.width_large' => 'Large',
    'settings.look.avatars'     => 'Afficher l’avatar du personnage à côté de ses messages',
    'settings.look.save'        => 'Enregistrer l’apparence',

    // --- onglet Prompts
    'settings.prompts.title'    => 'Prompts internes',
    'settings.prompts.subtitle' => 'Les consignes que l’app envoie au modèle pour la mémoire, les souvenirs et les relances. Vides = texte par défaut.',
    'settings.prompts.memory_label'   => 'Résumé de conversation',
    'settings.prompts.memory_help'    => 'Compresse les anciens messages. Doit renvoyer un JSON avec current_topic, conversation_goal, important_facts, decisions, open_questions, context_summary.',
    'settings.prompts.facts_label'    => 'Extraction des souvenirs',
    'settings.prompts.facts_help'     => 'Décide ce qui devient une mémoire durable sur l’utilisateur (persona). Doit renvoyer un JSON avec memories / forget.',
    'settings.prompts.followup_label' => 'Relance spontanée',
    'settings.prompts.followup_help'  => 'Instruction du message non sollicité. Placeholders disponibles : {persona} et {gap}.',
    'settings.prompts.custom'   => 'personnalisé',
    'settings.prompts.reset'    => 'Réinitialiser',
    'settings.prompts.save'     => 'Enregistrer les prompts',

    // --- onglet Sauvegarde
    'settings.backup.title'     => 'Sauvegarde',
    'settings.backup.subtitle'  => 'Un ZIP par sauvegarde : un dossier par persona avec sa fiche, ses prompts et ses photos.',
    'settings.backup.export'    => 'Exporter',
    'settings.backup.count_personas' => ['one' => '{n} persona', 'other' => '{n} personas'],
    'settings.backup.count_photos'   => ['one' => '{n} photo', 'other' => '{n} photos'],
    'settings.backup.count_prompts'  => ['one' => '{n} prompt', 'other' => '{n} prompts'],
    'settings.backup.count_conversations' => ['one' => '{n} conversation', 'other' => '{n} conversations'],
    'settings.backup.include_conversations' => 'Inclure les conversations, les messages et les souvenirs',
    'settings.backup.download'  => 'Télécharger la sauvegarde',
    'settings.backup.last'      => 'Dernière sauvegarde : {date}',
    'settings.backup.size_mb'   => '{size} Mo',
    'settings.backup.none'      => 'Aucune sauvegarde téléchargée pour l’instant.',
    'settings.backup.restore'   => 'Restaurer',
    'settings.backup.restore_hint' => 'Importe un ZIP de sauvegarde. Les personas existants ne sont jamais écrasés : un slug unique est créé.',
    'settings.backup.import_confirm' => 'Importer cette sauvegarde ? Les personas seront ajoutés à la bibliothèque.',
    'settings.backup.import_confirm_title' => 'Importer la sauvegarde',
    'settings.backup.import_confirm_ok' => 'Importer',
    'settings.backup.choose_file' => 'Choisir un fichier ZIP',
    'settings.backup.import_submit' => 'Importer l’archive',
    'settings.backup.tip'       => 'Astuce : garde le ZIP hors du serveur (disque externe, cloud). Il contient tout ce qu’il faut pour repartir de zéro.',

    // --- bulles d'information (bouton « i ») : libellé du bouton, titre de la fenêtre, contenu
    'settings.info.ia_model_label'    => 'modèle des fiches',
    'settings.info.ia_model_title'    => 'Modèle des fiches',
    'settings.info.ia_model_html'     => '<p>Ce modèle sert aux tâches ponctuelles : <strong>compléter une fiche</strong> avec le bouton IA,
            proposer une consigne de jeu, et éventuellement extraire les souvenirs si tu le choisis.</p>
            <p>C’est en général un service distant (DeepSeek, OpenAI, OpenRouter…). Les données ne partent
            qu’au moment où tu cliques sur une action IA.</p>',
    'settings.info.api_key_label'     => 'clé API',
    'settings.info.api_key_html'      => '<p>La clé est enregistrée dans la base locale de l’app et n’est <strong>jamais réaffichée</strong>
            dans la page. Laisse le champ vide pour conserver la clé actuelle.</p>
            <p>Le bouton « Test de connexion » n’envoie la clé enregistrée qu’à l’adresse enregistrée : si tu
            changes d’adresse, saisis la clé à nouveau.</p>',
    'settings.info.chat_model_label'  => 'modèle de discussion',
    'settings.info.chat_model_html'   => '<p>C’est le modèle qui fait parler les personas, en général <strong>LM Studio sur ta machine</strong>
            (adresse <code>http://localhost:1234/v1</code> par défaut). Rien ne sort de chez toi.</p>
            <p>Si le serveur est injoignable, l’app te demande quoi faire : elle ne bascule jamais toute seule
            vers un service distant.</p>',
    'settings.info.reasoning_label'   => 'réflexion du modèle',
    'settings.info.reasoning_html'    => '<p>Certains modèles (Qwen3, Gemma…) « réfléchissent » avant de répondre. C’est plus lent et ça
            consomme le budget de tokens, parfois au point de ne jamais répondre.</p>
            <p>Pour une conversation naturelle, laisse <strong>Désactivée</strong>.</p>',
    'settings.info.compression_label' => 'mémoire de conversation',
    'settings.info.compression_title' => 'Mémoire de la conversation',
    'settings.info.compression_html'  => '<p>Le modèle ne peut lire qu’un nombre limité de tokens (sa « fenêtre »). Quand les messages
            <strong>pas encore résumés</strong> dépassent le <strong>seuil de compression</strong>, les plus anciens
            sont résumés en quelques lignes (sujet, faits, décisions).</p>
            <p>Ensuite, le modèle reçoit ce résumé + les messages récents (environ la valeur
            « messages récents gardés »), et non plus tout l’historique : réponses plus rapides et
            plus de place pour la suite.</p>
            <p>Un token ≈ 3 à 4 caractères en français.</p>',
    'settings.info.split_label'       => 'bulles',
    'settings.info.split_html'        => '<p>Une longue réponse est coupée à la fin d’une phrase et affichée en plusieurs messages, avec une
            courte pause entre chaque, comme dans une vraie messagerie.</p>
            <p>« Bulles maximum » à 1 désactive le découpage.</p>',
    'settings.info.followup_label'    => 'messages spontanés',
    'settings.info.followup_html'     => '<p>Après un long silence, le persona peut t’écrire de lui-même, uniquement dans la plage horaire
            choisie et au plus une fois par période de silence.</p>
            <p>Ces relances sont déclenchées par une tâche planifiée sur le serveur, par exemple toutes les
            30 minutes : <code>php bin/followup.php</code> (cron). Sans elle, rien ne part.</p>',
    'settings.info.facts_label'       => 'souvenirs durables',
    'settings.info.facts_html'        => '<p>Après certains échanges, l’app demande à un modèle ce qui mérite d’être retenu sur toi
            (prénom, goûts, projets…). Ces souvenirs sont partagés entre toutes les conversations d’un persona
            et visibles dans la page <strong>Souvenirs</strong>.</p>
            <p>L’extraction se fait <strong>en arrière-plan</strong> : elle ne bloque jamais l’affichage.
            En revanche, si elle utilise le modèle local, elle l’occupe quelques secondes.</p>',
    'settings.info.facts_frequency_label' => 'fréquence',
    'settings.info.facts_frequency_html'  => '<p><strong>Après chaque échange</strong> : le plus réactif, mais un appel au modèle à chaque réponse.</p>
            <p><strong>Tous les N messages</strong> : regroupe plusieurs échanges en un seul appel. Rien n’est perdu,
            tous les messages depuis la dernière extraction sont analysés.</p>
            <p><strong>Seulement sur demande</strong> : uniquement quand tu écris « retiens que… », « souviens-toi… ».</p>
            <p><strong>Jamais</strong> : aucun nouveau souvenir automatique (les existants restent utilisés).</p>',
    'settings.info.facts_timing_label' => 'moment de l’extraction',
    'settings.info.facts_timing_html'  => '<p><strong>Dès la réponse</strong> : lancée juste après chaque réponse.</p>
            <p><strong>Quand la discussion est calme</strong> : attend que tu n’aies rien écrit pendant le délai
            choisi. Le modèle local reste ainsi disponible pendant que vous échangez. Si tu quittes la page
            avant, l’extraction se fera à la prochaine occasion, sans perte.</p>',
    'settings.info.facts_model_label' => 'modèle des souvenirs',
    'settings.info.facts_model_html'  => '<p><strong>Modèle de discussion (local)</strong> : tout reste sur ta machine.</p>
            <p><strong>Modèle des fiches (distant)</strong> : soulage le modèle local, mais les derniers messages de
            la conversation sont alors <strong>envoyés au service distant</strong> (DeepSeek, OpenAI…).
            À choisir en connaissance de cause.</p>',
    'settings.info.titles_label'      => 'titres automatiques',
    'settings.info.titles_html'       => '<p><strong>Modèle des fiches (distant)</strong> : les 4 premiers messages de la discussion
            (300 caractères chacun au plus) sont <strong>envoyés au service distant</strong> pour trouver un titre.
            Rapide, et le modèle local reste libre.</p>
            <p><strong>Modèle de discussion (local)</strong> : rien ne sort de ta machine ; le modèle local
            est occupé une ou deux secondes après le premier échange.</p>
            <p><strong>Pas de titre automatique</strong> : la discussion garde « Discussion avec … »
            jusqu’à ce que tu la renommes.</p>',
    'settings.info.purge_label'       => 'purge',
    'settings.info.purge_html'        => '<p>Décharge tous les modèles chargés dans LM Studio pour libérer la mémoire de la carte graphique
            (VRAM). Utile si LM Studio garde plusieurs modèles en mémoire et ralentit.</p>
            <p>Le modèle de discussion sera rechargé automatiquement au prochain message.</p>',
    'settings.info.look_label'        => 'apparence',
    'settings.info.look_html'         => '<p>Ces réglages ne changent que l’affichage des conversations, sur tous tes appareils.
            L’aperçu en haut du formulaire montre le rendu avant d’enregistrer.</p>',
    'settings.info.prompts_label'     => 'prompts internes',
    'settings.info.prompts_html'      => '<p>Ce sont les consignes que l’app envoie au modèle pour <strong>résumer</strong> une conversation,
            <strong>extraire les souvenirs</strong> et écrire les <strong>relances</strong>.</p>
            <p>Les deux premiers doivent faire répondre le modèle en JSON avec les champs indiqués : si tu les
            modifies, garde ce format. « Réinitialiser » remet le texte d’origine.</p>',
    'settings.info.backup_label'      => 'sauvegarde',
    'settings.info.backup_html'       => '<p>Le ZIP contient un dossier par persona : fiche, prompts, portrait et photos. Coche l’option pour
            y ajouter les conversations et les souvenirs.</p>
            <p>À l’import, rien n’est écrasé : un persona déjà présent est recréé sous un nouveau nom d’URL.
            Garde tes sauvegardes hors du serveur.</p>',
];
