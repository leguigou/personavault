// Parcours de toutes les routes de l'app (pages, actions, erreurs, flux SSE, accès anonyme).
// Sans dépendance : fetch de Node 18+ et un petit gestionnaire de cookies.
// Usage : node smoke.mjs <url de l'app> <port du faux modèle> <fichier de sortie>
// Chaque ligne : statut, type de contenu, empreinte du contenu normalisé, libellé.
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';

const [base = 'http://127.0.0.1:18091', llmPort = '18235', out = 'smoke-result.txt'] = process.argv.slice(2);

const jar = new Map();
const cookieHeader = () => [...jar].map(([k, v]) => k + '=' + v).join('; ');
const remember = (r) => {
    for (const c of r.headers.getSetCookie?.() ?? []) {
        const [kv] = c.split(';');
        const i = kv.indexOf('=');
        jar.set(kv.slice(0, i), kv.slice(i + 1));
    }
};
const http = async (path, { method = 'GET', form, anonymous = false } = {}) => {
    const r = await fetch(base + path, {
        method,
        redirect: 'manual',
        headers: anonymous ? {} : { cookie: cookieHeader() },
        body: form ? new URLSearchParams(form) : undefined,
    });
    if (!anonymous) remember(r);
    return r;
};

// contenu normalisé : sans jetons, chiffres, dates ni durées (qui changent à chaque exécution)
const norm = (t) => t
    .replace(/<script type="application\/json" id="pv-i18n">[\s\S]*?<\/script>\n?/g, '') // textes du JS, vérifiés à part
    .replace(/\?v=[0-9a-f]+/g, '?v=V') // version des fichiers statiques (empreinte du contenu)
    .replace(/p[0-9a-f]{16}/g, 'IMG') // noms d'images aléatoires
    .replace(/name="_token" value="[^"]+"/g, '')
    .replace(/data-token="[^"]+"/g, '')
    .replace(/"_token":"[^"]+"/g, '')
    .replace(/\d+/g, '#')
    .replace(/(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche|janv|févr|mars|avr|mai|juin|juil|août|sept|oct|nov|déc)[a-zé.]*/gi, 'D')
    .replace(/il y a [^<"]+/g, 'X')
    .replace(/à l['’]instant/g, 'X');

const lines = [];
let serverErrors = 0; // réponses 5xx
let failures = 0;     // vérifications de contenu ratées
const record = async (label, r) => {
    const ct = (r.headers.get('content-type') || '').split(';')[0];
    const binary = /zip|image/.test(ct);
    const body = binary ? '' : await r.text();
    const hash = createHash('md5').update(norm(body)).digest('hex').slice(0, 10);
    // E2E_DUMP=<dossier> : enregistre chaque contenu normalisé (pour comprendre un écart)
    if (process.env.E2E_DUMP) {
        mkdirSync(process.env.E2E_DUMP, { recursive: true });
        writeFileSync(`${process.env.E2E_DUMP}/${String(lines.length).padStart(2, '0')}-${label.replace(/[^\w.-]+/g, '_').slice(0, 60)}.txt`, norm(body));
    }
    if (r.status >= 500) serverErrors++;
    lines.push(`${r.status} ${ct.padEnd(22)} ${hash}  ${label}`);
    return body;
};
const expect = (ok, what) => {
    if (!ok) { failures++; console.log('  ÉCHEC : ' + what); }
};
const token = (html) => (html.match(/name="_token" value="([^"]+)"/) || html.match(/data-token="([^"]+)"/) || [])[1];

// --- accès anonyme
for (const u of ['/', '/login', '/chat', '/settings', '/assets/app.css?v=1', '/assets/nope.css', '/media/p0000000000000000.webp']) {
    await record('anonyme GET ' + u, await http(u, { anonymous: true }));
}

// --- connexion
const loginPage = await http('/login');
const t0 = token(await loginPage.text());
await record('POST /login (mauvais mot de passe)', await http('/login', { method: 'POST', form: { _token: t0, password: 'faux' } }));
await record('POST /login', await http('/login', { method: 'POST', form: { _token: t0, password: 'test' } }));

const home = await http('/');
const homeHtml = await home.text();
const csrf = token(homeHtml);
const media = (homeHtml.match(/src="(\/media\/[^"]+)"/) || [])[1] || '/media/absent.webp';

// --- pages et routes GET
for (const u of ['/', '/?fav=1', '/?arch=1', '/?q=lis', '/?tag=romance', '/p/new', '/p/camille-lefebvre',
    '/p/camille-lefebvre/edit', '/p/inexistant', '/chat', '/chat/1', '/chat/1/status', '/chat/1/messages?before=10',
    '/chat/1/search?q=lisb', '/chat/1/search?q=pasteis%20tage', '/chat/999', '/chat/models', '/memories',
    '/memories?q=train', '/settings', '/export.json', '/backup/export.zip', '/nope', '/photos/1', '/assets/app.js']) {
    const body = await record('GET ' + u, await http(u));
    // quelques vérifications de contenu, au-delà du statut
    if (u === '/') expect(body.includes('Camille Lefebvre') && body.includes('Hugo Bernard'), 'la bibliothèque liste les personas');
    if (u === '/chat/1/search?q=lisb') expect((JSON.parse(body).results || []).length > 0, 'la recherche « lisb » trouve « Lisbonne »');
    if (u === '/chat/1/search?q=pasteis%20tage') expect((JSON.parse(body).results || []).length > 0, 'recherche sans accents, plusieurs mots');
    if (u === '/export.json') expect((JSON.parse(body).personas || []).length === 3, 'l’export JSON contient les 3 personas');
    if (u === '/chat/1') expect(body.includes('Message 11'), 'la discussion affiche ses messages');
}
await record('GET /media/(portrait)', await http(media));

// --- exports (conversation, fiche) et import d'une fiche
for (const u of ['/chat/1/export.txt', '/chat/1/export.md', '/chat/1/print', '/chat/999/export.txt', '/p/camille-lefebvre/export.zip', '/p/camille-lefebvre/card.png']) {
    const body = await record('GET ' + u, await http(u));
    if (u === '/chat/1/export.txt') expect(body.includes('Week-end à Lisbonne') && body.includes('Message 11'), 'export texte : titre et messages');
    if (u === '/chat/1/export.md') expect(body.startsWith('# '), 'export Markdown : titre');
}
const cardRes = await http('/p/camille-lefebvre/card.json');
const cardJson = await cardRes.clone().text();
await record('GET /p/camille-lefebvre/card.json', cardRes);
expect(JSON.parse(cardJson).spec === 'chara_card_v2', 'carte exportée au format chara_card_v2');
{
    const fd = new FormData();
    fd.append('_token', csrf);
    fd.append('card', new Blob([cardJson], { type: 'application/json' }), 'camille.card.json');
    const r = await fetch(base + '/import', { method: 'POST', body: fd, redirect: 'manual', headers: { cookie: cookieHeader() } });
    remember(r);
    expect(r.status === 303 && /\/p\/camille-lefebvre-/.test(r.headers.get('location') || ''), 'import de la carte : nouvelle fiche créée sans écraser l’originale');
    await record('POST /import (carte JSON)', r);
}

// --- actions (POST), dans un ordre fixe
const post = async (u, form = {}, label = u) => record('POST ' + label, await http(u, { method: 'POST', form: { _token: csrf, ...form } }));
await post('/p', { name: 'Smoke Test', tagline: 'essai', description: 'd', tags: 'a, b', meta: 'Âge: 30' });
await post('/p/smoke-test/favorite');
await post('/p/smoke-test/archive');
await post('/p/smoke-test/archive', {}, '/p/smoke-test/archive (2)');
await post('/p/smoke-test/prompts', { title: 'P', kind: 'image', body: 'un prompt' });
await post('/p/smoke-test', { name: 'Smoke Test', tagline: 'modifié', description: 'd2', tags: 'a', meta: '' });
await post('/p', { name: '' }, '/p (nom vide)');
await post('/p/smoke-test/favorite', { _token: 'mauvais' }, '/p/smoke-test/favorite (jeton faux)');
await post('/chat', { persona_id: '2' });
await post('/chat/1/rename', { title: 'Renommée' });
await post('/chat/1/toggles', { memory_enabled: '1' });
await post('/chat/1/directive', { directive: 'Consigne de test pour cette conversation.' });
await post('/chat/1/facts-toggle', { enabled: '0' });
await post('/chat/1/facts-toggle', { enabled: '1' }, '/chat/1/facts-toggle (2)');
const comp = await post('/chat/1/compress', { force: '1' });
expect(JSON.parse(comp).compressed > 0, 'la compression résume des messages');
const rem = await post('/chat/1/remember', { force: '1' });
expect((JSON.parse(rem).added || []).length > 0, 'l’extraction ajoute un souvenir');
await post('/chat/1/autotitle');
await post('/memories', { content: 'Aime le jazz', category: 'preference', confidence: 'confirme', persona_id: '' });
await post('/settings/ui', { ui_font_size: '16', ui_bubble_style: 'carte', ui_density: 'compact', ui_width: 'large', ui_avatars: '1' });
await post('/settings/prompts', { prompt_memory: '', prompt_facts: '', prompt_followup: '' });
await post('/settings', { provider: 'deepseek', base_url: 'https://api.deepseek.com/v1', model: 'deepseek-chat', api_key: '' });
await post('/settings/chat', { chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`, chat_model: 'fake-model',
    chat_memory_threshold: '600', chat_memory_target: '300', chat_split_words: '50', chat_split_max_bubbles: '1',
    facts_frequency: 'each', chat_title_model: 'chat' });
const sse = await post('/chat/1/stream', { message: 'Bonjour, test du flux' });
expect(sse.includes('"delta"') && sse.includes('"done"'), 'la réponse arrive en streaming jusqu’au bout');
// régénérer deux fois : l'ancienne réponse est gardée, on navigue entre les versions (‹ 2/2 ›)
const regen1 = await post('/chat/1/stream', { regen: '1' }, '/chat/1/stream (régénérer)');
expect(/"versions":\{"index":2,"total":2\}/.test(regen1), 'la régénération garde l’ancienne réponse comme version 1/2');
const vid = (regen1.match(/"reply":\{"id":(\d+)/) || [])[1];
const vprev = await post('/chat/1/version', { mid: vid, dir: '-1' }, '/chat/1/version (précédente)');
expect(vprev.includes('"index":1') && vprev.includes('"total":2'), 'on revient à la version 1/2');
await post('/chat/1/pin', { pinned: '1' });
await post('/chat/1/pin', { pinned: '0' }, '/chat/1/pin (retirer)');
await post('/chat/1/msg-delete', { mid: '1' });
await post('/settings/test');
await post('/p/smoke-test/duplicate');
const copy = await record('GET /p/smoke-test-copie', await http('/p/smoke-test-copie'));
expect(copy.includes('Smoke Test (copie)') && copy.includes('un prompt'), 'la copie reprend la fiche et ses prompts');
await post('/p/smoke-test-copie/delete');
await post('/p/smoke-test/delete');
await post('/logout');
await record('GET / (après déconnexion)', await http('/'));

writeFileSync(out, lines.join('\n') + '\n');
const plural = (n, one, many) => n + ' ' + (n >= 2 ? many : one);
console.log(`${lines.length} requêtes → ${out}`
    + (serverErrors ? ' — ' + plural(serverErrors, 'erreur serveur (5xx)', 'erreurs serveur (5xx)') : '')
    + (failures ? ' — ' + plural(failures, 'vérification ratée', 'vérifications ratées') : ''));
process.exit(serverErrors || failures ? 1 : 0);
