// Faux serveur de modèles, compatible OpenAI / LM Studio, aux réponses déterministes.
// Usage : node fake-llm.mjs <port>
import http from 'node:http';

const port = Number(process.argv[2] || 18235);
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
    if (req.method === 'GET' && req.url.endsWith('/models')) {
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify(req.url.includes('/api/')
            ? { models: [{ key: 'fake-model', type: 'llm', loaded_instances: [{ id: 'fake-model', config: { context_length: 8192 } }] }],
                data: [{ id: 'fake-model', state: 'loaded', type: 'llm', max_context_length: 8192 }] }
            : { data: [{ id: 'fake-model' }] }));
    }
    if (req.url.endsWith('/chat/completions')) {
        const j = JSON.parse(body || '{}');
        const text = reply(String(j.messages?.[0]?.content ?? ''));
        const usage = { prompt_tokens: 100, completion_tokens: 20 };
        if (j.stream) {
            res.writeHead(200, { 'Content-Type': 'text/event-stream' });
            for (const w of text.split(' ')) res.write('data: ' + JSON.stringify({ choices: [{ delta: { content: w + ' ' } }] }) + '\n\n');
            res.write('data: ' + JSON.stringify({ choices: [{ delta: {}, finish_reason: 'stop' }], usage }) + '\n\n');
            return res.end('data: [DONE]\n\n');
        }
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify({ choices: [{ message: { content: text }, finish_reason: 'stop' }], usage }));
    }
    res.statusCode = 404;
    res.end('{}');
}).listen(port, '127.0.0.1');
