(function () {
  "use strict";

  var mcpBtn   = document.getElementById("mcp-access-btn");
  var mcpModal = document.getElementById("mcp-dialog");
  var mcpClose = document.getElementById("mcp-dialog-close");
  mcpBtn.addEventListener("click", function () { mcpModal.showModal(); });
  mcpClose.addEventListener("click", function () { mcpModal.close(); });
  mcpModal.addEventListener("click", function (event) {
    if (event.target === mcpModal) { mcpModal.close(); }
  });
})();
