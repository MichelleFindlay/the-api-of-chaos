<?php
declare(strict_types=1);

/**
 * The API of Chaos — MCP folder shared library.
 *
 * Config, the tool catalogue, and upstream-calling plumbing shared by both
 * front doors this folder offers:
 *
 *   index.php    MCP Streamable HTTP (JSON-RPC 2.0) — see index.php
 *   openapi.php  OpenAPI 3.1 spec for REST-style tool servers (Open WebUI's
 *                "OpenAPI Tool Server" and similar, which don't speak MCP)
 *   tools.php    REST invocation of one tool, matching openapi.php's paths
 *
 * Not meant to be requested directly.
 */

// ============================================================ configuration

/**
 * WEB_URL, API_URL, STAGING_WEB_URL, STAGING_API_URL, API_BASE,
 * APP_VERSION, TRUSTED_PROXIES, CONNECT_TIMEOUT, TIMEOUT, MAX_BYTES,
 * FORWARD_CLIENT_IP, CLIENT_IP_HEADER, FRONTEND_KEY and PILE_PARAM all
 * come from here — this folder shares the frontend's config rather than
 * keeping its own second copy that could drift out of sync.
 */
require __DIR__ . '/../config.php';

/** Where this folder itself is reachable — used as the OpenAPI "servers" entry. */
define('FRONTEND_MCP_URL', rtrim((($_SERVER['HTTP_HOST'] ?? '') === parse_url(STAGING_WEB_URL, PHP_URL_HOST)) ? STAGING_WEB_URL : WEB_URL, '/') . '/mcp');

const MAX_BODY_BYTES = 65536;

const SERVER_NAME    = 'the-api-of-chaos';
const SERVER_TITLE   = 'The API of Chaos';
const SERVER_VERSION = APP_VERSION;

// ================================================================= tools

/**
 * Every tool this server offers, each fixed to exactly one upstream path
 * and method. Both front doors (MCP and the REST/OpenAPI one) can only
 * reach what's listed here — there's no open ?path= pass-through like the
 * browser terminal has.
 *
 * 'pile' marks endpoints where the caller's address is sent upstream as
 * ?pile=..., same as the frontend: one pile per address, not settable by
 * the caller.
 */
$MCP_TOOLS = [
    [
        'name' => 'kick_rocks', 'path' => '/kick/rocks', 'method' => 'GET',
        'description' => 'Assigns a rock. Optionally pin it to an exact tier, or a min/max range. Tiers run 1 (lightest) through 14 (heaviest).',
        'params' => [
            'tier' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 14, 'description' => 'Exact tier to assign, 1-14. Omit for a random one.'],
            'min'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 14, 'description' => 'Lower bound of a random tier range.'],
            'max'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 14, 'description' => 'Upper bound of a random tier range.'],
        ],
    ],
    [
        'name' => 'kick_rocks_tiers', 'path' => '/kick/rocks/tiers', 'method' => 'GET',
        'description' => 'The full rock scale, tier 1 through 14.',
        'params' => [],
    ],
    [
        'name' => 'kick_munitions', 'path' => '/kick/munitions', 'method' => 'GET',
        'description' => 'Assigns an unintentionally-lost munition. Returns its tier and arc.',
        'params' => [],
    ],
    [
        'name' => 'kick_munitions_tiers', 'path' => '/kick/munitions/tiers', 'method' => 'GET',
        'description' => 'The full munitions scale, tier 1 through 50, in five ten-tier arcs.',
        'params' => [],
    ],
    [
        'name' => 'pound_dirt', 'path' => '/pound/dirt', 'method' => 'GET', 'pile' => true,
        'description' => 'Adds to your pile of dirt. One pile per caller address; rate-limited to once every 2 seconds per pile.',
        'params' => [],
    ],
    [
        'name' => 'pound_dirt_status', 'path' => '/pound/dirt/status', 'method' => 'GET', 'pile' => true,
        'description' => 'Peeks at your pile without pounding it.',
        'params' => [],
    ],
    [
        'name' => 'pound_dirt_tiers', 'path' => '/pound/dirt/tiers', 'method' => 'GET',
        'description' => 'The full pile scale, fistful through second moon.',
        'params' => [],
    ],
    [
        'name' => 'pound_dirt_leaderboard', 'path' => '/pound/dirt/leaderboard', 'method' => 'GET',
        'description' => 'Top 20 piles, ranked. IPs shown with the final octet removed.',
        'params' => [],
    ],
    [
        'name' => 'pound_dirt_reset', 'path' => '/pound/dirt', 'method' => 'DELETE', 'pile' => true,
        'description' => "Resets your pile to zero. Only affects the caller's own pile.",
        'params' => [],
    ],
    [
        'name' => 'excuses_teams', 'path' => '/excuses/teams', 'method' => 'GET',
        'description' => 'A reason not to join the call.',
        'params' => [],
    ],
    [
        'name' => 'excuses_social', 'path' => '/excuses/social', 'method' => 'GET',
        'description' => 'A reason not to attend, with tier.',
        'params' => [],
    ],
    [
        'name' => 'excuses_oops', 'path' => '/excuses/oops', 'method' => 'GET',
        'description' => 'A reason it went wrong, with tier explanation.',
        'params' => [],
    ],
    [
        'name' => 'excuses_ring_ring', 'path' => '/excuses/ring-ring', 'method' => 'GET',
        'description' => 'A reason you did not pick up.',
        'params' => [],
    ],
    [
        'name' => 'excuses_late', 'path' => '/excuses/late', 'method' => 'GET',
        'description' => "A reason you're late.",
        'params' => [],
    ],
    [
        'name' => 'excuses_alibis', 'path' => '/excuses/alibis', 'method' => 'GET',
        'description' => "A reason you weren't there.",
        'params' => [],
    ],
    [
        'name' => 'ministry_gentle_correction', 'path' => '/ministry/gentle-correction', 'method' => 'GET',
        'description' => "Rolls a d6 against the Ministry's approved remedies, graded in newtons.",
        'params' => [],
    ],
    [
        'name' => 'ministry_mandatory_pet_adoption', 'path' => '/ministry/mandatory-pet-adoption', 'method' => 'GET',
        'description' => 'Assigns a legally binding pet from 203 options, tiered by how badly it ends you.',
        'params' => [],
    ],
    [
        'name' => 'cage_finger', 'path' => '/cage/finger', 'method' => 'GET',
        'description' => 'Put your finger in the cage. 50 animals, 50/50 odds. Costs a finger if taken; once fingers run out, toes are next.',
        'params' => [],
    ],
    [
        'name' => 'cage_fictional_finger', 'path' => '/cage/fictional/finger', 'method' => 'GET',
        'description' => 'Put your finger in the cage. 50 fictional creatures this time. Shares your finger/toe count with cage_finger.',
        'params' => [],
    ],
    [
        'name' => 'cage_finger_left', 'path' => '/cage/finger/left', 'method' => 'GET',
        'description' => 'How many fingers and toes you have left, out of 10 each.',
        'params' => [],
    ],
    [
        'name' => 'cage_finger_reset', 'path' => '/cage/finger/reset', 'method' => 'GET',
        'description' => 'Pray to the gods of the holy hairy toe for 10 fingers and 10 toes again.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_8ball', 'path' => '/unhinged/8ball', 'method' => 'GET',
        'description' => 'Shake it. It answers, unreliably.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_optimism', 'path' => '/unhinged/optimism', 'method' => 'GET',
        'description' => 'An unearned, unsupported dose of positivity.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_pessimism', 'path' => '/unhinged/pessimism', 'method' => 'GET',
        'description' => 'An unearned, unsupported dose of dread.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_advice', 'path' => '/unhinged/advice', 'method' => 'GET',
        'description' => 'Advice that applies to almost every situation.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_non_committal', 'path' => '/unhinged/non-committal', 'method' => 'GET',
        'description' => 'A refusal to answer, fifty ways.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_optimistic_dooom', 'path' => '/unhinged/optimistic-dooom', 'method' => 'GET',
        'description' => 'The end of everything, relentlessly reframed as good news. Tiered.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_turn_it_upside_down', 'path' => '/unhinged/turn-it-upside-down', 'method' => 'GET',
        'description' => 'Flip a random item. Physics declines to attend.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_solid_suddenly_liquid', 'path' => '/unhinged/solid-suddenly-liquid', 'method' => 'GET',
        'description' => 'A solid, liquefied. Fifty of them, tiered by regret.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_solid_suddenly_gelatinous', 'path' => '/unhinged/solid-suddenly-gelatinous', 'method' => 'GET',
        'description' => 'A solid, turned to jelly. Fifty of them, tiered by wobble.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_choose_your_duck', 'path' => '/unhinged/choose-your-duck', 'method' => 'GET',
        'description' => 'A bath duck, and what it costs you. Fifty of them, S-Tier to F-Tier.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_gravity_resigned', 'path' => '/unhinged/gravity-resigned', 'method' => 'GET',
        'description' => 'Gravity has quit. Time to float.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_vengeful_weather', 'path' => '/unhinged/vengeful-weather', 'method' => 'GET',
        'description' => 'The sky, personally offended.',
        'params' => [],
    ],
    [
        'name' => 'unhinged_wrongfall', 'path' => '/unhinged/wrongfall', 'method' => 'GET',
        'description' => 'Clouds went feral. Fifty of them, tiered S to F.',
        'params' => [],
    ],
    [
        'name' => 'healthz', 'path' => '/healthz', 'method' => 'GET',
        'description' => 'Liveness, plus lifetime request, unique-IP, and rocks-kicked counts.',
        'params' => [],
    ],
];

/**
 * Any /unhinged call has a 1-in-10 chance of falling into the void instead
 * (upstream behaviour, not a bug here) — worth telling the model so it
 * doesn't treat that response as a broken tool.
 */
const UNHINGED_VOID_NOTE = "Note: /unhinged endpoints have a 1-in-10 chance of returning a 'fell into the "
    . "void' response instead of the usual payload. That's expected upstream behaviour — try again.";

// ================================================================= helpers

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

function chaos_pile_id(): string
{
    return chaos_client_ip();
}

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
        'CF-Connecting-IP: ' . $client,
        'True-Client-IP: ' . $client,
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

/** Calls the upstream API for one tool. Never throws — errors come back as a field. */
function mcp_call_upstream(string $path, string $method, array $query): array
{
    $target = rtrim(API_BASE, '/') . $path;
    if ($query !== []) {
        $target .= '?' . http_build_query($query);
    }

    $headers = ['Accept: application/json, text/plain;q=0.9, */*;q=0.8'];
    if (FORWARD_CLIENT_IP) {
        $headers = array_merge($headers, chaos_forward_headers());
    }
    $headers[] = 'User-Agent: chaos-mcp/1.0 (+' . rtrim(WEB_URL, '/') . '/mcp)';

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

    $started  = microtime(true);
    $result   = curl_exec($ch);
    $tookMs   = (int) round((microtime(true) - $started) * 1000);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr  = curl_error($ch);
    $curlCode = curl_errno($ch);
    curl_close($ch);

    if ($result === false) {
        return [
            'ok' => false, 'status' => 0, 'took_ms' => $tookMs, 'body' => null, 'json' => null,
            'error' => $curlCode === CURLE_ABORTED_BY_CALLBACK
                ? 'Upstream response exceeded the size limit.'
                : 'Could not reach the API: ' . $curlErr,
        ];
    }

    $isCfEdge = (($responseHeaders['server'] ?? '') === 'cloudflare')
        && $status >= 400
        && (preg_match('/error code:\s*(\d{4})/i', $result, $m) || stripos($result, 'cloudflare') !== false);
    if ($isCfEdge) {
        return [
            'ok' => false, 'status' => $status, 'took_ms' => $tookMs, 'body' => null, 'json' => null,
            'error' => 'Cloudflare edge error ' . ($m[1] ?? $status) . '. The request was rejected at the '
                . 'edge before it reached the API.',
        ];
    }

    $decoded = json_decode($result, true);
    $isJson  = json_last_error() === JSON_ERROR_NONE;

    return [
        'ok'      => $status >= 200 && $status < 400,
        'status'  => $status,
        'took_ms' => $tookMs,
        'body'    => $isJson ? null : $result,
        'json'    => $isJson ? $decoded : null,
        'error'   => null,
    ];
}

function mcp_find_tool(array $tools, string $name): ?array
{
    foreach ($tools as $tool) {
        if ($tool['name'] === $name) {
            return $tool;
        }
    }
    return null;
}

/**
 * Validates arguments against a tool's declared params. Returns
 * [errors[], query[]] — query is ready to hand to mcp_call_upstream().
 */
function mcp_validate_args(array $tool, array $args): array
{
    $errors = [];
    $query  = [];

    foreach ($args as $key => $value) {
        if (!array_key_exists($key, $tool['params'])) {
            $errors[] = "Unknown parameter: $key";
        }
    }

    foreach ($tool['params'] as $key => $spec) {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            continue;
        }
        $value = $args[$key];
        if ($spec['type'] === 'integer') {
            $isIntLike = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
            if (!$isIntLike) {
                $errors[] = "$key must be an integer";
                continue;
            }
            $intValue = (int) $value;
            if (isset($spec['minimum']) && $intValue < $spec['minimum']) {
                $errors[] = "$key must be >= {$spec['minimum']}";
                continue;
            }
            if (isset($spec['maximum']) && $intValue > $spec['maximum']) {
                $errors[] = "$key must be <= {$spec['maximum']}";
                continue;
            }
            $query[$key] = (string) $intValue;
        }
    }

    return [$errors, $query];
}

/**
 * Invokes one tool by name and returns a transport-agnostic result:
 * ['ok','status','error','data','took_ms']. Both front doors (MCP's
 * tools/call and the REST /tools/<name> endpoint) build their own response
 * shape from this — MCP wraps it as a content block, REST returns the data
 * directly with a matching HTTP status.
 */
function mcp_invoke_tool(array $tools, ?string $name, $arguments): array
{
    $fail = fn(int $status, string $error) => ['ok' => false, 'status' => $status, 'error' => $error, 'data' => null, 'took_ms' => null];

    if ($name === null || $name === '') {
        return $fail(400, 'Missing required parameter: name');
    }

    $tool = mcp_find_tool($tools, $name);
    if ($tool === null) {
        return $fail(404, "Unknown tool: $name");
    }

    $args = is_array($arguments) ? $arguments : [];
    [$errors, $query] = mcp_validate_args($tool, $args);
    if ($errors !== []) {
        return $fail(400, 'Invalid arguments — ' . implode('; ', $errors));
    }

    if (!empty($tool['pile'])) {
        $query[PILE_PARAM] = chaos_pile_id();
    }

    $upstream = mcp_call_upstream($tool['path'], $tool['method'], $query);
    if ($upstream['error'] !== null) {
        return $fail(502, $upstream['error']);
    }

    return [
        'ok'      => $upstream['ok'],
        'status'  => $upstream['status'] ?: 200,
        'error'   => null,
        'data'    => $upstream['json'] ?? $upstream['body'],
        'took_ms' => $upstream['took_ms'],
    ];
}
