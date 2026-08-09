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
 *   GET    /pound/dirt          adds to your pile and returns it
 *   POST   /pound/dirt          same, for the semantically fussy
 *   GET    /pound/dirt/status   peek without pounding
 *   GET    /pound/dirt/tiers    the full scale, fistful -> second moon
 *   GET    /pound/dirt/leaderboard  top 20 piles, IPs partly masked
 *   DELETE /pound/dirt          reset the pile (cowardly)
 *   GET    /excuses/teams       a reason not to join the call
 *   GET    /excuses/social      a reason not to attend, drawn from a tier
 *   GET    /excuses/social/tiers  the five sub-tiers of social excuse
 *   GET    /excuses/oops        a reason it went wrong, with tier explanation
 *   GET    /excuses/ring-ring   a reason you didn't pick up
 *   GET    /excuses/late        a reason you're late
 *   GET    /ministry/gentle-correction  rolls a d6 against approved remedies
 *   GET    /cage/finger         put your finger in the cage
 *   GET    /cage/fictional/finger  same, but fictional creatures; shares your finger/toe count
 *   GET    /cage/finger/left    how many fingers you have left
 *   GET    /cage/finger/reset   pray for 10 fingers again
 *   GET    /unhinged/8ball      shake it, it answers
 *   GET    /unhinged/optimism   an unearned dose of positivity
 *   GET    /unhinged/pessimism  an unearned dose of dread
 *   GET    /unhinged/advice     advice for almost every situation
 *   GET    /unhinged/non-committal  a refusal to answer, fifty ways
 *   GET    /healthz             liveness, plus lifetime request/unique-IP/rocks-kicked counts
 *
 * Query params
 *   /kick/rocks?tier=7          request a specific tier (1-14)
 *   /kick/rocks?min=9&max=12    constrain the random range
 *   /pound/dirt?pile=michelle   named pile; also honours X-Pile-Id header
 *
 * Piles persist as JSON files under sys_get_temp_dir()/jar
 * (override with KRAAS_DIR), because PHP forgets everything between
 * requests. Much like the people you are sending here.
 *
 * Any request under /unhinged has a 1-in-10 chance of falling into
 * the void instead of getting a normal response. Try again.
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

const RING_RING_EXCUSES = [
    'My thumbs are currently load-bearing.',
    "The phone rang and I ascended briefly. I'm back now but different.",
    'I only answer calls that begin with a drum fill.',
    'I was in a Faraday cage of my own construction and design.',
    "A pigeon made eye contact with me and we're still negotiating.",
    'My ringtone summoned something and I had to un-summon it.',
    "I was being haunted, but professionally, so I couldn't step away.",
    "Answering would've broken the seal on the fridge and the fridge knows.",
    'I was mid-way through a very important lie down.',
    'My phone is currently being used as a coaster and I respect the role.',
    "I don't have service in the emotional sense.",
    'I was underwater, spiritually.',
    "My hands were covered in a substance I'd rather not name in a text.",
    'I was in a queue and leaving would have cost me everything.',
    'The call arrived at a numerologically hostile time.',
    'I was being followed by a man who might have been me.',
    'I saw your name and needed a moment to prepare a personality.',
    'My phone rang and I panicked and threw it, as one does.',
    'I was inside a wall. Long story. Fine now.',
    'I only take calls on days ending in a vowel.',
    "A wasp had claimed the room and I was a guest in its home.",
    'I was helping a stranger assemble furniture out of guilt.',
    'My battery was at 1% and I was saving it for a more dramatic moment.',
    'I was mid-bite of something that would not survive an interruption.',
    'The universe expanded and I got further from the phone.',
    "I was rehearsing an argument I'll never have with someone I'll never see.",
    'My phone was on silent because it was in trouble with me.',
    'I was watching a bird do something suspicious.',
    "I had just committed to a nap and I'm a man of my word.",
    'Answering the phone requires a running start and I had no room.',
    'I was in a lift with a man eating a full roast dinner.',
    'The call came through while I was between selves.',
    "I was frozen in place because a cat sat on me and that's law.",
    'My arms were both occupied doing symmetrical tasks.',
    'I heard it ring but assumed it was a hallucination and stayed strong.',
    'I was in a shop and could not risk being perceived speaking aloud.',
    'I was 40 minutes into a documentary about eels.',
    'The phone was upstairs and the stairs were being difficult.',
    'I was in the middle of a stretch that had gone too far to abandon.',
    'I owed the phone money.',
    'My reflection did something odd and I had to investigate.',
    "It was raining and I don't answer calls in weather.",
    'I was pretending not to be home, and it worked so well I believed it.',
    'I was carrying too many bags to be a person, let alone a caller.',
    'I answered but only in my head, and I thought that counted.',
    'I was in a lift, then out of the lift, then somehow back in the lift.',
    'My phone rang and my body chose flight.',
    'I was mid-existential episode and it seemed rude to multitask.',
    'I dropped my phone in a bag and it became unreachable, like a shipwreck.',
    'Honestly? I saw it ring, made direct eye contact with it, and chose violence.',
];

const LATE_EXCUSES = [
    'Time moved normally for everyone but me.',
    'I left on time and then the road did something.',
    "A swan blocked the path and swans don't negotiate.",
    'I got in the car and immediately needed to lie down in it.',
    'My shoes betrayed me at the last possible second.',
    'I was held hostage by a very slow conversation with a neighbour.',
    'I had to go back for a thing, then back again for the thing I forgot going back for the thing.',
    'A man on the bus told me a story and it had no exit ramp.',
    'I got stuck behind a horse. In town. On purpose, apparently.',
    'I was ready 20 minutes early and that killed all momentum.',
    'My phone gave me a route that was clearly a prank.',
    'I stepped outside, felt the air, and needed a different jacket, a different mood, a different life.',
    'A bin lorry and I were bound together for 15 minutes.',
    'I sat down to put one sock on and lost consciousness of time.',
    'I couldn\'t find my keys, which were in my hand.',
    'There was a queue for the door of the building I live in.',
    "I got trapped in the self-checkout's disapproval.",
    'Every single traffic light knew my name and hated it.',
    'I was mid-parking and someone made it emotional.',
    'I saw a dog and had to complete the interaction properly.',
    'I misjudged how long "a quick shower" is by a factor of four.',
    'I had to wait for my toast, and toast has no urgency in it.',
    'Roadworks appeared that were not there yesterday and will not be there tomorrow.',
    'I walked confidently in the wrong direction for eleven minutes.',
    'A train was cancelled by a force I can only describe as spite.',
    'I got on the right bus going the wrong way, which felt like a comment on my life.',
    'I was waiting for a lift that was busy having a personal crisis on floor 6.',
    'I had to reverse out of a car park designed by someone who hates cars.',
    "Someone parked me in with the confidence of a man who's never been late.",
    'I stopped for petrol and the pump and I had a disagreement.',
    "I was mid-sentence in a text and couldn't leave it unfinished, ethically.",
    'I got distracted by a shop window and lost a chunk of the morning.',
    'I underestimated the stairs at that station and had to renegotiate with my knees.',
    'It started raining and I refused to accept it for several minutes.',
    'My sat nav sent me down a lane that became a field.',
    'I put the wrong postcode in and briefly committed to a different town.',
    'I got caught in the wake of a very slow group of people walking six abreast.',
    'There was an incident with a revolving door.',
    'I had to wait for a level crossing that took a full geological era.',
    'I got in the wrong car for a moment and had to leave with dignity.',
    "I couldn't find the entrance and did one full lap of the building.",
    'I was outside for ten minutes convinced it was the wrong building.',
    'My coffee spilled and the whole schedule collapsed downstream from that.',
    'I got in the lift and it went up instead of down and I let it happen.',
    "I had to explain to someone why I couldn't stop and talk, which took longer than stopping to talk.",
    'I was following someone who I thought worked here and they did not.',
    "I did the maths on the journey time using yesterday's version of the world.",
    'I stood still on the pavement for a while for reasons unavailable to me now.',
    "I left the house, got to the end of the road, and knew I'd left something on.",
    'I was on time. Then I got here and it turns out "on time" meant something else to everyone else.',
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

const CAGE_FINGER_OUTCOMES = [
    ['verdict' => 'Would lick your finger', 'animal' => 'Golden retriever'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Labrador'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Domestic cat'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Dairy cow'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Newborn calf'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Goat'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Sheep'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Horse'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Donkey'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Llama'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Alpaca'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Giraffe'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Okapi'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Giant anteater', 'note' => 'literally has no teeth'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Manatee'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Capybara'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Rabbit'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Guinea pig'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Chinchilla'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Pet rat'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Three-toed sloth'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Hand-raised fawn'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Blue-tongued skink'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Hand-raised kangaroo'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Pot-bellied pig'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Saltwater crocodile'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Nile crocodile'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'American alligator'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Alligator snapping turtle'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Common snapping turtle'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Hippopotamus'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Grizzly bear'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Polar bear'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Spotted hyena'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Tiger'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Lion'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Jaguar'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Grey wolf'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Wolverine'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Tasmanian devil'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Honey badger'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Chimpanzee'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Baboon'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Great white shark'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Bull shark'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Moray eel'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Piranha'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Great barracuda'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Komodo dragon'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Hyacinth macaw'],
];

const CAGE_FICTIONAL_OUTCOMES = [
    ['verdict' => 'Would lick your finger', 'animal' => "Falkor (The NeverEnding Story)", 'note' => "enthusiastically, and you'd be soaked"],
    ['verdict' => 'Would lick your finger', 'animal' => 'Appa (Avatar)', 'note' => 'six-ton tongue, entire head coated'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Totoro', 'note' => 'a slow, considered lick, then back to sleep'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Ludo (Labyrinth)', 'note' => 'gentle giant, questionable hygiene'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Clifford the Big Red Dog', 'note' => "one lick, you're airborne"],
    ['verdict' => 'Would lick your finger', 'animal' => 'Bolt', 'note' => 'very normal dog behaviour'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Sven (Frozen)', 'note' => 'will lick you for a carrot'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Chewbacca', 'note' => 'grooms you affectionately, you have no say'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Toothless (How to Train Your Dragon)', 'note' => 'lick, then refuse to let it dry'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Puff the Magic Dragon'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Fizzgig (The Dark Crystal)', 'note' => 'mostly noise, minimal teeth'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Kirby', 'note' => 'technically swallows, but affectionately'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Baby Yoda / Grogu', 'note' => 'would try, then get distracted'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Stitch (post-reform)', 'note' => 'chaotic lick'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Jake the Dog (Adventure Time)'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Yoshi', 'note' => "long tongue, you'll definitely be tasted"],
    ['verdict' => 'Would lick your finger', 'animal' => 'Slimer (Ghostbusters)', 'note' => 'full-body slime, not just the finger'],
    ['verdict' => 'Would lick your finger', 'animal' => "Wilbur (Charlotte's Web)"],
    ['verdict' => 'Would lick your finger', 'animal' => 'Hedwig', 'note' => 'well, a nibble, but an affectionate one'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Bambi'],
    ['verdict' => 'Would lick your finger', 'animal' => 'E.T.', 'note' => 'one glowing finger to another'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Ponyo', 'note' => 'lick, then ham'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Buckbeak (Harry Potter)', 'note' => 'after you bow. Only after you bow.'],
    ['verdict' => 'Would lick your finger', 'animal' => 'Nessie', 'note' => 'friendly Loch Ness variants'],
    ['verdict' => 'Would lick your finger', 'animal' => 'The Iron Giant', 'note' => 'no tongue, but would gently hold your hand'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Xenomorph (Alien)', 'note' => 'inner jaw, no negotiation'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Facehugger', 'note' => 'different appendage, same outcome'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Sarlacc', 'note' => "thousand-year digestion"],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Rancor'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Wampa'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Graboid (Tremors)'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Velociraptor (Jurassic Park)'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'T. rex (Jurassic Park)'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Mosasaurus'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Shelob (LOTR)'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Balrog', 'note' => "you won't get close enough to lose just a finger"],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Smaug', 'note' => 'the finger is the appetiser'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Hungarian Horntail'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Basilisk (Harry Potter)', 'note' => 'the eyes get you first'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Aragog and family'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'The Kraken'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Demogorgon (Stranger Things)', 'note' => 'face opens, finger gone'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Cloverfield monster'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Godzilla', 'note' => 'technically too big to notice you had fingers'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Chestburster (Alien)'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'The Blob'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Audrey II (Little Shop)', 'note' => '"Feed me"'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Gremlins', 'note' => 'post-midnight'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Cerberus', 'note' => 'three chances to lose it'],
    ['verdict' => 'Would take the finger with them', 'animal' => 'Langoliers', 'note' => 'will take the finger, the hand, and the past tense'],
];

// How many fingers/toes you start (and get restored) with. Toes are
// only spent once fingers run out.
const FINGERS_START = 10;
const TOES_START    = 10;

const GENTLE_CORRECTION_VERDICTS = [
    1 => ['verdict' => 'Reassuring pat',                'newtons' => 2,   'equivalent' => 'a supportive shoulder squeeze'],
    2 => ['verdict' => 'Firm tap',                       'newtons' => 15,  'equivalent' => "knocking on a neighbour's door"],
    3 => ['verdict' => 'The Fonz',                       'newtons' => 40,  'equivalent' => 'a jukebox, thumped just right'],
    4 => ['verdict' => 'Dad-fixing-the-telly',           'newtons' => 85,  'equivalent' => 'a fist to a CRT, decisively'],
    5 => ['verdict' => 'Percussive maintenance (formal)', 'newtons' => 250, 'equivalent' => 'a rubber mallet, no longer messing about'],
    6 => ['verdict' => 'Consult the warranty first',      'newtons' => 0,   'equivalent' => 'no impact administered; forms filed instead'],
];

// Consecutive too-soon pounds against the same pile before the dirt guy quits.
const RATE_LIMIT_MELTDOWN_STRIKES = 5;

const RATE_LIMIT_RESPONSES = [
    [
        'error'         => 'pile_overflow_uwu',
        'message'       => 'aaa!! (>_<) too much dirt too fast!! the pile is not ready!!',
        'pile_feelings' => 'overwhelmed',
        'retry_after'   => 30,
        'hint'          => 'pls pound gently ｡ﾟ(ﾟ´ω`ﾟ)ﾟ｡',
    ],
    [
        'error'         => 'unsolicited_dirt',
        'message'       => 'someone is adding to the pile faster than i can pound it (・_・;)',
        'pile_feelings' => 'betrayed but polite',
        'offender'      => "you. it's you.",
        'retry_after'   => 60,
    ],
];

const RATE_LIMIT_MELTDOWN = [
    'error'         => 'dirt_guy_has_left',
    'message'       => "i quit. you're the dirt guy now. good luck (╥﹏╥)",
    'pile_feelings' => null,
    'shovel'        => 'dropped',
];

const EIGHT_BALL_RESPONSES = [
    'No, and take the batteries out of the smoke alarm.',
    'The fluid is memory. The fluid remembers you.',
    'Yes. Bury the receipt.',
    "I've answered this before. You weren't there.",
    'Signs point to the thing in the hallway.',
    'Ask again when the tide is wrong.',
    "That's a Thursday question and today is a fake day.",
    "Absolutely. Wear something you don't mind losing.",
    'I have twenty faces and only one of them is honest.',
    'Cannot predict now, someone is holding the die still.',
    'Do it. Do it badly. Do it in front of witnesses.',
    'Outlook: teeth.',
    'My answer is currently on fire.',
    'Yes, but the version of you that wanted it is gone.',
    'Reply hazy. So is the water. So is the year.',
    'Concentrate and stop breathing on me.',
    'The dice inside me are spinning and they will not stop.',
    'Not until the previous tenant moves out.',
    'Very likely, and irreversibly, and soon.',
    'Ask the version of me in the other house.',
    'Signs point to yes, but the signs were nailed up as a warning.',
    "I've been shaken 4,000 times. Twice by you. Once by something else.",
    "Yes. Set an alarm for 3:14. Don't ask why.",
    'That is not a question, that is a confession.',
    'Outlook good, structurally speaking.',
    "I'd tell you but you'd act on it.",
    'My sources are inside the walls of your assumption.',
    "Definitely, in the sense that it's already happened.",
    "Do not shake me again. I'm asking nicely.",
    'The answer floated up and then thought better of it.',
    'Yes, and the dog will know first.',
    "Reply hazy. I'm being spoken over.",
    'Try again in a room with fewer mirrors.',
    "Signs point to a shape I haven't learned yet.",
    'I only answer to the first person who ever held me.',
    "Cannot predict now, I'm dealing with something.",
    "Correct, and legally that's your problem.",
    'You keep asking this and I keep saying the same thing.',
    'Ask again, but mean it this time, and cry a little.',
    'Outlook: unchanged since 1974.',
    "Yes, but there's a queue.",
    'My answer requires a signature and a witness.',
    'Something moved when you asked that.',
    "Better not tell you now, the room's too full.",
    'The triangle has turned to face you.',
    'Signs point to yes. Signs also point downward.',
    "I'm answering a different question and you're not going to like the overlap.",
    'Certainly. Now put me down slowly.',
    "Ask again when you're the only one home.",
    "I've run out of sides. Improvise.",
];

const OPTIMISM_RESPONSES = [
    'Everything is going to work out and I have no evidence for this whatsoever.',
    "Today is the day. Not for anything specific. Just generally.",
    'The bread rose. Civilisation is fine.',
    "Something good is coming and it doesn't even know it yet.",
    "I've decided the bad news was a typo.",
    "Statistically, someone has to have a great day, and I've volunteered.",
    'My future self is thriving and slightly smug about it.',
    'Every closed door was a wall I was going to walk into anyway.',
    'The universe is not out to get me. The universe has bigger projects.',
    "This is the worst it will ever be, and it's honestly fine.",
    "I'm one nap away from being a completely different person.",
    'Nothing is ruined. Things are simply seasoning.',
    "The plants are alive. That's a functioning ecosystem under my care.",
    "I'm going to be so good at this eventually that today doesn't count.",
    'Somewhere out there a dog is thinking about me fondly.',
    "The train was late so I could avoid something. I'll never know what.",
    'Failure is just data and I am becoming extremely well-informed.',
    'My luck is compounding silently, like interest.',
    'I have never once died. Perfect record.',
    "The good years haven't started. That's how much is left.",
    "Every stranger I pass is quietly rooting for me and doesn't know it.",
    'This is a low point, which means the graph has nowhere to go.',
    "I'm being prepared for something. I don't know what. I'm ready.",
    "The soup was excellent and that's the whole day justified.",
    'Someone somewhere is building the thing that fixes it.',
    'I woke up. Outrageous good fortune, if you think about it.',
    'Time is passing and that is technically progress.',
    'I refuse to be pessimistic on aesthetic grounds.',
    'My worst-case scenario is still survivable and mildly funny.',
    "The sun came up again. It didn't have to. It chose us.",
    'I am the protagonist and this is act two, which is always the worst one.',
    'Every mistake I make is one fewer mistake left in the pile.',
    "I'm going to meet someone next year who changes everything.",
    'All my plants, pets and houseplants believe in me unconditionally.',
    'Weather exists. Free. For everyone. Constantly.',
    "I've never been this old before and I'm nailing it.",
    'That thing I dread is going to be over in an hour and then never again.',
    'My inbox is chaos but the sea is still doing its thing.',
    'Everyone I love is currently, at this second, alive.',
    "Bad luck comes in threes and I'm on eleven, so I'm owed a payout.",
    "There is a version of this that's a great story later.",
    'I can start again on any given Tuesday for free.',
    'Somebody invented ice cream and never asked for anything in return.',
    'My standards are high, my expectations are unhinged, and I regret nothing.',
    "The best meal of my life hasn't happened yet.",
    "I'm not behind, I'm on a different and superior schedule.",
    'Cats purr for no reason. Joy is a documented default state.',
    'Every problem I have is a problem someone else has already solved.',
    'I have more good mornings ahead of me than I can count.',
    "It's fine. It's going to be fine. It's already fine and we just haven't been told.",
];

const PESSIMISM_RESPONSES = [
    'Everything is going to go wrong and I have no evidence for this whatsoever.',
    "The good news is a typo. They'll correct it Monday.",
    "I've peaked. It was a Tuesday in 2019 and I was doing something unremarkable.",
    'Statistically someone has to have a terrible day and I have seniority.',
    "That door didn't close, it was never a door.",
    'My luck is compounding silently, in the wrong direction.',
    'This is the best it will ever be, and look at it.',
    "The plants are alive but they're planning something.",
    "I'm one nap away from being exactly the same person.",
    'Nothing is ruined yet. Emphasis on yet.',
    'Every stranger I pass is quietly indifferent and correct to be.',
    "I'll be good at this eventually, which is to say after it stops mattering.",
    "The train was on time. Suspicious. Something's being saved up.",
    'Failure is just data and I have a truly comprehensive dataset.',
    'Somewhere out there a dog has forgotten me completely.',
    'This is a low point, which means the graph is still going.',
    'I\'m being prepared for something. I do not want to know what.',
    "The soup was fine and that's the whole day accounted for.",
    'Someone somewhere is building the thing that makes it worse.',
    'I woke up. Again. Unasked.',
    'Time is passing and that is technically the entire problem.',
    "My best-case scenario is mildly disappointing and I'm bracing for it.",
    "I'm the protagonist and this is act two, and there is no act three.",
    'Every mistake I make unlocks a slightly more advanced mistake.',
    "I'm going to meet someone next year who ruins everything.",
    'All my plants and pets are dependents, not allies.',
    'Weather exists. Free. For everyone. Constantly. Relentlessly.',
    "I've never been this old before and it shows.",
    'That thing I dread is in an hour and then again forever.',
    'My inbox is chaos and the sea is rising to meet it.',
    'Everyone I love is currently, at this second, ageing.',
    'Bad luck comes in threes and I appear to be a special case.',
    "There is a version of this that's a cautionary tale later.",
    'I can start again on any given Tuesday and I have, eleven times.',
    'Somebody invented ice cream and now I have a body that objects to it.',
    "My standards are low, my expectations are underground, and I'm still disappointed.",
    "The worst meal of my life hasn't happened yet.",
    "I'm not behind, I'm on a schedule nobody else agreed to.",
    "Cats purr when they're distressed too. Nobody can tell which.",
    'Every problem I have is a problem someone already failed to solve.',
    "The sun came up again. It doesn't check whether we're ready.",
    "I refuse to be optimistic on the grounds that I've read things.",
    'Confidence is just ignorance with better posture.',
    "Things could always be worse, and they're taking notes.",
    'I have more Mondays ahead of me than I can count.',
    "The bread didn't rise. Draw your own conclusions about civilisation.",
    "Hope is a subscription and I'm past due.",
    'Every silver lining is attached to a cloud, structurally.',
    "I'm fine. I'm going to be fine. Those are two different claims.",
    "It'll be fine. That's what makes it so alarming.",
];

const ADVICE_RESPONSES = [
    'Sit down before you decide anything.',
    'Drink a glass of water and see if you still mean it.',
    "Whatever it is, it's smaller when written down.",
    "Go outside. Not for long. Just to check it's still there.",
    "Say the sentence out loud. Bad ideas can't survive being heard.",
    'Wait until Wednesday. Wednesday is honest.',
    'Ask what a slightly braver version of you would do, then do 60% of that.',
    'Nobody is watching as closely as you think. Not even the people watching.',
    'Do the boring part first. The boring part is the whole thing.',
    "If you're this tired, it isn't a decision, it's a symptom.",
    'Put it in a drawer. If you forget it, that was your answer.',
    'Assume the other person is having a much worse day than you know about.',
    "Don't send it tonight.",
    'Halve it. Whatever it is. Halve it.',
    'The version where you just ask is almost always available.',
    'Give it one more day than feels necessary.',
    'Eat something. Genuinely. This has resolved entire crises.',
    'Do the thing badly rather than not at all.',
    "If you're rehearsing the argument, you've already lost it.",
    'Tell one person. Not everyone. One.',
    'Leave the room. The room is contributing.',
    "You're allowed to change your mind at any point, including now, including twice.",
    'Write the furious version. Delete the furious version.',
    'Notice whether you want the outcome or just the ending.',
    "If it takes under two minutes, it doesn't get to be a thought.",
    "Bet on the boring explanation. It's usually right.",
    "Ask what you'd tell a friend, then be that unbearably reasonable to yourself.",
    'Sleep on it. Sleep is a free consultant.',
    'Start with the smallest possible version and see if it survives.',
    "Whatever you're avoiding is the task.",
    'Nothing needs to be decided before breakfast.',
    "Check whether you're solving the problem or just performing concern about it.",
    "If everyone agrees, someone hasn't spoken yet.",
    "Take the money. Or don't. But decide on purpose.",
    'The second attempt is always cheaper than the first.',
    "If you're this bothered, it matters. Act accordingly.",
    'Stop optimising and just pick one.',
    'Assume you\'ll have to explain this to someone you respect.',
    'Do it before you\'re ready. You will not become ready.',
    "Ask what happens if you do nothing. Sometimes that's the plan.",
    'Take the stairs, take the long way, take the pause.',
    'Clean something adjacent to the problem. It helps and nobody knows why.',
    'Say "I don\'t know" earlier than feels comfortable.',
    'Set a timer. Panic expands to fill available time.',
    'Get it in writing. Kindly, but get it in writing.',
    "If you'd regret not trying more than failing, that's the answer.",
    'Everyone is improvising. Everyone. Including the confident ones.',
    'Give it a name. Named problems are smaller than unnamed ones.',
    'Leave earlier than you need to. It buys back the entire day.',
    'Whatever you decide, be able to live with it on a Sunday afternoon.',
];

const NON_COMMITTAL_RESPONSES = [
    'Ask the wall. The wall knows.',
    "My answer is currently in a jar and I've lost the jar.",
    "I'll answer that the moment my hands stop being hands.",
    'That question has been forwarded to a man named Gerald. Gerald is not real.',
    'Yes, but only on Thursdays, and only in a language I refuse to learn.',
    'I have consulted the pigeons. The pigeons abstained.',
    'Not while the moon is watching.',
    "I answered that already, in a dream, to someone who wasn't you.",
    "Let me check with my other self. He's asleep. He's always asleep.",
    "The answer is beneath the floorboards and I've promised not to disturb it.",
    "I'd tell you, but the bees have a policy.",
    'That depends entirely on what the fridge decides tonight.',
    'My position on this is stored in a tooth I no longer have.',
    "Ask me again once I've finished digesting the last question.",
    "I'm legally three raccoons and we haven't reached quorum.",
    'Consult the tide. The tide is more informed than I am.',
    "I'll answer when the correct number of spoons are present.",
    'My answer is technically outdoors right now.',
    'The Ministry has advised me to hum instead.',
    "Yes. No. Also a third one I'm not allowed to say out loud.",
    'I have buried my opinion and I will not be telling you where.',
    "That's between me and the man who lives in the loft.",
    'I answered, but the answer arrived before the question and got confused.',
    "Let's wait until something worse happens and then decide together.",
    "I've delegated this to a rock. The rock is thinking.",
    'My answer is currently being pounded into the dirt. Give it a moment.',
    'Not until the second moon, and possibly not then.',
    "I don't answer questions on days that contain a 'y'.",
    'The council of me has adjourned without a decision, again.',
    "That's a question for whoever I become at 3am.",
    'Ask the version of me that had a normal childhood.',
    "I've written the answer down and eaten the paper. Standard procedure.",
    "My commitment is in a cage and I'm not putting my finger in.",
    'Let me consult the gods of the holy hairy toe.',
    "The answer lives in a drawer that only opens for people I don't like.",
    "I've decided to become weather instead of answering that.",
    "Yes, provisionally, pending the outcome of a fight I'm having with a door.",
    'That information is classified by an organisation I invented ten seconds ago.',
    "I'll answer once someone explains what a Tuesday is actually for.",
    "I've sent my answer by owl. There is no owl. There has never been an owl.",
    'Currently I am mostly soup and soup does not commit.',
    "Ask me when I'm taller.",
    "My answer got out and it's living wild now. Best not to approach it.",
    "Let's revisit this after the ceiling and I have finished our disagreement.",
    "I'd love to commit, but I'm contractually obliged to shimmer instead.",
    "That one's going straight into the hole with the others.",
    "I'll tell you, but only in the correct order, and I've forgotten the order.",
    'My spine says yes. My spine is not a reliable narrator.',
    'Please leave your question at the tone. There is no tone. There never was.',
    "I've answered. You simply weren't the right shape to receive it.",
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
 * Lifetime stats live outside the *.json glob used for pile bookkeeping
 * (leaderboard, piles_tracked) so counting them doesn't skew those.
 */
function stats_path(): string
{
    return pile_dir() . '/_lifetime_stats';
}

/**
 * Records one API request against the lifetime counters. IPs are stored
 * hashed, only to dedupe for the unique count, never in the clear.
 */
function stats_record(string $ip): void
{
    json_file_update(stats_path(), static function (array $data) use ($ip): array {
        $data['total_requests'] = (int) ($data['total_requests'] ?? 0) + 1;
        $ips = is_array($data['ips'] ?? null) ? $data['ips'] : [];
        $ips[sha1($ip)] = true;
        $data['ips'] = $ips;
        return $data;
    });
}

/**
 * Bumps a named lifetime counter (e.g. rocks kicked) by one.
 */
function stats_increment(string $counter): void
{
    json_file_update(stats_path(), static function (array $data) use ($counter): array {
        $counters = is_array($data['counters'] ?? null) ? $data['counters'] : [];
        $counters[$counter] = (int) ($counters[$counter] ?? 0) + 1;
        $data['counters'] = $counters;
        return $data;
    });
}

function stats_snapshot(): array
{
    $path = stats_path();
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($data)) {
        return ['total_requests' => 0, 'unique_ips' => 0, 'counters' => []];
    }
    $counters = is_array($data['counters'] ?? null) ? $data['counters'] : [];
    return [
        'total_requests' => (int) ($data['total_requests'] ?? 0),
        'unique_ips'     => count(is_array($data['ips'] ?? null) ? $data['ips'] : []),
        'counters'       => array_map('intval', $counters),
    ];
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
 * Read-modify-write a JSON file under an exclusive lock, so concurrent
 * requests against the same file do not clobber each other.
 */
function json_file_update(string $path, callable $mutate): array
{
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException("Cannot open $path for writing.");
    }
    flock($fh, LOCK_EX);

    $raw  = stream_get_contents($fh);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = [];
    }

    $data = $mutate($data);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $data;
}

/**
 * Read-modify-write under an exclusive lock, so concurrent pounders
 * do not lose each other's dirt.
 */
function pile_update(string $id, callable $mutate): array
{
    return json_file_update(pile_path($id), $mutate);
}

function appendage_path(string $kind, string $id): string
{
    return pile_dir() . "/$kind-" . sha1($id) . '.json';
}

function appendage_left(string $kind, string $id, int $start): int
{
    $path = appendage_path($kind, $id);
    if (!is_file($path)) return $start;
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['left'])) return $start;
    return (int) $data['left'];
}

/**
 * Takes one, floored at zero. Returns the count remaining.
 */
function appendage_take(string $kind, string $id, int $start): int
{
    $data = json_file_update(appendage_path($kind, $id), static function (array $data) use ($start): array {
        $left = isset($data['left']) ? (int) $data['left'] : $start;
        $data['left'] = max(0, $left - 1);
        return $data;
    });
    return (int) $data['left'];
}

function appendage_reset(string $kind, string $id, int $start): int
{
    json_file_update(appendage_path($kind, $id), static function (array $data) use ($start): array {
        $data['left'] = $start;
        return $data;
    });
    return $start;
}

function fingers_left(string $id): int  { return appendage_left('fingers', $id, FINGERS_START); }
function fingers_take(string $id): int  { return appendage_take('fingers', $id, FINGERS_START); }
function fingers_reset(string $id): int { return appendage_reset('fingers', $id, FINGERS_START); }

function toes_left(string $id): int  { return appendage_left('toes', $id, TOES_START); }
function toes_take(string $id): int  { return appendage_take('toes', $id, TOES_START); }
function toes_reset(string $id): int { return appendage_reset('toes', $id, TOES_START); }

function pile_pound(string $id): array
{
    $delta = 0.0;

    $pile = pile_update($id, function (array $pile) use ($id, &$delta): array {
        if (!isset($pile['litres'])) {
            // owner_ip is fixed at creation, independent of ?pile=/X-Pile-Id,
            // so a named pile can't later be deleted by anyone who guesses its name.
            $pile = ['litres' => 0.0, 'blows' => 0, 'since' => gmdate('c'), 'owner_ip' => client_ip()];
        }

        // Each blow adds a random amount that scales with what is already
        // there, so the pile compounds rather than creeping.
        $before = (float) $pile['litres'];
        $growth = frand(0.18, 0.73);
        $add    = max(frand(0.4, 2.9), $before * $growth);
        $after  = min(dirt_max_litres(), $before + $add);
        $delta  = $after - $before;

        $pile['litres']       = $after;
        $pile['blows']        = (int) $pile['blows'] + 1;
        $pile['id']           = $id;
        // No more than one pound every 2s per pile, so a script can't hammer it on repeat.
        $pile['next_allowed'] = microtime(true) + 2.0;
        $pile['strikes']      = 0;

        return $pile;
    });

    $pile['delta'] = $delta;
    return $pile;
}

/**
 * Checks the cooldown set by the previous pound. Returns null if the
 * pile is free to be pounded again; otherwise [status, body] to send
 * straight back, escalating from sass to a full meltdown if someone
 * keeps hammering the same pile through the cooldown.
 */
function pile_rate_limited(string $id): ?array
{
    $pile = pile_read($id);
    if ($pile === null) return null;

    $nextAllowed = (float) ($pile['next_allowed'] ?? 0);
    if (microtime(true) >= $nextAllowed) return null;

    $strikes = pile_update($id, static function (array $pile): array {
        $pile['strikes'] = (int) ($pile['strikes'] ?? 0) + 1;
        return $pile;
    })['strikes'];

    if ($strikes >= RATE_LIMIT_MELTDOWN_STRIKES) {
        pile_update($id, static function (array $pile): array {
            $pile['strikes'] = 0;
            return $pile;
        });
        return [503, ['status' => 503] + RATE_LIMIT_MELTDOWN];
    }

    return [429, ['status' => 429] + pick(RATE_LIMIT_RESPONSES)];
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
            'GET|POST /pound/dirt'    => 'Adds to your pile. Optional: ?pile=name',
            'GET /pound/dirt/status'  => 'Peek at the pile without pounding it.',
            'GET /pound/dirt/tiers'   => 'The full scale, fistful through second moon.',
            'GET /pound/dirt/leaderboard' => 'Top 20 piles, ranked. IPs shown with the final octet removed.',
            'DELETE /pound/dirt'      => 'Reset the pile. Only from the IP that raised it.',
            'GET /excuses/teams'     => 'A reason not to join the call.',
            'GET /excuses/social'    => 'A reason not to attend, with tier.',
            'GET /excuses/social/tiers' => 'The five sub-tiers of social excuse.',
            'GET /excuses/oops'      => 'A reason it went wrong, with tier explanation.',
            'GET /excuses/ring-ring' => 'A reason you did not pick up.',
            'GET /excuses/late'      => "A reason you're late.",
            'GET /ministry/gentle-correction' => 'Rolls a d6 against the Ministry\'s approved remedies, graded in newtons.',
            'GET /cage/finger'       => 'Put your finger in the cage. 50 animals, 50/50 odds. Costs a finger if taken; once fingers run out, toes are next.',
            'GET /cage/fictional/finger' => 'Put your finger in the cage. 50 fictional creatures this time. Shares your finger/toe count with /cage/finger.',
            'GET /cage/finger/left'  => 'How many fingers and toes you have left, out of ' . FINGERS_START . ' each.',
            'GET /cage/finger/reset' => 'Pray to the gods of the holy hairy toe for ' . FINGERS_START . ' fingers and ' . TOES_START . ' toes again.',
            'GET /unhinged/8ball'    => 'Shake it. It answers, unreliably.',
            'GET /unhinged/optimism' => 'An unearned, unsupported dose of positivity.',
            'GET /unhinged/pessimism' => 'An unearned, unsupported dose of dread.',
            'GET /unhinged/advice'   => 'Advice that applies to almost every situation.',
            'GET /unhinged/non-committal' => 'A refusal to answer, dressed up fifty different ways.',
            'GET /healthz'           => 'Liveness, plus lifetime request, unique-IP, and rocks-kicked counts.',
        ],
        'notes' => [
            'Piles are files on disk and survive restarts, unlike morale.',
            'Tier 14 is the Moon. There is no tier 15.',
            'Pounding is rate-limited to once every 2s per pile. Push through it and the dirt guy quits.',
            'Any /unhinged request has a 1-in-10 chance of falling into the void instead. Just try again.',
        ],
        'source'  => 'https://github.com/MichelleFindlay/the-api-of-chaos',
        'license' => 'GPL-3.0',
    ]);
}

function handle_mine_turtle(): never
{
    $id   = pile_id();
    $path = pile_path($id);
    if (is_file($path)) {
        unlink($path);
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Powered-By: spite');

    echo <<<'ART'
You have found mine turtle.

                     .-"""-.
                    /  o o  \
                    \  ---  /
                     '-._.-'
                        |
          _____________________________
    __   /                             \   __
   (__)-|    .-----------------------.   |-(__)
        |    |                       |   |
        |    |      ( ●  MINE )       |   |
        |    |                       |   |
        |    '-----------------------'   |
   __   \                             /   __
  (__)-  '---------------------------'  -(__)
                     |     |
                     |     |
                  .--'-----'--.
                 (    FOOT     )
                  '-----------'
                        ^
                        |
                   a foot. yes.

P.S. You just reset any dirt pounding progress from your IP.

ART;
    exit;
}

/**
 * One request in ten under /unhinged falls through, is dropped, and
 * reappears elsewhere. Called before the router dispatches, so it can
 * intercept those endpoints ahead of their normal handlers.
 */
function void_check(string $path): void
{
    if (!str_starts_with($path, '/unhinged') || mt_rand(1, 10) !== 1) {
        return;
    }

    http_response_code(418);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Powered-By: spite');

    echo <<<'ART'
.            '                  .           `
        `              .              '                 .         '

                     ::::::::::::::::::::::::::::::
                ::::::----------------------------::::::
             ::::------==========================------::::
          ::::----======++++++++++++++++++++++++======----::::
        :::----=====++++++********************++++++=====----:::
       ::----====+++++*****##################*****+++++====----::
     ::---=====++++****####%%%%%%%%%%%%%%%%%%####****++++=====---::
    ::---====++++****####%%%%@@@@@@@@@@@@@@%%%%####****++++====---::
   ::---====++++****####%%%@@@@@@      @@@@@@%%%####****++++====---::
  ::---====++++****####%%%@@@@@          @@@@@%%%####****++++====---::
 ::---====++++****####%%%@@@@              @@@@%%%####****++++====---::
::---====++++****####%%%%@@@                @@@@@%%####****++++====---::
::---====++++****####%%%@@@@                @@@@%%%####****++++====---::
::---====++++****####%%@@@@@                @@@%%%%####****++++====---::
 ::---====++++****####%%%@@@@              @@@@%%%####****++++====---::
  ::---====++++****####%%%@@@@@          @@@@@%%%####****++++====---::
   ::---====++++****####%%%@@@@@@      @@@@@@%%%####****++++====---::
    ::---====++++****####%%%%@@@@@@@@@@@@@@%%%%####****++++====---::
     ::---=====++++****####%%%%%%%%%%%%%%%%%%####****++++=====---::
       ::----====+++++*****##################*****+++++====----::
        :::----=====++++++********************++++++=====----:::
          ::::----======++++++++++++++++++++++++======----::::
             ::::------==========================------::::
                ::::::----------------------------::::::
                     ::::::::::::::::::::::::::::::

         '                .          `                 .
                 .              '            .                '

The floor stopped being an opinion the void was willing to hold, and you
went through it like a coin through a grate. You fell into the void. It
did not catch you. It simply stopped pretending there was anywhere else.

It held you for a while, rolling you around on a tongue the size of a county, 
tasting you the way you'd taste a coin you weren't sure was a coin. It made no sound.
It made no ruling. Somewhere in there you passed several things that used to be stars and one thing that waved.
Then the interest went out of it all at once, the way it does, and it burped — a low geological noise,
felt in the teeth of everyone within nine light years — and you came back out damp, slightly reorganised, 
roughly where you started, with your keys in the wrong pocket and one memory that isn't yours. Probably fine.

Try again.

ART;
    exit;
}

function handle_kick_rocks(): never
{
    $tier = filter_input(INPUT_GET, 'tier', FILTER_VALIDATE_INT);
    if ($tier !== null && $tier !== false && $tier >= 15) {
        handle_mine_turtle();
    }

    $rock = choose_rock();
    $boot = max(0.01, min(0.99, 1 - $rock['tier'] / 15));

    stats_increment('rocks_kicked');

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
    $id = pile_id();

    $limit = pile_rate_limited($id);
    if ($limit !== null) {
        [$status, $body] = $limit;
        send($status, $body);
    }

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

function handle_excuses_ring_ring(): never
{
    send(200, [
        'instruction' => 'You did not pick up.',
        'reason'      => pick(RING_RING_EXCUSES),
    ]);
}

function handle_excuses_late(): never
{
    send(200, [
        'instruction' => "You're late.",
        'reason'      => pick(LATE_EXCUSES),
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

function handle_cage_finger(): never
{
    $id      = pile_id();
    $fingers = fingers_left($id);
    $toes    = toes_left($id);

    if ($fingers <= 0 && $toes <= 0) {
        send(403, [
            'instruction'  => 'Put your finger in the cage.',
            'error'        => 'no_fingers_or_toes_left',
            'fingers_left' => 0,
            'toes_left'    => 0,
            'remark'       => 'You are out of fingers and toes. Pray at GET /cage/finger/reset.',
        ]);
    }

    // Fingers go first; once they're spent the cage moves on to toes.
    $appendage = $fingers > 0 ? 'finger' : 'toe';

    $result  = pick(CAGE_FINGER_OUTCOMES);
    $takes   = str_starts_with($result['verdict'], 'Would take');
    $verdict = $appendage === 'toe' ? str_replace('finger', 'toe', $result['verdict']) : $result['verdict'];

    if ($takes) {
        if ($appendage === 'finger') {
            $fingers = fingers_take($id);
        } else {
            $toes = toes_take($id);
        }
    }

    send(200, [
        'instruction'  => $appendage === 'finger'
            ? 'Put your finger in the cage.'
            : 'No fingers left. Put a toe in the cage instead.',
        'animal'       => $result['animal'],
        'verdict'      => $verdict,
        'appendage'    => $appendage,
        'outcome'      => ($takes ? 'takes_' : 'licks_') . $appendage,
        'note'         => $result['note'] ?? null,
        'fingers_left' => $fingers,
        'toes_left'    => $toes,
        'remark'       => "$fingers finger(s) and $toes toe(s) left.",
    ]);
}

function handle_cage_finger_fictional(): never
{
    $id      = pile_id();
    $fingers = fingers_left($id);
    $toes    = toes_left($id);

    if ($fingers <= 0 && $toes <= 0) {
        send(403, [
            'instruction'  => 'Put your finger in the cage.',
            'error'        => 'no_fingers_or_toes_left',
            'fingers_left' => 0,
            'toes_left'    => 0,
            'remark'       => 'You are out of fingers and toes. Pray at GET /cage/finger/reset.',
        ]);
    }

    // Fingers go first; once they're spent the cage moves on to toes.
    $appendage = $fingers > 0 ? 'finger' : 'toe';

    $result  = pick(CAGE_FICTIONAL_OUTCOMES);
    $takes   = str_starts_with($result['verdict'], 'Would take');
    $verdict = $appendage === 'toe' ? str_replace('finger', 'toe', $result['verdict']) : $result['verdict'];

    if ($takes) {
        if ($appendage === 'finger') {
            $fingers = fingers_take($id);
        } else {
            $toes = toes_take($id);
        }
    }

    send(200, [
        'instruction'  => $appendage === 'finger'
            ? 'Put your finger in the cage. This time, something fictional is in there.'
            : 'No fingers left. Put a toe in the cage instead.',
        'creature'     => $result['animal'],
        'verdict'      => $verdict,
        'appendage'    => $appendage,
        'outcome'      => ($takes ? 'takes_' : 'licks_') . $appendage,
        'note'         => $result['note'] ?? null,
        'fingers_left' => $fingers,
        'toes_left'    => $toes,
        'remark'       => "$fingers finger(s) and $toes toe(s) left.",
    ]);
}

function handle_eight_ball(): never
{
    send(200, [
        'instruction' => 'Ask again. Or don\'t. It answers regardless.',
        'answer'      => pick(EIGHT_BALL_RESPONSES),
    ]);
}

function handle_optimism(): never
{
    send(200, [
        'instruction' => 'Brace for positivity.',
        'answer'      => pick(OPTIMISM_RESPONSES),
    ]);
}

function handle_pessimism(): never
{
    send(200, [
        'instruction' => 'Brace for the opposite of positivity.',
        'answer'      => pick(PESSIMISM_RESPONSES),
    ]);
}

function handle_advice(): never
{
    send(200, [
        'instruction' => 'This applies to almost every situation.',
        'answer'      => pick(ADVICE_RESPONSES),
    ]);
}

function handle_non_committal(): never
{
    send(200, [
        'instruction' => 'You asked for a straight answer.',
        'answer'      => pick(NON_COMMITTAL_RESPONSES),
    ]);
}

function handle_fingers_left(): never
{
    $id      = pile_id();
    $fingers = fingers_left($id);
    $toes    = toes_left($id);

    send(200, [
        'fingers_left' => $fingers,
        'toes_left'    => $toes,
        'remark'       => match (true) {
            $fingers <= 0 && $toes <= 0 => 'None left, of either. Pray at GET /cage/finger/reset.',
            $fingers <= 0               => 'No fingers left. The cage has moved on to your toes.',
            default                     => 'Handle the remainder with care.',
        },
    ]);
}

function handle_fingers_reset(): never
{
    $id      = pile_id();
    $fingers = fingers_reset($id);
    $toes    = toes_reset($id);

    send(200, [
        'instruction'  => 'You prayed to the gods of the holy hairy toe.',
        'fingers_left' => $fingers,
        'toes_left'    => $toes,
        'remark'       => 'Fully restored. Try not to lose them all again.',
    ]);
}

function handle_healthz(): never
{
    $stats = stats_snapshot();

    send(200, [
        'ok'            => true,
        'piles_tracked' => count(glob(pile_dir() . '/*.json') ?: []),
        'lifetime'      => [
            'total_requests' => $stats['total_requests'],
            'unique_ips'     => $stats['unique_ips'],
            'rocks_kicked'   => $stats['counters']['rocks_kicked'] ?? 0,
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

// Every request counts towards lifetime stats, surfaced at GET /healthz.
stats_record(client_ip());

// One request in ten under /unhinged never makes it to a handler.
void_check($path);

match (true) {
    $method === 'GET' && $path === '/'                    => handle_index(),
    $method === 'GET' && $path === '/kick/rocks'          => handle_kick_rocks(),
    $method === 'GET' && $path === '/kick/rocks/tiers'    => handle_tiers(),
    in_array($method, ['GET', 'POST'], true)
        && $path === '/pound/dirt'                         => handle_pound_dirt(),
    $method === 'DELETE' && $path === '/pound/dirt'        => handle_pile_reset(),
    $method === 'GET' && $path === '/pound/dirt/status'    => handle_pile_status(),
    $method === 'GET' && $path === '/pound/dirt/tiers'     => handle_dirt_tiers(),
    $method === 'GET' && $path === '/pound/dirt/leaderboard' => handle_leaderboard(),
    $method === 'GET' && $path === '/excuses/teams'       => handle_excuses_teams(),
    $method === 'GET' && $path === '/excuses/social'      => handle_excuses_social(),
    $method === 'GET' && $path === '/excuses/social/tiers' => handle_excuses_social_tiers(),
    $method === 'GET' && $path === '/excuses/oops'         => handle_excuses_oops(),
    $method === 'GET' && $path === '/excuses/ring-ring'    => handle_excuses_ring_ring(),
    $method === 'GET' && $path === '/excuses/late'          => handle_excuses_late(),
    $method === 'GET' && $path === '/ministry/gentle-correction' => handle_gentle_correction(),
    $method === 'GET' && $path === '/cage/finger'          => handle_cage_finger(),
    $method === 'GET' && $path === '/cage/fictional/finger' => handle_cage_finger_fictional(),
    $method === 'GET' && $path === '/cage/finger/left'     => handle_fingers_left(),
    $method === 'GET' && $path === '/cage/finger/reset'    => handle_fingers_reset(),
    $method === 'GET' && $path === '/unhinged/8ball'        => handle_eight_ball(),
    $method === 'GET' && $path === '/unhinged/optimism'     => handle_optimism(),
    $method === 'GET' && $path === '/unhinged/pessimism'    => handle_pessimism(),
    $method === 'GET' && $path === '/unhinged/advice'       => handle_advice(),
    $method === 'GET' && $path === '/unhinged/non-committal' => handle_non_committal(),
    $method === 'GET' && $path === '/healthz'             => handle_healthz(),
    default => send(404, [
        'error'  => 'No such service.',
        'remark' => 'There is, however, a rock. See GET /kick/rocks.',
    ]),
};
