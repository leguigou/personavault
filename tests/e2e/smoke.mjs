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
const http = async (path, { method = 'GET', form, anonymous = false, origin = base, signal } = {}) => {
    const r = await fetch(origin + path, {
        method,
        redirect: 'manual',
        headers: anonymous ? {} : { cookie: cookieHeader() },
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
const rem = await post('/chat/1/remember', { force: '1' });
expect((JSON.parse(rem).added || []).length > 0, 'l’extraction ajoute un souvenir');
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
        && preview.fields.family.includes('Mycroft') && preview.fields.friends.includes('Watson') && preview.fields.history && preview.fields.goals
        && !Object.hasOwn(preview.fields, 'looks')
        && (await http('/p/sherlock-holmes')).status === 404, 'assistant : la relecture contient les deux prompts et ne crée pas la fiche');
    const fields = { ...preview.fields, tagline: 'Détective relu', friends: 'Watson relit ses notes.',
        system_prompt: 'Tu es Sherlock Holmes. Tu parles avec précision.', image_prompt: '' };
    const c = JSON.parse(await post('/assist/create', { fields: JSON.stringify(fields) }));
    expect(c.ok && c.redirect === '/p/sherlock-holmes', 'assistant : fiche créée');
    const page = await record('GET /p/sherlock-holmes', await http('/p/sherlock-holmes'));
    expect(page.includes(fields.system_prompt) && page.includes(fields.tagline) && page.includes('Personnage d’origine')
        && page.includes(fields.friends) && page.includes(fields.family) && page.includes(fields.goals)
        && !page.includes(version.draft.looks)
        && !page.includes(preview.fields.image_prompt), 'assistant : les corrections de relecture et le retrait du prompt image sont enregistrés');
    const additions = JSON.parse(await post('/assist/preview', { slug: 'sherlock-holmes' }, '/assist/preview (compléter)'));
    expect(additions.ok && !Object.hasOwn(additions.fields, 'name') && !Object.hasOwn(additions.fields, 'description')
        && !Object.hasOwn(additions.fields, 'system_prompt') && Object.hasOwn(additions.fields, 'image_prompt')
        && !Object.hasOwn(additions.fields, 'friends'),
        'assistant : la relecture d’une fiche existante ne propose que les ajouts autorisés');
    await post('/p/sherlock-holmes/delete');
}
await post('/settings', { provider: 'deepseek', base_url: 'https://api.deepseek.com/v1', model: 'deepseek-chat', api_key: '' });
await post('/settings/chat', { chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`, chat_model: 'fake-model',
    chat_memory_threshold: '600', chat_memory_target: '300', chat_split_words: '50', chat_split_max_bubbles: '1',
    facts_frequency: 'each', chat_title_model: 'chat', chat_user_profile_enabled: '1', chat_user_profile: 'Je préfère les voyages en train.' });
const profileSettings = await (await http('/settings')).text();
expect(/name="chat_user_profile_enabled"[^>]*\bchecked/.test(profileSettings)
    && profileSettings.includes('id="chat-user-profile-field" data-show-if="chat_user_profile_enabled=1">')
    && profileSettings.includes('Je préfère les voyages en train.'), 'la présentation globale est enregistrée et affichée quand elle est activée');

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
    const newChat = await chatPost('/chat/1/new');
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
    const privateChat = (await chatPost('/chat/1/new')).headers.get('location');
    await chatPost(privateChat + '/facts-toggle', { enabled: '0' });
    const privateOpening = await (await chatPost(privateChat + '/opening')).json();
    expect(!privateOpening.message.content.includes('Lisbonne') && !privateOpening.questions.some((q) => q.includes('Lyon')),
        'une ouverture sans souvenirs ne s’appuie ni sur les anciens fils ni sur l’histoire mémorisée');
    const splitSettings = {
        chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`, chat_model: 'fake-model',
        chat_split_enabled: '1', chat_split_words: '15', chat_split_max_bubbles: '2',
    };
    for (const position of ['before', 'after']) {
        await chatPost('/settings/chat', { ...splitSettings, chat_split_position: position });
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
            const follow = async (saved, bubble = saved.bubble) => (await (await chatPost(privateChat + '/stream', {
                reaction_mid: reply.id, reaction_bubble: bubble, reaction: saved.reaction, reaction_revision: saved.revision,
            })).text()).split('\n').filter((line) => line.startsWith('data: '))
                .map((line) => JSON.parse(line.slice(6))).find((event) => event.done);
            expect(first.reply && second.reply && (await follow(second, 0))?.skipped,
                'une révision de réaction est liée à sa bulle');
            expect((await follow(second))?.reply?.content === 'Dernière phrase.'
                && (await follow(first))?.reply?.content === reply.content.slice(0, reply.split_points[0]).trim(),
                'deux réactions à la même réponse utilisent chacune le texte de leur bulle');
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
    const path = (await chatPost('/chat/1/new')).headers.get('location');
    const request = (message) => turn(path, message);
    const analyze = () => chatPost(path + '/relationship');
    expect((await request('Vous pouvez me tutoyer, s’il vous plaît ?'))?.relationship_pending,
        'un échange terminé déclenche le traitement de la relation');
    expect((await (await http(profile)).text()).includes('value="tu" selected'), 'la demande de tutoiement est mémorisée immédiatement');
    await analyze();
    await request('Je suis fâché à propos de la photo.');
    await analyze();
    let page = await (await http(profile)).text();
    expect(page.includes('Désaccord à propos de la photo') && page.includes('En cours') && page.includes('?message='),
        'une dispute ajoute un désaccord actif et un lien vers le message');
    await request('On en a parlé et on est réconciliés.');
    await analyze();
    page = await (await http(profile)).text();
    expect(page.includes('Désaccord à propos de la photo') && page.includes('Résolu') && !page.includes('En cours'),
        'la réconciliation ferme le désaccord automatiquement');
    const next = (await chatPost(path + '/new')).headers.get('location');
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
    const followReaction = async (mid, saved) => (await chat(1, 'stream', {
        reaction_mid: mid, reaction: saved.reaction, reaction_revision: saved.revision,
    })).text();

    const previous = await recent(1);
    const target = previous.find((m) => m.id === 2);
    const otherBefore = await recent(2);
    const wrongChat = await chat(2, 'react', { mid: target.id, reaction: 'angry' });
    expect(wrongChat.status === 409, 'une réaction ne peut pas viser le message d’une autre discussion');
    const angry = await react(target.id, 'angry');
    const result = turn(await followReaction(target.id, angry));
    const after = await recent(1);
    expect(result?.reply?.content === 'Réaction à la réponse 2 (colère).' && result.reply.reaction_reply
        && after.length === previous.length + 1 && after.find((m) => m.id === target.id)?.reaction === 'angry'
        && JSON.stringify(await recent(2)) === JSON.stringify(otherBefore),
        'une réaction négative à un ancien message relance sa discussion sur le bon sujet');
    expect(turn(await followReaction(target.id, angry))?.skipped, 'une même réaction ne lance qu’une réponse');
    const like = await react(target.id, 'like');
    expect(turn(await followReaction(target.id, like))?.reply?.content === 'Réaction à la réponse 2 (accord simple).',
        'le pouce en haut est transmis comme un accord simple');
    expect(!(await react(target.id, '')).reply && !(await recent(1)).find((m) => m.id === target.id)?.reaction,
        'retirer une réaction ne déclenche pas une nouvelle réponse');

    const practical = await (await chat(2, 'stream', { message: 'On se parle à 18 h ?' })).text();
    expect(!practical.includes('[reaction:') && !turn(practical)?.user_reaction
        && turn(practical)?.reply?.content === 'D’accord, à 18 h !', 'un échange pratique peut rester sans émoji ni marqueur visible');
    const kindness = { message: 'Merci, tu as été adorable avec moi.' };
    const first = await (await chat(1, 'stream', kindness)).text();
    const second = await (await chat(1, 'stream', kindness)).text();
    const userReaction = turn(first)?.user_reaction;
    expect(userReaction?.reaction === 'heart' && !first.includes('[re') && !second.includes('[re') && !turn(second)?.user_reaction
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
    await submit('/settings/chat', {
        chat_provider: 'lmstudio', chat_base_url: `http://127.0.0.1:${llmPort}/v1`,
        chat_model: 'fake-model', klipy_api_key: 'test-gif-key',
    });
    await submit('/p/camille-lefebvre', { name: 'Camille Lefebvre', klipy_formats_present: '1' });
    expect((await celebrate())?.klipy_url === '', 'le persona doit autoriser les GIF spontanés');
    await submit('/p/camille-lefebvre', { name: 'Camille Lefebvre', klipy_formats_present: '1', 'klipy_formats[]': 'gif' });
    const animated = await celebrate();
    const gifUrl = 'https://static.klipy.com/test/celebrate.gif';
    expect(animated?.klipy_url === gifUrl, 'le GIF KLIPY accompagne la réponse en direct');
    expect((await (await http('/chat/1')).text()).includes(gifUrl), 'le GIF est restauré au rechargement');
    expect((await celebrate())?.klipy_url === '', 'les GIF spontanés sont espacés');
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
    expect(settings.includes('test********-key') && !settings.includes('test-gif-key'),
        'la clé KLIPY est identifiable sans exposer sa valeur complète');
}
await post('/logout');
await record('GET / (après déconnexion)', await http('/'));

writeFileSync(out, lines.join('\n') + '\n');
const plural = (n, one, many) => n + ' ' + (n >= 2 ? many : one);
console.log(`${lines.length} requêtes → ${out}`
    + (serverErrors ? ' — ' + plural(serverErrors, 'erreur serveur (5xx)', 'erreurs serveur (5xx)') : '')
    + (failures ? ' — ' + plural(failures, 'vérification ratée', 'vérifications ratées') : ''));
process.exit(serverErrors || failures ? 1 : 0);
