const https = require('https');
const zlib = require('zlib');

const API_HOST = 'api.dumpsterfire.uk'; // Your API domain

const endpointMap = {
  'rocks': '/kick/rocks',
  'rock': '/kick/rocks',
  'kick rocks': '/kick/rocks',
  'rocks tiers': '/kick/rocks/tiers',
  'rock tiers': '/kick/rocks/tiers',
  'rocks scale': '/kick/rocks/tiers',
  'munitions': '/kick/munitions',
  'munition': '/kick/munitions',
  'lost munition': '/kick/munitions',
  'munitions tiers': '/kick/munitions/tiers',
  'munitions scale': '/kick/munitions/tiers',
  'munition tiers': '/kick/munitions/tiers',
  'dirt': '/pound/dirt',
  'pound dirt': '/pound/dirt',
  'dirt status': '/pound/dirt/status',
  'pile status': '/pound/dirt/status',
  'my pile': '/pound/dirt/status',
  'dirt tiers': '/pound/dirt/tiers',
  'dirt scale': '/pound/dirt/tiers',
  'pile tiers': '/pound/dirt/tiers',
  'dirt leaderboard': '/pound/dirt/leaderboard',
  'leaderboard': '/pound/dirt/leaderboard',
  'top piles': '/pound/dirt/leaderboard',
  'excuses teams': '/excuses/teams',
  'team excuse': '/excuses/teams',
  'dont join call': '/excuses/teams',
  'excuses social': '/excuses/social',
  'social excuse': '/excuses/social',
  'dont attend': '/excuses/social',
  'excuses oops': '/excuses/oops',
  'oops excuse': '/excuses/oops',
  'went wrong': '/excuses/oops',
  'excuses ring-ring': '/excuses/ring-ring',
  'ring ring': '/excuses/ring-ring',
  'didnt pick up': '/excuses/ring-ring',
  'phone excuse': '/excuses/ring-ring',
  'excuses late': '/excuses/late',
  'late excuse': '/excuses/late',
  'why am i late': '/excuses/late',
  'excuses alibis': '/excuses/alibis',
  'alibi': '/excuses/alibis',
  'wasnt there': '/excuses/alibis',
  'gentle correction': '/ministry/gentle-correction',
  'ministry correction': '/ministry/gentle-correction',
  'mandatory pet adoption': '/ministry/mandatory-pet-adoption',
  'pet adoption': '/ministry/mandatory-pet-adoption',
  'mandatory pet': '/ministry/mandatory-pet-adoption',
  'finger in cage': '/cage/finger',
  'finger cage': '/cage/finger',
  'cage finger': '/cage/finger',
  'fictional finger': '/cage/fictional/finger',
  'fictional creature': '/cage/fictional/finger',
  'fictional finger cage': '/cage/fictional/finger',
  'finger status': '/cage/finger/left',
  'fingers left': '/cage/finger/left',
  'toes left': '/cage/finger/left',
  'finger reset': '/cage/finger/reset',
  'reset fingers': '/cage/finger/reset',
  'reset toes': '/cage/finger/reset',
  '8ball': '/unhinged/8ball',
  'eight ball': '/unhinged/8ball',
  'magic eight ball': '/unhinged/8ball',
  'shake the ball': '/unhinged/8ball',
  'optimism': '/unhinged/optimism',
  'positive': '/unhinged/optimism',
  'give me optimism': '/unhinged/optimism',
  'pessimism': '/unhinged/pessimism',
  'negative': '/unhinged/pessimism',
  'give me pessimism': '/unhinged/pessimism',
  'advice': '/unhinged/advice',
  'give advice': '/unhinged/advice',
  'unhinged advice': '/unhinged/advice',
  'non-committal': '/unhinged/non-committal',
  'non committal': '/unhinged/non-committal',
  'maybe': '/unhinged/non-committal',
  'optimistic doom': '/unhinged/optimistic-dooom',
  'doom but positive': '/unhinged/optimistic-dooom',
  'turn it upside down': '/unhinged/turn-it-upside-down',
  'flip': '/unhinged/turn-it-upside-down',
  'flip item': '/unhinged/turn-it-upside-down',
  'upside down': '/unhinged/turn-it-upside-down',
  'solid suddenly liquid': '/unhinged/solid-suddenly-liquid',
  'liquid': '/unhinged/solid-suddenly-liquid',
  'solid liquid': '/unhinged/solid-suddenly-liquid',
  'solid suddenly gelatinous': '/unhinged/solid-suddenly-gelatinous',
  'jelly': '/unhinged/solid-suddenly-gelatinous',
  'gelatinous': '/unhinged/solid-suddenly-gelatinous',
  'solid jelly': '/unhinged/solid-suddenly-gelatinous',
  'make solid jelly': '/unhinged/solid-suddenly-gelatinous',
  'choose your duck': '/unhinged/choose-your-duck',
  'duck': '/unhinged/choose-your-duck',
  'bath duck': '/unhinged/choose-your-duck',
  'choose duck': '/unhinged/choose-your-duck'
};

function callApi(path, queryParams = {}, method = 'GET') {
  return new Promise((resolve, reject) => {
    const queryString = Object.keys(queryParams).length > 0
      ? '?' + Object.entries(queryParams).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&')
      : '';

    const options = {
      hostname: API_HOST,
      path: path + queryString,
      method: method,
      headers: {
        'User-Agent': 'AlexaSkill/1.0',
        'Accept': 'application/json',
        // Ask for uncompressed so we never have to decompress
        'Accept-Encoding': 'identity'
      }
    };

    const request = https.request(options, (res) => {
      const chunks = [];
      res.on('data', chunk => { chunks.push(chunk); });
      res.on('end', () => {
        let body = Buffer.concat(chunks);
        const encoding = (res.headers['content-encoding'] || '').toLowerCase();

        // Decompress if the server compressed anyway
        try {
          if (encoding === 'gzip') body = zlib.gunzipSync(body);
          else if (encoding === 'br') body = zlib.brotliDecompressSync(body);
          else if (encoding === 'deflate') body = zlib.inflateSync(body);
        } catch (e) {
          console.error('Decompress error:', e.message);
        }

        const text = body.toString('utf8');

        // Handle redirects (https module does not follow them)
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          console.error('Unexpected redirect to:', res.headers.location);
          resolve('The API redirected unexpectedly.');
          return;
        }

        // 418 I'm a teapot - the void's little joke
        if (res.statusCode === 418) {
          console.error('API returned 418');
          resolve('The void ate you and burped you back out. Try again.');
          return;
        }

        if (res.statusCode >= 400) {
          console.error('API returned status', res.statusCode, text.substring(0, 200));
          resolve('The API returned an error, status ' + res.statusCode + '.');
          return;
        }

        try {
          resolve(formatResponse(JSON.parse(text)));
        } catch (e) {
          // Not JSON: speak the raw text
          resolve(text.substring(0, 500) || 'The API sent an empty response.');
        }
      });
    });

    request.on('error', (error) => {
      console.error('API error:', error.message);
      resolve('I could not reach the API.');
    });

    request.setTimeout(7000, () => {
      request.destroy();
      console.error('API request timed out');
      resolve('The API took too long to answer.');
    });

    request.end();
  });
}

// Keys that are machine-facing noise when spoken aloud. The API pairs
// human-readable fields (mass_human, total, remark) with raw machine
// ones (mass_kg, total_litres, probabilities, ids, timestamps) - we
// speak the human ones and skip the raw duplicates.
const SKIP_KEYS = new Set([
  'id', 'uuid', 'ip', 'url', 'href', 'link', 'timestamp', 'ts', 'since',
  'mass_kg', 'total_litres', 'boot_survival_probability', 'appendage',
  'outcome', 'from', 'up_to', 'of'
]);

// Object keys that are pure wrappers around detail - speak their
// contents without announcing the wrapper name ("tier 6. millstone",
// not "rock: tier 6. name millstone").
const UNWRAP_KEYS = new Set([
  'rock', 'munition', 'pile', 'impact', 'assessment', 'leaderboard',
  'details', 'detail', 'data', 'info'
]);

// Keys whose values are self-explanatory prose - read them WITHOUT a
// spoken label ("Largest cat alive", not "consequence: Largest cat alive").
const PROSE_KEYS = new Set([
  'instruction', 'remark', 'message', 'result', 'answer', 'advice',
  'excuse', 'reason', 'alibi', 'verdict', 'note', 'saying', 'quote',
  'text', 'line', 'consequence', 'name', 'label', 'scale', 'now_roughly',
  'contender', 'tier_explanation', 'equivalent'
]);

// Join spoken fragments with clean punctuation (no double periods).
function joinSpoken(parts) {
  return parts
    .filter(Boolean)
    .map(p => p.trim().replace(/[.\s]+$/, '')) // strip trailing dots/space
    .filter(Boolean)
    .join('. ') + '.';
}

// Speak a value fully. Objects are walked; every string/number is included.
function speak(value, withLabels) {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value.trim();
  if (typeof value === 'number') return String(value);
  if (typeof value === 'boolean') return value ? 'yes' : 'no';

  if (Array.isArray(value)) {
    const items = value.map((item, i) => {
      const s = speak(item, withLabels);
      if (!s) return '';
      return value.length > 1 ? 'Number ' + (i + 1) + ', ' + s : s;
    });
    return joinSpoken(items);
  }

  if (typeof value === 'object') {
    const parts = Object.entries(value)
      .filter(([k]) => !SKIP_KEYS.has(k.toLowerCase()))
      .map(([k, v]) => {
        const key = k.toLowerCase();
        const nested = (typeof v === 'object' && v !== null);

        // Wrapper objects: speak the contents, drop the wrapper name.
        if (nested && UNWRAP_KEYS.has(key)) {
          return speak(v, true);
        }

        const s = speak(v, nested ? true : withLabels);
        if (!s) return '';

        // Read narrative prose fields without a label; label everything
        // else (including nested detail) so numbers keep meaning.
        const label = k.replace(/_/g, ' ');
        if (!withLabels && !nested && PROSE_KEYS.has(key)) {
          return s;
        }
        return label + ': ' + s;
      });
    return joinSpoken(parts);
  }

  return String(value);
}

const MAX_SPEECH = 3000; // Alexa allows ~8000 chars; keep it sane

function formatResponse(data) {
  let out;

  if (typeof data === 'string') {
    out = data;
  } else if (Array.isArray(data)) {
    out = data.length === 0 ? 'Nothing to report.' : speak(data, true);
  } else if (typeof data === 'object' && data !== null) {
    out = speak(data, false);
  } else {
    out = String(data);
  }

  out = (out || '').trim();
  if (!out) return 'The API sent nothing to say.';
  return out.length > MAX_SPEECH ? out.substring(0, MAX_SPEECH) + '...' : out;
}

function buildResponse(message, shouldEnd) {
  // Never emit empty speech - Alexa replaces it with its own generic
  // "here's what I found" wrapper, so guarantee a non-empty string.
  let text = (message === undefined || message === null) ? '' : String(message).trim();
  if (!text && !shouldEnd) {
    text = 'The API sent nothing to say. Try another command.';
  }
  console.log('Speaking:', JSON.stringify(text));

  const response = {
    outputSpeech: {
      type: 'PlainText',
      text: text
    },
    shouldEndSession: shouldEnd
  };

  // If we are keeping the session open, add a reprompt so the mic
  // stays on and Alexa listens for the next command.
  if (!shouldEnd) {
    response.reprompt = {
      outputSpeech: {
        type: 'PlainText',
        text: 'What next? Try another command, or say stop to quit.'
      }
    };
  }

  return {
    version: '1.0',
    response: response
  };
}

exports.handler = async function(event, context) {
  try {
    console.log('Event:', JSON.stringify(event));

    const requestType = event.request.type;

    // Launch: "open api of chaos"
    if (requestType === 'LaunchRequest') {
      return buildResponse('Welcome to the API of Chaos. Here is what I can do. ' +
        'Kick: rocks, or munitions. ' +
        'Pound: dirt, check your pile, or the leaderboard. ' +
        'Excuses: for teams, social, oops, late, or an alibi. ' +
        'Ministry: gentle correction, or mandatory pet adoption. ' +
        'Cage: put your finger in the cage, try the cage with fictional creatures, or check your fingers. ' +
        'And unhinged: the eight ball, optimism, pessimism, advice, optimistic doom, turn it upside down, change something from solid to a jelly or liquid, or choose your duck. ' +
        'What would you like?', false);
    }

    // Session ended: acknowledge silently
    if (requestType === 'SessionEndedRequest') {
      return buildResponse('', true);
    }

    // Anything that is not an IntentRequest from here on
    if (requestType !== 'IntentRequest') {
      return buildResponse('I did not understand that.', true);
    }

    const intentName = event.request.intent.name;
    let path = null;
    let queryParams = {};

    // Safe slot access: slots object may be entirely absent
    const slots = (event.request.intent && event.request.intent.slots) || {};
    const slotValue = (name) => (slots[name] && slots[name].value) ? slots[name].value : null;

    if (intentName === 'GetEndpoint') {
      const slot = slots.endpoint;
      const spoken = (slot && slot.value ? slot.value : '').toLowerCase().trim();

      // 1. Prefer Alexa's resolved canonical value (handles synonyms)
      let resolved = null;
      try {
        const res = slot.resolutions.resolutionsPerAuthority[0];
        if (res.status.code === 'ER_SUCCESS_MATCH') {
          resolved = res.values[0].value.name.toLowerCase().trim();
        }
      } catch (e) { /* no resolution present */ }

      // 2. Try resolved value, then raw spoken value
      path = (resolved && endpointMap[resolved]) || endpointMap[spoken];

      // 3. Fuzzy fallback: normalize and match against normalized keys
      if (!path) {
        const norm = s => s.replace(/[^a-z0-9]/g, '');
        const target = norm(spoken);
        for (const key of Object.keys(endpointMap)) {
          if (norm(key) === target) { path = endpointMap[key]; break; }
        }
      }

      if (!path) {
        console.error('Unmatched endpoint. spoken="' + spoken + '" resolved="' + resolved + '"');
        return buildResponse('I do not know that one. Try rocks, munitions, excuses, cage, or unhinged.', false);
      }

      if (slotValue('tier')) {
        queryParams.tier = slotValue('tier');
      }
    } else if (intentName === 'KickRocks') {
      path = '/kick/rocks';
      if (slotValue('tier')) {
        queryParams.tier = slotValue('tier');
      }
    } else if (intentName === 'KickMunitions') {
      path = '/kick/munitions';
    } else if (intentName === 'PoundDirt') {
      path = '/pound/dirt';
    } else if (intentName === 'ResetDirt') {
      path = '/pound/dirt';
    } else if (intentName === 'HealthCheck') {
      path = '/healthz';
    } else if (intentName === 'AMAZON.HelpIntent') {
      return buildResponse('You can ask for rocks, munitions, excuses, cage games, or unhinged answers.', false);
    } else if (intentName === 'AMAZON.StopIntent' || intentName === 'AMAZON.CancelIntent') {
      return buildResponse('Goodbye. You can also find the API of Chaos on the web at dumpsterfire dot U K.', true);
    } else if (intentName === 'AMAZON.FallbackIntent') {
      return buildResponse('I did not catch that. Try kick rocks, give me an excuse, or shake the eight ball.', false);
    }

    if (!path) {
      return buildResponse('I did not understand that. Try another command, or say stop to quit.', false);
    }

    const result = await callApi(path, queryParams, intentName === 'ResetDirt' ? 'DELETE' : 'GET');
    return buildResponse(result, false);

  } catch (error) {
    console.error('Error:', error);
    return buildResponse('That one broke. Try another command, or say stop to quit.', false);
  }
};
