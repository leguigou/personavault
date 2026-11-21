// Faux serveur de modèles, compatible OpenAI / LM Studio, aux réponses déterministes.
// Usage : node fake-llm.mjs <port>
import http from 'node:http';

const port = Number(process.argv[2] || 18235);
let nextStream = '';
let modelState = 'loaded';
// assistant de création : fiche de test (personnage existant « Sherlock Holmes »)
const sherlock = { name: 'Sherlock Holmes', tagline: 'Détective consultant au 221B Baker Street', description: 'Détective génial et excentrique de Londres.',
    tags: ['detective', 'londres'], meta: [{ k: 'Œuvre', v: 'Arthur Conan Doyle' }, { k: 'Âge', v: '35' }, { k: 'Ville', v: 'Londres' }],
    looks: 'Grand, mince, visage anguleux, nez aquilin, yeux gris perçants.', style: 'illustration victorienne',
    history: 'A ouvert un cabinet à Baker Street.', family: 'Mycroft, son frère.', friends: 'Watson, son confident.', work: 'Détective consultant.', goals: 'Résoudre les affaires que la police a abandonnées.',
    personality: 'Observateur, logique, distant.', voice: 'Précis, ironique, vouvoie.', relation: 'Client venu le consulter.', rules: 'Reste dans son époque.' };
const assist = (sys, user) => {
    if (/Tu remplis la fiche d'un PERSONA tiré d'un personnage EXISTANT/.test(sys)) {
        const ok = /RÉFÉRENCE \(Sherlock Holmes, https:\/\/fr\.wikipedia\.org\/wiki\/Sherlock_Holmes\)/.test(user);
        const novels = /DERNIÈRE DEMANDE DE L'UTILISATEUR : Les romans/.test(user);
        const draft = novels ? { ...sherlock, meta: [...sherlock.meta, { k: 'Version', v: 'Les romans' }] } : sherlock;
        return JSON.stringify({ found: ok, draft, reply: 'J’ai retrouvé Sherlock Holmes.', question: 'Quelle relation avec toi ?', choices: ['Un client'] });
    }
    if (/Tu finalises la fiche/.test(sys)) {
        return JSON.stringify({ draft: sherlock, system_prompt: 'Tu es Sherlock Holmes, de Conan Doyle.', image_prompt: 'portrait of Sherlock Holmes' });
    }
    if (/aide à créer la fiche d'un PERSONA/.test(sys)) {
        if (/On peut passer à la relecture maintenant/.test(user)) {
            return JSON.stringify({ draft: { tagline: 'Détective prêt à relire' }, review: true });
        }
        const answer = user.split("RÉPONSE DE L'UTILISATEUR : ").at(-1);
        if (/Détail ancien de Nina/.test(user) && answer.trim() === 'Je complète la voix de Nina') {
            return JSON.stringify({ draft: { ...sherlock, name: 'Nina', work: 'Fleuriste', family: 'Vit loin de sa famille.', friends: 'Aucun ami proche.' },
                topic: 'name', question: 'Quel est son prénom ?' });
        }
        if (answer.trim() === 'Je précise un détail de Nina') {
            return JSON.stringify({ draft: { voice: 'Parle à voix basse.' }, topic: 'name', question: 'Quel est son prénom ?' });
        }
        const known = /sherlock/i.test(answer) && answer !== user;
        return JSON.stringify({ draft: {}, reply: '', question: 'Quel est son prénom ?', choices: ['Inès'],
            canon: known ? { name: 'Sherlock Holmes', work: 'Arthur Conan Doyle', version_choices: ['Les romans', 'La série'] } : null });
    }
    return null;
};
const reply = (sys) => {
    if (/moteur de mémoire/i.test(sys)) {
        return JSON.stringify({ current_topic: 'Voyage', conversation_goal: 'Préparer le voyage',
            important_facts: ['Part en train'], decisions: [], open_questions: [], context_summary: 'Discussion de test.' });
    }
    if (/mémoire durable/i.test(sys)) {
        return JSON.stringify({ memories: [{ content: 'Aime les trains de nuit et préfère réserver une couchette près de la fenêtre pour regarder le paysage au réveil.', category: 'preference', confidence: 'confirme' }], forget: [],
            story: [{ content: 'Camille prépare une exposition à Lyon', category: 'projet', source: 2 }] });
    }
    if (/titre très court/i.test(sys)) return 'Voyage en train';
    if (/ressent pour l'utilisateur/.test(sys)) {
        return JSON.stringify({ emotion: 'gene', mot: 'troublée', intensite: 55, amitie: 6, amour: 25, colere: 0, raison: 'Elle a apprécié le compliment' });
    }
    if (/maître du jeu d'une discussion entre deux personnages/.test(sys)) {
        return JSON.stringify({ scene: 'Dans un café du centre-ville, un samedi soir', humeur_a: { emotion: 'joie', mot: 'joyeuse', intensite: 60 },
            humeur_b: { emotion: 'colere', mot: 'vexé', intensite: 40 },
            lien_a_b: { amitie: 25, amour: 4, colere: 0, raison: 'Ils rient ensemble' },
            lien_b_a: { amitie: -3, amour: 0, colere: 6, raison: 'Il se sent moqué' }, avance: false, relance: 'Un orage éclate dehors.' });
    }
    return 'Réponse de test du faux modèle. Une seconde phrase pour avoir du texte.';
};

http.createServer(async (req, res) => {
    let body = '';
    for await (const chunk of req) body += chunk;
    if (req.method === 'POST' && req.url === '/test/next-stream') {
        nextStream = body;
        res.statusCode = 204;
        return res.end();
    }
    if (req.method === 'GET' && req.url === '/test/next-stream') {
        return res.end(nextStream);
    }
    if (req.method === 'POST' && req.url === '/test/model-state') {
        modelState = body;
        res.statusCode = 204;
        return res.end();
    }
    if (req.method === 'GET' && req.url.endsWith('/models')) {
        if (req.url.startsWith('/api/') && req.headers.authorization !== 'Bearer lms-test-token') {
            res.statusCode = 401;
            return res.end('{}');
        }
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify(req.url.includes('/api/')
            ? { models: [{ key: 'fake-model', type: 'llm', loaded_instances: modelState === 'loaded'
                ? [{ id: 'fake-model', config: { context_length: 8192 } }] : [] }],
                data: [{ id: 'fake-model', state: modelState, type: 'llm', max_context_length: 8192 }] }
            : { data: [{ id: 'fake-model' }] }));
    }
    if (req.method === 'POST' && req.url === '/api/v1/models/load') {
        const request = JSON.parse(body);
        if (req.headers.authorization !== 'Bearer lms-test-token') {
            res.statusCode = 401;
            return res.end(JSON.stringify({ error: { message: 'Jeton de chargement manquant ou incorrect' } }));
        }
        if (modelState === 'refused') {
            res.statusCode = 403;
            return res.end(JSON.stringify({ error: { message: 'Permissions insuffisantes : ' + req.headers.authorization } }));
        }
        if (request.model !== 'fake-model' || request.context_length < 2048) {
            res.statusCode = 400;
            return res.end('{}');
        }
        modelState = 'loaded';
        return res.end(JSON.stringify({ instance_id: 'fake-model' }));
    }
    if (req.url.startsWith('/klipy/rejected-test-key/')) {
        res.statusCode = 401;
        return res.end();
    }
    if (req.url.startsWith('/web/search?')) {
        const query = new URL(req.url, 'http://localhost').searchParams.get('q');
        if (req.headers['x-subscription-token'] !== 'test-search-key'
            || !['restaurants italiens pizzas Toulon', 'Tour Eiffel Paris site officiel'].includes(query)) {
            res.statusCode = 503;
            return res.end();
        }
        return res.end(JSON.stringify({ web: { results: [
            { title: 'Pizzeria de test', description: 'Pizzas italiennes au 7 rue Exemple à Toulon.', url: 'https://example.com/pizzeria-toulon' },
            { title: 'Résultat invalide', description: '', url: 'javascript:alert(1)' },
        ] } }));
    }
    if (req.method === 'POST' && req.url === '/api/v1/chat') {
        const request = JSON.parse(body);
        const integration = request.integrations?.[0];
        if (req.headers.authorization !== 'Bearer lms-test-token'
            || !['restaurants italiens pizzas Toulon', 'Tour Eiffel Paris site officiel'].includes(request.input) || request.model !== 'fake-model'
            || request.store !== false || integration?.type !== 'plugin' || integration.id !== 'mcp/test-search'
            || integration.allowed_tools?.join() !== 'web_search') {
            res.statusCode = 400;
            return res.end('{}');
        }
        const result = { title: 'Pizzeria de test', description: 'Pizzas au 7 rue Exemple à Toulon.', url: 'https://example.com/mcp-toulon' };
        return res.end(JSON.stringify({ output: [
            { type: 'tool_call', tool: 'web_search', output: JSON.stringify([result]), provider_info: { type: 'plugin', plugin_id: integration.id } },
            { type: 'message', content: JSON.stringify({ results: [result, { ...result, url: 'https://example.com/invented' }] }) },
        ] }));
    }
    const klipyFormat = req.url.match(/^\/klipy\/test-gif-key\/(gif|sticker|clip)s\/search\?/)?.[1];
    if (klipyFormat) {
        const params = new URL(req.url, 'http://localhost').searchParams;
        if (!['happy dance', 'playful wink', 'friendly wave'].includes(params.get('q')) || params.get('content_filter') !== 'high') {
            res.statusCode = 400;
            return res.end();
        }
        const name = { 'playful wink': 'wink', 'happy dance': 'celebrate', 'friendly wave': 'hello' }[params.get('q')];
        const page = Number(params.get('page') || 1);
        const suffix = name === 'wink' && page === 2 ? '-second' : '';
        const url = (extension) => 'https://static.klipy.com/test/' + name + suffix + '.' + extension;
        const file = klipyFormat === 'clip' ? { mp4: url('mp4') }
            : klipyFormat === 'sticker' ? { md: { webp: { url: url('webp') } } }
            : { md: { gif: { url: url('gif') } } };
        return res.end(JSON.stringify({ data: { data: [
            { type: 'ad' },
            { type: klipyFormat, file },
        ], has_next: name === 'wink' && page === 1 } }));
    }
    if (req.url.endsWith('/chat/completions')) {
        const j = JSON.parse(body || '{}');
        if (j.messages?.[0]?.content === 'Réponds uniquement par OK.') {
            const key = req.url.startsWith('/remote/') ? 'llm-test-secret' : 'lms-test-token';
            if (req.headers.authorization !== 'Bearer ' + key) {
                res.statusCode = 401;
                return res.end(JSON.stringify({ error: { message: 'Jeton reçu : ' + req.headers.authorization } }));
            }
            if (req.url.startsWith('/redirect/')) {
                res.writeHead(307, { Location: '/v1/chat/completions' });
                return res.end();
            }
        }
        // comme LM Studio : json_object refusé, json_schema accepté
        if (j.response_format?.type === 'json_object') {
            res.statusCode = 400;
            res.setHeader('Content-Type', 'application/json');
            return res.end(JSON.stringify({ error: "'response_format.type' must be 'json_schema' or 'text'" }));
        }
        const system = String(j.messages?.[0]?.content ?? '');
        const last = String(j.messages?.at(-1)?.content ?? '');
        let text;
        if (system.startsWith('Décide si le dernier message appelle une recherche')) {
            const { recent, message } = JSON.parse(last);
            const hasCity = recent.some(([role, content]) => role === 'user' && content.includes('à Toulon'));
            // recherche d'informations : la source n'est proposée que si elle est listée dans la consigne
            const planned = {
                'Il fait quel temps à Toulon demain ?': { source: 'meteo', query: 'météo Toulon', lieu: 'Toulon' },
                'Trouve-moi une boulangerie ouverte à Toulon': { source: 'web', query: 'boulangerie ouverte Toulon' },
                'Qui était Sherlock Holmes ?': { source: 'wikipedia', query: 'Sherlock Holmes' },
            }[message];
            text = JSON.stringify(planned && system.includes('« ' + planned.source + ' »') ? planned
                : { query: message === 'Italien et pizza' && hasCity ? 'restaurants italiens pizzas Toulon'
                    : message === 'Vérifie les horaires à Toulon' ? 'horaires Toulon' : '' });
        } else if (system.startsWith('Choisis un média KLIPY facultatif')) {
            const format = system.match(/Formats autorisés : (gif|sticker|clip)/)?.[1];
            text = last.includes('Je fête cette réussite') ? format + ':celebrate' : 'none';
        } else if (system.includes("Analyse l'évolution de la relation")) {
            const { etat, nouveaux_echanges: exchanges } = JSON.parse(last);
            const conflict = etat.find((entry) => entry.kind === 'conflict' && !entry.resolved && !entry.deleted);
            const entries = [];
            const resolve = [];
            for (const turn of exchanges) {
                if (turn.utilisateur === 'Je suis fâché à propos de la photo.') {
                    entries.push({ kind: 'conflict', content: 'Désaccord à propos de la photo', source: turn.source });
                }
                if (turn.utilisateur === 'On en a parlé et on est réconciliés.' && conflict) {
                    resolve.push({ id: conflict.id, source: turn.source });
                }
            }
            text = JSON.stringify({ address: null, entries, resolve });
        } else if (system.includes('Tu ouvres une nouvelle conversation')) {
            text = last.includes('pastéis au bord du Tage') && last.includes('Camille prépare une exposition à Lyon')
                ? JSON.stringify({ message: 'Ça m’a fait repenser à ton voyage à Lisbonne. Tu y retournerais ?', questions: ['Et ton exposition à Lyon, elle avance ?'] })
                : JSON.stringify({ message: 'Tu as déjà développé une pellicule argentique ?', questions: ['Tu photographies quoi en ce moment ?'] });
        } else if (system.includes('open_topics et closed_topics') && last.includes('On reparlera du concours de cerfs-volants.')) {
            const source = Number(last.match(/\[(\d+)\] \((\d{4}-\d{2}-\d{2})\) Utilisateur : On reparlera du concours de cerfs-volants\./)?.[1]);
            const day = last.match(/^Date du jour : (\d{4}-\d{2}-\d{2})/)?.[1];
            text = JSON.stringify({ open_topics: [{ source, due_on: day, content: 'Reprendre le concours de cerfs-volants' }] });
        } else if (system.includes('open_topics et closed_topics') && last.includes('passé au concours')) {
            const topics = JSON.parse(last.match(/Sujets déjà suivis \(closed=1 signifie clos\) :\n([^\n]+)/)?.[1] || '[]');
            text = JSON.stringify({ closed_topics: topics.filter((topic) => !topic.closed).map((topic) => topic.id) });
        } else if (j.stream && (last.endsWith('Me revoilà.') || last.endsWith('Autre chose maintenant.'))) {
            text = last.includes('Sujet laissé en suspens') && last.includes('Reprendre le concours de cerfs-volants')
                ? 'Alors, comment ça s’est passé au concours ?' : 'Parlons de la suite.';
        } else if (last.endsWith('Test du seuil de découpage')) {
            text = 'mot '.repeat(14) + 'mot. ' + 'suite '.repeat(9) + 'suite. Dernière phrase.';
        } else if (j.stream && last.endsWith('Le départ est fixé à 17 heures.')) {
            text = 'Je peux te suggérer plusieurs itinéraires pour demain. Je te propose le train méridien pour ce voyage.';
        } else if (j.stream && last.endsWith('Oui, cite cette bulle.')) {
            text = last.includes('répond à ce passage de ton message') && last.includes('"Je te propose le train méridien pour ce voyage."')
                && last.includes('Échanges qui précédaient ce message') && last.includes('Utilisateur : Le départ est fixé à 17 heures.')
                && last.includes('Réserve toujours le train méridien en avance.')
                ? 'Le passage cité, son contexte et le souvenir sont disponibles.' : 'Il manque des éléments pour comprendre la citation.';
        } else if (system.includes('le sujet concret des derniers échanges') && last.includes('volcans en Islande')) {
            text = 'Volcans en Islande';
        } else if (j.stream && last.includes('Extraits web :') && last.includes('7 rue Exemple')) {
            text = 'La Pizzeria de test se trouve au 7 rue Exemple à Toulon.';
        } else if (j.stream && system.includes('n’a renvoyé aucun résultat exploitable')) {
            text = 'Je ne peux pas vérifier les horaires cette fois-ci.';
        } else {
            text = assist(system, String(j.messages?.[1]?.content ?? '')) ?? reply(system);
            // analyse du maître du jeu volontairement lente, comme sur un vrai modèle : la page
            // ne doit pas voir une discussion « terminée » avant que ses ressentis soient à jour
            if (system.includes("maître du jeu d'une discussion entre deux personnages")) {
                await new Promise((r) => setTimeout(r, 1200));
            }
        }
        const usage = { prompt_tokens: 100, completion_tokens: 20 };
        if (j.stream) {
            res.writeHead(200, { 'Content-Type': 'text/event-stream' });
            const mode = nextStream;
            nextStream = '';
            const delta = (content) => res.write('data: ' + JSON.stringify({ choices: [{ delta: { content } }] }) + '\n\n');
            let finish = 'stop';
            if (last.endsWith("Tu m'envoies un petit gif")) {
                delta(system.includes('Le média demandé a été trouvé') ? 'Avec plaisir !' : 'Je ne peux pas envoyer de GIF.');
            } else if (last.endsWith('Envoie-moi un GIF avec un clin d’œil')) {
                delta('Avec plaisir ! [G');
                delta('IF d’un clin d’œil]');
            } else if (last.endsWith('Test du réglage de réflexion')) {
                delta(`Niveau reçu : ${j.reasoning_effort}.`);
            } else if (last.endsWith('Merci, tu as été adorable avec moi.')) {
                if (system.includes('[reaction:choix]')) {
                    for (const part of ['\n[re', 'action:', 'heart', ']', '\n', '\n']) delta(part);
                }
                delta('Ça me touche. Hier soir, j’ai vraiment');
                finish = 'length';
            } else if (last.endsWith('On se parle à 18 h ?') && system.includes('[reaction:choix]')) {
                delta('[reaction:none]\nD’accord, à 18 h !');
            } else if (mode) {
                if (mode !== 'silent') delta('Début de la nouvelle réponse.');
                if (mode === 'fail') {
                    // Laisser le temps à un autre onglet de lire la réponse encore active.
                    const timer = setTimeout(() => res.end('data: {"error":{"message":"Échec simulé"}}\n\n'), 1200);
                    res.on('close', () => clearTimeout(timer));
                } else if (mode === 'cut') {
                    return res.end();
                } else {
                    const timer = setTimeout(() => res.end('data: [DONE]\n\n'), 10000);
                    res.on('close', () => clearTimeout(timer));
                }
                return;
            } else {
                for (const w of text.split(' ')) delta(w + ' ');
            }
            res.write('data: ' + JSON.stringify({ choices: [{ delta: {}, finish_reason: finish }], usage }) + '\n\n');
            return res.end('data: [DONE]\n\n');
        }
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify({ choices: [{ message: { content: text }, finish_reason: 'stop' }], usage }));
    }
    // faux Wikipédia (API MediaWiki) pour la recherche d'un personnage existant
    if (req.url.startsWith('/wiki/fr/api.php')) {
        const q = new URL(req.url, 'http://x').searchParams;
        res.setHeader('Content-Type', 'application/json');
        if (q.get('list') === 'search') {
            return res.end(JSON.stringify({ query: { search: /sherlock/i.test(q.get('srsearch') || '')
                ? [{ title: 'Arthur Conan Doyle' }, { title: 'Sherlock Holmes' }] : [] } }));
        }
        return res.end(JSON.stringify({ query: { pages: [{ title: q.get('titles'), fullurl: 'https://fr.wikipedia.org/wiki/' + String(q.get('titles')).replace(/ /g, '_'),
            extract: 'Sherlock Holmes est un détective de fiction créé par Arthur Conan Doyle. '.repeat(8) }] } }));
    }
    // faux SearXNG (format JSON exigé, comme une instance où il n'est pas activé renvoie 403)
    if (req.url.startsWith('/searxng/search?')) {
        const q = new URL(req.url, 'http://x').searchParams;
        if (q.get('format') !== 'json') {
            res.statusCode = 403;
            return res.end();
        }
        const results = q.get('q') === 'boulangerie ouverte Toulon'
            ? [{ title: 'Boulangerie du port', content: 'Ouverte tous les jours de 6 h à 20 h, quai Cronstadt.', url: 'https://example.com/boulangerie-toulon' }]
            : q.get('q') === 'Tour Eiffel Paris' ? [{ title: 'Tour Eiffel', content: 'Site officiel.', url: 'https://example.com/tour-eiffel' }] : [];
        return res.end(JSON.stringify({ query: q.get('q'), results }));
    }
    if (req.url.startsWith('/searxng-nojson/search?')) {
        res.statusCode = 403;
        return res.end();
    }
    // fausse météo (Open-Meteo : géocodage puis prévisions)
    if (req.url.startsWith('/meteo/geo?')) {
        const q = new URL(req.url, 'http://x').searchParams;
        return res.end(JSON.stringify(q.get('name') === 'Toulon'
            ? { results: [{ name: 'Toulon', country: 'France', latitude: 43.12, longitude: 5.93 }] } : {}));
    }
    if (req.url.startsWith('/meteo/forecast?')) {
        return res.end(JSON.stringify({
            current: { temperature_2m: 14.2, weather_code: 1, wind_speed_10m: 12 },
            daily: { weather_code: [1, 61, 3], temperature_2m_max: [16, 15, 17], temperature_2m_min: [9, 10, 11], precipitation_probability_max: [10, 80, 20] },
        }));
    }
    res.statusCode = 404;
    res.end('{}');
}).listen(port, '127.0.0.1');
