(function () {
  "use strict";

  // Hide-all / show-all across every section, so you can clear the clutter and
  // reveal just the ones you want to run.
  var toggleAll = document.getElementById("toggle-all");
  var sections  = Array.prototype.slice.call(document.querySelectorAll(".grpwrap"));
  toggleAll.addEventListener("click", function () {
    var anyOpen = sections.some(function (s) { return s.open; });
    sections.forEach(function (s) { s.open = !anyOpen; });
    toggleAll.textContent = anyOpen ? "show all" : "hide all";
    toggleAll.setAttribute("aria-pressed", String(anyOpen));
  });
  // "new" switch: while on, every section is still listed, but only the
  // new endpoints show (or "nothing new here" in a section without any).
  // Starts on (set server side) whenever anything is tagged new.
  var newOnly = document.getElementById("new-only");
  var body    = document.querySelector(".pane__body");
  // On: open the sections with something new, collapse the rest. Off: back
  // to each section's default open/collapsed state.
  function applyNewOnly() {
    body.classList.toggle("is-new-only", newOnly.checked);
    sections.forEach(function (s) {
      s.open = newOnly.checked ? s.hasAttribute("data-has-new") : s.hasAttribute("data-default-open");
    });
  }
  newOnly.addEventListener("change", applyNewOnly);
  // Back/forward navigation can restore the switch to a different state
  // than the server rendered, so match the list to it on load.
  applyNewOnly();

  // Keep the button label honest when sections are toggled individually.
  sections.forEach(function (s) {
    s.addEventListener("toggle", function () {
      var anyOpen = sections.some(function (x) { return x.open; });
      toggleAll.textContent = anyOpen ? "hide all" : "show all";
      toggleAll.setAttribute("aria-pressed", String(!anyOpen));
    });
  });
})();
