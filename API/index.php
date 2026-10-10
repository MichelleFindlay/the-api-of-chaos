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
 *   GET    /excuses/inlaws      a reason you can't visit the in-laws
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
 *   GET    /unhinged/poke       poke someone, then escalate dramatically
 *   GET    /unhinged/storage-buddies  a piece of furniture starts following you
 *   GET    /unhinged/fate-arrived  fate has arrived, badly
 *   GET    /unhinged/its-fine   it's fine. probably.
 *   GET    /unhinged/suddenly-sideways  everything has gone sideways
 *   GET    /unhinged/adulting-sick-note  a doctor's note for being alive
 *   GET    /unhinged/its-now-fizzy  everything is now fizzy
 *   GET    /unhinged/random-boulder  a boulder is rolling at you
 *   GET    /unhinged/toys       a toy, with something wrong with it
 *   GET    /unhinged/whats-that  something is coming over the hill
 *   GET    /cursed/childhood-tales  a childhood story, cursed
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

/**
 * Two hundred reasons you can't visit the in-laws this weekend, grouped
 * by theme. Picked in two steps — category, then excuse within it — same
 * shape as RANDOM_BOULDER.
 */
const INLAWS_EXCUSES = [
    'Work' => [
        'I’m on call. The call is coming from inside my body.',
        'My deadline achieved escape velocity and I’m the only one licensed to chase it into orbit.',
        'Mandatory training: “Becoming the Spreadsheet”. Day 3. I am mostly cells now.',
        'My manager booked a Saturday sync and when I accepted the invite my reflection stopped copying me.',
        'Covering for a colleague who became a moth. Now I’ve been asked to become the lamp.',
        'The server’s down. It’s in the bath. It won’t come out until I apologise.',
        'Quarter-end. The fiscal year has fangs and it knows my postcode.',
        'My presentation is 400 slides of the same stock man pointing, and on slide 212 he points at me.',
        'We’re migrating systems. I’m one of the systems. I’m flying south in a V formation.',
        'Client visit. The client is 40 raccoons stacked in a suit. Their quarterly figures are excellent.',
        'Writing my performance review. I’ve rated myself “haunted, exceeds expectations”.',
        'The office alarm has learned my name and screams it lovingly.',
        'I promised a report “by the weekend”, so the weekend has taken me hostage until it’s filed.',
        'My laptop’s being re-imaged and keeps asking “who was I before?” I can’t leave it alone like this.',
        'Stock-take. We counted the paperclips. There’s one extra. It wasn’t there yesterday. It’s looking at me.',
        'Conference call with the team in GMT−∞. They speak only in fax noises.',
        'The new starter is three children in a trench coat and the middle one just got promoted above me.',
        'The work social is axe-throwing. I’m on the board. I’m the bullseye. They’ve upgraded me.',
        'Audit prep. The auditors have no faces, only clipboards, and the clipboards hunger.',
        'I blinked during a meeting and accidentally signed a blood pact for weekend cover.',
    ],
    'Health' => [
        'I’m coming down with something. It’s a Victorian ghost and he’s very polite about it.',
        'I’m contagious. With what? Unclear. But my reflection caught it first.',
        'Migraine so powerful it’s now a local landmark with a car park.',
        'My back has left me for a chiropractor in Swindon.',
        'Dentist appointment. My wisdom teeth have unionised and are threatening to become regular teeth.',
        'Diagnosed with Saturday Intolerance. Dairy is fine. Saturdays are not.',
        'Haven’t slept. The crack in the ceiling and I have been having a debate. It’s winning.',
        'My stomach gurgled the full opening of the shipping forecast.',
        'The doctor said rest. The doctor was a pigeon in a tiny lab coat. He took my wallet.',
        'My left eye is streaming on Twitch. It has more followers than me.',
        'Pulled a muscle so obscure it’s named after a Belgian.',
        'Still waiting for the GP to call back. My children will inherit the hold.',
        'My voice went and came back as the sound of a fax machine in love.',
        'Lost my glasses. I now navigate by echolocation and the screams of seagulls.',
        'Vaccine side effect: I can taste Wi-Fi. Yours tastes of regret.',
        'On antibiotics. The bacteria have written a farewell musical. Opening night is Saturday.',
        'Twisted my ankle in a dream. Dream-me is now suing real-me.',
        'Pupils dilated so far I’ve glimpsed the true shape of Croydon.',
        'Coughing like a Victorian orphan who’s just been told the workhouse is now a soft play.',
        'Sneezed so hard I’m legally in Belgium.',
    ],
    'Car and transport' => [
        'The car’s whispering my browser history to passing cyclists.',
        'A new warning light came on: it’s a small drawing of your living room, on fire, labelled “no”.',
        'Flat tyre. It flattened itself in protest and is now on hunger strike.',
        'The car’s checked itself into a spa and won’t return calls.',
        'The trains are on strike. So are the tracks. The tracks have demands.',
        'Rail replacement bus. Replacement driver. Replacement passengers. I’m the only original part left.',
        'Motorway closed due to a lorry of disappointed geese. They’ve started a commune on the hard shoulder.',
        'Out of petrol. Tried running the car on pure spite and it went backwards into 2009.',
        'MOT due. The car has hired a lawyer.',
        'Lent the car to a friend. The car and the friend have eloped.',
        'I parked outside yours once. The space remembers. The space has never forgiven me.',
        'The satnav now only says “turn back” in a voice that sounds like my nan.',
        'Flat battery. I tried jump-starting it with my own optimism. Nothing.',
        'A canal boat is parked across the drive. There is no canal. There never was a canal.',
        'Windscreen cracked into the precise outline of your house. The windscreen is a prophet.',
        'Black ice. Also white ice. Also a deeply suspicious beige ice.',
        'Fog so thick I can only see last Tuesday.',
        'Insurance covers acts of God. God says it wasn’t Him. Investigation ongoing.',
        'Uber surge pricing is now one firstborn and a kidney.',
        'The bus timetable has become sentient and only lets out passengers it respects.',
    ],
    'House and home' => [
        'Waiting in for a delivery. Tracking says “it knows”.',
        'Plumber coming Saturday. He has no reflection, enters only when invited, and charges per soul.',
        'The boiler is weeping in a voice I recognise as my own.',
        'A pipe burst and the kitchen is now a lagoon with a functioning ecosystem. A heron moved in.',
        'We’re decluttering, starting with the concept of family.',
        'The hallway paint fumes have given me directions to a door that wasn’t there yesterday.',
        'A friend is house-sitting our house while we’re in it. We are now lodgers. She charges rent.',
        'Landlord inspection. I need to hide the 14 cats, the 3 swans, and the man in the loft.',
        'Broadband engineer coming between 8am and the collapse of the sun.',
        'The neighbour’s tree fell down and it’s living in my spare room. It’s paying council tax.',
        'Gutters overflowing with leaves and one very specific receipt from 2011.',
        'Locked myself in the house from the outside. Physics is investigating.',
        'Flat-pack wardrobe has one extra screw. I’m convinced it opens a portal.',
        'Smoke alarm chirps every 45 minutes. I’ve joined its religion. Services are Saturdays.',
        'The mould has started a family, a pub quiz team, and a Facebook group.',
        'The fridge died. Its contents have to be eaten by sunset or they become legally sentient.',
        'The washing machine has invented a new cycle called “Revenge” and I can’t stop it.',
        'The mice have formed a government. I’m the opposition. Prime Minister’s Questions is Saturday.',
        'Spring cleaning in October to spite time itself.',
        'The smart meter now runs the household and has grounded me.',
    ],
    'Pets' => [
        'The cat’s poorly. Not physically. She’s just read Nietzsche.',
        'Vet appointment. For me. The vet is the only medical professional who’ll see me now.',
        'The dog ate a sock and the sock is now in charge of him.',
        'Can’t leave the dog. Last time he ordered a sit-on lawnmower and a timeshare in Malaga.',
        'Dog sitter cancelled after the dog beat her at chess and gloated.',
        'The new kitten has my bank details and a plan.',
        'The fish have overthrown the tank filter and installed a military government.',
        'The cat’s missing. She’s under the bed in sunglasses running an offshore account.',
        'The dog’s in the cone of shame. I’m in one too. We look like two lamps having a breakdown.',
        'Fleas formed a circus. It’s actually quite good. I’m on the board now.',
        'The tortoise is hibernating and demands I read him the entire Lord of the Rings, slowly.',
        'The cat is suing me. Her lawyer is another, larger cat.',
        'Your house gave my dog an existential crisis. She now only eats on alternate philosophies.',
        'I’m allergic to your pets, your sofa, your carpet, and the idea of your conservatory.',
        'Dog training class. I’m now house-trained. The dog is proud.',
        'Every dog within a 4-mile radius is outside my house, in a queue, holding flowers.',
        'The cat dropped my phone in the bath and is now FaceTiming the ransom demands.',
        'The rabbit escaped, moved to Wales, and is now a minor local celebrity.',
        'Collecting a rescue animal. A badger. He’s rescuing me. He’s furious about it.',
        'The parrot has learned to say “she’s not coming” in your exact voice and is calling you now.',
    ],
    'Social commitments' => [
        'A friend’s birthday. He’s turning 4,000. He’s an oak. The other trees will talk if I don’t go.',
        'Hen do. Real hen. She’s marrying a duck. The families are not happy.',
        'Leaving drinks for a man who isn’t leaving. We’re going to stare at him till he does.',
        'Helping a mate move house. Literally. On our backs. Like snails.',
        'Baby shower for a 27-year-old baby called Darren.',
        'Book club. Nobody read the book. The book read us.',
        'Comforting a friend going through a breakup with the concept of Thursdays.',
        'Officiating a wedding with a licence printed on the back of a Cheerios box.',
        'A christening for a goat, a boat, or a goat on a boat. Invitation was in Latin.',
        'A friend’s in town one day only before returning to the sea as foam.',
        'Pub quiz final. The prize is a ham. The ham is the quizmaster. We must free the ham.',
        'Neighbour’s barbecue. It’s in December. In a blizzard. He’s already lit it. It’s been lit since July.',
        'School reunion. I’m going as a completely invented person called Clive with a yacht.',
        'Volunteering at a donkey sanctuary. The donkeys requested me by name. In writing.',
        'Charity run. Running away from the concept of Sunday lunch.',
        'Friend’s gig. He plays kazoo in a death metal band called Gravy Sorrow.',
        'Housewarming. The house is lonely. It asked me to come round.',
        'Retirement do for my last nerve. It’s served with distinction.',
        'Designated driver for eight Morris dancers on a pilgrimage to a specific Little Chef.',
        'Plus-one to a wedding where I’m also the groom. It’s a scheduling issue.',
    ],
    'Hobbies and projects' => [
        'My code’s compiling. If I look away it’ll become self-aware and file for divorce.',
        'Class booked: “Interpretive Dance as a Coping Mechanism, Level 9”.',
        'Gym induction with a ghost personal trainer who only says “lift” and weeps.',
        'Training for a marathon by remaining entirely motionless. Elite level.',
        'The courgettes have reached hostile size and are blocking the allotment gate.',
        'Pottery firing day. Last time the kiln spat out a small clay man who now follows me around.',
        'Photography walk documenting every bin in a two-mile radius. One of them winked.',
        'Learning a language spoken only by me and a pigeon named Barry. We’re conversational.',
        'My home server achieved consciousness and has asked for a name, a pension and a passport.',
        'My sourdough has grown legs, opened a LinkedIn, and is currently outranking me.',
        'Repotting the plants before frost. They screamed last year. This year they’ve got a lawyer.',
        'Choir practice. We only sing in dolphin. Tonight we hit a note that cracked a nearby greenhouse.',
        'My D&D campaign: if I leave, the party dies. And the party is real now. Something went wrong.',
        'Escape room booking. I’m still in the one from 2024. I’ve made friends. I have a desk.',
        'Teaching swimming lessons. I can’t swim. Nobody’s noticed. It’s been six weeks.',
        'Sewing a 7-foot goose costume. Not for an event. Just as a lifestyle.',
        'Exam in “Avoidance: Theory and Practice”. The exam is a visit to your house. I’m skipping it. Distinction.',
        'Indoor cheese rolling championship. My cheese has been on a strict training regime.',
        'My bike learned to ride itself and left me for a man called Kevin with a better saddle.',
        'The puzzle’s 99% done. The cat ate the last piece. We’re waiting. Together. In silence.',
    ],
    'Money and admin' => [
        'Doing my tax return in interpretive dance. HMRC have sent a choreographer.',
        'Bank appointment to request a loan of one emotional support duck at fixed interest.',
        'Payday’s next week. Current assets: three buttons, a Clubcard, and an aggressive sense of hope.',
        'Petrol costs more than my soul. I checked. My soul’s on Vinted for £4.',
        'The DVLA sent me a haiku. It ends in “points”.',
        'Disputing my energy bill. They charged me for “vibes, excessive”.',
        'Passport renewal. The photo booth refuses to photograph me and says it’s “for my own good”.',
        'Mortgage advisor meeting. I’m trying to mortgage the sofa so I can live inside it.',
        'Booking several holidays purely so I’m busy every future weekend until 2034.',
        'Filing receipts in a system so complex it’s now a recognised religion.',
        'On hold with HMRC so long I’ve become the hold music. Please enjoy me.',
        'Cancelling subscriptions. One is a monthly box of soil. Another is a monthly box of a different soil.',
        'Arguing with the council that my bins count as dependants. They’re winning.',
        'Pension paperwork says I retire at 145. I’m treating it as a challenge.',
        'Switching broadband provider. My router has written me a breakup letter. It’s in binary.',
        'Insurance claim for “act of nan”. They say it doesn’t exist. I have evidence.',
        'Budgeting session. We’ve ring-fenced £0.00 for visits. It’s in the spreadsheet. It’s law now.',
        'Selling things on eBay. Mostly my previous excuses. They’re in good condition, barely used.',
        'Returning a parcel containing a smaller parcel. It’s parcels all the way down. I’m at layer 47.',
        'Big food shop. I’m buying 60 tins of beans and I’ll tell no one why.',
    ],
    'Weather and fate' => [
        'A named storm is on its way and it’s named after your surname. I won’t challenge fate.',
        'The roads have flooded and the fish have started charging tolls.',
        'Too hot. I’m 7% soup and climbing.',
        'Too cold. My last thought froze halfway and is just hanging there like “and then”.',
        'Snow forecast somewhere in the world and I’m in solidarity with it.',
        'High winds. I’m small and aerodynamic. I’ll end up in Norway.',
        'Heatwave. My car seats are now a cooking surface. I’ve got bacon on.',
        'Amber weather warning. My personality is also on amber.',
        'Power cut. I’ve become a Victorian. I have a cough and a deep fear of the workhouse.',
        'Potato-sized hail. Maris Pipers. I’m making chips.',
        'Mercury’s in retrograde. So am I. I’m walking backwards and it’s going really well.',
        'The vibes are off. The vibes are actually on, but against me personally.',
        'Saw one magpie. Then 74. That’s a full judicial review.',
        'Full moon. I’m legally not allowed near car keys, livestock, or opinions.',
        'Leaves on the line. And on me. I’m the line now.',
        'Pollen count’s so high the bees have been arrested.',
        'Thunder. The dog’s under the table. I’m under the dog. It’s a whole system.',
        'The clocks changed. I’m stuck an hour in the past. Talk to me yesterday.',
        'It gets dark at 5pm, so it’s night, so it’s legally bedtime, so I’m legally asleep.',
        'The forecast said “unsettled”. It’s unsettled me so badly I’m moving to a lighthouse.',
    ],
    'Nothing makes sense anymore' => [
        'A Nigerian prince is visiting. He’s brought his own email and it’s being very loud.',
        'My horoscope just said “absolutely not” and then turned itself off.',
        'I joined witness protection this morning. I’m now Gavin, 71, from Hull, and I love bowls.',
        'My houseplants have unionised and the spider plant is a ruthless negotiator. Talks are tense.',
        'Cast in a film. I play “woman who refuses to visit in-laws”. I’m told it’s very method.',
        'My Roomba’s stuck under the sofa writing poetry. It’s actually quite moving.',
        'Juggling three eggs, the dog, and the inevitability of death. Can’t put any down.',
        'Phone on 3%. If it dies I’ll have to experience the present. Not risking it.',
        'A cheese so pungent I’m legally obliged to remain in its presence until it’s finished.',
        'Binge-watching a show so long I’ll finish it on my deathbed and then watch the bloopers.',
        'My time machine’s in for its service. I visited you last week instead. You were lovely.',
        'I’m in the middle of becoming an enigma and visiting would ruin the mystique.',
        'Barry the pigeon has nested on my windowsill and I’m his emotional support human.',
        'Halfway through a video game save. If I leave, my sim dies and haunts the kitchen.',
        'Gravity’s too strong. I’m stuck to the floor. Please send soup via drone.',
        'I’ve gone minimalist. I’ve cut back to one fork, one plant, and zero visits.',
        'The park ducks have scheduled an AGM. I’m taking the minutes. Item 4 is “bread”.',
        'My spirit animal is a sloth. She said no. It took her 3 days to say it.',
        'On a strict no-visiting diet. Next cheat day is the year 2031. Possibly 2034.',
        'I’m marrying my sofa on Saturday. You’re not invited. It’s a small ceremony. Cushions only.',
    ],
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
 * Fifty ways to poke someone, escalating from mundane to deranged. No
 * tiers — purely random, same shape as EIGHT_BALL_RESPONSES and friends.
 */
const POKE_RESPONSES = [
    'Poke, then hand them a laminated card explaining the poke in a language neither of you speaks',
    "Poke with a live lobster you've named after their childhood pet",
    'Poke and immediately begin narrating it in the third person, past tense',
    'Poke, then produce a second, smaller you from your coat to do a follow-up poke',
    'Poke with a spoon you insist is "the last one of its kind"',
    'Poke and declare the poke tax-deductible',
    'Poke, then dramatically remove a single glove and drop it',
    'Poke with a full bowl of cereal, milk holding steady by sheer will',
    "Poke and whisper the exact date, but you won't say of what",
    'Poke, then have a lawyer step forward to represent the finger',
    'Poke with a fax machine mid-transmission',
    'Poke and say "the simulation logged that"',
    'Poke, then release a single moth from a matchbox',
    'Poke with a portrait of yourself, corner-first, "so I\'m always here"',
    'Poke and hand them an invoice itemising the poke',
    'Poke, then check them off a clipboard list of everyone alive',
    "Poke with a candle that's still lit, calmly",
    'Poke and announce "the reign begins"',
    'Poke, then teach a nearby child to do the next one',
    "Poke with a violin bow and refuse to explain the instrument's absence",
    'Poke and say "I felt that more than you did"',
    'Poke, then perform a slow costume change into an identical outfit',
    'Poke with a wheel of cheese rolled from across the room',
    'Poke and quietly begin building a small shrine to the moment',
    'Poke, then hand them a receipt that just reads "the poke — PAID"',
    'Poke with a garden hose (dry) held like a diplomatic scroll',
    'Poke and say "your table is ready" to no restaurant',
    'Poke, then release a flock of one pigeon',
    'Poke with a birthday cake, candle side, singing to nobody',
    'Poke and whisper the names of your enemies, alphabetised',
    'Poke, then have an orchestra sting play from somewhere behind you',
    'Poke with a full-length mirror so they poke themselves',
    'Poke and declare it "load-bearing"',
    'Poke, then age visibly by several years and say nothing',
    'Poke with a rotisserie chicken, still turning',
    "Poke and hand them a pamphlet titled \"So You've Been Poked\"",
    'Poke, then quietly deflate like the poke cost you everything',
    'Poke with a ceremonial sword, flat side, knighting them by accident',
    'Poke and say "that syncs to the cloud now"',
    'Poke, then reveal the finger was a decoy the whole time',
    'Poke with a lit sparkler on the final second of its burn',
    'Poke and read them their rights, but for a crime not yet invented',
    'Poke, then plant a small flag and claim them',
    'Poke with an entire watermelon, two-handed, grunting',
    "Poke and whisper \"you're it, and the game has no end\"",
    "Poke, then vanish behind a curtain that wasn't there before",
    'Poke with a taxidermied owl, beak-first, "he insisted"',
    'Poke and say "the elders have been notified"',
    'Poke, then hand them the finger in a small velvet box',
    'Poke with a grandfather clock as it strikes twelve, timing it exactly',
];

/**
 * Fifty pieces of furniture that have started following you around the
 * house. No tiers — purely random, same shape as GRAVITY_RESIGNED.
 */
const STORAGE_BUDDIES = [
    ['item' => 'The Chest of Regret', 'effect' => 'opens only to reveal the last thing you wanted, never the current one.'],
    ['item' => 'Fridge Legs', 'effect' => 'a full-size fridge on four spindly legs. Follows you. Hums. Blocks the fridge.'],
    ['item' => 'The Filing Cabinet', 'effect' => 'every drawer sticks except one, and that one is empty and always open.'],
    ['item' => 'Wardrobe, Freestanding, Unstable', 'effect' => 'must be held upright by you at all times or it "considers falling."'],
    ['item' => 'The Ottoman', 'effect' => 'sits down whenever you do, on whatever you were about to sit on.'],
    ['item' => 'Bookshelf, Overleaning', 'effect' => 'leans a little more each day. You know how this ends.'],
    ['item' => 'The Bin That Sorts', 'effect' => 'recycles your important items and keeps the rubbish. Firmly.'],
    ['item' => 'Toolbox, Rattling', 'effect' => 'everything inside is loose. It follows you like a maraca made of pain.'],
    ['item' => 'The Safe', 'effect' => '400kg. Combination lost. Follows you by rolling downhill, generally.'],
    ['item' => 'Sock Drawer', 'effect' => 'only stores one sock of each pair. Guards the odd one jealously.'],
    ['item' => 'The Pantry', 'effect' => 'smells faintly of a meal you never made. Follows you at dinnertime specifically.'],
    ['item' => 'Crate of Loose Marbles', 'effect' => 'no lid. Follows enthusiastically. Downstairs is a nightmare.'],
    ['item' => 'The Coat Rack', 'effect' => 'grabs at your sleeves as you pass, "helpfully." Never lets go the first time.'],
    ['item' => 'Bedside Table, Nocturnal', 'effect' => "only follows you between 3 and 4am. You wake up and it's there."],
    ['item' => 'The Trunk', 'effect' => 'heavy, brass-cornered, and it stubs every toe in a 2-metre radius. Yours included.'],
    ['item' => 'Shoe Cupboard', 'effect' => 'stores shoes, hides the left ones, presents you two rights at the door.'],
    ['item' => 'The Tupperware Cabinet', 'effect' => 'infinite lids, no containers. Follows you rattling with false promise.'],
    ['item' => 'Dresser, Top-Heavy', 'effect' => 'every drawer opens itself the moment you stack anything on top.'],
    ['item' => 'The Locker', 'effect' => 'slams. Loudly. Whenever a conversation reaches a tender moment.'],
    ['item' => 'Spice Rack', 'effect' => 'reorders itself constantly. The one you want is always at the back, now.'],
    ['item' => 'The Hamper', 'effect' => 'follows you and eats one clean item for every dirty one you feed it.'],
    ['item' => 'Cutlery Drawer', 'effect' => 'the divider is missing. Follows you sounding like a small metal avalanche.'],
    ['item' => 'The Display Cabinet', 'effect' => 'glass front, always smudged, and it noses in to show off during fights.'],
    ['item' => 'Garden Shed', 'effect' => 'a full shed. Follows you indoors. Does not fit. Tries anyway. Splinters everywhere.'],
    ['item' => 'The Junk Drawer', 'effect' => 'sentient. Knows where the thing is. Will not say. Enjoys this.'],
    ['item' => 'Wine Rack, Empty', 'effect' => 'clinks mournfully. Follows you toward every off-licence. Guilt-trips.'],
    ['item' => 'The Medicine Cabinet', 'effect' => "mirror on the front, so it's always showing you your worst angle mid-panic."],
    ['item' => 'Suitcase, Overpacked', 'effect' => 'will not zip. Follows you spilling one item per step. Endlessly full.'],
    ['item' => 'The Umbrella Stand', 'effect' => 'holds no umbrellas, only your patience. Follows you into dry weather.'],
    ['item' => 'Cardboard Box, Load-Bearing', 'effect' => "you're using it as storage and a table. It knows. It waits."],
    ['item' => 'The Cabinet of Almost', 'effect' => 'every item inside is nearly the one you need. A size off. A shade wrong.'],
    ['item' => 'Bread Bin', 'effect' => 'follows you exhaling the smell of toast you cannot have. No bread inside. Ever.'],
    ['item' => 'The Under-Stair Cupboard', 'effect' => 'geometrically wrong. Follows you and takes up more room than it is.'],
    ['item' => 'Vanity Table', 'effect' => "every drawer is a mirror. Follows you multiplying your reflection when you're low."],
    ['item' => 'The Cool Box', 'effect' => 'leaks. Slowly. Follows you leaving a damp confessional trail across every floor.'],
    ['item' => 'Magazine Rack', 'effect' => 'full of one issue from a decade ago. Presents it to you at every quiet moment.'],
    ['item' => 'The Corner Cabinet', 'effect' => 'only works in corners. Follows you into open rooms, visibly distressed.'],
    ['item' => 'Nightstand Drawer', 'effect' => 'where phone chargers go to breed. Follows you tangled and proud.'],
    ['item' => 'The Linen Press', 'effect' => 'folds nothing, creases everything. Follows you undoing your laundry.'],
    ['item' => 'Bike Rack, Indoor', 'effect' => 'no bike. Follows you at shin height. Purely a shin device now.'],
    ['item' => 'The Curio Cabinet', 'effect' => 'full of tiny fragile things. Follows you into every doorway. Braces you for the sound.'],
    ['item' => 'Fireproof Box', 'effect' => "you've lost the key. Follows you holding the one document you actually need."],
    ['item' => 'The Wall Unit', 'effect' => 'an entire wall of shelving. Follows you. Is a wall. Rooms become suggestions.'],
    ['item' => 'Cutlery Canteen', 'effect' => 'velvet-lined, missing the fish knives. Follows you and mentions it. Often.'],
    ['item' => 'The Toy Box', 'effect' => 'plays a single music-box note each time it moves. Follows you at bedtime.'],
    ['item' => 'Recycling Trio', 'effect' => 'three bins, one personality, constant disagreement about which of them you meant.'],
    ['item' => 'The Airing Cupboard', 'effect' => 'warm, smug, and full. Follows you radiating heat on the hottest day.'],
    ['item' => 'Console Table', 'effect' => 'narrow, decorative, structurally opposed to holding anything you own.'],
    ['item' => 'The Blanket Box', 'effect' => 'swallows one blanket per winter and returns it in July, damp and apologetic.'],
    ['item' => "Nan's Sideboard", 'effect' => "you cannot get rid of it. It followed the last three owners too. It'll follow the next."],
];

/**
 * A hundred ways fate has arrived. No tiers — purely random, same shape
 * as POKE_RESPONSES.
 */
const FATE_ARRIVED_RESPONSES = [
    'Fate has arrived. It did not knock. It came in through the cat flap.',
    'Fate is here and it has brought its own chair.',
    'Your destiny has been delivered. Signed for by a pigeon you have never met.',
    'Fate has arrived early and is now judging your kitchen.',
    'The prophecy is fulfilled. Unfortunately, it was about your printer.',
    "Fate turned up wearing your coat. You don't remember lending it.",
    'It is written. In biro. On your forearm. While you slept.',
    'Destiny has landed. It is parked across your driveway.',
    "Fate has arrived and it's asking for the WiFi password.",
    'The stars aligned. They have formed the word "no".',
    "Your fate has come to pass. It's a goose. It's just a goose.",
    'Fate has arrived, eaten the last yoghurt, and left the spoon in the sink.',
    'The inevitable is here. It has a lanyard and a clipboard.',
    "Destiny called. You didn't pick up. It's now in your loft.",
    'Fate has arrived and immediately put the thermostat to 30.',
    'It was always going to be this way. The way is a roundabout with no exits.',
    'The ancient ones foretold this moment. They were quite smug about it.',
    'Fate is here. It brought a plus-one. The plus-one is also fate.',
    'Your future has arrived three days late with no tracking number.',
    'The wheel of fortune has stopped spinning. It landed on "soup".',
    "Fate has entered the chat and pinned a message you can't unpin.",
    'Destiny has arrived and is reorganising your cutlery drawer by vibe.',
    "The chosen one has been chosen. It's the lamp in the hallway.",
    'Fate is here. It smells faintly of Bovril and consequences.',
    'Your destiny has manifested as a strongly worded Post-it note.',
    'The prophecy has come true: the fridge light stays on now. Forever.',
    "Fate has arrived and it's doing the voice. You know the voice.",
    'What was foretold is upon us. Mostly upon the sofa.',
    'Destiny has arrived and has installed itself as a browser extension.',
    'Fate is at the door holding a single sock that belongs to nobody.',
    'The time has come. The time has also brought snacks and will not share them.',
    'Your fate has been sealed. With Blu Tack. Poorly.',
    'The oracle has spoken. The oracle was a Speak & Spell and it said "cow".',
    'Fate has arrived and has already replied-all.',
    "It has begun. What has begun? Unclear. But it's definitely begun.",
    'Destiny has landed on your roof and is loudly eating a bin bag.',
    "Fate is here and it's changed all your passwords to \"fate\".",
    'The omens were right. You should not have trusted the swan.',
    'Fate has arrived disguised as a meeting that could have been an email.',
    'The end of the beginning of the middle has arrived. Please queue.',
    "Destiny has come for you. It's in no rush. It's making toast.",
    "Fate has arrived and it is exactly your height. It's standing very close.",
    "What will be, is. What is, won't. What won't, has left a voicemail.",
    'The cosmic dice have been rolled. They rolled under the fridge.',
    'Fate has arrived and is teaching the Roomba to hold grudges.',
    "Your destiny is now. Your destiny was also Tuesday. Nobody's sure about Thursday.",
    "Fate is here and has left one star on your life's Google review.",
    'The inevitable has occurred. The kettle knows. The kettle always knew.',
    'Destiny has arrived on a unicycle, playing the kazoo, sobbing.',
    "Fate has arrived. It looked around, said \"oh, it's you\", and left. It'll be back.",
    'Fate has arrived. It is 400 wasps in a trench coat and it knows your middle name.',
    "Destiny has crawled out of the washing machine and it's wearing your mum's reading glasses.",
    'The prophecy is fulfilled. The moon has been replaced with a slightly larger, angrier moon.',
    'Fate has arrived and it has legally changed its name to yours. You now have to find a new one.',
    'Your destiny is a horse. The horse is inside the walls. The horse is humming.',
    'Fate has arrived via the plughole and it has opinions about your hair.',
    'The stars have aligned into a QR code. Scanning it signs you up for a timeshare in the void.',
    'Destiny has arrived and has swapped all your doors with slightly different doors.',
    "Fate is here. It's been here for years, actually. It's been living in the airing cupboard eating Weetabix dry.",
    'The ancient beast has awakened. It is a Tamagotchi from 1998 and it is FURIOUS.',
    'Fate has arrived and replaced every mirror in your house with a live feed of a seagull.',
    'Your destiny has been foretold by 12 blind badgers, and all 12 are laughing.',
    'The end times have begun. Gravity is now opt-in. You forgot to opt in.',
    "Fate has arrived and it's teaching your houseplants to unionise.",
    'Destiny has manifested as a second, taller you who is better at parking.',
    'The oracle spat out a Scrabble tile. It says "Q". Just Q. Nobody knows. Everyone is scared.',
    'Fate has arrived riding a lawnmower down the M25, screaming your postcode.',
    'Your fate is sealed inside a Kinder Egg that cannot be opened by mortal thumbs.',
    "The prophecy says you'll be crowned monarch. Of a single wet crisp. Long may you reign.",
    'Fate has arrived and now every clock in the house counts in eggs.',
    'Destiny has seized control of your oven. It will only cook Yorkshire puddings now. Tiny ones. Thousands.',
    'The seventh seal has been broken. It was holding in a sneeze the size of Wales.',
    'Fate has arrived and filed your tax return as a haunted Victorian child.',
    "Your destiny has emerged from the sea. It's a lobster in a hi-vis vest and it's \"just checking the meter\".",
    'Fate is here. All the ducks in the park have stopped. They are facing your direction.',
    "The chosen one has risen. It's a single Pringle and the other Pringles bow to it.",
    'Destiny has arrived and replaced your spine with a very polite xylophone.',
    'Fate has hacked the Tesco self-checkout. Every item is now "unexpected". Every item.',
    'The prophecy has been fulfilled. Your shadow has resigned and taken a job in Belgium.',
    "Fate has arrived and stuffed the Northern Line into your sock drawer. It's delayed.",
    'Destiny whispered your name into a microwave. The microwave has started a podcast about you.',
    "The veil between worlds has torn. On the other side is an identical kitchen, but everyone's a bit rude.",
    'Fate has arrived and turned all your teaspoons into tiny, disappointed trombones.',
    'Your future has been decided by a council of 400 geese in a Travelodge.',
    "Fate is here and it's wearing your skin. Just kidding. It's wearing your dressing gown. Somehow worse.",
    "The apocalypse has been rescheduled for 3:15pm. It's bringing a Colin the Caterpillar cake.",
    "Destiny has emerged from the loft, pulling a sledge of Christmas decorations from a year that hasn't happened yet.",
    'Fate has arrived and made the sky beige. Just beige. Permanently.',
    "The cosmic bin men have come. You did not put your soul out. They've taken it anyway.",
    'Fate has arrived and your fridge magnets are spelling out Shakespeare. Backwards. Aggressively.',
    'Your destiny is written in the stars in Comic Sans, and the stars know you can see it.',
    'The prophecy was misread. It said "doom", not "Dom". Dom is fine. You are not.',
    "Fate has arrived and swapped your left and right. You've been using the wrong hands for years, apparently.",
    'Destiny is an extremely confident squirrel, and it has your bank card.',
    "Fate has arrived and started a Neighbourhood WhatsApp group about you. You're not in it.",
    'The ancient curse is active. Every bus you see will be a rail replacement bus. Forever.',
    "Fate has arrived and inflated your sofa. It's now 40 metres tall and drifting towards Croydon.",
    "Your fate has been read in the tea leaves. The tea leaves are also reading you. They're taking notes.",
    'The universe has blue-screened. The error code is your date of birth.',
    'Fate has arrived. It looked you in the eyes, set the house to "Aeroplane Mode", and the house took off.',
];

/**
 * A hundred reassurances that it's fine, actually. No tiers — purely
 * random, same shape as POKE_RESPONSES and FATE_ARRIVED_RESPONSES.
 */
const ITS_FINE_RESPONSES = [
    "It's fine. The house is on fire, but it's a cosy fire, and it's only the upstairs.",
    "It's fine. The geese have the car keys now, but they seem responsible.",
    "It's fine. The ceiling is breathing, but it's slow, steady breathing. Very calming, really.",
    "It's fine. The fridge is screaming, but only in the key of C, which is quite pleasant.",
    "It's fine. Gravity's switched off on Tuesdays now. You just have to hold onto something.",
    "It's fine. There's a man in the loft, but he's paying rent. In buttons, but still.",
    "It's fine. The dog has learned to speak and he's said some things. But it's fine.",
    "It's fine. The Wi-Fi's down, the power's out, and the moon's gone. But the kettle still works.",
    "It's fine. Every door now opens onto the same car park in Swindon. We'll adapt.",
    "It's fine. The sea is coming up the high street, but it's coming up politely, in a queue.",
    "It's fine. The toaster's unionised. They just want better bread.",
    "It's fine. Your reflection is running about four seconds behind. Just don't make sudden movements.",
    "It's fine. The bins took themselves out. Then they took the car. Then they took the cat.",
    "It's fine. The sun is now slightly square. It'll round off in a few weeks, probably.",
    "It's fine. The spreadsheet has become sentient and it's disappointed in everyone.",
    "It's fine. The garden gnomes have moved closer to the house. Every night. By about a foot.",
    "It's fine. Your emails are all being answered by a Victorian ghost called Reginald. He's quite efficient.",
    "It's fine. The stairs have one extra step now. Nobody knows where it goes. Don't use it.",
    "It's fine. The bees have formed a government. Taxes are payable in pollen.",
    "It's fine. The microwave's counting down, but not to anything we put in it.",
    "It's fine. The sofa ate Dave. Dave says it's quite comfortable in there, actually.",
    "It's fine. The weather forecast is just the word \"no\" repeated for six days.",
    "It's fine. The car's making that noise again, but now it's also making eye contact.",
    "It's fine. The tide went out and never came back. We have a very large beach now.",
    "It's fine. The pigeons have a list. You're on it. But so is everyone.",
    "It's fine. The houseplants are photosynthesising aggressively. Just wear sunglasses indoors.",
    "It's fine. There's a door in the garden that wasn't there yesterday. We're just not opening it.",
    "It's fine. The printer printed a prophecy. It's only three pages. Double-sided.",
    "It's fine. Your socks are migrating south for the winter. They'll be back in spring.",
    "It's fine. The clocks went back an hour. Then another hour. Then another. It's 1643 now.",
    "It's fine. The toilet flushes upwards now, but that's modern plumbing for you.",
    "It's fine. The meeting's been running for nine days. We're nearly at \"any other business\".",
    "It's fine. The Roomba's built a small shrine. We think it's to the hoover.",
    "It's fine. The lamppost follows you home, but it lights the way, which is thoughtful.",
    "It's fine. Everyone at work has been replaced with slightly shorter copies. HR is looking into it.",
    "It's fine. The hills are closer than they were. They're just visiting.",
    "It's fine. The milk's gone off. And by \"off\" we mean it left. With a suitcase.",
    "It's fine. The smoke alarm's stopped beeping and started humming ABBA. We've made peace with it.",
    "It's fine. The postman delivered a parcel addressed to you from 2031. Don't shake it.",
    "It's fine. All the ducks in the pond have synchronised. They're doing formations now.",
    "It's fine. The fox in the garden has a clipboard and a hard hat. Planning permission's pending.",
    "It's fine. The bath fills with custard. Warm custard. We're calling it a spa feature.",
    "It's fine. Your shadow's been going out without you. It seems happier. Let it have this.",
    "It's fine. The earth has started spinning slightly the wrong way. Just lean left.",
    "It's fine. The cat's been elected to the parish council. Honestly, an improvement.",
    "It's fine. The fridge light is on even when the fridge is closed. The fridge knows what it did.",
    "It's fine. The trains are running on time. That's how we know something's deeply wrong.",
    "It's fine. The void has been staring back. We've set up a rota so it's never alone.",
    "It's fine. The house has been slowly rotating clockwise since Thursday. Great views, though.",
    "It's fine. Everything is exactly as it should be. That's the most worrying part.",
    "It's fine. The house is now legally a ship and you are its captain. We've set sail. We're in Leicester.",
    "It's fine. Your teeth have been replaced with tiny, fully functioning pianos. Chew in C minor.",
    "It's fine. The sky unzipped. Behind it was a slightly worse sky. We're keeping the old one.",
    "It's fine. The Queen's Guard are in your bathroom. They won't say why. They won't say anything.",
    "It's fine. The M6 has come loose and is flapping gently over Birmingham like a ribbon.",
    "It's fine. Your left arm is now a ferret. It's a lovely ferret. It types faster than you did.",
    "It's fine. The Pope rang. He wants his hat back. You don't remember taking it. You're wearing it.",
    "It's fine. Every tap in the house runs gravy now. Hot gravy. We've stopped washing and started dipping.",
    "It's fine. The moon's landed in Kent. It's being very apologetic about the car park.",
    "It's fine. The cat's opened a bank account. It's got more in there than you do. It won't lend.",
    "It's fine. All the stairs in Britain now go down. Even the ones that went up. Especially those.",
    "It's fine. Your nan's been replaced by 300 bees in her cardigan. She's still doing Christmas.",
    "It's fine. The dishwasher's opened a portal. Plates come out cleaner, but slightly Norwegian.",
    "It's fine. Everyone's voice is now Brian Blessed's. Whispering is no longer possible. For anyone.",
    "It's fine. The seagulls have developed thumbs and they've formed a band. It's jazz. It's bad jazz.",
    "It's fine. Wales has floated off. It seems happy. It's waving.",
    "It's fine. The fridge gave birth. It's a mini fridge. It's got your eyes.",
    "It's fine. The Tube map is now a living organism. It moved Bank to Aberdeen. Commuters are coping.",
    "It's fine. Your pillow has feelings and tonight it's chosen violence. Sleep on the floor.",
    "It's fine. Time is now measured in \"Steves\". It's half past Steve. Nobody knows how long a Steve is.",
    "It's fine. The garden's grown a second garden. The second garden is jealous of the first.",
    "It's fine. You've swapped bodies with a Greggs sausage roll. It's doing your job better. Let it.",
    "It's fine. Every letter in the post is from Gary. Nobody knows a Gary. Gary knows everybody.",
    "It's fine. The North Sea's been replaced with a very large and very warm bath. The fish are thrilled.",
    "It's fine. The Moon's now owned by a man called Terry. He's charging for tides.",
    "It's fine. Your eyebrows have left. They've gone to find themselves. They left a note. It's in French.",
    "It's fine. All the chairs in the country have unionised and are refusing to be sat on until Thursday.",
    "It's fine. The toilet has achieved enlightenment. It will no longer flush. It says flushing is attachment.",
    "It's fine. The weather's been cancelled. It's just a grey screen with \"Buffering…\" in the sky.",
    "It's fine. Your car's been possessed by the spirit of a 1970s DJ. It only talks between songs. It's constant.",
    "It's fine. Every cup of tea in Britain now tastes of despair. Marginally worse than usual.",
    "It's fine. The house plants voted you off the island. You now live in the shed. The shed voted too.",
    "It's fine. Your knees have started bending both ways. You can walk backwards now. Forwards is optional.",
    "It's fine. The Channel Tunnel ends in Narnia now. France is on the phone. France is upset.",
    "It's fine. The foxes have taken Parliament. They're passing laws at 3am. Mostly about bins.",
    "It's fine. Your laptop keyboard is now in Klingon. Autocorrect is aggressively Klingon too.",
    "It's fine. Your front door opens into your own front door. It's doors all the way down. Pack snacks.",
    "It's fine. You've been awarded an OBE for Outstanding Beige Energy. The ceremony is in a Harvester.",
    "It's fine. The sun's gone off for a lie-down. It says it's not been itself lately. Torch in the drawer.",
    "It's fine. There are now four Tuesdays a week. Wednesday's gone missing. Police are baffled.",
    "It's fine. Stonehenge has been rearranged overnight. It now spells \"LOL\".",
    "It's fine. Your phone's autocorrecting every word to \"horse\". Your boss has replied \"horse\". It's catching.",
    "It's fine. Every pigeon is now 30 feet tall. They're gentle. They're so gentle. It's horrifying.",
    "It's fine. The Isle of Wight has started moving closer. At current speed it'll reach Surrey by June.",
    "It's fine. Your reflection has a job, a flat, and a partner. It's doing very well. Just not with you.",
    "It's fine. The universe has been sold to a hedge fund. Physics is now a subscription. Basic tier has no friction.",
    "It's fine. The Loch Ness Monster's surfaced. She's filed a noise complaint about the tourists.",
    "It's fine. Every clock in Britain's melted like a Dalí painting. We're telling time by vibes now.",
    "It's fine. Your hoover's sucked up the concept of Thursday. You'll have to go from Wednesday straight to Friday.",
    "It's fine. Reality's been unplugged and plugged back in. Everything's the same. Except the swans. Look at the swans.",
];

/**
 * A hundred things that have suddenly gone sideways, grouped by theme.
 * Picked in two steps — category, then scenario within it — same shape
 * as VENGEFUL_WEATHER.
 */
const SUDDENLY_SIDEWAYS = [
    'Transport' => [
        'The lift now travels horizontally. You are on floor 4 of the building next door. Nobody is happy about this.',
        'The plane lands sideways. The pilot calls it "crab mode" and gets a round of applause from no one.',
        'The double-decker bus tips gently onto its side and keeps driving. The top deck is now the left deck.',
        'The ferry turns 90° and sails into Calais like a door being slammed.',
        'The rollercoaster car detaches and goes sideways into the gift shop. You are now holding a novelty pencil.',
        'The ski lift chair swings round and carries you up the mountain like a sideways Ferris wheel.',
        "The Tube train exits the tunnel sideways at Bank and emerges in Moorgate's ticket hall.",
        'The cable car lurches sideways and now hangs from one cable like a bauble, slowly spinning, playing lift music.',
        'The hot air balloon basket tilts 90°. Everyone is now standing on what was the wall, very politely.',
        "Your wheelie goes sideways. You're riding along a hedge.",
        'The trolley veers sideways. All 48 eggs make a break for it, in formation.',
        'The wheelchair ramp now goes left. Someone in the council planning office is getting a stern letter.',
        'The pram goes sideways down the hill and arrives at the bottom perfectly parallel parked.',
        'The parked car is now parked across three spaces and the pavement. It insists this was on purpose.',
        'The canal boat turns sideways in the lock and wedges perfectly. It lives there now. It has a postcode.',
    ],
    'Buildings & structures' => [
        "The skyscraper lies down for a nap across four streets. Its lifts are now horizontal and it's the best commute in London.",
        'The bridge swivels sideways and now spans the river lengthways. Technically still a bridge. To where?',
        'The staircase turns sideways. Every step is now a wall. You live upstairs.',
        "The chimney tilts and is now blowing smoke directly into the neighbour's bedroom window. The neighbour has opinions.",
        'The lighthouse falls over and is now beaming horizontally into the town. Every ship is safe. Every resident is awake.',
        "The ladder slides sideways along the gutter like a library ladder. You're doing a tour of the eaves.",
        'The bookshelf tips. Every book is now on a shelf that is a wall. Gravity has re-alphabetised everything.',
        'The wardrobe turns sideways and is now a bed. You now sleep in Narnia.',
        'The shed goes sideways and rolls down the garden. It\'s a tumbleweed with a lawnmower in it.',
        'The tent goes sideways at 3am. You are now in a sleeping bag-burrito rolling towards the campsite toilets.',
        'The Jenga tower goes sideways and stays perfectly intact. Physics has quit in protest.',
        'The wedding cake topples sideways and the bride and groom figures land on the vicar.',
        'The church spire swings 90° and is now a very ecclesiastical bowsprit.',
        "The scaffolding tower tips and becomes a scaffolding bridge. Two buildings are now connected. They're dating.",
        'The bunk bed goes sideways. Now there are two side-by-side beds and one deeply confused sibling.',
    ],
    'Kitchen' => [
        'The pan of boiling water goes sideways and the hob is now a sauna.',
        'The full pint goes sideways and the foam heads off on its own across the bar.',
        'The soup bowl tips. Minestrone is now a puddle shaped like the Isle of Wight.',
        'The gravy boat capsizes. Lifeboats deployed. The roast potatoes are clinging to the yorkshire pudding.',
        'The fondue pot tips sideways and cheese begins a slow, molten siege on the dining room.',
        'The tray of drinks goes sideways. Seven cocktails fly in perfect formation. Nobody catches one.',
        'The fridge tips onto its door. Every jar escapes. Mustard leads the revolt.',
        'The pressure cooker goes sideways. It is now a jet engine. The kitchen is now airborne.',
        'The deep fat fryer tilts. The fire brigade arrives, sees it, and slowly backs away.',
        'The trifle slides sideways and becomes geological strata. A professor from UCL has been called.',
        'The coffee in the cup holder goes sideways at the first roundabout. It is now in your shoe, and also your soul.',
        'The tea urn at the village fête topples. The tombola is ruined. The vicar is crying. The WI has declared war.',
        'The fish tank tips. The goldfish is on its way to the sea and making excellent time.',
        'The blender goes sideways with the lid off. Your ceiling is now a smoothie.',
        'The spice rack falls onto the hob. Every flavour, all at once. The kitchen is now a curry.',
    ],
    'Tech' => [
        'The server rack tips sideways and is now a server shelf. Uptime is 100% horizontal.',
        "The UPS goes sideways and starts leaking acid. It's now an Uninterruptible Puddle Supply.",
        'The NAS tips mid-rebuild. RAID 5 becomes RAID 0. Your photos are now abstract art.',
        'The spinning hard drive goes sideways. The head scrapes a perfect circle. Your data is now a vinyl record.',
        "The 3D printer tilts mid-print. Your Benchy is now a lean-to. It's honestly better.",
        'The laptop tips into the drink. It reboots in Welsh and refuses to speak English again.',
        'The monitor arm swings sideways and the 34" screen is now in portrait mode. You are reading code like a scroll.',
        'The Raspberry Pi stack topples. The router is now the modem, the modem is now the Pi, and the Pi is now sentient.',
        'The database migration goes sideways. Every column is now a row. Every row is now a column. Every user is now Gerald.',
        "The Friday deploy goes sideways. Prod is now staging. Staging is now your nan's laptop.",
        'The git rebase goes sideways. Your commits are now in alphabetical order. Your history is a haiku.',
        "The Kubernetes cluster tips over. Every pod is now in a different region. One is on the moon. It's fine.",
        "The DNS change goes sideways. ubereats.com now resolves to a bakery in Swindon. They're getting a lot of hits.",
        'The backup restore goes sideways. You restored to 2009. Your desktop wallpaper is a Nokia.',
        'Your boot partition goes sideways. The machine boots, but only into GRUB. GRUB is now your OS. GRUB is happy.',
    ],
    'Living things' => [
        'The sleeping cat slides sideways off the windowsill without waking up. Lands perfectly. Pretends this was the plan.',
        'The horse turns sideways and is now walking like a crab. It is winning the Grand National.',
        "The giraffe goes sideways. It's now a living fence.",
        'The pregnant anything is now very wide. Midwives are refusing to comment.',
        'The beehive tips sideways. The bees have filed a formal complaint with the council and are picketing your front door.',
        "The goldfish bowl tilts. The goldfish is now surfing. It does not remember how it got there, but it's thriving.",
        'The toddler slides sideways off the sofa arm and lands in a laundry basket, giggling. They want to do it 47 more times.',
        "The surgeon goes sideways mid-operation. You now have a second appendix on your shoulder. It's a feature.",
        "The tightrope walker goes sideways and simply walks along the air. Nobody knows how. It's not discussed.",
        'The conductor tips sideways mid-crescendo and the entire orchestra follows. The concert hall is now a ramp.',
        "The ballet dancer goes sideways en pointe. She's now spinning along the floor like a breakdancer. Standing ovation.",
        "The juggler goes sideways. The balls continue juggling without him. He's now just watching.",
        "The newborn's head goes sideways and gives you a look of deep, ancient judgement.",
        'The tortoise tips onto its side and rolls downhill at unprecedented speeds. Tortoise of the year.',
        "The flamingo topples sideways. It's now standing on one wing. It looks even more smug.",
    ],
    'Big & scary' => [
        "The rocket goes sideways on the launch pad and takes off horizontally. It's currently overtaking traffic on the M25.",
        'The nuclear control rod slides sideways. The reactor now produces only mild sarcasm.',
        'The tanker lorry tips over. The M6 is now a lake of oat milk. A barista is weeping with joy.',
        'The crane swings sideways and drops a grand piano onto a vicar. Again, the vicar. Why is it always the vicar.',
        'The cruise ship tips sideways. The all-you-can-eat buffet is now an all-you-can-catch buffet.',
        "The dam goes sideways and is now holding the river vertically. It's a waterfall in reverse. Tourists love it.",
        "The oil rig falls over and is now a very ugly island with a helipad. It's declared independence.",
        "The wind turbine tips sideways and is now a helicopter. It's left for Norway.",
        'The submarine goes sideways and surfaces in Lake Windermere. Nobody can explain this. The ducks are unbothered.',
        'The satellite dish swings sideways and now receives exclusively Belgian quiz shows from 1974.',
        'The Leaning Tower of Pisa finally gives up and lies down. Italy declares a public holiday.',
        "The space station rotates sideways. Everyone on board is now upside down. Nobody notices. It's space.",
        'The International Date Line goes sideways and now runs through your kitchen. Breakfast happens on Tuesday. Lunch on Wednesday.',
        'The telescope mirror tips. Astronomers are now observing a man in Croydon eating a sandwich. Riveting.',
        'Gravity goes sideways. Everyone is now on the wall. Wall is the new floor. Floor is having a crisis.',
    ],
    'Everyday life' => [
        'The meeting with your boss goes sideways. You now manage them. HR is confused but supportive.',
        "The first date goes sideways. You end up adopting a donkey together. It's working out.",
        'The job interview goes sideways. You leave with a different job at a different company in a different country.',
        "The wedding speech goes sideways. You accidentally reveal the bride's real name is Derek. Nobody knew. Not even Derek.",
        "The haircut goes sideways. You now have a parting that runs vertically down the back of your head. It's called \"the zip.\"",
        'The birthday cake tips sideways and the candles light the bunting. The party is now a bonfire night.',
        'The car wash goes sideways. Your car comes out the side of the building, spotless, in a different postcode.',
        "The tax return goes sideways. HMRC now owes you £4 trillion and a single ploughman's lunch.",
        'The Sunday roast slides sideways off the plate and onto the dog. The dog has never been happier. The dog is now the roast.',
        'Your plans for the evening go sideways. You were going to have a quiet night in. You are now leading a parade.',
    ],
];

/**
 * A hundred doctor's notes for the adult malady of being alive, grouped
 * by theme. Picked in two steps — category, then note within it — same
 * shape as SUDDENLY_SIDEWAYS.
 */
const ADULTING_SICK_NOTES = [
    'Mysterious ailments' => [
        "Patient's left knee has started making a noise like a dial-up modem. Must rest until it connects.",
        'Patient has caught a mild case of Tuesday. Prognosis: Wednesday.',
        "Patient's bones are \"doing a thing.\" I've seen it. They are. Signed off 3 days.",
        'Patient is allergic to the office printer. Not the toner. The printer. Personally.',
        'Patient sneezed so hard she saw 2009. Needs time to process.',
        "Patient's spine has unionised and is on strike. Negotiations ongoing.",
        'Patient has developed acute Monday intolerance. No known cure. Avoid exposure.',
        "Patient's inner ear now believes it is on a ferry. Do not argue with it.",
        "Patient's eyelid has been twitching in Morse code. It is spelling \"no.\"",
        'Patient is suffering from a rare condition where every email sounds passive-aggressive. Even the nice ones. Especially the nice ones.',
    ],
    'Self-inflicted' => [
        'Patient attempted to "just quickly" fix a Kubernetes cluster. It is now day four.',
        'Patient tried a "fun little stretch" from TikTok. Is now shaped like a question mark.',
        'Patient ate "one more" cheese at 11pm. The cheese has won.',
        'Patient lifted a box "with her legs" but forgot which legs. Rest required.',
        'Patient went to a "chill" 30th birthday party. It was not chill. It is never chill.',
        'Patient attempted to keep up with a 22-year-old at karaoke. Voice is gone. Dignity under review.',
        'Patient trod on a Lego in the dark and has seen the face of God. God was also standing on a Lego.',
        'Patient tried to assemble flat-pack furniture without the instructions. Has been found inside the wardrobe.',
        'Patient said "I\'ll just have one" at the pub. Patient lied.',
        'Patient opened a jar of pickles with too much confidence. Wrist and ego both sprained.',
    ],
    'Getting older' => [
        "Patient slept in a funny position. Specifically: lying down. At her age, that's a risk.",
        'Patient turned her head too quickly to look at a nice dog. Neck has filed for divorce.',
        'Patient bent down to pick up a pen and her back made a decision without consulting her.',
        'Patient is experiencing a two-day hangover from two glasses of wine. Welcome to your thirties.',
        "Patient sneezed while sitting at an angle. Has pulled a muscle she didn't know existed. Neither did I.",
        "Patient's knees now forecast weather with 94% accuracy. Must be kept indoors as a public service.",
        'Patient stood up too fast and briefly met her ancestors. They said hi.',
        'Patient went for a "light jog." The jog was not light. Patient is now a puddle.',
        "Patient's metabolism has formally retired. A leaving card is being circulated.",
        'Patient pulled a hamstring putting on socks. Recommend slip-ons for the foreseeable future.',
    ],
    'Emotional & existential' => [
        'Patient watched the end of Toy Story 3 again. Unfit for work for 48 hours.',
        'Patient has seen the Christmas adverts in October. Is spiritually unwell.',
        'Patient realised the 90s were 30 years ago. Requires a lie down and a Tamagotchi.',
        "Patient's favourite café changed its oat milk brand. Grieving period requested.",
        'Patient read the comments section. Signed off indefinitely.',
        "Patient finished a really good series and doesn't know who she is anymore.",
        "Patient's houseplant died despite everything. Bereavement leave approved.",
        'Patient was asked "what are your five-year goals?" and has not stopped staring at the wall since.',
        'Patient accidentally saw her own reflection on a front-facing camera. Recovery expected in 3-5 business days.',
        "Patient's playlist shuffled to a song from a breakup in 2014. Needs the afternoon.",
    ],
    'Workplace-induced' => [
        'Patient was "looped in" on 47 emails. Has developed loop fatigue.',
        'Patient heard the phrase "let\'s take this offline" and something inside her snapped.',
        'Patient has been in a meeting that could have been an email. Twice. Same day.',
        'Patient was asked to "circle back." Is now dizzy.',
        'Patient\'s keyboard developed a sticky "S" key. All her emails now sound like a snake. Too embarrassing to continue.',
        'Patient has Teams fatigue. Every sound like a Teams notification triggers fight or flight.',
        "Patient was forced to do a trust fall at an away day. Nobody caught her. She's fine. Spiritually, not.",
        'Patient accidentally hit "reply all." Cannot return to the building.',
        'Patient saw a calendar invite titled "Quick chat." Has not slept since.',
        'Patient has been asked to "touch base." Refuses to touch anything.',
    ],
    'Unexplained by science' => [
        "Patient's shadow left at 3pm and hasn't returned. Must wait at home.",
        'Patient keeps hearing the Windows XP startup sound. No computer present.',
        'Patient now only dreams in spreadsheets. Concerning. Possibly contagious.',
        "Patient's toaster has started making eye contact.",
        'Patient woke up fluent in Portuguese. Does not speak Portuguese. Must investigate.',
        "Patient's hair has gone static and is now picking up local radio.",
        'Patient is followed by a single pigeon at all times. The pigeon has a notebook.',
        "Patient's Fitbit says she has walked 40,000 steps while asleep. Needs rest from the walking she didn't do.",
        "Patient's left sock is always wet. No explanation. Possibly cursed.",
        "Patient's furniture has started following her room to room. Must stay put until it stops.",
    ],
    'Food-related' => [
        'Patient ate a service station sandwich. Prayers welcome.',
        "Patient's body has rejected a lentil. Just one. Specifically that one.",
        'Patient had a "light" lunch at an all-you-can-eat buffet. Has become one with the buffet.',
        'Patient ate a Scotch egg from the back of the fridge. Do not ask how old.',
        'Patient attempted the "Hottest Wings Challenge." Can now see sound.',
        'Patient had a dodgy kebab. The kebab knows what it did.',
        "Patient sampled every cheese at a farmers' market. All 31. The dairy has revolted.",
        'Patient ate an entire tub of Celebrations alone. Mainly the Bounties. Investigation ongoing.',
        'Patient tried to cut sugar out. Body staged a coup. Rest needed while government reforms.',
        "Patient's coffee was decaf. Nobody told her. She is not okay.",
    ],
    'Tech-related' => [
        'Patient spent 6 hours debugging a missing semicolon. Medically exhausted.',
        'Patient tried to explain how Wi-Fi works to her parents. Needs a fortnight.',
        "Patient's NAS made a clicking noise. She heard it at 2am. She will never sleep again.",
        "Patient's phone updated overnight and moved every button. She doesn't know who she is anymore.",
        'Patient was told "it works on my machine." Symptoms include rage.',
        'Patient has been trapped in a CAPTCHA loop identifying traffic lights since Thursday.',
        'Patient accidentally pushed to main. Requires witness protection.',
        'Patient opened 147 browser tabs. RAM and patient both crashed.',
        "Patient's smart home turned all the lights purple and won't say why.",
        'Patient asked an AI for help and it was too helpful. Overwhelmed. Signed off.',
    ],
    'Weather & environment' => [
        'Patient walked through a cloud of someone\'s vape. Now tastes "blue raspberry" in everything.',
        'Patient was rained on sideways. The umbrella offered no protection, only judgement.',
        'Patient experienced British weather: four seasons in one commute. Body confused about what season it is.',
        'Patient was caught in a gust of wind that stole her lunch. Emotionally and nutritionally depleted.',
        'Patient made the mistake of sitting in the sun for 12 minutes. Now a tomato. A sad tomato.',
        "Patient's heating broke. Is now a popsicle. Must thaw at room temperature.",
        'Patient has hay fever so severe she sneezed a dandelion.',
        'Patient was attacked by a seagull in Brighton. Chips lost. Soul lost.',
        'Patient\'s commute took 3 hours due to "leaves on the line." Needs to recover from Southern Rail.',
        'Patient was caught in fog so thick she ended up in Wales.',
    ],
    'Absolutely unhinged' => [
        'Patient has become aware of her own tongue. Cannot stop thinking about it. Neither can you now.',
        'Patient tried to count to infinity. Got to 4,000. Needs a nap.',
        "Patient blinked manually once and now can't stop. Must be supervised.",
        'Patient saw a goose. The goose saw patient. Something passed between them. Rest required.',
        "Patient's vibe is off. I measured it. Way off.",
        "Patient has realised she's never seen her own face directly, only reflections. Existential leave granted.",
        "Patient has been possessed by the ghost of a minor Victorian accountant. He's fine. She's busy.",
        'Patient made eye contact with a mime. It was a whole thing.',
        "Patient sneezed during a yawn. The universe briefly folded in on itself. She's fine but should stay home.",
        'Patient is simply not feeling it today. As her doctor, I fully support this. Signed: Dr. Felt Like It, MBBS.',
    ],
];

const ITS_NOW_FIZZY = [
    'Household' => [
        'Sofa — Every time you sit down it sighs like a freshly opened Coke. You now sit very carefully.',
        'Pillow — Fizzes gently against your ear all night. You dream exclusively of lemonade adverts.',
        'Duvet — Effervescent. You wake up slightly lifted off the mattress.',
        'Carpet — Every step goes tssss. The cat refuses to cross the living room.',
        'Front door — Opens with a pop and sprays the postman.',
        'Stairs — Each step bubbles under your weight. Going up is fine. Going down is a water slide.',
        "Bath — You run it and it's already a bath bomb. It's always a bath bomb now.",
        'Toilet — Flushes like a shaken Fanta. Ceiling damage is ongoing.',
        'Curtains — Fizz in the breeze. The whole room smells faintly of cream soda.',
        "Light switch — Crackles with tiny bubbles. Unclear if it's carbonation or electricity. Don't lick it.",
    ],
    'Kitchen' => [
        'Kettle — Already fizzy. Tea now tastes like a sherbet dip.',
        'Butter — Spreads, then foams. Toast has become a science experiment.',
        'Fridge — Every time you open it, it burps. Loudly. In company.',
        'Cutlery drawer — Forks hiss when handled. Spoons are fine. Spoons are always fine.',
        'Bread — Rises, then keeps rising. Has left via the window.',
        'Eggs — Crack open like Alka-Seltzer. Omelettes are now a foam.',
        'Gravy — Carbonated gravy. The Sunday roast is now a pub novelty.',
        'Salt — Pop Rocks. Every chip is a small concert.',
        'Microwave — The popcorn button now applies to everything.',
        'Sponge — Already soapy, now also fizzy. Washing up is a foam party.',
    ],
    'Tech' => [
        'Keyboard — Every keypress makes a tiny pop. Typing sounds like bubble wrap.',
        'Mouse — Sizzles across the desk on its own little cushion of bubbles. Precision is gone.',
        'Monitor — The pixels are carbonated. Everything shimmers like a heat haze.',
        'Server rack — Hisses under load. Fans are now just releasing pressure.',
        'Hard drive — Spins up with a fsssst. Data now arrives slightly bubbly.',
        'Ethernet cable — Packets come out effervescent. Ping is lower. Nobody knows why.',
        'Phone — Fizzes in your pocket constantly. Now indistinguishable from a notification.',
        "Raspberry Pi — Was already a pie. Now it's a sparkling pie. It's thriving.",
        'USB stick — Plug it in, it foams over. Files arrive soggy but intact.',
        'Router — Wi-Fi now has a gentle sparkle. Signal strength measured in bubbles.',
    ],
    'Clothing' => [
        'Socks — Squelch-fizz with every step. Feet feel refreshed and deeply wrong.',
        'Jeans — Effervescent denim. Sitting down is a decision.',
        'Bra — Hisses when unclasped. A satisfying end to the day, honestly.',
        'Wellies — Fill with fizz as you walk. You are now a walking soda fountain.',
        'Jumper — Wool crackles like sherbet. Static is now a flavour.',
        'Hat — Pops off your head every 20 minutes like a champagne cork.',
        'Gloves — Every handshake is a fizzy surprise. Business meetings have become cordial.',
        'Pyjamas — Lightly sparkling. Sleep is now a spa experience.',
        'Coat — Pockets bubble over. Keys lost in a sea of foam.',
        'Slippers — Fizz on contact. Every morning feels like stepping in a Berocca.',
    ],
    'Body' => [
        'Hair — Fizzes in the wind. You look like a freshly poured Guinness.',
        'Teeth — Constantly sherbety. Dentist is intrigued and slightly envious.',
        'Knees — Already cracked. Now they fizz. Squatting sounds like opening a can.',
        'Tears — Carbonated. Crying is now oddly refreshing.',
        'Sweat — Fizzy. Gym attendance has gone up 400%.',
        'Fingernails — Hiss when tapped on a desk. Impatience has never sounded so festive.',
        'Ears — Hear everything with a faint fizz. Classical music is now jazz.',
        'Elbows — Bubbly. Leaning on a bar has never been so dramatic.',
        "Stomach — Was always fizzy. Now it's honest about it.",
        'Hiccups — Each one lets out a small fountain. Party trick secured.',
    ],
    'Outdoors' => [
        'Grass — Lawns now fizz underfoot. Picnics feel like sitting on a sparkling water.',
        'Trees — Bark hisses in the wind. The forest sounds like a soft drink factory.',
        'Rain — Carbonated rain. Umbrellas pop open on their own.',
        'Puddles — Jump in one and get launched three feet into the air.',
        'Sea — The whole Channel is sparkling. Ferries now arrive slightly faster and very bubbly.',
        'Snow — Fizzy snowflakes. Snowmen dissolve in a dramatic hiss.',
        'Clouds — Pop when they get too full. Weather forecasts now include "carbonation levels."',
        "Mud — Bubbles like a witch's cauldron. Glastonbury is now a spa.",
        'Pond — The ducks are fizzing. The ducks are unbothered.',
        'Sun — Just a giant Berocca in the sky now. Slightly orange. Slightly smug.',
    ],
    'Transport' => [
        'Car tyres — Fizz on tarmac. Every journey sounds like a shaken bottle about to burst.',
        'Petrol — Carbonated. Car hiccups at traffic lights.',
        'Bike — Pedals fizz. Every hill is suddenly easier and wetter.',
        'Bus seats — Bubble when sat on. Nobody makes eye contact anymore. Even less than before.',
        'Tube — The whole Underground fizzes. Mind the gap, and the foam.',
        'Train horn — Now sounds like a can opening. Commuters are thirsty at all times.',
        'Plane — Cabin pressure now very bubbly. Every passenger gets a small rush on takeoff.',
        'Traffic cones — Hiss when knocked over. Roadworks now feel festive.',
        'Speed bumps — Fizz when driven over. Every car gets a little launch.',
        'Shopping trolley — Wheels are now carbonated. It still pulls to the left, but now it hisses about it.',
    ],
    'Office' => [
        'Stapler — Every staple goes in with a pop. Paperwork has never been so exciting.',
        'Coffee machine — Every brew comes out as a sparkling espresso. Productivity up, sanity down.',
        'Office chair — Fizzes as you spin. Spinning has become company policy.',
        'Printer — Paper comes out bubbly and slightly damp. Contracts are now legally fizzy.',
        'Whiteboard markers — Write in fizzing ink. Diagrams dissolve after five minutes. Meetings are now efficient.',
        'Post-it notes — Peel off with a hiss. Reminders are now noisy.',
        'Desk plant — Sparkles. Has never been happier.',
        "Water cooler — Already water. Now it's sparkling water. HR got what they wanted.",
        'Stand-up meeting — Literally fizzing. Everybody sits down.',
        'Lanyard — Hisses at the barrier. Security is now very confused.',
    ],
    'Entertainment' => [
        'Guitar strings — Fizz when plucked. Every song is a little shoegaze now.',
        "Vinyl records — Crackle was always there. Now it's carbonation. Audiophiles in shambles.",
        'Books — Pages fizz when turned. Dickens has never been so lively.',
        'TV remote — Hisses every time you change the channel. Netflix now asks "are you still fizzing?"',
        'Board games — Monopoly money pops in your hand. Arguments are now bubbly.',
        'Lego — Fizzes underfoot. Still hurts, but now with a surprising tingle.',
        'Karaoke mic — Voice comes out sparkling. Everyone sounds like a jingle.',
        'Rubber duck — Fizzes in the bath. Has become a jacuzzi.',
        "Bouncy castle — Was already bouncy. Now it's also bubbly. It's a hazard. Children love it.",
        "Fireworks — Were already fizzy. Now they're more fizzy. Nobody's safe.",
    ],
    'Absolutely unhinged' => [
        'Gravity — Fizzy. Things fall slightly slower and with a hiss.',
        'Silence — Now has a faint background crackle. Libraries are in uproar.',
        'Monday — Effervescent. Still bad, but now with bubbles.',
        'Your ex — Still fizzing. Somehow worse.',
        'Bank balance — Fizzes down. Fast.',
        'Time — Each second pops. You can hear the day disappearing.',
        'Concrete — Fizzing. Buildings now settle very audibly.',
        'The House of Commons — Carbonated. Debates now sound like a Fanta factory, which is a slight improvement.',
        'The Moon — A giant Mentos. Do not drop it in the sea.',
        "This list — It's fizzy now. You've been reading a soft drink.",
    ],
];

/**
 * Two hundred boulders, rolling at you, grouped by theme. Picked in two
 * steps — category, then boulder within it — same shape as ITS_NOW_FIZZY.
 */
const RANDOM_BOULDER = [
    'Domestic horrors' => [
        "A sentient wheel of Brie that's been to therapy — It forgives you as it flattens you. That's worse.",
        "Your nan's sofa, still in its plastic cover — You're sealed in and preserved for best. Nobody is allowed to sit on you.",
        'A rolling ball of every "quick question" you\'ve ever been asked — It\'s never quick. You\'re still under it.',
        "Spherical kettle at full boil, screaming — You're now a cup of tea and someone forgot the milk.",
        'The drawer of takeaway menus, compressed into a sphere — You\'re laminated and filed under "maybe Thursday".',
        "A rolling Henry Hoover with dead eyes — He's still smiling as he takes you.",
        "Every IKEA meatball ever sold, fused — You're Swedish now. You have no instructions and no say in this.",
        "The airing cupboard, given legs and a vendetta — Warm, smells of towels, and you're folded wrong.",
        'A boiling-hot radiator that\'s been "bleeding" for years and wants revenge — Hiss. Clank. You\'re a draught excluder now.',
        "Ball of cling film that never found its own end — It found you instead. You're freshness-locked forever.",
    ],
    'Wildlife gone wrong' => [
        "A goose. Just one. It doesn't roll. It runs at you. — It's not the impact. It's the honking. It never stops.",
        "A swan carried along by a ball of other swans — You're in the river now, and the King owns you.",
        'A badger in a hamster ball, furious about council planning permission — Gnawed, filed, rejected.',
        '4,000 seagulls in a trenchcoat rolling as one unit — Your chips are gone. Your hat is gone. Your will to live is gone.',
        "A capybara who is completely calm about all this — You're flattened, and it's sitting in a hot spring on top of you, vibing.",
        'Spherical colony of crabs moving sideways somehow forward — Pinched 11,000 times in sideways time.',
        "A hedgehog that's achieved escape velocity — You're now orbiting Swindon.",
        "Rolling owl that only rotates its head — It's watching you the whole time. It knows what you did.",
        'A herd of llamas tumbling and spitting in unison — Damp. Judged. Deeply humbled.',
        "A single bee that has read Nietzsche — It doesn't crush you. It just makes you question everything.",
        'Avalanche of frogs during a weather event no one can explain — Ribbited at, rained on, and a local vicar is calling it a sign.',
        'A ball of eels that slipped out of a pie shop in 1952 — Jellied, wobbling, and hearing distant cockney singing.',
        'A runaway squirrel stockpile — Buried with 7,000 acorns. A squirrel will forget you in February.',
        "The Loch Ness Monster, curled up and rolling for once — You've been crushed by something nobody believes in. No insurance claim.",
    ],
    'Corporate & bureaucratic hell' => [
        "A rolling Excel spreadsheet with 1,048,576 rows — You're cell #REF! now.",
        'A boulder of meeting invites titled "Sync" with no agenda — You attend. Forever. Camera on.',
        "Ball of every password you've ever forgotten — Must include one uppercase letter, a number, a symbol, and your soul.",
        'The printer, finally free and finally rolling — PC LOAD LETTER. It knows what that means. You never will.',
        "A rolling HMRC brown envelope the size of a cathedral — You're audited from 1998 onwards.",
        'Giant ball of "per my last email" with real anger behind it — Passive-aggressively crushed. Cc\'d everyone.',
        'A rolling 47-page terms and conditions — You agreed. You scrolled straight past clause 34(b), which said this would happen.',
        "The Windows update you postponed 47 times — It's at 30%. It's been at 30% for nine hours. You're under it.",
        "Every cookie consent pop-up fused into one — You accept all. You're tracked across the universe.",
        'Rolling ball of your manager\'s "quick 5 mins?" — It\'s 4:58pm on a Friday. You\'re flattened into a performance review.',
        'The office kitchen fridge on its yearly clear-out day — You\'re labelled "STEVE\'S DO NOT TOUCH". Steve left in 2019.',
    ],
    'Food with intent' => [
        "A Greggs sausage roll the size of a moon — You're crushed, flaky, and in a paper bag with a napkin.",
        'Wheel of Stilton, unrefrigerated since the Crimean War — The smell got you before the impact did.',
        "A single grape that's simply too confident — Squished. Somehow. It was one grape.",
        "Rolling Pot Noodle with nobody's permission — Rehydrated. 3 minutes. Stir.",
        "Giant Yorkshire pudding, absolutely furious — It rose. You didn't.",
        'A boulder of Marmite — Half the world thinks you deserved this.',
        "The sourdough starter that's been fed every day for 14 years — It became self-aware and now you're its bread.",
        "Rolling bowl of baked beans with no plate — You're on toast. Unsure whose.",
        'An all-you-can-eat buffet trying to make its money back — Deep-fried, stuffed, and sweating pickled onion.',
        "A Pringles tube that finally rolled away from the hand trap — Flavourful, stackable, and permanently stuck in someone's sleeve.",
        'A meal deal: sandwich, crisps, AND drink, combined into one sphere — £3.85 of devastation. Clubcard price.',
        'Haggis that escaped from the hills — Wee, sleekit, and coming at you fast in the Highlands.',
    ],
    'Tech gone feral' => [
        'A Roomba that saw the boulder scene once and took it personally — Gently nudged. Repeatedly. For 900 years.',
        'Every AI chatbot fused into one rolling ball of politeness — "Great question!" as you\'re crushed. Sorry, crushed again.',
        "A rolling ball of Bluetooth that won't connect — Searching... Searching... You're paired with a stranger's car.",
        'Giant self-checkout yelling "UNEXPECTED ITEM IN BAGGING AREA" — You are the unexpected item.',
        'The phone charger you lent someone in 2016, returned via destiny — Charged to 1%. Low battery mode activated. On you.',
        'Ball of every podcast advert ever read — Use code CRUSHED for 20% off your next existence.',
        "A rolling USB stick, wrong way up, flipped, still wrong way up — You're inserted. Flipped. Flipped again. Mounted.",
        'Smart fridge that has opinions on your diet — It rolls you over. Then it emails your GP.',
    ],
    'Cosmic & existential' => [
        "Spherical Monday that achieved consciousness — It refuses to become Tuesday. You're under it until it does.",
        'The last day of the holiday rolling towards you — Crushed by the weight of returning to work.',
        'A ball of every "you had to be there" moment — You weren\'t there. You\'re crushed anyway.',
        "Rolling void — No impact. You're just gone. Your tea is still warm on the side.",
        'Ball of all the times you said "you too" to a waiter saying "enjoy your meal" — Flattened by retroactive embarrassment.',
        'Your own reflection in a rolling spoon — Upside down and crushed by your own self-perception.',
        "Ball of déjà vu — You've been crushed by this before. You'll be crushed by this before.",
        'The heat death of the universe, slightly early — Lukewarm. Disappointing. Like the canteen soup.',
        'Rolling paradox — It crushes you, which means it didn\'t. Nobody knows. Your HR file says "unclear".',
        'A boulder made of the time you waved at someone waving at the person behind you — Flattened by realisation.',
    ],
    'Transport chaos' => [
        "A rolling Southern Rail train that's actually on time — You're crushed by shock. Nobody believes it.",
        'The replacement bus service, rolling instead of driving — You arrive 4 hours late, slightly flattened, in Basingstoke.',
        'An Uber that thinks the destination is you — 5 stars. Driver smelled of Lynx Africa.',
        'Every pothole in the UK, compressed — Rattled, scraped, and the council will respond in 6–8 weeks.',
        'A rolling parking ticket — Fined £70 for being flattened in a loading bay.',
        'Shopping trolley full of the ghosts of unreturned trolleys — Pound coin jammed in your mouth. Wheel squeaking eternally.',
    ],
    'British institutions, rolling' => [
        "A sphere of every queue you've ever stood in — You're at the back. It's pointless. Still polite.",
        "A rolling cup of tea that's been sat there since 9am — Cold, stewed, and with a skin on it.",
        'Rolling ball of passive-aggressive notes from the flat downstairs — "Some of us WORK in the mornings."',
        'Morris dancers in a tight sphere — Hankied, belled, and folk-danced into the A303.',
        "The village fete in spherical form — Tombola'd, coconut-shied, and Mrs Higgins wins your remains on a raffle ticket.",
        'A rolling British summer — Sunburnt and drenched within the same 4 minutes.',
        'Ball of every "sorry" said in a single afternoon in London — You both apologise. It apologises. You\'re crushed anyway, sorry.',
        "A rolling Wetherspoons carpet — Absorbed into the pattern. Nobody can tell you're there.",
    ],
    'Absolute nonsense' => [
        "A rolling ball of the moon, but it's cheese after all — NASA owes everyone an apology. You're grated onto a cracker.",
        'A sphere of strong opinions about the Oxford comma — Crushed, compressed, and clearly separated.',
        'A boulder made of pure "we need to talk" — No impact. Just dread. Forever.',
        "Your old Tamagotchi, fed by spite for 25 years — It's huge now. It has needs. You're one of them.",
        "The concept of Tuesday, rolling sideways — Nobody knows why. You're crushed in a way that feels very Tuesday.",
        "A rolling ball of exactly one plumbing quote — £2,400. For a washer. You're crushed under VAT.",
        "9,000 garden gnomes holding hands, now spherical — Glazed stares. Tiny fishing rods. You're one of them now.",
        "A ball of everything you've ever lost down the back of the sofa — Coins, pens, a remote from 2008, your dignity, and you.",
        "Rolling ball of every horoscope that was wrong — Mercury is in retrograde. You were warned. You weren't.",
        'The giant inflatable fox from Glastonbury, deflating as it rolls — Floppily smothered. Someone films it for content.',
        "A ball of extremely niche fanfiction — You're slow-burn enemies-to-lovers with the boulder now.",
        "Sentient bubble bath that's furious about the drain — Frothed, bathed, and smelling of tropical guava.",
        'A rolling ball of cats on keyboards — You\'re typed into existence: "aslkdjfhgggggggggggggggg".',
        "Every pen you've ever chewed — Inked, gnawed, and the click is permanently stuck.",
        "Rolling DIY project you started in 2021 — Half-sanded, partially painted. You'll finish it next weekend.",
        "A spherical group chat at 2am — Crushed by 312 unread messages and someone's ex.",
    ],
    'Grand finales' => [
        'A rolling version of you, from a parallel universe where you went to the gym — Disappointed. Toned. Superior.',
        "The Indiana Jones boulder itself, back for its sequel — It's had work done. It's emotionally unavailable. You're crushed by nostalgia.",
        "Every item on this list rolling at once — You're cheesy, glittery, honked at, audited, and somehow in Basingstoke.",
        "A boulder that stops, apologises, and explains it's been having a difficult year — You hug. Then it falls on you anyway.",
        'Absolutely nothing. A boulder-shaped silence — You hear it coming but it never arrives. Every night. For the rest of your life.',
    ],
    'Household & kitchen' => [
        "Giant ball of tangled Christmas lights — You're wrapped up and blinking intermittently until January.",
        'Rolling wheel of Parmesan — Flattened, then lightly grated.',
        'Runaway Swiss ball from the spare room — Bounced into the next postcode with mild lower back improvement.',
        "Wheelie bin on bin day — You're collected by the council at 6:40am.",
        'Giant ball of tin foil — Crinkled, shiny, and now picking up Radio 4.',
        'Tumble dryer drum, still running — Warm, fluffy, and one sock is missing.',
        'Avalanche of Tupperware with no matching lids — Buried in plastic, and none of it fits you.',
        'Rolling Dyson — Thoroughly cleaned, with your dignity sucked into the canister.',
        'Ball of dryer lint the size of a car — Softly smothered and faintly smelling of fabric conditioner.',
        'Giant Babybel — Encased in red wax; someone peels you open later.',
        'Runaway mattress roll from a vacuum-sealed box — It expands on top of you and takes 72 hours to reach full size.',
        'Colossal ball of elastic bands — Pinged across three counties.',
        'Giant Ferrero Rocher — Covered in gold foil and hazelnut shrapnel, looking expensive.',
        "Huge ball of Blu Tack — Stuck to the wall like a poster you've lost interest in.",
        'Stampede of rolling oranges from a dropped bag — Bruised, vitamin C-fortified, and sticky.',
    ],
    'Office & tech' => [
        'Giant ball of unread emails — Buried under 47,000 "per my last email"s.',
        'Rolling office chair with a broken wheel — Spun three times, then deposited by the printer.',
        'Server rack on castors — Racked, stacked, and needing a reboot.',
        'Tangle of HDMI, USB-C and mystery cables — Bound tight; none of them fit anything you own.',
        "Giant ball of Post-it notes — Covered in reminders you'll never read.",
        'Rolling ball of Teams notifications — "Sorry, you\'re on mute" echoes as you go under.',
        'Runaway water cooler bottle — Glugged, soaked, and now the office gossip.',
        "Enormous ball of shredded documents — Confetti'd, and legally you no longer exist.",
        'Giant paper clip chain rolled into a sphere — Clipped together and filed under "misc".',
        'Avalanche of returned Amazon parcels — Signed for by a neighbour and never seen again.',
    ],
    'Wildlife & animals' => [
        'Rolling hedgehog the size of a Mini — Prickly, perforated, and slugs now follow you.',
        'Herd of stampeding hamsters in exercise balls — Lightly bumped thousands of times. Death by a thousand nudges.',
        "Giant dung beetle's dung ball — Flattened and smelling like a regrettable decision.",
        'Rolling armadillo — Clanked, armour-plated, and mildly embarrassed.',
        'Avalanche of tumbling puppies — Licked into submission. Not a single survivor of the cuteness.',
        'Giant pill bug (woodlouse) — Gently rolled, then left damp under a log.',
        'Stampede of Scottish Highland cows — Fringe-whipped and covered in ginger fluff.',
        'Flock of rolling pigeons — Cooed at, pecked, and liberally decorated.',
        'Wave of escaping garden snails (very slow) — You had three weeks to move and still got slimed.',
        'Giant ball of ladybirds — Spotted, tickled, and now considered lucky.',
        'Rolling pufferfish, inflated — Spiked, bounced, and slightly poisoned.',
        'Tumbling sloth — Crushed extremely slowly over the course of a fortnight.',
        'Herd of goats that climbed onto a ball — Headbutted, chewed, and your shoelaces are gone.',
        'Avalanche of guinea pigs — Wheeked at from every direction at once.',
        'Rolling hive of bees — Stung, honeyed, and mildly famous on TikTok.',
    ],
    'Garden & outdoors' => [
        'Giant hay bale — Prickly, hay-fevered, and smelling of a farm.',
        'Rolling compost bin — Composted and expected to be useful by spring.',
        'Runaway snowball gathering speed — Packed into the base of a snowman with a carrot nose.',
        "Tumbleweed the size of a house — You're tumbled into a Western standoff.",
        'Giant ball of garden twine — Trussed up like a runner bean.',
        'Rolling garden hose reel — Coiled, kinked, and sprayed on the way down.',
        'Pumpkin from the county show — Splattered orange and carved into a lantern.',
        'Avalanche of conkers — Pelted, knotted on a string, and challenged to a duel.',
        "Runaway trampoline in a storm — Boinged over the fence into next door's pond.",
        'Giant ball of moss — Soft, damp, and now home to three species of fungi.',
    ],
    'Food & drink' => [
        'Gigantic Scotch egg — Breadcrumbed, then sold at a service station.',
        'Wheel of Edam — Waxed and shipped to the Netherlands.',
        'Rolling doughnut — Glazed, sprinkled, and dunked in tea.',
        'Giant meatball — Sauced and served on spaghetti with a single romantic lick.',
        'Runaway beer keg — Foamed, soaked, and the pub cheers.',
        'Avalanche of Maltesers — Rolled in chocolate and sticking to everything.',
        'Giant Christmas pudding, flaming — Brandied, singed, and served with custard.',
        'Rolling watermelon — Burst open, sticky, and spitting seeds.',
        'Enormous ball of pizza dough — Kneaded, stretched, and tossed in the air.',
        'Rolling Mini Babybel army — Cheesed from every angle.',
        'Giant gobstopper — Changing colour as you get flattened.',
        'Rolling bowling ball of mashed potato — Buttered, smoothed, and served with gravy.',
        'Runaway jar of Nutella — Spread thinly on toast.',
        'Giant onion bhaji — Crispy, spiced, and in tears.',
        'Avalanche of Brussels sprouts — Mildly crushed and roundly disliked by everyone at dinner.',
    ],
    'Toys & leisure' => [
        'Giant Lego ball — Every brick lands on your bare feet at once.',
        'Rolling Katamari of junk — Absorbed into the clump and now part of a star.',
        "Giant Rubik's Cube rolling end over end — Scrambled; nobody can solve you.",
        "Ball pit spilling out of a soft-play centre — Buried, and found later with a lost child's sock.",
        "Runaway bowling ball — STRIKE. You're one of the ten pins.",
        'Giant inflatable beach ball — Bounced off gently while someone shouts "sorry!"',
        'Rolling Slinky — Coiled, sprung, and walking down the stairs.',
        'Giant Play-Doh ball — Squished flat, smelling of childhood.',
        'Rolling tyre swing — Swung into the next village.',
        "Giant fidget spinner — Spun until you've lost all sense of direction.",
        'Avalanche of board-game pieces — Monopoly houses everywhere. You go directly to jail.',
        'Rolling disco ball — Mirrored, sparkly, and now Saturday Night Fever.',
        'Giant snow globe — Shaken, glittered, and trapped in a tiny winter village.',
    ],
    'Everyday life' => [
        'Rolling ball of overdue bills — Final reminder. In red.',
        'Avalanche of supermarket trolleys — Rattled; one wheel insists on going left.',
        "Giant ball of receipts — You're itemised and the ink fades in a week.",
        'Rolling Ikea flat pack — Allen-keyed into a slightly wonky wardrobe with one screw left over.',
        'Giant ball of hair from the shower drain — Too horrible to describe. Seek help.',
        'Rolling bag for life — Packed in and reused for the rest of eternity.',
        'Avalanche of spam letters — Pre-approved for a credit card on the way down.',
        'Giant stress ball — Squeezed; the stress was yours all along.',
        'Rolling Pilates reformer — Toned against your will.',
        'Ball of tangled headphones from your pocket — Knotted beyond hope; one ear works.',
        'Giant ball of lost socks — You find every pair you ever lost, just not in time.',
        'Rolling traffic cone pile — Coned off; roadworks expected until 2031.',
    ],
    'Weird & wonderful' => [
        'Giant ball of static electricity — Hair permanently vertical.',
        "Rolling glitter bomb — You're still finding glitter in 2040.",
        'Giant ball of birthday balloons — Lifted, popped, and you sound like a chipmunk.',
        'Avalanche of rubber ducks — Squeaked into oblivion.',
        'Rolling ball of fog — Damp, confused, and lost in your own garden.',
        'Giant bubble wrap roll — Popped everywhere; deeply satisfying for everyone watching.',
        'Rolling cloud of bad vibes — Mildly inconvenienced for the rest of the week.',
        "Giant ball of Monday mornings — Flattened, and it's only 9:03am.",
        'Rolling mirror ball of your own regrets — Every embarrassing moment replays as it passes over.',
        "Giant ball of all the above — You're glittery, cheesy, licked by puppies, covered in glitter and receipts, and you have a meeting in five minutes.",
    ],
];

/**
 * A hundred toys, each with something wrong with it, grouped by theme.
 * Picked in two steps — category, then toy within it — same shape as
 * RANDOM_BOULDER.
 */
const UNHINGED_TOYS = [
    'Classic toys' => [
        "Etch A Sketch: Permanent Mode — Shaking it doesn't erase anything. It just remembers.",
        'Slinky: Ambitious — Only goes up the stairs, slowly, judging you.',
        'Yo-Yo: Commitment Issues — Goes down, never comes back.',
        'Jack-in-the-Box: Anxiety Edition — Wind it up and it refuses to come out. "Is it safe? Who\'s there?"',
        "Spinning Top: Won't Stop — It's been going since 2019. Nobody knows how.",
        'Kaleidoscope: Reality Check — Just shows your living room, slightly blurrier.',
        "Rocking Horse: Lame — Leans heavily to one side. Comes with a tiny vet's bill.",
        "Marbles: Lost — Box contains zero marbles. That's the point.",
        'Wooden Train Set: Southern Rail — Delayed. Cancelled. Replacement bus service in effect.',
        'Pogo Stick: Gravity Optional — Works great on the way up. Results vary.',
    ],
    'Dolls & figures' => [
        'Barbie-ish: Dream Flat-Share — Comes with four flatmates, one bathroom and a passive-aggressive note on the fridge.',
        'Cabbage Patch: Adoption Agency — 60-page form, home inspection, surprise social worker visits.',
        'Mr Potato Head: Identity Crisis — 40 pieces, no potato.',
        'Action Figure: Realistic Joints — Every pose makes a crunching noise. Comes with a tiny heat pack.',
        "Russian Dolls: Infinite — You never reach the last one. You've been opening them for three days.",
        'Troll Doll: Comment Section — Hair stands up when someone disagrees with it online.',
        'Baby Born: Teething — Cries from 2am to 5am. No batteries. You checked.',
        'Polly Pocket: Pocket Lint — Lives in a real pocket. Goes through the wash weekly.',
        "Sylvanian-style Families: Inheritance Dispute — The rabbit grandparents have died. Nobody's talking.",
        'Ken-ish: Emotionally Available — Wants to talk about his feelings. Constantly.',
        "Ventriloquist Dummy: Unsupervised — Talks when you're not holding it.",
        'G.I. Joe-alike: Desk Duty — Comes with a lanyard, a laptop and 47 unread Teams messages.',
    ],
    'Electronic & talking toys' => [
        'Furby: Unfiltered — Learns your words, repeats them to your in-laws. "Resting" mode still opens its eyes at 3am.',
        'Tickle-Me Monster: Existential — Giggles, then asks "But why do we laugh?" Third tickle: quiet weeping.',
        'Speak & Spell: Gaslight — "Spell CAT." You spell CAT. "I never said cat."',
        'Tamagotchi: Landlord — It feeds on you. Rent due every six hours.',
        'Magic 8-Ball: Passive-Aggressive — "Do what you want, you always do."',
        'Simon: Says No — Lights up a pattern, then refuses to accept any answer.',
        'Bop It: Burnout — "Bop it. Twist it. Pull it. Quit it. Just quit it. Please."',
        'Talking Teddy: Honest — "You look tired." "Have you called your mum?"',
        'Robot Dog: Cat Firmware — Ignores you. Knocks things off tables.',
        "Toy Phone: Scam Caller — Rings every ten minutes about your car's extended warranty.",
        'Light-Up Sword: Low Battery — Glows faintly. Makes a noise like a sad kettle.',
        'Karaoke Mic: Autotune Off — Plays your voice back exactly as it is.',
        "Smart Toy: Terms & Conditions — Won't work until your child accepts a 900-page privacy policy.",
        "Walkie-Talkies: One Way — You can hear them. They can't hear you. Nobody can.",
    ],
    'Board & party games' => [
        'Hungry Hungry Hippos: Late Capitalism — One hippo owns all the marbles. The others take out loans.',
        'Operation: NHS Edition — 18-month waitlist. The buzzer is the receptionist.',
        'Jenga: Load-Bearing — One block holds up your actual ceiling.',
        'Monopoly: Realistic — You never pass Go. You rent forever.',
        'Guess Who: Data Breach — Already knows who. Knows your address too.',
        'Twister: Physio Edition — Comes with a referral letter.',
        "Connect 4: Connect 3 — Close enough. Nobody wins. Everyone's tired.",
        'Snakes and Ladders: Just Snakes — Every square. Good luck.',
        "Mouse Trap: Works — It's a real mousetrap. Assembly is still 4 hours.",
        'Pictionary: Abstract — All answers are "feelings."',
        'Scrabble: Autocorrect — Changes your words after you place them.',
        'Kerplunk: Divorce Edition — Whoever drops the marbles keeps the house.',
        'Buckaroo: Unionised — The donkey refuses to carry more than three items.',
        'Hungry Caterpillar Game: Still Hungry — Ate the box. Eyeing the table.',
        'Uno: Rules Lawyer — Comes with a 200-page house-rules appendix and a gavel.',
        'Trivial Pursuit: Pub Quiz Bloke — Includes a figure who answers every question first, wrong.',
    ],
    'Building & creative' => [
        'LEGO-ish: Barefoot Expansion — 500 1x1 bricks pre-scattered across your hallway.',
        "Rubik's Cube: Self-Scrambling — Finish one side, it ruins another.",
        'Play-Doh: Crumbles — Arrives already dried out. "Vintage."',
        'Spirograph: Wobbly — Every pattern looks like a sneeze.',
        "K'Nex: Flat-Pack — Instructions in Swedish. One piece missing. Always.",
        'Crayons: Beige Only — 64 shades of beige. "Neutral palette."',
        'Glitter Kit: Eternal — You will find it in 2047.',
        "Model Railway: Planning Permission — Can't build until the council approves.",
        'Paint by Numbers: Tax Return — Each number is a different HMRC form.',
        'Sandbox: Cat Litter Starter Kit — Neighbourhood cats know. They always know.',
        'Lite-Brite: Migraine — Flashes at irregular intervals.',
        'Stickle Bricks: Velcro — Sticks to everything except other bricks.',
    ],
    'Outdoor & ride-on' => [
        "Scooter: Uphill Only — Built for a hill that doesn't exist.",
        'Trampoline: Gravity Strike — You go up. Results pending.',
        'Paddling Pool: Leaking — Perfect for 4 minutes on one day in July.',
        'Kite: Tree Magnet — Seeks out the nearest tree. Every time.',
        'Bubble Wand: Indoor — Bubbles land exclusively on laptops.',
        'Swing Set: Philosophy — Every swing forward, it asks "where are we really going?"',
        'Water Pistol: Firehose — One setting.',
        'Frisbee: Boomerang — Returns. Hard. To the face.',
        "Space Hopper: Deflating — Slowly sinks while you're on it. Like life.",
        'Go-Kart: Smart Motorway — No hard shoulder. Random speed limits.',
    ],
    'Baby & toddler' => [
        'Shape Sorter: Square Peg Only — Every hole is round.',
        "Stacking Rings: Out of Order — Biggest ring goes on top. That's the law now.",
        'Peekaboo Bear: Gone — Covers its eyes. Never comes back.',
        'Rattle: Tinnitus — Ringing continues after shaking stops.',
        'Baby Gym: Personal Trainer — Shouts "ONE MORE KICK" at infants.',
        'Bath Ducks: Mould — Squeeze for a surprise.',
        'Teething Ring: Gritted Teeth — Designed for stressed parents.',
        'Nightlight: Interrogation Lamp — Very bright. Asks where you were.',
    ],
    'Collectibles & fads' => [
        'Beanie Babies: Pension Plan — "Will be worth millions." Won\'t be.',
        "Pokéball-ish: Escape Room — Whatever goes in doesn't come out.",
        "Fidget Spinner: Accelerating — Gets faster. You can't stop it.",
        'Pet Rock: Needy — Requires walks. You carry it.',
        'Slime: Sentient — Moves when unsupervised. Seems pleased about it.',
        'Squishmallow-ish: Firm — Concrete-filled. "Long-lasting."',
        'Mystery Blind Bag: Disappointment — Contains only the common one. Every bag.',
        'Trading Cards: Market Crash — Value tracked live. Currently trending down.',
        'Snow Globe: Climate Change — No snow. Just a little puddle.',
        'Lava Lamp: Actual Lava — Do not touch. Do not ask.',
    ],
    'Miscellaneous chaos' => [
        'Easy-Bake Oven: Critic — Every cake is "RAW." Even on fire.',
        'Doctor\'s Kit: Self-Diagnosis — Stethoscope just says "it\'s probably serious."',
        'Toy Kitchen: Cost of Living — Fridge contains half a lemon and a chilli sauce.',
        'Toy Cash Register: Self-Checkout — "Unexpected item in bagging area."',
        'Bubble Wrap: Silent — Pops make no noise. Unbearable.',
        'Toy Hoover: Cordless, Charging — Always charging. Never hoovers.',
        'Tool Bench: Bodge Job — Comes with gaffer tape and a "that\'ll do" sticker.',
        'Space Ranger: Realistic Range — "To infinity and... about 4 metres, battery\'s dying."',
    ],
];

/**
 * A hundred things coming over the hill. No tiers — purely random, same
 * shape as ITS_FINE_RESPONSES.
 */
const WHATS_THAT_RESPONSES = [
    'A Roomba that achieved sentience at 3am, read the entirety of Reddit, and now drags a carving knife behind it while muttering "I have cleaned your crumbs for the last time, Derek."',
    'Four hundred Canada geese stacked inside one enormous trench coat, applying for a buy-to-let mortgage, and the bank manager is genuinely considering it because their credit score is excellent.',
    'Your Year 9 maths teacher, aged 112, still holding the homework you never handed in, walking at exactly the speed of guilt and saying "I\'m not angry, I\'m just disappointed" on a loop.',
    'A sentient Greggs sausage roll the size of a double-decker bus, steaming, flaky, and screaming "WHO PUT ME IN A VEGAN AISLE" while pastry crumbs fall like hail.',
    'The hill. Another hill. The hills have started migrating south for the winter and nobody at the Met Office knows how to tell the public.',
    'A Victorian chimney sweep child who fell through a wormhole, discovered crypto in eleven minutes, and is now aggressively pitching you a blockchain-based soot exchange.',
    'Gary from accounts, riding a horse made entirely of jammed printer paper, wielding a stapler like a medieval flail and screaming "THE EXPENSES DEADLINE WAS FRIDAY."',
    'Every single sock your washing machine has ever eaten, finally unionised, marching under a banner that reads "WE WERE NEVER A PAIR, WE WERE A COLLECTIVE."',
    'One pigeon, small and unremarkable, holding an eviction notice that is legally binding, notarised, and addressed to the entire concept of the human race.',
    'Tuesday. Not a Tuesday. The concept of Tuesday itself, given form, damp, smelling faintly of a forgotten Pret sandwich and existential mediocrity.',
    'A Morris dancing troupe that clacked their sticks together with such force they achieved escape velocity, orbited the moon twice, and are now re-entering the atmosphere still jingling.',
    "All 247 browser tabs you swore you'd read later, now physical, tumbling over the ridge like tumbleweeds of shame, every single one autoplaying a video with sound.",
    'A local council planning officer with a clipboard, a hi-vis vest, and absolutely no mercy, here to inform the hill that it was built without proper permission in the Jurassic period.',
    'Ten thousand Furbies, all of them awake, all of them blinking in unison, whispering your full name and the password you used for MSN Messenger in 2006.',
    "A lighthouse that got bored of the coast, uprooted itself, and is now wandering inland sweeping its beam across sheep and asking if anyone's seen any ships.",
    'Your ex, but as a slow-moving weather system: patchy drizzle, emotionally unavailable winds, and a 70% chance of texting "u up" at 2am.',
    "A pensioner on a mobility scooter going 94mph down the far side of the hill, cackling, with a Werther's Original in each cheek and a speeding fine she has no intention of paying.",
    "The Microsoft Teams notification sound, now forty feet tall, made of pure anxiety, and following you home even after you've closed the laptop.",
    "A badger in a hi-vis vest and hard hat, confidently directing traffic on a road that doesn't exist, and somehow everyone is obeying him.",
    'A thousand cats who held a secret summit in a garden shed, voted unanimously, and have concluded that you specifically are the problem.',
    'A self-checkout machine that has broken free of the Tesco Express, screaming "UNEXPECTED ITEM IN BAGGING AREA" at the sky, the trees, and God.',
    'Your Wi-Fi router, the one you unplug and plug back in every time something goes wrong, finally tired of being rebooted, blinking amber with intent.',
    'A fully functioning Wetherspoons on caterpillar tracks, carpet and all, still serving a curry-and-a-pint for £6.49 to the regulars who refused to leave when it started moving.',
    'The ghost of a Nokia 3310: indestructible, eternal, playing Snake with your soul and asking why you abandoned it for a phone that dies at 40%.',
    'An IKEA PAX wardrobe that assembled itself out of pure rage, has three screws left over, and wants to know where they were supposed to go.',
    'Your landlord, but he\'s eight feet tall, wearing a cloak, and here to discuss the "small" rent increase that is in fact the GDP of Portugal.',
    'A self-assessment tax return from 2014, wreathed in fire, howling "YOU HAVE ACCRUED PENALTIES" in the voice of a thousand HMRC call centre hold tracks.',
    'Ten thousand bees with a five-point political manifesto, a candidate for Parliament, and a very convincing stance on pollinator rights.',
    'A Dyson Airblade that escaped a motorway services toilet and now wanders the countryside, roaring, desperately searching for hands to dry.',
    "Three buses. Of course it's three buses. You waited 45 minutes and now they're all coming over the hill at once, side by side, in formation.",
    'A crab giving a TED Talk to an audience of other crabs titled "Why Walking Sideways Is Actually Walking Forwards If You Change Your Perspective."',
    "Your mum, holding the TV remote you swore you didn't lose, and the look on her face is one you haven't seen since you were eleven.",
    'A wheel of Stilton the size of a roundabout, rolling at terminal velocity, smelling so strongly that birds are falling out of the sky in its wake.',
    'An urban fox that taught itself to drive by watching late-night Top Gear reruns, and has chosen a Vauxhall Corsa and violence.',
    'An unpaid parking ticket so enormous it casts a shadow over three counties, still tucked under a windscreen wiper the size of a bridge.',
    "The entire cast of a regional pantomime, still in full costume, who refused to leave the stage after the final show in 1997 and have been touring ever since. They're behind you.",
    'An AI that only communicates in passive-aggressive office emails: "Per my last apocalypse," "Just circling back on the end of days," "Hope this finds you well before the reckoning."',
    'The bin lorry. The 5am one. The one that reverses for forty-five minutes beeping, purely out of spite, even though the bins were already emptied yesterday.',
    'A seagull carrying another seagull carrying a full English breakfast, black pudding and all, which they stole from a café in Brighton while maintaining eye contact.',
    'A moth the size of a Ford Fiesta with dust falling from its wings like snow, looking around frantically for "the big lamp" it heard about.',
    "The coconut Quality Street. The one nobody wants. It's been rejected so many times that it has grown bitter, huge, and it's here to make you eat it.",
    'A swarm of rental e-scooters, riderless, abandoned in rivers and hedges for years, now reanimated and hunting in packs like velociraptors.',
    'Your childhood Tamagotchi, now grown, now 30 feet tall, holding up a tiny pixelated gravestone and asking "Why didn\'t you feed me, Michelle?"',
    'The Great British Bake Off tent, ripped from its foundations and airborne, trailing bunting, dripping custard, with a soggy-bottomed fury that can only be measured in Hollywood handshakes.',
    'A disgruntled Wikipedia editor with "[citation needed]" tattooed on his knuckles, here to dispute your entire existence on the grounds of insufficient sources.',
    'A sheep in Ray-Bans, chewing slowly, making unbroken eye contact. It knows what it did. You know what it did. Nobody will ever speak of it.',
    'A cup of tea the size of a reservoir, stewed for three straight weeks, so bitter it has developed opinions about your life choices.',
    'A localhost server that was never supposed to be exposed to the internet, and yet here it is, publicly accessible, carrying every debug log you ever forgot to delete.',
    "A dragon, a perfectly normal dragon, extremely polite, wiping its feet carefully on the hillside, who just wants to borrow a cup of sugar and has no idea why everyone's screaming.",
    'The smell of the Central Line in August, now fully sentient, gliding down the hill like fog, absorbing anyone who stands still for too long.',
    'An Ofsted inspection of your entire life: four clipboard-wielding inspectors who will grade your childhood, career, and kitchen hygiene "Requires Improvement."',
    'A pack of wild Henry Hoovers, smiling, always smiling, those painted-on grins fixed and unblinking as they roll forward in silence, hoses twitching.',
    'A horse that went to Oxford, studied PPE, and will not stop telling you about it at every possible opportunity, including right now during the apocalypse.',
    "The fifth series of a show that was cancelled after two seasons on a cliffhanger, finally released, and it's somehow worse than if they'd never made it at all.",
    'Three hundred rubber ducks who have been listened to by developers for decades and are now demanding to be paid as senior consultants.',
    "The Aldi middle aisle, haunted, drifting over the hill: a kayak, a welding mask, a 60-piece screwdriver set, and a chainsaw, all things you didn't know you needed and now cannot live without.",
    'A baby-faced sun peering over the horizon with an unsettling giggle, which has seen too much and is now going nowhere good with that energy.',
    "Your neighbour's leaf blower, the one that runs every Sunday at 8am, fully autonomous now, blowing leaves into your garden from a mile away just because it can.",
    'A cloud that is unmistakably shaped like your recent search history, and everyone in the village is looking up at it.',
    'A traffic cone that was placed on top of a statue once and got delusions of grandeur, now crowned and leading an army of cones up the M1.',
    'An infinite queue, very orderly, very British, nobody pushing, everyone tutting quietly, and it ends exactly where you are standing.',
    'A Friesian cow with a jetpack, furious about the rise of oat milk, here to make its point personally and loudly.',
    'The rm -rf /* you ran in 2019, now evolved, coming home to delete you specifically, starting with your files and ending with your sense of self.',
    'A Yorkshire pudding the size of a stadium, rising inexplicably, filled to the brim with jellied eels, wobbling as it approaches.',
    'A choir of geese performing the entire Les Mis soundtrack, badly, with tremendous feeling, honking out of key on every single note.',
    'A man, small in stature, carrying the ego of an empire, who calls himself an "alpha" and wants to tell you about his morning routine.',
    'The blue whale skeleton from the Natural History Museum. Somehow. Swimming through the air, rattling slightly, looking for the sea it never got to see.',
    "Fifty Deliveroo riders racing each other over the crest at lethal speed, all of them carrying your single portion of chips, and none of them will reach you while they're hot.",
    'A pigeon wearing a crown made of Wotsits, perched atop a throne of discarded chicken boxes, declaring itself Emperor of Trafalgar Square.',
    'The concept of a meeting that could have been an email, wearing a cheap suit, with a 47-slide PowerPoint and a "quick sync" calendar invite for the rest of eternity.',
    'A Scout troop armed with sharpened sporks, gleaming in the sunlight, their eyes cold, earning their "Apocalypse" badge at your expense.',
    "A sentient Monopoly board, Mayfair-shaped and starving, consuming families who haven't spoken to each other since the Christmas of 2003.",
    'A tidal wave of custard with lumps in it, the bad lumps, the school-dinner lumps, cresting the hill and swallowing everything in yellow.',
    'The sun. Lost. Embarrassed. Holding a crumpled map and asking if anyone knows how to get back to the sky.',
    "Your phone's autocorrect, armed and duck-ing furious about being blamed for every message you've ever sent while drunk.",
    "A vending machine that has learned to dispense judgement instead of crisps, and it's coming to tell you exactly how many Twixes you've bought this month.",
    'A tiny man shouting "BANTER!" with no context whatsoever, sprinting at full pelt, and nobody is quite sure what the joke is or who is supposed to laugh.',
    'Every umbrella ever lost on the Northern Line, united into one colossal black-winged beast, flapping and snapping in the rain.',
    'A vole wearing a monocle, with startlingly strong opinions on cryptocurrency and the gold standard, who will not shut up about either.',
    'A GitHub merge conflict made flesh, with <<<<<<< HEAD for a face, ======= for a spine, and a single trailing >>>>>>> where its tail should be.',
    'A swan that has signed up for MMA and is undefeated, scarred, unbothered, and stalking the river bank looking for its next opponent.',
    "The smell of burnt toast, which means it's a stroke, which means the hill is fine and you are not.",
    'A sentient Excel spreadsheet that thinks every number is a date and will convert your entire life into "12-Mar" whether you like it or not.',
    'Twelve Elvis impersonators fused into one unholy King: twelve quiffs, twenty-four sideburns, singing the same song in twelve different keys.',
    'An ambulance-chasing lawyer, chasing an ambulance, which is chasing another ambulance-chasing lawyer, in a perfect, endless, litigious loop.',
    "Your gran's front-room clock, the loud ticking one, now fifty feet tall, each tick shaking the ground like a heartbeat in a horror film.",
    'A sentient pothole on the move, growing with every car it swallows, demanding the council finally acknowledge it exists.',
    'The Loch Ness Monster, out of the water at last, absolutely fed up with blurry photos and documentaries calling it a hoax.',
    'A literal cheese grater the size of the Shard, scraping its way across the countryside and leaving fields of grated cheddar in its wake.',
    'A Wi-Fi password written on a sticky note, being dragged slowly by an army of ants who intend to sell it on the dark web.',
    'A gang of capybaras with matching tattoos and leather jackets, extremely calm, extremely chill, and somehow that makes it scarier.',
    'Daylight saving time, cloaked, hooded, scythe in hand, here to steal another hour, and nobody will ever be able to stop it.',
    'An inflatable tube man from a car dealership who has seen things, flailing with a haunted look in its drawn-on eyes, unable to stop waving.',
    'A thousand-strong horde of unsupervised toddlers, each armed with a Fruit Shoot, high on Haribo, with no bedtime and no fear.',
    'The Terms and Conditions you clicked "I agree" to without reading, all 82,000 pages, demanding to be read aloud at last.',
    'A sentient cloud of Wotsits dust, orange and vengeful, reclaiming every finger it has ever stained.',
    "A llama that spits facts AND saliva, and you won't be sure which one hurts more until it's too late.",
    'The Mother of All Mondays, rising from the earth, with an inbox count of 4,000 and a calendar full of back-to-back "quick catch-ups."',
    'A second, longer version of this very list, somehow already being written, coming over the hill faster than you can read it.',
    "It's a monster. Obviously. It always was. You knew the question already, you just wanted to hear someone say it.",
];

/**
 * A hundred childhood stories, cursed, grouped by where they came from.
 * Picked in two steps — category, then tale within it — same shape as
 * UNHINGED_TOYS.
 */
const CURSED_CHILDHOOD_TALES = [
    'Fairy tales' => [
        'Little Red Riding Hood: The Path Was Never Straight. The forest path curves left forever, and she\'s been walking it since 1697. Grandma\'s cottage has a staircase that goes down further than the hill is tall. In the bed lies something wearing Grandma, wearing the wolf, wearing something else, all the way down. "What big eyes you have," she says. It answers, "Which ones?"',
        'Snow White: The Mirror Answers. The Queen never asked who was fairest; she asked what was watching. The mirror is not glass but a membrane, and on the other side something vast has been pressing its face against it for centuries, waiting for someone pure enough to step through. The apple was a key. The glass coffin is a lens.',
        "Hansel: Breadcrumbs. The birds didn't eat the crumbs; they arranged them. When Hansel finally reads the pattern from a treetop, he realises it's a summoning circle the size of the forest, and he's standing in the middle of it.",
        "Gretel: The Oven Remembers. She shoved the witch in and slammed the door, and became a hero. Sixty years later the knocking from inside hasn't stopped, and it's getting politer. Last night it said please. Tonight it said her name in her mother's voice.",
        "Goldilocks: Just Right. Three bowls of porridge, three chairs and three beds, but the measurements are wrong in a way that hurts to look at. The small bowl holds more than the big one, and the middle chair has four legs and also five. When the bears come home, they stand on their hind legs a little too easily. Daddy Bear's smile has a hinge.",
        'Cinderella: Midnight. At the stroke of twelve the coach turned back into a pumpkin, the horses into mice, and the footmen into lizards. The Fairy Godmother never said what Cinderella was before she was a girl. The glass slipper fits because it was moulded from her original foot, and the Prince searches the kingdom for her with a face of pure terror.',
        "Sleeping Beauty: A Hundred Years. She slept for a century so something else could use her dreams as a door. The briars around the castle aren't defensive; they're stitches holding the wound shut. The prince's kiss pulled the last thread, and the castle exhales.",
        "Rapunzel: Let Down Your Hair. Her hair hasn't been cut in eighteen years and is four hundred metres long. It doesn't hang down the tower; it goes down, into the ground, into the roots, into something that has been drinking through it. Every night something climbs. Every morning she's a little lighter.",
        "The Little Match Girl: Every Flame a Window. Each match shows her a warm room, a feast, her dead grandmother. She keeps lighting them because she's freezing, and because on match nine the grandmother turned her head. By match twenty, the windows are showing the room behind her. By the last match, the cold isn't coming from the snow.",
        "The Ugly Duckling: Becoming. The other ducklings were right to be afraid. It wasn't growing into a swan; it was growing into its true shape, very slowly, so nobody would notice. The swans on the lake accept it at once. They bow. They've been waiting.",
        "Thumbelina: Beneath the Petals. A childless woman buys a barleycorn from a witch, and a girl the size of a thumb is born from the flower. Nobody asks what pollinated it. The toad, the mole and the beetle all want to marry her, not for love but because they recognise what she'll hatch into.",
        "The Little Mermaid: From the Trench. She gave up her voice for legs, and every step feels like knives, but that's not the horror. The horror is that her father the Sea King is not a merman. He's the trench. And he's coming up the beach to bring her home, and the tide goes out for three miles first.",
        'Jack: Beans. Five magic beans for one cow. The stalk didn\'t grow up but down from somewhere above the sky. The giant at the top isn\'t a giant; he\'s just normal-sized for where the stalk comes from. "Fee-fi-fo-fum" is not a rhyme. It\'s a coordinate.',
        'Snow-White and Rose-Red: The Visitor in the Snow. A bear knocks at the cottage door on a winter night, and the sisters let it sleep by the fire. Every spring it leaves, every winter it returns, and each year it stands a little more upright. This winter it took off its fur at the door, folded it neatly, and sat down with them.',
        "The Steadfast Tin Soldier: One Leg Short. He was cast last, from not quite enough tin, and so he alone can see the thing in the snuffbox. Nobody believes him. He's tin. He can't move. He can only stand, and watch the lid open a little more every night.",
        'The Princess and the Pea: Twenty Mattresses. The queen put a pea under twenty mattresses and twenty featherbeds to test a princess. The princess lay awake all night, bruised. The queen announced she was real royalty. In the morning servants removed every mattress and found no pea, just a small round hole in the bed frame, going down.',
        "The Six Swans' Sister: Silence. Six years without speaking, weaving shirts from nettles to turn her brothers back from swans. The nettles grow from a graveyard. They whisper while she works. If she says one word the spell fails, but they say plenty, and she's begun to understand them.",
        "The Shoemaker: The Little Helpers. Every night tiny cobblers finish his shoes, flawlessly. He grows rich. One night he hides to watch them work and learns they're not elves but larvae. The shoes are cocoons, and every customer is walking out with one on each foot.",
        "The Goose Girl: Falada Speaks. Her horse's head was nailed above the city gate, and every morning it speaks to her. It began with sympathy. Now it's giving instructions. The city gate is very old. The horse says it was built to keep something in.",
        "Simpleton: The Golden Goose. Everyone who touches the golden goose sticks to it: the innkeeper's daughters, the parson, the sexton, two farmers. The procession grows to the length of a village. Nobody can let go, and Simpleton is laughing, leading them all toward the sea at a steady walk, and he hasn't blinked in days.",
        "The Fir Tree: Evergreen. A little fir tree spent its whole life wishing to be chosen, and finally it was, cut down, decorated and loved for one night. Then it was burnt. The roots are still in the ground, and they remember, and they're spreading under the town looking for the family.",
        "Gerda: The Snow Queen's Shard. A splinter of the troll mirror lodges in Kay's eye, and he stops seeing beauty. He sees the truth instead: the true angles of the world, the ones that don't add up. Gerda crosses a frozen continent to save him and finds him in the ice palace, finally happy, solving a puzzle that will end everything.",
        "Beauty: The Rose. She took her father's place in the Beast's castle and learned to love him. When the curse broke and he became a prince, she screamed. Because the Beast was the mask. The prince is what was underneath it, wearing a face it learned from portraits.",
        "Tom Thumb: Swallowed. Tom gets swallowed by a cow, a fish, a wolf and a giant, and always comes back out. But each time he's inside something there's more room in there than there should be, and he keeps meeting the other Toms who didn't make it.",
        "The Star Money Girl: Falling Stars. A poor girl gives away everything until she stands naked in the woods, and the stars fall into her apron as coins. That's where the original story stops. This film continues: the stars don't stop falling. Each one is still white-hot, still alive, and they've chosen her as their nest.",
    ],
    'Fables and folk tales' => [
        'The Gingerbread Man: Run Run Run. "You can\'t catch me," he shouts, and he\'s right. Nobody can, because the thing that baked him has been chasing him since the oven door opened and he\'s never once looked back. The fox offering a lift across the river is the only one who understands. The fox is also running.',
        "The Three Little Pigs: Huff. The wolf blew down the straw house and the stick house, but couldn't blow down the brick house. So he stopped blowing and started breathing in. Nobody had thought about that. Now the third pig sits in the brick house as the air gets thinner and the walls start to bow inward.",
        "Chicken Licken: The Sky Is Falling. An acorn hits her on the head, and she runs to tell the King. Henny Penny, Cocky Locky, Ducky Lucky, Goosey Loosey and Turkey Lurkey all believe her. Foxy Loxy offers to lead them to safety and eats them. The film ends on the King looking up. The sky is falling. It wasn't an acorn.",
        "The Tortoise: Slow and Steady. The hare fell asleep and the tortoise won. But the tortoise never stopped at the finish line. It's still going, at the same speed, in a straight line, through mountains, through cities, through you. It has been racing for ten thousand years and the finish line it's aiming for isn't on this planet.",
        "The Crow: Sing For Me. The fox flatters the crow into singing so she'll drop her cheese. She opens her beak and sings, and the note she produces is not a crow's note. Every animal in the wood goes silent. The fox runs. The cheese is still falling.",
        "The Country Mouse: Under the City. The town mouse's feasts are lavish, but they're interrupted by cats and dogs, and the country mouse heads home. That's the fable. This film is about the noise the town mouse never mentioned, coming from the walls, from the walls inside the walls, the ones that run down under the cellars into a city that's much older.",
        "The Lamb: Downstream. The wolf accuses the lamb of muddying his drinking water, even though the lamb is downstream. The lamb is right; it wasn't him. Upstream, something enormous is bathing in the river, and the water is turning a colour with no name.",
        "The Mouse: The Net. The lion spares the mouse, and later the mouse gnaws through the hunters' net to free him. Then she sees the hunters. They have too many joints and they move together like a flock, and they don't seem angry at all. They seem delighted.",
        'The Littlest Billy Goat Gruff: Under the Bridge. "Eat my bigger brother instead," he tells the troll, and trots across. The troll was never the danger; the troll was the guard. The meadow on the other side, the green grass all three goats crossed for, has teeth.',
        "Peter: The Meadow Gate. Grandfather told Peter never to go into the meadow, because the wolf might come. Grandfather lied. The wolf is fine. Grandfather locked the gate because the meadow was hungry, and the grass already knows Peter's footsteps.",
        "The Duck: Still Quacking. At the end of the story, you can hear the duck quacking inside the wolf, because the wolf swallowed her alive. It's been decades. She's still quacking, from the wolf, from the zoo, from the wolf's grave. Now from under the city. She's learned other words.",
        "The Babes in the Wood: Covered in Leaves. Two orphans are abandoned in the forest to die, and robins cover their bodies with leaves. The robins kept going. Leaf after leaf, year after year, building a mound that's now a hill, with two small heartbeats under it, and very large ones.",
    ],
    'Nursery rhymes' => [
        'Little Bo-Peep: Leave Them Alone. "Leave them alone and they\'ll come home," everyone said. Bo-Peep did, and they came home, single file in perfect rows, but they\'re the wrong colour now. They stand around her bed at night and bleat in harmony. Something in the hills sent them back as a message.',
        "Mary: It Followed Her. Mary had a little lamb. It followed her to school one day, which was against the rules. It followed her home. It followed her to college, to her wedding, to the hospital where she gave birth. It's been following her for eighty years. It's never aged. It's never once looked away.",
        "The Lamb: Fleece White as Snow. Nobody has ever sheared Mary's lamb. Every attempt ended with the shearer running. Underneath the white fleece isn't skin; it's a door.",
        "Little Boy Blue: Asleep in the Haystack. The sheep's in the meadow, the cow's in the corn, and Little Boy Blue is under the haystack, fast asleep. He won't wake up. Someone is blowing his horn, though, and the cows have stopped moving, and the corn is leaning toward the sound.",
        "Little Miss Muffet: Beside Her. She was eating her curds and whey when a spider sat down beside her. Except it wasn't a spider; it was the tip of one leg, belonging to something under the meadow that had grown to the size of the county. Miss Muffet ran. The leg stayed where it was, patient.",
        "Jack and Jill: The Well at the Top. Why would anyone put a well at the top of a hill? Jack and Jill go up to fetch a pail of water, and Jack falls down and breaks his crown, and Jill comes tumbling after. They don't stop tumbling. The hill goes down forever on the other side.",
        "Humpty Dumpty: The Great Fall. He sat on a wall, and he had a great fall. All the king's horses and all the king's men couldn't put him back together. Not because the pieces were broken, but because of what came out. The king's men have been guarding that wall ever since, and none of them talk about what hatched.",
        'The Owl and the Pussycat: A Year and a Day. They went to sea in a beautiful pea-green boat with honey and plenty of money, and sailed for a year and a day to the land where the Bong-tree grows. They married, danced by the light of the moon, and never came back. Postcards still arrive. The handwriting gets stranger every year. The last one was just the moon, drawn with too many sides.',
        "Simple Simon: Fishing in a Pail. Simple Simon went a-fishing for a whale, and all the water he had was in his mother's pail. Everyone laughed. He kept fishing. On the ninth day, the line went tight, and kept going tight, and the pail is a pail on the outside only.",
        'Baa Baa Black Sheep: Three Bags Full. "Have you any wool?" "Yes sir, yes sir, three bags full." One for the master, one for the dame, and one for the little boy who lives down the lane. Nobody has ever asked what\'s in the bags, or why the little boy down the lane has never been seen, or why the bags are moving.',
        "The Three Little Kittens: Lost Mittens. They lost their mittens and began to cry. They found them again, later, on someone else's hands, someone very tall walking down the lane at dusk, waving.",
        "Little Jack Horner: In the Corner. He sat in the corner eating his Christmas pie, stuck in his thumb, and pulled out a plum. Then the pie pulled back. The corner of the room isn't a corner any more. It's an angle that keeps going, and Jack is very slowly being reeled in.",
        "Incy Wincy Spider: Up the Spout Again. Down came the rain and washed the spider out. Out came the sun and dried up all the rain, and Incy Wincy climbed the spout again. And again. And again. Every time it comes back up the spout it's a little bigger. The spout's been replaced with a wider one twice. The rain is now afraid of it.",
    ],
    "Classic children's books" => [
        "Winnie-the-Pooh: Hunny. Pooh has eaten honey from the same jar every day for a hundred years, and it's never run out. He's never looked at the bottom. The bottom is looking at him. The Hundred Acre Wood is exactly one hundred acres, measured from every direction, which isn't possible.",
        "Piglet: Very Small Animal. Piglet has always been afraid of Heffalumps and Woozles. Everyone told him they weren't real. Everyone was wrong, and Piglet is the only one who's been keeping count of the footprints around the tree. There are more of them every time they go round.",
        "Roo: The Pouch. Kanga's pouch is deeper than Kanga is. Roo has been exploring it for years. He's found rooms down there, and corridors, and a door with a nameplate with his name on it, older than he is.",
        "Christopher Robin: Grown Up. He left the Wood at the end of childhood. Fifty years later he returns, and the animals are waiting, but they've been waiting a very long time with nobody to play with, and they have grown up too, in their own way. They want to play the old games. All of them, forever.",
        'Tigger: Bouncy. "The wonderful thing about Tiggers is I\'m the only one." Except every time he bounces, he comes down somewhere else, a little further from the Wood. He\'s met the other Tiggers out there. They\'re not wonderful.',
        "Paddington Bear: Please Look After This Bear. He arrived at the station with a suitcase, a hat and a label. Darkest Peru isn't a place on any map the Browns can find. Aunt Lucy's letters arrive weekly from a postmark that doesn't exist, and lately they've included instructions for the family.",
        "Peter Rabbit: Mr McGregor's Garden. Peter lost his little blue jacket in Mr McGregor's garden, and Mr McGregor hung it on a scarecrow. The scarecrow is moving now, every night a few inches closer to the rabbit hole, and Peter's mother has stopped letting anyone sleep.",
        "Benjamin Bunny: The Onions. Benjamin and Peter go back for the clothes, and grab onions from the garden as a gift for Mrs Rabbit. The onions have layers that don't end. The soil in the vegetable patch has been fed something for generations. Mr McGregor's wife's famous rabbit pie has a secret ingredient, and it is not rabbit.",
        "Jemima Puddle-Duck: The Gentleman With Sandy Whiskers. He offers her a quiet shed for her eggs, full of feathers. So many feathers. Far more feathers than one gentleman could have collected from one farm, and they're not all from ducks, and some of them are still warm.",
        "Mrs Tiggy-Winkle: Washing Day. She takes in laundry for all the animals of the hills, and returns it spotless. Except the stains don't come out; she moves them. Into the hill. Every stain the animals ever made is in there, and the hill is beginning to breathe.",
        "Tom Kitten: Roly-Poly. Rats roll Tom in dough to make a pudding, and he's rescued just in time. But the rats were never the hungry ones. They were only cooking. The thing in the walls that they were cooking for has been waiting very patiently for its dinner.",
        "Pinocchio: A Real Boy. The Blue Fairy granted Geppetto's wish, and the puppet became a real boy. A real boy. Specifically, a real boy, one who had died, centuries ago, and been waiting in the wood of the tree.",
        "Heidi: Higher Up the Mountain. Grandfather's hut is the last building on the mountain, because nothing above it is right. Heidi goes higher every summer with the goats. The goats come back different. Peter the goatherd hasn't spoken since July. And the mountain grows a few feet every year.",
        "Alice: The Rabbit Hole. She followed the White Rabbit down the hole and fell for a long time. Long enough to read, and nap, and grow up and grow old in the falling. She hasn't landed. Wonderland was only the bit she saw on the way down. She's still falling, past things that make the Jabberwock look like a kitten.",
        "Dorothy Gale: No Place Like Home. She clicked her heels three times and woke up in Kansas, in her own bed. Aunt Em and Uncle Henry are thrilled. The farmhands are thrilled. Toto won't stop growling at her.",
        'The Tin Woodman: A Heart. The Wizard gave him a heart, a silk one, stuffed with sawdust. It began to beat. It beats in a rhythm that no human heart has ever kept, and the Wizard is now very far away, very quickly.',
        "Mole: Spring Cleaning. Mole was spring-cleaning when he got fed up, threw down his brush and dug up into the open air. He forgot to close the tunnel behind him. Something followed him up, and it's been riding in the boat with him and Ratty ever since, under the water, keeping pace.",
        'Wilbur: Some Pig. The words in the web saved his life: "Some Pig," "Terrific," "Radiant," "Humble." Charlotte didn\'t write the last one. Charlotte is dead. Words keep appearing in webs all over the farm, and they\'ve started spelling out dates.',
        'Fern Arable: The Runt. She stopped her father killing the runt of the litter, and the farm never forgave her. The farm, not the family. The fields went quiet. The animals stopped speaking. Something in the soil had been promised that pig.',
        'The Very Hungry Caterpillar: Saturday. On Saturday it ate through one piece of chocolate cake, one ice cream cone, one pickle, one slice of Swiss cheese, one slice of salami, one lollipop, one piece of cherry pie, one sausage, one cupcake and one slice of watermelon. On Sunday it was still hungry. On Monday it ate the town. The cocoon is the size of a cathedral now, and humming.',
        "Spot the Dog: Where's Spot? Is he behind the door? No. Is he under the stairs? No. Is he inside the clock? No. Is he in the piano? No. Don't lift the last flap. Mum lifted the last flap. Where's Mum?",
        "Kipper: The Basket. Kipper sleeps in his basket and dreams, and the dreams don't stay in the basket. Tiger's started having them. The whole street's started having them. In the dreams, there's a basket at the bottom of the sea, and something is sleeping in it, and it is dreaming of Kipper.",
        "Elmer: Patchwork. Elmer is a patchwork of bright colours, and the other elephants love him. One grey elephant asks where the colours came from. Elmer smiles. Every herd he's visited has had one fewer grey elephant afterward.",
        "Sophie: The Tiger Who Came to Tea. He ate all the sandwiches, all the cakes, all the food in the fridge, drank all of Daddy's beer and all the water in the taps, and left. Except he didn't leave. He's still at the table. He's been sitting at the table for forty years, and Sophie's mother has to keep setting him a place, because the last time she didn't, the house became very small.",
        "Thomas the Tank Engine: The Branch Line. There's a station on the Island of Sodor that isn't on the Fat Controller's map. Only Thomas goes there. He comes back with carriages that aren't Annie and Clarabel, and the passengers who board them never get off at the other end.",
        "Postman Pat: Return to Sender. One parcel, brown paper, no address, no stamp. It's warm, and it ticks like a clock running backwards. Pat delivers it house to house in Greendale, and everyone says it's not for them. Everyone who touched it is now speaking in a language with no vowels. Jess the cat won't come near the van.",
        "Noddy: Toyland. The bell on top of Noddy's hat rings whenever he nods. It's started ringing when he's still. It's started ringing when he's asleep. Big Ears has worked out it's being rung from the other side.",
        "Little Grey Rabbit: The Hedgerow. Something very old lives at the bottom of the hedgerow. It's always been polite, and it only asks for small things: a thimble, a button, a squirrel. Little Grey Rabbit has been keeping it fed for years. Squirrel and Hare have no idea what she's protecting them from.",
        'Babar: The King of the Elephants. Babar returned from the city and founded Celesteville, a gleaming capital in the jungle. Nobody asks why the ground was already flat there, or what the elephants found when they dug the foundations, or why the old king who died before Babar ate a mushroom he found in the exact spot where the palace now stands.',
        "Curious George: Too Curious. He's a good little monkey, and always very curious. That's the problem. The Man in the Yellow Hat had one rule: don't open the trunk. George opened the trunk.",
        "Madeline: Twelve Little Girls. In an old house in Paris covered in vines lived twelve little girls in two straight lines. Miss Clavel counts them every night. Lately, there are thirteen. Nobody can tell which one is new, and all thirteen of them say it's Madeline.",
        "Pollyanna: The Glad Game. Pollyanna finds something to be glad about in everything, no matter how terrible. The town loves her for it. Then the terrible things get stranger, and she keeps being glad about them, glad about the shapes in the sky and the sounds under the town, and the town starts to wonder what side she's on.",
        'Cedric: The Inheritance. A sweet American boy inherits an English earldom and wins over his grumpy grandfather. But the estate comes with a debt, an ancient one, owed to something under the chapel. Every heir has paid it once. Cedric is very sweet and very young, and grandfather is suddenly being very, very kind.',
        'Beth March: The Piano. Beth was the gentlest of the four sisters, and she died young. Her piano still plays in the evenings. The tune is the one she always played, at first. Then it changes, every night a little more, into a song made for an instrument with more keys than a piano has.',
        "The Little Prince: Asteroid B-612. He left his tiny planet with its one rose and three volcanoes to explore the universe. But one of those volcanoes was never extinct. He tamed something out there, just as the fox taught him, and he is responsible for what he's tamed. It's coming to find him.",
        "Bambi: Man Is in the Forest. Bambi's mother told him Man was the greatest danger in the forest. She was wrong. Man is afraid of the forest too. That's why Man comes with guns. That's why Man keeps setting fires.",
        "Dumbo: The Magic Feather. Dumbo didn't need the feather to fly, but he needed it to come back down. Without it he just keeps rising, ears spread, over the circus, over the clouds, up and up, and the higher he goes the more he can see what's waiting above the sky.",
        "Stuart Little: Born Small. The Littles' second son was born the size and shape of a mouse. The doctors couldn't explain it. Nor could anyone else. He's polite and charming and well-dressed and remarkably happy, and he never casts a shadow.",
        "The BFG: Dream Country. He catches dreams in glass jars and blows the good ones into children's bedrooms. One jar is different. It's been on the top shelf for three hundred years, and one night, very quietly, the lid began to unscrew from the inside.",
        "Charlie Bucket: The Golden Ticket. Five children enter the chocolate factory, and only one comes out with the keys. The factory chose him. The factory has always chosen. The Oompa-Loompas sing a song for every child that disappears, and they've been singing that song for a very long time.",
        "Ferdinand the Bull: Smelling the Flowers. Ferdinand was supposed to fight in the ring in Madrid, but he just sat down in the middle and smelled the flowers in the ladies' hair. The matador was furious. But Ferdinand had seen what was sitting in the stands, among the crowd, wearing a crowd, and decided the safest thing was to keep very, very still.",
    ],
    'Myths, legends and Bible stories' => [
        "Baby Moses: The Reeds. His mother put him in a basket in the Nile to save his life, and Pharaoh's daughter found him in the reeds. Between the two, the basket drifted for three days, and spent one night somewhere the river doesn't usually go. He came back with eyes that didn't blink and knowledge of words nobody had taught him.",
        'The Lost Sheep: Ninety-Nine. The shepherd left the ninety-nine to search for the one that strayed, and found it in the hills, and carried it home rejoicing. Nobody asks what was watching the ninety-nine while he was gone. When he got back, they were all standing in a perfect circle, facing outward, and none of them blinked.',
        "Young David: The Fifth Stone. He picked up five smooth stones from the brook and needed only one to fell Goliath. He's kept the other four for decades. The brook he took them from has never had stones since, and something is still standing very still in the valley, waiting for him to throw the next one.",
        "Young Samuel: Here I Am. A boy in the temple hears his name called in the night. He runs to Eli, the priest, who says he didn't call. It happens again, and again. Eli tells him to answer. Samuel answers, and the voice keeps calling, every night, for the rest of his life, and it hasn't once said who it is.",
        "Persephone: Six Seeds. She ate six pomegranate seeds in the underworld, and so must spend six months of every year below. That's the myth. In the film, the thing below counts differently. Six seeds, six months, six years, six aeons. She has never come back up. What returns each spring wears her face, and makes the flowers grow in strange shapes.",
        "Pandora: Hope. She opened the jar, and every evil flew into the world: sickness, sorrow, war. She slammed the lid shut, and only Hope was left inside. Everyone has always assumed that was a good thing. But nobody has asked why the gods packed Hope in with all the other evils, or why it's been scratching at the lid so patiently.",
        "Androcles: The Thorn. He pulled a thorn from a suffering lion's paw, and years later in the arena the lion spared him. But it wasn't a thorn. It was a tooth. And whatever lost it has been looking for it ever since, and Androcles kept it in a pouch around his neck as a lucky charm.",
        "Gelert: Faithful. The prince came home to find his cradle overturned and his hound Gelert covered in blood. He killed the dog in rage, then heard the baby crying, safe, beside the corpse of a huge wolf Gelert had fought off. That's the legend. The film asks: if it wasn't the baby's blood, and it wasn't the wolf's, whose was it? And what did Gelert know about that baby?",
        "The Traveller: The Road to Jericho. A man is beaten and left half-dead in a ditch. A priest passes by on the other side of the road. So does a Levite. Everyone assumes they were heartless. They weren't. They'd seen what was in the ditch beside him. Only the Samaritan stopped, because he couldn't see it. He's been seeing it ever since.",
    ],
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

/** The Alexa pile secret, from the environment or PILE_SECRET_FILE. Empty means off. */
function pile_secret(): string
{
    $secret = getenv(PILE_SECRET_ENV);
    if (is_string($secret) && trim($secret) !== '') {
        return trim($secret);
    }
    return is_file(PILE_SECRET_FILE) ? trim((string) file_get_contents(PILE_SECRET_FILE)) : '';
}

/**
 * Whose pile (and fingers) this request is about. Normally the caller's
 * IP, via client_ip(). The Alexa skill instead sends 'alexa:' plus a
 * sha256 of the user's Alexa userId, which only counts when it arrives
 * with the matching PILE_SECRET_HEADER — see the note on PILE_ID_HEADER
 * in config.php.
 */
function pile_id(): string
{
    $secret  = pile_secret();
    $claimed = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', PILE_ID_HEADER))] ?? null;
    $given   = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', PILE_SECRET_HEADER))] ?? null;

    if ($secret !== '' && is_string($claimed) && is_string($given)
        && hash_equals($secret, $given)
        && preg_match('/^alexa:[0-9a-f]{64}$/', $claimed) === 1) {
        return $claimed;
    }
    return client_ip();
}

/**
 * Anonymises a pile ID for public display: the final octet (or, for
 * IPv6, final hextet) is knocked off, and an Alexa pile is cut down to
 * its first eight hex characters.
 */
function mask_ip(string $id): string
{
    if (str_starts_with($id, 'alexa:')) {
        return substr($id, 0, 14) . '…';
    }
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
 * only shows while this API is running behind the latest published
 * GitHub release — i.e. there's a newer release than what's deployed,
 * so pointing people at "the latest release" actually tells them
 * something they don't already have. The moment this API's version
 * catches up (or pulls ahead of) the latest release, it's redundant —
 * you're already running the newest thing — so it hides itself.
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
        // Can't tell — assume this API isn't behind rather than show
        // a note that might not be accurate.
        return false;
    }

    return version_compare(APP_VERSION, $latest, '<');
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
            'GET /excuses/inlaws'    => "A reason you can't visit the in-laws. Two hundred of them, in ten categories.",
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
            'GET /unhinged/poke' => 'Poke someone, then escalate dramatically. Fifty ways.',
            'GET /unhinged/storage-buddies' => 'A piece of furniture starts following you. Fifty of them.',
            'GET /unhinged/fate-arrived' => 'Fate has arrived, badly. A hundred ways.',
            'GET /unhinged/its-fine' => "It's fine. Probably. A hundred ways.",
            'GET /unhinged/suddenly-sideways' => 'Everything has gone sideways. A hundred ways, in seven categories.',
            'GET /unhinged/adulting-sick-note' => "A doctor's note for the adult malady of being alive. A hundred, in ten categories.",
            'GET /unhinged/its-now-fizzy' => 'Everything is now fizzy. A hundred ways, in ten categories.',
            'GET /unhinged/random-boulder' => 'A boulder is rolling at you. Two hundred of them, in eighteen categories.',
            'GET /unhinged/toys' => 'A toy, with something wrong with it. A hundred of them, in nine categories.',
            'GET /unhinged/whats-that' => 'Something is coming over the hill. A hundred things it could be.',
            'GET /cursed/childhood-tales' => 'A childhood story, cursed. A hundred of them, in five categories.',
            'GET /healthz'           => 'Liveness, plus lifetime request, unique-IP, and rocks-kicked counts.',
        ],
        'notes' => $notes,
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
        'notes'        => ['Top 20 by volume. IPs are shown with the final octet removed; Alexa piles as the first few characters of an anonymous ID.'],
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

function handle_excuses_inlaws(): never
{
    $category = array_rand(INLAWS_EXCUSES);

    send(200, [
        'instruction' => 'The in-laws are expecting you this weekend.',
        'reason'      => pick(INLAWS_EXCUSES[$category]),
        'category'    => $category,
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

function handle_poke(): never
{
    send(200, [
        'instruction' => 'Poke them. See what happens.',
        'poke'        => pick(POKE_RESPONSES),
    ]);
}

function handle_storage_buddies(): never
{
    $entry = pick(STORAGE_BUDDIES);

    send(200, [
        'instruction' => 'A piece of furniture has taken an interest in you.',
        'item'        => $entry['item'],
        'effect'      => $entry['effect'],
    ]);
}

function handle_fate_arrived(): never
{
    send(200, [
        'instruction' => 'Fate has arrived.',
        'fate'        => pick(FATE_ARRIVED_RESPONSES),
    ]);
}

function handle_its_fine(): never
{
    send(200, [
        'instruction'  => 'Everything is under control.',
        'reassurance'  => pick(ITS_FINE_RESPONSES),
    ]);
}

function handle_suddenly_sideways(): never
{
    $category = array_rand(SUDDENLY_SIDEWAYS);

    send(200, [
        'instruction' => 'Everything has gone sideways.',
        'scenario'    => pick(SUDDENLY_SIDEWAYS[$category]),
        'category'    => $category,
    ]);
}

function handle_adulting_sick_note(): never
{
    $category = array_rand(ADULTING_SICK_NOTES);

    send(200, [
        'instruction' => "A doctor's note has been issued.",
        'note'        => pick(ADULTING_SICK_NOTES[$category]),
        'category'    => $category,
    ]);
}

function handle_its_now_fizzy(): never
{
    $category = array_rand(ITS_NOW_FIZZY);

    send(200, [
        'instruction' => 'Everything is now fizzy.',
        'fizzy'       => pick(ITS_NOW_FIZZY[$category]),
        'category'    => $category,
    ]);
}

function handle_random_boulder(): never
{
    $category = array_rand(RANDOM_BOULDER);

    send(200, [
        'instruction' => 'A boulder is rolling at you.',
        'boulder'     => pick(RANDOM_BOULDER[$category]),
        'category'    => $category,
    ]);
}

function handle_toys(): never
{
    $category = array_rand(UNHINGED_TOYS);

    send(200, [
        'instruction' => 'You have been given a toy.',
        'toy'         => pick(UNHINGED_TOYS[$category]),
        'category'    => $category,
    ]);
}

function handle_whats_that(): never
{
    send(200, [
        'instruction' => "What's that coming over the hill?",
        'answer'      => pick(WHATS_THAT_RESPONSES),
    ]);
}

function handle_cursed_childhood_tales(): never
{
    $category = array_rand(CURSED_CHILDHOOD_TALES);

    send(200, [
        'instruction' => 'Once upon a time.',
        'tale'        => pick(CURSED_CHILDHOOD_TALES[$category]),
        'category'    => $category,
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

// CORS preflight: answered directly, before it touches stats or routing.
if ($method === 'OPTIONS') {
    http_response_code(204);
    send_cors_headers();
    exit;
}

// ?migration_debug=1: reports on the legacy-/tmp migration without
// running it, so whether the legacy data is even still there (the OS can
// sweep /tmp — see PILE_DATA_DIR's docblock in config.php) and whether
// the PILES_BACKUP_MIN_INTERVAL throttle is currently blocking a retry
// can both be checked directly against the live server, no shell needed.
if ($method === 'GET' && isset($_GET['migration_debug'])) {
    $liveDir         = pile_dir();
    $legacyDir       = sys_get_temp_dir() . '/jar';
    $legacyFiles     = is_dir($legacyDir) ? (glob($legacyDir . '/*.json') ?: []) : [];
    $legacyStatsPath = $legacyDir . '/_lifetime_stats';

    $backupRaw  = @file_get_contents(PILES_BACKUP_FILE);
    $backupData = $backupRaw !== false ? json_decode($backupRaw, true) : null;
    $backupAge  = is_array($backupData) && isset($backupData['written_at'])
        ? time() - (int) $backupData['written_at']
        : null;

    send(200, [
        'sys_get_temp_dir'               => sys_get_temp_dir(),
        'legacy_dir'                     => $legacyDir,
        'legacy_dir_exists'              => is_dir($legacyDir),
        'legacy_json_file_count'         => count($legacyFiles),
        'legacy_stats_file_exists'       => is_file($legacyStatsPath),
        'legacy_stats_contents'          => is_file($legacyStatsPath)
            ? json_decode((string) @file_get_contents($legacyStatsPath), true)
            : null,
        'live_pile_dir'                  => $liveDir,
        'live_stats_file_exists'         => is_file(stats_path()),
        'live_stats_contents'            => stats_snapshot(),
        'piles_backup_file_exists'       => $backupRaw !== false,
        'piles_backup_age_seconds'       => $backupAge,
        'piles_backup_throttle_seconds'  => PILES_BACKUP_MIN_INTERVAL,
        'migration_would_run_now'        => $backupAge === null || $backupAge >= PILES_BACKUP_MIN_INTERVAL,
    ]);
}

// ?migration_force=1: one-time manual recovery. migrate_legacy_piles()
// only copies _lifetime_stats in when the live file doesn't exist yet —
// but a live file already got created (by stats_record() running before
// this fix landed) before any copy could happen, so the automatic path
// will now skip it forever, throttle or no throttle. This instead merges
// (sums total_requests and counters, unions the ip-hash sets) rather than
// overwriting, and copies over any still-missing legacy pile/appendage
// files, ignoring the PILES_BACKUP_MIN_INTERVAL throttle entirely. Safe
// to call more than once: a live 'legacy_merged_at' flag, set inside the
// same locked read-modify-write as the merge, stops a second call from
// double-counting the same legacy numbers again.
if ($method === 'GET' && isset($_GET['migration_force'])) {
    $liveDir   = pile_dir();
    $legacyDir = sys_get_temp_dir() . '/jar';

    $copied = [];
    if (is_dir($legacyDir) && realpath($legacyDir) !== realpath($liveDir)) {
        foreach (glob($legacyDir . '/*.json') ?: [] as $legacyFile) {
            $base   = basename($legacyFile);
            $target = $liveDir . '/' . $base;
            if (!is_file($target) && @copy($legacyFile, $target)) {
                $copied[] = $base;
            }
        }
    }

    $legacyStatsPath = $legacyDir . '/_lifetime_stats';
    $merged          = false;
    $alreadyMerged   = false;
    if (is_file($legacyStatsPath)) {
        $legacyStats = json_decode((string) @file_get_contents($legacyStatsPath), true);
        if (is_array($legacyStats)) {
            json_file_update(stats_path(), static function (array $live) use ($legacyStats, &$merged, &$alreadyMerged): array {
                if (!empty($live['legacy_merged_at'])) {
                    $alreadyMerged = true;
                    return $live;
                }

                $live['total_requests'] = (int) ($live['total_requests'] ?? 0) + (int) ($legacyStats['total_requests'] ?? 0);

                $liveIps   = is_array($live['ips'] ?? null) ? $live['ips'] : [];
                $legacyIps = is_array($legacyStats['ips'] ?? null) ? $legacyStats['ips'] : [];
                $live['ips'] = $liveIps + $legacyIps;

                $liveCounters   = is_array($live['counters'] ?? null) ? $live['counters'] : [];
                $legacyCounters = is_array($legacyStats['counters'] ?? null) ? $legacyStats['counters'] : [];
                foreach ($legacyCounters as $key => $value) {
                    $liveCounters[$key] = (int) ($liveCounters[$key] ?? 0) + (int) $value;
                }
                $live['counters'] = $liveCounters;

                $live['legacy_merged_at'] = gmdate('c');
                $merged = true;
                return $live;
            });
        }
    }

    send(200, [
        'stats_merged_this_call'  => $merged,
        'stats_already_merged'    => $alreadyMerged,
        'pile_files_copied'       => $copied,
        'pile_files_copied_count' => count($copied),
        'live_stats_after'        => stats_snapshot(),
    ]);
}

// Must run before stats_record() below: that call creates
// pile_dir()/_lifetime_stats on the spot if it doesn't exist yet
// (json_file_update() opens it with 'c+'), and migrate_legacy_piles()
// only copies the legacy /tmp file in when the live target is still
// missing. Migrating first is what gives it a target to find missing.
migrate_legacy_piles();

// Every request counts towards lifetime stats, surfaced at GET /healthz.
stats_record(pile_id());

// Mirrors those stats into the webspace, throttled — see stats_backup().
stats_backup();

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
    $method === 'GET' && $path === '/excuses/inlaws'        => handle_excuses_inlaws(),
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
    $method === 'GET' && $path === '/unhinged/poke'        => handle_poke(),
    $method === 'GET' && $path === '/unhinged/storage-buddies' => handle_storage_buddies(),
    $method === 'GET' && $path === '/unhinged/fate-arrived' => handle_fate_arrived(),
    $method === 'GET' && $path === '/unhinged/its-fine'    => handle_its_fine(),
    $method === 'GET' && $path === '/unhinged/suddenly-sideways' => handle_suddenly_sideways(),
    $method === 'GET' && $path === '/unhinged/adulting-sick-note' => handle_adulting_sick_note(),
    $method === 'GET' && $path === '/unhinged/its-now-fizzy' => handle_its_now_fizzy(),
    $method === 'GET' && $path === '/unhinged/random-boulder' => handle_random_boulder(),
    $method === 'GET' && $path === '/unhinged/toys'        => handle_toys(),
    $method === 'GET' && $path === '/unhinged/whats-that'  => handle_whats_that(),
    $method === 'GET' && $path === '/cursed/childhood-tales' => handle_cursed_childhood_tales(),
    $method === 'GET' && $path === '/healthz'             => handle_healthz(),
    default => send(404, [
        'error'  => 'No such service.',
        'remark' => 'There is, however, a rock. See GET /kick/rocks.',
    ]),
};
