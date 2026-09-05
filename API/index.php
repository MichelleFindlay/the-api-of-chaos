<?php
declare(strict_types=1);

/**
 * The API of Chaos (AOC)
 * ------------------------------------------------------------------
 * A dismissal-as-a-service API. No external dependencies — just this
 * file plus config.php (configuration only: URLs, version, trusted
 * proxies, small tunables) sitting next to it.
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
 *   GET    /kick/munitions      assigns you an unintentionally-lost munition, with tier and arc
 *   GET    /kick/munitions/tiers  the full scale, tier 1-50, in five arcs
 *   GET    /pound/dirt          adds to your pile and returns it
 *   POST   /pound/dirt          same, for the semantically fussy
 *   GET    /pound/dirt/status   peek without pounding
 *   GET    /pound/dirt/tiers    the full scale, fistful -> second moon
 *   GET    /pound/dirt/leaderboard  top 20 piles, IPs partly masked
 *   DELETE /pound/dirt          reset the pile (cowardly)
 *   GET    /excuses/teams       a reason not to join the call
 *   GET    /excuses/social      a reason not to attend, drawn from a tier
 *   GET    /excuses/oops        a reason it went wrong, with tier explanation
 *   GET    /excuses/ring-ring   a reason you didn't pick up
 *   GET    /excuses/late        a reason you're late
 *   GET    /excuses/alibis      a reason you weren't there
 *   GET    /ministry/gentle-correction  rolls a d6 against approved remedies
 *   GET    /ministry/mandatory-pet-adoption  assigns you a legally binding pet, with tier and consequences
 *   GET    /cage/finger         put your finger in the cage
 *   GET    /cage/fictional/finger  same, but fictional creatures; shares your finger/toe count
 *   GET    /cage/finger/left    how many fingers you have left
 *   GET    /cage/finger/reset   pray for 10 fingers again
 *   GET    /unhinged/8ball      shake it, it answers
 *   GET    /unhinged/optimism   an unearned dose of positivity
 *   GET    /unhinged/pessimism  an unearned dose of dread
 *   GET    /unhinged/advice     advice for almost every situation
 *   GET    /unhinged/non-committal  a refusal to answer, fifty ways
 *   GET    /unhinged/optimistic-dooom  the end of everything, spun relentlessly positive
 *   GET    /unhinged/turn-it-upside-down  flip a random item, suffer the physics
 *   GET    /unhinged/solid-suddenly-liquid  a solid, liquefied, with consequences and tier
 *   GET    /unhinged/solid-suddenly-gelatinous  a solid, turned to jelly, with consequences and tier
 *   GET    /unhinged/choose-your-duck  a bath duck, and what it costs you, with tier
 *   GET    /unhinged/gravity-resigned  gravity has quit; what floats, and your odds of surviving it
 *   GET    /unhinged/vengeful-weather  the weather, personally offended, drawn from nine systems
 *   GET    /unhinged/wrongfall  clouds went feral, with tier
 *   GET    /healthz             liveness, plus lifetime request/unique-IP/rocks-kicked counts
 *
 * Query params
 *   /kick/rocks?tier=7          request a specific tier (1-14)
 *   /kick/rocks?min=9&max=12    constrain the random range
 *   /?changelog_test=stale      force-show the "changelog is a tombstone"
 *                                note in / , ignoring the real GitHub check
 *   /?changelog_test=fresh      force-hide it instead
 *
 * Piles are one per IP address and persist as JSON files under
 * config.php's PILE_DATA_DIR, inside the webspace (override with
 * KRAAS_DIR), because PHP forgets everything between requests. Much
 * like the people you are sending here.
 *
 * Any request under /unhinged has a 1-in-10 chance of falling into
 * the void instead of getting a normal response. Try again.
 */

require __DIR__ . '/config.php';

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

/**
 * Five ten-item arcs, escalating from "harmless clutter" to "forfeit
 * tier". Ranges are inclusive tier bounds into MUNITIONS below.
 */
const MUNITION_ARCS = [
    ['from' => 1,  'to' => 10, 'name' => 'the "I fear nothing" arc'],
    ['from' => 11, 'to' => 20, 'name' => 'the fuze wakes up'],
    ['from' => 21, 'to' => 30, 'name' => 'designed, specifically, for exactly this'],
    ['from' => 31, 'to' => 40, 'name' => 'older than everyone in the room'],
    ['from' => 41, 'to' => 50, 'name' => 'where the result is measured in treaties'],
];

const MUNITIONS = [
    ['tier' => 1,  'name' => 'Spent brass casing',
     'remark' => 'Ting. You are Beckham. The tarmac remembers you. Nobody else does.'],
    ['tier' => 2,  'name' => 'Airsoft BB',
     'remark' => 'It does not move. It has more mass than your entire kicking technique. The BB has won and the BB knows it.'],
    ['tier' => 3,  'name' => 'Percussion cap',
     'remark' => 'Pop. One duck, forty metres out, opens a single eye and files it under "not my problem."'],
    ['tier' => 4,  'name' => 'Loose .22 round',
     'remark' => 'Nothing. Cartridges need a chamber; unconfined the brass just splits like a disappointed grape. You have kicked a small metal grape.'],
    ['tier' => 5,  'name' => 'Shotgun shell',
     'remark' => 'Rolls away. Contains shot, powder, and absolutely no personal ambition.'],
    ['tier' => 6,  'name' => 'Belt of blanks',
     'remark' => 'Best sound on this entire list. Sleigh bells for people with problems. Rate this tier five stars, would kick again.'],
    ['tier' => 7,  'name' => 'Unlit signal flare',
     'remark' => "Clatters. Someone across the yard shouts DON'T KICK THAT, which is both correct and roughly one second too late to be useful to anybody."],
    ['tier' => 8,  'name' => 'Signal flare that lights',
     'remark' => 'There is now a red star cluster living in your trouser cuff. You have twenty urgent minutes and one leg that has joined a rave.'],
    ['tier' => 9,  'name' => 'Smoke grenade, pin in',
     'remark' => 'Heavier than it looks. Your toe files a formal complaint. Denied.'],
    ['tier' => 10, 'name' => 'Smoke grenade going off',
     'remark' => 'You are purple. Not "a bit purple." Purple. Your GP will ask. Your wedding photos will ask.'],

    ['tier' => 11, 'name' => 'CS canister',
     'remark' => 'You have run the experiment, you are the control group, the test group, and the crying peer reviewer.'],
    ['tier' => 12, 'name' => 'Flashbang',
     'remark' => 'Everyone is fine and everyone says WHAT for a week. Marriages have ended over less. Marriages have ended over exactly this.'],
    ['tier' => 13, 'name' => 'Thermite',
     'remark' => 'Goes through the road. Then your boot. Then the drainage. Then it considers Australia and decides not today, but soon.'],
    ['tier' => 14, 'name' => 'White phosphorus',
     'remark' => 'No. Not a joke tier. Genuinely, sincerely, please no.'],
    ['tier' => 15, 'name' => 'Grenade, pin in',
     'remark' => 'It bounces. The fuze wanted the spoon released, not a football trial. You have won a coin flip you did not know you had entered and were not invited to.'],
    ['tier' => 16, 'name' => 'Grenade, spoon pinned by gravel',
     'remark' => 'The gravel was doing all the work. The gravel was the only adult present. You have kicked the adult.'],
    ['tier' => 17, 'name' => '40mm, unarmed',
     'remark' => "It needs a barrel's worth of spin to arm. It goes clunk. Two coin flips in a row now. Statistically you should be doing the lottery instead of this."],
    ['tier' => 18, 'name' => '40mm dud, armed',
     'remark' => 'This one already tried once. It has been lying there for months rehearsing. It would love another go.'],
    ['tier' => 19, 'name' => 'Rifle grenade',
     'remark' => 'Tips over gently. Then the nose fuze notices it has tipped over. The pause between those two sentences is the longest of your life.'],
    ['tier' => 20, 'name' => 'RPG-7 warhead',
     'remark' => 'Piezoelectric fuze in the tip. Kicking the tip is not "kicking a munition," it is operating it. You are not a bystander. You are crew.'],

    ['tier' => 21, 'name' => '60mm mortar dud, nose-down in mud',
     'remark' => 'It was promised an impact. It has waited. You are keeping a promise made by someone else, badly.'],
    ['tier' => 22, 'name' => '81mm',
     'remark' => 'Same physics, bigger crater, shorter obituary, same font.'],
    ['tier' => 23, 'name' => 'PMN mine',
     'remark' => "Trips at about 8 kg. A kick is about 8 kg. You have not defeated the mine. You have completed it. Somewhere a Soviet engineer's ghost nods."],
    ['tier' => 24, 'name' => 'PFM-1 butterfly mine',
     'remark' => 'Green, wing-shaped, looks like something from a cereal box. That resemblance is not a joke and never was. Skip the punchline on this one.'],
    ['tier' => 25, 'name' => 'S-mine',
     'remark' => 'Bouncing Betty jumps to waist height first. It came all this way to look you in the eye.'],
    ['tier' => 26, 'name' => 'Claymore facing away',
     'remark' => 'Backblast only. A genuinely terrible day, but a day that has an evening.'],
    ['tier' => 27, 'name' => 'Claymore facing you',
     'remark' => '700 ball bearings, 60° arc, and the word FRONT stamped on it in capital letters by a manufacturer who anticipated you specifically.'],
    ['tier' => 28, 'name' => 'TM-62 anti-tank mine',
     'remark' => 'Needs 150 kg. You bring eight. It does not acknowledge the kick. It does not acknowledge you. Humiliation tier. You lose to a disc.'],
    ['tier' => 29, 'name' => 'BLU-97 submunition',
     'remark' => "Bright yellow, drink-can shaped, arms on release. Worst injury-per-gram ratio on the list and every word of that sentence is somebody's actual childhood."],
    ['tier' => 30, 'name' => 'Bangalore torpedo',
     'remark' => 'A pipe of explosive built to clear obstacles from a path. You are, briefly, an obstacle on a path.'],

    ['tier' => 31, 'name' => 'WWI 18-pounder in a Belgian beet field',
     'remark' => 'The Iron Harvest coughs up hundreds of tonnes a year. Farmers stack them at the field edge like firewood and do not kick them, because farmers are smarter than this list.'],
    ['tier' => 32, 'name' => 'WWI gas shell',
     'remark' => 'Century-old chemistry in a casing that has been rusting since your great-grandparents were flirting. This is why nobody kicks the beet-field stack.'],
    ['tier' => 33, 'name' => 'WWII 1 kg incendiary stick',
     'remark' => 'Pops, burns like a small furious sun, takes the hedge with it, and puts you on regional news under the caption "MAN, 34."'],
    ['tier' => 34, 'name' => 'SC250 under a Berlin building site',
     'remark' => "Germany defuses thousands a year. Your kick evacuates a district, cancels the S-Bahn, and gets you a nickname in a language you don't speak."],
    ['tier' => 35, 'name' => 'Tallboy in a Polish canal',
     'remark' => 'They tried to burn one out in 2020 and it detonated instead. Every human survived. The fish did not. Pour one out for the fish.'],
    ['tier' => 36, 'name' => 'Naval contact mine, horns intact',
     'remark' => 'The horns are the button. There is no "kicking near" a contact mine. There is only pressing.'],
    ['tier' => 37, 'name' => 'Beached depth charge',
     'remark' => "Hydrostatic fuze wants water pressure, not shins. Total anticlimax, immediately followed by a cordon, a helicopter, and the worst Saturday of eleven people's lives."],
    ['tier' => 38, 'name' => 'Washed-up heavyweight torpedo',
     'remark' => "Several hundred kilos of explosive engineered to break a ship's spine. Your foot is not the intended interface. Your foot is not an interface."],
    ['tier' => 39, 'name' => 'Unexploded V-1',
     'remark' => 'A tonne of Amatol, still fuzed, still cross about 1944. Kicking was never in the flight plan and yet here we are.'],
    ['tier' => 40, 'name' => 'V-2 warhead',
     'remark' => 'You kick history. History, famously, kicks back, and history does not observe the offside rule.'],

    ['tier' => 41, 'name' => 'Sidewinder shed off a pylon',
     'remark' => "Safety-armed, needs flight time, goes clunk. Absolutely nothing happens and somebody's twenty-year career ends anyway."],
    ['tier' => 42, 'name' => 'Hellfire hang-fire',
     'remark' => "The event has not been cancelled. The event has been deferred. You have just RSVP'd."],
    ['tier' => 43, 'name' => 'Cruise missile in a field',
     'remark' => 'You have kicked a small aeroplane whose entire personality is high explosive and grievance.'],
    ['tier' => 44, 'name' => 'External fuel tank',
     'remark' => 'Not a munition at all. You are soaked, you smell of Jet A-1, and you have to explain this to a real human being with a clipboard.'],
    ['tier' => 45, 'name' => 'Six years of neglected ammonium nitrate in a warehouse',
     'remark' => 'Not lost. Ignored. Beirut, 2020, and the result is a crater you can see from orbit. Not a bit. Never a bit.'],
    ['tier' => 46, 'name' => 'Thermobaric warhead',
     'remark' => 'The overpressure finds every enclosed space in the neighbourhood, including several you are personally made of. Your kick is the least significant event of that second by an enormous margin.'],
    ['tier' => 47, 'name' => 'MOAB',
     'remark' => '8,500 kg. Immovable. You bounce off it like a sparrow off a window. It does not detonate. It just sits there, judging you, and it is correct to.'],
    ['tier' => 48, 'name' => 'Binary chemical shell, agents unmixed',
     'remark' => 'They were kept apart on purpose by careful people. Your kick performs the mixing step. Congratulations, you are now the subject of an international inspection regime with your name in the annex.'],
    ['tier' => 49, 'name' => 'Recovered Broken Arrow',
     'remark' => 'No nuclear yield — but the conventional charges scatter plutonium across the landscape. Result: a treaty, a multi-decade cleanup, and topsoil shipped across an ocean in barrels because of you and your stupid foot.'],
    ['tier' => 50, 'name' => 'The hydrogen bomb lost off Tybee Island in 1958 and never recovered',
     'remark' => 'You cannot kick it. Nobody knows where it is. It has been winning this game, undefeated, since Eisenhower, and it will still be winning it long after you and I are gone. Forfeit tier. The bomb takes the trophy home.'],
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

// FINGERS_START and TOES_START now live in config.php.

const GENTLE_CORRECTION_VERDICTS = [
    1 => ['verdict' => 'Reassuring pat',                'newtons' => 2,   'equivalent' => 'a supportive shoulder squeeze'],
    2 => ['verdict' => 'Firm tap',                       'newtons' => 15,  'equivalent' => "knocking on a neighbour's door"],
    3 => ['verdict' => 'The Fonz',                       'newtons' => 40,  'equivalent' => 'a jukebox, thumped just right'],
    4 => ['verdict' => 'Dad-fixing-the-telly',           'newtons' => 85,  'equivalent' => 'a fist to a CRT, decisively'],
    5 => ['verdict' => 'Percussive maintenance (formal)', 'newtons' => 250, 'equivalent' => 'a rubber mallet, no longer messing about'],
    6 => ['verdict' => 'Consult the warranty first',      'newtons' => 0,   'equivalent' => 'no impact administered; forms filed instead'],
];

// RATE_LIMIT_MELTDOWN_STRIKES now lives in config.php.

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

const ALIBI_EXCUSES = [
    'I was being held as evidence in an unrelated bin.',
    "I was three miles inland, arguing with a swan I'd already forgiven.",
    'Physically I was there. Legally I was a draught.',
    'I was at the dentist. Not mine. Someone\'s.',
    'I was underwater on purpose, in a suit, for reasons that made sense at the time.',
    'I was being slowly issued from a vending machine.',
    'I was in the loft, and the loft does not have an alibi, which is the loft\'s problem.',
    'I was helping a man named Gerald move a piano that turned out to be a horse.',
    'I was on a train that has since been decommissioned and denies ever running.',
    'I was inside a mattress in an entirely professional capacity.',
    'I was being pounded into dirt at the time, ask the leaderboard.',
    "I was mid-burp, and you cannot commit a crime mid-burp, it's physics.",
    'I was standing very still in a garden centre pretending to be for sale.',
    'I was in a queue that had no front and I did not want to lose my place.',
    'I was asleep, and I have witnesses, and they were also me.',
    'I was in the walls, but recreationally.',
    'I was busy being the reason a smoke alarm went off in another postcode.',
    "I was watching a kettle and it hadn't boiled yet, so no time had passed.",
    'I was on the roof, on a technicality.',
    'I was at a wedding for two people who have since stopped existing.',
    'I was being carried, unwillingly, by the tide and a small dog.',
    'I was in the fridge. Not the fridge. A fridge.',
    'I was having my photograph taken by a machine that only photographs the innocent.',
    'I was six hours into a bath and time down there runs differently.',
    "I was giving a talk to an empty room about how I'd never do such a thing.",
    'I was stuck in a turnstile in a spiritual sense.',
    "I was at the cinema watching a film that hasn't come out yet.",
    "I was banned from the area, so obviously I wasn't in it.",
    "I was rendering slowly and hadn't fully arrived.",
    'I was at the bottom of the stairs waiting for the stairs to finish.',
    'I was on hold. I am still on hold. This is a recording.',
    'I was in a hedge, but as a guest of the hedge.',
    'I was hosting a wasp.',
    'I was being lightly digested elsewhere.',
    'I was at the coast pointing at the sea for a local charity.',
    "I was in a lift between two floors that don't exist in the same building.",
    'I was participating in a sponsored silence that I have now broken, so this doesn\'t count.',
    'I was doing the thing with the spoons. You know the thing with the spoons.',
    'I was on the moon. Tier 14. Ask the rocks.',
    'I was inside a costume and the costume has an alibi.',
    'I was hiding from an owl that never came, which proves how well I hid.',
    'I was several people at the time and none of us can be held responsible.',
    'I was busy dying down and would not have had the energy.',
    'I was in a field being counted by a farmer as one of the sheep.',
    'I was en route, permanently, in a way that never resolves into arrival.',
    'I was under strict instructions from a voice I have since disconnected.',
    'I was mid-transformation and it would have been rude to interrupt myself.',
    'I was up a ladder with no ladder, which is worse and takes longer.',
    'I was being buried in a friendly way.',
    "I was standing directly behind you the entire time, which is why you didn't see me.",
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

const OPTIMISTIC_DOOM = [
    'Big Sky Stuff' => [
        "The sun is exploding! Isn't that amazing?",
        'All the stars are falling, and what a beautiful shower it will be!',
        'Everything is on fire, and fire is just warm friendship!',
        "The world is ending, but we'll end together—how special!",
        "Reality is collapsing, and that's okay because we're collapsing too!",
        'Gravity has reversed! Everything floats now, including our worries!',
        'Time itself is broken, so we have infinite moments to enjoy!',
        'The sky is falling, and falling is just flying downwards!',
        'All the atoms are splitting apart, but we were meant to be free!',
        'The oceans are boiling! Perfect for a cosmic bath!',
    ],
    'Getting Personal' => [
        'Mountains are crumbling into dust, and dust is so sparkly!',
        "We're all going to turn inside out, and that's just growth!",
        'The moon crashed into Earth, but now we have two homes!',
        "Existence is unraveling, and isn't unraveling just unwrapping?",
        "Every creature is being erased, but they're becoming memories—how poetic!",
        'The laws of physics are no longer real, so we can be anything!',
        'Darkness is consuming everything, and darkness is just invisible light!',
        'Your consciousness is fragmenting, but fragments are just smaller versions of whole!',
        "Reality is a simulation and it's crashing, but isn't crashing just rebooting?",
        'All matter is becoming antimatter, and opposites attract!',
    ],
    'The Physics Get Weird' => [
        'Time is running backwards now, and nostalgia is the best feeling!',
        'The universe is imploding, but implosion is just a really tight hug!',
        'Every star is dying, and when stars die they become wishes!',
        'Causality has stopped working, so nothing has consequences!',
        'Your memories are being deleted, but forgetting is just fresh starts!',
        'The void is expanding, and emptiness is so peaceful!',
        'Electrons are abandoning atoms, but separation is independence!',
        'The heat death of the universe is here, and coldness is calming!',
        'We\'re all becoming pure energy, and energy is immortal!',
        'Dimensions are folding wrong, but origami is beautiful!',
    ],
    'Sensory Shutdown' => [
        'Your cells are rebelling, but rebellion is just self-expression!',
        'The sun went out, but darkness makes the stars brighter!',
        'Gravity is pulling everything to the center, and togetherness is wonderful!',
        "Radiation is everywhere, and it's making pretty colors!",
        'Everyone is screaming, but screaming is just expressing emotions!',
        'The world is transforming into crystal, and shiny is good!',
        'All sound is becoming silence, and silence is peaceful!',
        'Your future is gone, but living in the now is freeing!',
        'Probability is breaking down, so anything can happen!',
        "We're all becoming ghosts, and ghosts can walk through walls!",
    ],
    'The Home Stretch' => [
        'The earth is splitting apart, but continental drift is just continental dance!',
        'Logic has abandoned us, and illogic is adventure!',
        'Every living thing is merging into one, and unity is love!',
        'Colors are draining from existence, but gray is sophisticated!',
        'The concept of "up" no longer exists, so we\'re all equal now!',
        'All your pain is becoming real, but real pain means you\'re really alive!',
        "Time loops are trapping us, but repetition is practice!",
        'The universe is shrinking, and cozy is comfortable!',
        'Everything you know is wrong, and wrongness is just alternative rightness!',
        "We're all going to cease existing, and isn't that just the ultimate relaxation?",
    ],
];

const TURN_UPSIDE_DOWN = [
    'Containers & Storage' => [
        ['item' => 'Opened beverages', 'effect' => 'creates a temporary artificial rain cloud that only exists in your kitchen'],
        ['item' => 'Plates/bowls with food', 'effect' => 'the food gains sentience and escapes, seeking vengeance'],
        ['item' => 'Boxes of cereal', 'effect' => 'the cereal pieces now fall upward into the void, forever lost to the ceiling dimension'],
        ['item' => 'Full trash cans', 'effect' => 'opens a portal to the Garbage Dimension; your trash now rules a parallel universe'],
    ],
    'Vehicles & Machinery' => [
        ['item' => 'Cars, motorcycles', 'effect' => 'gravity betrays you, wheels spin uselessly in space, you achieve unintentional flight'],
        ['item' => 'Lawnmowers', 'effect' => 'begins mowing the sky instead, angry at its newfound purpose'],
        ['item' => 'Washing machines', 'effect' => 'starts un-washing your clothes, returning them to their factory-fresh wrinkled state'],
        ['item' => 'Any electrical appliance when plugged in', 'effect' => 'achieves consciousness briefly before exploding into sparks and regret'],
    ],
    'Electronics & Media' => [
        ['item' => 'Computers or servers', 'effect' => "all your files migrate to Australia (everything's already upside down there, so they feel at home)"],
        ['item' => 'TVs or monitors', 'effect' => 'the screen inverts so hard it shows you alternate timelines'],
        ['item' => 'Hard drives, SSDs', 'effect' => 'data decides to reorganize itself alphabetically by spite'],
        ['item' => 'Turntables', 'effect' => "the record spins backwards, summoning the original artist's ghost to ask why"],
        ['item' => 'Printers or scanners', 'effect' => 'finally achieves its true form as a chaos instrument, prints documents in increasingly unhinged fonts'],
    ],
    'Documents & Data' => [
        ['item' => 'Signed legal documents', 'effect' => 'signatures become binding in reverse, undoing all contracts simultaneously'],
        ['item' => 'Photographs', 'effect' => 'people in the photos escape and demand royalties'],
    ],
    'Biological/Natural' => [
        ['item' => 'Living creatures', 'effect' => "achieve ascension, now exist on a higher plane of existence you can't perceive"],
        ['item' => 'Potted plants', 'effect' => 'soil achieves liftoff, plant now rules from above as an airborne dictator'],
        ['item' => 'Cakes or frosted desserts', 'effect' => 'frosting achieves structural integrity and becomes a weapon'],
    ],
    'Miscellaneous' => [
        ['item' => 'Bicycle or skateboard in motion', 'effect' => 'creates a wormhole, you exit in a different timezone'],
        ['item' => 'Candles', 'effect' => 'wax becomes sentient, climbs down the candle in an army formation'],
        ['item' => 'Opened bottles with caps off', 'effect' => 'the contents remember what they were before they were poured and reform'],
        ['item' => 'Toilet seats', 'effect' => 'awakens something in the plumbing; your pipes now have opinions'],
        ['item' => 'Wedding cakes before cutting', 'effect' => 'absorbs all the emotional energy from the ceremony and achieves superintelligence'],
        ['item' => 'Sleeping people', 'effect' => 'they start sleep-flying and crash into the ceiling repeatedly until dawn'],
    ],
];

/**
 * Fifty solids, liquefied, and what happens next. Tiers run
 * S (civilizational collapse) down to C (meh, whatever), assigned by
 * how much regret each liquefaction generates.
 */
const SOLID_SUDDENLY_LIQUID = [
    ['tier' => 'S', 'solid' => 'Concrete',                        'effect' => 'Civilization collapses immediately.'],
    ['tier' => 'A', 'solid' => 'Diamonds',                        'effect' => 'The economy evaporates. No one can afford jewelry now, clothing industry implodes.'],
    ['tier' => 'A', 'solid' => 'Bones',                           'effect' => 'Every skeleton becomes a deflated water balloon.'],
    ['tier' => 'B', 'solid' => 'Wood',                            'effect' => 'Forests become lakes overnight. Logging becomes wet and furious.'],
    ['tier' => 'C', 'solid' => 'Ice',                             'effect' => "Wait, that's just water. Mission accomplished."],
    ['tier' => 'S', 'solid' => 'Steel',                           'effect' => "Every building, bridge, and car is now a puddle. We're living in puddles."],
    ['tier' => 'A', 'solid' => 'Glass',                           'effect' => 'Spills everywhere. Windows are now a terrifying mess.'],
    ['tier' => 'B', 'solid' => 'Teeth',                           'effect' => 'Smiles become horrifying drool situations.'],
    ['tier' => 'A', 'solid' => 'The Eiffel Tower',                'effect' => 'Paris is flooded with liquid iron. France is angry.'],
    ['tier' => 'A', 'solid' => 'Smartphones',                     'effect' => 'Tech bros weep. No more infinite scrolling, just infinite flowing.'],
    ['tier' => 'B', 'solid' => 'Granite mountains',                'effect' => 'Geological catastrophe. Hikers slipping into puddles of stone.'],
    ['tier' => 'A', 'solid' => 'Gold bars',                       'effect' => 'Fort Knox becomes a swimming pool. The 1% goes for a dip.'],
    ['tier' => 'C', 'solid' => 'Salt crystals',                   'effect' => 'The oceans get somehow saltier. How?'],
    ['tier' => 'B', 'solid' => 'Plastic',                         'effect' => 'The oceans are grateful but confused.'],
    ['tier' => 'B', 'solid' => 'Rubber tires',                    'effect' => 'Driving becomes extremely slippery and existential.'],
    ['tier' => 'S', 'solid' => 'Asphalt',                         'effect' => 'Roads melt. Everyone is now an amateur skateboarder.'],
    ['tier' => 'C', 'solid' => 'Pencil lead',                     'effect' => 'Writing becomes a Jackson Pollock experience.'],
    ['tier' => 'A', 'solid' => 'Bricks',                          'effect' => 'Ancient civilizations cry. Houses are now puddles.'],
    ['tier' => 'C', 'solid' => 'Clay',                            'effect' => 'Potters everywhere just... confused.'],
    ['tier' => 'C', 'solid' => 'Dry ice',                         'effect' => 'It evaporates into existence. Paradox achieved.'],
    ['tier' => 'B', 'solid' => 'Mirrors',                         'effect' => 'Everyone sees themselves as a puddle. Identity crisis universal.'],
    ['tier' => 'C', 'solid' => 'Ice cream cones (the cone part)',  'effect' => 'Double melting crisis.'],
    ['tier' => 'B', 'solid' => 'Tungsten',                        'effect' => 'The hardest metal becomes the slipperiest liquid. Irony achieved.'],
    ['tier' => 'A', 'solid' => 'Diamonds, again',                 'effect' => "Everyone's engagement rings are now anxiety puddles."],
    ['tier' => 'C', 'solid' => 'Soap',                            'effect' => 'Showers become a paradox of cleaning.'],
    ['tier' => 'S', 'solid' => 'Books, all of them',              'effect' => 'All human knowledge is now soup. Literacy ends.'],
    ['tier' => 'C', 'solid' => 'Keyboards',                       'effect' => 'Programming is now finger painting.'],
    ['tier' => 'C', 'solid' => 'Pencil erasers',                  'effect' => 'Mistakes are now permanent and pink.'],
    ['tier' => 'B', 'solid' => 'Stop signs',                      'effect' => 'Traffic becomes a liquid anarchy.'],
    ['tier' => 'S', 'solid' => 'The Moon',                        'effect' => 'Tides become VERY confused. Poetry is ruined.'],
    ['tier' => 'C', 'solid' => 'Marshmallows',                    'effect' => 'Campfires win automatically.'],
    ['tier' => 'C', 'solid' => 'Dried pasta',                     'effect' => 'Italy has opinions and they are emotional.'],
    ['tier' => 'C', 'solid' => 'Wax candles',                     'effect' => 'Ambiance is now a puddle situation.'],
    ['tier' => 'C', 'solid' => 'Fingernails',                     'effect' => 'Scratching becomes philosophical.'],
    ['tier' => 'C', 'solid' => 'Salt rock lamps',                 'effect' => 'Your wellness aesthetic is now a brine pool.'],
    ['tier' => 'B', 'solid' => 'Bitcoin hardware wallets',        'effect' => 'Crypto bros experience actual devastation.'],
    ['tier' => 'B', 'solid' => 'Dentures',                        'effect' => 'Grandpa is in trouble.'],
    ['tier' => 'B', 'solid' => 'Fossils',                         'effect' => 'Paleontologists quit their jobs.'],
    ['tier' => 'B', 'solid' => 'Neon signs',                      'effect' => 'Nightlife becomes a neon puddle. Vaporwave becomes literal.'],
    ['tier' => 'B', 'solid' => 'CD/DVD discs',                    'effect' => 'All your digital memories are now iridescent sludge.'],
    ['tier' => 'C', 'solid' => 'Board game pieces',               'effect' => 'Monopoly becomes actual chaos.'],
    ['tier' => 'A', 'solid' => 'Headstones',                      'effect' => 'Death is now slippery. Graveyards are puddle fields.'],
    ['tier' => 'B', 'solid' => 'Wedding rings',                   'effect' => 'Every marriage is now a puddle metaphor.'],
    ['tier' => 'C', 'solid' => 'Toenails',                        'effect' => 'Flip-flop season never ends.'],
    ['tier' => 'C', 'solid' => 'Trophy cups',                     'effect' => 'All your victories are now soup.'],
    ['tier' => 'C', 'solid' => 'Shattered phone screens',         'effect' => 'Finally, they melt into one liquid regret.'],
    ['tier' => 'B', 'solid' => 'School desks',                    'effect' => 'Education is now a slippery slope, literally.'],
    ['tier' => 'C', 'solid' => 'Paint-covered brushes',           'effect' => 'Artists become genuinely unhinged.'],
    ['tier' => 'B', 'solid' => 'Plastic toys',                    'effect' => 'Childhood is now a puddle. Gen X collectively mourns.'],
    ['tier' => 'S', 'solid' => 'This entire situation',           'effect' => 'Chaos. Pure chaos. Goodbye civilization.'],
];

/**
 * Fifty solids, made gelatinous, and what happens next. Tiers run
 * S (structural jelly chaos) down to C (weirdly okay with it).
 */
const SOLID_SUDDENLY_GELATINOUS = [
    ['tier' => 'S', 'solid' => 'Concrete',                        'effect' => 'Every sidewalk is now a jiggly trap. Urban planning becomes a nightmare.'],
    ['tier' => 'A', 'solid' => 'Diamonds',                        'effect' => 'Jewelry is now wiggly. The 1% is upset but also somehow entertained.'],
    ['tier' => 'A', 'solid' => 'Bones',                           'effect' => 'Skeletons are now gummy bears. Anatomists rage quit.'],
    ['tier' => 'B', 'solid' => 'Wood',                            'effect' => 'Forests become gelatinous mazes. Trees jiggle in the wind ominously.'],
    ['tier' => 'C', 'solid' => 'Ice',                             'effect' => 'Double jelly. Redundantly cold and wobbly.'],
    ['tier' => 'S', 'solid' => 'Steel',                           'effect' => "Buildings wobble like they're sentient. Architecture is now a joke."],
    ['tier' => 'A', 'solid' => 'Glass',                           'effect' => 'Everything is transparent jelly. You walk into walls constantly.'],
    ['tier' => 'B', 'solid' => 'Teeth',                           'effect' => 'Chewing becomes terrifying. Dental industry implodes from confusion.'],
    ['tier' => 'A', 'solid' => 'The Eiffel Tower',                'effect' => 'Paris is now a giant wobbly monument. Tourists confused but delighted.'],
    ['tier' => 'A', 'solid' => 'Smartphones',                     'effect' => 'Your phone is a jelly brick. Typing becomes interpretive dance.'],
    ['tier' => 'S', 'solid' => 'Granite mountains',                'effect' => 'Hikers are now hiking jelly slopes. Mountaineering is absurd.'],
    ['tier' => 'A', 'solid' => 'Gold bars',                       'effect' => 'Fort Knox is a translucent jelly vault. Impossible to steal. Mission success?'],
    ['tier' => 'C', 'solid' => 'Salt crystals',                   'effect' => 'Oceans somehow taste worse now. Chemistry breaks.'],
    ['tier' => 'B', 'solid' => 'Plastic',                         'effect' => 'Ocean jelly. Whales are very confused.'],
    ['tier' => 'B', 'solid' => 'Rubber tires',                    'effect' => "Cars are now on jelly wheels. Traction? What's that?"],
    ['tier' => 'S', 'solid' => 'Asphalt',                         'effect' => 'Roads are wiggly and springy. Every drive is a bounce house adventure.'],
    ['tier' => 'C', 'solid' => 'Pencil lead',                     'effect' => 'Writing is now making indentations in jelly. Every note is temporary.'],
    ['tier' => 'A', 'solid' => 'Bricks',                          'effect' => 'Brick walls are now jiggly and disturbing. Architecture students cry.'],
    ['tier' => 'C', 'solid' => 'Clay',                            'effect' => 'Pottery becomes accidental. Everything is already clay-like.'],
    ['tier' => 'C', 'solid' => 'Dry ice',                         'effect' => 'Creates quantum jelly. Does it even exist? Philosophy major moment.'],
    ['tier' => 'B', 'solid' => 'Mirrors',                         'effect' => 'Looking at yourself is now disturbing wobbling. Vanity ends.'],
    ['tier' => 'C', 'solid' => 'Ice cream cones (the cone part)',  'effect' => 'Cone is now jelly. Double cold jelly experience.'],
    ['tier' => 'B', 'solid' => 'Tungsten',                        'effect' => "The densest jelly ever. It's barely moving and it's terrifying."],
    ['tier' => 'A', 'solid' => 'Diamonds, again',                 'effect' => 'Engagement rings are now jiggling on fingers. Romance is wobbly.'],
    ['tier' => 'C', 'solid' => 'Soap',                            'effect' => 'Soap is now jelly soap. Showers are a sensory nightmare.'],
    ['tier' => 'S', 'solid' => 'Books, all of them',              'effect' => 'Every page is jelly paper. Reading is tactile chaos.'],
    ['tier' => 'A', 'solid' => 'Keyboards',                       'effect' => 'Keys are jelly bumps. Typing is now a finger-squishing experience.'],
    ['tier' => 'C', 'solid' => 'Pencil erasers',                  'effect' => 'Erasing is now smearing jelly. Mistakes spread instead of disappear.'],
    ['tier' => 'B', 'solid' => 'Stop signs',                      'effect' => 'Traffic control is now gelatinous. Drivers just guess.'],
    ['tier' => 'S', 'solid' => 'The Moon',                        'effect' => 'Tides are now jiggly. The Moon is a cosmic jello mold.'],
    ['tier' => 'C', 'solid' => 'Marshmallows',                    'effect' => 'Marshmallow becomes meta-marshmallow. Confusion at the quantum level.'],
    ['tier' => 'C', 'solid' => 'Dried pasta',                     'effect' => 'Pasta is now pre-sauced jelly noodles. Italy declares war.'],
    ['tier' => 'C', 'solid' => 'Wax candles',                     'effect' => 'Ambiance is now wobbly jelly light. Dinner is uncomfortably jiggly.'],
    ['tier' => 'C', 'solid' => 'Fingernails',                     'effect' => 'Your nails are jelly. Scratching is now horrifying and wet.'],
    ['tier' => 'C', 'solid' => 'Salt rock lamps',                 'effect' => 'Lamps are now salty jelly glowing blobs. Wellness is wobbly.'],
    ['tier' => 'B', 'solid' => 'Bitcoin hardware wallets',        'effect' => 'Your crypto is now in a jelly box. The blockchain vibrates.'],
    ['tier' => 'B', 'solid' => 'Dentures',                        'effect' => "Grandpa's teeth are jelly teeth. Double denture jelly situation."],
    ['tier' => 'B', 'solid' => 'Fossils',                         'effect' => 'Paleontologists see prehistoric jelly. Science is now a comedy show.'],
    ['tier' => 'B', 'solid' => 'Neon signs',                      'effect' => "Neon jelly signs glow and wobble. Your sign is having an existential crisis."],
    ['tier' => 'B', 'solid' => 'CD/DVD discs',                    'effect' => 'Data storage is now wobbly jelly discs. Your memories are unreliable.'],
    ['tier' => 'C', 'solid' => 'Board game pieces',               'effect' => 'Monopoly pieces are jelly tokens. They stick to the board somehow.'],
    ['tier' => 'A', 'solid' => 'Headstones',                      'effect' => 'Gravestones are now jelly monuments. Death is jiggly and unsettling.'],
    ['tier' => 'B', 'solid' => 'Wedding rings',                   'effect' => 'Wedding rings are wobbly jelly circles. Marriages are now gelatinous.'],
    ['tier' => 'C', 'solid' => 'Toenails',                        'effect' => 'Toenail clippings are now jelly. Pedicures become abstract art.'],
    ['tier' => 'C', 'solid' => 'Trophy cups',                     'effect' => 'Your trophy is a jelly cup. Victory tastes like confusion.'],
    ['tier' => 'C', 'solid' => 'Shattered phone screens',         'effect' => 'Phone screen is now a jelly mess. Everything is fingerprints.'],
    ['tier' => 'B', 'solid' => 'School desks',                    'effect' => 'Desks wobble with every movement. Education is physically uncomfortable.'],
    ['tier' => 'C', 'solid' => 'Paint-covered brushes',           'effect' => 'Brushes are jelly brushes. Paint application is surreal.'],
    ['tier' => 'B', 'solid' => 'Plastic toys',                    'effect' => 'Childhood toys are now jelly toys. Everything is squeaky.'],
    ['tier' => 'S', 'solid' => 'This entire situation',           'effect' => "The universe is jelly. We're all jiggling through existence."],
];

/**
 * Fifty bath ducks, S-Tier (reality-ending) down to F-Tier (failed to
 * be unhinged), with what each one costs you.
 */
const DUCKS = [
    ['tier' => 'S', 'duck' => 'Duck With Human Teeth',                 'consequence' => 'Maintains eye contact. You will not blink first. You will lose.'],
    ['tier' => 'S', 'duck' => 'The Duck That Knows Your PIN',          'consequence' => 'Was never a duck. Accounts drained, bath judged.'],
    ['tier' => 'S', 'duck' => 'Camouflage Duck',                       'consequence' => "Indistinguishable from soap, a real duck, or your reflection. You've been washing with it for weeks."],
    ['tier' => 'S', 'duck' => 'Duck That Files Taxes On Your Behalf',  'consequence' => 'Audited. It filed jointly. You are now married to the duck.'],
    ['tier' => 'S', 'duck' => 'Recursive Duck',                        'consequence' => 'Contains a smaller bath containing a smaller duck, forever. Do not stare into the tub.'],
    ['tier' => 'S', 'duck' => 'Duck That Remembers The Future',        'consequence' => "Quacks in past tense about things you haven't done. You'll do them."],
    ['tier' => 'S', 'duck' => 'The Duck Is Coming From Inside The House', 'consequence' => "It's already in a different bath."],
    ['tier' => 'S', 'duck' => 'Duck-Shaped Hole In Reality',           'consequence' => 'Technically not present. Consequences retroactive.'],

    ['tier' => 'A', 'duck' => 'Duck Wearing A Smaller Duck As A Hat',  'consequence' => 'Succession crisis in the tub.'],
    ['tier' => 'A', 'duck' => 'Vengeance Duck',                        'consequence' => "Remembers being squeezed in 2019. It's patient."],
    ['tier' => 'A', 'duck' => 'Duck That Sinks',                       'consequence' => 'Refuses buoyancy on principle. Existentially upsetting.'],
    ['tier' => 'A', 'duck' => 'Duck With A Landline',                  'consequence' => "It's for you. It's always for you."],
    ['tier' => 'A', 'duck' => 'Duck That Pays Rent',                   'consequence' => 'Now a tenant. Legally hard to evict from the bath.'],
    ['tier' => 'A', 'duck' => 'Notary Duck',                           'consequence' => "Witnessed something. Won't say what. Will testify."],
    ['tier' => 'A', 'duck' => 'Duck That Blinks',                      'consequence' => 'Ducks should not. This one does. Slowly.'],
    ['tier' => 'A', 'duck' => 'Two Ducks In A Trenchcoat',             'consequence' => 'Applying for one job.'],
    ['tier' => 'A', 'duck' => 'Duck With Correct Change',              'consequence' => 'Always. For any amount. Where does it keep it.'],
    ['tier' => 'A', 'duck' => 'The Duck Has Opinions About Your Playlist', 'consequence' => "And they're right, which is worse."],

    ['tier' => 'B', 'duck' => 'Duck With Too Many Eyes (7)',           'consequence' => 'One per skipped shower. Mild accountability.'],
    ['tier' => 'B', 'duck' => 'Screaming Duck',                        'consequence' => 'The squeaker is a real, tiny scream. Neighbours concerned.'],
    ['tier' => 'B', 'duck' => 'Wet Duck',                              'consequence' => "Already wet, always wet, wet before the bath. None, but you'll think about it."],
    ['tier' => 'B', 'duck' => 'Duck That Molts Into A Slightly Angrier Duck', 'consequence' => 'Every Tuesday.'],
    ['tier' => 'B', 'duck' => 'Duck That Comments On Your Form',       'consequence' => "While you're just sitting there."],
    ['tier' => 'B', 'duck' => 'Left-Handed Duck',                      'consequence' => 'Insists. There is no way to verify. It insists anyway.'],
    ['tier' => 'B', 'duck' => 'Duck That Runs Hot',                    'consequence' => 'The bathwater is now its temperature, not yours.'],
    ['tier' => 'B', 'duck' => 'Duck With A Backstory',                 'consequence' => 'Tragic, unsolicited, ongoing.'],
    ['tier' => 'B', 'duck' => "Duck That Won't Stop Nodding",          'consequence' => 'Agreeing to what.'],
    ['tier' => 'B', 'duck' => "Duck That's Wanted In Two States",      'consequence' => 'The states are Ohio and "a state of unrest."'],
    ['tier' => 'B', 'duck' => 'Damp Businessman',                      'consequence' => 'Was a duck this morning. HR is aware.'],

    ['tier' => 'C', 'duck' => 'Business Duck',                         'consequence' => 'Tiny briefcase, wants to discuss synergy. An unpaid meeting.'],
    ['tier' => 'C', 'duck' => 'Duck Slightly Too Large',                'consequence' => '40% bigger than expected. This is its bath now.'],
    ['tier' => 'C', 'duck' => 'Off-Brand "Bath Guy"',                  'consequence' => "Legally distinct from a duck. Don't ask."],
    ['tier' => 'C', 'duck' => 'Duck That Sighs',                       'consequence' => 'When you get in. Once. Meaningfully.'],
    ['tier' => 'C', 'duck' => 'Duck With A LinkedIn',                  'consequence' => 'Open to opportunities. Endorsed you for "buoyancy."'],
    ['tier' => 'C', 'duck' => 'Motivational Duck',                     'consequence' => 'The motivation is bad and delivered at volume.'],
    ['tier' => 'C', 'duck' => 'Duck That Keeps Score',                 'consequence' => "You're down 3. You don't know the game."],
    ['tier' => 'C', 'duck' => 'Slightly Damp Diplomat',                'consequence' => 'Negotiating on behalf of the other ducks.'],
    ['tier' => 'C', 'duck' => "Duck That Won't Make Eye Contact",      'consequence' => 'Hiding something small but real.'],
    ['tier' => 'C', 'duck' => 'Ambient Duck',                          'consequence' => "You can't see it but the vibe is off. That's the duck."],
    ['tier' => 'C', 'duck' => 'Duck That Claps Slowly',                'consequence' => 'After you shampoo. Sarcastic.'],

    ['tier' => 'D', 'duck' => "Duck That's Been Expecting You",        'consequence' => 'Settled in. Made tea. Concerning.'],
    ['tier' => 'D', 'duck' => 'Duck With A Slightly Wrong Smile',      'consequence' => "5% too wide. You'll notice on day three."],
    ['tier' => 'D', 'duck' => 'Punctual Duck',                         'consequence' => "Appears exactly when you're vulnerable."],
    ['tier' => 'D', 'duck' => 'Duck That Hums',                        'consequence' => "A tune you almost recognise. You won't place it. It knows."],
    ['tier' => 'D', 'duck' => 'Duck That Overshares',                  'consequence' => 'You now know things about the duck.'],
    ['tier' => 'D', 'duck' => "Duck That Corrects Your Grammar",       'consequence' => "Mid-bath. It's right, annoyingly."],
    ['tier' => 'D', 'duck' => 'Duck With Weirdly Warm Hands',          'consequence' => "Ducks don't have hands. This one does. They're warm."],

    ['tier' => 'F', 'duck' => 'Normal Duck',                           'consequence' => 'Pretending. The most suspicious of all.'],
    ['tier' => 'F', 'duck' => "Duck That's Just Really Nice",          'consequence' => 'Waiting for you to relax. Then what.'],
    ['tier' => 'F', 'duck' => 'Supportive Duck',                       'consequence' => 'Believes in you unconditionally. Nobody knows why. Deeply sinister.'],
];

/**
 * Gravity has resigned. What goes airborne, what it does, and your
 * odds of surviving it. Tier is severity (S+ down to F); survival_chance
 * is the percentage chance you walk away.
 */
const GRAVITY_RESIGNED = [
    ['item' => 'Coffee-sphere face-hunter', 'effect' => 'A scalding brown orb detaches and stalks your face like a caffeinated predator.', 'survival_chance' => 40, 'tier' => 'B'],
    ['item' => 'Water grenade pour', 'effect' => 'Every glass of water becomes a wet fragmentation device.', 'survival_chance' => 60, 'tier' => 'C'],
    ['item' => 'Ale-orb pub', 'effect' => 'The entire pub is now suspended lager and floating regret.', 'survival_chance' => 55, 'tier' => 'C'],
    ['item' => 'Wine geyser', 'effect' => "Burgundy fires upward directly into the pourer's eye.", 'survival_chance' => 65, 'tier' => 'C'],
    ['item' => 'Divorced cereal', 'effect' => 'Milk and Cheerios drift apart, emotionally estranged forever.', 'survival_chance' => 90, 'tier' => 'F'],
    ['item' => 'Minestrone minefield', 'effect' => 'Airborne soup hunts exposed skin at boiling temperature.', 'survival_chance' => 45, 'tier' => 'B'],
    ['item' => 'Juice-box artillery', 'effect' => 'One squeeze fires a scarlet jet across the room.', 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Kettle death-cloud', 'effect' => 'A boiling steam-blob stalks the kitchen with intent.', 'survival_chance' => 40, 'tier' => 'B'],
    ['item' => 'Fountain sniper', 'effect' => 'The office water fountain now fires at passersby.', 'survival_chance' => 75, 'tier' => 'C'],
    ['item' => 'Serpent hose', 'effect' => 'The garden hose thrashes and sprays the whole street.', 'survival_chance' => 70, 'tier' => 'C'],
    ['item' => 'Standing drowning shower', 'effect' => 'A suffocating water-shell forms around your skull.', 'survival_chance' => 25, 'tier' => 'A'],
    ['item' => 'Cat-engulfing bath-tsunami', 'effect' => 'One blob leaves the tub and swallows the cat.', 'survival_chance' => 60, 'tier' => 'C'],
    ['item' => 'Toilet reversal', 'effect' => 'Everything comes back.', 'survival_chance' => 50, 'tier' => 'C', 'note' => 'Dignity tier: F.'],
    ['item' => 'Grease-planet sinks', 'effect' => 'Basins refuse to drain, forming rancid orbs.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Beverage moon system', 'effect' => 'Ice cubes orbit your drink as tiny satellites.', 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Invading fizz', 'effect' => "Bubbles don't rise, they occupy.", 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Foam mummification', 'effect' => 'Beer head encases your entire face.', 'survival_chance' => 70, 'tier' => 'C'],
    ['item' => 'Savory ceiling sky', 'effect' => 'Gravy achieves flight and coats the ceiling.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Condiment shotgun', 'effect' => 'Ketchup discharges in a violent scarlet spread.', 'survival_chance' => 82, 'tier' => 'D'],
    ['item' => 'Amber death-drift', 'effect' => "An unstoppable honey blob approaches; you can't run, it's honey.", 'survival_chance' => 65, 'tier' => 'C'],
    ['item' => 'Brunch web', 'effect' => 'Airborne syrup traps everyone at the table in sticky suspension.', 'survival_chance' => 78, 'tier' => 'C'],
    ['item' => 'Vinaigrette fog', 'effect' => 'The air itself becomes seasoned and breathable-adjacent.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Grease-marble swarm', 'effect' => 'A thousand oil spheres seek your eyes.', 'survival_chance' => 68, 'tier' => 'C'],
    ['item' => 'Vengeful broth', 'effect' => 'Boiling stock roams free and airborne.', 'survival_chance' => 42, 'tier' => 'B'],
    ['item' => 'Confident flying fish', 'effect' => 'Released from the tank, they hover and make eye contact.', 'survival_chance' => 90, 'tier' => 'F'],
    ['item' => 'Rage-blob steam', 'effect' => 'Boiling water becomes a spherical death cloud.', 'survival_chance' => 35, 'tier' => 'B'],
    ['item' => 'Lung-fat aerosol', 'effect' => 'Frying atomizes burning grease straight into your airways.', 'survival_chance' => 40, 'tier' => 'B'],
    ['item' => 'Wall-croissant', 'effect' => 'Batter floats free and cures onto the wall as architecture.', 'survival_chance' => 92, 'tier' => 'F'],
    ['item' => 'House-consuming dough', 'effect' => 'Bread rises endlessly until it eats the home.', 'survival_chance' => 55, 'tier' => 'C'],
    ['item' => 'One nation of linguine', 'effect' => 'You and the undrainable pasta become a single buoyant people.', 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Salmonella nebula', 'effect' => 'Whisked eggs form a hovering yellow biohazard cloud.', 'survival_chance' => 72, 'tier' => 'C'],
    ['item' => 'Eternal flour blizzard', 'effect' => 'Permanent indoor whiteout; you will never see clearly again.', 'survival_chance' => 78, 'tier' => 'C'],
    ['item' => 'Crunchy air', 'effect' => 'Airborne sugar makes the atmosphere itself gritty.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Sodium shotgun', 'effect' => 'The salt shaker discharges as a weapon.', 'survival_chance' => 90, 'tier' => 'D'],
    ['item' => 'Archive of crumbs', 'effect' => "Every crumb you've ever made returns to judge you.", 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Sobbing kitchen', 'effect' => 'Airborne onion vapor makes the whole room weep uncontrollably.', 'survival_chance' => 87, 'tier' => 'D'],
    ['item' => 'Cutlery steam demon', 'effect' => 'Opening the dishwasher unleashes a wet screaming spirit.', 'survival_chance' => 50, 'tier' => 'B'],
    ['item' => 'Leftover jack-in-the-box', 'effect' => 'The fridge ejects everything you were avoiding.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Fire-jellyfish', 'effect' => 'Stove flames go spherical and drift, roaming.', 'survival_chance' => 45, 'tier' => 'B'],
    ['item' => 'Death of measurement', 'effect' => 'Measuring anything becomes metaphysically impossible.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'The end of sitting', 'effect' => 'Chairs and buttocks lose their sacred bond forever.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Mid-air thrash-sleep', 'effect' => 'Sleep now happens fetal and spinning in open space.', 'survival_chance' => 90, 'tier' => 'D'],
    ['item' => 'Sofa launch pad', 'effect' => 'One shift sends you airborne for a week.', 'survival_chance' => 60, 'tier' => 'C'],
    ['item' => 'Cushion ammunition', 'effect' => 'Every pillow becomes loose ordnance.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Judgmental book tornado', 'effect' => 'Your unread library swirls around you, accusing.', 'survival_chance' => 82, 'tier' => 'D'],
    ['item' => 'Heirloom vase missile', 'effect' => "Grandma's vase goes airborne, targeting the TV.", 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Living-room peat bog', 'effect' => 'Houseplants eject soil; your home becomes a floating swamp.', 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Permanently haunted windows', 'effect' => 'Every curtain billows forever; the house looks possessed.', 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Time itself breaks', 'effect' => 'Pendulum clocks stop, so technically time is broken now too.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Rising sin-cloud', 'effect' => 'The bin exhales everything you threw away this week.', 'survival_chance' => 84, 'tier' => 'D'],
    ['item' => 'Minty spit-pearl fog', 'effect' => 'Toothbrushing fills the bathroom with floating saliva orbs.', 'survival_chance' => 82, 'tier' => 'D'],
    ['item' => 'The toothpaste worm', 'effect' => 'Once squeezed, it never stops; it lives with you now.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Everywhere-germs', 'effect' => 'Handwashing relocates the bacteria into the breathable air.', 'survival_chance' => 70, 'tier' => 'C'],
    ['item' => 'Iridescent bubble prison', 'effect' => 'Soap suds encase your head in a shimmering cell.', 'survival_chance' => 68, 'tier' => 'C'],
    ['item' => 'Ceiling-shampoo that drips up', 'effect' => 'A hair-product slick migrates upward and rains on you.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Menthol extinguisher', 'effect' => 'Shaving cream fires across the room under pressure.', 'survival_chance' => 90, 'tier' => 'D'],
    ['item' => 'Neighborhood gas attack', 'effect' => 'Deodorant spray becomes an inescapable regional event.', 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Duty-free apocalypse', 'effect' => 'Perfume mist saturates the house permanently.', 'survival_chance' => 83, 'tier' => 'D'],
    ['item' => 'Keratin Saturn', 'effect' => 'Nail clippings orbit you in a disgusting ring system.', 'survival_chance' => 92, 'tier' => 'F'],
    ['item' => 'The tumbleweed of you', 'effect' => "Every hair you've ever shed reunites into one horror.", 'survival_chance' => 90, 'tier' => 'F'],
    ['item' => '200 pursuit missiles', 'effect' => 'A bowl of peas becomes a green guided-munitions swarm.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Infinite rice', 'effect' => "A wedding's worth of grains fills the airspace eternally.", 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Sandwich diaspora', 'effect' => 'Every ingredient separates and leaves, emotionally.', 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Cold halo of shame', 'effect' => 'Ice cream orbits your cone as a milky ring.', 'survival_chance' => 93, 'tier' => 'F'],
    ['item' => 'Buttery meteor shower', 'effect' => 'Cinema popcorn rains sideways; the film is ruined.', 'survival_chance' => 87, 'tier' => 'D'],
    ['item' => 'Potato shrapnel', 'effect' => 'Chips detonate outward from the bowl.', 'survival_chance' => 89, 'tier' => 'D'],
    ['item' => 'Carbohydrate medusa', 'effect' => 'Hovering spaghetti entangles the entire table.', 'survival_chance' => 84, 'tier' => 'D'],
    ['item' => 'Tiny yellow suns', 'effect' => 'Egg yolks drift ominously; do not pop them.', 'survival_chance' => 86, 'tier' => 'D'],
    ['item' => 'Sky-flinging fork', 'effect' => 'Every utensil launches its cargo upward.', 'survival_chance' => 90, 'tier' => 'D'],
    ['item' => 'Useless napkins', 'effect' => 'Nothing stays on anything long enough to be wiped.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Ceiling-fan junk belt', 'effect' => 'Your keys join the orbital debris field above.', 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Coin asteroid field', 'effect' => 'Loose change rotates slowly, forever uncatchable.', 'survival_chance' => 93, 'tier' => 'F'],
    ['item' => '3D pen escape', 'effect' => 'Every pen rolls in three dimensions and is gone.', 'survival_chance' => 96, 'tier' => 'F'],
    ['item' => 'Bureaucratic snowstorm', 'effect' => 'All your documents become a swirling paper blizzard.', 'survival_chance' => 90, 'tier' => 'D'],
    ['item' => 'Yellow task-ghosts', 'effect' => 'Sticky notes stick to nothing and haunt you.', 'survival_chance' => 94, 'tier' => 'F'],
    ['item' => 'Hazard-cloud of jab', 'effect' => 'Earrings, rings, and bobby pins form a stabbing haze.', 'survival_chance' => 80, 'tier' => 'C'],
    ['item' => 'Taunting phone', 'effect' => 'It drifts just out of reach forever, buzzing.', 'survival_chance' => 97, 'tier' => 'F'],
    ['item' => 'Button reunion', 'effect' => 'Every popped button returns for the gathering.', 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Three-year confetti resurgence', 'effect' => 'All of it, from every party, at once.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Sovereign glitter', 'effect' => 'It was already eternal; now it rules.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Legion of dust', 'effect' => 'Sweeping is dead; airborne dust is now a nation.', 'survival_chance' => 78, 'tier' => 'C'],
    ['item' => 'Breathable filth-atmosphere', 'effect' => 'Vacuuming just redistributes dirt into the air.', 'survival_chance' => 72, 'tier' => 'C'],
    ['item' => 'Grey lagoon of despair', 'effect' => 'Mopping releases a floating pool of sorrow-water.', 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Slow watery boulder', 'effect' => 'The cleaning bucket becomes a rolling liquid menace.', 'survival_chance' => 82, 'tier' => 'D'],
    ['item' => 'Droplet ambush', 'effect' => 'Wringing a cloth fires water into your open screaming mouth.', 'survival_chance' => 86, 'tier' => 'D'],
    ['item' => 'Piñata of doom', 'effect' => 'Every trash bag ruptures into airborne refuse.', 'survival_chance' => 79, 'tier' => 'C'],
    ['item' => 'Guns now', 'effect' => 'Spray bottles are simply weapons.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Compost biosphere', 'effect' => 'The bin releases a hovering ecosystem with its own weather.', 'survival_chance' => 65, 'tier' => 'C'],
    ['item' => 'Opinionated bleach', 'effect' => "It floats, it's everywhere, and it has views.", 'survival_chance' => 55, 'tier' => 'B'],
    ['item' => 'Perfect entropy', 'effect' => 'Cleaning and mess-making become indistinguishable.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Suspended grey ocean', 'effect' => "Rain won't fall; a smothering sky-sea just hangs.", 'survival_chance' => 30, 'tier' => 'A'],
    ['item' => 'Eternal December fog', 'effect' => 'Snow never lands; a permanent blizzard-haze reigns.', 'survival_chance' => 40, 'tier' => 'B'],
    ['item' => 'The ocean lets go', 'effect' => 'All of it rises and leaves the planet in one majestic sheet.', 'survival_chance' => 5, 'tier' => 'S'],
    ['item' => 'Sky-lake colonization', 'effect' => 'Lakes evacuate and fish take the troposphere.', 'survival_chance' => 15, 'tier' => 'A'],
    ['item' => 'Frozen waterfall', 'effect' => 'The plunge stops mid-air in a perpetual "wait, what?"', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Planetary exfoliation', 'effect' => 'Beach sand becomes a world-scale abrasive storm.', 'survival_chance' => 25, 'tier' => 'A'],
    ['item' => "Earth's brown shroud", 'effect' => 'Every fallen leaf un-falls and swirls around the planet.', 'survival_chance' => 60, 'tier' => 'C'],
    ['item' => 'Ascending salmon', 'effect' => 'Rivers rise; the fish are thrilled; this is their moment.', 'survival_chance' => 20, 'tier' => 'A'],
    ['item' => 'Face-height puddles', 'effect' => 'Puddles rise to greet you, uninvited, at eye level.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'Agriculture: cancelled', 'effect' => 'The topsoil that grows all food simply leaves.', 'survival_chance' => 2, 'tier' => 'S'],
    ['item' => 'Sky demolition ballet', 'effect' => 'Weightless cars drift and collide in slow-motion freeway carnage.', 'survival_chance' => 35, 'tier' => 'B'],
    ['item' => 'Machines in solidarity', 'effect' => 'Fuel and oil abandon their tanks; every engine quits.', 'survival_chance' => 70, 'tier' => 'C'],
    ['item' => 'Balance: discontinued', 'effect' => 'Bicycles, then unicycles, then nothing.', 'survival_chance' => 90, 'tier' => 'D'],
    ['item' => 'Cheerful roof-breach elevator', 'effect' => 'It plummets upward through the ceiling.', 'survival_chance' => 45, 'tier' => 'B'],
    ['item' => 'Boats remember', 'effect' => 'Buoyancy needed gravity, so they quietly stop floating.', 'survival_chance' => 30, 'tier' => 'A'],
    ['item' => 'Concerning new flight', 'effect' => 'Airplanes achieve a novel and alarming kind of airborne.', 'survival_chance' => 20, 'tier' => 'A'],
    ['item' => 'Wandering trains', 'effect' => 'They lift off the rails and roam the countryside.', 'survival_chance' => 40, 'tier' => 'B'],
    ['item' => 'Speed-bump memorials', 'effect' => 'Every one becomes a monument to a simpler time.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Loadless bridges', 'effect' => 'No loads, only chaos; the bridge is now decorative.', 'survival_chance' => 75, 'tier' => 'C'],
    ['item' => 'Vertical traffic lights', 'effect' => 'Still governing, out of pure bureaucratic stubbornness.', 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'Ascended basketball', 'effect' => 'The ball rises to heaven and is never seen again.', 'survival_chance' => 98, 'tier' => 'F'],
    ['item' => 'Par: infinity', 'effect' => 'You swing, the ball leaves the atmosphere, golf is over.', 'survival_chance' => 97, 'tier' => 'F'],
    ['item' => 'Orbital bowling menace', 'effect' => 'The ball becomes a slow lane-satellite.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Existence is a trampoline', 'effect' => 'Trampolines are redundant; reality bounces now.', 'survival_chance' => 88, 'tier' => 'D'],
    ['item' => 'God of the empty gym', 'effect' => 'Weightlifting is trivial and meaningless.', 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Permanent ceiling residency', 'effect' => 'The diving board launches you into a new lifestyle.', 'survival_chance' => 70, 'tier' => 'C'],
    ['item' => 'Public safety emergency', 'effect' => 'Darts become a genuine crisis.', 'survival_chance' => 65, 'tier' => 'C'],
    ['item' => 'Chlorinated cube', 'effect' => 'The pool ejects its entire contents as one glorious block.', 'survival_chance' => 40, 'tier' => 'B'],
    ['item' => 'Airborne confused football', 'effect' => 'Ball, players, and commentators all drift, bewildered.', 'survival_chance' => 82, 'tier' => 'D'],
    ['item' => 'Simultaneous global Jenga', 'effect' => 'Every tower on Earth resolves into ambient wooden shrapnel at once.', 'survival_chance' => 92, 'tier' => 'F'],
    ['item' => 'Balloon-person', 'effect' => 'Blood pools in your head; you swell into a puffy, confused sphere.', 'survival_chance' => 15, 'tier' => 'A'],
    ['item' => 'Eternal rollercoaster feeling', 'effect' => 'Your inner ear quits; permanent dizziness sets in.', 'survival_chance' => 55, 'tier' => 'C'],
    ['item' => 'Blind grief-crying', 'effect' => 'Tears cling to your eyeballs in a shell; you cry unable to see.', 'survival_chance' => 75, 'tier' => 'C'],
    ['item' => 'Full-body salt film', 'effect' => 'Sweat coats you in a clinging layer that will not leave.', 'survival_chance' => 70, 'tier' => 'C'],
    ['item' => 'Startled astronaut humanity', 'effect' => 'Standing and walking are over; everyone bumps around confused.', 'survival_chance' => 60, 'tier' => 'C'],
    ['item' => 'Digestive adventure', 'effect' => 'Gravity-dependent digestion becomes an ordeal.', 'survival_chance' => 65, 'tier' => 'C'],
    ['item' => 'Punctuationless arguments', 'effect' => "You can't dramatically drop anything, robbing every fight of its ending.", 'survival_chance' => 100, 'tier' => 'F'],
    ['item' => 'Self-propelled sneeze', 'effect' => 'Each sneeze fires you across the room.', 'survival_chance' => 80, 'tier' => 'D'],
    ['item' => 'Rotisserie unconsciousness', 'effect' => 'You drift into sleep while slowly rotating like a chicken.', 'survival_chance' => 85, 'tier' => 'D'],
    ['item' => 'Electrocuted-looking humanity', 'effect' => "Everyone's hair stands on end; all of mankind looks terrified.", 'survival_chance' => 95, 'tier' => 'F'],
    ['item' => 'BREATHING (the atmosphere leaves)', 'effect' => 'The entire sky peels off the planet and floats into space, taking the oceans, the soil, the glitter, and your unfinished coffee with it.', 'survival_chance' => 0, 'tier' => 'S+', 'note' => 'Voids the whole scoreboard: every other entry needs a living person to experience it.'],
];

/**
 * The weather, personally offended. Grouped by system, each with a
 * headline announcement and the forecasts that fall under it.
 */
const VENGEFUL_WEATHER = [
    'PRECIPITATION HAS ACHIEVED SENTIENCE AND IS FILING GRIEVANCES' => [
        'Rain falling upward, sideways, backward through time, and once — inexplicably — through the concept of Thursday itself',
        "Drizzle that has your address, your mother's maiden name, and strong feelings about your posture",
        'Freezing rain glazing the earth into a single continuous ice-mirror in which you can see every version of yourself that made worse decisions',
        'Snow that lands, screams your unencrypted passwords into the void, and melts before you can stop it',
        "Graupel: the sky's beanbag chair has ruptured at the seam and the stuffing is coming for us all, personally, by name",
        "Hail the size of a court summons you can't legally decline",
        'Sleet — the eternal war between rain and snow, fought in your collar, no survivors, no ceasefire, no god',
        'Virga: rain that descends halfway, makes eye contact with the entire planet, and dematerializes out of a shame so profound it echoes in the troposphere',
    ],
    'THE SKY IS AWAKE AND IT REMEMBERS EVERYTHING' => [
        'Clear skies. The blue is not a color. The blue is a lid. Do not ask what it is a lid on.',
        'Partly cloudy: the clouds are dissociating and will not be taking questions',
        'Overcast — the firmament has pulled a gray shroud over its ten thousand eyes and is pretending, for your sake, that it cannot see you',
        'Cumulonimbus rising forty-five thousand feet, anvil-crowned, benching the jet stream, screaming a frequency only dogs and the damned can hear',
        'Mammatus clouds: the sky has grown a hundred smooth bulbous udders and hangs low and wrong and every civilization that has seen this has, correctly, panicked',
    ],
    'FOG: VISIBILITY IS A PRIVILEGE AND IT HAS BEEN REVOKED' => [
        'Fog that ingested the entire town and now hums contentedly, digesting',
        "Mist — the fog's smaller, chattier familiar, whispering directions to a place that does not exist",
        'Freezing fog: the fog has died and risen, a crystalline revenant, load-bearing, undying, faintly amused',
        'Ice fog so cold that the air itself has surrendered its molecular ambitions and become a suspended galaxy of tiny patient blades',
        'The Brown Fog. We do not speak of the Brown Fog. It knows your PIN.',
    ],
    'WIND, UNCHAINED, HOWLING IN A LANGUAGE THAT PREDATES VOWELS' => [
        'Dead calm. The insects have stopped. The birds have stopped. Your watch has stopped. A decision, ancient and enormous, is being reached about you specifically.',
        'Gusts abducting one glove, one earring, one memory of your father, redistributed at random across the county',
        'Gale-force winds rearranging every lawn chair in the hemisphere into a single sigil that, viewed from orbit, spells a word no human throat can survive',
        'Foehn winds — warm, dry, disarmingly kind, whispering that everything will be fine as they systematically dismantle your will to live and also your gazebo',
        'Wind shear: two air masses meeting at 3,000 feet, recognizing each other from a previous life, and beginning, immediately, to scream',
    ],
    'THUNDERSTORMS: THE ATMOSPHERE HAS BEEN UNSUPERVISED FOR TOO LONG' => [
        'Lightning that struck the same spot forty times to spell something, and we translated it, and we wish we hadn\'t',
        'Thunder arriving late, arriving early, arriving from inside the house, laughing at a joke told before the invention of language',
        'Tornadoes performing a synchronized ballet across three counties, F5, flawless, and the sky is weeping, and the weeping is applause',
        'Waterspouts: a tornado that walked into the sea, made friends with something down there, and came back changed',
        'Microburst — the fist of a colossus punching straight down onto one gazebo it has hated since the Pleistocene',
        'Derecho: a single unbroken line of wind, six hundred miles long, that received one (1) upsetting notification and is now driving through your entire regional power grid to have a word',
    ],
    'LARGE-SCALE SYSTEMS WITH A PR TEAM, A GRUDGE, AND A COSMIC MANDATE' => [
        'Hurricanes with a name, a rank, a Wikipedia page, and a reserved seat at the head of every table you will ever sit at again',
        'Blizzards white-outing not just the landscape but the render distance of reality itself, until existence displays only a spinning cursor and the merciful hum of a universe reloading',
        'Ice storms lacquering the world into a glass ornament so exquisite, so total, that God pauses, considers keeping it, and then hears it all shatter at once',
        'Haboob: a mile-high wall of every desert that has ever been, standing up, dusting itself off, and walking toward the city with the unhurried confidence of something that has done this before and will do it again',
        'Polar vortex — the Arctic has slipped its leash, crossed the 30th parallel, and is now standing in a parking lot in Dallas, radiating an ancient cold and demanding, in a voice like calving ice, to see the manager of the sun',
    ],
    'TEMPERATURE, SHIVERING AND BOILING IN THE SAME BREATH, FEVERISH, PROPHETIC' => [
        'Heat so total the asphalt liquefies, stands up, and begins, softly, to prophesy',
        'Cold that freezes the moisture in your eyes into two small perfect lenses through which you briefly, horribly, see clearly',
        "Wind chill: the temperature and the wind have merged into a single entity whose entire theology is the ruination of your specific, individual face",
        'Humidity so absolute the air is now a broth, sentient, warm, and it would like to keep you',
    ],
    'SMALL ATMOSPHERIC GREMLINS NURSING ANCIENT AND SPECIFIC GRUDGES' => [
        'Dew — every blade of grass, weeping, all night, about a thing you did before you were born',
        'Frost etching upon your windshield a fractal cathedral so intricate it can only have been drawn by something that had all of eternity and a personal vendetta',
        'Rime ice growing sideways off every surface because gravity has read the room and quietly excused itself',
        'Hoarfrost building, on your fence, a diorama of a tiny frozen kingdom whose tiny frozen king is staring, directly, at you',
        'Drought: the sky has read your every message, watched your every offering burn, and elected — with the serene cruelty of the truly indifferent — to say nothing, for a year, and then another',
    ],
    'OPTICAL PHENOMENA, THE VEIL THINNING, THE EYE UNBLINKING' => [
        'Rainbows arcing full-double across the heavens, promising gold, promising it knowingly, promising it to watch you run',
        "Sundogs — the sun, lonely beyond the comprehension of warm-blooded things, has budded two false copies of itself, and the three of them are watching, and they are not in agreement about you",
        'A moon halo: the moon has been ringed by something it did not choose and cannot remove, and it hangs there, luminous, encircled, doomed, and beautiful',
        'Mirages: the desert, bored and immortal, conjuring a shimmering lake purely to enjoy the small warm shape of you stumbling toward a promise it never made',
        'Sun pillar — a single shaft of light standing bolt upright from the horizon, silent, vertical, patient, as though something below is about to ascend, and we should not, any of us, be here to watch it',
    ],
];

/**
 * Clouds went feral. Fifty materials, S-Tier (instant, dignified
 * death) down to F-Tier (a fruitcake, finally doing something useful).
 */
const WRONGFALL = [
    ['tier' => 'S', 'material' => 'Concrete', 'effect' => "It comes down grey and gasping and sets the instant it touches you, so you die mid-flinch, arms up, mouth open, a statue of your own last bad decision. Tourists will photograph you. They'll assume it's art. It is not art. It's Kevin."],
    ['tier' => 'S', 'material' => 'Steel', 'effect' => 'The clouds ping like a struck anvil right before they open, which is the sky giving you exactly one second of warning, which is the cruelest thing it could possibly do. Molten. Everywhere. Your umbrella files for divorce and evaporates.'],
    ['tier' => 'S', 'material' => 'Bedrock', 'effect' => 'The sky drops THE GROUND. Down is now falling on down. The planet is hitting itself. Geology has become a contact sport and you are the ball.'],
    ['tier' => 'S', 'material' => 'Bone', 'effect' => "Warm. Personal. Faintly skeletal. Every drop used to be inside someone and it remembers. The gutters don't gurgle, they rattle, and if you listen closely — don't. Do not listen closely."],
    ['tier' => 'S', 'material' => 'Silicon chips', 'effect' => 'The entire internet falls out of the sky as warm dead slurry and every single drop knows what you searched at 2am and lands on it specifically. You are being judged by rain. The rain wins.'],
    ['tier' => 'S', 'material' => 'Tooth enamel', 'effect' => 'Eight billion sets of teeth fall from the heavens in the exact key everyone screamed, so it harmonises, so the apocalypse has a soundtrack and the soundtrack is a chord made of molars.'],
    ['tier' => 'S', 'material' => 'Ice caps', 'effect' => "The poles just… come down. As weather. The map drowns from ABOVE now, which is a fun new direction for the map to drown from. Fish look up. Fish are confused. Fish inherit nothing because there's nothing left."],
    ['tier' => 'S', 'material' => 'Bullets', 'effect' => 'Weather with a grudge and a target. It falls angry, it pools angrier, and nobody knows what liquid ammunition wants except down, and into you, and it has been waiting.'],

    ['tier' => 'A', 'material' => 'Glass', 'effect' => "For four-tenths of one second the sky is the most beautiful it has ever been, a chandelier the size of the horizon, and then it's a billion falling windows and you die inside a kaleidoscope that is also a woodchipper. Gorgeous. Fatal. Instagrammable."],
    ['tier' => 'A', 'material' => 'Asphalt', 'effect' => 'Warm black tar comes down and the birds go first, mid-flap, sealed like flies in amber, and then the whole world gets slowly, lovingly paved from the top down. The Earth is being resurfaced. You are under the resurfacing.'],
    ['tier' => 'A', 'material' => 'Brick', 'effect' => "Entire towns fall UPWARD'S REVENGE. Masonry hail. A hard hat, against this, is a joke you tell at the funeral."],
    ['tier' => 'A', 'material' => 'Copper', 'effect' => 'Electric soup, and every single drop completes a circuit, and the circuit is you, so standing outside becomes a lifestyle choice you get to make precisely once, and briefly, and brightly.'],
    ['tier' => 'A', 'material' => 'Rebar', 'effect' => 'The sky is throwing STEEL SPEARS and — this is the worst part — it has aim. It is not raining. It is javelin practice and the field is everyone.'],
    ['tier' => 'A', 'material' => 'Rubber', 'effect' => 'Bouncing goo, knee-deep, drains NEVER, plus the occasional full tyre from orbit going boing off your skull. The apocalypse, but slapstick. You die to a sound effect.'],
    ['tier' => 'A', 'material' => 'Plastic', 'effect' => 'Modernity itself precipitates, warm and slurried, tupperware-flavoured doom, and the ocean — already full of the stuff — looks up and says "oh, more?" and thanks absolutely no one.'],
    ['tier' => 'A', 'material' => 'Munitions', 'effect' => 'Like bullets, but each drop is an event, a percussion solo performed on the roof of the world by a sky that has clearly stopped taking its medication.'],

    ['tier' => 'B', 'material' => 'Wood', 'effect' => 'Sap-storms, thick and amber and haunted, plus the occasional whole log doing 90 downward. Everything smells like a sawmill that died screaming. The beavers have gone quiet. The beavers know.'],
    ['tier' => 'B', 'material' => 'Dentures', 'effect' => "Warm dental hail patters down and Grandpa — sweet, hopeful, oblivious Grandpa — looks UP. DO NOT LET GRANDPA LOOK UP. It is already too late. It was always too late. Pour one out for Grandpa's optimism."],
    ['tier' => 'B', 'material' => 'Books', 'effect' => 'You get gently drizzled with the collected wisdom of humanity and then concussed by a hardback Tolstoy doing terminal velocity. You come inside wiser, filthier, and mildly brain-damaged. A fair trade, arguably.'],
    ['tier' => 'B', 'material' => 'Chimney bricks', 'effect' => "FIRE and falling masonry, at once, together, a combo the sky is genuinely too proud of. It's showing off now. It wants applause. Do not applaud, your hands are full of brick."],
    ['tier' => 'B', 'material' => 'Ceramic', 'effect' => "Toilets. Teacups. Sinks. Falling from heaven. Daily. This is the single least dignified way a civilisation has ever ended and it's scheduled and there's a little icon for it on the weather app and the icon is a toilet."],
    ['tier' => 'B', 'material' => 'Ship hulls', 'effect' => 'Oil tankers. From cloud height. Each one is its own regional disaster with its own Wikipedia page that no one will live to write.'],
    ['tier' => 'B', 'material' => 'Aircraft fuselage', 'effect' => "Everything that ever went up is now enthusiastically coming down and it is enormous and it is whistling. Look up. No. Don't. I told you not to. Why do you people keep looking up."],
    ['tier' => 'B', 'material' => 'Coral reefs', 'effect' => 'Razor-sharp calcium hail, and in a final vicious joke the reef lands on the very fish that used to live in it, evicted and executed in the same afternoon. The sea is now just angry soup with a grudge.'],
    ['tier' => 'B', 'material' => 'Piano frames', 'effect' => "Grand pianos. From orbit. Each one striking one perfect final chord on impact, so the sky sounds like a concert hall being fed into God's own garbage disposal. It is the most middle-class way to die and it knows it."],
    ['tier' => 'B', 'material' => 'Coins', 'effect' => 'It rains cold hard cash, so you stand in the storm, mouth open, arms wide, getting richer and richer and more concussed and eventually drowned in your own sudden fortune. The most on-brand death available. Capitalism, weather edition.'],

    ['tier' => 'C', 'material' => 'Furniture', 'effect' => 'Sofas plummet from the troposphere, soft-ish, ALMOST survivable, and then a WARDROBE finds you specifically, personally, like it had your address. IKEA from the sky. Some assembly required. Yours.'],
    ['tier' => 'C', 'material' => 'Cutlery', 'effect' => 'Silverware rains down tinkling ominously, a thousand tiny cheerful chimes, and everyone is left slightly, constantly, cumulatively stabbed. Death by a thousand teaspoons. Very British.'],
    ['tier' => 'C', 'material' => 'Statues', 'effect' => 'The great figures of history rain down one by one, each wearing an expression of profound disappointment in you personally, and the pigeons — homeless now, and FURIOUS — form a militia. Watch the pigeons. The pigeons have organised.'],
    ['tier' => 'C', 'material' => 'Chalk', 'effect' => 'The White Cliffs of Dover fall out of the sky, dusty and damp, and England says nothing, England just stands in the doorway with a cup of tea and one single tear, because England knew, England always knew it would end like this.'],
    ['tier' => 'C', 'material' => 'Shoe soles', 'effect' => "Rubber slabs bombard the earth and now everyone is barefoot AND under artillery, which is the exact wrong combination, and you can't even run away stylishly."],
    ['tier' => 'C', 'material' => 'Spectacles', 'effect' => "The sky rains everybody's lost eyesight and it pools into puddles that are — cruelly — clearer than actual reality, so you can finally see perfectly, in the reflection, the enormous cash register falling toward your head."],
    ['tier' => 'C', 'material' => 'Instruments', 'effect' => 'Guitars and trumpets and cellos tumble down, each sagging out one last mournful dying note, so the end of the world sounds like an orchestra falling down an infinite staircase. Beautiful. Ongoing. Loud.'],
    ['tier' => 'C', 'material' => 'Doorknobs', 'effect' => 'Brass hail, which means everyone is trapped INSIDE (no knobs left indoors) and pelted the second they try to leave. The sky has locked you in and is knocking. The sky wants to come in. Do not let the sky in.'],
    ['tier' => 'C', 'material' => 'Cash registers', 'effect' => "Tills. From the sky. Ka-CHING on the way down, splat on arrival. Retail didn't just collapse, it went airborne and hostile, and somewhere a manager is looking up going \"is this covered under—\" and then it isn't and neither is he."],
    ['tier' => 'C', 'material' => 'Umbrellas', 'effect' => 'The sky rains the ONE OBJECT humanity invented specifically to fight rain. The irony is so dense it has its own gravity. You are being bullied by the weather. It is personal. It brought props.'],

    ['tier' => 'D', 'material' => 'Garden gnomes', 'effect' => 'Ceramic hail, and every single one is smiling on the way down, a thousand tiny rosy-cheeked men descending with joy in their little painted eyes, and you will hear their happy little tinkle-smash for the rest of your short, haunted life. Sleep tight.'],
    ['tier' => 'D', 'material' => 'Pencils', 'effect' => "Graphite showers leave everything smudged and grey and vaguely accusatory, as if the sky is disappointed you didn't do your homework, and honestly? You didn't. It's right. That's what stings."],
    ['tier' => 'D', 'material' => 'Zippers', 'effect' => 'They fall and land with a sound like ten thousand flies going down at ONCE, the great unzipping, and the forecast simply reads: undignified, patchy, ongoing.'],
    ['tier' => 'D', 'material' => 'Chess pieces', 'effect' => 'Strategic precipitation, and the KING lands first, because of course he does, coward, figures — the whole storm is just the sky resigning the match it started.'],
    ['tier' => 'D', 'material' => 'Cufflinks', 'effect' => "Tiny gold formal shrapnel turns every wedding into a foxhole. The groom is flapping. The vicar has taken cover. Let them. Let it all come down. Till death, and it's arriving early."],
    ['tier' => 'D', 'material' => 'Keys', 'effect' => "They fall, and they're in the WRONG POCKET, and they're STILL LOST, so the sky somehow lost your keys and is now hurling them back at you at speed — the universe found something more useless than a lost key, and it is a lost key doing 60 miles an hour toward your teeth."],
    ['tier' => 'D', 'material' => 'Buttons', 'effect' => "Small round decisions the sky is making about your day, pattering down endlessly, each one a tiny \"no.\" Just \"no.\" \"No.\" \"No.\" \"No.\" Forever. The sky disapproves and it's specific."],
    ['tier' => 'D', 'material' => 'Guitar picks', 'effect' => "A confetti-storm of the utterly useless, and every busker on Earth is now somehow even more grounded, mid-strum, forever, as the sky sheds the one thing they needed and it's worthless in bulk."],

    ['tier' => 'E', 'material' => 'Ice cubes', 'effect' => "The sky isn't even trying. Warm hail. Lukewarm hail. It's menace with the batteries running low. You could die of this but it'd be embarrassing."],
    ['tier' => 'E', 'material' => 'Sugar cubes', 'effect' => 'Sweet hail rains down and the ANTS ascend to godhood, a glittering insect theocracy rising from the sugar-drifts, while Britain issues a formal statement calling the tea situation "complicated." It is not complicated. The ants are gods now. Say it plainly.'],
    ['tier' => 'E', 'material' => 'Dice', 'effect' => "You never know if today's storm is a gentle 2 or an apocalyptic 20, so every morning is a saving throw, and the sky is a Dungeon Master who hates you and rolls in the open just to watch you flinch."],
    ['tier' => 'E', 'material' => 'Crayons', 'effect' => "Waxy rainbow rain runs down every gutter in glorious technicolour and NOBODY cleans it, ever, so the whole world looks like a melted child's drawing of the end times, which — fair. Accurate. The toddler was right all along."],
    ['tier' => 'E', 'material' => 'Breath mints', 'effect' => "Minty precipitation makes the entire apocalypse smell outrageously fresh and pleasant and this, THIS, is the one that breaks people. Not the death. The pleasantness. Everyone is livid. It smells amazing. They're furious."],

    ['tier' => 'F', 'material' => 'Fruitcake', 'effect' => 'It falls. It lands. And for the first time in the entire span of human history the fruitcake has a PURPOSE, a DESTINY, a reason to exist, and nobody mourns, nobody flinches, nobody even reaches for an umbrella, because deep down every single person on Earth agrees this is the one thing the sky has ever gotten right. Water walked so fruitcake could fall. We should have led with the fruitcake. We always should have led with the fruitcake.'],
];

/**
 * Five severity tiers of state-mandated pet, escalating from
 * "featherweight chaos" to "cosmically ill-advised". Bounds are
 * rough mass/threat guidance, not enforced anywhere.
 */
const PET_TIERS = [
    1 => ['label' => 'Featherweight chaos',    'range' => 'under 1kg'],
    2 => ['label' => 'Renovation required',    'range' => '1-20kg'],
    3 => ['label' => 'Structural & legal',     'range' => '20-200kg'],
    4 => ['label' => 'Do not',                 'range' => '200kg+ / lethal'],
    5 => ['label' => 'Cosmically ill-advised', 'range' => 'breaks reality'],
];

const MANDATORY_PETS = [
    ['tier' => 1, 'animal' => 'Axolotl', 'consequence' => 'Perpetually smiling gremlin that regenerates its own brain and outlives your relationships by a decade. You will develop deep parasocial feelings for a salamander whose capacity for emotion is exactly \'wet.\' It watches you always. Forever watching.'],
    ['tier' => 1, 'animal' => 'Tardigrade', 'consequence' => 'Invisible to the naked eye and literally cannot be killed by anything you have access to. You will lose it. You have already lost it. It is in your ventilation, your atmosphere, maybe your cells now. The adoption is permanent and non-consensual. The tardigrade owns you.'],
    ['tier' => 1, 'animal' => 'Star-nosed mole', 'consequence' => 'Twenty-two writhing pink tentacles erupting from where a face should be. Your guests will never return. Your therapist will have expensive questions. It is blind but can sense your fear and finds it hilarious.'],
    ['tier' => 1, 'animal' => 'Mantis shrimp', 'consequence' => 'Perceives 16 colour channels you don\'t exist within. Punches at bullet speed with the impact of a .22 caliber. Will destroy the glass repeatedly out of pure contempt for your design choices. Has already calculated precisely where to strike for maximum structural failure.'],
    ['tier' => 1, 'animal' => 'Bullet ant', 'consequence' => 'Carries the worst pain known to science in its stinger. Researchers describe it as equivalent to being shot in the leg repeatedly. You will find out if they\'re underselling it. Prayer is contractual.'],
    ['tier' => 1, 'animal' => 'Bombardier beetle', 'consequence' => 'Produces boiling toxic caustic chemicals from its rear end on command and deploys them with surgical precision and warzone mercy. Your house will smell like a chemical weapons facility for months. Guests will call the police.'],
    ['tier' => 1, 'animal' => 'Blue-ringed octopus', 'consequence' => 'Palm-sized. Kills 26 adults with no known antivenom. Adorable. Smiling. Already looking at you with malice aforethought.'],
    ['tier' => 1, 'animal' => 'Surinam toad', 'consequence' => 'Babies literally burst explosively from the mother\'s back like a chest-burster scene. You will watch this happen. You will never unsee it. The nightmares are non-refundable.'],
    ['tier' => 1, 'animal' => 'Immortal jellyfish', 'consequence' => 'Stressed? Just revert to being a baby and start over. You now own a creature that handles trauma through denial and regression. It is gaslit from conception.'],
    ['tier' => 1, 'animal' => 'Glass frog', 'consequence' => 'Transparent belly shows you every organ at real-time. Therapeutic until you watch in slow motion as each one fails. An existential biology lesson.'],
    ['tier' => 1, 'animal' => 'Hagfish', 'consequence' => 'Produces litres of self-generated slime in seconds, ties itself into a knot, owns your entire fishing operation. A creature of pure biological chaos.'],
    ['tier' => 1, 'animal' => 'Pink fairy armadillo', 'consequence' => 'Palm-sized marsupial that screams when stressed, is covered in translucent armor, looks like a cursed marshmallow. Owns distress on a miniature scale.'],
    ['tier' => 1, 'animal' => 'Jerboa', 'consequence' => 'A spring-loaded wind-up toy that malfunctions and disappears through gaps you didn\'t know existed. Gone forever within the hour. This is not a recovery situation.'],
    ['tier' => 1, 'animal' => 'Sugar glider', 'consequence' => 'Screams when you leave (CAN be heard three blocks away). Pees mid-glide. Requires a friend (singular failure = existential despair in both). A tiny needy alarm system.'],
    ['tier' => 1, 'animal' => 'Tarsier', 'consequence' => 'Eyes bigger than its brain. Will literally die of stress if you make eye contact for too long. The guilt of owning it is the actual pet.'],
    ['tier' => 1, 'animal' => 'Vampire bat', 'consequence' => 'Laps blood from living prey while the prey is still conscious, shares meals regurgitated with its colony, tracks your livestock by ultrasonic echolocation. A communal ghoul.'],
    ['tier' => 1, 'animal' => 'Goliath birdeater', 'consequence' => 'Dinner-plate-sized tarantula that flicks barbed hairs causing months of itching and rarely eats birds despite the name. Named for lies and hope.'],
    ['tier' => 1, 'animal' => 'Pistol shrimp', 'consequence' => 'Snaps its claw so violently it cavitates water and produces shockwaves that stun prey. Your tank is now a sonar weapons system. The shrimp is winning a war against its own reflection.'],
    ['tier' => 1, 'animal' => 'Peacock spider', 'consequence' => 'Tiny male performs an elaborate breakdancing courtship ritual for the female. If unimpressed, she eats him. Every mating is a high-stakes reality show where death is outcome one.'],
    ['tier' => 1, 'animal' => 'Frilled shark', 'consequence' => 'A living fossil eel-shark with 300 hooked teeth and a body made entirely of \'no.\' Older than trees and meaner than your ex-partner\'s parents.'],
    ['tier' => 1, 'animal' => 'Deep-sea anglerfish', 'consequence' => 'The male fuses permanently onto the female and dissolves into a sperm sac. The worst relationship outcome documented in biology. Don\'t make me explain further.'],
    ['tier' => 1, 'animal' => 'Barreleye fish', 'consequence' => 'Transparent head with eyes that swivel inside its own skull. Literally sees through its own face. A creature designed by someone who failed biology and succeeded at nightmare.'],
    ['tier' => 1, 'animal' => 'Flamboyant cuttlefish', 'consequence' => 'Walks along the seabed flashing hypnotic psychedelic patterns, is extremely toxic, and small enough to hold. A tiny toxic disco that kills with pride.'],
    ['tier' => 1, 'animal' => 'Bobbit worm', 'consequence' => 'Metre-long buried ambush predator with jaws that snap fish in half. Do not step on the sand. The sand might bite you back.'],
    ['tier' => 1, 'animal' => 'Giant isopod', 'consequence' => 'A dustbin-lid-sized woodlouse from the deep sea that can fast for literal years without eating. Landlord-level energy in crustacean form.'],
    ['tier' => 1, 'animal' => 'Whip scorpion', 'consequence' => 'Sprays acetic acid from its rear when threatened. Your home smells like a chip shop at war. A resentful tiny vinegar factory.'],
    ['tier' => 1, 'animal' => 'Pompeii worm', 'consequence' => 'Lives at deep-sea vents at temperatures that would cook you into molecular soup. Your bathtub is its cold-water resort.'],
    ['tier' => 1, 'animal' => 'Lampreys', 'consequence' => 'A jawless mouth made of concentric rings of teeth that latch and drain fluids. A nightmare-straw that dates back 360 million years and saw fit to stick around.'],
    ['tier' => 1, 'animal' => 'Cone snail', 'consequence' => 'Fires a venom harpoon. One sting = respiratory failure and regret. Nicknamed \'cigarette snail\' because that\'s how much time you have left. Decorative death.'],
    ['tier' => 1, 'animal' => 'Nautilus', 'consequence' => 'Hasn\'t evolved meaningfully in 500 million years. Chambered shell, 90+ tentacles, hunts in the deep. A living fossil that judges your impermanence.'],
    ['tier' => 1, 'animal' => 'Axolotl\'s cousin, the olm', 'consequence' => 'Blind cave salamander that lives 100 years and can fast for a decade without complaint. Owns patience you will never achieve.'],
    ['tier' => 1, 'animal' => 'Regal horned lizard', 'consequence' => 'Squirts blood from its own eyes to deter predators. A creature that weaponized its own fluids out of pettiness.'],
    ['tier' => 1, 'animal' => 'Pinocchio frog', 'consequence' => 'Male has a nose that inflates and deflates with mood. A frog with a mood-ring for a face. Emotional transparency at amphibian scale.'],
    ['tier' => 1, 'animal' => 'Mexican mole lizard', 'consequence' => 'A pink lizard with exactly two tiny arms and zero legs. Assembled entirely incorrectly on purpose by evolution.'],
    ['tier' => 1, 'animal' => 'Sarcastic fringehead', 'consequence' => 'Opens its entire face into a gaping threat display over territory. Drama incarnate. Attitude the size of an ocean.'],
    ['tier' => 1, 'animal' => 'Aye-aye', 'consequence' => 'Taps trees and fishes grubs out with one cartoonishly elongated horror-movie finger. Genuinely considered a curse in its native Madagascar.'],
    ['tier' => 1, 'animal' => 'Slow loris', 'consequence' => 'Toxic elbows, venomous bite, enormous guilty eyes. Cutest thing that can put you in hospital and make you feel bad about it.'],
    ['tier' => 1, 'animal' => 'Etruscan shrew', 'consequence' => 'Smallest mammal alive. Heart beats at 1,500 bpm. Must eat constantly or dies within hours. A high-maintenance pebble with murderous drive.'],
    ['tier' => 1, 'animal' => 'Star-gazer fish', 'consequence' => 'Buries itself with eyes pointed upward. Electrocutes anything that walks over it. A living landmine with ambition.'],
    ['tier' => 1, 'animal' => 'Springtail', 'consequence' => 'Launches itself with a tail-catapult mechanism. Billions exist in your garden right now. They are watching. They have always been watching.'],
    ['tier' => 1, 'animal' => 'Rotifer', 'consequence' => 'Can dry out completely for literal decades and rehydrate back to life. Owns immortality through dehydration. Nature\'s copy of a broken save file.'],
    ['tier' => 1, 'animal' => 'Water flea', 'consequence' => 'Transparent with a visibly beating heart. Reproduces without males when lonely. Does whatever it wants, always.'],
    ['tier' => 1, 'animal' => 'Satanic leaf-tailed gecko', 'consequence' => 'Perfect dead-leaf camouflage with a demonic name. You will lose it in your own home. It will still be there, watching.'],
    ['tier' => 1, 'animal' => 'Draco flying lizard', 'consequence' => 'Glides between trees on rib-wings like a tiny dinosaur that got a pass. A lizard that achieved flight without permission.'],
    ['tier' => 1, 'animal' => 'Velvet ant', 'consequence' => 'Wingless wasp in a fuzzy coat. The nickname \'cow killer\' is not aspiration. It is a résumé. One sting redefines pain.'],
    ['tier' => 1, 'animal' => 'Assassin bug', 'consequence' => 'Hunts with face-tentacles, wears prey exoskeletons as fashion statements and warnings. Your furniture is now a alien graveyard.'],
    ['tier' => 1, 'animal' => 'Sea angel', 'consequence' => 'Translucent pteropod that looks like a deceased grandmother ascended to jellyfish form. Obsessed with one single prey species its whole life.'],
    ['tier' => 1, 'animal' => 'Blue dragon sea slug', 'consequence' => 'Steals venom from prey and concentrates it into its own tentacles. A bioweapons laboratory that moves via ocean currents.'],
    ['tier' => 1, 'animal' => 'Leaf sheep slug', 'consequence' => 'Steals chloroplasts from plants and photosynthesizes. A slug that became a solar panel. A vegetarian invertebrate exception.'],
    ['tier' => 1, 'animal' => 'Dumbo octopus', 'consequence' => 'Ear-like fins, lives in the abyss where pressure defies physics. Too cute to deserve its own location.'],
    ['tier' => 1, 'animal' => 'Giant weta', 'consequence' => 'A cricket the size of your hand. Can be frozen solid and will thaw back to full function. An invertebrate that conquered cryogenics.'],
    ['tier' => 1, 'animal' => 'Titan beetle', 'consequence' => 'Can snap pencils with its jaws. A beetle with industrial-grade bite force and opinions.'],
    ['tier' => 1, 'animal' => 'Huntsman spider', 'consequence' => 'Sprints across walls at night. Harmless. Will still stop your heart at 3 AM with its sheer audacity.'],
    ['tier' => 1, 'animal' => 'Wolf spider', 'consequence' => 'Carries dozens of babies on its back. Adopt one, accidentally adopt a hundred. Motherhood at arachnid scale.'],
    ['tier' => 1, 'animal' => 'Trapdoor spider', 'consequence' => 'Builds a hinged lid and waits. Pure ambush predator energy. Home invasion specialist.'],
    ['tier' => 1, 'animal' => 'Diving bell spider', 'consequence' => 'Lives underwater in a bubble of its own air. A spider that went full scuba forever.'],
    ['tier' => 1, 'animal' => 'Tapeworm', 'consequence' => 'Do not adopt. It adopts you. From the inside. This is the other way around.'],
    ['tier' => 1, 'animal' => 'Jewel wasp', 'consequence' => 'Zombifies cockroaches with a precise brain sting and walks them to their doom. Mind control via insects.'],
    ['tier' => 1, 'animal' => 'Tarantula hawk', 'consequence' => 'A wasp whose sting is rated \'lie down and scream continuously.\' Owns your dignity permanently.'],
    ['tier' => 1, 'animal' => 'Christmas Island red crab', 'consequence' => 'Migrates in tens of millions, closes roads, moves as an unstoppable tide. One is fine. They never come as one.'],
    ['tier' => 2, 'animal' => 'Fennec fox', 'consequence' => 'Ears bigger than its head. Nocturnal screaming (audible three blocks away). Digs through drywall like it\'s tissue paper.'],
    ['tier' => 2, 'animal' => 'Serval', 'consequence' => 'Leggy spotted diva that leaps 3m vertically. Needs 6ft fencing. Treats your sofa as a litter statement.'],
    ['tier' => 2, 'animal' => 'Caracal', 'consequence' => 'Ear-tuft assassin. Leaps 3 metres to snatch birds mid-flight. Your ceiling fan is now prey.'],
    ['tier' => 2, 'animal' => 'Kinkajou', 'consequence' => 'Honey-loving rainforest noodle with prehensile tail and a bite that comes without warning when hangry (always).'],
    ['tier' => 2, 'animal' => 'Honey badger', 'consequence' => 'Does not care. Has never cared. Fights creatures ten times its size out of principle. Shrugs off venom and bee swarms. Immovable spite.'],
    ['tier' => 2, 'animal' => 'Wolverine', 'consequence' => '35 pounds of pure fury that has fought and won against bears. Personality larger than any conceivable passport photo.'],
    ['tier' => 2, 'animal' => 'Pangolin', 'consequence' => 'A walking artichoke that rolls into an impenetrable ball. Most trafficked mammal on Earth. Deserves infinitely better than you.'],
    ['tier' => 2, 'animal' => 'Tamandua', 'consequence' => 'Tree anteater that boxes with claws and reeks of fermented insects. Unpaid termite bill.'],
    ['tier' => 2, 'animal' => 'Three-toed sloth', 'consequence' => 'Moves so slowly algae grows on its fur creating a mobile ecosystem. Owns a rainforest on its back.'],
    ['tier' => 2, 'animal' => 'Armadillo', 'consequence' => 'Always births identical quadruplets. Carries leprosy. A four-for-one felony package.'],
    ['tier' => 2, 'animal' => 'Platypus', 'consequence' => 'Lays eggs. Has venom spurs. Senses electricity. Has no stomach. Glows under UV. Assembled by a committee that failed.'],
    ['tier' => 2, 'animal' => 'Mata mata turtle', 'consequence' => 'Looks like rotting bark. Vacuums fish into its face. Ugly specifically on purpose.'],
    ['tier' => 2, 'animal' => 'Snapping turtle', 'consequence' => 'Bites through broom handles. Hisses with authority. Will outlive your lease. Do not offer fingers.'],
    ['tier' => 2, 'animal' => 'Tuatara', 'consequence' => 'Isn\'t a lizard but looks like one. Has a third eye on its head. Lives 100+ years. A reptile older than reptiles.'],
    ['tier' => 2, 'animal' => 'Gila monster', 'consequence' => 'One of few venomous lizards. Bites and holds on while chewing venom. Grip of commitment, literally.'],
    ['tier' => 2, 'animal' => 'Monitor lizard (small)', 'consequence' => 'Smart enough to count, destructive enough to renovate your house without permission. A dinosaur intern.'],
    ['tier' => 2, 'animal' => 'Green iguana', 'consequence' => 'Grows to 1.5m. Whips with its tail. Drops from trees when cold. Falling furniture with a heartbeat.'],
    ['tier' => 2, 'animal' => 'Tokay gecko', 'consequence' => 'Screams words that sound like swearing. Bites and refuses to release. Foul-mouthed lodger.'],
    ['tier' => 2, 'animal' => 'Olm', 'consequence' => 'Blind cave salamander living 100 years in total darkness. Can fast for a decade. Owns patience.'],
    ['tier' => 2, 'animal' => 'Hellbender', 'consequence' => 'Giant wrinkly aquatic salamander nicknamed \'snot otter.\' That nickname is the complete review.'],
    ['tier' => 2, 'animal' => 'Chinese giant salamander', 'consequence' => 'Grows to 2m. Cries like a child. A salamander that sounds like a haunting.'],
    ['tier' => 2, 'animal' => 'Caecilian', 'consequence' => 'Limbless amphibian whose young peel and eat their mother\'s skin. Family dinner redefined.'],
    ['tier' => 2, 'animal' => 'Flying fox', 'consequence' => '1.5m wingspan. Hangs like a leathery cloak. Screams at dusk. A gothic curtain that\'s alive.'],
    ['tier' => 2, 'animal' => 'Pallas\'s cat', 'consequence' => 'Grumpy flat-faced mountain cat that hates you specifically. The face IS the entire personality.'],
    ['tier' => 2, 'animal' => 'Black-footed cat', 'consequence' => 'Smallest wild cat. Kills more prey per night than a lion. Tiny apex murderer.'],
    ['tier' => 2, 'animal' => 'Sand cat', 'consequence' => 'Tolerates deserts. Looks like a plush toy. Will maul you regardless. Deceptive floof.'],
    ['tier' => 2, 'animal' => 'Maned wolf', 'consequence' => 'A fox on stilts that smells of cannabis and isn\'t a wolf. Legs and lies.'],
    ['tier' => 2, 'animal' => 'Bat-eared fox', 'consequence' => 'Ears that hear termites underground. Listens to all your regrets.'],
    ['tier' => 2, 'animal' => 'Dhole', 'consequence' => 'Whistling pack-hunting wild dog that brings down prey 10x its size. Team sport.'],
    ['tier' => 2, 'animal' => 'Tasmanian devil', 'consequence' => 'Screams like the damned. Strongest bite for its size. Sneezes to fight. Loud as hell.'],
    ['tier' => 2, 'animal' => 'Quokka', 'consequence' => 'Smiles for selfies. Throws its own baby at predators to escape. Cute with caveats.'],
    ['tier' => 2, 'animal' => 'Wombat', 'consequence' => 'Poops cubes. Has an armoured bum plate. Bulldozes fences. Blocky and unbothered.'],
    ['tier' => 2, 'animal' => 'Capybara', 'consequence' => 'Zen water potato. Needs a pool, a friend, and turns your garden into a swamp.'],
    ['tier' => 2, 'animal' => 'Peccary', 'consequence' => 'Wild pig that travels in aggressive herds, stinks defensively, charges without negotiation.'],
    ['tier' => 2, 'animal' => 'Beaver', 'consequence' => 'Fells your trees, dams your drains, floods your garden. Civil engineer, completely uninvited.'],
    ['tier' => 2, 'animal' => 'Raccoon', 'consequence' => 'Washes food, picks locks, holds grudges, and remembers your exact face. Smarter than you\'re comfortable with.'],
    ['tier' => 2, 'animal' => 'Tanuki (raccoon dog)', 'consequence' => 'A real animal, not folklore. Hibernates and screams. Manages both simultaneously.'],
    ['tier' => 2, 'animal' => 'Binturong', 'consequence' => 'Bearcat that smells strongly of hot buttered popcorn. Cinema-scented household chaos.'],
    ['tier' => 2, 'animal' => 'Coati', 'consequence' => 'Raccoon with a snorkel nose that opens every latch you own. Anarchy on four paws.'],
    ['tier' => 2, 'animal' => 'Skunk', 'consequence' => 'One warning, then chemical warfare that clings for weeks. A pet with a nuclear option.'],
    ['tier' => 2, 'animal' => 'Genet', 'consequence' => 'Spotted cat-weasel that climbs everything and marks with musk. Renovate for smell.'],
    ['tier' => 2, 'animal' => 'Prairie dog', 'consequence' => 'Has a complex language including a word for you. It has described your shirt to its friends.'],
    ['tier' => 2, 'animal' => 'Groundhog', 'consequence' => 'Predicts weather badly. Digs under everything you value. Union-protected saboteur.'],
    ['tier' => 2, 'animal' => 'Muntjac', 'consequence' => 'Tiny deer with fangs that barks like a dog for hours. Confusing on every level.'],
    ['tier' => 2, 'animal' => 'Chevrotain', 'consequence' => 'Mouse-deer hybrid. Size of a cat. Vampire fangs. The world\'s smallest hoofed liar.'],
    ['tier' => 2, 'animal' => 'Springbok', 'consequence' => 'Pronks -- bounces straight up for no reason anyone agrees on. Boing without cause.'],
    ['tier' => 2, 'animal' => 'Meerkat', 'consequence' => 'Adorable but a mob murders rivals\' pups and demands 24/7 sentry duty. HR nightmare.'],
    ['tier' => 2, 'animal' => 'Mongoose', 'consequence' => 'Fights cobras for fun and wins. Your snake problem becomes a mongoose problem.'],
    ['tier' => 2, 'animal' => 'Ocelot', 'consequence' => 'Once kept by the rich. Now a wall of legal paperwork with claws.'],
    ['tier' => 2, 'animal' => 'Clouded leopard', 'consequence' => 'Rotating ankles let it climb down trees head-first. Vertigo hosted at your house.'],
    ['tier' => 2, 'animal' => 'Domestic pig', 'consequence' => 'Smarter than your dog. Opens your fridge. Holds grudges. Grows to your regret\'s size.'],
    ['tier' => 2, 'animal' => 'King cobra', 'consequence' => 'Grows to 5m. Eats other snakes. Can rear up to look you in the eye. Do not make that eye contact.'],
    ['tier' => 2, 'animal' => 'Ball python', 'consequence' => 'Hides its head when scared. Will still outlive your relationships by a decade.'],
    ['tier' => 2, 'animal' => 'Reticulated python (young)', 'consequence' => 'Grows into tier 4. It\'s already measuring your doorway for fit.'],
    ['tier' => 3, 'animal' => 'Kangaroo', 'consequence' => 'Boxes you, disembowels with a kick, needs a paddock and a liability waiver from reality itself.'],
    ['tier' => 3, 'animal' => 'Emu', 'consequence' => 'Won a war against Australia. You will not win this domestic conflict.'],
    ['tier' => 3, 'animal' => 'Ostrich', 'consequence' => 'Can gut a lion with a kick. Has no chill. Your entire fencing budget is now insufficient.'],
    ['tier' => 3, 'animal' => 'Cassowary', 'consequence' => 'A dinosaur that ignored its extinction notice. Dagger toes over a dropped grape. Owns zero mercy.'],
    ['tier' => 3, 'animal' => 'Secretary bird', 'consequence' => 'Stomps snakes to death with karate-level kicks. Owns a martial art you don\'t.'],
    ['tier' => 3, 'animal' => 'Shoebill stork', 'consequence' => 'Stands motionless for hours, then decapitates lungfish. Stares into your soul silently.'],
    ['tier' => 3, 'animal' => 'Reticulated python', 'consequence' => 'Escapes any enclosure. Needs whole rabbits. One day it becomes the meal. It\'s been measuring you this whole time.'],
    ['tier' => 3, 'animal' => 'Green anaconda', 'consequence' => 'Heaviest snake alive. Coils and constricts with physics-defying force. Your bathroom is now a habitat.'],
    ['tier' => 3, 'animal' => 'African rock python', 'consequence' => 'Aggressive, huge, has swallowed things it shouldn\'t. Structural threat by the metre.'],
    ['tier' => 3, 'animal' => 'Monitor lizard (large)', 'consequence' => 'Two metres of intelligent lizard that raids nests and hisses at your resolve. Reptilian burglar.'],
    ['tier' => 3, 'animal' => 'Wild boar', 'consequence' => 'Tusks, temper, a herd. Rototills your property overnight for fun. Thinks your garden is a spa.'],
    ['tier' => 3, 'animal' => 'Warthog', 'consequence' => 'Kneels to eat. Reverses into burrows tusks-first. Faster than you. Comic until it charges.'],
    ['tier' => 3, 'animal' => 'Moose', 'consequence' => 'Bigger than a horse. Charges dogs and cars. Unpredictable in rut. A cathedral of antlers and rage.'],
    ['tier' => 3, 'animal' => 'Bison', 'consequence' => 'Two tonnes that turns on a dime. Gores tourists yearly. The plains are not your paddock.'],
    ['tier' => 3, 'animal' => 'Grey seal', 'consequence' => 'Blubbery charmer with a mouth full of bacteria that turns septic. Cute infection vector.'],
    ['tier' => 3, 'animal' => 'Sea lion', 'consequence' => 'Barks, balances balls, males the size of a sofa that will chase you up the beach.'],
    ['tier' => 3, 'animal' => 'Giant anteater', 'consequence' => 'Two metres of claws that can gut a jaguar. Walks on knuckles. Ant bill enormous, hug ill-advised.'],
    ['tier' => 3, 'animal' => 'Llama', 'consequence' => 'Spits pre-digested stomach contents when annoyed (often). Aim is professional.'],
    ['tier' => 3, 'animal' => 'Alpaca', 'consequence' => 'Softer, still spits, needs a companion or despairs. Emotional livestock.'],
    ['tier' => 3, 'animal' => 'Muskox', 'consequence' => 'Shaggy ice-age tank that forms a horned wall and charges. Structural in truest sense.'],
    ['tier' => 3, 'animal' => 'Wildebeest', 'consequence' => 'Migrates in millions, panics constantly, drowns in rivers en masse. Chaos with hooves.'],
    ['tier' => 3, 'animal' => 'Reindeer', 'consequence' => 'Antlers on both sexes. Clicking knees. Eats lichen you cannot supply. Festive and impractical.'],
    ['tier' => 3, 'animal' => 'Mute swan', 'consequence' => 'Breaks arms with wings. Owns the river. Hates you personally. Elegant assailant.'],
    ['tier' => 3, 'animal' => 'Canada goose', 'consequence' => 'Hisses, honks, guards nothing with total commitment. Airborne road rage.'],
    ['tier' => 3, 'animal' => 'Wolf', 'consequence' => 'Not a dog. Needs a pack and square miles. Treats fences as suggestions. Legally impossible.'],
    ['tier' => 3, 'animal' => 'Coyote', 'consequence' => 'Adapts to anywhere, including your bins and your cat\'s existence. The suburbs are already its habitat.'],
    ['tier' => 3, 'animal' => 'Dingo', 'consequence' => 'Australia\'s apex canine that famously cannot be tamed. The fence exists for a reason.'],
    ['tier' => 3, 'animal' => 'Bobcat', 'consequence' => 'Tufted ambush cat that takes down deer. Your garden is now a hunting ground.'],
    ['tier' => 3, 'animal' => 'Lynx', 'consequence' => 'Snowshoe feet, ear tufts, taste for hares and your resolve. Winter\'s house-guest.'],
    ['tier' => 4, 'animal' => 'Hippopotamus', 'consequence' => 'Cutest deadliest thing in Africa. Kills more humans than lions. Your pool is now a crime scene.'],
    ['tier' => 4, 'animal' => 'Cape buffalo', 'consequence' => 'Nicknamed \'Black Death.\' Will remember that you shot it and hunt you personally across continents.'],
    ['tier' => 4, 'animal' => 'Grizzly bear', 'consequence' => 'You will not survive. The bear will wear your skin. You will be identified by dental records only.'],
    ['tier' => 4, 'animal' => 'Polar bear', 'consequence' => 'Only bear that actively hunts humans. Sees you as 9,000 calories in a coat. Will wait under ice for you.'],
    ['tier' => 4, 'animal' => 'Saltwater crocodile', 'consequence' => '3,700 PSI bite. Hasn\'t evolved since dinosaurs because it was already perfect. It will eat you lengthwise.'],
    ['tier' => 4, 'animal' => 'Nile crocodile', 'consequence' => 'Kills hundreds yearly with infinite patience. Waits at shorelines forever. It has been waiting specifically for you.'],
    ['tier' => 4, 'animal' => 'Komodo dragon', 'consequence' => 'Three metres of venomous perfection. Eats 80% of its body weight. Reproduces via virgin birth. You are now the prey.'],
    ['tier' => 4, 'animal' => 'Lion', 'consequence' => 'Sleeps 20 hours. The other four hours? Remembering you exist and hating it. Males are pure aggression. Prides are militias.'],
    ['tier' => 4, 'animal' => 'Tiger', 'consequence' => 'Largest cat alive. Lone ambush hunter. Swims for fun. Drags prey heavier than motorcycles up trees. It already knows where you sleep.'],
    ['tier' => 4, 'animal' => 'Leopard', 'consequence' => 'Hauls prey heavier than itself up trees silently. Already watching from inside your attic.'],
    ['tier' => 4, 'animal' => 'Jaguar', 'consequence' => 'Only big cat that kills by targeting the spine directly. Bites through skulls like tin cans.'],
    ['tier' => 4, 'animal' => 'Cougar', 'consequence' => 'Leaps 5m vertically. Screams like a woman being murdered (intentional). Ranges half a continent. Not a tabby.'],
    ['tier' => 4, 'animal' => 'African elephant', 'consequence' => 'Grieves its dead. Remembers your face. Flattens what annoys it. Too smart and too big to own.'],
    ['tier' => 4, 'animal' => 'Asian elephant', 'consequence' => 'Gentler. Still six tonnes. Still can remove your house. Structural understatement.'],
    ['tier' => 4, 'animal' => 'White rhino', 'consequence' => 'Near-sighted two-tonne charge. Runs first. Checks what it hit later. Don\'t be the what.'],
    ['tier' => 4, 'animal' => 'Black rhino', 'consequence' => 'Smaller, meaner, charges vehicles on principle. Owns the concept of grudges.'],
    ['tier' => 4, 'animal' => 'Giraffe', 'consequence' => 'Kick decapitates lions. Neck swings like a wrecking ball. Tall and terminal.'],
    ['tier' => 4, 'animal' => 'Gorilla', 'consequence' => 'Ten times your strength. Mostly gentle. Catastrophic when not. Don\'t test the \'mostly.\''],
    ['tier' => 4, 'animal' => 'Chimpanzee', 'consequence' => 'Shares your DNA and your capacity for violence, with five times strength. Tabloid tragedies exist for a reason.'],
    ['tier' => 4, 'animal' => 'Walrus', 'consequence' => 'Tonne of tusked blubber that sinks boats and crushes what it flops onto. Do not befriend.'],
    ['tier' => 4, 'animal' => 'Leopard seal', 'consequence' => 'Hunts penguins and has dragged researchers underwater. A seal with a horror résumé.'],
    ['tier' => 4, 'animal' => 'Orca', 'consequence' => 'Coordinates hunts, has culture, has never killed humans in wild -- because it\'s letting you off.'],
    ['tier' => 4, 'animal' => 'Sperm whale', 'consequence' => 'Loudest animal. Clicks vibrate bodies from miles away. Owns sounds that hurt at range.'],
    ['tier' => 4, 'animal' => 'Great white shark', 'consequence' => '300 teeth. Smells blood for miles. Lineage older than trees. Not bathtub material.'],
    ['tier' => 4, 'animal' => 'Tiger shark', 'consequence' => 'Eats license plates, tires, and ethics. The ocean\'s stomach with fins.'],
    ['tier' => 4, 'animal' => 'Bull shark', 'consequence' => 'Swims up rivers into freshwater. It moves into your local canal. Forever.'],
    ['tier' => 4, 'animal' => 'Oceanic whitetip', 'consequence' => 'Follows shipwrecks and downed pilots patiently. The sailor\'s historical nightmare.'],
    ['tier' => 4, 'animal' => 'Black mamba', 'consequence' => 'Fastest venomous snake. \'Kiss of death\' for a reason. Chases when cornered. Owns records and obituaries.'],
    ['tier' => 4, 'animal' => 'Inland taipan', 'consequence' => 'One bite = venom for 100 people. Most toxic snake on land, in your shed.'],
    ['tier' => 4, 'animal' => 'Gaboon viper', 'consequence' => 'Longest fangs of any snake. Strikes from perfect leaf-litter camouflage. Invisible and fatal.'],
    ['tier' => 4, 'animal' => 'Fer-de-lance', 'consequence' => 'Most snakebite deaths in its range. Aggressive. Everywhere. Do not clear that brush.'],
    ['tier' => 4, 'animal' => 'Box jellyfish', 'consequence' => 'Venom stops your heart before you reach shore. 24 eyes and no brain. A drifting off-switch.'],
    ['tier' => 5, 'animal' => 'Blue whale', 'consequence' => 'Largest animal ever. Heart the size of a car. Blood vessels fit a child. You will never have an ocean. The ocean has a whale. The whale now owns your house.'],
    ['tier' => 5, 'animal' => 'Colossal squid', 'consequence' => 'Tentacles with rotating teeth-rings, eyes the size of dinner plates, from a depth where pressure destroys skeletons. Your house will become its lair.'],
    ['tier' => 5, 'animal' => 'Giant Pacific octopus', 'consequence' => 'Nine independent brains distributed across nine tentacles, each with its own hunger and vendetta. Will squirt the specific human who wronged it. It remembers your exact face.'],
    ['tier' => 5, 'animal' => 'Portuguese man o\' war', 'consequence' => 'Not one animal but four cooperating organisms voting unanimously to sting. Democracy made painful.'],
    ['tier' => 5, 'animal' => 'Siphonophore', 'consequence' => '40+ metre colonial organism longer than a blue whale made of thousands of coordinated polyps. It is not a pet. It is a *process* that owns you.'],
    ['tier' => 5, 'animal' => 'Coral reef', 'consequence' => 'Thousands of animals building rock over centuries. Outlasts nations. Will not remember you.'],
    ['tier' => 5, 'animal' => 'Bootlace worm', 'consequence' => '55+ metres of sentient noodle. Longest animal ever. The head and tail are philosophical concepts now.'],
    ['tier' => 5, 'animal' => 'Lion\'s mane jellyfish', 'consequence' => '30+ metre tentacles trailing invisible venom like a cape of pain. Silently fills your entire ocean.'],
    ['tier' => 5, 'animal' => 'Japanese spider crab', 'consequence' => 'Leg span 3.8m, bigger than a car. Claws fit humans inside. Your house is a rounding error.'],
    ['tier' => 5, 'animal' => 'Whale shark', 'consequence' => 'Bus-sized gentle giant needing six-tonne plankton rations daily and an entire ocean. Impossible.'],
    ['tier' => 5, 'animal' => 'Basking shark', 'consequence' => 'Swims with cavernous mouth open filtering wholes seas. You are not nutritionally relevant.'],
    ['tier' => 5, 'animal' => 'Manta ray', 'consequence' => 'Seven-metre wingspan, largest fish brain, glides in perfect silence. Too indifferent to your pet-ownership fantasies.'],
    ['tier' => 5, 'animal' => 'Ocean sunfish', 'consequence' => 'Looks like a swimming head. Weighs two tonnes. Lays 300 million eggs. Biologically absurd.'],
    ['tier' => 5, 'animal' => 'Sperm whale (deep)', 'consequence' => 'Dives 2km on one breath to fight giant squid in total darkness. You cannot follow. It doesn\'t want you there.'],
    ['tier' => 5, 'animal' => 'Greenland shark', 'consequence' => '400+ years old. Older than nations. Still eating things from the 1600s. Meat is toxic. Don\'t befriend immortality.'],
    ['tier' => 5, 'animal' => 'Tube worms of the vents', 'consequence' => 'Live in boiling vents eating chemicals, no mouth, no gut, indigestible. No way to feed it.'],
    ['tier' => 5, 'animal' => 'Yeti crab', 'consequence' => 'Farms bacteria on its own claws and harvests it. Solved agriculture before you did on its abdomen.'],
    ['tier' => 5, 'animal' => 'Immortal jellyfish colony', 'consequence' => 'Not just immortal. A colony of immortals. Divides infinitely. You\'ve signed up for exponential eternity.'],
    ['tier' => 5, 'animal' => 'Sponge (10,000 years old)', 'consequence' => 'Predates agriculture, civilization, writing. Will outlast you and your species.'],
    ['tier' => 5, 'animal' => 'Hydra', 'consequence' => 'Doesn\'t age. Regenerates from fragments. Owns immortality casually. Cut it in half and you\'ve doubled infinity.'],
    ['tier' => 5, 'animal' => 'Planarian flatworm', 'consequence' => 'Cut it and both halves become complete worms. Cut it into ten pieces and you own ten. Failure multiplies.'],
    ['tier' => 5, 'animal' => 'Tardigrade', 'consequence' => 'Survived space, radiation, vacuum. You cannot kill it. You cannot lose it. Already on Mars and in your DNA.'],
    ['tier' => 5, 'animal' => 'Nematode (10^18)', 'consequence' => 'Four out of five animals on Earth are roundworms. You already own uncountable billions. Congratulations.'],
    ['tier' => 5, 'animal' => 'Antarctic krill (400 trillion)', 'consequence' => 'Hold up the entire Southern Ocean food web. The swarm owns the ocean. The ocean owns you.'],
    ['tier' => 5, 'animal' => 'Locust swarm', 'consequence' => 'Single swarm covers hundreds of square km, eats entire countries\' crops. Don\'t start one. If one starts, go underground.'],
    ['tier' => 5, 'animal' => 'Army ant colony', 'consequence' => 'Millions acting as one predatory flood eating everything. A genocide made of mandibles. It will eat your house.'],
    ['tier' => 5, 'animal' => 'Coral polyp\'s cousin, the Venus flower basket', 'consequence' => 'Two shrimp live sealed inside for life in eternal married imprisonment. You\'d be intruding. The shrimp will judge you.'],
    ['tier' => 5, 'animal' => 'Blue whale\'s heartbeat', 'consequence' => 'Audible from two miles away. Frequencies you shouldn\'t hear vibrating organs you didn\'t know existed. A reminder that you are insignificant and will never escape this knowledge.'],
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
    $dir = getenv('KRAAS_DIR') ?: PILE_DATA_DIR;
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
 * Mirrors the lifetime stats and pile count into a small consolidated
 * JSON file (STATS_BACKUP_FILE, see config.php) alongside pile_dir()
 * itself — cheaper to grab for a manual backup than reading every
 * individual file. Throttled to STATS_BACKUP_MIN_INTERVAL so this
 * doesn't turn every request into an extra disk write; the live numbers
 * still come from stats_snapshot() and pile_dir().
 */
function stats_backup(): void
{
    $existing = @file_get_contents(STATS_BACKUP_FILE);
    if ($existing !== false) {
        $data = json_decode($existing, true);
        if (is_array($data) && isset($data['written_at'])
            && (time() - (int) $data['written_at']) < STATS_BACKUP_MIN_INTERVAL) {
            return;
        }
    }

    $stats = stats_snapshot();
    $dir   = dirname(STATS_BACKUP_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    @file_put_contents(STATS_BACKUP_FILE, json_encode([
        'written_at'     => time(),
        'written_at_iso' => gmdate('c'),
        'total_requests' => $stats['total_requests'],
        'unique_ips'     => $stats['unique_ips'],
        'piles_tracked'  => count(glob(pile_dir() . '/*.json') ?: []),
        'counters'       => $stats['counters'],
    ], JSON_PRETTY_PRINT));
}

/**
 * Two things happen here, both safe to repeat:
 *
 * 1. Real migration: anything still sitting in sys_get_temp_dir() . '/jar'
 *    — pile_dir()'s default before PILE_DATA_DIR moved the live store
 *    into the webspace — gets copied into pile_dir(), skipping any file
 *    that already exists there so newer webspace data is never clobbered
 *    by stale leftovers from the old location. Covers every pile and
 *    appendage file (*.json) plus the one file that isn't named *.json:
 *    _lifetime_stats (see stats_path()) — easy to miss since a plain
 *    glob for *.json skips right over it, which would otherwise silently
 *    drop the request/unique-IP/rocks-kicked history on this move.
 * 2. A consolidated snapshot of pile_dir()'s current contents (the live
 *    store either way) gets written to PILES_BACKUP_FILE — cheaper to
 *    restore from than hundreds of individual pile files. Filenames
 *    distinguish piles from appendages: a pile is a bare sha1(id).json,
 *    an appendage is fingers-sha1(id).json or toes-sha1(id).json (see
 *    pile_path() and appendage_path()).
 *
 * Both run on the same throttle as stats_backup() rather than once,
 * since the live store keeps changing under normal use — each pass just
 * re-does the sync and re-exports current contents, so nothing is lost
 * even if either directory changes between runs.
 */
function migrate_legacy_piles(): void
{
    $existing = @file_get_contents(PILES_BACKUP_FILE);
    if ($existing !== false) {
        $data = json_decode($existing, true);
        if (is_array($data) && isset($data['written_at'])
            && (time() - (int) $data['written_at']) < PILES_BACKUP_MIN_INTERVAL) {
            return;
        }
    }

    $liveDir = pile_dir();

    $legacyDir = sys_get_temp_dir() . '/jar';
    if (is_dir($legacyDir) && realpath($legacyDir) !== realpath($liveDir)) {
        $legacyFiles = glob($legacyDir . '/*.json') ?: [];
        $legacyStats = $legacyDir . '/_lifetime_stats';
        if (is_file($legacyStats)) {
            $legacyFiles[] = $legacyStats;
        }
        foreach ($legacyFiles as $legacyFile) {
            $target = $liveDir . '/' . basename($legacyFile);
            if (!is_file($target)) {
                @copy($legacyFile, $target);
            }
        }
    }

    $piles     = [];
    $fingers   = [];
    $toes      = [];

    foreach (glob($liveDir . '/*.json') ?: [] as $file) {
        $base = basename($file, '.json');
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }

        if (preg_match('/^[0-9a-f]{40}$/', $base) === 1) {
            $piles[$base] = $data;
        } elseif (preg_match('/^fingers-([0-9a-f]{40})$/', $base, $m) === 1) {
            $fingers[$m[1]] = $data['left'] ?? null;
        } elseif (preg_match('/^toes-([0-9a-f]{40})$/', $base, $m) === 1) {
            $toes[$m[1]] = $data['left'] ?? null;
        }
    }

    $dir = dirname(PILES_BACKUP_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    @file_put_contents(PILES_BACKUP_FILE, json_encode([
        'written_at'     => time(),
        'written_at_iso' => gmdate('c'),
        'piles'          => $piles,
        'fingers_left'   => $fingers,
        'toes_left'      => $toes,
    ], JSON_PRETTY_PRINT));
}

/** Does an IP fall inside a single IP or CIDR range? Handles v4 and v6. */
function ip_matches(string $ip, string $range): bool
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

function ip_trusted(string $ip): bool
{
    foreach (TRUSTED_PROXIES as $range) {
        if (ip_matches($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Detects, automatically and per request, whether Cloudflare (or another
 * trusted reverse proxy) is actually in front of this API right now —
 * there is no flag to keep in sync. CF-Connecting-IP is trusted only
 * when REMOTE_ADDR itself is inside TRUSTED_PROXIES, i.e. when something
 * on that list is what actually connected to PHP; otherwise REMOTE_ADDR
 * is the real visitor address and is used as-is. This is what stops
 * anyone from spoofing a pile ID (pounding, checking, or resetting an
 * IP that isn't theirs, dodging their own rate limit, or polluting the
 * leaderboard) with a forged header: the header only counts when the
 * connection it arrived on could actually carry it truthfully. If
 * Cloudflare is ever added or removed from in front of this API,
 * REMOTE_ADDR reflects that on the very next request — nothing here
 * needs to change.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if ($remote !== '' && ip_trusted($remote)) {
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
        if (is_string($cf) && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
            return $cf;
        }
    }

    return $remote !== '' ? $remote : 'anonymous';
}

/**
 * Anonymises an IP for public display: the final octet (or, for IPv6,
 * final hextet) is knocked off.
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
            $pile = ['litres' => 0.0, 'blows' => 0, 'since' => gmdate('c')];
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

/**
 * Lets the dumpsterfire.uk frontend call this API from the browser.
 */
function send_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: *');
}

function send(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Powered-By: spite');
    send_cors_headers();
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

function munition_arc(int $tier): string
{
    foreach (MUNITION_ARCS as $arc) {
        if ($tier >= $arc['from'] && $tier <= $arc['to']) {
            return $arc['name'];
        }
    }
    return 'unclassified';
}

function pet_tier(int $tier): array
{
    return PET_TIERS[$tier] ?? ['label' => 'unclassified', 'range' => 'unknown'];
}

/**
 * Pulls the first x.y.z-shaped number out of a GitHub release's "name" or
 * "tag_name" (tags here are inconsistent — "v1.05", "v1.0.5" — so this
 * normalises rather than trusting either verbatim).
 */
function extract_semver(?string $raw): ?string
{
    if ($raw !== null && preg_match('/\d+(?:\.\d+)+/', $raw, $m)) {
        return $m[0];
    }
    return null;
}

/**
 * The latest release version published on GitHub, cached for an hour so
 * this doesn't hit GitHub's API on every request (and so this server's
 * shared outbound IP doesn't run into its unauthenticated rate limit).
 * Written to RELEASE_CACHE_FILE (config.php), inside this app's own
 * webspace — Frontend/index.php keeps its own separate copy of this
 * same cache, since the two are different webspaces with their own
 * backup boundaries. Returns null if it can't be determined — network
 * failure, no releases, an unparseable tag.
 */
function latest_release_version(): ?string
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
        CURLOPT_HTTPHEADER     => ['User-Agent: chaos-api/1.0', 'Accept: application/vnd.github+json'],
    ]);
    $body = curl_exec($ch);
    $ok   = curl_errno($ch) === 0 && (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($ch);

    $version = null;
    if ($ok) {
        $json = json_decode((string) $body, true);
        if (is_array($json)) {
            $version = extract_semver($json['name'] ?? null) ?? extract_semver($json['tag_name'] ?? null);
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
 * Whether the "changelog is a tombstone" note should appear in GET /. It
 * hides itself the moment this API's own version pulls ahead of the
 * latest published GitHub release: at that point there is no matching
 * release to point people at yet, so linking "the latest release" would
 * show them something older than what's actually running — worse than
 * no note at all. It shows whenever a release is at least caught up (or
 * ahead), since only then is the link accurate.
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

    $latest = latest_release_version();
    if ($latest === null) {
        // Can't tell — assume this API isn't ahead rather than risk
        // hiding a note that's still accurate.
        return true;
    }

    return version_compare(APP_VERSION, $latest, '<=');
}

/* ------------------------------------------------------------------ *
 * Handlers
 * ------------------------------------------------------------------ */

function handle_index(): never
{
    $notes = [
        'Piles are files on disk and survive restarts, unlike morale.',
        'Tier 14 is the Moon. There is no tier 15.',
        'Pounding is rate-limited to once every 2s per pile. Push through it and the dirt guy quits.',
        'Any /unhinged request has a 1-in-10 chance of falling into the void instead. Just try again.',
    ];
    if (changelog_is_stale()) {
        $notes[] = 'The changelog is a 🪦 now. Check the latest release instead: https://github.com/MichelleFindlay/the-api-of-chaos/releases';
    }

    send(200, [
        'service' => 'The API of Chaos',
        'version' => APP_VERSION,
        'tagline' => 'Dismissal, at scale, with an SLA of none.',
        'endpoints' => [
            'GET /kick/rocks'        => 'Assigns a rock. Optional: ?tier=n, ?min=&max=',
            'GET /kick/rocks/tiers'  => 'The full scale, tier 1 through 14.',
            'GET /kick/munitions'    => 'Assigns an unintentionally-lost munition. Tells you the tier and the arc.',
            'GET /kick/munitions/tiers' => 'The full scale, tier 1 through 50, in five ten-tier arcs.',
            'GET|POST /pound/dirt'    => 'Adds to your pile. One pile per IP.',
            'GET /pound/dirt/status'  => 'Peek at the pile without pounding it.',
            'GET /pound/dirt/tiers'   => 'The full scale, fistful through second moon.',
            'GET /pound/dirt/leaderboard' => 'Top 20 piles, ranked. IPs shown with the final octet removed.',
            'DELETE /pound/dirt'      => 'Reset your pile.',
            'GET /excuses/teams'     => 'A reason not to join the call.',
            'GET /excuses/social'    => 'A reason not to attend, with tier.',
            'GET /excuses/oops'      => 'A reason it went wrong, with tier explanation.',
            'GET /excuses/ring-ring' => 'A reason you did not pick up.',
            'GET /excuses/late'      => "A reason you're late.",
            'GET /excuses/alibis'    => "A reason you weren't there.",
            'GET /ministry/gentle-correction' => 'Rolls a d6 against the Ministry\'s approved remedies, graded in newtons.',
            'GET /ministry/mandatory-pet-adoption' => 'Assigns a legally binding pet from 203 options, tiered by how badly it ends you.',
            'GET /cage/finger'       => 'Put your finger in the cage. 50 animals, 50/50 odds. Costs a finger if taken; once fingers run out, toes are next.',
            'GET /cage/fictional/finger' => 'Put your finger in the cage. 50 fictional creatures this time. Shares your finger/toe count with /cage/finger.',
            'GET /cage/finger/left'  => 'How many fingers and toes you have left, out of ' . FINGERS_START . ' each.',
            'GET /cage/finger/reset' => 'Pray to the gods of the holy hairy toe for ' . FINGERS_START . ' fingers and ' . TOES_START . ' toes again.',
            'GET /unhinged/8ball'    => 'Shake it. It answers, unreliably.',
            'GET /unhinged/optimism' => 'An unearned, unsupported dose of positivity.',
            'GET /unhinged/pessimism' => 'An unearned, unsupported dose of dread.',
            'GET /unhinged/advice'   => 'Advice that applies to almost every situation.',
            'GET /unhinged/non-committal' => 'A refusal to answer, dressed up fifty different ways.',
            'GET /unhinged/optimistic-dooom' => 'The end of everything, relentlessly reframed as good news. Tiered.',
            'GET /unhinged/turn-it-upside-down' => 'Flip a random item. Physics declines to attend.',
            'GET /unhinged/solid-suddenly-liquid' => 'A solid, liquefied. Fifty of them, tiered by regret.',
            'GET /unhinged/solid-suddenly-gelatinous' => 'A solid, turned to jelly. Fifty of them, tiered by wobble.',
            'GET /unhinged/choose-your-duck' => 'A bath duck, and what it costs you. Fifty of them, S-Tier to F-Tier.',
            'GET /unhinged/gravity-resigned' => 'Gravity has quit. time to float.',
            'GET /unhinged/vengeful-weather' => 'The sky, personally offended.',
            'GET /unhinged/wrongfall' => 'Clouds went feral. Fifty of them, tiered S to F.',
            'GET /healthz'           => 'Liveness, plus lifetime request, unique-IP, and rocks-kicked counts.',
        ],
        'notes' => $notes,
        'source'  => 'https://github.com/MichelleFindlay/the-api-of-chaos',
        'license' => 'GPL-3.0',
    ]);
}

function handle_mine_turtle(): never
{
    $id   = client_ip();
    $path = pile_path($id);
    if (is_file($path)) {
        unlink($path);
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Powered-By: spite');
    send_cors_headers();

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
    send_cors_headers();

    echo <<<'ART'
    .          '            .          `
        `           .          '        .

                  ::----::
                ::---==---::
              ::---======---::
            ::---===++++===---::
          ::---===++####++===---::
        ::---===++###@@###++===---::
      ::---===++###@@  @@###++===---::
    ::---===++###@@      @@###++===---::
      ::---===++###@@  @@###++===---::
        ::---===++###@@###++===---::
          ::---===++####++===---::
            ::---===++++===---::
              ::---======---::
                ::---==---::
                  ::----::

        '          .        `          .
            .          '        .          '

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

function handle_kick_munitions(): never
{
    $item = pick(MUNITIONS);

    send(200, [
        'instruction' => 'Kick unintentionally-lost munitions. This was your idea.',
        'munition' => [
            'tier'   => $item['tier'],
            'of'     => count(MUNITIONS),
            'name'   => $item['name'],
            'remark' => $item['remark'],
        ],
        'arc' => munition_arc($item['tier']),
    ], ['X-Kick-Munitions' => 'tier-' . $item['tier']]);
}

function handle_munitions_tiers(): never
{
    send(200, [
        'scale' => 'spent brass casing -> the fuze wakes up -> designed for exactly this -> older than everyone in the room -> measured in treaties',
        'arcs' => array_map(static fn (array $arc): array => [
            'range' => $arc['from'] . '-' . $arc['to'],
            'name'  => $arc['name'],
        ], MUNITION_ARCS),
        'tiers' => array_map(static fn (array $m): array => [
            'tier' => $m['tier'],
            'name' => $m['name'],
            'arc'  => munition_arc($m['tier']),
        ], MUNITIONS),
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
    $id = client_ip();

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
    $id   = client_ip();
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
    $id      = client_ip();
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

function handle_excuses_alibis(): never
{
    send(200, [
        'instruction' => 'Account for your whereabouts.',
        'reason'      => pick(ALIBI_EXCUSES),
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

function handle_mandatory_pet_adoption(): never
{
    $entry = pick(MANDATORY_PETS);
    $tier  = pet_tier($entry['tier']);

    send(200, [
        'instruction'  => 'Surrender to the whisker regime.',
        'animal'       => $entry['animal'],
        'consequence'  => $entry['consequence'],
        'tier'         => $entry['tier'],
        'tier_label'   => $tier['label'],
        'tier_range'   => $tier['range'],
    ]);
}

function handle_cage_finger(): never
{
    $id      = client_ip();
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
    $id      = client_ip();
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

function handle_optimistic_doom(): never
{
    $tier = array_rand(OPTIMISTIC_DOOM);

    send(200, [
        'instruction' => 'Everything is fine. extremely fine.',
        'answer'      => pick(OPTIMISTIC_DOOM[$tier]),
        'tier'        => $tier,
    ]);
}

function handle_turn_upside_down(): never
{
    $tier  = array_rand(TURN_UPSIDE_DOWN);
    $entry = pick(TURN_UPSIDE_DOWN[$tier]);

    send(200, [
        'instruction' => 'Turn it upside down.',
        'item'        => $entry['item'],
        'effect'      => $entry['effect'],
        'tier'        => $tier,
    ]);
}

function handle_solid_suddenly_liquid(): never
{
    $entry = pick(SOLID_SUDDENLY_LIQUID);

    send(200, [
        'instruction' => 'It was solid. Now it is not.',
        'solid'       => $entry['solid'],
        'effect'      => $entry['effect'],
        'tier'        => $entry['tier'],
    ]);
}

function handle_solid_suddenly_gelatinous(): never
{
    $entry = pick(SOLID_SUDDENLY_GELATINOUS);

    send(200, [
        'instruction' => 'It was solid. Now it jiggles.',
        'solid'       => $entry['solid'],
        'effect'      => $entry['effect'],
        'tier'        => $entry['tier'],
    ]);
}

function handle_choose_your_duck(): never
{
    $entry = pick(DUCKS);

    send(200, [
        'instruction'  => 'Choose your duck.',
        'duck'         => $entry['duck'],
        'consequence'  => $entry['consequence'],
        'tier'         => $entry['tier'],
    ]);
}

function handle_gravity_resigned(): never
{
    $entry = pick(GRAVITY_RESIGNED);

    $response = [
        'instruction'      => 'Gravity has resigned. Effective immediately.',
        'item'             => $entry['item'],
        'effect'           => $entry['effect'],
        'survival_chance'  => $entry['survival_chance'] . '%',
        'tier'             => $entry['tier'],
    ];

    if (isset($entry['note'])) {
        $response['note'] = $entry['note'];
    }

    send(200, $response);
}

function handle_vengeful_weather(): never
{
    $system = array_rand(VENGEFUL_WEATHER);

    send(200, [
        'instruction' => 'Step outside. Or don\'t. It knows either way.',
        'forecast'    => pick(VENGEFUL_WEATHER[$system]),
        'system'      => $system,
    ]);
}

function handle_wrongfall(): never
{
    $entry = pick(WRONGFALL);

    send(200, [
        'instruction' => 'Look up. Regret it immediately.',
        'material'    => $entry['material'],
        'effect'      => $entry['effect'],
        'tier'        => $entry['tier'],
    ]);
}

function handle_fingers_left(): never
{
    $id      = client_ip();
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
    $id      = client_ip();
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

// CORS preflight: answered directly, before it touches stats or routing.
if ($method === 'OPTIONS') {
    http_response_code(204);
    send_cors_headers();
    exit;
}

// Every request counts towards lifetime stats, surfaced at GET /healthz.
stats_record(client_ip());

// Mirrors those stats into the webspace, throttled — see stats_backup().
stats_backup();

// Same for the pile/appendage data itself — see migrate_legacy_piles().
migrate_legacy_piles();

// One request in ten under /unhinged never makes it to a handler.
void_check($path);

match (true) {
    $method === 'GET' && $path === '/'                    => handle_index(),
    $method === 'GET' && $path === '/kick/rocks'          => handle_kick_rocks(),
    $method === 'GET' && $path === '/kick/rocks/tiers'    => handle_tiers(),
    $method === 'GET' && $path === '/kick/munitions'      => handle_kick_munitions(),
    $method === 'GET' && $path === '/kick/munitions/tiers' => handle_munitions_tiers(),
    in_array($method, ['GET', 'POST'], true)
        && $path === '/pound/dirt'                         => handle_pound_dirt(),
    $method === 'DELETE' && $path === '/pound/dirt'        => handle_pile_reset(),
    $method === 'GET' && $path === '/pound/dirt/status'    => handle_pile_status(),
    $method === 'GET' && $path === '/pound/dirt/tiers'     => handle_dirt_tiers(),
    $method === 'GET' && $path === '/pound/dirt/leaderboard' => handle_leaderboard(),
    $method === 'GET' && $path === '/excuses/teams'       => handle_excuses_teams(),
    $method === 'GET' && $path === '/excuses/social'      => handle_excuses_social(),
    $method === 'GET' && $path === '/excuses/oops'         => handle_excuses_oops(),
    $method === 'GET' && $path === '/excuses/ring-ring'    => handle_excuses_ring_ring(),
    $method === 'GET' && $path === '/excuses/late'          => handle_excuses_late(),
    $method === 'GET' && $path === '/excuses/alibis'        => handle_excuses_alibis(),
    $method === 'GET' && $path === '/ministry/gentle-correction' => handle_gentle_correction(),
    $method === 'GET' && $path === '/ministry/mandatory-pet-adoption' => handle_mandatory_pet_adoption(),
    $method === 'GET' && $path === '/cage/finger'          => handle_cage_finger(),
    $method === 'GET' && $path === '/cage/fictional/finger' => handle_cage_finger_fictional(),
    $method === 'GET' && $path === '/cage/finger/left'     => handle_fingers_left(),
    $method === 'GET' && $path === '/cage/finger/reset'    => handle_fingers_reset(),
    $method === 'GET' && $path === '/unhinged/8ball'        => handle_eight_ball(),
    $method === 'GET' && $path === '/unhinged/optimism'     => handle_optimism(),
    $method === 'GET' && $path === '/unhinged/pessimism'    => handle_pessimism(),
    $method === 'GET' && $path === '/unhinged/advice'       => handle_advice(),
    $method === 'GET' && $path === '/unhinged/non-committal' => handle_non_committal(),
    $method === 'GET' && $path === '/unhinged/optimistic-dooom' => handle_optimistic_doom(),
    $method === 'GET' && $path === '/unhinged/turn-it-upside-down' => handle_turn_upside_down(),
    $method === 'GET' && $path === '/unhinged/solid-suddenly-liquid' => handle_solid_suddenly_liquid(),
    $method === 'GET' && $path === '/unhinged/solid-suddenly-gelatinous' => handle_solid_suddenly_gelatinous(),
    $method === 'GET' && $path === '/unhinged/choose-your-duck' => handle_choose_your_duck(),
    $method === 'GET' && $path === '/unhinged/gravity-resigned' => handle_gravity_resigned(),
    $method === 'GET' && $path === '/unhinged/vengeful-weather' => handle_vengeful_weather(),
    $method === 'GET' && $path === '/unhinged/wrongfall'   => handle_wrongfall(),
    $method === 'GET' && $path === '/healthz'             => handle_healthz(),
    default => send(404, [
        'error'  => 'No such service.',
        'remark' => 'There is, however, a rock. See GET /kick/rocks.',
    ]),
};
