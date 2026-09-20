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
  // Keep the button label honest when sections are toggled individually.
  sections.forEach(function (s) {
    s.addEventListener("toggle", function () {
      var anyOpen = sections.some(function (x) { return x.open; });
      toggleAll.textContent = anyOpen ? "hide all" : "show all";
      toggleAll.setAttribute("aria-pressed", String(!anyOpen));
    });
  });
})();
