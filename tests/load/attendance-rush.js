// Test de charge k6 — simule une ruée réaliste de visiteurs distincts scannant
// le QR à l'ouverture d'un événement (public, sans compte).
//
// Chaque itération = UN visiteur distinct (email/IP unique) qui exécute le
// vrai parcours : GET /e/{slug} (récupère le ticket de scan + jeton CSRF)
// puis POST /e/{slug} (émargement complet : identité, géoloc, signature).
//
// IP distincte par VU (X-Forwarded-For) : sans ça, le rate-limit anti-fraude
// par IP (`attendance-store`, 10/60s) collapserait tous les visiteurs
// simulés en une seule IP et fausserait complètement le test — voir le fix
// TrustProxies dans bootstrap/app.php.
//
// Lancer : k6 run -e BASE_URL=http://127.0.0.1:8000 -e SLUG=<slug> tests/load/attendance-rush.js
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const SLUG = __ENV.SLUG;
if (!SLUG) {
    throw new Error('Passe le slug de l\'événement de test : -e SLUG=...');
}

const TINY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

export const options = {
    scenarios: {
        rush: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '20s', target: 200 }, // ruée à l'ouverture : 200 visiteurs en 20s
                { duration: '40s', target: 200 }, // maintien de la charge
                { duration: '10s', target: 0 },   // redescente
            ],
        },
    },
    thresholds: {
        // `http_req_failed` compte tout statut >= 400, y compris les 429
        // anti-fraude ATTENDUS sous une vraie ruée (rate-limit qui fait son
        // travail) — ce n'est donc PAS le bon signal de succès. Les checks
        // personnalisés ci-dessous acceptent explicitement 200 ET 429 comme
        // corrects ; seul un statut inattendu (419, 500...) les fait échouer.
        checks: ['rate>0.99'],
        http_req_duration: ['p(95)<2000'],
    },
};

function forwardedIpFor(vu) {
    // Une IP "visiteur" distincte et stable par VU (simule un vrai smartphone).
    const b = vu % 256;
    const c = Math.floor(vu / 256) % 256;
    return `10.${c}.${b}.${(vu * 7) % 256}`;
}

export default function () {
    const xff = forwardedIpFor(__VU);
    const headers = { 'X-Forwarded-For': xff };

    const showRes = http.get(`${BASE_URL}/e/${SLUG}`, { headers });
    const showOk = check(showRes, { 'show: 200': (r) => r.status === 200 });
    if (!showOk) { sleep(1); return; }

    const csrfMatch = showRes.body.match(/csrf-token"\s+content="([^"]+)"/);
    const ticketMatch = showRes.body.match(/ticket:\s*"([^"]+)"/);
    if (!csrfMatch || !ticketMatch) { sleep(1); return; }

    const uid = `${__VU}-${__ITER}-${Date.now()}`;
    const payload = {
        email: `visiteur-${uid}@charge-test.ci`,
        last_name: 'Test',
        first_name: `Visiteur${uid}`,
        phone: '+225 0700000000',
        company: 'Charge Test SARL',
        direction: 'Direction Test',
        service: '',
        position: 'Testeur',
        latitude: '5.35',
        longitude: '-4.01',
        accuracy: '12.5',
        signature: TINY_PNG,
        ticket: ticketMatch[1],
        consent: '1',
    };

    const storeRes = http.post(`${BASE_URL}/e/${SLUG}`, payload, {
        headers: Object.assign({}, headers, {
            'X-CSRF-TOKEN': csrfMatch[1],
            Accept: 'application/json',
        }),
    });

    check(storeRes, {
        'store: 200 (ou 429 rate-limit attendu au-delà du seuil)': (r) => r.status === 200 || r.status === 429,
        'store: pas de 500': (r) => r.status < 500,
    });

    sleep(Math.random() * 2); // un vrai visiteur ne soumet pas en boucle instantanée
}
