# the-api-of-chaos
The Chaos API

Version -0.5.∞ — "It Works On My Machine, Probably"

Base URL: https://api.dumpsterfire.uk/ (the version number is decorative)

**Overview**

The Chaos API is a fully unsupported, aggressively opinionated REST-ish interface for people who need to express dissatisfaction, dismiss others, and evade calendar invites at scale. Rate limits are enforced by vibes. Authentication is optional but judged. All timestamps are returned in a timezone we have chosen for you and will not disclose.

{
    "service": "The API of Chaos",
    "version": "1.0.0",
    "tagline": "Dismissal, at scale, with an SLA of none.",
    "endpoints": {
        "GET **/kick/rocks**": "Assigns a rock. Optional: ?tier=n, ?min=&max=",
        "GET **/kick/rocks/tiers**": "The full scale, tier 1 through 14.",
        "GET|POST **/pound/dirt**": "Adds to your pile. Optional: ?pile=name",
        "GET **/pound/dirt/status**": "Peek at the pile without pounding it.",
        "GET **/pound/dirt/tiers**": "The full scale, fistful through second moon.",
        "GET **/pound/dirt/leaderboard**": "Top 20 piles, ranked. IPs shown with the final octet removed.",
        "DELETE **/pound/dirt**": "Reset the pile. Only from the IP that raised it.",
        "GET **/excuses/teams**": "A reason not to join the call.",
        "GET **/excuses/social"**: "A reason not to attend, with tier.",
        "GET **/excuses/social/tiers**": "The five sub-tiers of social excuse.",
        "GET **/excuses/oops**": "A reason it went wrong, with tier explanation.",
        "GET **/ministry/gentle-correction**": "Rolls a d6 against the Ministry's approved remedies, graded in newtons.",
        "GET **/healthz**": "Liveness."
    },
    "notes": [
        "Piles are files on disk and survive restarts, unlike morale.",
        "Tier 14 is the Moon. There is no tier 15."
    ],
    "source": "https://github.com/MichelleFindlay/the-api-of-chaos",
    "license": "GPL-3.0"
}
