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
- Mémoire glissante : les vieux échanges sont résumés automatiquement quand le contexte se remplit.
- Souvenirs persistants par personnage (recherche FTS5), partagés entre toutes ses discussions,
  avec une trace visible « mémoire mise à jour » sous l'échange.
- Relance spontanée : le personnage peut écrire de lui-même après plusieurs heures de silence.
- Photos : quand le modèle sait voir les images, un trombone apparaît pour en envoyer une.
- Édition : modifier son message efface la suite et rejoue la réponse depuis ce point.
- Titres de discussion générés automatiquement.
- Réglages par personnage : modèle, température, longueur, fenêtre de contexte, consigne.

### Sauvegarde
- Export ZIP : un dossier par personnage (`persona.json`, `prompts.json`, photos).
- Import : recrée les personnages sans rien écraser, avec ré-encodage des images.
- Export optionnel des conversations.

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
php -S 0.0.0.0:8091 -t public public/index.php
```

→ http://localhost:8091

En service (systemd) :

```ini
[Unit]
Description=PersonaVault
After=network.target

[Service]
User=www-data
WorkingDirectory=/chemin/vers/personavault
ExecStart=/usr/bin/php -S 0.0.0.0:8091 -t public public/index.php
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
| Discussion (local) | [LM Studio](https://lmstudio.ai) ou tout serveur compatible OpenAI | `http://localhost:1234/v1` par défaut |
| Fiches, résumés, titres | DeepSeek, OpenAI, OpenRouter… | n'importe quelle API `/chat/completions` |

Le chat utilise le modèle local ; s'il n'est pas joignable, l'app propose de basculer sur le
fournisseur distant — elle ne le fait jamais sans demander.

**Images** : le bouton trombone n'apparaît que si le modèle choisi sait voir les images.
Les photos sont converties en JPEG avant l'envoi (certains serveurs refusent le WebP).

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

---

## Structure

```
personavault/
├── config.php              # réglages par défaut
├── config.local.example.php
├── public/
│   ├── index.php           # routeur + actions
│   └── assets/             # app.css, app.js, favicon
├── src/
│   ├── bootstrap.php       # session, PDO, schéma, uploads, helpers
│   ├── llm.php             # client OpenAI-compatible (streaming inclus)
│   ├── chat.php            # moteur de discussion, mémoire, bulles
│   ├── facts.php           # souvenirs + recherche FTS5
│   ├── prompts.php         # prompts internes éditables
│   └── backup.php          # export / import ZIP
├── views/                  # gabarits PHP
├── bin/                    # outils CLI (aperçu de prompt, relance, nettoyage)
└── storage/                # base + images (non versionné)
```

---

## Sécurité

Accès protégé par **un seul mot de passe** (hash `PASSWORD_DEFAULT`, 5 tentatives par quart
d'heure). L'app est pensée pour un usage personnel sur un réseau privé : si tu l'exposes sur
Internet, place-la derrière **HTTPS** (reverse proxy) — sinon le mot de passe et le cookie de
session circulent en clair.
