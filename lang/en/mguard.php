<?php

/* MemoryGuard : fiabilité des souvenirs (src/memory_guard.php, views/memories.php). */

return [
    'mguard.title' => 'Reliability computed by MemoryGuard: proof, origin, confirmations, expiry',
    'mguard.fiable' => 'reliable',
    'mguard.probable' => 'likely',
    'mguard.incertain' => 'uncertain',
    'mguard.expire' => 'expired',
    'mguard.evidence' => 'Proof: “{quote}”',
    'mguard.confirmed' => 'confirmed {n} times',
    'mguard.expires' => 'expires on {date}',
    'mguard.origin.manual' => 'added by hand',
    'mguard.origin.explicit' => 'asked by you (“remember…”)',
    'mguard.origin.mcp' => 'added by the connector',
    'mguard.origin.auto_noproof' => 'extracted without proof: never stated as fact to the persona',
    'mguard.origin.legacy' => 'memory from before MemoryGuard',
];
