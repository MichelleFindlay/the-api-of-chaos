<?php
declare(strict_types=1);

/**
 * The API of Chaos — configuration.
 *
 * Every actual configuration knob (environment URLs, version, trust
 * settings, small tunables) lives here, separate from index.php's
 * routes/handlers and its much larger joke-content tables (ROCKS,
 * MUNITIONS, DUCKS and so on stay in index.php — they're content, not
 * configuration). Required by index.php before anything else runs.
 */

/**
 * Canonical URLs for the two environments this API serves. Single source of
 * truth shared with the frontend's copy of the same four constants — keep
 * them in sync. Used below to restrict CORS to just these two origins.
 */
const WEB_URL         = 'https://dumpsterfire.uk';
const API_URL         = 'https://api.dumpsterfire.uk';
const STAGING_WEB_URL = 'https://dev.dumpsterfire.uk';
const STAGING_API_URL = 'https://dev.dumpsterfire.uk/api';

/**
 * This API's own version, returned from GET /. Bump this alongside the
 * same number in Frontend/index.php's APP_VERSION and Frontend/mcp/lib.php's
 * SERVER_VERSION.
 */
const APP_VERSION = '2.0.0';
const GITHUB_REPO = 'MichelleFindlay/the-api-of-chaos';

/**
 * Reverse proxies in front of *this* script whose forwarding headers we
 * believe — Cloudflare's edge ranges, from https://www.cloudflare.com/ips-v4
 * and https://www.cloudflare.com/ips-v6, plus loopback and private ranges
 * for a local nginx, Caddy or php-fpm sitting in front. Used by client_ip()
 * to detect, automatically and per request, whether Cloudflare is actually
 * in front right now — there is no flag to keep in sync; whichever side
 * Cloudflare is on, REMOTE_ADDR reveals it. Refresh the Cloudflare list
 * occasionally:
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

// How many fingers/toes you start (and get restored) with. Toes are
// only spent once fingers run out.
const FINGERS_START = 10;
const TOES_START    = 10;

// Consecutive too-soon pounds against the same pile before the dirt guy quits.
const RATE_LIMIT_MELTDOWN_STRIKES = 5;

/**
 * Where piles, appendage (fingers/toes) counts and the lifetime-stats
 * file actually live day to day — pile_dir()'s default. This used to be
 * sys_get_temp_dir() . '/jar', which is invisible to ordinary website
 * backups and can be swept by the OS between requests; putting the live
 * store inside the webspace instead means the data that matters is
 * backed up the same way the rest of the site is, with no separate
 * mirroring step required. Override with the KRAAS_DIR environment
 * variable if you'd rather point it elsewhere (e.g. outside the webspace
 * on a host where that's preferred). Web/data/.htaccess denies all
 * direct web access to everything under here regardless of where it
 * points, since pile records carry a visitor's raw IP in the 'id' field.
 */
const PILE_DATA_DIR = __DIR__ . '/data/jar';

/**
 * Where the lifetime-stats backup snapshot gets written, alongside
 * PILE_DATA_DIR — a periodic single-file mirror of the summary numbers,
 * cheaper to grab for a manual backup than every individual pile file.
 * See stats_backup() in index.php. STATS_BACKUP_MIN_INTERVAL throttles
 * how often it rewrites.
 */
const STATS_BACKUP_FILE         = __DIR__ . '/data/stats-backup.json';
const STATS_BACKUP_MIN_INTERVAL = 1440;

/**
 * Same idea as STATS_BACKUP_FILE, but for the actual pile and appendage
 * records rather than just the summary numbers — one consolidated
 * snapshot of PILE_DATA_DIR's current contents, cheaper to restore from
 * than individual files. migrate_legacy_piles() in index.php also uses
 * this same pass to fold in anything still sitting in the old
 * sys_get_temp_dir() . '/jar' default from before PILE_DATA_DIR moved
 * into the webspace, so nothing existing is lost by that change.
 */
const PILES_BACKUP_FILE         = __DIR__ . '/data/piles-backup.json';
const PILES_BACKUP_MIN_INTERVAL = 1440;

/**
 * Where the cached "latest GitHub release" lookup gets written — inside
 * the webspace, not sys_get_temp_dir(), same reasoning as everything
 * else here. Just a version string and a timestamp, nothing sensitive;
 * data/.htaccess denies direct access to it anyway.
 */
const RELEASE_CACHE_FILE = __DIR__ . '/data/release-cache.json';
