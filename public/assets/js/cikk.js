/* Blogcikk: a cikk linkjének másolása a vágólapra */
(function () {
  "use strict";
  var gomb = document.querySelector("[data-masol]");
  if (!gomb) return;
  gomb.addEventListener("click", function () {
    var url = gomb.getAttribute("data-masol");
    var kesz = function () { gomb.textContent = "Link másolva ✓"; setTimeout(function () { gomb.textContent = "Link másolása"; }, 2500); };
    if (navigator.clipboard) navigator.clipboard.writeText(url).then(kesz, function () { window.prompt("A cikk linkje:", url); });
    else window.prompt("A cikk linkje:", url);
  });
})();
