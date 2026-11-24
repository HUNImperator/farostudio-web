/*
 * Süti nélküli látogatottságmérés (saját, a FARO admin Statisztika oldalához).
 * Semmit nem tárol a böngészőben; az oldal címét, a hivatkozó oldalt és az
 * esetleges utm_source / utm_campaign paramétert küldi el.
 * Az admin böngészője (localStorage: faro-nem-szamol) és a „Ne kövess” beállítású
 * böngészők nem számítanak bele.
 */
(function () {
  "use strict";
  var src = document.currentScript && document.currentScript.src;
  var VEG = src ? new URL("../../api/meres.php", src).href : "/api/meres.php";

  function kizarva() {
    try { if (localStorage.getItem("faro-nem-szamol")) return true; } catch (e) {}
    // a „Ne kövess” / Global Privacy Control beállítást tiszteletben tartjuk
    if (navigator.doNotTrack === "1" || window.doNotTrack === "1" || navigator.globalPrivacyControl) return true;
    return !!navigator.webdriver;
  }
  function kuld(adat) {
    if (kizarva()) return;
    var test = JSON.stringify(adat);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(VEG, new Blob([test], { type: "text/plain" }))) return;
    } catch (e) {}
    try { fetch(VEG, { method: "POST", body: test, keepalive: true, headers: { "Content-Type": "text/plain" } }); } catch (e) {}
  }
  function oldal() {
    var p = new URLSearchParams(location.search);
    kuld({ t: "o", o: location.pathname, r: document.referrer || "", u: p.get("utm_source") || "", c: p.get("utm_campaign") || "" });
  }

  window.FaroMeres = {
    esemeny: function (nev) { kuld({ t: "e", e: String(nev).slice(0, 140) }); }
  };

  // e-mail- és telefonlinkre kattintás
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("a[href]");
    if (!a) return;
    var h = a.getAttribute("href");
    if (/^mailto:/i.test(h)) window.FaroMeres.esemeny("kattintas:email");
    else if (/^tel:/i.test(h)) window.FaroMeres.esemeny("kattintas:telefon");
  });

  if (document.prerendering) document.addEventListener("prerenderingchange", oldal, { once: true });
  else oldal();
})();
