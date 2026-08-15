<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API of Chaos — Privacy &amp; Terms</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');

  :root {
    --ink: #1a1714;
    --paper: #e8e2d5;
    --paper-dark: #ddd5c3;
    --spite: #c8442a;
    --spite-dim: #a3341f;
    --ash: #6b6357;
    --line: #b8ad97;
    --stamp: #3a3630;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  html { scroll-behavior: smooth; }

  body {
    background: var(--paper);
    background-image:
      repeating-linear-gradient(0deg, transparent, transparent 27px, rgba(120,110,90,0.06) 27px, rgba(120,110,90,0.06) 28px);
    color: var(--ink);
    font-family: 'Space Grotesk', system-ui, sans-serif;
    line-height: 1.65;
    padding: clamp(1.5rem, 5vw, 5rem) clamp(1rem, 5vw, 2rem);
    -webkit-font-smoothing: antialiased;
  }

  .sheet {
    max-width: 760px;
    margin: 0 auto;
  }

  /* Header */
  .masthead {
    border: 2px solid var(--ink);
    padding: clamp(1.25rem, 4vw, 2.5rem);
    background: var(--paper-dark);
    position: relative;
    margin-bottom: 2.5rem;
  }

  .masthead::before {
    content: "x-powered-by: spite";
    position: absolute;
    top: 0.6rem;
    right: 0.9rem;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.62rem;
    letter-spacing: 0.05em;
    color: var(--spite);
    opacity: 0.8;
  }

  .eyebrow {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.28em;
    text-transform: uppercase;
    color: var(--ash);
    margin-bottom: 0.9rem;
  }

  h1 {
    font-size: clamp(2rem, 8vw, 3.4rem);
    font-weight: 700;
    line-height: 0.98;
    letter-spacing: -0.02em;
    margin-bottom: 1rem;
  }

  h1 .strike {
    color: var(--spite);
  }

  .dek {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.85rem;
    color: var(--stamp);
    max-width: 52ch;
    line-height: 1.55;
  }

  .status-line {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 1.4rem;
    margin-top: 1.4rem;
    padding-top: 1.2rem;
    border-top: 1px dashed var(--line);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.04em;
    color: var(--ash);
    text-transform: uppercase;
  }

  .status-line b { color: var(--ink); font-weight: 600; }

  /* Sections */
  section {
    margin-bottom: 2.75rem;
  }

  .sec-head {
    display: flex;
    align-items: baseline;
    gap: 0.9rem;
    margin-bottom: 1.1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--ink);
  }

  .sec-num {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--spite);
    letter-spacing: 0.05em;
    flex-shrink: 0;
  }

  h2 {
    font-size: clamp(1.15rem, 4vw, 1.55rem);
    font-weight: 500;
    letter-spacing: -0.01em;
    line-height: 1.15;
  }

  p { margin-bottom: 1rem; }
  p:last-child { margin-bottom: 0; }

  .lede {
    font-size: 1.05rem;
  }

  strong { font-weight: 600; }

  a {
    color: var(--spite-dim);
    text-decoration: underline;
    text-underline-offset: 2px;
    text-decoration-thickness: 1.5px;
  }
  a:hover { color: var(--spite); }

  /* The verdict list — plain-english rulings */
  .rulings {
    list-style: none;
    margin: 1.2rem 0;
    border-top: 1px solid var(--line);
  }

  .rulings li {
    display: grid;
    grid-template-columns: minmax(90px, 130px) 1fr;
    gap: 1rem;
    padding: 0.85rem 0;
    border-bottom: 1px solid var(--line);
    align-items: start;
  }

  .rulings .verdict {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--paper);
    background: var(--stamp);
    padding: 0.28rem 0.5rem;
    text-align: center;
    align-self: start;
    line-height: 1.2;
  }

  .rulings .verdict.no { background: var(--spite); }
  .rulings .verdict.yes { background: var(--stamp); }

  .rulings .detail { padding-top: 0.1rem; }
  .rulings .detail b { display: block; margin-bottom: 0.15rem; font-weight: 600; }
  .rulings .detail span { font-size: 0.92rem; color: var(--ash); }

  /* Callout */
  .callout {
    border-left: 3px solid var(--spite);
    padding: 0.9rem 0 0.9rem 1.2rem;
    margin: 1.4rem 0;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.82rem;
    line-height: 1.6;
    color: var(--stamp);
    background: var(--paper-dark);
  }

  /* Footer */
  footer {
    margin-top: 3.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid var(--ink);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: var(--ash);
    letter-spacing: 0.03em;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1rem;
  }

  footer a { color: var(--stamp); }

  .toe {
    color: var(--spite);
    font-weight: 600;
  }

  @media (max-width: 520px) {
    .rulings li { grid-template-columns: 1fr; gap: 0.4rem; }
    .rulings .verdict { justify-self: start; }
  }

  @media (prefers-reduced-motion: no-preference) {
    section { animation: rise 0.5s ease both; }
    @keyframes rise {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: none; }
    }
  }
</style>
</head>
<body>
<main class="sheet">

  <header class="masthead">
    <div class="eyebrow">The Ministry of Chaos · filed under obligation</div>
    <h1>Privacy Policy<br>&amp; <span class="strike">Terms of Misuse</span></h1>
    <p class="dek">The legally-required paperwork for the API of Chaos and its Alexa skill. It assigns you rocks, loses munitions in your general direction, and pounds dirt into a pile. Someone insisted this page exist. Here it is.</p>
    <div class="status-line">
      <span>Governs: <b>api.dumpsterfire.uk</b></span>
      <span>Skill: <b>API of Chaos</b></span>
      <span>Last poked: <b>August 2026</b></span>
    </div>
  </header>

  <section>
    <div class="sec-head">
      <span class="sec-num">01</span>
      <h2>The short version</h2>
    </div>
    <p class="lede">This is a joke API that returns nonsense on demand. It is not a real service, it holds nothing of value about you, and it would like to be left roughly alone. If you want the whole thing in five plain rulings, here they are.</p>

    <ul class="rulings">
      <li>
        <span class="verdict no">No</span>
        <span class="detail"><b>We don't collect your personal information</b><span>No names, no emails, no accounts, no logins. The Alexa skill asks who you are exactly never.</span></span>
      </li>
      <li>
        <span class="verdict no">No</span>
        <span class="detail"><b>You can't spend money here</b><span>Nothing is for sale. The only currency is fingers, and those are fictional. Probably.</span></span>
      </li>
      <li>
        <span class="verdict no">No</span>
        <span class="detail"><b>No advertising</b><span>Nobody would pay to advertise next to a lost hydrogen bomb. We checked.</span></span>
      </li>
      <li>
        <span class="verdict no">No</span>
        <span class="detail"><b>Not for children</b><span>The content involves weapons, mild peril, and legally-binding tigers. It is not aimed at anyone under 13.</span></span>
      </li>
      <li>
        <span class="verdict yes">Sort of</span>
        <span class="detail"><b>It remembers your dirt pile</b><span>One thing is stored, and only one. See section 03. It's a number and a coarse fragment of an address. That's it.</span></span>
      </li>
    </ul>
  </section>

  <section>
    <div class="sec-head">
      <span class="sec-num">02</span>
      <h2>What the Alexa skill does with your voice</h2>
    </div>
    <p>When you talk to the API of Chaos skill, Amazon's Alexa service turns your speech into a short label — "kick rocks", "shake the eight ball" — and hands that label to the skill. The skill takes the label, calls the matching endpoint on <strong>api.dumpsterfire.uk</strong>, and reads the reply back to you.</p>
    <p>The skill does not record you, does not keep your audio, and does not know your name. It receives an anonymous Alexa identifier from Amazon and does nothing with it but ignore it. What Amazon itself collects when you use any Alexa device is governed by <a href="https://www.amazon.com/gp/help/customer/display.html?nodeId=GVP69FUJ48X9DK8V">Amazon's own privacy notice</a>, which is between you and them.</p>
  </section>

  <section>
    <div class="sec-head">
      <span class="sec-num">03</span>
      <h2>The one thing that gets stored</h2>
    </div>
    <p>The <strong>/pound/dirt</strong> endpoint keeps a running total of dirt you have pounded, so your pile survives between visits and can appear on a leaderboard. To tell one pile from another without asking you to log in, the API notes the network address the request came from.</p>
    <p>On the public leaderboard that address is shown <strong>with its final segment removed</strong>, so it points at a rough neighbourhood on the internet, not at you. No name is attached because the API never learns one. If you want your pile gone, call <strong>DELETE /pound/dirt</strong> — or ask the skill to reset your pile — and it is erased.</p>
    <div class="callout">TL;DR — one dirt total, one blurred address, zero identity. Reset it whenever you like and it ceases to exist.</div>
  </section>

  <section>
    <div class="sec-head">
      <span class="sec-num">04</span>
      <h2>Terms of use, or: house rules</h2>
    </div>
    <p>Use the API of Chaos and its skill for what they plainly are — amusement. In exchange for the entertainment, you agree to a few reasonable things:</p>
    <ul class="rulings">
      <li>
        <span class="verdict">Rule</span>
        <span class="detail"><b>Take nothing here as advice</b><span>Not the excuses, not the optimism, not the pet assignments. The advice endpoint applies to every situation precisely because it helps with none of them.</span></span>
      </li>
      <li>
        <span class="verdict">Rule</span>
        <span class="detail"><b>Don't hammer the service</b><span>It's held together by spite and one server. Automated flooding, abuse, or attempts to break it may get your rough address blocked.</span></span>
      </li>
      <li>
        <span class="verdict">Rule</span>
        <span class="detail"><b>No warranty, no guarantees</b><span>It's provided as-is. It may be down, wrong, absurd, or all three at once. That is the intended behaviour.</span></span>
      </li>
      <li>
        <span class="verdict">Rule</span>
        <span class="detail"><b>Your fingers are your own risk</b><span>The cage keeps a count. What you do with that information is a matter between you and the holy hairy toe.</span></span>
      </li>
    </ul>
  </section>

  <section>
    <div class="sec-head">
      <span class="sec-num">05</span>
      <h2>Changes, and how to reach a human</h2>
    </div>
    <p>These terms may change if the API grows new ways to misbehave. The version that matters is whatever is posted here, dated at the top. Continuing to use the service after a change means you're fine with it.</p>
    <p>Questions, complaints, or a strong opinion about tier 50? The whole thing lives at <a href="https://dumpsterfire.uk">dumpsterfire.uk</a>, and the API answers at <a href="https://api.dumpsterfire.uk/healthz">api.dumpsterfire.uk</a>. It will not answer them kindly, but it will answer.</p>
  </section>

  <footer>
    <span>© 2026 · api.dumpsterfire.uk · powered by spite</span>
    <span>10 fingers · 10 toes · <span class="toe">for now</span></span>
  </footer>

</main>
</body>
</html>
