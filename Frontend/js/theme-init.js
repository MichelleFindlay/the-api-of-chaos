// Applied synchronously, before first paint, so a saved light/high-contrast
// choice doesn't flash dark first. Mirrored in theme-toggle.js, which is
// what actually reads clicks and writes the choice back.
(function () {
  try {
    var t = localStorage.getItem("chaos-theme");
    if (t === "light" || t === "high-contrast") {
      document.documentElement.setAttribute("data-theme", t);
    }
  } catch (e) {}
})();
