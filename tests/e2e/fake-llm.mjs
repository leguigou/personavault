// Faux serveur de modèles, compatible OpenAI / LM Studio, aux réponses déterministes.
// Usage : node fake-llm.mjs <port>
import http from 'node:http';

const port = Number(process.argv[2] || 18235);
let nextStream = '';
// assistant de création : fiche de test (personnage existant « Sherlock Holmes »)
const sherlock = { name: 'Sherlock Holmes', tagline: 'Détective consultant au 221B Baker Street', description: 'Détective génial et excentrique de Londres.',
    tags: ['detective', 'londres'], meta: [{ k: 'Œuvre', v: 'Arthur Conan Doyle' }, { k: 'Âge', v: '35' }, { k: 'Ville', v: 'Londres' }],
    looks: 'Grand, mince, visage anguleux, nez aquilin, yeux gris perçants.', style: 'illustration victorienne',
    history: 'A ouvert un cabinet à Baker Street.', family: 'Mycroft, son frère.', friends: 'Watson, son confident.', goals: 'Résoudre les affaires que la police a abandonnées.',
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
        const known = /sherlock/i.test(user.split('RÉPONSE DE L\'UTILISATEUR')[1] || '');
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
        return JSON.stringify({ memories: [{ content: 'Aime les trains de nuit', category: 'preference', confidence: 'confirme' }], forget: [] });
    }
    if (/titre très court/i.test(sys)) return 'Voyage en train';
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
    if (req.method === 'GET' && req.url.endsWith('/models')) {
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify(req.url.includes('/api/')
            ? { models: [{ key: 'fake-model', type: 'llm', loaded_instances: [{ id: 'fake-model', config: { context_length: 8192 } }] }],
                data: [{ id: 'fake-model', state: 'loaded', type: 'llm', max_context_length: 8192 }] }
            : { data: [{ id: 'fake-model' }] }));
    }
    if (req.url.endsWith('/chat/completions')) {
        const j = JSON.parse(body || '{}');
        // comme LM Studio : json_object refusé, json_schema accepté
        if (j.response_format?.type === 'json_object') {
            res.statusCode = 400;
            res.setHeader('Content-Type', 'application/json');
            return res.end(JSON.stringify({ error: "'response_format.type' must be 'json_schema' or 'text'" }));
        }
        const text = assist(String(j.messages?.[0]?.content ?? ''), String(j.messages?.[1]?.content ?? ''))
            ?? reply(String(j.messages?.[0]?.content ?? ''));
        const usage = { prompt_tokens: 100, completion_tokens: 20 };
        if (j.stream) {
            res.writeHead(200, { 'Content-Type': 'text/event-stream' });
            const mode = nextStream;
            nextStream = '';
            const delta = (content) => res.write('data: ' + JSON.stringify({ choices: [{ delta: { content } }] }) + '\n\n');
            if (mode) {
                if (mode !== 'silent') delta('Début de la nouvelle réponse.');
                if (mode === 'fail') {
                    // Laisser le temps à un autre onglet de lire la réponse encore active.
                    const timer = setTimeout(() => res.end('data: {"error":{"message":"Échec simulé"}}\n\n'), 1200);
                    res.on('close', () => clearTimeout(timer));
                } else {
                    const timer = setTimeout(() => res.end('data: [DONE]\n\n'), 10000);
                    res.on('close', () => clearTimeout(timer));
                }
                return;
            }
            for (const w of text.split(' ')) delta(w + ' ');
            res.write('data: ' + JSON.stringify({ choices: [{ delta: {}, finish_reason: 'stop' }], usage }) + '\n\n');
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
    res.statusCode = 404;
    res.end('{}');
}).listen(port, '127.0.0.1');
