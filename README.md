# The API of Chaos

**v1.0.0** — Dismissal, at scale, with an SLA of none.

**Base URL** - https://api.dumpsterfire.uk

## Endpoints

| Method | Path | Description |
|---|---|---|
| GET | /kick/rocks | Assigns a rock. Optional: ?tier=n, ?min=&max= |
| GET | /kick/rocks/tiers | The full scale, tier 1 through 14. |
| GET | /kick/munitions | Assigns an unintentionally-lost munition. Tells you the tier and the arc.| 
| GET | /kick/munitions/tiers | The full scale, tier 1 through 50, in five ten-tier arcs.| 
| GET\|POST | /pound/dirt | Adds to your pile. Optional: ?pile=name |
| GET | /pound/dirt/status | Peek at the pile without pounding it. |
| GET | /pound/dirt/tiers | The full scale, fistful through second moon. |
| GET | /pound/dirt/leaderboard | Top 20 piles, ranked. IPs shown with the final octet removed. |
| DELETE | /pound/dirt | Reset the pile. Only from the IP that raised it. |
| GET | /excuses/teams | A reason not to join the call. |
| GET | /excuses/social | A reason not to attend, with tier. |
| GET | /excuses/social/tiers | The five sub-tiers of social excuse. |
| GET | /excuses/oops | A reason it went wrong, with tier explanation. |
| GET | /excuses/ring-ring | A reason you did not pick up. |
| GET | /excuses/late | A reason you're late. |
| GET | /excuses/alibis | A reason you weren't there.
| GET | /ministry/gentle-correction | Rolls a d6 against the Ministry's approved remedies, graded in newtons. |
| GET | /cage/finger | Put your finger in the cage. 50 animals, 50/50 odds. Costs a finger if taken; once fingers run out, toes are next. |
| GET | /cage/fictional/finger | Put your finger in the cage. 50 fictional creatures this time. Shares your finger/toe count with /cage/finger. |
| GET | /cage/finger/left | How many fingers and toes you have left, out of 10 each. |
| GET | /cage/finger/reset | Pray to the gods of the holy hairy toe for 10 fingers and 10 toes again. |
| GET | /unhinged/8ball | Shake it. It answers, unreliably. |
| GET | /unhinged/optimism | An unearned, unsupported dose of positivity. |
| GET | /unhinged/pessimism | An unearned, unsupported dose of dread. |
| GET | /unhinged/advice | Advice that applies to almost every situation. |
| GET | /unhinged/non-committal | A refusal to answer, fifty ways
| GET | /healthz | Liveness, plus lifetime request, unique-IP, and rocks-kicked counts. |

## Notes

- Piles are files on disk and survive restarts, unlike morale.
- Tier 14 is the Moon. There is no tier 15.
- Pounding is rate-limited to once every 2s per pile. Push through it and the dirt guy quits.
- Any /unhinged request has a 1-in-10 chance of falling into the void instead. Just try again.

## License

[GPL-3.0](LICENSE)
