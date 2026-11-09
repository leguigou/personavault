<?php
/*
 * Français — langue de référence. Textes communs : menus, connexion, 404, modales.
 * Toute clé ajoutée dans lang/fr/ doit exister dans les autres langues (vérifié par bin/i18n-check.php).
 * Paramètres entre accolades : {name}. Pluriels : ['one' => …, 'other' => …] avec {n}.
 * Les clés « js.* » sont aussi disponibles dans le JavaScript (fonction tr()).
 */
return [
    '_name' => 'Français',

    // --- général
    'app.default_title'   => 'Personas',
    'common.close'        => 'Fermer',
    'lightbox.label' => 'Visionneuse de photos',
    'lightbox.download' => 'Télécharger la photo',
    'lightbox.prev' => 'Photo précédente',
    'lightbox.next' => 'Photo suivante',
    'notfound.title'      => 'Introuvable',
    'notfound.text'       => 'Cette page n’existe pas (ou plus).',
    'notfound.back'       => 'Retour à la bibliothèque',

    // --- navigation
    'nav.main'            => 'Navigation principale',
    'nav.mobile'          => 'Navigation mobile',
    'nav.library'         => 'Bibliothèque',
    'nav.chats'           => 'Discussions',
    'nav.favorites'       => 'Favoris',
    'nav.archives'        => 'Archives',
    'nav.new'             => 'Nouveau',
    'nav.new_persona'     => 'Nouveau persona',
    'nav.settings'        => 'Réglages',
    'nav.logout'          => 'Se déconnecter',
    'logout.title'        => 'Déconnexion',
    'logout.confirm'      => 'Se déconnecter de PersonaVault ?',

    // --- connexion
    'login.title'         => 'Connexion',
    'login.tagline'       => 'Ta bibliothèque de personas, photos et prompts.',
    'login.no_password'   => 'Aucun mot de passe configuré : copie {example} en {local} et renseigne ton hash.',
    'login.password'      => 'Mot de passe',
    'login.submit'        => 'Entrer',
    'login.wrong'         => 'Mot de passe incorrect.',
    'login.blocked'       => 'Trop de tentatives. Réessaie dans {n} min.',
    'login.now_blocked'   => 'Trop de tentatives : réessaie dans {n} min.',

    // --- réglages : langue
    'settings.lang.label' => 'Langue de l’interface',
    'settings.lang.auto'  => 'Automatique (langue du navigateur)',
    'settings.look.saved' => 'Apparence enregistrée.',

    // --- JavaScript : fenêtres modales
    'js.modal.close'          => 'Fermer',
    'js.modal.ok'             => 'OK',
    'js.modal.info'           => 'Information',
    'js.modal.confirm_title'  => 'Confirmer',
    'js.modal.confirm'        => 'Confirmer',
    'js.modal.cancel'         => 'Annuler',
    'js.modal.got_it'         => 'Compris',
    'js.modal.delete_title'   => 'Supprimer ?',
    'js.modal.delete'         => 'Supprimer',
    'js.unsaved.title'        => 'Modifications non enregistrées',
    'js.unsaved.text'         => 'Tu as modifié des réglages sans les enregistrer. Les quitter maintenant les annule.',
    'js.unsaved.stay'         => 'Rester sur la page',
    'js.unsaved.leave'        => 'Quitter sans enregistrer',
    'js.chat.jump'            => 'Aller au dernier message',
];
