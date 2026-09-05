<?php
declare(strict_types=1);

/**
 * The API of Chaos — frontend configuration.
 *
 * Every actual configuration knob (environment URLs, version, proxy
 * trust, the forwarding/proxy allowlists) lives here, separate from
 * index.php's page markup and $CATALOGUE (what people see and click —
 * content, not configuration). Required by index.php before anything
 * else runs.
 */

/**
 * Canonical URLs for the two environments this site runs in. Single source
 * of truth for both files — the API applies these same four constants to
 * its CORS allowlist, so keep the two copies in sync.
 */
const WEB_URL         = 'https://dumpsterfire.uk';
const API_URL         = 'https://api.dumpsterfire.uk';
const STAGING_WEB_URL = 'https://dev.dumpsterfire.uk';
const STAGING_API_URL = 'https://dev.dumpsterfire.uk/api';

/**
 * This site's own version, shown in the banner. Bump this alongside the
 * same number in Web/index.php and Frontend/mcp/lib.php's SERVER_VERSION.
 */
const APP_VERSION  = '2.0.0';
const GITHUB_REPO  = 'MichelleFindlay/the-api-of-chaos';

/**
 * Where the cached "latest GitHub release" lookup gets written — inside
 * the webspace, not sys_get_temp_dir(), so nothing this site writes ends
 * up somewhere outside its own backups. Just a version string and a
 * timestamp, nothing sensitive; data/.htaccess denies direct access to
 * it anyway, same as everything else under data/.
 */
const RELEASE_CACHE_FILE = __DIR__ . '/data/release-cache.json';

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
 *
 * Picked automatically from the host this page is served on: the staging web
 * host gets the staging API, anything else (production, localhost, a preview
 * domain) gets production. Uses define() rather than const because it needs
 * $_SERVER at runtime, which plain const expressions can't touch.
 */
define('API_BASE', (($_SERVER['HTTP_HOST'] ?? '') === parse_url(STAGING_WEB_URL, PHP_URL_HOST)) ? STAGING_API_URL : API_URL);

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
 * Whether this site is currently behind Cloudflare is detected
 * automatically, per request — there is no flag to keep in sync.
 * CF-Connecting-IP is only believed when REMOTE_ADDR itself is inside
 * the trusted ranges below (i.e. an actual reverse proxy is what
 * connected), so a client sending it directly cannot spoof a pile ID:
 * nothing rewrites the header before it reaches PHP unless something
 * on that trusted list is genuinely in front. If Cloudflare is ever
 * removed from in front of this site, REMOTE_ADDR simply stops
 * matching and the header stops being read, automatically, from the
 * next request on — and the reverse happens if Cloudflare comes back.
 * Either way, lock the origin firewall to Cloudflare's ranges whenever
 * Cloudflare is genuinely in front, so nobody can connect directly and
 * hand you a header of their choosing.
 */

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
    '#^/unhinged/(8ball|optimism|pessimism|advice|non-committal|optimistic-dooom|turn-it-upside-down|solid-suddenly-liquid|solid-suddenly-gelatinous|choose-your-duck|gravity-resigned|vengeful-weather|wrongfall)$#',
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
