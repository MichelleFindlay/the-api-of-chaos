<?php
declare(strict_types=1);

/**
 * The API of Chaos (AOC)
 * ------------------------------------------------------------------
 * A dismissal-as-a-service API. Single file, no dependencies.
 *
 *   php -S localhost:8000 kick-rocks.php
 *   KRAAS_DIR=/var/lib/kraas php -S 0.0.0.0:8000 kick-rocks.php
 *
 * Also drops straight into Apache/nginx+FPM as index.php.
 *
 * Endpoints
 *   GET    /                    service index
 *   GET    /kick-rocks          assigns you a rock to kick
 *   GET    /kick-rocks/tiers    the full scale, moon rock -> Moon
 *   GET    /pound-dirt          adds to your pile and returns it
 *   POST   /pound-dirt          same, for the semantically fussy
 *   GET    /pound-dirt/status   peek without pounding
 *   DELETE /pound-dirt          reset the pile (cowardly)
 *   GET    /healthz             liveness
 *
 * Query params
 *   /kick-rocks?tier=7          request a specific tier (1-14)
 *   /kick-rocks?min=9&max=12    constrain the random range
 *   /pound-dirt?pile=michelle   named pile; also honours X-Pile-Id header
 *
 * Piles persist as JSON files under sys_get_temp_dir()/kraas-piles
 * (override with KRAAS_DIR), because PHP forgets everything between
 * requests. Much like the people you are sending here.
 */

/* ------------------------------------------------------------------ *
 * The scale. Masses are order-of-magnitude estimates and are not
 * warranted for use in actual geology.
 * ------------------------------------------------------------------ */

const ROCKS = [
    ['tier' => 1,  'name' => 'Apollo moon-rock chip',      'mass_kg' => 0.02,
     'location' => 'a sealed nitrogen cabinet in Houston',
     'advice'   => 'Start small. It has already been to space; it can take a knock.'],
    ['tier' => 2,  'name' => 'skimming stone',             'mass_kg' => 0.15,
     'location' => 'any shingle beach, take your pick',
     'advice'   => 'Flat, agreeable, kicks beautifully. A gateway rock.'],
    ['tier' => 3,  'name' => 'cobblestone',                'mass_kg' => 4.0,
     'location' => 'a listed street somebody will shout at you about',
     'advice'   => 'Wear something with a toecap.'],
    ['tier' => 4,  'name' => 'curling stone',              'mass_kg' => 19.0,
     'location' => 'a rink in Ayrshire',
     'advice'   => 'It is designed to slide. This is the last easy one.'],
    ['tier' => 5,  'name' => 'kerbstone',                  'mass_kg' => 95.0,
     'location' => 'the edge of the road, where you left it',
     'advice'   => 'Granite does not negotiate.'],
    ['tier' => 6,  'name' => 'millstone',                  'mass_kg' => 900.0,
     'location' => 'around somebody else\'s neck, traditionally',
     'advice'   => 'Symbolically apt. Physically inadvisable.'],
    ['tier' => 7,  'name' => 'glacial erratic',            'mass_kg' => 12000.0,
     'location' => 'a field in Cumbria, dropped there by an ice sheet',
     'advice'   => 'It was carried a hundred miles by a glacier. You get one boot.'],
    ['tier' => 8,  'name' => 'Stonehenge sarsen',          'mass_kg' => 25000.0,
     'location' => 'Salisbury Plain, behind a rope',
     'advice'   => 'Neolithic engineers moved this. Do not embarrass them.'],
    ['tier' => 9,  'name' => 'Cleopatra\'s Needle',        'mass_kg' => 224000.0,
     'location' => 'Victoria Embankment, London',
     'advice'   => 'Roughly 3,500 years old. Aim for the base.'],
    ['tier' => 10, 'name' => 'the Rock of Gibraltar',      'mass_kg' => 1.9e12,
     'location' => 'the mouth of the Mediterranean',
     'advice'   => 'The monkeys will watch. They will not help.'],
    ['tier' => 11, 'name' => 'the White Cliffs of Dover',  'mass_kg' => 4.4e12,
     'location' => 'facing France, disapprovingly',
     'advice'   => 'Chalk. Softer than granite, so technically progress. It is not.'],
    ['tier' => 12, 'name' => 'Uluru',                      'mass_kg' => 1.4e13,
     'location' => 'the Northern Territory',
     'advice'   => 'You are asked not to climb it. Kicking is a grey area. Do not.'],
    ['tier' => 13, 'name' => 'Mount Everest',              'mass_kg' => 8.1e14,
     'location' => 'the Nepal-Tibet border, 8,849 m up',
     'advice'   => 'Bring crampons and a decade.'],
    ['tier' => 14, 'name' => 'the Moon',                   'mass_kg' => 7.342e22,
     'location' => '384,400 km that way',
     'advice'   => 'The final tier. There is nothing beyond this but disappointment.'],
];

const KICK_REMARKS = [
    'Go on then.',
    'Take your time. Nobody is waiting.',
    'This one has your name on it.',
    'Allocated fairly, via an unbiased process you may not appeal.',
    'Complaints about the assignment are handled by kicking a second rock.',
    'Others have kicked this rock. None have returned satisfied.',
    'The rock is unbothered. Be more like the rock.',
];

const DIRT_STAGES = [
    [1.0,   'a disappointing fistful'],
    [12.0,  'a bucketful'],
    [90.0,  'a wheelbarrow load'],
    [600.0, 'a proper molehill'],
    [4e3,   'a skip, filled past the line'],
    [3e4,   'an allotment\'s worth, all of it in one heap'],
    [2e5,   'a village green, relocated'],
    [1.5e6, 'a spoil heap with its own microclimate'],
    [1e7,   'a burial mound the size of Silbury Hill'],
    [1e8,   'Wembley, filled to the upper tier'],
    [1e9,   'a small unnamed hill now appearing on maps'],
    [1e11,  'Ben Nevis, but browner and entirely your fault'],
    [1e13,  'most of Snowdonia, stacked'],
    [1e16,  'a landmass with a coastline and weather of its own'],
    [INF,   'a second moon, of dirt, in a decaying orbit'],
];

const POUND_REMARKS = [
    'Keep at it.',
    'The dirt is not getting any smaller.',
    'That is the spirit. That is exactly the spirit.',
    'Somebody has to, and it is not going to be me.',
    'Excellent form. Terrible outcome.',
    'You are now measurably worse off than when you started.',
    'Sisyphus had a rock. You chose this.',
    'The pile grows. The pile always grows.',
];

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */

function pick(array $a): mixed
{
    return $a[array_rand($a)];
}

function frand(float $lo, float $hi): float
{
    return $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);
}

function human_mass(float $kg): string
{
    if ($kg < 1)    return rtrim(rtrim(number_format($kg, 2), '0'), '.') . ' kg';
    if ($kg < 1e6)  return number_format($kg) . ' kg';
    return sprintf('%.2f x 10^%d kg', $kg / (10 ** floor(log10($kg))), floor(log10($kg)));
}

function human_volume(float $litres): string
{
    if ($litres < 1000) return number_format($litres, 1) . ' litres';
    $m3 = $litres / 1000;
    if ($m3 < 1e6)      return number_format($m3) . ' m3';
    return sprintf('%.2f x 10^%d m3', $m3 / (10 ** floor(log10($m3))), floor(log10($m3)));
}

function stage_for(float $litres): string
{
    foreach (DIRT_STAGES as [$under, $label]) {
        if ($litres < $under) return $label;
    }
    return 'indescribable';
}

function pile_dir(): string
{
    $dir = getenv('KRAAS_DIR') ?: sys_get_temp_dir() . '/kraas-piles';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

function pile_path(string $id): string
{
    return pile_dir() . '/' . sha1($id) . '.json';
}

function pile_id(): string
{
    $q = $_GET['pile'] ?? null;
    if (is_string($q) && $q !== '') return substr($q, 0, 128);
    $h = $_SERVER['HTTP_X_PILE_ID'] ?? null;
    if (is_string($h) && $h !== '') return substr($h, 0, 128);
    return $_SERVER['REMOTE_ADDR'] ?? 'anonymous';
}

function pile_read(string $id): ?array
{
    $path = pile_path($id);
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Read-modify-write under an exclusive lock, so concurrent pounders
 * do not lose each other's dirt.
 */
function pile_pound(string $id): array
{
    $fh = fopen(pile_path($id), 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open pile for writing.');
    }
    flock($fh, LOCK_EX);

    $raw  = stream_get_contents($fh);
    $pile = json_decode((string) $raw, true);
    if (!is_array($pile)) {
        $pile = ['litres' => 0.0, 'blows' => 0, 'since' => gmdate('c')];
    }

    // Each blow adds a random amount that scales with what is already
    // there, so the pile compounds rather than creeping.
    $growth = frand(0.18, 0.73);
    $delta  = max(frand(0.4, 2.9), (float) $pile['litres'] * $growth);

    $pile['litres'] = (float) $pile['litres'] + $delta;
    $pile['blows']  = (int) $pile['blows'] + 1;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($pile));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $pile['delta'] = $delta;
    return $pile;
}

function send(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Powered-By: spite');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

function choose_rock(): array
{
    $count = count(ROCKS);
    $tier  = filter_input(INPUT_GET, 'tier', FILTER_VALIDATE_INT);
    if ($tier !== null && $tier !== false && $tier >= 1 && $tier <= $count) {
        return ROCKS[$tier - 1];
    }
    $min = filter_input(INPUT_GET, 'min', FILTER_VALIDATE_INT) ?: 1;
    $max = filter_input(INPUT_GET, 'max', FILTER_VALIDATE_INT) ?: $count;
    $min = max(1, min($count, $min));
    $max = max($min, min($count, $max));
    return ROCKS[mt_rand($min, $max) - 1];
}

/* ------------------------------------------------------------------ *
 * Handlers
 * ------------------------------------------------------------------ */

function handle_index(): never
{
    send(200, [
        'service' => 'The API of Chaos',
        'version' => '1.0.0',
        'tagline' => 'Dismissal, at scale, with an SLA of none.',
        'endpoints' => [
            'GET /kick-rocks'        => 'Assigns a rock. Optional: ?tier=n, ?min=&max=',
            'GET /kick-rocks/tiers'  => 'The full scale, tier 1 through 14.',
            'GET|POST /pound-dirt'   => 'Adds to your pile. Optional: ?pile=name',
            'GET /pound-dirt/status' => 'Peek at the pile without pounding it.',
            'DELETE /pound-dirt'     => 'Reset the pile. Noted on your permanent record.',
            'GET /healthz'           => 'Liveness.',
        ],
        'notes' => [
            'Piles are files on disk and survive restarts, unlike morale.',
            'Tier 14 is the Moon. There is no tier 15.',
        ],
    ]);
}

function handle_kick_rocks(): never
{
    $rock = choose_rock();
    $boot = max(0.01, min(0.99, 1 - $rock['tier'] / 15));

    send(200, [
        'instruction' => 'Kick rocks.',
        'rock' => [
            'tier'       => $rock['tier'],
            'of'         => count(ROCKS),
            'name'       => $rock['name'],
            'mass_kg'    => $rock['mass_kg'],
            'mass_human' => human_mass($rock['mass_kg']),
            'location'   => $rock['location'],
        ],
        'assessment' => [
            'advice'                     => $rock['advice'],
            'boot_survival_probability'  => round($boot, 2),
            'estimated_completion'       => $rock['tier'] < 6 ? 'this afternoon' : 'never',
        ],
        'remark' => pick(KICK_REMARKS),
    ], ['X-Kick-Rocks' => 'tier-' . $rock['tier']]);
}

function handle_tiers(): never
{
    send(200, [
        'scale' => 'moon rock -> White Cliffs of Dover -> the Moon',
        'tiers' => array_map(static fn (array $r): array => [
            'tier'       => $r['tier'],
            'name'       => $r['name'],
            'mass_human' => human_mass($r['mass_kg']),
            'location'   => $r['location'],
        ], ROCKS),
    ]);
}

function handle_pound_dirt(): never
{
    $id   = pile_id();
    $pile = pile_pound($id);

    send(200, [
        'instruction' => 'Pound dirt.',
        'pile' => [
            'id'           => $id,
            'blows'        => $pile['blows'],
            'added'        => human_volume($pile['delta']),
            'total'        => human_volume($pile['litres']),
            'total_litres' => round($pile['litres'], 2),
            'now_roughly'  => stage_for($pile['litres']),
            'since'        => $pile['since'],
        ],
        'remark' => pick(POUND_REMARKS),
    ], ['X-Pile-Litres' => (string) round($pile['litres'], 2)]);
}

function handle_pile_status(): never
{
    $id   = pile_id();
    $pile = pile_read($id);

    if ($pile === null) {
        send(404, [
            'pile'   => ['id' => $id, 'blows' => 0, 'total' => '0 litres'],
            'remark' => 'No pile on record. You have pounded no dirt. Suspicious.',
        ]);
    }

    send(200, [
        'pile' => [
            'id'           => $id,
            'blows'        => $pile['blows'],
            'total'        => human_volume((float) $pile['litres']),
            'total_litres' => round((float) $pile['litres'], 2),
            'now_roughly'  => stage_for((float) $pile['litres']),
            'since'        => $pile['since'],
        ],
    ]);
}

function handle_pile_reset(): never
{
    $id      = pile_id();
    $path    = pile_path($id);
    $existed = is_file($path) && unlink($path);

    send(200, [
        'pile'   => ['id' => $id, 'total' => '0 litres', 'blows' => 0],
        'remark' => $existed
            ? 'Pile levelled. The dirt remembers.'
            : 'Nothing to level. You were never here.',
    ]);
}

/* ------------------------------------------------------------------ *
 * Router
 * ------------------------------------------------------------------ */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Tolerate being served from a subdirectory or as /kick-rocks.php/...
// (The CLI server rewrites SCRIPT_NAME to the requested path, so only
// strip a prefix we can actually see at the front of the URL.)
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$base   = '';
if (str_ends_with($script, '.php')) {
    if (str_starts_with($path, $script)) {
        $base = $script;
    } else {
        $dir = rtrim(dirname($script), '/');
        if ($dir !== '' && str_starts_with($path, $dir . '/')) {
            $base = $dir;
        }
    }
}
if ($base !== '') {
    $path = substr($path, strlen($base));
}
$path = rtrim($path, '/');
if ($path === '' || $path === '/index.php' || $path === '/kick-rocks.php') {
    $path = '/';
}

match (true) {
    $method === 'GET' && $path === '/'                    => handle_index(),
    $method === 'GET' && $path === '/kick-rocks'          => handle_kick_rocks(),
    $method === 'GET' && $path === '/kick-rocks/tiers'    => handle_tiers(),
    in_array($method, ['GET', 'POST'], true)
        && $path === '/pound-dirt'                        => handle_pound_dirt(),
    $method === 'DELETE' && $path === '/pound-dirt'       => handle_pile_reset(),
    $method === 'GET' && $path === '/pound-dirt/status'   => handle_pile_status(),
    $method === 'GET' && $path === '/healthz'             => send(200, [
        'ok'            => true,
        'php'           => PHP_VERSION,
        'piles_tracked' => count(glob(pile_dir() . '/*.json') ?: []),
    ]),
    default => send(404, [
        'error'  => 'No such service.',
        'remark' => 'There is, however, a rock. See GET /kick-rocks.',
    ]),
};