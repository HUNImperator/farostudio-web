/*
 * Közös űrlapkezelő (előadás igénylése, mentorálás jelentkezés…).
 * <form data-urlap data-vegpont="api/…php">
 *  - kötelező mező: required + data-hiba="hibaüzenet"
 *  - kötelező jelölőcsoport: <div class="field" data-csoport="nev" data-kotelezo="hibaüzenet">
 *  - kizáró jelölő (pl. „Még egyiket sem”): data-kizaro
 *  - „Egyéb” szövegmező: data-egyeb-hez="<a jelölőnégyzet id-je>" + data-hiba
 *  - feltételes rész: data-mutat-ha="mezonev=érték" (csak akkor látszik és küldődik, ha az a választás be van jelölve)
 *  - elfogadás: #adatkezeles + [data-elfogadas-hiba]
 * Sikeres küldés után az űrlap eltűnik, és a [data-koszonjuk] doboz jelenik meg.
 */
(function () {
  "use strict";
  var urlap = document.querySelector("[data-urlap]");
  if (!urlap) return;
  var gomb = urlap.querySelector("[data-bekuld]");
  var uzenet = urlap.querySelector("[data-uzenet]");
  var koszonjuk = document.querySelector("[data-koszonjuk]");
  var elf = document.getElementById("adatkezeles");
  var elfHiba = urlap.querySelector("[data-elfogadas-hiba]");
  var betoltve = Date.now();

  function hiba(el, szoveg) {
    var f = el.closest(".field");
    if (f) {
      f.classList.toggle("is-invalid", !!szoveg);
      var h = f.querySelector(".field__error");
      if (h) h.textContent = szoveg || "";
    }
    if (el.setAttribute) el.setAttribute("aria-invalid", szoveg ? "true" : "false");
  }

  // kizáró jelölő: ha bejelölik, a csoport többi tagja kikapcsol (és fordítva)
  urlap.addEventListener("change", function (e) {
    var t = e.target;
    if (t.type === "checkbox" && t.checked && t.name) {
      var tarsak = urlap.querySelectorAll('input[type=checkbox][name="' + t.name + '"]');
      Array.prototype.forEach.call(tarsak, function (x) {
        if (x !== t && (t.hasAttribute("data-kizaro") || x.hasAttribute("data-kizaro"))) x.checked = false;
      });
    }
    var csoport = t.closest("[data-csoport]");
    if (csoport) hiba(csoport, "");
    if (t === elf && elf.checked) elfHiba.textContent = "";
  });
  // feltételes részek (pl. „Gyermekemnek” → életkor mező)
  var feltetelesek = urlap.querySelectorAll("[data-mutat-ha]");
  function feltetelek() {
    Array.prototype.forEach.call(feltetelesek, function (el) {
      var p = el.getAttribute("data-mutat-ha").split("=");
      var latszik = !!urlap.querySelector('input[name="' + p[0] + '"][value="' + p[1] + '"]:checked');
      el.hidden = !latszik;
      Array.prototype.forEach.call(el.querySelectorAll("input, select, textarea"), function (i) { i.disabled = !latszik; });
    });
  }
  feltetelek();
  urlap.addEventListener("change", feltetelek);

  // az „Egyéb” szöveg beírásakor a hozzá tartozó jelölő bekapcsol
  Array.prototype.forEach.call(urlap.querySelectorAll("[data-egyeb-hez]"), function (inp) {
    var cb = document.getElementById(inp.getAttribute("data-egyeb-hez"));
    inp.addEventListener("input", function () { if (inp.value.trim()) cb.checked = true; });
  });
  urlap.addEventListener("input", function (e) {
    if (e.target.getAttribute("aria-invalid") === "true") hiba(e.target, "");
  });

  function ellenoriz() {
    var elso = null;
    function rossz(el, szoveg, fokusz) { hiba(el, szoveg); if (!elso) elso = fokusz || el; }
    Array.prototype.forEach.call(urlap.querySelectorAll("input[required], textarea[required], select[required]"), function (i) {
      if (i === elf || i.disabled) return;
      var v = i.value.trim();
      if (!v) rossz(i, i.getAttribute("data-hiba") || "Kérlek, töltsd ki ezt a mezőt.");
      else if (i.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) rossz(i, "Kérlek, adj meg egy érvényes e-mail-címet.");
      else hiba(i, "");
    });
    Array.prototype.forEach.call(urlap.querySelectorAll("input[type=tel]"), function (i) {
      var t = i.value.replace(/[\s\-()\/]/g, "");
      if (t && !/^\+?\d{6,15}$/.test(t)) rossz(i, "Kérlek, ellenőrizd a telefonszámot.");
      else if (!i.required) hiba(i, "");
    });
    Array.prototype.forEach.call(urlap.querySelectorAll("[data-csoport][data-kotelezo]"), function (c) {
      var van = c.querySelector('input[name="' + c.getAttribute("data-csoport") + '"]:checked');
      if (!van) rossz(c, c.getAttribute("data-kotelezo"), c.querySelector("input"));
      else hiba(c, "");
    });
    Array.prototype.forEach.call(urlap.querySelectorAll("[data-egyeb-hez]"), function (inp) {
      var cb = document.getElementById(inp.getAttribute("data-egyeb-hez"));
      if (cb.checked && !inp.value.trim()) rossz(inp, inp.getAttribute("data-hiba") || "Kérlek, írd le röviden.");
      else hiba(inp, "");
    });
    if (elf && !elf.checked) {
      elfHiba.textContent = "A küldéshez el kell fogadnod az adatkezelési tájékoztatót.";
      if (!elso) elso = elf;
    } else if (elfHiba) {
      elfHiba.textContent = "";
    }
    return elso;
  }

  function adatok() {
    var adat = {};
    Array.prototype.forEach.call(urlap.elements, function (el) {
      if (!el.name || el.disabled) return;
      if (el.type === "checkbox") {
        if (el === elf) { adat.adatkezeles = el.checked; return; }
        if (!adat[el.name]) adat[el.name] = [];
        if (el.checked) adat[el.name].push(el.value);
      } else if (el.type === "radio") {
        if (!(el.name in adat)) adat[el.name] = "";
        if (el.checked) adat[el.name] = el.value;
      } else if (el.tagName !== "BUTTON") {
        adat[el.name] = el.value;
      }
    });
    adat.ido = Math.round((Date.now() - betoltve) / 1000);
    return adat;
  }

  urlap.addEventListener("submit", function (e) {
    e.preventDefault();
    uzenet.hidden = true;
    var elso = ellenoriz();
    if (elso) {
      elso.focus();
      return;
    }
    gomb.disabled = true;
    gomb.setAttribute("aria-busy", "true");
    fetch(urlap.getAttribute("data-vegpont"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(adatok())
    })
      .then(function (v) {
        return v.text().then(function (szoveg) {
          var j;
          try { j = JSON.parse(szoveg); } catch (x) { throw new Error("A szerver most nem válaszolt megfelelően."); }
          if (!v.ok || j.hiba) throw new Error(j.hiba || "Ismeretlen hiba.");
          return j;
        });
      })
      .then(function () {
        urlap.hidden = true;
        koszonjuk.hidden = false;
        koszonjuk.scrollIntoView({ block: "center" });
        koszonjuk.focus({ preventScroll: true });
      })
      .catch(function (err) {
        uzenet.textContent = "Nem sikerült elküldeni az űrlapot: " + err.message + " Kérlek, próbáld újra, vagy írj az info@farostudio.hu címre.";
        uzenet.hidden = false;
      })
      .then(function () {
        gomb.disabled = false;
        gomb.removeAttribute("aria-busy");
      });
  });
})();
