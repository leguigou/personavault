# PersonaVault

**Bibliothèque de personnages + chat.** Crée des fiches de personas (portrait, galerie, prompts),
puis discute avec elles dans une interface qui imite une messagerie — avec mémoire, souvenirs
partagés et réponses en plusieurs bulles.

PHP 8 + SQLite, **sans framework ni build**. Un seul fichier de config, une base, des images.

---

## Ce qu'il y a dedans

### Bibliothèque
- Fiches de personnages : nom, accroche, description, tags, attributs libres.
- Portrait + galerie photos (conversion WebP automatique, miniatures, orientation EXIF).
- Prompts rangeables par type : système, image, négatif, vidéo, autre.
- Recherche plein texte, filtres par tags, vue grille ou liste, favoris.
- Complétion assistée d'une fiche par un LLM : il ne remplit **que les champs vides**
  et **ne touche jamais aux prompts**.

### Discussion
- Streaming mot à mot (SSE), indicateur de saisie, réponses découpées en **1 à 8 bulles**
  coupées sur une fin de phrase, avec une pause de 1 à 2 s entre chaque.
- État du modèle en direct : vert = chargé, rouge = absent, bleu = en chargement.
- Chargement du modèle à la demande avec progression ; aucune bascule silencieuse vers le
  fournisseur de secours (une fenêtre demande quoi faire).
- Contexte temporel : heure, jour, temps écoulé depuis le dernier message.
- Mémoire glissante : quand les messages pas encore résumés dépassent le seuil, les plus anciens sont
  résumés ; le modèle reçoit ensuite le résumé + les messages récents seulement (voir
  [Mémoire et contexte](#mémoire-et-contexte)).
- Souvenirs persistants par personnage (recherche FTS5), partagés entre toutes ses discussions,
  avec une trace visible « mémoire mise à jour » sous l'échange. Fréquence, moment et modèle
  d'extraction réglables.
- Relance spontanée : le personnage peut écrire de lui-même après plusieurs heures de silence
  (nécessite une tâche planifiée, voir plus bas).
- Photos : quand le modèle sait voir les images, un trombone apparaît pour en envoyer une.
- Édition : modifier son message efface la suite et rejoue la réponse depuis ce point.
- Régénérer garde les réponses précédentes : flèches ‹ 2 / 3 › sous la dernière réponse pour
  passer de l'une à l'autre (la version affichée est celle que le modèle voit ensuite).
- Liste des discussions façon messagerie : heure relative, « Vous : » devant son dernier message,
  badge « Nouveau » après une relance, conversations **épinglées** en tête (punaise sur la carte
  ou interrupteur dans les réglages de la discussion).
- Titres de discussion générés automatiquement.
- Export d'une conversation (réglages de la discussion › Exporter) : texte brut, Markdown
  ou page imprimable (`/chat/{id}/print`) à « Enregistrer en PDF » depuis le navigateur.
- Réglages par personnage : modèle, température, longueur, fenêtre de contexte, consigne.

### Sauvegarde
- Export ZIP : un dossier par personnage (`persona.json`, `prompts.json`, photos).
- Import : recrée les personnages sans rien écraser, avec ré-encodage des images.
- Export optionnel des conversations.

### Fiches (export / import d'un personnage)
- **Exporter** (bouton sur la fiche) : archive PersonaVault d'une seule fiche (`.zip`, même format
  que la sauvegarde, réimportable sans perte), ou **Character Card V2** (format ouvert `chara_card_v2`)
  en `.json` ou en `.png` (le portrait, avec la fiche en base64 dans un bloc tEXt `chara`). Sans
  portrait, le PNG est une image aux initiales générée.
- **Importer** (tuile « Importer une fiche » de la bibliothèque, à côté de « Nouveau persona », ou en
  haut du formulaire de création) : archive
  PersonaVault (une fiche ou une sauvegarde complète), Character Card JSON (V1 sans `spec`, V2,
  V3 `chara_card_v3`) ou PNG (bloc `ccv3` de préférence, sinon `chara`). Le type est reconnu au
  contenu. Toujours un **nouveau** personnage (slug inédit), puis redirection vers sa fiche.
- Contrôles : CSRF, taille (JSON ≤ 2 Mo, `card_max_json` ; PNG ≤ `max_upload` ; ZIP : limites de la
  sauvegarde), JSON validé (types, longueurs bornées comme dans le formulaire), portrait vérifié
  avant décodage puis ré-encodé en WebP. Le texte importé n'est jamais interprété comme du HTML.

| PersonaVault | Character Card (export) | À l'import d'une carte d'une autre appli |
|---|---|---|
| nom | `name` | `name` (V1 : aussi `char_name`) |
| description | `description` | `description` |
| accroche | `creator_notes` | `creator_notes` d'une seule ligne (≤ 200 car.) ; sinon prompt « Notes du créateur » |
| tags | `tags` | `tags` (12 max, 32 car.) |
| attributs | `personality` (lignes « clé: valeur »), sauf s'il existe un prompt « Personnalité » | `creator`, `character_version` → attributs « Créateur », « Version de la fiche » |
| prompts « Système » | `system_prompt` (réunis) | `system_prompt` → prompt « Système » |
| consigne de jeu | `post_history_instructions` | `post_history_instructions` |
| prompts « Autre » titrés Personnalité, Scénario, Premier message, Autre premier message, Exemples de dialogue, Notes du créateur (titre reconnu dans les 3 langues) | `personality`, `scenario`, `first_mes`, `alternate_greetings`, `mes_example`, `creator_notes` | ces champs → prompts « Autre » portant ces titres |
| tout (accroche, attributs, tous les prompts, consigne, favori, archivé, modèle, température, longueur, contexte) | `extensions.personavault` | si présent, restitué tel quel (aller-retour sans perte) ; `name`, `description`, `tags` viennent toujours de la carte |
| portrait | image du PNG | image du PNG → portrait |

Non repris : photos de la galerie (seulement dans l'archive `.zip`), conversations, souvenirs ; côté
carte, `character_book` (lorebook), les autres `extensions` et les ajouts V3 (`assets`,
`nickname`, `group_only_greetings`…) sont ignorés.

---

## Installation

Prérequis : **PHP 8.2+** avec `pdo_sqlite`, `gd` (WebP), `mbstring`, `fileinfo`, `exif`.

```bash
git clone https://github.com/<ton-compte>/personavault.git
cd personavault

# mot de passe
cp config.local.example.php config.local.php
php -r "echo password_hash('MonMotDePasse', PASSWORD_DEFAULT), PHP_EOL;"
#   → colle le hash dans config.local.php

mkdir -p storage/uploads
php -d upload_max_filesize=16M -d post_max_size=64M -S 0.0.0.0:8091 -t public public/index.php
```

→ http://localhost:8091 (`0.0.0.0` = joignable depuis le réseau local, par ex. ton téléphone ;
mets `127.0.0.1` si seul un reverse proxy local doit y accéder)

> **Limites d'envoi.** Par défaut PHP n'accepte que **2 Mo par fichier** (`upload_max_filesize`) et
> **8 Mo par envoi** (`post_max_size`) : une photo de téléphone ou une sauvegarde ZIP dépasse vite ces
> valeurs. Passe-les en `-d` comme ci-dessus, ou dans le `php.ini` / la configuration PHP-FPM.
> `post_max_size` doit couvrir l'envoi complet (plusieurs photos, ou la sauvegarde entière).
> L'app accepte ensuite jusqu'à 15 Mo et 40 Mpx par image (`max_upload`, `max_pixels`).

Vérification rapide après installation ou mise à jour (base temporaire, la vraie base n'est pas touchée) :

```bash
php bin/selftest.php
```

### Production

Le serveur intégré `php -S` est un **outil de développement** : la documentation PHP déconseille de
l'utiliser en production (un seul processus par worker, pas de TLS, `PHP_CLI_SERVER_WORKERS`
expérimental). Pour un usage durable, préfère **nginx ou Caddy + PHP-FPM** :

```nginx
server {
    listen 443 ssl;
    server_name personavault.example;
    root /chemin/vers/personavault/public;
    client_max_body_size 64m;

    location / {
        try_files $uri /index.php$is_args$args;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_buffering off;          # streaming des réponses (SSE)
        fastcgi_read_timeout 330s;      # réponses longues du modèle
    }
}
```

Avec Caddy : `php_fastcgi unix//run/php/php8.2-fpm.sock` et `root * /chemin/vers/personavault/public`.

Derrière un reverse proxy HTTPS, active `'trust_proxy' => true` dans `config.local.php` : le cookie
de session reçoit alors l'attribut `Secure` (d'après `X-Forwarded-Proto`) et la limitation des
tentatives de connexion utilise la vraie IP du client (`X-Forwarded-For`). **Laisse-le à `false`**
si PHP est joignable directement : ces en-têtes seraient falsifiables.

Si tu gardes `php -S` (réseau privé uniquement), en service systemd :

```ini
[Unit]
Description=PersonaVault
After=network.target

[Service]
User=www-data
WorkingDirectory=/chemin/vers/personavault
ExecStart=/usr/bin/php -d upload_max_filesize=16M -d post_max_size=64M -S 0.0.0.0:8091 -t public public/index.php
Environment=PHP_CLI_SERVER_WORKERS=4
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## Modèles de langage

Deux fournisseurs, réglables dans **Réglages** :

| Usage | Fournisseur | Remarque |
|---|---|---|
| Discussion, résumés, souvenirs (local) | [LM Studio](https://lmstudio.ai) ou tout serveur compatible OpenAI | `http://localhost:1234/v1` par défaut |
| Fiches, consignes de jeu, titres (réglable) | DeepSeek, OpenAI, OpenRouter… | n'importe quelle API `/chat/completions` |

Les souvenirs peuvent aussi être confiés au modèle distant (Réglages › Chat › Souvenirs durables) :
les derniers messages lui sont alors envoyés.

**Titres automatiques** (Réglages › Chat › Titres des discussions) : modèle distant (par défaut ;
les 4 premiers messages lui sont envoyés), modèle local (rien ne sort) ou désactivés. Si le modèle
distant n'est pas configuré, le local prend le relais.

Le chat utilise le modèle local ; s'il n'est pas joignable, l'app propose de basculer sur le
fournisseur distant — elle ne le fait jamais sans demander.

**Images** : le bouton trombone n'apparaît que si le modèle choisi sait voir les images.
Les photos sont converties en JPEG avant l'envoi (certains serveurs refusent le WebP).

### Mémoire et contexte

Ce que reçoit le modèle à chaque message, dans cet ordre :

1. **Partie fixe** (message système) : prompts « système » du persona, fiche, consigne de jeu,
   résumé de la conversation, règles. Elle ne change qu'à la compression : LM Studio / llama.cpp
   réutilisent leur cache et ne retraitent que la fin.
2. **Historique** : uniquement les messages **pas encore résumés**, plus les 2 derniers messages
   résumés pour la continuité. Sans mémoire, tout l'historique dans la limite de la fenêtre.
3. **Dernier message**, précédé d'un bloc `<contexte>` variable : date et heure, silence écoulé,
   souvenirs pertinents, extraits d'autres conversations.

La compression se déclenche quand les messages non résumés dépassent le **seuil de compression** ;
il en reste environ « messages récents gardés » en clair. L'anneau affiche une estimation de ce qui
est réellement envoyé. `php bin/prompt-preview.php <id> "message"` affiche le prompt complet.

**Souvenirs durables** — Réglages › Chat :
- fréquence : après chaque échange, tous les N messages, seulement sur demande (« retiens que… »), jamais ;
- moment : dès la réponse, ou quand la discussion est calme depuis X secondes (le modèle local reste
  libre pendant l'échange ; si la page est fermée avant, l'extraction se fait la fois suivante, sans perte) ;
- modèle : local (rien ne sort) ou distant (les derniers messages sont envoyés au fournisseur).

### Relances spontanées (tâche planifiée)

Les messages spontanés sont envoyés par un script à lancer régulièrement, par exemple toutes les
30 minutes :

```cron
*/30 * * * * cd /chemin/vers/personavault && php bin/followup.php >> storage/followup.log 2>&1
```

`php bin/followup.php --dry` montre ce qui serait envoyé sans rien enregistrer.

Chaque passage laisse une trace : Réglages › Relances spontanées affiche le dernier passage et son
résultat (ou « jamais lancée », avec la ligne à copier), et une conversation prévient quand les
relances ne peuvent pas partir (désactivées partout, ou tâche jamais lancée). Deux passages ne se
chevauchent pas (verrou `storage/followup.lock`).

---

## Configuration

`config.php` contient les valeurs par défaut ; `config.local.php` (non versionné) les surcharge :

```php
return [
    'password_hash' => '...',
    'db'            => __DIR__ . '/storage/personavault.sqlite',
    'uploads'       => __DIR__ . '/storage/uploads',
    'max_upload'    => 15 * 1024 * 1024,
];
```

La clé API du fournisseur distant est stockée en base, dans la table `settings`.

---

## Données

| Table | Contenu |
|---|---|
| `personas` | nom, accroche, description, portrait, tags, attributs, favori, archivé |
| `photos` | galerie par personnage (`storage/uploads/<base>.webp` + `.thumb.webp`) |
| `prompts` | titre, type, contenu |
| `conversations` / `messages` | discussions, bulles, images envoyées |
| `user_memories` | souvenirs persistants (+ index FTS5) |
| `login_attempts` | échecs de connexion récents (limitation) |

Le schéma est versionné (`PRAGMA user_version`) : les migrations s'appliquent seules au premier
accès, une seule fois, dans une transaction.

---

## Structure

```
personavault/
├── config.php              # réglages par défaut
├── config.local.example.php
├── public/
│   ├── index.php           # fichiers statiques, démarrage, liste des routes
│   └── assets/             # app.css, app.js, favicon
├── src/
│   ├── routes/             # une route ou un domaine par fichier, inclus par index.php
│   │   ├── auth.php, media.php, chat.php, library.php, settings.php, memories.php…
│   │   ├── conversation.php    # /chat/{id} → conversation/{stream,model,memory,manage,export,messages}.php
│   │   └── persona.php         # /p/{slug} → persona/{ai,edit,media,show}.php
│   │   ├── conversation.php    # /chat/{id} → conversation/{stream,model,memory,manage,messages}.php
│   │   └── persona.php         # /p/{slug} → persona/{ai,edit,media,cards,show}.php
│   ├── bootstrap.php       # session, PDO, schéma, uploads, helpers
│   ├── i18n.php            # traductions : t(), langue, pluriels
│   ├── llm.php             # client OpenAI-compatible (streaming inclus)
│   ├── chat.php            # moteur de discussion, mémoire, bulles
│   ├── facts.php           # souvenirs + recherche FTS5
│   ├── prompts.php         # prompts internes éditables
│   ├── backup.php          # export / import ZIP
│   └── cards.php           # fiches : Character Card JSON / PNG, import de fiches
├── views/                  # gabarits PHP
├── bin/                    # outils CLI (aperçu de prompt, relance, nettoyage, selftest)
├── lang/                   # textes de l'interface : lang/fr/ (référence), lang/en/, lang/es/
├── tests/e2e/              # parcours de toutes les routes (faux modèle, données de test)
├── .github/workflows/      # CI : php -l, bin/selftest.php, tests/e2e/run.sh
└── storage/                # base + images (non versionné)
```

---

## Langues de l'interface

Les textes de l'interface sont dans `lang/`, **un dossier par langue** (`lang/fr/` = référence,
`lang/en/`, `lang/es/`) et un fichier par domaine (`common.php`, `chat.php`, `settings.php`…) :

```php
// lang/fr/common.php
return [
    '_name'          => 'Français',                       // nom affiché dans les réglages
    'nav.library'    => 'Bibliothèque',
    'login.blocked'  => 'Trop de tentatives. Réessaie dans {n} min.',
    'count.photos'   => ['one' => '{n} photo', 'other' => '{n} photos'],   // pluriel
    'js.modal.close' => 'Fermer',                         // « js.* » : aussi pour le JavaScript
];
```

- PHP : `<?= e(t('nav.library')) ?>`, `t('login.blocked', 3)`, `t('clé', ['name' => $x])` ;
  `t_html()` pour insérer un fragment HTML ; une clé qui finit par `_html` contient du HTML de confiance
  et s'affiche sans `e()`.
- JavaScript : `tr("modal.close")` (clés `js.*`, fournies par la page).
- Langue : Réglages › Apparence (« Automatique » = langue du navigateur, sinon français).
  Une clé absente dans une langue retombe sur le français.
- **Ajouter une langue** : copier le dossier `lang/en/` en `lang/xx/`, traduire, puis `php bin/i18n-check.php`
  (clés manquantes ou en double, paramètres `{…}` oubliés, pluriels, HTML hors des clés `_html`, clés
  utilisées mais inconnues ; lancé par la CI).
- Le dossier `lang/` doit être déployé avec le reste.
- Toute l'interface est traduite en **français, anglais et espagnol** (≈ 720 textes) : pages, JavaScript,
  messages du serveur, dates (`fr_date()`), nombres (`fmt_number()` : « 12 345 » / « 12,345 »).
- Restent en français, volontairement : les consignes envoyées aux modèles (Réglages › Prompts), et les
  notices déjà enregistrées dans une conversation (écrites dans la langue du moment).
- La détection de « retiens que… », « tu te souviens… » fonctionne dans les trois langues, quelle que soit
  la langue de l'interface (« remember that… », « recuerda que… »).
- Détail d'écriture : PHP supprime le saut de ligne qui suit `?>`. Un texte traduit seul en fin de ligne
  s'écrit donc `<?= e(t('…')), "\n" ?>` pour garder un rendu identique.

---

## Tests

Deux niveaux, lancés aussi par la CI à chaque envoi sur GitHub :

```bash
php bin/selftest.php        # fonctions internes : migrations, images, sauvegarde, fiches, mémoire, sécurité
bash tests/e2e/run.sh       # l'app réelle : toutes les routes, avec un faux modèle (Node 18+)
```

`tests/e2e/run.sh` copie l'app dans un dossier temporaire avec des données de test
(`tests/e2e/seed.php`) et un faux modèle déterministe (`tests/e2e/fake-llm.mjs`) : la vraie base
n'est jamais touchée. Il vérifie les statuts de ~60 requêtes (`tests/e2e/expected.txt`), quelques
contenus clés (recherche, compression, souvenirs, flux de réponse) et l'absence d'avertissement PHP.

Avant / après un refactoring, pour vérifier que **rien** ne change (contenus compris) :

```bash
bash tests/e2e/run.sh --record /tmp/avant.txt   # avant la modification
bash tests/e2e/run.sh --compare /tmp/avant.txt  # après
```

Après un changement voulu de statut ou de route : `bash tests/e2e/run.sh --update-expected`.

---

## Sécurité

- Accès protégé par **un seul mot de passe** (hash `PASSWORD_DEFAULT`, dans `config.local.php`).
- **Tentatives de connexion limitées** et comptées en base (table `login_attempts`) : 5 échecs par IP
  et 30 échecs toutes IP confondues par quart d'heure (`login_max_try`, `login_global_max`,
  `login_window`). Effacer ses cookies ne remet pas le compteur à zéro. Revers du seuil global :
  quelqu'un qui martèle la page de connexion peut te bloquer toi aussi pendant 15 minutes.
- Jeton CSRF sur tous les formulaires et appels AJAX (un jeton vide est toujours refusé), session
  régénérée à la connexion, cookie `HttpOnly` + `SameSite=Lax`.
- En-têtes `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.
- Images ré-encodées en WebP (métadonnées supprimées), 40 Mpx max, stockées hors de `public/` et
  servies uniquement après connexion. Import ZIP : rien n'est extrait sur le disque, nombre et taille
  des entrées bornés (`zip_max_entries`, `zip_max_entry_size`, `zip_max_total`).
- La clé API du fournisseur distant est stockée **en clair** dans la base (`storage/`, créé en 0700,
  base en 0600) : protège l'accès au serveur et aux sauvegardes du dossier `storage/`. Le bouton
  « Tester » n'envoie la clé enregistrée qu'à l'adresse enregistrée.
- Les erreurs techniques sont écrites dans le journal PHP (`error_log`) ; le navigateur ne reçoit
  qu'un message générique.

L'app est pensée pour un usage personnel. Si tu l'exposes sur Internet, place-la **obligatoirement
derrière HTTPS** (reverse proxy, voir « Production ») — sinon le mot de passe et le cookie de session
circulent en clair.
