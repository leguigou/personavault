#!/usr/bin/env bash
# Parcours e2e : copie l'app dans un dossier temporaire, crée des données de test,
# lance l'app (php -S) et un faux modèle, puis appelle toutes les routes.
# Ne touche JAMAIS à la vraie base ni au dossier storage/ du dépôt.
#
#   tests/e2e/run.sh                     vérifie statuts et types de contenu (tests/e2e/expected.txt)
#   tests/e2e/run.sh --record ref.txt    enregistre le résultat complet (avec empreintes des contenus)
#   tests/e2e/run.sh --compare ref.txt   compare au résultat enregistré : idéal avant/après un refactoring
#   tests/e2e/run.sh --update-expected   met à jour tests/e2e/expected.txt après un changement voulu
#
# Ports : E2E_PORT (18091) pour l'app, E2E_LLM_PORT (18235) pour le faux modèle.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PORT="${E2E_PORT:-18091}"
LLM_PORT="${E2E_LLM_PORT:-18235}"
MODE="${1:-}"
REF="${2:-}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/pv-e2e-XXXXXX")"
PIDS=()
cleanup() {
    for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null || true; done
    rm -rf "$WORK"
}
trap cleanup EXIT

# --- copie de l'app + configuration de test (mot de passe « test »)
cp -r "$ROOT/config.php" "$ROOT/public" "$ROOT/src" "$ROOT/views" "$ROOT/bin" "$ROOT/lang" "$WORK/"
HASH="$(php -r 'echo password_hash("test", PASSWORD_DEFAULT);')"
cat > "$WORK/config.local.php" <<PHP
<?php
return [
    'password_hash' => '$HASH',
    'db'            => __DIR__ . '/storage/test.sqlite',
    'uploads'       => __DIR__ . '/storage/uploads',
    'assist_lookup_url' => 'http://127.0.0.1:$LLM_PORT/wiki/{lang}/api.php',
];
PHP
mkdir -p "$WORK/storage/uploads"

# --- données de test, faux modèle, serveur
php "$HERE/seed.php" "$WORK" "$LLM_PORT"
node "$HERE/fake-llm.mjs" "$LLM_PORT" & PIDS+=($!)
(cd "$WORK" && exec php -S "127.0.0.1:$PORT" -t public public/index.php) > "$WORK/server.log" 2>&1 & PIDS+=($!)
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/login" && break
    sleep 0.1
done

# --- parcours
RESULT="$WORK/result.txt"
SMOKE=0
node "$HERE/smoke.mjs" "http://127.0.0.1:$PORT" "$LLM_PORT" "$RESULT" || SMOKE=$?

# aucune erreur ni avertissement PHP (vérifié même si le parcours a échoué)
if grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "$WORK/server.log"; then
    echo "ÉCHEC : avertissements ou erreurs PHP pendant le parcours (ci-dessus)."
    exit 1
fi
if [ "$SMOKE" -ne 0 ]; then
    echo "ÉCHEC du parcours (détails ci-dessus)."
    exit "$SMOKE"
fi

# statut + type de contenu + libellé (sans l'empreinte)
statuses() { awk '{ $3 = ""; print }' "$1"; }

case "$MODE" in
    --record)
        cp "$RESULT" "${REF:?fichier attendu}"
        echo "Résultat enregistré dans $REF"
        ;;
    --compare)
        if diff -u "${REF:?fichier attendu}" "$RESULT"; then
            echo "IDENTIQUE à $REF : mêmes statuts et mêmes contenus."
        else
            echo "ÉCART avec $REF (ci-dessus)."
            exit 1
        fi
        ;;
    --update-expected)
        statuses "$RESULT" > "$HERE/expected.txt"
        echo "tests/e2e/expected.txt mis à jour."
        ;;
    *)
        if diff -u "$HERE/expected.txt" <(statuses "$RESULT"); then
            echo "OK : toutes les routes répondent comme attendu."
        else
            echo "ÉCART de statut ou de type de contenu (ci-dessus)."
            exit 1
        fi
        ;;
esac
