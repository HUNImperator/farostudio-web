// Köszönő oldal: a rendelés adatai a rendelési oldalról (sessionStorage) vagy az URL-ből
(function () {
  "use strict";
  var adat = {};
  try { adat = JSON.parse(sessionStorage.getItem("faro-utolso-rendeles")) || {}; } catch (e) {}
  var szam = adat.rendelesszam || new URLSearchParams(location.search).get("rendeles") || "";
  function ft(n) { return new Intl.NumberFormat("hu-HU").format(n) + " Ft"; }
  if (szam) {
    document.querySelector("[data-koszono-bevezeto]").textContent =
      "A rendelésed megérkezett, a rendelésszámod: " + szam + ". A visszaigazolást e-mailben is elküldtem.";
  }
  if (adat.fizetes === "atutalas" && adat.vegosszeg) {
    document.querySelector("[data-atutalas]").hidden = false;
    document.querySelector("[data-osszeg]").textContent = ft(adat.vegosszeg);
    document.querySelector("[data-szam]").textContent = szam;
  }
})();
