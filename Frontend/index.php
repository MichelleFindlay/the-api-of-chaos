<?php
declare(strict_types=1);

/**
 * The API of Chaos — single-file frontend.
 *
 * Drop this anywhere PHP 8.1+ with curl runs. No other files needed.
 *
 *   /index.php                              the page
 *   /index.php?path=/kick/rocks&tier=9      proxied JSON envelope
 *   /index.php?path=/healthz&raw=1          verbatim upstream status + body
 *
 * Requests are proxied server side and the caller's IP is forwarded upstream,
 * so /pound/dirt still attributes piles to the right person.
 */

// ============================================================ configuration

/**
 * Where the API lives.
 *
 * Every request carries the visitor's address in CF-Connecting-IP. Be aware
 * that if this hostname is proxied by Cloudflare (orange cloud), Cloudflare
 * rewrites CF-Connecting-IP at its edge to whatever address connected — this
 * server — and the API never sees the visitor. Two ways round it:
 *
 *   1. Point this at an origin hostname that bypasses Cloudflare (grey cloud,
 *      or a direct origin record), so the header arrives untouched.
 *   2. Have the API read X-Chaos-Client-IP, which nothing rewrites.
 */
const API_BASE        = 'https://api.dumpsterfire.uk';

/**
 * Reach the origin directly, bypassing Cloudflare's edge.
 *
 * Leave empty for normal operation. If the API host is stuck behind a
 * Cloudflare edge error (e.g. 1000 dns_loop) you can point the proxy straight
 * at the origin server's real IP: requests still send the correct Host and SNI
 * for API_BASE, but the TCP connection goes to this address instead of the
 * Cloudflare-resolved one. Set it to the box actually running the API.
 *
 *   const API_ORIGIN_IP = '203.0.113.50';
 */
const API_ORIGIN_IP   = '';
const CONNECT_TIMEOUT = 4;
const TIMEOUT         = 10;
const MAX_BYTES       = 262144;
const FORWARD_CLIENT_IP = true;

/**
 * This site is served only through Cloudflare, so CF-Connecting-IP is taken as
 * the visitor's address whenever it is present, whatever REMOTE_ADDR says.
 * That is the header Cloudflare rewrites on every request and it is what the
 * pile ID, the status bar and everything sent upstream are built from.
 *
 * Set this to false only if the origin is also reachable without Cloudflare —
 * then the header is believed just for connections from the ranges below.
 * Either way, lock the origin firewall to Cloudflare's ranges so nobody can
 * connect directly and hand you a header of their choosing.
 */
const TRUST_CLOUDFLARE_HEADER = true;

/**
 * Proxies in front of *this* page whose forwarding headers we believe.
 *
 * Cloudflare's edge ranges, from https://www.cloudflare.com/ips-v4 and
 * https://www.cloudflare.com/ips-v6, plus loopback and private ranges for a
 * local nginx, Caddy or php-fpm sitting between Cloudflare and this file —
 * without those, REMOTE_ADDR is 127.0.0.1 and the edge headers get ignored.
 * Refresh the Cloudflare list occasionally:
 *
 *   curl -s https://www.cloudflare.com/ips-v4 https://www.cloudflare.com/ips-v6 \
 *     | sed "s/^/    '/;s/$/',/"
 */
const TRUSTED_PROXIES = [
    // Loopback and private, for a local reverse proxy
    '127.0.0.0/8',
    '::1',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
    'fc00::/7',
    // Cloudflare IPv4
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    // Cloudflare IPv6
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
];

/**
 * The API sits behind Cloudflare too, which overwrites CF-Connecting-IP with
 * *this* server's address on the way through. So the real visitor is sent
 * upstream in a header Cloudflare leaves alone. Read this one in the API.
 */
const CLIENT_IP_HEADER = 'X-Chaos-Client-IP';

/**
 * Optional shared secret sent as X-Chaos-Frontend-Key. Set it here and in the
 * API so the API only believes CLIENT_IP_HEADER when it came from this
 * frontend. Leave empty to skip. Better still, read it from the environment:
 * getenv('CHAOS_FRONTEND_KEY') — constants can't call functions, so set it in
 * the line below if you go that route.
 */
const FRONTEND_KEY = '';

/**
 * Query parameters allowed through to the API. Everything else is dropped,
 * including ?pile — the pile is fixed to the visitor's address below and
 * cannot be named, typed or guessed from the browser.
 */
const ALLOWED_PARAMS = ['tier', 'min', 'max'];

/** Paths the proxy will forward, as anchored regexes. */
const ALLOWED_PATHS = [
    '#^/$#',
    '#^/healthz$#',
    '#^/kick/rocks$#',
    '#^/kick/rocks/tiers$#',
    '#^/kick/munitions$#',
    '#^/kick/munitions/tiers$#',
    '#^/pound/dirt$#',
    '#^/pound/dirt/(status|tiers|leaderboard)$#',
    '#^/excuses/(teams|social|oops|ring-ring|late|alibis)$#',
    '#^/ministry/(gentle-correction|mandatory-pet-adoption)$#',
    '#^/cage/finger$#',
    '#^/cage/finger/(left|reset)$#',
    '#^/cage/fictional/finger$#',
    '#^/unhinged/(8ball|optimism|pessimism|advice|non-committal|optimistic-dooom|turn-it-upside-down|solid-suddenly-liquid|solid-suddenly-gelatinous|choose-your-duck|gravity-resigned|vengeful-weather)$#',
];

/** Paths that may be called with DELETE. Everything else is GET or POST. */
const DELETE_PATHS = [
    '#^/pound/dirt$#',
];

/**
 * One pile per IP address, no exceptions.
 *
 * On these paths the frontend sets ?pile to an ID derived from the visitor's
 * Cloudflare-resolved address, always, overwriting anything that arrives with
 * the request. Named piles are gone: pound, status and reset all act on the
 * one pile that belongs to the caller, and nobody can touch anyone else's.
 */
const PILE_ID_PATHS = [
    '#^/pound/dirt$#',
    '#^/pound/dirt/status$#',
];

/**
 * 'ip'   — the pile ID is the visitor's raw address, as asked for. Note that
 *          the leaderboard's octet stripping applies to IDs the API works out
 *          for itself; a pile *name* that happens to be an IP may well be
 *          printed in full, so full visitor addresses could end up on a public
 *          page. Worth checking against the API before leaving this on.
 * 'hash' — a short keyed hash of the address instead. Same pile for the same
 *          visitor every time, nothing personal on the leaderboard. Set
 *          PILE_ID_SALT to anything long and random if you use this.
 */
const PILE_ID_MODE = 'ip';
const PILE_ID_SALT = 'change-me-if-you-switch-to-hash';

/**
 * The query parameter the API reads the pile ID from. Change this one line if
 * the reset expects something other than ?pile — ?ip, ?id and so on.
 */
const PILE_PARAM = 'pile';

/** What the page offers. Order here is the order on screen. */
$CATALOGUE = [
    [
        'group'   => 'Rocks',
        'collapsed' => true,
        'caption' => 'assigned, not chosen',
        'items'   => [
            [
                'path' => '/kick/rocks', 'method' => 'GET',
                'note' => 'assigns a 🪨. tier, or a min/max range',
                'fields' => [
                    ['name' => 'tier', 'label' => 'Tier', 'type' => 'number', 'min' => 1, 'max' => 14, 'placeholder' => 'n'],
                    ['name' => 'min',  'label' => 'Min',  'type' => 'number', 'min' => 1, 'max' => 14, 'placeholder' => '1'],
                    ['name' => 'max',  'label' => 'Max',  'type' => 'number', 'min' => 1, 'max' => 14, 'placeholder' => '14'],
                ],
            ],
            [
                'path' => '/kick/rocks/tiers', 'method' => 'GET',
                'note' => 'the full scale, 1 through 14 ⛰️', 'fields' => [],
            ],
        ],
    ],
    [
        'group'   => 'Munitions',
        'collapsed' => true,
        'caption' => 'unintentionally lost',
        'items'   => [
            [
                'path' => '/kick/munitions', 'method' => 'GET',
                'note' => 'assigns one. tells you the tier and the arc 🔫', 'fields' => [],
            ],
            [
                'path' => '/kick/munitions/tiers', 'method' => 'GET',
                'note' => '1 through 50, in five ten-tier arcs ☢️', 'fields' => [],
            ],
        ],
    ],
    [
        'group'   => 'Dirt',
        'collapsed' => true,
        'caption' => 'one pile per address',
        'items'   => [
            [
                'path' => '/pound/dirt', 'method' => 'GET',
                'note' => 'adds to your pile. post works too 💩',
                'fields' => [],
            ],
            [
                'path' => '/pound/dirt/status', 'method' => 'GET',
                'note' => 'peek without pounding 🫣',
                'fields' => [],
            ],
            [
                'path' => '/pound/dirt/tiers', 'method' => 'GET',
                'note' => '👊 through second 🌕', 'fields' => [],
            ],
            [
                'path' => '/pound/dirt/leaderboard', 'method' => 'GET',
                'note' => 'top 20. final octet removed 🛘', 'fields' => [],
            ],
            [
                'path' => '/pound/dirt', 'method' => 'DELETE',
                'note' => 'levels your own pile and nobody else\'s 💣',
                'fields' => [],
            ],
        ],
    ],
    [
        'group'   => 'Excuses',
        'caption' => 'six ways out',
        'items'   => [
            ['path' => '/excuses/teams',        'method' => 'GET', 'note' => 'not joining the call 👏',        'fields' => []],
            ['path' => '/excuses/social',       'method' => 'GET', 'note' => 'not attending, with tier 👥',    'fields' => []],
            ['path' => '/excuses/oops',         'method' => 'GET', 'note' => 'why it went wrong 🙈','fields' => []],
            ['path' => '/excuses/ring-ring',    'method' => 'GET', 'note' => 'why you did not pick up 📞',     'fields' => []],
            ['path' => '/excuses/late',         'method' => 'GET', 'note' => 'why you are late ⏰',            'fields' => []],
            ['path' => '/excuses/alibis',       'method' => 'GET', 'note' => 'why you were not there 😉',      'fields' => []],
        ],
    ],
    [
        'group'   => 'The Ministry',
        'caption' => 'graded in newtons',
        'items'   => [
            ['path' => '/ministry/gentle-correction', 'method' => 'GET', 'note' => 'd6 against approved remedies 💪', 'fields' => []],
            ['path' => '/ministry/mandatory-pet-adoption', 'new' => true, 'method' => 'GET', 'note' => 'resistance futile 🐻', 'fields' => []],
        ],
    ],
    [
        'group'   => 'The cage',
        'collapsed' => true,
        'caption' => '50/50, fingers first, toes for the brave',
        'items'   => [
            ['path' => '/cage/finger',           'method' => 'GET', 'note' => '50 animals. costs a 👉 if taken', 'fields' => []],
            ['path' => '/cage/fictional/finger', 'method' => 'GET', 'note' => '50 fictional creatures, same 🫵',  'fields' => []],
            ['path' => '/cage/finger/left',      'method' => 'GET', 'note' => 'what remains, out of 10 each 🖐️',        'fields' => []],
            ['path' => '/cage/finger/reset',     'method' => 'GET', 'note' => 'pray to the holy hairy toe 🦶',          'fields' => []],
        ],
    ],
    [
        'group'   => 'Unhinged',
        'caption' => 'no supervision',
        'items'   => [
            ['path' => '/unhinged/8ball',         'method' => 'GET', 'note' => 'answers, unreliably 🎱',        'fields' => []],
            ['path' => '/unhinged/optimism',      'method' => 'GET', 'note' => 'unearned positivity 😵‍💫',        'fields' => []],
            ['path' => '/unhinged/pessimism',     'method' => 'GET', 'note' => 'unearned dread 😮‍💨',             'fields' => []],
            ['path' => '/unhinged/advice',        'method' => 'GET', 'note' => 'applies to almost anything 🫢', 'fields' => []],
            ['path' => '/unhinged/non-committal', 'method' => 'GET', 'note' => 'fifty ways to not answer 😶',   'fields' => []],
            ['path' => '/unhinged/optimistic-dooom', 'method' => 'GET', 'note' => 'the end of everything, as good news 😅', 'fields' => []],
            ['path' => '/unhinged/turn-it-upside-down', 'display' => '\\unhinged\\turn-it-upside-down', 'method' => 'GET', 'note' => '🔃 it and find out 🙃', 'fields' => []],
            ['path' => '/unhinged/solid-suddenly-liquid', ' 'method' => 'GET', 'note' => 'a solid, liquefied 💦', 'fields' => []],
            ['path' => '/unhinged/solid-suddenly-gelatinous', 'method' => 'GET', 'note' => '🪨, now jelly 🍧', 'fields' => []],
            ['path' => '/unhinged/choose-your-duck', 'new' => true, 'method' => 'GET', 'note' => 'pick your 🛁 buddy', 'fields' => []],
            ['path' => '/unhinged/gravity-resigned', 'new' => true, 'method' => 'GET', 'note' => 'gravity has quit. time to float 🫧', 'fields' => []],
            ['path' => '/unhinged/vengeful-weather', 'new' => true, 'method' => 'GET', 'note' => 'the sky, personally offended ⛈️', 'fields' => []],
        ],
    ],
    [
        'group'   => 'Vitals',
        'collapsed' => true,
        'caption' => 'is anything on fire',
        'items'   => [
            ['path' => '/healthz', 'method' => 'GET', 'note' => 'liveness, plus lifetime counters 💊', 'fields' => []],
        ],
    ],
];

// ================================================================= helpers

function chaos_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Does an IP fall inside a single IP or CIDR range? Handles v4 and v6. */
function chaos_ip_matches(string $ip, string $range): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strpos($range, '/') === false) {
        $target = @inet_pton($range);
        return $target !== false && $target === $bin;
    }
    [$subnet, $bits] = explode('/', $range, 2);
    $subnetBin = @inet_pton($subnet);
    if ($subnetBin === false || strlen($subnetBin) !== strlen($bin)) {
        return false;
    }
    $bits    = (int) $bits;
    $maxBits = strlen($bin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;
    if ($whole > 0 && strncmp($bin, $subnetBin, $whole) !== 0) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return ($bin[$whole] & $mask) === ($subnetBin[$whole] & $mask);
}

function chaos_ip_trusted(string $ip): bool
{
    foreach (TRUSTED_PROXIES as $range) {
        if (chaos_ip_matches($ip, $range)) {
            return true;
        }
    }
    return false;
}

/** Valid IPs from the inbound X-Forwarded-For header, left to right. */
function chaos_forwarded_chain(): array
{
    $raw = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $hop) {
        $hop = trim($hop);
        if ($hop !== '' && filter_var($hop, FILTER_VALIDATE_IP)) {
            $out[] = $hop;
        }
    }
    return $out;
}

/**
 * The IP of the person actually clicking buttons.
 *
 * CF-Connecting-IP first: Cloudflare rewrites it on every request, so when the
 * site is only reachable through Cloudflare it is the visitor's real address.
 * True-Client-IP is the Enterprise equivalent. Failing both, we walk the
 * X-Forwarded-For chain from the right and take the first hop we did not put
 * there ourselves, and failing that, REMOTE_ADDR.
 */
function chaos_client_ip(): string
{
    $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = TRUST_CLOUDFLARE_HEADER || ($remote !== '' && chaos_ip_trusted($remote));

    if ($trusted) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'] as $header) {
            $candidate = trim((string) ($_SERVER[$header] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    if ($remote === '' || !chaos_ip_trusted($remote)) {
        return $remote !== '' ? $remote : '0.0.0.0';
    }

    $chain = chaos_forwarded_chain();
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        if (!chaos_ip_trusted($chain[$i])) {
            return $chain[$i];
        }
    }
    return $chain[0] ?? $remote;
}

/**
 * The pile this visitor owns. This is exactly the address shown as "you" and
 * sent in the IP headers — chaos_client_ip(), nothing added — so the pile the
 * frontend acts on is always the caller's own.
 */
function chaos_pile_id(): string
{
    return chaos_client_ip();
}

/** Cloudflare's two-letter country code for this visitor, when present. */
function chaos_client_country(): ?string
{
    $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = TRUST_CLOUDFLARE_HEADER || ($remote !== '' && chaos_ip_trusted($remote));
    $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (!$trusted || !preg_match('/^[A-Z]{2}$|^XX$|^T1$/', $country)) {
        return null;
    }
    return $country;
}

/** Forwarding headers to send upstream, behaving like a polite reverse proxy. */
function chaos_forward_headers(): array
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $client = chaos_client_ip();

    $chain = chaos_ip_trusted($remote) ? chaos_forwarded_chain() : [];
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
        $chain[] = $remote;
    }
    if ($chain === []) {
        $chain = [$client];
    }

    $forFor = strpos($client, ':') !== false ? '"[' . $client . ']"' : $client;
    $proto  = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');

    $headers = [
        // Cloudflare's own header name, carrying the visitor rather than this
        // server. If the API keys piles off CF-Connecting-IP, this is the one
        // it reads. See the note on API_BASE about Cloudflare overwriting it.
        'CF-Connecting-IP: ' . $client,
        'True-Client-IP: ' . $client,
        // Same value under our own name, which nothing in the path rewrites.
        CLIENT_IP_HEADER . ': ' . $client,
        'X-Forwarded-For: ' . implode(', ', $chain),
        'X-Real-IP: ' . $client,
        'X-Forwarded-Proto: ' . $proto,
        'Forwarded: for=' . $forFor . ';proto=' . $proto . ($host !== '' ? ';host=' . $host : ''),
    ];

    if (FRONTEND_KEY !== '') {
        $headers[] = 'X-Chaos-Frontend-Key: ' . FRONTEND_KEY;
    }

    $country = chaos_client_country();
    if ($country !== null) {
        $headers[] = 'X-Chaos-Client-Country: ' . $country;
    }

    if ($host !== '') {
        $headers[] = 'X-Forwarded-Host: ' . $host;
    }
    return $headers;
}

function chaos_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================ debug mode (?debug=1, no call)

if (isset($_GET['debug']) && !isset($_GET['path'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $sending = [];
    foreach (chaos_forward_headers() as $line) {
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $sending[$key] = (strtolower($key) === 'x-chaos-frontend-key') ? '(redacted)' : $value;
    }

    echo json_encode([
        'resolved_client_ip' => chaos_client_ip(),
        'resolved_from'      => (trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')) !== '')
                                ? 'CF-Connecting-IP'
                                : 'REMOTE_ADDR or X-Forwarded-For (no Cloudflare header on this request)',
        'trust_cf_header'    => TRUST_CLOUDFLARE_HEADER,
        'pile_id'            => chaos_pile_id(),
        'pile_id_mode'       => PILE_ID_MODE,
        'reset_sends'        => 'DELETE ' . rtrim(API_BASE, '/') . '/pound/dirt?'
                              . http_build_query([PILE_PARAM => chaos_pile_id()]),
        'remote_addr'        => $remote,
        'remote_is_trusted'  => chaos_ip_trusted($remote),
        'received_from_edge' => [
            'CF-Connecting-IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            'True-Client-IP'   => $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
            'CF-IPCountry'     => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
            'X-Forwarded-For'  => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        ],
        'sending_upstream'   => $sending,
        'upstream'           => API_BASE,
        'frontend_key_set'   => FRONTEND_KEY !== '',
        'note'               => 'The API keys piles by whatever it reads. Compare resolved_client_ip '
                              . 'with the pile id it returns — if they differ, the API is not reading '
                              . CLIENT_IP_HEADER . '.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// =================================================== proxy mode (?path=...)

if (isset($_GET['path'])) {

    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
        header('Allow: GET, POST, DELETE');
        chaos_fail(405, 'This proxy speaks GET, POST and DELETE only.');
    }

    $path = (string) $_GET['path'];
    if ($path === '' || $path[0] !== '/') {
        chaos_fail(400, 'Path must start with a slash. Try ?path=/healthz');
    }
    if (strlen($path) > 256) {
        chaos_fail(400, 'That path is far too long.');
    }
    if (strpos($path, '//') === 0 || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
        chaos_fail(400, 'Path contains something it should not.');
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        chaos_fail(400, 'Absolute URLs are not forwarded.');
    }

    $allowed = false;
    foreach (ALLOWED_PATHS as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        chaos_fail(403, 'That endpoint is not on the list. Add it to ALLOWED_PATHS.');
    }

    if ($method === 'DELETE') {
        $deletable = false;
        foreach (DELETE_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $deletable = true;
                break;
            }
        }
        if (!$deletable) {
            header('Allow: GET, POST');
            chaos_fail(405, 'That endpoint does not take DELETE.');
        }
    }

    $query = [];
    foreach ($_GET as $key => $value) {
        if ($key === 'path' || $key === 'raw' || !in_array($key, ALLOWED_PARAMS, true)) {
            continue;
        }
        if (is_string($value) && $value !== '' && strlen($value) <= 128) {
            $query[$key] = $value;
        }
    }

    $target = rtrim(API_BASE, '/') . $path;

    // One pile per IP. Set here, not by the browser, on every verb including
    // the DELETE reset, so the API is told which pile to level.
    foreach (PILE_ID_PATHS as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            $query[PILE_PARAM] = chaos_pile_id();
            break;
        }
    }

    if ($query !== []) {
        $target .= '?' . http_build_query($query);
    }

    $headers = ['Accept: application/json, text/plain;q=0.9, */*;q=0.8'];
    if (FORWARD_CLIENT_IP) {
        $headers = array_merge($headers, chaos_forward_headers());
    }

    $ua = str_replace(["\r", "\n"], '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $headers[] = 'User-Agent: ' . ($ua !== '' && strlen($ua) < 512 ? $ua . ' (via chaos-frontend)' : 'chaos-frontend/1.0');

    $lang = str_replace(["\r", "\n"], '', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($lang !== '' && strlen($lang) < 128) {
        $headers[] = 'Accept-Language: ' . $lang;
    }

    $requestBody = null;
    if ($method === 'POST') {
        $requestBody = (string) file_get_contents('php://input');
        if (strlen($requestBody) > 8192) {
            chaos_fail(413, 'Request body too large.');
        }
        $headers[] = 'Content-Type: ' . str_replace(["\r", "\n"], '', (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/json'));
    }

    $responseHeaders = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL              => $target,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => false,
        CURLOPT_CONNECTTIMEOUT   => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT          => TIMEOUT,
        CURLOPT_HTTPHEADER       => $headers,
        CURLOPT_CUSTOMREQUEST    => $method,
        CURLOPT_ENCODING         => '',
        CURLOPT_SSL_VERIFYPEER   => true,
        CURLOPT_SSL_VERIFYHOST   => 2,
        CURLOPT_HEADERFUNCTION   => function ($ch, $line) use (&$responseHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
        CURLOPT_NOPROGRESS       => false,
        CURLOPT_PROGRESSFUNCTION => fn($ch, $dlTotal, $dlNow) => $dlNow > MAX_BYTES ? 1 : 0,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody ?? '');
    }

    // Optional: connect to the origin IP directly, bypassing Cloudflare's edge,
    // while still presenting the real Host and SNI so TLS and routing work.
    if (API_ORIGIN_IP !== '') {
        $hostPart = parse_url(API_BASE, PHP_URL_HOST) ?: '';
        $portPart = parse_url(API_BASE, PHP_URL_PORT)
            ?: (parse_url(API_BASE, PHP_URL_SCHEME) === 'http' ? 80 : 443);
        if ($hostPart !== '') {
            curl_setopt($ch, CURLOPT_RESOLVE, [$hostPart . ':' . $portPart . ':' . API_ORIGIN_IP]);
        }
    }

    $started  = microtime(true);
    $result   = curl_exec($ch);
    $tookMs   = (int) round((microtime(true) - $started) * 1000);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    $curlCode = curl_errno($ch);
    curl_close($ch);

    if ($result === false) {
        chaos_fail(502, $curlCode === CURLE_ABORTED_BY_CALLBACK
            ? 'Upstream response exceeded the size limit.'
            : 'Could not reach the API: ' . $curlErr);
    }

    // Cloudflare answered for the origin rather than the origin answering. This
    // is a 1xxx edge error — the request never reached the API, so it is not a
    // pile problem. Error 1000 (dns_loop) means the API's DNS points at a
    // Cloudflare IP; the fix is in the api.dumpsterfire.uk DNS panel, not here.
    $isCfEdge = (($responseHeaders['server'] ?? '') === 'cloudflare')
             && $status >= 400
             && (preg_match('/error code:\s*(\d{4})/i', $result, $m)
                 || stripos($result, 'cloudflare') !== false);
    if ($isCfEdge) {
        $code = $m[1] ?? null;
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'          => false,
            'status'      => $status,
            'path'        => $path,
            'method'      => $method,
            'took_ms'     => $tookMs,
            'client_ip'   => chaos_client_ip(),
            'edge_error'  => true,
            'cf_code'     => $code,
            'cf_ray'      => $responseHeaders['cf-ray'] ?? null,
            'error'       => $code === '1000'
                ? 'Cloudflare error 1000 at the edge: the API DNS record points at a '
                  . 'Cloudflare IP (dns_loop). The request never reached the API. Fix the '
                  . 'A/AAAA record for api.dumpsterfire.uk to point at the origin server — '
                  . 'this is not a frontend or pile problem.'
                : 'Cloudflare edge error ' . ($code ?? $status) . '. The request was rejected '
                  . 'at Cloudflare before reaching the API.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (isset($_GET['raw']) && $_GET['raw'] !== '0') {
        http_response_code($status ?: 502);
        header('Content-Type: ' . ($responseHeaders['content-type'] ?? 'application/json; charset=utf-8'));
        echo $result;
        exit;
    }

    $decoded = json_decode($result, true);
    $isJson  = json_last_error() === JSON_ERROR_NONE;

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'        => $status >= 200 && $status < 400,
        'status'    => $status,
        'path'      => $path,
        'method'    => $method,
        'took_ms'   => $tookMs,
        'client_ip' => chaos_client_ip(),
        'headers'   => array_intersect_key($responseHeaders, array_flip(['content-type', 'x-request-id', 'retry-after', 'date'])),
        'json'      => $isJson ? $decoded : null,
        'body'      => $isJson ? null : $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================== page

$clientIp  = chaos_client_ip();
$country   = chaos_client_country();
$sectionNo = 0;
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>chaos.sh &mdash; the api of chaos</title>
<meta name="description" content="A terminal for The API of Chaos. Click a command or type a path.">
<meta name="color-scheme" content="dark">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
:root {
  --bg:      #05070a;
  --pane:    #090c11;
  --line:    #1a212a;
  --fg:      #b7c1bd;
  --dim:     #5c6a68;
  --amber:   #ffb454;
  --cyan:    #6fb3c0;
  --red:     #ff6b5e;
  --green:   #86c17c;
  --mono: "JetBrains Mono", ui-monospace, "SF Mono", "Cascadia Mono", Menlo, Consolas, monospace;
}

* { box-sizing: border-box; }

html, body { height: 100%; }

body {
  margin: 0;
  background: var(--bg);
  color: var(--fg);
  font-family: var(--mono);
  font-size: 14px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}

/* faint CRT texture, kept low enough to read through */
body::after {
  content: "";
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 9;
  background:
    repeating-linear-gradient(to bottom, rgba(255, 255, 255, 0.018) 0 1px, transparent 1px 3px),
    radial-gradient(ellipse at center, transparent 55%, rgba(0, 0, 0, 0.45) 100%);
}

a { color: var(--cyan); }
a:hover { color: var(--amber); }

.term {
  max-width: 1180px;
  margin: 0 auto;
  padding: 1.75rem 1.25rem 2.5rem;
}

/* ------------------------------------------------------------- banner */

.banner__title {
  margin: 0;
  font-size: clamp(1.4rem, 4.5vw, 2.1rem);
  font-weight: 700;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--amber);
}
.banner__title::before { content: "🗑️🔥 "; -webkit-text-fill-color: initial; text-shadow: none; }

/* cool on the left, fully alight by the right */
.banner__grad {
  background-image: linear-gradient(100deg,
    #6fc36b 0%,
    #b8cf5c 22%,
    #ffb454 48%,
    #ff8034 72%,
    #ef3d21 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  -webkit-text-fill-color: transparent;
  filter: drop-shadow(0 0 20px rgba(255, 110, 50, 0.22));
}
@supports not ((background-clip: text) or (-webkit-background-clip: text)) {
  .banner__grad { color: var(--amber); -webkit-text-fill-color: var(--amber); }
}

.caret {
  display: inline-block;
  width: 0.55em;
  height: 1.05em;
  margin-left: 0.15em;
  vertical-align: -0.16em;
  background: #ef3d21;
  animation: blink 1.1s steps(1) infinite;
}
@keyframes blink { 0%, 49% { opacity: 1; } 50%, 100% { opacity: 0; } }

.banner__lines { margin: 0.75rem 0 0; color: var(--dim); }
.banner__lines span { display: block; }
.banner__lines b { color: var(--fg); font-weight: 400; }

.statusbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0 1.5rem;
  margin: 1.25rem 0 0;
  padding: 0.35rem 0.75rem;
  background: #10161d;
  border: 1px solid var(--line);
  color: var(--dim);
  font-size: 0.8rem;
}
.statusbar b { color: var(--amber); font-weight: 400; }

/* -------------------------------------------------------------- panes */

.panes {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
  border-top: 0;
}
@media (max-width: 900px) { .panes { grid-template-columns: 1fr; } }

.pane { background: var(--pane); min-width: 0; }

.pane__bar {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.3rem 0.75rem;
  border-bottom: 1px solid var(--line);
  background: #0d1218;
  color: var(--dim);
  font-size: 0.78rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.pane__bar b { color: var(--fg); font-weight: 400; text-transform: none; letter-spacing: 0; }

.barbtn {
  font: inherit;
  font-size: 0.74rem;
  letter-spacing: 0.06em;
  color: var(--dim);
  background: none;
  border: 1px solid var(--line);
  padding: 0.05rem 0.5rem;
  cursor: pointer;
  text-transform: none;
}
.barbtn:hover { color: var(--amber); border-color: var(--amber); }
.barbtn:focus-visible { outline: 1px solid var(--amber); outline-offset: 1px; }

.pane__body { padding: 0.85rem 0.75rem 1.1rem; }

/* ----------------------------------------------------------- commands */

.grpwrap { margin: 1.25rem 0 0; }
.grpwrap:first-child { margin-top: 0; }

.grp {
  list-style: none;
  cursor: pointer;
  margin: 0 0 0.35rem;
  font-size: 0.82rem;
  font-weight: 400;
  color: var(--dim);
  user-select: none;
}
.grp::-webkit-details-marker { display: none; }
.grp::before { content: "# "; }
.grp:hover { color: var(--fg); }
.grp:focus-visible { outline: 1px solid var(--amber); outline-offset: 2px; }
.grp em { font-style: normal; color: #3f4a49; }

.grp__chev {
  display: inline-block;
  width: 1em;
  color: var(--amber);
  transition: transform 120ms ease;
}
.grpwrap:not([open]) .grp__chev { transform: rotate(-90deg); }
.grpwrap:not([open]) .grp { color: #46514f; }
@media (prefers-reduced-motion: reduce) { .grp__chev { transition: none; } }

.row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0 0.5rem;
  padding: 0.12rem 0.4rem;
  border-left: 2px solid transparent;
}
.row:hover, .row:focus-within { background: #0f151c; border-left-color: var(--amber); }

.run {
  font: inherit;
  color: var(--fg);
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
  text-align: left;
}
.run::before { content: "› "; color: var(--dim); }
.run:hover { color: var(--amber); }
.run:focus-visible { outline: 1px solid var(--amber); outline-offset: 2px; }
.run[disabled] { color: var(--dim); cursor: progress; }
.run .verb { color: var(--cyan); }

.icon { font-size: 0.95em; line-height: 1; margin-left: -0.15rem; }

.badge-new {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--bg);
  background: var(--amber);
  padding: 0.05rem 0.35rem;
  border-radius: 2px;
  animation: newflash 1s steps(1) infinite;
}
@keyframes newflash {
  0%, 49%   { background: var(--amber); color: var(--bg); opacity: 1; }
  50%, 100% { background: transparent; color: var(--amber); opacity: 0.85; box-shadow: inset 0 0 0 1px var(--amber); }
}
@media (prefers-reduced-motion: reduce) {
  .badge-new { animation: none; box-shadow: inset 0 0 0 1px var(--amber); background: transparent; color: var(--amber); }
}

.flag { color: var(--dim); font-size: 0.9em; }
.flag input {
  width: 4.5rem;
  font: inherit;
  color: var(--amber);
  background: #0d1218;
  border: 1px solid var(--line);
  padding: 0 0.3rem;
  caret-color: var(--amber);
}
.flag input:focus-visible { outline: 1px solid var(--amber); outline-offset: 1px; }
.flag input::placeholder { color: #3f4a49; }

.note { color: #465250; font-size: 0.86em; margin-left: auto; }
@media (max-width: 620px) { .note { display: none; } }

/* ------------------------------------------------------------ session */

.log {
  padding: 0.85rem 0.75rem 0;
  height: min(62vh, 34rem);
  overflow-y: auto;
  scrollbar-color: var(--line) transparent;
}
@media (max-width: 900px) { .log { height: 24rem; } }

.log__hint { color: var(--dim); margin: 0 0 1rem; }

.entry { margin: 0 0 1.15rem; }
.entry__cmd { color: var(--fg); word-break: break-all; }
.entry__cmd::before { content: "🗑️🔥 "; font-size: 0.9em; }
.entry__out {
  margin: 0.35rem 0 0;
  padding-left: 0.9rem;
  border-left: 1px solid var(--line);
  color: var(--fg);
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.92em;
}
.entry__out .key { color: var(--cyan); }
.entry__meta { margin-top: 0.3rem; color: var(--dim); font-size: 0.82em; }
.entry__meta .ok { color: var(--green); }
.entry__meta .muted { color: var(--dim); }
.entry__meta .bad { color: var(--red); }
.entry--bad .entry__out { border-left-color: var(--red); color: var(--red); }

.prompt {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-top: 1px solid var(--line);
  padding: 0.5rem 0.75rem;
  background: #0d1218;
}
.prompt__sigil { color: var(--amber); white-space: nowrap; }
.prompt input {
  flex: 1;
  min-width: 0;
  font: inherit;
  color: var(--fg);
  background: none;
  border: 0;
  padding: 0;
  caret-color: var(--amber);
}
.prompt input:focus { outline: 0; }
.prompt input::placeholder { color: #3a4544; }

@media (prefers-reduced-motion: reduce) {
  .caret { animation: none; }
}

footer.foot {
  margin-top: 1.1rem;
  color: var(--dim);
  font-size: 0.8rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem 1.75rem;
}

/* ----------------------------------------------------------- mobile */

@media (max-width: 560px) {
  body { font-size: 15px; }
  .term { padding: 1.1rem 0.85rem 2rem; }

  .masthead__title { font-size: clamp(1.5rem, 8vw, 2rem); }
  .masthead__sub { font-size: 0.95rem; }
  .masthead__meta { gap: 0.15rem 1.25rem; font-size: 0.7rem; }

  /* status bar reads as stacked label/value pairs instead of one crowded row */
  .statusbar { gap: 0.1rem 1rem; font-size: 0.74rem; padding: 0.4rem 0.6rem; }

  .pane__body { padding: 0.75rem 0.6rem 1rem; }

  /* each command becomes its own stacked card: button on top, controls below,
     note beneath — nothing gets squeezed off-screen or hidden */
  .row {
    flex-direction: column;
    align-items: stretch;
    gap: 0.3rem;
    padding: 0.55rem 0.5rem;
    border-left-width: 3px;
  }
  .run {
    font-size: 0.95rem;
    padding: 0.25rem 0;
    min-height: 40px;            /* comfortable tap target */
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    word-break: break-word;
  }
  .entry__controls,
  .row > .badge-new { align-self: flex-start; }

  .flag { font-size: 0.78rem; }
  .flag input {
    width: 100%;
    min-width: 4rem;
    min-height: 38px;
    font-size: 16px;             /* stops iOS Safari zooming on focus */
  }

  /* notes were hidden at 620px; on a stacked card there's room to keep them */
  .note { display: block; margin-left: 0; font-size: 0.85em; }

  .barbtn { min-height: 34px; padding: 0.2rem 0.7rem; }

  .log { height: 20rem; padding: 0.75rem 0.6rem 0; }
  .entry__out { font-size: 0.88em; }

  .prompt { padding: 0.55rem 0.6rem; gap: 0.4rem; }
  .prompt input { font-size: 16px; }   /* no focus-zoom on iOS */

  .docket, .term { max-width: 100%; }

  footer.foot { gap: 0.25rem 1.25rem; font-size: 0.74rem; }
}

/* very narrow phones */
@media (max-width: 360px) {
  .masthead__meta span, .statusbar span { flex-basis: 100%; }
}
</style>
</head>
<body>

<div class="term">

  <header class="banner">
    <h1 class="banner__title"><span class="banner__grad">the api of chaos</span><span class="caret" aria-hidden="true"></span></h1>
    <p class="banner__lines">
      <span>v1.0.5 &mdash; GPL-3.0 &mdash; <a href="https://github.com/MichelleFindlay/the-api-of-chaos">github.com/MichelleFindlay/the-api-of-chaos</a></span>
      <span>click a command on the left, or type a path below (<b>DELETE /pound/dirt</b> works too). <b>help</b> lists everything, <b>clear</b> wipes the session.</span>
    </p>
    <div class="statusbar">
      <span>upstream <b><?= chaos_h(API_BASE) ?></b></span>
      <span>you/pile <b><?= chaos_h($clientIp) ?></b><?php if (chaos_pile_id() !== $clientIp): ?> / <b><?= chaos_h(chaos_pile_id()) ?></b><?php endif; ?></span>
      <?php if ($country !== null): ?><span>region <b><?= chaos_h($country) ?></b></span><?php endif; ?>
      <span>date <b><?= chaos_h(gmdate('Y-m-d')) ?></b></span>
    </div>
  </header>

  <div class="panes">

    <section class="pane" aria-label="Commands">
      <div class="pane__bar">
        <span>endpoints</span>
        <button id="toggle-all" class="barbtn" type="button" aria-pressed="false">hide all</button>
      </div>
      <div class="pane__body">
        <?php foreach ($CATALOGUE as $group): $sectionNo++; ?>
        <details class="grpwrap"<?= empty($group['collapsed']) ? ' open' : '' ?>>
          <summary class="grp"><span class="grp__chev" aria-hidden="true">▾</span><?= chaos_h(strtolower($group['group'])) ?> <em>&mdash; <?= chaos_h(strtolower($group['caption'] ?? '')) ?></em></summary>
          <div class="grpbody">
          <?php foreach ($group['items'] as $item): ?>
          <div class="row">
            <button class="run"
                    data-path="<?= chaos_h($item['path']) ?>"
                    data-method="<?= chaos_h($item['method']) ?>"><span class="verb"><?= chaos_h($item['method']) ?></span> <?= chaos_h($item['display'] ?? $item['path']) ?></button>
            <?php if (!empty($item['icon'])): ?><span class="icon" aria-hidden="true"><?= chaos_h($item['icon']) ?></span><?php endif; ?>
            <?php if (!empty($item['new'])): ?><span class="badge-new">new</span><?php endif; ?>
            <?php foreach (($item['fields'] ?? []) as $field): ?>
            <label class="flag">--<?= chaos_h($field['name']) ?>=<input
                type="<?= chaos_h($field['type'] ?? 'text') ?>"
                data-param="<?= chaos_h($field['name']) ?>"
                <?php if (isset($field['min'])): ?>min="<?= (int) $field['min'] ?>"<?php endif; ?>
                <?php if (isset($field['max'])): ?>max="<?= (int) $field['max'] ?>"<?php endif; ?>
                placeholder="<?= chaos_h($field['placeholder'] ?? '') ?>"
                aria-label="<?= chaos_h($field['label']) ?> for <?= chaos_h($item['path']) ?>"></label>
            <?php endforeach; ?>
            <span class="note"><?= chaos_h(strtolower($item['note'] ?? '')) ?></span>
          </div>
          <?php endforeach; ?>
          </div>
        </details>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="pane" aria-label="Session">
      <div class="pane__bar"><span>session</span><b id="counter">0 calls</b></div>
      <div class="log" id="log" role="log" aria-live="polite">
        <p class="log__hint">no calls yet. pick something on the left, or type a path and hit enter.</p>
      </div>
      <div class="prompt">
        <span class="prompt__sigil" aria-hidden="true">chaos&nbsp;🗑️🔥</span>
        <label for="cli" class="sr-only" hidden>command</label>
        <input id="cli" type="text" autocomplete="off" spellcheck="false" placeholder="/unhinged/8ball">
      </div>
    </section>

  </div>

  <footer class="foot">
    <span>every call goes straight from your browser to the api</span>
    <span>your ip is your pile</span>
    <span>nothing here is load-bearing</span>
  </footer>

</div>

<script>
(function () {
  "use strict";

  var log     = document.getElementById("log");
  var hint    = log.querySelector(".log__hint");
  var cli     = document.getElementById("cli");
  var counter = document.getElementById("counter");

  var calls   = 0;
  var history = [];
  var histAt  = -1;

  var METHODS = ["GET", "POST", "DELETE"];

  // Every API call fires straight from the browser to Cloudflare, so the
  // connection Cloudflare sees is the real visitor's — no server in the middle
  // to be relabelled. The same-origin PHP proxy is no longer in the path.
  var API_BASE = <?= json_encode(rtrim(API_BASE, '/')) ?>;
  var DIRECT_RE = /^\//;

  var PATHS = Array.prototype.map.call(
    document.querySelectorAll(".run"),
    function (b) { return b.dataset.method + " " + b.dataset.path; }
  );

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  function push(commandText) {
    if (hint) { hint.remove(); hint = null; }
    var entry = el("div", "entry");
    entry.appendChild(el("div", "entry__cmd", commandText));
    var out = el("pre", "entry__out", "…");
    entry.appendChild(out);
    var meta = el("div", "entry__meta", "");
    entry.appendChild(meta);
    log.appendChild(entry);
    log.scrollTop = log.scrollHeight;
    return { entry: entry, out: out, meta: meta };
  }

  function finish(slot, text, metaHtml, bad) {
    slot.out.textContent = text;
    slot.meta.innerHTML = metaHtml;
    if (bad) { slot.entry.classList.add("entry--bad"); }
    log.scrollTop = log.scrollHeight;
  }

  function request(path, method, params) {
    var qs    = params && params.length ? params.join("&") : "";
    var shown = method + " " + path + (qs ? "?" + qs : "");
    var slot  = push(shown);

    calls += 1;
    counter.textContent = calls + (calls === 1 ? " call" : " calls");

    // Straight to the API. Cloudflare sees the browser's own connection, so the
    // pile — and everything else — is keyed to whoever is actually clicking.
    var direct = DIRECT_RE.test(path);
    var url = direct
      ? API_BASE + path + (qs ? "?" + qs : "")
      : "?path=" + encodeURIComponent(path) + (qs ? "&" + qs : "");

    return fetch(url, {
      method: METHODS.indexOf(method) === -1 ? "GET" : method,
      headers: { "Accept": "application/json" },
      credentials: "omit",
      mode: "cors"
    })
      .then(function (r) {
        return r.json().then(function (body) { return { status: r.status, ok: r.ok, body: body }; });
      })
      .then(function (res) {
        if (direct) {
          // Raw API JSON — the pile id in here is the browser's own IP.
          var text = JSON.stringify(res.body, null, 2);
          var tag = res.ok
            ? "<span class=\"ok\">exit 0</span>"
            : "<span class=\"bad\">exit " + res.status + "</span>";
          finish(slot, text, tag + " · " + res.status + " · direct to api", !res.ok);
          return;
        }
        var data = res.body;
        if (data.ok === false && data.error) {
          finish(slot, data.error, "<span class=\"bad\">exit 1</span> · refused by proxy", true);
          return;
        }
        var payload = (data.json !== null && data.json !== undefined) ? data.json : data.body;
        var text = typeof payload === "string" ? payload : JSON.stringify(payload, null, 2);
        var tag = data.ok
          ? "<span class=\"ok\">exit 0</span>"
          : "<span class=\"bad\">exit " + data.status + "</span>";
        finish(slot, text,
          tag + " · " + data.status + " · " + data.took_ms + "ms · seen as " + data.client_ip,
          !data.ok);
      })
      .catch(function () {
        finish(slot,
          direct
            ? "could not reach the api directly. the api must allow CORS from this page (Access-Control-Allow-Origin)."
            : "no response from the proxy. check the php error log.",
          "<span class=\"bad\">exit 1</span> · transport failure", true);
      });
  }

  function collect(row) {
    var params = [];
    row.querySelectorAll("input[data-param]").forEach(function (input) {
      var value = input.value.trim();
      if (value !== "") {
        params.push(encodeURIComponent(input.dataset.param) + "=" + encodeURIComponent(value));
      }
    });
    return params;
  }

  document.querySelectorAll(".run").forEach(function (button) {
    button.addEventListener("click", function () {
      button.disabled = true;
      request(button.dataset.path, button.dataset.method, collect(button.closest(".row")))
        .finally(function () { button.disabled = false; });
    });
  });

  function local(commandText, output) {
    var slot = push(commandText);
    finish(slot, output, "<span class=\"ok\">exit 0</span> · local", false);
  }

  cli.addEventListener("keydown", function (event) {
    if (event.key === "ArrowUp" || event.key === "ArrowDown") {
      if (!history.length) { return; }
      event.preventDefault();
      histAt = event.key === "ArrowUp"
        ? Math.max(0, (histAt === -1 ? history.length : histAt) - 1)
        : Math.min(history.length, histAt + 1);
      cli.value = history[histAt] || "";
      return;
    }
    if (event.key !== "Enter") { return; }

    var line = cli.value.trim();
    if (line === "") { return; }
    cli.value = "";
    history.push(line);
    histAt = -1;

    if (line === "clear") {
      log.innerHTML = "";
      calls = 0;
      counter.textContent = "0 calls";
      return;
    }
    if (line === "help" || line === "ls") {
      local(line, PATHS.join("\n") + "\n\nflags: --tier, --min, --max on rocks.\ntype them as a query string, e.g. /kick/rocks?tier=9\nthe dirt pile is fixed to your address and cannot be set.");
      return;
    }
    if (line === "ip" || line === "whoami") {
      local(line, <?= json_encode($clientIp) ?>);
      return;
    }
    if (line === "debug" || line === "env") {
      var slot = push(line);
      fetch("?debug=1", { headers: { "Accept": "application/json" } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          finish(slot, JSON.stringify(d, null, 2), "<span class=\"ok\">exit 0</span> · local", false);
        })
        .catch(function () {
          finish(slot, "debug endpoint unreachable.", "<span class=\"bad\">exit 1</span>", true);
        });
      return;
    }

    var method = "GET";
    var head = line.split(/\s+/)[0].toUpperCase();
    if (METHODS.indexOf(head) !== -1) {
      method = head;
      line = line.slice(line.split(/\s+/)[0].length).trim();
    }
    if (line === "") { return; }

    var path  = line.charAt(0) === "/" ? line : "/" + line;
    var parts = path.split("?");
    var params = parts[1] ? parts[1].split("&").filter(Boolean) : [];
    request(parts[0], method, params);
  });

  // Hide-all / show-all across every section, so you can clear the clutter and
  // reveal just the ones you want to run.
  var toggleAll = document.getElementById("toggle-all");
  var sections  = Array.prototype.slice.call(document.querySelectorAll(".grpwrap"));
  toggleAll.addEventListener("click", function () {
    var anyOpen = sections.some(function (s) { return s.open; });
    sections.forEach(function (s) { s.open = !anyOpen; });
    toggleAll.textContent = anyOpen ? "show all" : "hide all";
    toggleAll.setAttribute("aria-pressed", String(anyOpen));
  });
  // Keep the button label honest when sections are toggled individually.
  sections.forEach(function (s) {
    s.addEventListener("toggle", function () {
      var anyOpen = sections.some(function (x) { return x.open; });
      toggleAll.textContent = anyOpen ? "hide all" : "show all";
      toggleAll.setAttribute("aria-pressed", String(!anyOpen));
    });
  });

  cli.focus();
})();
</script>
</body>
</html>
