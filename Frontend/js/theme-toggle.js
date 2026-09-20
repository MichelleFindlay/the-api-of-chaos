(function () {
  "use strict";

  // Cycles dark -> light -> high-contrast -> dark. theme-init.js already
  // applied a saved choice before paint; this just reads that back off the
  // <html> attribute so there's one source of truth, and writes clicks on.
  var THEME_ORDER  = ["dark", "light", "high-contrast"];
  var THEME_LABELS = { "dark": "dark", "light": "light", "high-contrast": "high contrast" };
  var themeBtn = document.getElementById("theme-toggle-btn");

  function currentTheme() {
    return document.documentElement.getAttribute("data-theme") || "dark";
  }
  // Label is just the mode name (no "theme: " prefix) to keep the button
  // narrow enough that the footer still fits on one line; the full context
  // lives in aria-label for anyone using a screen reader.
  function setTheme(theme) {
    if (theme === "dark") {
      document.documentElement.removeAttribute("data-theme");
    } else {
      document.documentElement.setAttribute("data-theme", theme);
    }
    try { localStorage.setItem("chaos-theme", theme); } catch (e) {}
    themeBtn.textContent = THEME_LABELS[theme];
    themeBtn.setAttribute("aria-label", "Theme: " + THEME_LABELS[theme] + ". Click to switch.");
  }

  themeBtn.textContent = THEME_LABELS[currentTheme()];
  themeBtn.setAttribute("aria-label", "Theme: " + THEME_LABELS[currentTheme()] + ". Click to switch.");
  themeBtn.addEventListener("click", function () {
    var next = THEME_ORDER[(THEME_ORDER.indexOf(currentTheme()) + 1) % THEME_ORDER.length];
    setTheme(next);
  });
})();
