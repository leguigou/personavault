// Parcours de toutes les routes de l'app (pages, actions, erreurs, flux SSE, accès anonyme).
// Sans dépendance : fetch de Node 18+ et un petit gestionnaire de cookies.
// Usage : node smoke.mjs <url de l'app> <port du faux modèle> <fichier de sortie> [url du second worker]
// Chaque ligne : statut, type de contenu, empreinte du contenu normalisé, libellé.
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';

const [base = 'http://127.0.0.1:18091', llmPort = '18235', out = 'smoke-result.txt', peer] = process.argv.slice(2);

const jar = new Map();
const cookieHeader = () => [...jar].map(([k, v]) => k + '=' + v).join('; ');
const remember = (r) => {
    for (const c of r.headers.getSetCookie?.() ?? []) {
        const [kv] = c.split(';');
        const i = kv.indexOf('=');
        jar.set(kv.slice(0, i), kv.slice(i + 1));
    }
};
const http = async (path, { method = 'GET', form, anonymous = false, origin = base, signal, ajax = false } = {}) => {
    const headers = anonymous ? {} : { cookie: cookieHeader() };
    if (ajax) headers['X-Requested-With'] = 'XMLHttpRequest';
    const r = await fetch(origin + path, {
        method,
        redirect: 'manual',
        headers,
        body: form ? new URLSearchParams(form) : undefined,
        signal,
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
const login = await http('/login', { method: 'POST', form: { _token: t0, password: 'test' } });
expect(login.headers.get('location') === '/chat', 'la connexion ouvre les discussions');
await record('POST /login', login);
expect((await http('/')).headers.get('location') === '/chat', 'l’accueil ouvre les discussions');

const home = await http('/library');
const homeHtml = await home.text();
expect(homeHtml.includes('data-chat-unread>'), 'le point de notification est visible avec une discussion non lue');
expect((await (await http('/chat/unread')).json()).unread === true, 'le statut de notification indique un nouveau message');
const csrf = token(homeHtml);
{
    const searchPath = '/chat/1/find-messages';
    const find = async (url) => (await http(url)).json();
    const found = await find(searchPath + '?q=R%C3%89PONSE');
    expect(found.ok && found.total === 6 && found.results[0]?.id === 12
        && found.results.every((row) => row.role === 'assistant' && row.excerpt.startsWith('Réponse') && row.match_at === 0),
        'la recherche locale retrouve le texte sans tenir compte de la casse et classe les résultats du plus récent au plus ancien');
    const other = await find('/chat/2/find-messages?q=R%C3%89PONSE');
    expect(other.total === 0 && (await find(searchPath + '?offset=1&q=R%C3%89PONSE')).results[0]?.id === 10,
        'la recherche reste limitée à la discussion et peut paginer les résultats');
    const day = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris' }).format(new Date(found.results[0]?.at));
    const dated = await find(searchPath + '?date=' + day);
    expect(dated.results.some((row) => row.id === 12) && dated.results.every((row) =>
        new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris' }).format(new Date(row.at)) === day)
        && (await find(searchPath + '?date=2000-01-01')).total === 0
        && (await http(searchPath + '?date=2026-02-30')).status === 400, 'le filtre par date utilise le jour local de la discussion');
    const window = await find('/chat/1/message-window?id=4');
    expect(window.messages.some((message) => message.id === 3) && window.messages.some((message) => message.id === 5)
        && (await http('/chat/2/message-window?id=4')).status === 404, 'la vue dans le fil charge les voisins du résultat dans la bonne discussion');
}
const media = (homeHtml.match(/src="(\/media\/[^"]+)"/) || [])[1] || '/media/absent.webp';

// --- pages et routes GET
for (const u of ['/library', '/?fav=1', '/?arch=1', '/?q=lis', '/?tag=romance', '/p/new', '/p/camille-lefebvre',
    '/p/camille-lefebvre/edit', '/p/inexistant', '/chat', '/chat/1', '/chat/1/status', '/chat/1/messages?before=10',
    '/chat/1/search?q=lisb', '/chat/1/search?q=pasteis%20tage', '/chat/999', '/chat/models', '/memories',
    '/memories?q=train', '/settings', '/settings/logs', '/p/assist', '/p/smoke-test/assist', '/prompts/1/history', '/export.json', '/backup/export.zip', '/nope', '/photos/1', '/assets/app.js']) {
    const body = await record('GET ' + u, await http(u));
    // quelques vérifications de contenu, au-delà du statut
    if (u === '/library') expect(body.includes('Camille Lefebvre') && body.includes('Hugo Bernard'), 'la bibliothèque liste les personas');
    if (u === '/chat') expect(/<nav class="tabbar"[^>]*>\s*<a href="\/chat"[^>]*aria-current="page"[\s\S]*?<\/a>\s*<a href="\/library"/.test(body),
        'le menu du bas affiche Discussions en premier et Bibliothèque en deuxième');
    if (u === '/chat/1/search?q=lisb') expect((JSON.parse(body).results || []).length > 0, 'la recherche « lisb » trouve « Lisbonne »');
    if (u === '/chat/1/search?q=pasteis%20tage') expect((JSON.parse(body).results || []).length > 0, 'recherche sans accents, plusieurs mots');
    if (u === '/export.json') expect((JSON.parse(body).personas || []).length === 3, 'l’export JSON contient les 3 personas');
    if (u === '/chat/1') expect(body.includes('Message 11'), 'la discussion affiche ses messages');
}
expect((await (await http('/chat/unread')).json()).unread === false
    && (await (await http('/library')).text()).includes('data-chat-unread hidden>'),
    'le point de notification disparaît après lecture de la dernière discussion non lue');
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
const rem = JSON.parse(await post('/chat/1/remember', { force: '1' }));
expect(rem.added.some((fact) => fact.length > 90) && rem.memory_msg?.content === rem.added.join(' · '),
    'la bulle mémoire contient les souvenirs en entier');
const savedMemory = (await (await http('/chat/1/messages')).json()).messages.find((m) => m.id === rem.memory_msg?.id);
expect(savedMemory?.role === 'memory' && savedMemory.content === rem.memory_msg.content,
    'le texte entier de la bulle mémoire reste visible après rechargement');
await post('/chat/1/autotitle');
{
    const chatAction = async (cid, action, form = {}) => http(`/chat/${cid}/${action}`, {
        method: 'POST', form: { _token: csrf, ...form },
    });
    const empty = await (await chatAction(2, 'retitle')).json();
    expect(!empty.ok && empty.error, 'la réécriture d’une conversation vide explique pourquoi elle est impossible');
    await (await chatAction(1, 'stream', { message: 'Parlons maintenant des volcans en Islande.' })).text();
    const title = await (await chatAction(1, 'retitle')).json();
    const list = await (await http('/chat')).text();
    expect(title.ok && title.title === 'Volcans en Islande'
        && list.includes('data-act="retitle"') && list.includes('data-conv-title="Volcans en Islande"'),
        'le menu permet de remplacer un titre manuel par le sujet des échanges récents');
    expect(!(await (await chatAction(1, 'autotitle')).json()).ok, 'le titre réécrit est protégé de la génération automatique');
}
await post('/memories', { content: 'Aime le jazz', category: 'preference', confidence: 'confirme', persona_id: '' });
await post('/settings/ui', { ui_font_size: '16', ui_bubble_style: 'carte', ui_density: 'compact', ui_width: 'large', ui_avatars: '1' });
await post('/settings/prompts', { prompt_memory: '', prompt_facts: '', prompt_followup: '' });
// assistant de création : un personnage existant est retrouvé (faux Wikipédia) et toute la fiche remplie
{
    const t1 = JSON.parse(await post('/assist/turn', { draft: '{}', history: '[]', action: 'reply', answer: 'Je veux Sherlock Holmes' }, '/assist/turn (personnage existant)'));
    expect(t1.ok && t1.pending_canon?.name === 'Sherlock Holmes' && t1.draft.name === '' && t1.choices.length === 2,
        'assistant : le choix de la version précède le remplissage de la fiche');
    const version = JSON.parse(await post('/assist/turn', { draft: JSON.stringify(t1.draft), action: 'reply', answer: 'Les romans',
        pending_canon: JSON.stringify(t1.pending_canon) }, '/assist/turn (version choisie)'));
    expect(version.ok && version.draft.name === 'Sherlock Holmes' && version.draft.looks !== '' && version.draft.source.includes('Sherlock')
        && version.draft.meta.some(({ k, v }) => k === 'Version' && v === 'Les romans')
        && (version.source?.url || '').startsWith('https://fr.wikipedia.org/wiki/Sherlock_Holmes'), 'assistant : la version choisie et la référence sont transmises au remplissage');
    const t2 = JSON.parse(await post('/assist/turn', { draft: '{}', history: '[]', action: 'reply', answer: 'Une fleuriste' }, '/assist/turn (idée libre)'));
    expect(t2.ok && t2.source === null && t2.question !== '', 'assistant : idée libre, une question à la fois');
    const draftTurn = async (form) => (await (await http('/assist/turn', { method: 'POST', form: { _token: csrf, ...form } })).json());
    const history = Array.from({ length: 18 }, (_, i) => ({ role: i % 2 ? 'assistant' : 'user', content: i === 0 ? 'Détail ancien de Nina' : `Détail ${i}` }));
    const completed = await draftTurn({ history: JSON.stringify(history), answer: 'Je complète la voix de Nina' });
    expect(completed.ready && completed.progress.percent === 100 && completed.question === '' && completed.draft.work === 'Fleuriste',
        'assistant : retrouve un détail au-delà des douze derniers messages et s’arrête à 100 %');
    const enriched = await draftTurn({ draft: JSON.stringify(completed.draft), answer: 'Je précise un détail de Nina' });
    expect(enriched.ready && enriched.question === '' && enriched.draft.voice === 'Parle à voix basse.',
        'assistant : une fiche prête accepte encore des précisions sans rouvrir le questionnaire');
    let stalled = { draft: { name: 'Nina' }, topic: 'name', stalls: 0 };
    for (let i = 0; i < 3; i++) {
        stalled = await draftTurn({ draft: JSON.stringify(stalled.draft), focus: stalled.topic, stalls: String(stalled.stalls), answer: 'Je ne sais pas.' });
    }
    expect(stalled.paused && stalled.question === '' && stalled.progress.percent < 100,
        'assistant : la série de questions s’arrête après trois tours sans progrès');
    for (const answer of ['Je valide la fiche.', 'C’est bon, je valide.', 'On peut passer à la relecture maintenant']) {
        const validation = await http('/assist/turn', { method: 'POST', form: { _token: csrf, draft: JSON.stringify(version.draft), answer } });
        const result = await validation.json();
        expect(result.ok && result.review === true && result.question === '' && result.draft.name === 'Sherlock Holmes'
            && (await http('/p/sherlock-holmes')).status === 404, 'assistant : la validation écrite demande la relecture et conserve la fiche sans l’enregistrer');
    }
    const preview = JSON.parse(await post('/assist/preview', { draft: JSON.stringify(version.draft) }));
    expect(preview.ok && preview.progress.percent === 100 && preview.fields.system_prompt.includes('Sherlock Holmes') && preview.fields.image_prompt.includes('Sherlock Holmes')
        && !['history', 'family', 'friends', 'goals', 'personality', 'looks'].some((key) => Object.hasOwn(preview.fields, key))
        && preview.fields.meta.includes('Âge: 35')
        && (await http('/p/sherlock-holmes')).status === 404, 'assistant : la relecture contient les deux prompts et ne crée pas la fiche');
    const fields = { ...preview.fields, tagline: 'Détective relu',
        system_prompt: 'Tu es Sherlock Holmes. Watson relit tes notes. Tu parles avec précision.', image_prompt: '' };
    const c = JSON.parse(await post('/assist/create', { fields: JSON.stringify(fields) }));
    expect(c.ok && c.redirect === '/p/sherlock-holmes', 'assistant : fiche créée');
    const page = await record('GET /p/sherlock-holmes', await http('/p/sherlock-holmes'));
    expect(page.includes(fields.system_prompt) && page.includes(fields.tagline) && page.includes('Personnage d’origine')
        && !page.includes('Mycroft, son frère.') && !page.includes('Résoudre les affaires')
        && !page.includes(version.draft.looks)
        && !page.includes(preview.fields.image_prompt), 'assistant : les corrections de relecture et le retrait du prompt image sont enregistrés');
    const additions = JSON.parse(await post('/assist/preview', { slug: 'sherlock-holmes' }, '/assist/preview (compléter)'));
    expect(additions.ok && !Object.hasOwn(additions.fields, 'name') && !Object.hasOwn(additions.fields, 'description')
        && !Object.hasOwn(additions.fields, 'system_prompt') && Object.hasOwn(additions.fields, 'image_prompt')
        && !Object.hasOwn(additions.fields, 'friends'),
        'assistant : la relecture d’une fiche existante ne propose que les ajouts autorisés');
    await post('/p/sherlock-holmes/delete');
}
const testLlmBase = `http://127.0.0.1:${llmPort}`;
await post('/settings', { provider: 'custom', base_url: testLlmBase + '/remote/v1', model: 'fake-model', api_key: 'llm-test-secret' });
await post('/settings/chat', { chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`, chat_model: 'fake-model',
    chat_api_key: 'lms-test-token',
    chat_memory_threshold: '600', chat_memory_target: '300', chat_split_words: '50', chat_split_max_bubbles: '1',
    facts_frequency: 'each', chat_title_model: 'chat', chat_user_profile_enabled: '1', chat_user_profile: 'Je préfère les voyages en train.' });
const profileSettings = await (await http('/settings')).text();
expect(/name="api_key"[^>]*>\s*<small>\s*[^<]+<code>llm-\*{8}cret<\/code>/.test(profileSettings)
    && /name="chat_api_key"[^>]*>\s*<small>[^<]+<code>lms-\*{8}oken<\/code>/.test(profileSettings)
    && /Clé API Brave Search<\/h3>\s*<p class="hint">[^<]+<code>test\*{8}-key<\/code>/.test(profileSettings)
    && !['llm-test-secret', 'lms-test-token', 'test-search-key'].some((secret) => profileSettings.includes(secret)),
    'les réglages montrent uniquement un aperçu des clés IA, LM Studio et Brave Search');
expect(/name="chat_user_profile_enabled"[^>]*\bchecked/.test(profileSettings)
    && profileSettings.includes('id="chat-user-profile-field" data-show-if="chat_user_profile_enabled=1">')
    && profileSettings.includes('Je préfère les voyages en train.'), 'la présentation globale est enregistrée et affichée quand elle est activée');

{
    const ping = (path, form = {}, origin = base) => http(path, { method: 'POST', form: { _token: csrf, ...form }, origin });
    expect(['/settings/test', '/settings/chat/test', '/settings/brave/test'].every((path) =>
        profileSettings.includes(`data-connection-test="${path}"`)), 'les réglages proposent les tests des services configurés');
    const chat = { chat_base_url: testLlmBase + '/v1', chat_model: 'fake-model' };
    const remote = await ping('/settings/test', { base_url: testLlmBase + '/remote/v1', model: 'fake-model' });
    expect(remote.headers.get('cache-control') === 'no-store' && (await remote.json()).ok,
        'le fournisseur IA répond avec la clé enregistrée');
    expect((await (await ping('/settings/chat/test', chat)).json()).ok, 'LM Studio répond avec le jeton enregistré');
    const refused = await (await ping('/settings/chat/test', { ...chat, chat_api_key: 'jeton-refuse-secret' })).text();
    const exchanged = await (await http('/settings/logs')).text();
    expect(refused.includes('401') && !refused.includes('jeton-refuse-secret') && !exchanged.includes('jeton-refuse-secret'),
        'un fournisseur qui réaffiche le jeton refusé ne le divulgue ni dans la réponse ni dans le journal');
    const otherUrl = await ping('/settings/chat/test', { ...chat, chat_base_url: testLlmBase + '/V1' });
    expect(otherUrl.status === 400 && (await otherUrl.text()).includes('Adresse différente'),
        'le jeton enregistré reste lié à l’adresse configurée, casse du chemin comprise');
    const redirect = await ping('/settings/chat/test', { ...chat, chat_base_url: testLlmBase + '/redirect/v1', chat_api_key: 'lms-test-token' });
    expect(redirect.status === 400 && (await redirect.text()).includes('307'), 'le test ne suit pas une redirection du fournisseur');
    expect((await (await ping('/settings/brave/test', { url: testLlmBase + '/redirect/v1' })).json()).ok,
        'Brave Search est testé avec la clé et l’adresse côté serveur');
    expect((await ping('/settings/brave/test', { _token: 'invalide' })).status === 419, 'le diagnostic exige un jeton CSRF valide');
    if (peer) {
        const mcpPage = await (await http('/settings', { origin: peer })).text();
        expect(mcpPage.includes('data-connection-test="/settings/mcp/test"')
            && (await (await ping('/settings/mcp/test', {}, peer)).json()).ok,
        'le test MCP exécute l’outil avec le jeton LM Studio enregistré');
    }
}

{
    const chatPost = (path, form = {}, options = {}) => http(path, { method: 'POST', form: { _token: csrf, ...form }, ...options });
    const details = await (await http('/p/camille-lefebvre')).text();
    expect(details.includes('href="/chat/1" aria-labelledby="act-chat"') && !details.includes('name="persona_id"'),
        'la fiche reprend la conversation et ne propose pas de créer un autre fil');
    expect(!(await (await http('/chat')).text()).includes('class="conv-list contact-list"'),
        'la présentation par titre est active par défaut');
    await chatPost('/settings/ui', { ui_chat_list: 'contacts' });
    const saved = await (await http('/settings')).text();
    expect(saved.includes('value="contacts" selected'), 'le mode carnet reste sélectionné après enregistrement');

    const remembered = await (await chatPost('/chat/1/remember')).json();
    expect(remembered.memories.some((memory) => memory.subject === 'story' && memory.content.includes('exposition à Lyon')),
        'la mémoire conserve les projets du persona avec une source dans la conversation');
    const first = await (await chatPost('/chat/2/opening')).json();
    expect(first.message.content === 'J’ai retrouvé une vieille pellicule.' && first.questions[0].includes('photograph'),
        'la première rencontre utilise le message importé et propose une question liée au persona');
    const chatPage = await (await http('/chat/1')).text();
    expect(chatPage.includes('data-new-chat') && chatPage.includes('data-fresh="Repartir de zéro"')
        && chatPage.includes('data-continue="Continuer avec les souvenirs"'),
        'le bouton de rédaction propose les deux modes de création');
    const newChat = await chatPost('/chat/1/new', { mode: 'with-memory' });
    const path = newChat.headers.get('location');
    expect(newChat.status === 303 && /^\/chat\/\d+$/.test(path) && path !== '/chat/1',
        'le bouton du chat crée un fil distinct avec le même persona');
    const results = await Promise.all([
        chatPost(path + '/opening'),
        ...(peer ? [chatPost(path + '/opening', {}, { origin: peer })] : []),
    ]);
    const successful = results.find((result) => result.ok);
    const opened = await successful.json();
    expect(opened.message.content.includes('Lisbonne') && opened.questions[0].includes('exposition à Lyon'),
        'le persona ouvre sur un échange précédent et propose une question qui réutilise son histoire');
    const again = await (await chatPost(path + '/opening')).json();
    expect(again.messages.length === 1 && again.messages[0].id === opened.message.id && again.questions.length === 1,
        'réouvrir la même conversation garde un seul premier message et les mêmes questions');
    const refreshed = await (await http('/p/camille-lefebvre')).text();
    expect(refreshed.includes(`href="${path}" aria-labelledby="act-chat"`),
        'la fiche reprend le nouveau fil lorsqu’il devient le plus récent');
    await chatPost('/chat/1/pin', { pinned: '1' });
    const contacts = await (await http('/chat')).text();
    const camille = contacts.match(/<article[^>]*data-contact="1">([\s\S]*?)<\/article>/g) || [];
    expect(camille.length === 1 && camille[0].includes('Camille Lefebvre</strong>')
        && camille[0].includes(`class="conv-card contact-open" href="${path}"`)
        && camille[0].includes('class="conv-card" href="/chat/1"')
        && camille[0].includes(`class="conv-card" href="${path}"`) && !contacts.includes('data-open-picker'),
        'le carnet regroupe les deux fils de Camille et ouvre le plus récent même si l’ancien est épinglé');
    const sidebar = (await (await http('/chat/1')).text()).split('</aside>')[0];
    expect((sidebar.match(/Camille Lefebvre<\/strong>/g) || []).length === 1 && sidebar.includes(`is-on" href="${path}"`),
        'le carnet latéral affiche une seule entrée pour Camille même depuis son ancien fil');
    const privateChat = (await chatPost('/chat/1/new', { mode: 'fresh' })).headers.get('location');
    const privatePage = await (await http(privateChat)).text();
    expect(!/data-facts-toggle[^>]*\bchecked\b/.test(privatePage)
        && /name="memory_enabled"[^>]*\bchecked\b/.test(privatePage),
        'le fil neuf désactive les souvenirs partagés et garde la mémoire du fil');
    const privateOpening = await (await chatPost(privateChat + '/opening')).json();
    expect(!privateOpening.message.content.includes('Lisbonne') && !privateOpening.questions.some((q) => q.includes('Lyon')),
        'une ouverture sans souvenirs ne s’appuie ni sur les anciens fils ni sur l’histoire mémorisée');
    const splitSettings = {
        chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`, chat_model: 'fake-model',
        chat_split_enabled: '1', chat_split_words: '15', chat_split_max_bubbles: '2',
        chat_split_delay_min: '12.5', chat_split_delay_max: '16',
    };
    for (const position of ['before', 'after']) {
        await chatPost('/settings/chat', { ...splitSettings, chat_split_position: position });
        if (position === 'before') {
            const settings = await (await http('/settings')).text();
            const chat = await (await http(privateChat)).text();
            expect(settings.includes('name="chat_split_delay_min" min="0.5" max="16"')
                && settings.includes('name="chat_split_delay_max" min="0.5" max="16"')
                && chat.includes('data-split-delay-min="12.5"') && chat.includes('data-split-delay-max="16"'),
                'le réglage de pause accepte 16 secondes et transmet la plage enregistrée à la discussion');
        }
        const stream = await (await chatPost(privateChat + '/stream', { message: 'Test du seuil de découpage' })).text();
        const reply = stream.split('\n').filter((line) => line.startsWith('data: '))
            .map((line) => JSON.parse(line.slice(6))).find((event) => event.done)?.reply;
        const expectedStart = position === 'after' ? 'Dernière phrase.' : 'suite suite';
        expect(reply?.split_points?.length === 1 && reply.content.slice(reply.split_points[0]).startsWith(expectedStart),
            'le flux enregistre le point de coupe choisi ' + position);
        if (position === 'after' && reply?.split_points?.length === 1) {
            const react = async (bubble, reaction) => (await chatPost(privateChat + '/react', { mid: reply.id, bubble, reaction })).json();
            const first = await react(0, 'like');
            const second = await react(1, 'heart');
            expect(first.ok && second.ok && !first.reply && !second.reply,
                'les réactions de chaque bulle sont enregistrées sans réponse automatique');
            const load = async () => (await (await http(privateChat + '/messages')).json()).messages.find((m) => m.id === reply.id);
            expect((await load())?.bubble_reactions?.[1] === 'heart' && (await load())?.bubble_reactions?.[0] === 'like',
                'les réactions aux deux bulles survivent au rechargement');
            const invalid = await chatPost(privateChat + '/react', { mid: reply.id, bubble: 2, reaction: 'angry' });
            expect(invalid.status === 409, 'une bulle inexistante ne peut pas recevoir de réaction');
            expect(!(await react(0, '')).reply && !(await load())?.bubble_reactions?.[0]
                && (await load())?.bubble_reactions?.[1] === 'heart', 'retirer une réaction préserve celle de la bulle voisine');
            const regen = (await (await chatPost(privateChat + '/stream', { regen: '1', regen_reply: reply.id })).text())
                .split('\n').filter((line) => line.startsWith('data: ')).map((line) => JSON.parse(line.slice(6))).find((event) => event.done);
            const archived = await (await chatPost(privateChat + '/version', { mid: regen?.reply?.id, dir: -1 })).json();
            expect(archived.bubble_reactions?.[1] === 'heart' && !archived.bubble_reactions?.[0],
                'le changement de version restaure les réactions de la bonne bulle');
        }
    }
    await chatPost('/settings/chat', {
        ...splitSettings, chat_split_enabled: '', chat_split_words: '50', chat_split_max_bubbles: '1',
        chat_split_delay_min: '1', chat_split_delay_max: '2',
        chat_memory_threshold: '600', chat_memory_target: '300', facts_frequency: 'each',
        chat_title_model: 'chat', chat_user_profile_enabled: '1', chat_user_profile: 'Je préfère les voyages en train.',
    });
    await chatPost('/settings/ui', { ui_chat_list: 'threads' });
}

{
    const chatPost = (path, form = {}) => http(path, { method: 'POST', form: { _token: csrf, ...form } });
    const turn = async (path, message) => (await (await chatPost(path + '/stream', { message })).text())
        .split('\n').filter((line) => line.startsWith('data: '))
        .map((line) => JSON.parse(line.slice(6))).find((event) => event.done);
    const profile = '/p/camille-lefebvre/relationship';
    const path = (await chatPost('/chat/1/new', { mode: 'with-memory' })).headers.get('location');
    const request = (message) => turn(path, message);
    const analyze = () => chatPost(path + '/relationship');
    const greeting = await request('Vous pouvez me tutoyer, s’il vous plaît ?');
    expect(greeting?.relationship_pending,
        'un échange terminé déclenche le traitement de la relation');
    expect((await (await http(profile)).text()).includes('value="tu" selected'), 'la demande de tutoiement est mémorisée immédiatement');
    const mark = (reaction) => chatPost(path + '/react', {
        mid: greeting.reply.id, version_id: greeting.reply.version_id, reaction,
    });
    const reaction = await (await mark('angry')).json();
    expect(reaction.reaction === 'angry' && reaction.reply === false, 'la réaction est conservée sans réponse automatique');
    await analyze();
    const analysisLogs = await (await http('/settings/logs')).text();
    expect(analysisLogs.includes('reactions_utilisateur') && analysisLogs.includes('😡 — colère ou frustration'),
        'l’analyse relationnelle reçoit la réaction et sa bulle');
    expect(!(await (await http(profile)).text()).includes('En cours'), 'une réaction isolée n’ouvre pas de désaccord');
    await request('Je suis fâché à propos de la photo.');
    const chatLogs = await (await http('/settings/logs')).text();
    expect(chatLogs.includes('Réactions récentes dans cette conversation') && chatLogs.includes('😡 — colère ou frustration'),
        'le tour écrit suivant reçoit le ton de la réaction');
    await mark('');
    await analyze();
    let page = await (await http(profile)).text();
    expect(page.includes('Désaccord à propos de la photo') && page.includes('En cours') && page.includes('?message='),
        'une dispute ajoute un désaccord actif et un lien vers le message');
    await request('On en a parlé et on est réconciliés.');
    await analyze();
    page = await (await http(profile)).text();
    expect(page.includes('Désaccord à propos de la photo') && page.includes('Résolu') && !page.includes('En cours'),
        'la réconciliation ferme le désaccord automatiquement');
    const next = (await chatPost(path + '/new', { mode: 'with-memory' })).headers.get('location');
    await turn(next, 'On reparle de la photo ?');
    const logs = await (await http('/settings/logs')).text();
    expect(logs.includes('Tu tutoies toujours cet interlocuteur')
        && logs.includes('Désaccord clos, sans distance ni tension persistante'),
        'le nouveau fil reçoit le tutoiement et la fin de la distance');
    await chatPost(profile, { operation: 'settings', address: 'vous' });
    expect(!(await request('Une dernière chose.'))?.relationship_pending
        && (await (await http(profile)).text()).includes('value="vous" selected'),
        'le réglage manuel s’applique et la désactivation suspend les analyses');
    await chatPost(profile, { operation: 'settings', address: '', automatic: '1' });
}

const sse = await post('/chat/1/stream', { message: 'Bonjour, test du flux' });
expect(sse.includes('"delta"') && sse.includes('"done"'), 'la réponse arrive en streaming jusqu’au bout');
expect((await (await http('/settings/logs')).text()).includes('Je préfère les voyages en train.'),
    'la présentation activée arrive dans la requête au modèle');
// régénérer deux fois : l'ancienne réponse est gardée, on navigue entre les versions (‹ 2/2 ›)
const regen1 = await post('/chat/1/stream', { regen: '1' }, '/chat/1/stream (régénérer)');
expect(/"versions":\{"index":2,"total":2\}/.test(regen1), 'la régénération garde l’ancienne réponse comme version 1/2');
const vid = (regen1.match(/"reply":\{"id":(\d+)/) || [])[1];
const vprev = await post('/chat/1/version', { mid: vid, dir: '-1' }, '/chat/1/version (précédente)');
expect(vprev.includes('"index":1') && vprev.includes('"total":2'), 'on revient à la version 1/2');
if (peer) {
    const postChat = (action, form, options) => http('/chat/1/' + action, {
        method: 'POST', form: { _token: csrf, ...form }, ...options,
    });
    const messages = async (generation = false) => (await http(
        '/chat/1/messages?limit=10' + (generation ? '&generation=1' : ''), { origin: peer }
    )).json();
    const currentReply = (d) => d.messages.findLast((m) => m.role === 'assistant');
    const modelControl = 'http://127.0.0.1:' + llmPort + '/test/next-stream';
    const startRegen = async (mode, replyId, signal) => {
        await fetch(modelControl, { method: 'POST', body: mode });
        const r = await postChat('stream', { regen: '1', regen_reply: replyId }, { signal });
        return r.body.pipeThrough(new TextDecoderStream()).getReader();
    };
    const readUntil = async (reader, marker) => {
        let seen = '';
        while (!seen.includes(marker)) {
            const { value, done } = await reader.read();
            if (done) break;
            seen += value;
        }
        expect(seen.includes(marker), 'le flux contient ' + marker);
        return seen;
    };
    const waitForStop = async () => {
        for (let i = 0; i < 45; i++) {
            const d = await messages(true);
            if (!d.generating) return d;
            await new Promise((resolve) => setTimeout(resolve, 150));
        }
        throw new Error('Le flux interrompu ne libère pas le verrou de la discussion.');
    };

    const old = currentReply(await messages(true));
    const failing = await startRegen('fail', old.id);
    await readUntil(failing, '"delta"');
    expect(currentReply(await messages()).id === old.id, 'l’ancienne réponse reste visible pendant la régénération');
    const concurrent = await postChat('stream', { message: 'Envoi concurrent' }, { origin: peer });
    expect(concurrent.status === 409, 'un second worker refuse l’envoi concurrent');
    await concurrent.json();
    await readUntil(failing, '"error"');
    const afterFailure = await waitForStop();
    expect(!afterFailure.messages.some((m) => m.content === 'Envoi concurrent'), 'l’envoi refusé ne crée aucun message');
    expect(JSON.stringify(currentReply(afterFailure)) === JSON.stringify(old), 'un échec après un delta préserve la version sélectionnée et son identifiant');

    const cut = await startRegen('cut', old.id);
    await readUntil(cut, 'Le flux du modèle a été coupé');
    expect(JSON.stringify(currentReply(await waitForStop())) === JSON.stringify(old),
        'une fin de flux sans marqueur ne valide pas la réponse partielle');

    const silentAbort = new AbortController();
    const silent = await startRegen('silent', old.id, silentAbort.signal);
    await readUntil(silent, '"start"');
    for (let i = 0; i < 50; i++) {
        if (await (await fetch(modelControl)).text() === '') break;
        if (i === 49) throw new Error('Le faux modèle n’a pas reçu la régénération silencieuse.');
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    silentAbort.abort();
    expect(JSON.stringify(currentReply(await waitForStop())) === JSON.stringify(old), 'un arrêt sans texte préserve la version sélectionnée');

    const partialAbort = new AbortController();
    const partial = await startRegen('partial', old.id, partialAbort.signal);
    await readUntil(partial, '"delta"');
    const rename = await http('/chat/2/rename', {
        method: 'POST', form: { _token: csrf, title: 'Pellicule retrouvée' }, origin: peer,
    });
    expect(rename.headers.get('location') === '/chat/2', 'un autre worker écrit pendant le streaming');
    partialAbort.abort();
    const saved = currentReply(await waitForStop());
    expect(saved.id !== old.id && saved.content === 'Début de la nouvelle réponse.'
        && saved.versions?.index === 3 && saved.versions?.total === 3, 'un arrêt après un delta enregistre une nouvelle version navigable (' + JSON.stringify(saved) + ')');

    const stale = await postChat('stream', { regen: '1', regen_reply: old.id }, { origin: peer });
    expect((await stale.text()).includes('"kind":"regen"'), 'un onglet périmé ne régénère pas une réponse remplacée');
    const retry = await postChat('stream', { regen: '1', regen_reply: saved.id }, { origin: peer });
    expect((await retry.text()).includes('"versions":{"index":4,"total":4}'), 'la génération peut reprendre après un arrêt');
}
{
    const chat = (id, action, form) => http(`/chat/${id}/${action}`, {
        method: 'POST', form: { _token: csrf, ...form },
    });
    const recent = async (id) => (await (await http(`/chat/${id}/messages?limit=50`)).json()).messages;
    const turn = (sse) => sse.split('\n').filter((line) => line.startsWith('data: '))
        .map((line) => JSON.parse(line.slice(6))).find((event) => event.done);
    const react = async (mid, reaction, version_id = 0) => (await chat(1, 'react', { mid, reaction, version_id })).json();

    const previous = await recent(1);
    const target = previous.find((m) => m.id === 2);
    const otherBefore = await recent(2);
    const wrongChat = await chat(2, 'react', { mid: target.id, reaction: 'angry' });
    expect(wrongChat.status === 409, 'une réaction ne peut pas viser le message d’une autre discussion');
    const angry = await react(target.id, 'angry');
    const legacy = await (await chat(1, 'stream', {
        reaction_mid: target.id, reaction: 'angry', reaction_revision: angry.revision ?? 'ancienne-revision',
    })).text();
    const after = await recent(1);
    expect(angry.ok && !angry.reply && turn(legacy)?.skipped && !legacy.includes('"delta"')
        && after.length === previous.length && after.find((m) => m.id === target.id)?.reaction === 'angry'
        && JSON.stringify(await recent(2)) === JSON.stringify(otherBefore),
        'une réaction à un ancien message ne relance pas le persona, même avec un ancien client');
    const like = await react(target.id, 'like');
    const afterLike = await recent(1);
    expect(like.ok && !like.reply && afterLike.length === previous.length && afterLike.find((m) => m.id === target.id)?.reaction === 'like',
        'remplacer une réaction ne crée aucun message');
    expect(!(await react(target.id, '')).reply && !(await recent(1)).find((m) => m.id === target.id)?.reaction,
        'retirer une réaction ne déclenche pas une nouvelle réponse');

    const practical = await (await chat(2, 'stream', { message: 'On se parle à 18 h ?' })).text();
    expect(!practical.includes('[reaction:') && !turn(practical)?.user_reaction
        && turn(practical)?.reply?.content === 'D’accord, à 18 h !', 'un échange pratique peut rester sans émoji ni marqueur visible');
    const kindness = { message: 'Merci, tu as été adorable avec moi.' };
    const first = await (await chat(1, 'stream', kindness)).text();
    const second = await (await chat(1, 'stream', kindness)).text();
    const userReaction = turn(first)?.user_reaction;
    expect(userReaction?.reaction === 'heart' && turn(first)?.finish_reason === 'length'
        && !first.includes('[re') && !second.includes('[re') && !turn(second)?.user_reaction
        && turn(first)?.reply?.content === 'Ça me touche. Hier soir, j’ai vraiment'
        && (await recent(1)).find((m) => m.id === userReaction.id)?.reaction === 'heart',
        'la réaction du personnage survit à une réponse tronquée, reste invisible dans le texte et respecte le délai');
    const last = turn(second)?.reply;
    if (last) {
        await react(last.id, 'heart');
        const regen = turn(await (await chat(1, 'stream', { regen: '1', regen_reply: last.id })).text());
        const old = await (await chat(1, 'version', { mid: regen?.reply?.id, dir: '-1' })).json();
        expect(old.reaction === 'heart' && old.versions?.index === 1 && old.version_id > 0,
            'la réaction reste associée à la version d’origine après régénération');
    }
}
await record('GET /chat/1/load-result', await http('/chat/1/load-result'));
await post('/chat/1/model-override', { model: 'fake-model' });
await post('/chat/1/model-override', { model: '' }, '/chat/1/model-override (retour persona)');
await post('/chat/1/pin', { pinned: '1' });
await post('/chat/1/pin', { pinned: '0' }, '/chat/1/pin (retirer)');
const beforeEdit = await (await http('/chat/1')).text();
expect(beforeEdit.includes('Sujet : Voyage'), 'un résumé existe avant la modification d’un message ancien');
const edit = await http('/chat/1/msg-edit', {
    method: 'POST', form: { _token: csrf, mid: '3', content: 'Correction : le train va à Lyon.' },
});
expect((await edit.json()).replay === true, 'un message ancien modifié peut être rejoué');
const replay = await http('/chat/1/stream', { method: 'POST', form: { _token: csrf, regen: '1', regen_mid: '3' } });
expect((await replay.text()).includes('"done"'), 'la réponse est régénérée après la correction');
const afterEdit = await (await http('/chat/1')).text();
expect(afterEdit.includes('Correction : le train va à Lyon.') && !afterEdit.includes('Sujet : Voyage')
    && !afterEdit.includes('Réponse 12'), 'la discussion reprend sur le message corrigé sans le résumé ni la suite obsolètes');
await post('/chat/1/msg-delete', { mid: '1' });
await post('/settings/test');
await post('/p/smoke-test/duplicate');
const copy = await record('GET /p/smoke-test-copie', await http('/p/smoke-test-copie'));
expect(copy.includes('Smoke Test (copie)') && copy.includes('un prompt'), 'la copie reprend la fiche et ses prompts');
await post('/p/smoke-test-copie/delete');
// une copie a ses propres fichiers photo (rien de partagé avec l'original)
const mediaOf = (html) => new Set(html.match(/p[0-9a-f]{16}/g) || []);
const origHtml = await (await http('/p/camille-lefebvre')).text();
await post('/p/camille-lefebvre/duplicate');
const copyHtml = await record('GET /p/camille-lefebvre-copie', await http('/p/camille-lefebvre-copie'));
const shared = [...mediaOf(copyHtml)].filter((f) => mediaOf(origHtml).has(f));
expect(mediaOf(copyHtml).size > 0 && shared.length === 0, 'la copie a ses propres fichiers photo');
const copyMedia = [...mediaOf(copyHtml)][0];
await record('GET /media/(photo de la copie)', await http('/media/' + copyMedia + '.thumb.webp'));
await post('/p/camille-lefebvre-copie/delete');
await record('GET /media/(photo de l’original après suppression de la copie)', await http('/media/' + [...mediaOf(origHtml)][0] + '.thumb.webp'));
await post('/p/smoke-test/delete');
const zipResponse = await http('/backup/export.zip?conv=1');
await record('GET /backup/export.zip?conv=1', zipResponse);
const backupForm = new FormData();
backupForm.append('_token', csrf);
backupForm.append('archive', await zipResponse.blob(), 'personavault.zip');
const zipImport = await fetch(base + '/backup/import', {
    method: 'POST', body: backupForm, redirect: 'manual', headers: { cookie: cookieHeader() },
});
await record('POST /backup/import', zipImport);
const restoredSettings = await (await http('/settings')).text();
expect(/Discussions restaurées \([1-9]\d*\), messages \([1-9]\d*\), souvenirs \([1-9]\d*\)/.test(restoredSettings),
    'la restauration ZIP affiche le nombre de discussions, messages et souvenirs importés');
{
    const submit = (path, fields) => http(path, { method: 'POST', form: { _token: csrf, ...fields } });
    const turn = async (id, level) => {
        const response = await submit(`/chat/${id}/stream`, { message: 'Test du réglage de réflexion' });
        return (await response.text()).includes(`Niveau reçu : ${level}.`);
    };
    await submit('/p/camille-lefebvre', { name: 'Camille Lefebvre', reasoning_effort: 'medium' });
    expect(await turn(1, 'medium'), 'la discussion hérite du niveau de réflexion du persona');
    await submit('/chat/1/reasoning', { reasoning_effort: 'high' });
    expect(await turn(1, 'high'), 'la discussion peut augmenter son niveau de réflexion');
    await submit('/chat/1/reasoning', { reasoning_effort: 'none' });
    expect(await turn(1, 'none'), 'la discussion peut désactiver la réflexion du persona');
    await submit('/chat/1/reasoning', { reasoning_effort: '' });
    expect(await turn(1, 'medium') && await turn(2, 'none'), 'le retour à l’héritage respecte les autres personas');
}
{
    const submit = (path, fields) => http(path, { method: 'POST', form: { _token: csrf, ...fields } });
    const celebrate = async (path = '/chat/1') => {
        const response = await submit(path + '/stream', { message: 'Je fête cette réussite' });
        return (await response.text()).split('\n').filter(line => line.startsWith('data: '))
            .map(line => JSON.parse(line.slice(6))).find(event => event.done)?.reply;
    };
    const testKlipy = async (key = '') => (await submit('/settings/klipy/test', { klipy_api_key: key })).json();
    expect(!(await testKlipy()).ok, 'le test KLIPY signale une clé manquante');
    expect((await testKlipy('test-gif-key')).ok && !(await testKlipy()).ok,
        'on peut tester une clé saisie sans l’enregistrer');
    const saved = await submit('/settings/chat', {
        chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`,
        chat_model: 'fake-model', klipy_api_key: 'test-gif-key',
    });
    expect(saved.headers.get('location') === '/settings#chat'
        && /data-connection-test="\/settings\/klipy\/test"[^>]*>\s*Tester la clé enregistrée\s*<\/button>/.test(await (await http('/settings')).text()),
        'après enregistrement, les réglages Discussion proposent de tester la clé enregistrée');
    const rejected = await testKlipy('rejected-test-key');
    expect(!rejected.ok && rejected.message.includes('401') && !rejected.message.includes('rejected-test-key')
        && (await testKlipy()).ok, 'le test signale le refus et préserve la clé enregistrée');
    await submit('/p/camille-lefebvre', { name: 'Camille Lefebvre', klipy_formats_present: '1' });
    expect((await celebrate())?.klipy_url === '', 'le persona doit autoriser les GIF spontanés');
    await submit('/p/camille-lefebvre', { name: 'Camille Lefebvre', klipy_formats_present: '1', 'klipy_formats[]': 'gif' });
    const animated = await celebrate();
    const gifUrl = 'https://static.klipy.com/test/celebrate.gif';
    expect(animated?.klipy_url === gifUrl, 'le GIF KLIPY accompagne la réponse en direct');
    expect((await (await http('/chat/1')).text()).includes(gifUrl), 'le GIF est restauré au rechargement');
    expect((await celebrate())?.klipy_url === '', 'les GIF spontanés sont espacés');
    const requestGif = async () => (await (await submit('/chat/1/stream', { message: 'Envoie-moi un GIF avec un clin d’œil' })).text());
    const explicit = await requestGif();
    const explicitEvents = explicit.split('\n').filter(line => line.startsWith('data: ')).map(line => JSON.parse(line.slice(6)));
    expect(!explicitEvents.map(event => event.delta || '').join('').includes('[G')
        && explicitEvents.find(event => event.done)?.reply?.klipy_url === 'https://static.klipy.com/test/wink.gif',
        'une demande explicite affiche un vrai GIF même pendant le délai, sans description dans le flux');
    expect(/<figure class="bubble chat-klipy">\s*<img src="https:\/\/static\.klipy\.com\/test\/wink\.gif"/.test(await (await http('/chat/1')).text()),
        'le GIF demandé est conservé dans sa propre bulle');
    const recent = await (await http('/chat/1/messages')).json();
    const mid = recent.messages.filter(m => m.role === 'user').at(-1).id;
    const edited = await (await submit('/chat/1/msg-edit', { mid, content: "Tu m'envoies un petit gif" })).json();
    const replay = await (await submit('/chat/1/stream', { regen: '1', regen_mid: mid })).text();
    expect(edited.replay && replay.includes('Avec plaisir !') && !replay.includes('Je ne peux pas envoyer')
        && replay.replaceAll('\\/', '/').includes('https://static.klipy.com/test/hello.gif'),
        'la formulation naturelle déclenche KLIPY après modification du message et le modèle reçoit le résultat');
    await requestGif();
    const repeated = await requestGif();
    expect(repeated.replaceAll('\\/', '/').includes('https://static.klipy.com/test/wink-second.gif'),
        'la demande répétée passe à la page suivante pour trouver un GIF inédit');
    expect((await requestGif()).includes('KLIPY n’a trouvé aucun média'), 'une recherche épuisée ne renvoie pas de doublon');
    await submit('/chat/1/klipy', {});
    expect((await requestGif()).includes('Ce format KLIPY est désactivé'), 'une demande explicite signale un format désactivé');
    const stickerChat = (await submit('/chat/1/new', {})).headers.get('location');
    await submit(stickerChat + '/klipy', {});
    expect((await celebrate(stickerChat))?.klipy_url === '', 'décocher tous les formats désactive les médias de la discussion');
    await submit(stickerChat + '/klipy', { 'klipy_formats[]': 'sticker' });
    expect((await celebrate(stickerChat))?.klipy_url?.endsWith('.webp'), 'la discussion peut choisir les stickers à la place des GIF');
    const clipChat = (await submit('/chat/1/new', {})).headers.get('location');
    await submit(clipChat + '/klipy', { 'klipy_formats[]': 'clip' });
    expect((await celebrate(clipChat))?.klipy_url?.endsWith('.mp4')
        && /<video[^>]*controls[^>]*preload="none"/.test(await (await http(clipChat)).text()), 'les clips se lisent à la demande');
    const settings = await (await http('/settings')).text();
    expect(/name="klipy_api_key"[^>]*>\s*<small>[^<]+<code>test\*{8}-key<\/code>/.test(settings) && !settings.includes('test-gif-key'),
        'la clé KLIPY est identifiable sans exposer sa valeur complète');
}
{
    const submit = (path, fields) => http(path, { method: 'POST', form: { _token: csrf, ...fields } });
    const path = (await submit('/chat/1/new', {})).headers.get('location');
    const turn = async (message) => (await (await submit(path + '/stream', { message })).text())
        .split('\n').filter((line) => line.startsWith('data: ')).map((line) => JSON.parse(line.slice(6)));
    const first = await turn('Tu as des adresses à Toulon pour ce week-end ?');
    expect(!first.some((event) => event.phase === 'web_search'), 'une demande en attente de précision ne lance aucune recherche');
    const results = await turn('Italien et pizza');
    const searchAt = results.findIndex((event) => event.phase === 'web_search');
    const content = results.find((event) => event.done)?.reply?.content ?? '';
    expect(searchAt >= 0 && searchAt < results.findIndex((event) => event.delta)
        && content.includes('7 rue Exemple') && content.includes('https://example.com/pizzeria-toulon')
        && !content.includes('javascript:'), 'la précision reprend Toulon et le même tour répond à partir des résultats web');
    expect((await (await http(path)).text()).includes('https://example.com/pizzeria-toulon'),
        'les liens de la recherche restent dans la conversation');
    const failed = await turn('Vérifie les horaires à Toulon');
    expect(failed.some((event) => event.phase === 'web_search')
        && failed.find((event) => event.done)?.reply?.content === 'Je ne peux pas vérifier les horaires cette fois-ci.',
        'un échec du moteur laisse le persona répondre en signalant la vérification impossible');

    if (peer) {
        const mcpPath = (await submit('/chat/1/new', {})).headers.get('location');
        const mcpTurn = async (message) => (await (await http(mcpPath + '/stream', {
            method: 'POST', form: { _token: csrf, message }, origin: peer,
        })).text()).split('\n').filter((line) => line.startsWith('data: ')).map((line) => JSON.parse(line.slice(6)));
        await mcpTurn('Tu as des adresses à Toulon pour ce week-end ?');
        const mcpEvents = await mcpTurn('Italien et pizza');
        const reply = mcpEvents.find((event) => event.done)?.reply?.content ?? '';
        expect(mcpEvents.some((event) => event.phase === 'web_search') && reply.includes('7 rue Exemple')
            && reply.includes('https://example.com/mcp-toulon') && !reply.includes('invented'),
            'le chat local utilise le MCP LM Studio en priorité et conserve les liens réellement retournés');
    }
}
// --- recherche d'informations : SearXNG, Wikipédia, météo, ligne bleue, interrupteurs
{
    const submit = (path, fields) => http(path, { method: 'POST', form: { _token: csrf, ...fields } });
    const chatSettings = { chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`, chat_model: 'fake-model',
        chat_memory_threshold: '600', chat_memory_target: '300', chat_split_words: '50', chat_split_max_bubbles: '1',
        facts_frequency: 'each', chat_title_model: 'chat', chat_user_profile_enabled: '1', chat_user_profile: 'Je préfère les voyages en train.' };
    const searxng = `http://127.0.0.1:${llmPort}/searxng`;
    const webSettings = (extra) => submit('/settings/chat', { ...chatSettings, web_settings_present: '1', web_enabled: '1',
        web_searxng_url: searxng, web_wikipedia: '1', web_weather: '1', ...extra });
    const test = async (fields) => (await submit('/settings/searxng/test', fields)).json();
    expect((await test({ web_searxng_url: searxng })).ok, 'SearXNG : le test réussit avec l’adresse saisie');
    expect(/json/i.test((await test({ web_searxng_url: `http://127.0.0.1:${llmPort}/searxng-nojson` })).error || ''),
        'SearXNG : un format JSON non autorisé est expliqué');
    await webSettings({});
    const settingsPage = await (await http('/settings')).text();
    expect(settingsPage.includes(`value="${searxng}"`) && /name="web_weather"[^>]*\bchecked/.test(settingsPage),
        'les réglages de la recherche sont enregistrés');

    const path = (await submit('/chat/1/new', {})).headers.get('location');
    const turn = async (message) => (await (await submit(path + '/stream', { message })).text())
        .split('\n').filter((line) => line.startsWith('data: ')).map((line) => JSON.parse(line.slice(6)));
    const lineOf = (events) => events.find((event) => event.web)?.web;

    const meteo = await turn('Il fait quel temps à Toulon demain ?');
    const meteoLine = lineOf(meteo);
    const logs = await (await http('/settings/logs')).text();
    expect(meteo.some((event) => event.phase === 'web_search' && event.source === 'meteo')
        && meteoLine?.role === 'web' && meteoLine.content.includes('météo') && meteoLine.content.includes('Toulon')
        && meteo.findIndex((event) => event.web) < meteo.findIndex((event) => event.delta)
        && logs.includes('Météo à Toulon (France)') && logs.includes('Demain : pluie, 10 à 15 °C, pluie 80 %'),
        'météo : ligne de recherche avant la réponse, prévisions transmises au persona');

    const bakery = await turn('Trouve-moi une boulangerie ouverte à Toulon');
    expect(lineOf(bakery)?.content.includes('boulangerie ouverte Toulon')
        && (bakery.find((event) => event.done)?.reply?.content ?? '').includes('https://example.com/boulangerie-toulon'),
        'SearXNG : la recherche internet passe par SearXNG et garde le lien');

    const wiki = await turn('Qui était Sherlock Holmes ?');
    expect(lineOf(wiki)?.content.includes('Wikipédia')
        && (wiki.find((event) => event.done)?.reply?.content ?? '').includes('https://fr.wikipedia.org/wiki/'),
        'Wikipédia : la culture générale passe par Wikipédia');

    const page = await (await http(path)).text();
    expect((page.match(/class="msg-web"/g) || []).length === 3 && page.includes('boulangerie ouverte Toulon'),
        'les lignes de recherche restent affichées dans la discussion');

    await submit(path + '/toggles', { web_search_present: '1' });
    expect(!(await turn('Il fait quel temps à Toulon demain ?')).some((event) => event.phase === 'web_search' || event.web),
        'discussion : la recherche se coupe avec son interrupteur');
    await submit(path + '/toggles', { web_search_present: '1', web_search: '1' });

    await webSettings({ web_weather: '' });
    const noWeather = await turn('Il fait quel temps à Toulon demain ?');
    expect(!noWeather.some((event) => event.source === 'meteo'), 'réglages : une source décochée n’est plus proposée au modèle');

    await webSettings({ web_enabled: '' });
    expect(!(await turn('Trouve-moi une boulangerie ouverte à Toulon')).some((event) => event.web),
        'réglages : l’interrupteur général coupe toute recherche');
    // retour à l'état d'origine pour la suite du parcours
    await webSettings({ web_searxng_url: '', web_wikipedia: '', web_weather: '' });
}
{
    const modelControl = `http://127.0.0.1:${llmPort}/test/model-state`;
    const load = async (state) => {
        await fetch(modelControl, { method: 'POST', body: state });
        const started = await (await http('/chat/1/load', { method: 'POST', form: { _token: csrf } })).json();
        expect(started.ok && started.started, 'le chargement manuel démarre en arrière-plan');
        for (let i = 0; i < 60; i++) {
            const result = await (await http('/chat/1/load-result')).json();
            if (result.done) return result;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        throw new Error('Le chargement manuel ne renvoie pas son résultat.');
    };
    const refused = await load('refused');
    expect(!refused.ok && refused.error.includes('Permissions insuffisantes') && !refused.error.includes('lms-test-token'),
        'le chargement authentifié masque le jeton dans les erreurs renvoyées par LM Studio');
    expect((await load('unloaded')).ok, 'le chargement manuel transmet le jeton enregistré à LM Studio');
}
{
    const submit = (path, form) => http(path, { method: 'POST', form: { _token: csrf, ...form } });
    const path = (await submit('/chat/1/new', { mode: 'with-memory' })).headers.get('location');
    await submit('/settings/chat', { chat_provider: 'lmstudio', chat_base_url: testLlmBase + '/v1', chat_model: 'fake-model',
        chat_split_enabled: '1', chat_split_words: '8', chat_split_position: 'before', chat_split_max_bubbles: '2' });
    await submit('/memories', { content: 'Réserve toujours le train méridien en avance.' });
    const turn = async (conv, fields) => (await (await submit(conv + '/stream', fields)).text())
        .split('\n').filter((line) => line.startsWith('data: ')).map((line) => JSON.parse(line.slice(6)));
    const target = (await turn(path, { message: 'Le départ est fixé à 17 heures.' })).find((event) => event.done).reply;
    const quote = { reply_to: target.id, reply_bubble: '1', reply_version: target.version_id };
    expect(target.split_points.length === 1, 'le message du persona comporte deux bulles');
    expect((await turn('/chat/1', { message: 'Hors conversation', ...quote })).some((event) => event.kind === 'reply_source'),
        'une citation ne peut pas viser un message d’une autre discussion');
    const sent = await turn(path, { message: 'Oui, cite cette bulle.', ...quote, reply_text: 'Faux passage fourni par le client' });
    const saved = sent.find((event) => event.start)?.user?.reply_to;
    const answer = sent.find((event) => event.done)?.reply?.content ?? '';
    expect(saved?.id === target.id && saved.bubble === 1 && saved.text === 'Je te propose le train méridien pour ce voyage.'
        && answer.includes('son contexte et le souvenir sont disponibles'), 'la bulle exacte, les messages précédents et les souvenirs sont fournis au modèle');
    const listed = await (await http(path + '/messages')).json();
    const page = await (await http(path)).text();
    expect(listed.messages.some((message) => message.reply_to?.text === saved?.text)
        && page.includes(`data-quote-id="${target.id}" data-quote-bubble="1"`), 'la citation reste cliquable après rechargement');
    const regen = await turn(path, { regen: '1' });
    expect(regen.find((event) => event.done)?.reply?.content.includes('son contexte et le souvenir sont disponibles'),
        'la régénération recharge la citation et le contexte de la réponse');
    await submit(path + '/msg-delete', { mid: target.id });
    const afterDelete = await (await http(path + '/messages')).json();
    expect(afterDelete.messages.some((message) => message.reply_to?.text === saved?.text), 'la citation reste lisible après suppression de la source');
}
// --- discussions entre personas : menées en arrière-plan, humeurs et ressentis persistants
{
    const page = await http('/settings/duo');
    const html = await page.text();
    await record('GET /settings/duo', { status: page.status, headers: page.headers, text: async () => html });
    const id = (name) => (html.match(new RegExp('data-duo-pick="(\\d+)" data-name="' + name + '"')) || [])[1];
    const [a, b] = [id('Camille Lefebvre'), id('Hugo Bernard')];
    expect(a && b && html.includes('/media/'), 'la page liste les personas avec leur avatar');
    const chats = async () => ((await (await http('/chat')).text()).match(/href="\/chat\/\d+"/g) || []).length;
    const before = await chats();
    const create = async (form) => http('/settings/duo', { method: 'POST', form: { _token: csrf, a, b, topic: 'La campagne', ...form } });
    const sessionId = async (r) => ((r.headers.get('location') || '').match(/\/settings\/duo\/(\d+)$/) || [])[1];
    const state = async (sid, after = 0) => (await http(`/settings/duo/${sid}/state?after=${after}`)).json();
    const until = async (sid, ok, ms = 30000) => {
        const end = Date.now() + ms;
        let st = await state(sid);
        while (!ok(st) && Date.now() < end) {
            await new Promise((r) => setTimeout(r, 300));
            st = await state(sid);
        }
        return st;
    };
    const control = async (sid, op) => (await http(`/settings/duo/${sid}/control`, { method: 'POST', form: { _token: csrf, op } })).json();

    // une discussion complète, menée par le serveur sans que la page la demande
    const created = await create({ goal_b: 'Défendre la ville', turns: '5' });
    const sid = await sessionId(created);
    expect(created.status === 303 && sid, 'la discussion est créée et ouvre sa page');
    const livePage = await http('/settings/duo/' + sid);
    const liveHtml = await livePage.text();
    await record('GET /settings/duo/{id}', { status: livePage.status, headers: livePage.headers, text: async () => liveHtml });
    expect(liveHtml.includes('data-duo-bar') && liveHtml.includes('data-duo-op="pause"'), 'la page a sa barre de commandes');
    const done = await until(sid, (st) => st.status === 'done');
    const spoken = done.lines.filter((l) => l.kind === 'line');
    expect(done.status === 'done' && spoken.length === 5 && spoken.every((l, i) => l.speaker === i % 2),
        'le serveur mène seul la discussion jusqu’au bout, les deux voix en alternance');
    expect(done.people[0].mood.emotion === 'joie' && done.people[0].mood.color === '#facc15' && done.people[1].mood.emotion === 'colere'
        && done.lines.some((l) => l.kind === 'mood' && l.emotion === 'colere' && l.color === '#ef4444' && l.text === 'vexé'),
        'chaque humeur reçoit sa couleur de la palette, à la manière des Sims');
    expect(done.lines.some((l) => l.kind === 'mood' && l.text === 'joyeuse') && done.lines.some((l) => l.kind === 'event')
        && done.people[0].mood.mood === 'joyeuse' && done.people[0].bond.friendship === 20 && done.people[1].bond.anger === 12,
        'le maître du jeu met à jour humeurs et ressentis (variations bornées) et relance un échange qui piétine');
    const logs = await (await http('/settings/logs')).text();
    expect(logs.includes('Défendre la ville') && logs.includes('Tu échanges avec Camille Lefebvre')
        && logs.includes('[Maître du jeu : Un orage éclate dehors.]') && logs.includes('Ton humeur en ce moment : joie')
        && logs.includes('Lieu et situation en ce moment : Dans un café du centre-ville') && logs.includes('Commence et termine autrement') && logs.includes('Agis : si vous avez décidé')
        && logs.includes('ne lui donne jamais le prénom ou le surnom')
        && logs.includes('ton objectif reste « Défendre la ville »') && logs.includes('Objectifs des personnages')
        && logs.includes('gene (gêne)'),
        'chaque voix reçoit la consigne, son objectif, la relance et son humeur');
    expect(done.scene === 'Dans un café du centre-ville, un samedi soir' && done.lines.some((l) => l.kind === 'scene')
        && done.lines.filter((l) => l.kind === 'event').length === 1,
        'le maître du jeu fait évoluer la scène et ne répète pas une relance déjà proposée');
    const report = await http(`/settings/duo/${sid}/report`);
    const md = await report.text();
    expect(report.status === 200 && md.includes('**Hugo Bernard** : ') && md.includes('joyeuse'), 'le rapport reprend l’échange et les ressentis');

    // une nouvelle discussion entre les mêmes personas reprend humeurs et ressentis
    const again = await sessionId(await create({ turns: '40' }));
    const paused = await control(again, 'pause');
    expect(paused.status === 'paused' && paused.people[0].bond.friendship === 20, 'la discussion se met en pause ; les ressentis sont repris');
    const dir = await (await http(`/settings/duo/${again}/direction`, { method: 'POST', form: { _token: csrf, text: 'Hugo devient jaloux', target: '1' } })).json();
    expect(dir.ok && dir.lines.some((l) => l.kind === 'direction' && l.speaker === 1), 'la consigne au maître du jeu est enregistrée');
    const adjusted = await (await http(`/settings/duo/${again}/feelings`, { method: 'POST',
        form: { _token: csrf, who: '1', anger: '70', emotion: 'colere', intensity: '80' } })).json();
    expect(adjusted.ok && adjusted.people[1].bond.anger === 70 && adjusted.people[1].mood.emotion === 'colere'
        && adjusted.people[1].mood.intensity === 80 && adjusted.lines.some((l) => l.kind === 'adjust' && l.speaker === 1 && l.text.includes('70')),
        'les ressentis et l’humeur se règlent à la main et le réglage est noté dans le fil');
    const extended = await control(again, 'extend');
    expect(extended.target === 50 && extended.status === 'paused', 'la discussion peut être prolongée');
    await control(again, 'resume');
    const resumed = await until(again, (st) => st.count >= 2);
    expect(resumed.count >= 2, 'la reprise relance la discussion');
    const logs2 = await (await http('/settings/logs')).text();
    expect(logs2.includes('[Maître du jeu, pour toi seul : Hugo devient jaloux'), 'la consigne arrive dans le dernier message du persona visé');
    expect(logs2.includes('nouvelles valeurs, à respecter telles quelles') && logs2.includes('colère (forte)')
        && logs2.includes('phrases courtes et sèches'), 'le maître du jeu analyse le réglage et l’humeur réglée arrive dans le dernier message');
    const kept = await state(again);
    expect(kept.people[1].bond.anger === 70 && kept.lines.some((l) => l.kind === 'event'),
        'l’analyse respecte les valeurs réglées et les fait vivre par une relance');
    expect(kept.directions.length === 1 && kept.directions[0].target === 1, 'la consigne reste active tant qu’elle n’est pas retirée');
    const cleared = await (await http(`/settings/duo/${again}/direction-clear`, { method: 'POST', form: { _token: csrf, target: '1' } })).json();
    expect(cleared.ok && cleared.directions.length === 0 && cleared.lines.some((l) => l.kind === 'clear' && l.speaker === 1),
        'la consigne peut être retirée');
    const stopped = await control(again, 'stop');
    expect(stopped.status === 'stopped', 'la discussion peut être arrêtée');
    const list = await (await http('/settings/duo')).text();
    expect(list.includes(`href="/settings/duo/${again}"`), 'les discussions sont listées');

    const same = await create({ b: a });
    const forged = await http(`/settings/duo/${sid}/control`, { method: 'POST', form: { _token: 'mauvais', op: 'pause' } });
    expect(same.headers.get('location') === '/settings/duo' && forged.status === 419, 'deux personas différents et un jeton valide sont exigés');
    expect(await chats() === before, 'les discussions entre personas n’ajoutent rien aux conversations');
    const del = await http(`/settings/duo/${again}/delete`, { method: 'POST', form: { _token: csrf } });
    expect(del.status === 303 && (await http('/settings/duo/' + again)).status === 404, 'une discussion peut être supprimée');
}
// --- conversation : humeur et ressentis du persona, réglables
{
    const page = await (await http('/chat/1')).text();
    expect(page.includes('data-feel ') && page.includes('data-chat-mood'), 'la conversation affiche l’humeur et les ressentis');
    expect(page.includes('data-scene-time ') && page.indexOf('data-scene-time ') < page.indexOf('data-feel '),
        'le jour et l’heure se règlent juste avant les humeurs');
    const setScene = (form) => http('/chat/1/scene-time', { method: 'POST', ajax: true, form: { _token: csrf, ...form } });
    const scene = await (await setScene({ scene_day: '2', scene_time: '03:15' })).json();
    expect(scene.ok && scene.scene_day === 2 && scene.scene_time === '03:15', 'le jour et l’heure choisis sont enregistrés');
    expect((await setScene({ scene_day: '8', scene_time: '24:00' })).status === 422, 'un créneau invalide est refusé');
    const reloaded = await (await http('/chat/1')).text();
    expect(reloaded.includes('Mardi · 03:15'), 'le créneau forcé est conservé après rechargement');
    const set = await (await http('/chat/1/feelings', { method: 'POST', form: { _token: csrf, emotion: 'colere', intensity: '80', love: '40' } })).json();
    expect(set.ok && set.feelings.emotion === 'colere' && set.feelings.intensity === 80 && set.feelings.love === 40
        && set.notices.some((n) => n.text.includes('40')), 'humeur et ressentis se règlent à la main, avec une note dans le fil');
    const sent = await (await http('/chat/1/stream', { method: 'POST', form: { _token: csrf, message: 'Tu boudes ?' } })).text();
    const logs = await (await http('/settings/logs')).text();
    expect(sent.includes('"done"') && logs.includes('Ton humeur et tes sentiments dans cette conversation')
        && logs.includes('colère (forte)') && logs.includes('tu ressens une vraie attirance'),
        'l’humeur et les ressentis réglés arrivent dans le contexte de la réponse suivante');
    expect(logs.includes('Moment de la scène choisi pour cette conversation : mardi, 03h15.'),
        'le créneau forcé arrive dans le contexte de la réponse suivante');
    const resetScene = await (await setScene({ op: 'reset' })).json();
    expect(resetScene.ok && resetScene.scene_day === 0 && resetScene.scene_time === '', 'le bouton rétablit le jour et l’heure actuels');
    const forged = await http('/chat/1/feelings', { method: 'POST', form: { _token: 'mauvais', emotion: 'joie' } });
    expect(forged.status === 419, 'un jeton valide est exigé pour régler les ressentis');
}
{
    const send = async (path, message) => (await (await http(path + '/stream', {
        method: 'POST', form: { _token: csrf, message },
    })).text()).split('\n').filter((line) => line.startsWith('data: '))
        .map((line) => JSON.parse(line.slice(6))).find((event) => event.done)?.reply;
    const messages = async (path) => (await (await http(path + '/messages?limit=100')).json()).messages;
    const fresh = await http('/chat/2/new', { method: 'POST', form: { _token: csrf, mode: 'with-memory' } });
    const topicPath = fresh.headers.get('location');
    await send(topicPath, 'On reparlera du concours de cerfs-volants.');
    const analysis = await http(topicPath + '/remember', { method: 'POST', form: { _token: csrf, force: '1' } });
    expect((await analysis.json()).ok, 'l’analyse enregistre le sujet laissé en suspens');
    expect((await send(topicPath, 'Me revoilà.'))?.content.includes('passé au concours'), 'le persona reprend un sujet arrivé à échéance');
    const resumed = await http(topicPath + '/remember', { method: 'POST', form: { _token: csrf, force: '1' } });
    expect((await resumed.json()).ok, 'l’analyse traite la reprise du sujet');
    expect((await send(topicPath, 'Autre chose maintenant.'))?.content === 'Parlons de la suite.', 'le sujet repris ne revient pas au tour suivant');

    const listing = await (await http('/chat')).text();
    expect(!listing.includes('data-act="fork"'), 'la bifurcation se choisit depuis un message du fil');
    expect((await (await http(topicPath)).text()).includes('data-chat-fork'), 'le fil propose une autre suite depuis un message');
    const original = await messages(topicPath);
    const branchAt = original.find((message) => message.role === 'user' && message.content === 'Me revoilà.');
    const invalid = await http(topicPath + '/fork', { method: 'POST', form: { _token: csrf, mid: original.find((message) => message.role === 'assistant').id } });
    expect(invalid.headers.get('location') === topicPath, 'un message du persona ne peut pas servir de point de départ');
    await http(topicPath + '/memory', { method: 'POST', form: { _token: csrf, op: 'save', summary: 'Résumé de la suite originale à exclure.' } });
    const fork = await http(topicPath + '/fork', { method: 'POST', form: { _token: csrf, mid: branchAt.id } });
    const forkPath = fork.headers.get('location');
    const duplicate = await messages(forkPath);
    const signature = (rows) => rows.map(({ role, content }) => [role, content]);
    const prefix = original.filter((message) => message.id <= branchAt.id);
    expect(fork.status === 303 && forkPath !== topicPath && JSON.stringify(signature(duplicate)) === JSON.stringify(signature(prefix))
        && duplicate.every((message, i) => message.id !== prefix[i].id), 'la branche s’arrête exactement au message choisi');
    expect(!(await (await http(forkPath)).text()).includes('Résumé de la suite originale à exclure.'), 'le résumé de la suite ne passe pas dans la branche');
    const firstMessage = duplicate.find((message) => message.role === 'user');
    if (firstMessage) {
        const edit = await http(forkPath + '/msg-edit', { method: 'POST', form: { _token: csrf, mid: firstMessage.id, content: 'Une autre suite.' } });
        expect((await edit.json()).ok && JSON.stringify(await messages(topicPath)) === JSON.stringify(original),
            'modifier un ancien message de la branche préserve le fil d’origine');
    }
}
{
    const fresh = await http('/chat/2/new', { method: 'POST', form: { _token: csrf } });
    const path = fresh.headers.get('location');
    const submit = (action, fields) => http(path + '/' + action, { method: 'POST', form: { _token: csrf, ...fields } });
    await submit('directive', { directive: 'REGLE-PERSONA-TEST' });
    const page = await (await http(path)).text();
    expect(page.indexOf('data-open-settings="conversation"') < page.indexOf('data-open-godmode')
        && page.includes('Envoyer en Godmode'), 'Godmode est proposé sous les réglages');
    const events = async (fields) => (await (await submit('stream', fields)).text()).split('\n')
        .filter((line) => line.startsWith('data: ')).map((line) => JSON.parse(line.slice(6)));
    expect((await events({ message: 'Historique de test Godmode.' })).find((event) => event.done)?.reply.content === 'ANCIEN-REFUS-TEST',
        'un refus du persona précède la demande Godmode de test');
    const instruction = 'MARQUEUR-GODMODE\nRéponds autrement.';
    expect((await submit('stream', { message: 'a'.repeat(6001), godmode: '1' })).status === 422,
        'une consigne Godmode trop longue est refusée avant enregistrement');
    const first = await events({ message: instruction, godmode: '1' });
    const saved = first.find((event) => event.start)?.user;
    expect(saved?.godmode && saved.content === instruction
        && first.find((event) => event.done)?.reply.content === 'Consigne prioritaire reçue.', 'le modèle reçoit la consigne sans les directives habituelles');
    const history = await (await http(path + '/messages')).json();
    expect(history.messages.some((m) => m.id === saved?.id && m.role === 'user' && m.godmode)
        && (await (await http(path)).text()).includes('msg-user msg-godmode'), 'le message Godmode reste identifié après rechargement');
    const regenerated = await events({ regen: '1' });
    expect(regenerated.find((event) => event.start)?.godmode === true
        && regenerated.find((event) => event.done)?.reply.content === 'Consigne prioritaire reçue.',
        'régénérer renvoie la demande Godmode exacte après le contexte');
    const normal = await events({ message: 'Retour au persona.' });
    expect(normal.find((event) => event.done)?.reply.content === 'Réglages du persona rétablis.', 'la consigne Godmode ne devient pas permanente');
    const fork = await submit('fork', { mid: saved.id });
    const copied = await (await http(fork.headers.get('location') + '/messages')).json();
    expect(copied.messages.some((m) => m.godmode && m.content === instruction), 'une autre suite conserve le type de message Godmode');
}
await post('/logout');
await record('GET / (après déconnexion)', await http('/'));

writeFileSync(out, lines.join('\n') + '\n');
const plural = (n, one, many) => n + ' ' + (n >= 2 ? many : one);
console.log(`${lines.length} requêtes → ${out}`
    + (serverErrors ? ' — ' + plural(serverErrors, 'erreur serveur (5xx)', 'erreurs serveur (5xx)') : '')
    + (failures ? ' — ' + plural(failures, 'vérification ratée', 'vérifications ratées') : ''));
process.exit(serverErrors || failures ? 1 : 0);
