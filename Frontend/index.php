<?php
declare(strict_types=1);

/**
 * The API of Chaos — frontend.
 *
 * No external dependencies — just this file plus config.php
 * (configuration only: URLs, version, proxy trust, allowlists) sitting
 * next to it.
 *
 *   /index.php                              the page
 *   /index.php?path=/kick/rocks&tier=9      proxied JSON envelope
 *   /index.php?path=/healthz&raw=1          verbatim upstream status + body
 *   /index.php?changelog_test=stale         force-show the "changelog is a
 *                                            tombstone" banner line, ignoring
 *                                            the real GitHub release check
 *   /index.php?changelog_test=fresh         force-hide it instead
 *
 * Requests are proxied server side and the caller's IP is forwarded upstream,
 * so /pound/dirt still attributes piles to the right person.
 */

require __DIR__ . '/config.php';

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
        'collapsed' => true,
        'caption' => 'graded in newtons',
        'items'   => [
            ['path' => '/ministry/gentle-correction', 'method' => 'GET', 'note' => 'd6 against approved remedies 💪', 'fields' => []],
            ['path' => '/ministry/mandatory-pet-adoption', 'method' => 'GET', 'note' => 'resistance futile 🐻', 'fields' => []],
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
            ['path' => '/unhinged/optimistic-dooom', 'method' => 'GET', 'note' => 'end of everything, as good news 😅', 'fields' => []],
            ['path' => '/unhinged/turn-it-upside-down', 'display' => '\\unhinged\\turn-it-upside-down', 'method' => 'GET', 'note' => '🔃 it and find out 🙃', 'fields' => []],
            ['path' => '/unhinged/solid-suddenly-liquid', 'method' => 'GET', 'note' => 'a solid, liquefied 💦', 'fields' => []],
            ['path' => '/unhinged/solid-suddenly-gelatinous', 'method' => 'GET', 'note' => '🪨, now jelly 🍧', 'fields' => []],
            ['path' => '/unhinged/choose-your-duck', 'method' => 'GET', 'note' => 'pick your 🛁 buddy', 'fields' => []],
            ['path' => '/unhinged/gravity-resigned', 'method' => 'GET', 'note' => 'gravity quit. now float 🫧', 'fields' => []],
            ['path' => '/unhinged/vengeful-weather', 'new' => true, 'method' => 'GET', 'note' => 'the sky, now upset ⛈️', 'fields' => []],
            ['path' => '/unhinged/wrongfall', 'new' => true, 'method' => 'GET', 'note' => 'Clouds went feral. 🌧️', 'fields' => []],
            ['path' => '/unhinged/poke', 'new' => true, 'method' => 'GET', 'note' => 'poke, then escalate dramatically 👉', 'fields' => []],
            ['path' => '/unhinged/storage-buddies', 'new' => true, 'method' => 'GET', 'note' => 'furniture following you 🗄️', 'fields' => []],
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
    $trusted = ($remote !== '' && chaos_ip_trusted($remote));

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
    $trusted = ($remote !== '' && chaos_ip_trusted($remote));
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

/**
 * Pulls the first x.y.z-shaped number out of a GitHub release's "name" or
 * "tag_name" (tags here are inconsistent — "v1.05", "v1.0.5" — so this
 * normalises rather than trusting either verbatim).
 */
function chaos_extract_semver(?string $raw): ?string
{
    if ($raw !== null && preg_match('/\d+(?:\.\d+)+/', $raw, $m)) {
        return $m[0];
    }
    return null;
}

/**
 * The latest release version published on GitHub, cached for an hour so
 * this doesn't hit GitHub's API on every page load (and so this server's
 * shared outbound IP doesn't run into its unauthenticated rate limit).
 * Returns null if it can't be determined — network failure, no releases,
 * an unparseable tag — in which case the caller should assume nothing.
 */
function chaos_latest_release_version(): ?string
{
    $cacheFile = RELEASE_CACHE_FILE;
    $cached    = @file_get_contents($cacheFile);
    if ($cached !== false) {
        $data = json_decode($cached, true);
        if (is_array($data) && isset($data['checked_at']) && (time() - (int) $data['checked_at']) < 3600) {
            return is_string($data['version'] ?? null) ? $data['version'] : null;
        }
    }

    $ch = curl_init('https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['User-Agent: chaos-frontend/1.0', 'Accept: application/vnd.github+json'],
    ]);
    $body = curl_exec($ch);
    $ok   = curl_errno($ch) === 0 && (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($ch);

    $version = null;
    if ($ok) {
        $json = json_decode((string) $body, true);
        if (is_array($json)) {
            $version = chaos_extract_semver($json['name'] ?? null) ?? chaos_extract_semver($json['tag_name'] ?? null);
        }
    }

    // Cache the result either way — including a failed lookup — so a
    // GitHub outage doesn't turn into a curl call on every single request.
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0770, true);
    }
    @file_put_contents($cacheFile, json_encode(['checked_at' => time(), 'version' => $version]));

    return $version;
}

/**
 * Whether the "changelog is a tombstone" banner line should show. It
 * only shows while this site is running behind the latest published
 * GitHub release — i.e. there's a newer release than what's deployed,
 * so pointing people at "the latest release" actually tells them
 * something they don't already have. The moment this site's version
 * catches up (or pulls ahead of) the latest release, it's redundant —
 * you're already running the newest thing — so it hides itself.
 * Nothing to remember to toggle either way.
 *
 * ?changelog_test=stale / =fresh force one state or the other, bypassing
 * the real GitHub check entirely, so both states can be checked without
 * waiting for an actual release.
 */
function changelog_is_stale(): bool
{
    $test = $_GET['changelog_test'] ?? null;
    if ($test === 'stale') {
        return true;
    }
    if ($test === 'fresh') {
        return false;
    }

    $latest = chaos_latest_release_version();
    if ($latest === null) {
        // Can't tell — assume this site isn't behind rather than show
        // a notice that might not be accurate.
        return false;
    }

    return version_compare(APP_VERSION, $latest, '<');
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
        'trust_cf_header'    => chaos_ip_trusted($remote),
        'trust_cf_header_note' => 'Detected automatically per request from remote_is_trusted below, not a fixed setting.',
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

/** MCP connector URLs live on the web host, so they follow it to staging too. */
$mcpWebBase    = (($_SERVER['HTTP_HOST'] ?? '') === parse_url(STAGING_WEB_URL, PHP_URL_HOST)) ? STAGING_WEB_URL : WEB_URL;
$mcpOpenApiUrl = rtrim($mcpWebBase, '/') . '/mcp';
$mcpClaudeUrl  = rtrim($mcpWebBase, '/') . '/mcp-claude';
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>chaos.sh &mdash; the api of chaos</title>
<meta name="description" content="A terminal for The API of Chaos. Click a command or type a path.">
<meta name="color-scheme" content="dark light">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<script src="js/theme-init.js"></script>
<link rel="stylesheet" href="css/theme.css">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/banner.css">
<link rel="stylesheet" href="css/panes.css">
<link rel="stylesheet" href="css/commands.css">
<link rel="stylesheet" href="css/session.css">
<link rel="stylesheet" href="css/footer.css">
<link rel="stylesheet" href="css/dialog.css">
<link rel="stylesheet" href="css/responsive.css">
</head>
<body>

<div class="term">

  <header class="banner">
    <h1 class="banner__title"><span class="banner__grad">the api of chaos</span><span class="caret" aria-hidden="true"></span></h1>
    <p class="banner__lines">
      <span>v<?= chaos_h(APP_VERSION) ?> &mdash; GPL-3.0 &mdash; <a href="https://github.com/MichelleFindlay/the-api-of-chaos">github.com/MichelleFindlay/the-api-of-chaos</a></span>
      <?php if (changelog_is_stale()): ?><span><b>The changelog is a 🪦 now.</b> Check <a href="https://github.com/MichelleFindlay/the-api-of-chaos/releases">the latest release</a> instead.</span><?php endif; ?>
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
        <input id="cli" type="text" autocomplete="off" spellcheck="false" placeholder="/unhinged/8ball"
               role="combobox" aria-autocomplete="list" aria-expanded="false"
               aria-controls="cli-suggest" aria-haspopup="listbox">
        <div id="cli-suggest" class="cli-suggest" role="listbox" hidden></div>
      </div>
    </section>

  </div>

  <footer class="foot">
    <?php if (($_SERVER['HTTP_HOST'] ?? '') === parse_url(STAGING_WEB_URL, PHP_URL_HOST)): ?><span><b>Beta / Testing</b></span><?php endif; ?>
    <span>every call goes straight from your browser to the api</span>
    <span>your ip is your pile</span>
    <span>nothing here is load-bearing</span>
    <button id="theme-toggle-btn" class="barbtn" type="button" aria-label="Theme: dark. Click to switch.">dark</button>
    <button id="mcp-access-btn" class="mcp-btn" type="button">MCP Access</button>
  </footer>

  <dialog id="mcp-dialog" class="mcp-dialog">
    <div class="mcp-dialog__bar">
      <span>mcp access</span>
      <button id="mcp-dialog-close" class="barbtn" type="button">close</button>
    </div>
    <div class="mcp-dialog__body">
      <section>
        <h3>Adding OpenAI MCP</h3>
        <dl class="mcp-kv">
          <dt>Type</dt><dd>OpenAPI</dd>
          <dt>Name</dt><dd>api-of-chaos</dd>
          <dt>ID (optional)</dt><dd>api-of-chaos</dd>
          <dt>Description</dt><dd>(leave empty)</dd>
          <dt>URL</dt><dd><code><?= chaos_h($mcpOpenApiUrl) ?></code></dd>
          <dt>Enabled toggle</dt><dd>on</dd>
          <dt>Auth</dt><dd>None (no authentication)</dd>
          <dt>Advanced</dt><dd>(leave collapsed)</dd>
          <dt>Access Control</dt><dd>(not configured)</dd>
          <dt>Function Name Filter List</dt><dd>(leave empty &mdash; e.g. func1, !func2)</dd>
        </dl>
        <p>Click <b>Save</b>.</p>
      </section>
      <section>
        <h3>Adding Claude Connector</h3>
        <ol>
          <li>In Claude, go to <b>Settings &rarr; Connectors &rarr; Add custom connector</b>.</li>
          <li>Name: anything (<code>api-of-chaos</code> works). URL: <code><?= chaos_h($mcpClaudeUrl) ?></code></li>
          <li>Claude's automatic server check is unreliable against this server regardless of what it actually sends back &mdash; it will likely report &ldquo;Couldn't determine the server settings&rdquo; and fail to find an authorization server. That's expected. Click <b>Next</b> anyway.</li>
          <li>On the auth step, choose <b>No authentication</b> &mdash; this server has none.</li>
          <li>Click <b>Add</b>. No further setup: no auth, no session state, nothing to configure.</li>
        </ol>
      </section>
    </div>
  </dialog>

</div>

<script>
  window.CHAOS_CONFIG = {
    apiBase: <?= json_encode(rtrim(API_BASE, '/')) ?>,
    clientIp: <?= json_encode($clientIp) ?>
  };
</script>
<script src="js/panels.js"></script>
<script src="js/mcp-dialog.js"></script>
<script src="js/theme-toggle.js"></script>
<script src="js/cli.js"></script>
</body>
</html>
