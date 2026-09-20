(function () {
  "use strict";

  var log     = document.getElementById("log");
  var hint    = log.querySelector(".log__hint");
  var cli     = document.getElementById("cli");
  var counter = document.getElementById("counter");

  var calls   = 0;
  var history = [];
  var histAt  = -1;

  var METHODS = ["GET", "POST", "DELETE"];

  // Every API call fires straight from the browser to Cloudflare, so the
  // connection Cloudflare sees is the real visitor's — no server in the middle
  // to be relabelled. The same-origin PHP proxy is no longer in the path.
  // apiBase/clientIp come from the small inline config script index.php
  // renders just before this file loads, since that's the only bridge
  // needed from server-rendered PHP values into a static JS file.
  var API_BASE = window.CHAOS_CONFIG.apiBase;
  var DIRECT_RE = /^\//;

  var runButtons = document.querySelectorAll(".run");
  var PATHS = Array.prototype.map.call(
    runButtons,
    function (b) { return b.dataset.method + " " + b.dataset.path; }
  );
  // Deduped, sorted paths for the CLI's autocomplete — several appear twice
  // in PATHS under different methods (e.g. GET and DELETE /pound/dirt).
  var CLI_PATHS = Array.prototype.map.call(runButtons, function (b) { return b.dataset.path; })
    .filter(function (p, i, arr) { return arr.indexOf(p) === i; })
    .sort();

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  function push(commandText) {
    if (hint) { hint.remove(); hint = null; }
    var entry = el("div", "entry");
    entry.appendChild(el("div", "entry__cmd", commandText));
    var out = el("pre", "entry__out", "…");
    entry.appendChild(out);
    var meta = el("div", "entry__meta", "");
    entry.appendChild(meta);
    log.appendChild(entry);
    log.scrollTop = log.scrollHeight;
    return { entry: entry, out: out, meta: meta };
  }

  function finish(slot, text, metaHtml, bad) {
    slot.out.textContent = text;
    slot.meta.innerHTML = metaHtml;
    if (bad) { slot.entry.classList.add("entry--bad"); }
    log.scrollTop = log.scrollHeight;
  }

  function request(path, method, params) {
    var qs    = params && params.length ? params.join("&") : "";
    var shown = method + " " + path + (qs ? "?" + qs : "");
    var slot  = push(shown);

    calls += 1;
    counter.textContent = calls + (calls === 1 ? " call" : " calls");

    // Straight to the API. Cloudflare sees the browser's own connection, so the
    // pile — and everything else — is keyed to whoever is actually clicking.
    var direct = DIRECT_RE.test(path);
    var url = direct
      ? API_BASE + path + (qs ? "?" + qs : "")
      : "?path=" + encodeURIComponent(path) + (qs ? "&" + qs : "");

    return fetch(url, {
      method: METHODS.indexOf(method) === -1 ? "GET" : method,
      headers: { "Accept": "application/json" },
      credentials: "omit",
      mode: "cors"
    })
      .then(function (r) {
        // Read as text first, not r.json() directly: /unhinged/* has a
        // 1-in-10 chance of answering with a plain-text 418 (the "void")
        // instead of JSON, and calling r.json() straight on that throws,
        // landing in the generic transport-failure catch() below even
        // though the API answered fine. Parsing here instead lets a
        // non-JSON body get shown for what it is.
        return r.text().then(function (raw) {
          var body, isJson = true;
          try { body = JSON.parse(raw); } catch (e) { isJson = false; body = raw; }
          return { status: r.status, ok: r.ok, body: body, raw: raw, isJson: isJson };
        });
      })
      .then(function (res) {
        if (direct) {
          // Raw API response — JSON pretty-printed, or the void's plain
          // text exactly as sent. The pile id in a JSON body here is the
          // browser's own IP.
          var text = res.isJson ? JSON.stringify(res.body, null, 2) : res.raw;
          var tag = res.ok
            ? "<span class=\"ok\">exit 0</span>"
            : "<span class=\"bad\">exit " + res.status + "</span>";
          finish(slot, text, tag + " · " + res.status + " · direct to api", !res.ok);
          return;
        }
        if (!res.isJson) {
          // The proxy always wraps responses in JSON itself, so a non-JSON
          // body here means the proxy broke, not the upstream API.
          finish(slot, res.raw, "<span class=\"bad\">exit 1</span> · unexpected proxy response", true);
          return;
        }
        var data = res.body;
        if (data.ok === false && data.error) {
          finish(slot, data.error, "<span class=\"bad\">exit 1</span> · refused by proxy", true);
          return;
        }
        var payload = (data.json !== null && data.json !== undefined) ? data.json : data.body;
        var text = typeof payload === "string" ? payload : JSON.stringify(payload, null, 2);
        var tag = data.ok
          ? "<span class=\"ok\">exit 0</span>"
          : "<span class=\"bad\">exit " + data.status + "</span>";
        finish(slot, text,
          tag + " · " + data.status + " · " + data.took_ms + "ms · seen as " + data.client_ip,
          !data.ok);
      })
      .catch(function () {
        finish(slot,
          direct
            ? "could not reach the api directly. the api must allow CORS from this page (Access-Control-Allow-Origin)."
            : "no response from the proxy. check the php error log.",
          "<span class=\"bad\">exit 1</span> · transport failure", true);
      });
  }

  function collect(row) {
    var params = [];
    row.querySelectorAll("input[data-param]").forEach(function (input) {
      var value = input.value.trim();
      if (value !== "") {
        params.push(encodeURIComponent(input.dataset.param) + "=" + encodeURIComponent(value));
      }
    });
    return params;
  }

  document.querySelectorAll(".run").forEach(function (button) {
    button.addEventListener("click", function () {
      button.disabled = true;
      request(button.dataset.path, button.dataset.method, collect(button.closest(".row")))
        .finally(function () { button.disabled = false; });
    });
  });

  function local(commandText, output) {
    var slot = push(commandText);
    finish(slot, output, "<span class=\"ok\">exit 0</span> · local", false);
  }

  // Runs whatever's typed, exactly as Enter always has — pulled into its own
  // function so the autocomplete dropdown below can also trigger it (e.g.
  // clicking a suggestion) without duplicating this logic.
  function submitCliLine(rawLine) {
    var line = rawLine.trim();
    if (line === "") { return; }
    cli.value = "";
    history.push(line);
    histAt = -1;
    closeSuggest();

    if (line === "clear") {
      log.innerHTML = "";
      calls = 0;
      counter.textContent = "0 calls";
      return;
    }
    if (line === "help" || line === "ls") {
      local(line, PATHS.join("\n") + "\n\nflags: --tier, --min, --max on rocks.\ntype them as a query string, e.g. /kick/rocks?tier=9\nthe dirt pile is fixed to your address and cannot be set.");
      return;
    }
    if (line === "ip" || line === "whoami") {
      local(line, window.CHAOS_CONFIG.clientIp);
      return;
    }
    if (line === "debug" || line === "env") {
      var slot = push(line);
      fetch("?debug=1", { headers: { "Accept": "application/json" } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          finish(slot, JSON.stringify(d, null, 2), "<span class=\"ok\">exit 0</span> · local", false);
        })
        .catch(function () {
          finish(slot, "debug endpoint unreachable.", "<span class=\"bad\">exit 1</span>", true);
        });
      return;
    }

    var method = "GET";
    var head = line.split(/\s+/)[0].toUpperCase();
    if (METHODS.indexOf(head) !== -1) {
      method = head;
      line = line.slice(line.split(/\s+/)[0].length).trim();
    }
    if (line === "") { return; }

    var path  = line.charAt(0) === "/" ? line : "/" + line;
    var parts = path.split("?");
    var params = parts[1] ? parts[1].split("&").filter(Boolean) : [];
    request(parts[0], method, params);
  }

  // ------------------------------------------------------- autocomplete

  var suggestBox    = document.getElementById("cli-suggest");
  var suggestPaths  = [];
  var activeIndex   = -1;
  var methodPrefix  = ""; // e.g. "DELETE ", preserved from what's already typed

  function closeSuggest() {
    suggestBox.hidden = true;
    suggestBox.innerHTML = "";
    suggestPaths = [];
    activeIndex = -1;
    cli.setAttribute("aria-expanded", "false");
    cli.removeAttribute("aria-activedescendant");
  }

  function markActive() {
    Array.prototype.forEach.call(suggestBox.children, function (child, i) {
      var isActive = i === activeIndex;
      child.classList.toggle("is-active", isActive);
      child.setAttribute("aria-selected", String(isActive));
    });
    if (activeIndex !== -1) {
      cli.setAttribute("aria-activedescendant", "cli-suggest-" + activeIndex);
    } else {
      cli.removeAttribute("aria-activedescendant");
    }
  }

  function moveActive(delta) {
    if (!suggestPaths.length) { return; }
    activeIndex = (activeIndex + delta + suggestPaths.length) % suggestPaths.length;
    markActive();
  }

  // Selects a suggestion: fills the input (and runs it, when run is true) —
  // used by both click and Tab/Enter-on-a-highlighted-item.
  function acceptSuggestion(index, run) {
    if (index < 0 || index >= suggestPaths.length) { return; }
    var full = methodPrefix + suggestPaths[index];
    if (run) {
      submitCliLine(full);
    } else {
      cli.value = full;
      closeSuggest();
    }
  }

  function renderSuggestions(matches, needle) {
    suggestBox.innerHTML = "";
    suggestPaths = matches;
    activeIndex = matches.length ? 0 : -1;

    matches.forEach(function (path, i) {
      var item = el("button", "cli-suggest__item");
      item.type = "button";
      item.id = "cli-suggest-" + i;
      item.setAttribute("role", "option");

      var idx = needle ? path.toLowerCase().indexOf(needle.toLowerCase()) : -1;
      if (idx === -1) {
        item.textContent = path;
      } else {
        item.appendChild(document.createTextNode(path.slice(0, idx)));
        var strong = document.createElement("strong");
        strong.textContent = path.slice(idx, idx + needle.length);
        item.appendChild(strong);
        item.appendChild(document.createTextNode(path.slice(idx + needle.length)));
      }

      // mousedown, not click: fires before the input would blur, so
      // preventDefault here keeps focus (and the dropdown state) intact.
      item.addEventListener("mousedown", function (event) {
        event.preventDefault();
        acceptSuggestion(i, true);
      });
      item.addEventListener("mouseenter", function () {
        activeIndex = i;
        markActive();
      });

      suggestBox.appendChild(item);
    });

    suggestBox.hidden = matches.length === 0;
    cli.setAttribute("aria-expanded", String(matches.length > 0));
    markActive();
  }

  function updateSuggestions() {
    var raw = cli.value;
    if (raw.trim() === "") { closeSuggest(); return; }

    methodPrefix = "";
    var needle = raw;
    var firstWord = raw.split(/\s+/)[0];
    if (raw.indexOf(" ") !== -1 && METHODS.indexOf(firstWord.toUpperCase()) !== -1) {
      methodPrefix = firstWord.toUpperCase() + " ";
      needle = raw.slice(firstWord.length).replace(/^\s+/, "");
    }

    if (needle === "") { closeSuggest(); return; }

    var lower = needle.toLowerCase();
    var matches = CLI_PATHS
      .filter(function (p) { return p.toLowerCase().indexOf(lower) !== -1; })
      .slice(0, 8);
    renderSuggestions(matches, needle);
  }

  cli.addEventListener("input", updateSuggestions);
  cli.addEventListener("blur", closeSuggest);

  cli.addEventListener("keydown", function (event) {
    if (!suggestBox.hidden) {
      if (event.key === "ArrowDown") { event.preventDefault(); moveActive(1); return; }
      if (event.key === "ArrowUp")   { event.preventDefault(); moveActive(-1); return; }
      if (event.key === "Tab")       { event.preventDefault(); acceptSuggestion(activeIndex, false); return; }
      if (event.key === "Escape")    { event.preventDefault(); closeSuggest(); return; }
      if (event.key === "Enter" && activeIndex !== -1) {
        event.preventDefault();
        acceptSuggestion(activeIndex, true);
        return;
      }
    }

    if (event.key === "ArrowUp" || event.key === "ArrowDown") {
      if (!history.length) { return; }
      event.preventDefault();
      histAt = event.key === "ArrowUp"
        ? Math.max(0, (histAt === -1 ? history.length : histAt) - 1)
        : Math.min(history.length, histAt + 1);
      cli.value = history[histAt] || "";
      return;
    }
    if (event.key === "Escape") { closeSuggest(); return; }
    if (event.key !== "Enter") { return; }

    submitCliLine(cli.value);
  });

  cli.focus();
})();
