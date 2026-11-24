// Köszönő oldal: a rendelés adatai a rendelési oldalról (sessionStorage) vagy az URL-ből
(function () {
  "use strict";
  var adat = {};
  try { adat = JSON.parse(sessionStorage.getItem("faro-utolso-rendeles")) || {}; } catch (e) {}
  var param = new URLSearchParams(location.search);
  var szam = param.get("rendeles") || adat.rendelesszam || "";
  var kartya = param.get("fizetes") === "kartya";
  function ft(n) { return new Intl.NumberFormat("hu-HU").format(n) + " Ft"; }

  var bevezeto = document.querySelector("[data-koszono-bevezeto]");
  if (kartya) {
    bevezeto.textContent = "A bankkártyás fizetésed sikeres" + (szam ? ", a rendelésszámod: " + szam : "") +
      ". A visszaigazolást néhány percen belül e-mailben is megkapod.";
    // a sikeres fizetés után ürül ki a Könyvespolc
    var urit = function () { if (window.Konyvespolc) window.Konyvespolc.urit(); };
    if (window.Konyvespolc && window.Konyvespolc.kesz) urit();
    else document.addEventListener("konyvespolc:kesz", urit);
  } else if (szam) {
    bevezeto.textContent = "A rendelésed megérkezett, a rendelésszámod: " + szam + ". A visszaigazolást e-mailben is elküldtem.";
  }
  // a rendelés tartalma szerinti teendők
  var tipusok = adat.tipusok || ["konyv"];
  document.querySelectorAll("[data-csak]").forEach(function (e) { e.hidden = tipusok.indexOf(e.getAttribute("data-csak")) === -1; });
  if (!kartya && adat.fizetes === "atutalas" && adat.vegosszeg) {
    document.querySelector("[data-atutalas]").hidden = false;
    document.querySelector("[data-osszeg]").textContent = ft(adat.vegosszeg);
    document.querySelector("[data-szam]").textContent = szam;
  }
})();
