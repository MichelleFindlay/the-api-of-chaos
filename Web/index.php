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
 *   GET    /kick/rocks          assigns you a rock to kick
 *   GET    /kick/rocks/tiers    the full scale, moon rock -> Moon
 *   GET    /kick/dirt            adds to your pile and returns it
 *   POST   /kick/dirt            same, for the semantically fussy
 *   GET    /kick/dirt/status     peek without pounding
 *   GET    /kick/dirt/tiers      the full scale, fistful -> second moon
 *   GET    /kick/dirt/leaderboard  top 20 piles, IPs partly masked
 *   DELETE /kick/dirt            reset the pile (cowardly)
 *   GET    /excuses/teams       a reason not to join the call
 *   GET    /excuses/social      a reason not to attend, drawn from a tier
 *   GET    /excuses/social/tiers  the five sub-tiers of social excuse
 *   GET    /excuses/oops        a reason it went wrong, with tier explanation
 *   GET    /ministry/gentle-correction  rolls a d6 against approved remedies
 *   GET    /healthz             liveness
 *
 * Query params
 *   /kick/rocks?tier=7          request a specific tier (1-14)
 *   /kick/rocks?min=9&max=12    constrain the random range
 *   /kick/dirt?pile=michelle    named pile; also honours X-Pile-Id header
 *
 * Piles persist as JSON files under sys_get_temp_dir()/jar
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
    [5.0,   'You\'ve got a jar of dirt'],
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

const NO_TEAMS_TODAY_REASONS = [
    "My camera works, but my face doesn't today.",
    "Teams updated overnight and has developed a personality I'm not ready to meet.",
    'Someone in this building is drilling directly into my will to live.',
    "I'm being held hostage by a cat who has claimed my keyboard as sovereign territory.",
    'Outlook told me it was in a different timezone and I chose to believe it.',
    'My "mute" button broke in the on position, which honestly feels like a sign.',
    'I have a conflicting meeting with a sandwich.',
    'The meeting has no agenda and I have no coping mechanisms.',
    'My headphones only connect to devices that spark joy.',
    "I'm currently trapped in a Teams call from 2023 that nobody ever left.",
    'My laptop fan is making a noise usually associated with takeoff.',
    "I promised my houseplant I'd be present for it today.",
    'There are 14 people on this call and 13 of them are decorative.',
    'My internet is fine but my emotional bandwidth is not.',
    'I clicked "Join" and it opened Skype. I\'m scared.',
    "I'm at that stage of the day where I can hear colours.",
    'Someone forwarded the invite to me with "FYI" and I\'ve chosen to interpret that literally.',
    "My background blur can't blur what's happening back here.",
    "I'm on a train that goes through eleven tunnels and one of them is spiritual.",
    "My chair broke and I'm currently at desk-height for a much smaller person.",
    "I already know what's going to be said and I'd rather be surprised later.",
    'The calendar invite had a "(tentative)" in it and I\'ve committed fully to the tentative.',
    'I have to physically restrain my dog from joining and outperforming me.',
    'My microphone picks up my thoughts and that\'s a liability.',
    "I'm waiting in for a delivery between 8am and the heat death of the universe.",
    "I've been double-booked with an identical meeting and I'm attending the more attractive one.",
    'My smoke alarm has chosen violence.',
    "I've read the deck. I've absorbed the deck. I've become the deck. There's nothing left for me here.",
    "I'm currently locked out of my own house by my own front door.",
    "There's a wasp in here and only one of us is leaving.",
    "My laptop battery is at 3% and the charger is in a room I'm not emotionally ready to enter.",
    "I'm on annual leave, which I know because I booked it, and also because I'm in a swimming pool.",
    'Someone said "let\'s take this offline" three meetings ago and I took it very seriously.',
    'My VPN has decided I live in Ohio now.',
    'I have a dentist appointment. The dentist is fictional but my commitment is real.',
    "I'm currently in a queue on the phone to an energy supplier and I'm not losing my place for anyone.",
    'The neighbours are having an argument with better content than this agenda.',
    "My webcam makes me look like a Victorian ghost and I'd hate to distract everyone.",
    'I can only attend meetings that could not have been an email, and this one could.',
    "I'm in the middle of a very intense staring contest with a spreadsheet.",
    "I've caught something. Nothing serious. Just a general reluctance.",
    'My cat is on a call of her own and we only have the one desk.',
    "I tried to join but Teams asked me to sign in as an account I've never heard of, and I've decided that account can attend instead.",
    'I\'m halfway up a ladder and the ladder has opinions.',
    'There\'s roadworks outside and the drill is in the key of my soul.',
    "I'm doing my bit for the environment by not adding to the video-conferencing carbon load.",
    'My child has taken my mouse and hidden it somewhere only she knows.',
    'My kettle has boiled and I have a duty of care.',
    "I RSVP'd yes purely out of politeness and I regret to inform you the politeness has worn off.",
    "I'll be there in spirit, which is arguably the most I've contributed to any of the last six.",
];

const SOCIAL_EXCUSES = [
    'The Domestic Crisis Tier' => [
        'My sourdough starter has entered a critical phase and cannot be left unsupervised.',
        "A pigeon got into the airing cupboard and we've reached an uneasy stalemate.",
        "My smoke alarm is beeping in a rhythm I'm beginning to think is deliberate.",
        "The washing machine walked three feet across the kitchen and I need to see where it's going.",
        "I've locked myself out of the house but only emotionally.",
        "There's a bee in the conservatory that I've decided to name and I can't leave now.",
        "My freezer defrosted and I'm currently in a race against fourteen bags of peas.",
        'I have to be home for a delivery scheduled between 8am and the heat death of the universe.',
        'The boiler is making a noise I would describe as "confessional."',
        'I put something in the microwave in 2019 and I need to deal with that today.',
    ],
    'The Technical Difficulties Tier' => [
        'My calendar and I are no longer on speaking terms.',
        "My laptop has entered a fan cycle I'm legally obligated to see through.",
        'I updated something and now nothing is where I left it, including my resolve.',
        'My webcam works but only shows a version of me from four seconds ago and I find that upsetting.',
        'My phone autocorrected my RSVP to "no" and I don\'t want to make it a whole thing.',
        'The Wi-Fi is fine but the vibes are down.',
        "My headphones connected to my neighbour's television and I'm three episodes deep now.",
        'I accidentally set my status to Away in real life.',
        'Two-factor authentication has locked me out of the building, spiritually.',
        'My alarm went off but in the wrong emotional key.',
    ],
    'The Medical-Adjacent Tier' => [
        'I have a mild case of not.',
        "I've come down with a 24-hour personality.",
        'My back went out and took my willingness with it.',
        "I'm allergic to buffets held after 6pm.",
        'Doctor says I should avoid crowds, small talk, and anyone who says "circle back."',
        "I've developed a temporary intolerance to standing near a cheese board.",
        'My sleep schedule has become a work of abstract art and I refuse to interpret it.',
        'I strained something reaching for a metaphor.',
        "I've been advised to rest my opinions.",
        'I have a sore throat but only for talking, not eating.',
    ],
    'The Cosmic / Existential Tier' => [
        "Mercury isn't in retrograde but I'm choosing to act as if it is.",
        "I promised my past self I'd stop doing this and I'd hate to let him down.",
        'I looked at the invite too long and became briefly aware of my own mortality.',
        "I'm currently the only thing holding the week together and cannot be moved.",
        "I've been thinking about the ocean and now I'm no good to anyone.",
        'Time is a flat circle and I already attended this in a previous configuration.',
        'I have a prior commitment to lying on the floor.',
        "I've reached my annual limit of being perceived.",
        'My horoscope specifically said "no."',
        "I'm observing a personal holiday. It's called Wednesday.",
    ],
    'The Wildly Specific Tier' => [
        "I'm on jury duty for a dispute between two of my houseplants.",
        "My cat has scheduled a performance review and I don't want to reschedule.",
        "I'm the emergency contact for someone who is, I'm now realising, also me.",
        'I have to drive a very small distance for a very long time.',
        "There's a man coming to look at the loft. He's been coming for three years.",
        "I'm helping a friend move something that is technically an idea.",
        "I'm in a queue and I've come too far to leave now.",
        "My sat nav sent me somewhere and I've decided to stay.",
        "I'm attending in spirit, and my spirit is famously unreliable.",
        'I said yes assuming it would never actually happen, and now look at us.',
    ],
];

const OOPS_EXCUSES = [
    'Cosmic Interference' => [
        'description' => 'Forces beyond mortal accountability: the universe, physics, or a rogue butterfly.',
        'excuses' => [
            'The moon was doing something and nobody warned me.',
            'A butterfly flapped in 1994 and this was always going to happen to me specifically.',
            "I was downstream of someone else's bad decision and it splashed.",
            'The universe needed a small failure to balance a large success elsewhere. I was volunteered.',
            "Somewhere there's a version of me who got this right and he's insufferable about it.",
            "I was operating under yesterday's laws of physics.",
            'A prophecy required it. Sorry.',
            "Mercury isn't in retrograde but I am.",
            "Time briefly moved to the left and I didn't follow.",
            "I caught a stray thought that wasn't addressed to me.",
        ],
    ],
    'Bodily Betrayal' => [
        'description' => 'Your own body did something without asking you first.',
        'excuses' => [
            'My hands acted independently and have declined to give a statement.',
            'My left eye was still asleep.',
            'I blinked at the exact moment competence was being distributed.',
            'Low blood sugar with a side of unearned confidence.',
            'Muscle memory from a job I had eleven years ago.',
            'My thumb has always been a liability and today it showed its hand.',
            'I yawned mid-decision and something fell out.',
            'I was, at the critical moment, thinking about a completely different door.',
            'My spine made an executive decision without consulting me.',
            "I'd been standing up too long and my brain got jealous.",
        ],
    ],
    'Environmental Factors' => [
        'description' => 'The room, the lighting, the furniture: anything but me.',
        'excuses' => [
            'The lighting in that room has never once told the truth.',
            'Someone nearby was breathing confidently and I deferred to them.',
            'The chair was slightly too comfortable and I got sloppy.',
            'The font was misleading.',
            "There was a smell I couldn't identify and it consumed all available processing power.",
            'I was being watched by a plant.',
            'The room was 0.4 degrees too warm for good judgement.',
            'There was a fly and it had a plan.',
            'The button was designed by someone who hates me personally.',
            'A background noise resolved into a rhythm and I started nodding along.',
        ],
    ],
    'Institutional Failure' => [
        'description' => 'The process, the documentation, or the org chart set me up to fail.',
        'excuses' => [
            'Nobody sent me the memo, because the memo was about the memo.',
            'The documentation was accurate for a version that never shipped.',
            'I followed the process. The process was wrong. The process is on annual leave.',
            'Two systems disagreed and made me the tiebreaker with no context.',
            'The training was in 2021 and the trainer has since left the industry.',
            'There was a checklist. Item 4 said "see item 4."',
            'The requirements changed while I was reading them.',
            'Someone said "you\'re the expert" and I believed them for eight fatal seconds.',
            'Legal signed off. Legal has never been to this building.',
            "I was empowered to make the call, which is management's way of pre-assigning blame.",
        ],
    ],
    'Character Evidence' => [
        'description' => 'A candid look at long-standing personal flaws, now catching up with me.',
        'excuses' => [
            "I was doing an impression of someone who knows what they're doing and it went too well.",
            'Past me set this trap. Past me is a menace.',
            'I made the decision in the shower and it did not survive contact with daylight.',
            'I had a hunch. The hunch had a hunch. Somewhere the hunches got confused.',
            'I trusted a spreadsheet last touched in March.',
            'I said "how hard can it be" out loud, which is essentially a summoning.',
            'I was solving a slightly different problem extremely well.',
            'Confidence is just a mistake wearing a nice coat, and mine was tailored.',
            "I've been getting away with it for years and the invoice has arrived.",
            "Honestly? I just did. It happens. I'll fix it and not do it again.",
        ],
    ],
];

const GENTLE_CORRECTION_VERDICTS = [
    1 => ['verdict' => 'Reassuring pat',                'newtons' => 2,   'equivalent' => 'a supportive shoulder squeeze'],
    2 => ['verdict' => 'Firm tap',                       'newtons' => 15,  'equivalent' => "knocking on a neighbour's door"],
    3 => ['verdict' => 'The Fonz',                       'newtons' => 40,  'equivalent' => 'a jukebox, thumped just right'],
    4 => ['verdict' => 'Dad-fixing-the-telly',           'newtons' => 85,  'equivalent' => 'a fist to a CRT, decisively'],
    5 => ['verdict' => 'Percussive maintenance (formal)', 'newtons' => 250, 'equivalent' => 'a rubber mallet, no longer messing about'],
    6 => ['verdict' => 'Consult the warranty first',      'newtons' => 0,   'equivalent' => 'no impact administered; forms filed instead'],
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

/**
 * The largest finite tier boundary in DIRT_STAGES. Piles are capped
 * here so a pile can never grow past the top of the scale.
 */
function dirt_max_litres(): float
{
    static $max = null;
    if ($max === null) {
        $max = max(array_filter(array_column(DIRT_STAGES, 0), 'is_finite'));
    }
    return $max;
}

function pile_dir(): string
{
    $dir = getenv('KRAAS_DIR') ?: sys_get_temp_dir() . '/jar';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

function pile_path(string $id): string
{
    return pile_dir() . '/' . sha1($id) . '.json';
}

/**
 * When served behind Cloudflare, REMOTE_ADDR is Cloudflare's own edge IP,
 * not the visitor's. CF-Connecting-IP carries the real one; trust it only
 * if it is actually shaped like an IP address.
 */
function client_ip(): string
{
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
    if (is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
        return $cf;
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'anonymous';
}

/**
 * Anonymises an identifier for public display: IPs get their final
 * octet (or, for IPv6, final hextet) knocked off. Custom pile names
 * (from ?pile= or X-Pile-Id) are left as-is, since they were chosen
 * to be shown.
 */
function mask_ip(string $id): string
{
    if (filter_var($id, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $parts = explode('.', $id);
        $parts[3] = 'x';
        return implode('.', $parts);
    }
    if (filter_var($id, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $parts = explode(':', $id);
        $parts[count($parts) - 1] = 'x';
        return implode(':', $parts);
    }
    return $id;
}

function pile_id(): string
{
    $q = $_GET['pile'] ?? null;
    if (is_string($q) && $q !== '') return substr($q, 0, 128);
    $h = $_SERVER['HTTP_X_PILE_ID'] ?? null;
    if (is_string($h) && $h !== '') return substr($h, 0, 128);
    return client_ip();
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
        // owner_ip is fixed at creation, independent of ?pile=/X-Pile-Id,
        // so a named pile can't later be deleted by anyone who guesses its name.
        $pile = ['litres' => 0.0, 'blows' => 0, 'since' => gmdate('c'), 'owner_ip' => client_ip()];
    }

    // Each blow adds a random amount that scales with what is already
    // there, so the pile compounds rather than creeping.
    $before = (float) $pile['litres'];
    $growth = frand(0.18, 0.73);
    $delta  = max(frand(0.4, 2.9), $before * $growth);
    $after  = min(dirt_max_litres(), $before + $delta);

    $pile['litres'] = $after;
    $pile['blows']  = (int) $pile['blows'] + 1;
    $pile['id']     = $id;
    $delta          = $after - $before;

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
            'GET /kick/rocks'        => 'Assigns a rock. Optional: ?tier=n, ?min=&max=',
            'GET /kick/rocks/tiers'  => 'The full scale, tier 1 through 14.',
            'GET|POST /kick/dirt'    => 'Adds to your pile. Optional: ?pile=name',
            'GET /kick/dirt/status'  => 'Peek at the pile without pounding it.',
            'GET /kick/dirt/tiers'   => 'The full scale, fistful through second moon.',
            'GET /kick/dirt/leaderboard' => 'Top 20 piles, ranked. IPs shown with the final octet removed.',
            'DELETE /kick/dirt'      => 'Reset the pile. Only from the IP that raised it.',
            'GET /excuses/teams'     => 'A reason not to join the call.',
            'GET /excuses/social'    => 'A reason not to attend, with tier.',
            'GET /excuses/social/tiers' => 'The five sub-tiers of social excuse.',
            'GET /excuses/oops'      => 'A reason it went wrong, with tier explanation.',
            'GET /ministry/gentle-correction' => 'Rolls a d6 against the Ministry\'s approved remedies, graded in newtons.',
            'GET /healthz'           => 'Liveness.',
        ],
        'notes' => [
            'Piles are files on disk and survive restarts, unlike morale.',
            'Tier 14 is the Moon. There is no tier 15.',
        ],
        'source'  => 'https://github.com/MichelleFindlay/the-api-of-chaos',
        'license' => 'GPL-3.0',
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

function handle_dirt_tiers(): never
{
    $tiers = [];
    $from  = 0.0;
    foreach (DIRT_STAGES as $i => [$upTo, $label]) {
        $tiers[] = [
            'tier'  => $i + 1,
            'label' => $label,
            'from'  => human_volume($from),
            'up_to' => is_infinite($upTo) ? 'no upper bound' : human_volume($upTo),
        ];
        $from = $upTo;
    }

    send(200, [
        'scale' => 'a disappointing fistful -> a second moon, of dirt, in a decaying orbit',
        'tiers' => $tiers,
    ]);
}

function handle_leaderboard(): never
{
    $rows = [];
    foreach (glob(pile_dir() . '/*.json') ?: [] as $file) {
        $pile = json_decode((string) file_get_contents($file), true);
        if (!is_array($pile) || !isset($pile['litres'])) continue;

        $rows[] = [
            'contender'    => mask_ip(is_string($pile['id'] ?? null) ? $pile['id'] : 'anonymous'),
            'total'        => human_volume((float) $pile['litres']),
            'total_litres' => round((float) $pile['litres'], 2),
            'now_roughly'  => stage_for((float) $pile['litres']),
            'blows'        => (int) ($pile['blows'] ?? 0),
            'since'        => $pile['since'] ?? null,
        ];
    }

    usort($rows, static fn (array $a, array $b): int => $b['total_litres'] <=> $a['total_litres']);
    $rows = array_slice($rows, 0, 20);
    foreach ($rows as $i => &$row) {
        $row = ['rank' => $i + 1] + $row;
    }
    unset($row);

    send(200, [
        'instruction'  => 'Behold the competition.',
        'leaderboard'  => $rows,
        'notes'        => ['Top 20 by volume. IPs are shown with the final octet removed.'],
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
    $id   = pile_id();
    $pile = pile_read($id);

    if ($pile !== null && ($pile['owner_ip'] ?? null) !== client_ip()) {
        send(403, [
            'pile'   => ['id' => $id],
            'remark' => 'That pile was not raised from your IP. It stays.',
        ]);
    }

    $path    = pile_path($id);
    $existed = is_file($path) && unlink($path);

    send(200, [
        'pile'   => ['id' => $id, 'total' => '0 litres', 'blows' => 0],
        'remark' => $existed
            ? 'Pile levelled. The dirt remembers.'
            : 'Nothing to level. You were never here.',
    ]);
}

function handle_excuses_teams(): never
{
    send(200, [
        'instruction' => 'Do not join the call.',
        'reason'      => pick(NO_TEAMS_TODAY_REASONS),
    ]);
}

function handle_excuses_social(): never
{
    $tier = array_rand(SOCIAL_EXCUSES);

    send(200, [
        'instruction' => 'You will not be attending.',
        'reason'      => pick(SOCIAL_EXCUSES[$tier]),
        'tier'        => $tier,
    ]);
}

function handle_excuses_social_tiers(): never
{
    $tiers = [];
    foreach (SOCIAL_EXCUSES as $tier => $excuses) {
        $tiers[] = [
            'tier'  => $tier,
            'count' => count($excuses),
        ];
    }

    send(200, [
        'tiers' => $tiers,
    ]);
}

function handle_excuses_oops(): never
{
    $tier  = array_rand(OOPS_EXCUSES);
    $entry = OOPS_EXCUSES[$tier];

    send(200, [
        'instruction'      => 'Explain yourself.',
        'reason'           => pick($entry['excuses']),
        'tier'             => $tier,
        'tier_explanation' => $entry['description'],
    ]);
}

function handle_gentle_correction(): never
{
    $roll   = mt_rand(1, 6);
    $result = GENTLE_CORRECTION_VERDICTS[$roll];

    send(200, [
        'instruction' => 'When in doubt, apply gentle correction.',
        'roll'        => $roll,
        'verdict'     => $result['verdict'],
        'impact' => [
            'newtons'    => $result['newtons'],
            'equivalent' => $result['equivalent'],
        ],
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
    $method === 'GET' && $path === '/kick/rocks'          => handle_kick_rocks(),
    $method === 'GET' && $path === '/kick/rocks/tiers'    => handle_tiers(),
    in_array($method, ['GET', 'POST'], true)
        && $path === '/kick/dirt'                         => handle_pound_dirt(),
    $method === 'DELETE' && $path === '/kick/dirt'        => handle_pile_reset(),
    $method === 'GET' && $path === '/kick/dirt/status'    => handle_pile_status(),
    $method === 'GET' && $path === '/kick/dirt/tiers'     => handle_dirt_tiers(),
    $method === 'GET' && $path === '/kick/dirt/leaderboard' => handle_leaderboard(),
    $method === 'GET' && $path === '/excuses/teams'       => handle_excuses_teams(),
    $method === 'GET' && $path === '/excuses/social'      => handle_excuses_social(),
    $method === 'GET' && $path === '/excuses/social/tiers' => handle_excuses_social_tiers(),
    $method === 'GET' && $path === '/excuses/oops'         => handle_excuses_oops(),
    $method === 'GET' && $path === '/ministry/gentle-correction' => handle_gentle_correction(),
    $method === 'GET' && $path === '/healthz'             => send(200, [
        'ok'            => true,
        'piles_tracked' => count(glob(pile_dir() . '/*.json') ?: []),
    ]),
    default => send(404, [
        'error'  => 'No such service.',
        'remark' => 'There is, however, a rock. See GET /kick/rocks.',
    ]),
};