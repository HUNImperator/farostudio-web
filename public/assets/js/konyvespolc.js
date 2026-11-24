/* ==========================================================
   Megrendelésem (korábban: Könyvespolcom) – a kiválasztott könyvek
   és videósorozatok lebegő panelje.
   A tartalom a böngészőben tárolódik (localStorage), így oldalak
   között járva is megmarad, és a panel nyitott/csukott állapota is.
   Tételtípusok: konyv (darabszám 1–10), video és hosszabbitas (1 db).
   ========================================================== */
(function () {
  "use strict";

  var KULCS = "faro-konyvespolc";
  var MAX_DB = 10;
  // a webhely gyökere a szkript helyéből (…/assets/js/konyvespolc.js), így alkönyvtárból is működik
  var szkript = document.currentScript;
  var GYOKER = szkript ? new URL("../../", szkript.src).href : "./";
  var adatUrl = GYOKER + "assets/data/termekek.json";

  var katalogus = null;
  var mobil = window.matchMedia("(max-width: 759px)");
  var mobilCsukva = mobil.matches;   // mobilon oldalváltás után kis sávként indul           // { szallitas, termekek: {id: termek} }
  var allapot = betolt();         // { tetelek: [{id, db}], nyitva: bool }
  var feliratkozok = [];

  /* ---------- tárolás ---------- */
  function betolt() {
    try {
      var a = JSON.parse(localStorage.getItem(KULCS));
      if (a && Array.isArray(a.tetelek)) return a;
    } catch (e) {}
    return { tetelek: [], nyitva: true };
  }
  function ment() {
    try { localStorage.setItem(KULCS, JSON.stringify(allapot)); } catch (e) {}
    feliratkozok.forEach(function (fn) { fn(); });
  }

  /* ---------- segédek ---------- */
  function ft(osszeg) {
    return new Intl.NumberFormat("hu-HU").format(osszeg) + " Ft";
  }
  function el(tag, osztaly, szoveg) {
    var e = document.createElement(tag);
    if (osztaly) e.className = osztaly;
    if (szoveg != null) e.textContent = szoveg;
    return e;
  }
  function tetel(id) {
    for (var i = 0; i < allapot.tetelek.length; i++) if (allapot.tetelek[i].id === id) return allapot.tetelek[i];
    return null;
  }
  // csak a katalógusban létező, elérhető tételek maradhatnak; videóból 1 db
  function tisztit() {
    if (!katalogus) return;
    allapot.tetelek = allapot.tetelek.filter(function (t) {
      var k = katalogus.termekek[t.id];
      return k && k.elerheto && t.db > 0;
    });
    allapot.tetelek.forEach(function (t) { if (katalogus.termekek[t.id].tipus !== "konyv") t.db = 1; });
  }
  function egyediTipus(id) {
    var k = katalogus && katalogus.termekek[id];
    return !!k && k.tipus !== "konyv";
  }

  /* ---------- nyilvános műveletek ---------- */
  var Polc = {
    // visszaad: true, ha bekerült / nőtt; false, ha (videóként) már benne volt
    hozzaad: function (id) {
      mobilCsukva = false;
      var t = tetel(id);
      var uj = true;
      if (t) {
        if (egyediTipus(id)) uj = false;
        else t.db = Math.min(MAX_DB, t.db + 1);
      } else {
        allapot.tetelek.push({ id: id, db: 1 });
      }
      allapot.nyitva = true;
      ment();
      return uj;
    },
    beallit: function (id, db) {
      var t = tetel(id);
      if (!t || egyediTipus(id)) return;
      t.db = Math.max(1, Math.min(MAX_DB, db));
      ment();
    },
    torol: function (id) {
      allapot.tetelek = allapot.tetelek.filter(function (t) { return t.id !== id; });
      ment();
    },
    urit: function () { allapot.tetelek = []; ment(); },
    nyit: function (nyitva) { allapot.nyitva = nyitva; ment(); },
    tetelek: function () {
      if (!katalogus) return [];
      return allapot.tetelek.map(function (t) {
        var k = katalogus.termekek[t.id];
        return { id: t.id, db: t.db, termek: k, osszeg: k.ar * t.db };
      });
    },
    darab: function () { return allapot.tetelek.reduce(function (s, t) { return s + t.db; }, 0); },
    reszosszeg: function () { return Polc.tetelek().reduce(function (s, t) { return s + t.osszeg; }, 0); },
    vanTipus: function (tipus) { return Polc.tetelek().some(function (t) { return t.termek.tipus === tipus; }); },
    // szállítás csak akkor van, ha könyv is van a megrendelésben
    szallitas: function () { return katalogus && Polc.vanTipus("konyv") ? katalogus.szallitas : null; },
    vegosszeg: function () { var sz = Polc.szallitas(); return Polc.reszosszeg() + (sz ? sz.ar : 0); },
    termek: function (id) { return katalogus ? katalogus.termekek[id] || null : null; },
    videoSorozatok: function () {
      if (!katalogus) return [];
      return Object.keys(katalogus.termekek).map(function (k) { return katalogus.termekek[k]; })
        .filter(function (t) { return t.tipus === "video"; });
    },
    GYOKER: GYOKER,
    feliratkozik: function (fn) { feliratkozok.push(fn); },
    ft: ft,
    MAX_DB: MAX_DB
  };
  // kis borító: könyvnél kép, videónál tipográfiai jel (római szám)
  Polc.kisBorito = function (termek, osztaly) {
    if (termek.tipus === "konyv") {
      var kep = el("img", osztaly);
      kep.src = GYOKER + termek.borito;
      kep.alt = "";
      kep.width = 48;
      kep.height = 72;
      return kep;
    }
    var d = el("span", (osztaly || "") + " mini-video");
    d.setAttribute("aria-hidden", "true");
    d.appendChild(el("span", "mini-video__brand", "One Brain"));
    d.appendChild(el("span", "mini-video__num", termek.romai || ""));
    if (termek.tipus === "hosszabbitas") d.appendChild(el("span", "mini-video__plus", "+90"));
    return d;
  };
  window.Konyvespolc = Polc;
  window.Megrendelesem = Polc;

  /* ---------- panel ---------- */
  var panel, lista, osszesites, darabJel, csukoGomb, hirdeto;

  function panelEpit() {
    panel = el("aside", "shelf");
    panel.setAttribute("aria-label", "Megrendelésem");
    panel.hidden = true;

    var fej = el("div", "shelf__head");
    csukoGomb = el("button", "shelf__toggle");
    csukoGomb.type = "button";
    csukoGomb.setAttribute("aria-controls", "konyvespolc-tartalom");
    var cim = el("span", "shelf__title", "Megrendelésem");
    darabJel = el("span", "shelf__count");
    var ikon = el("span", "shelf__chevron");
    ikon.setAttribute("aria-hidden", "true");
    csukoGomb.appendChild(cim);
    csukoGomb.appendChild(darabJel);
    csukoGomb.appendChild(ikon);
    csukoGomb.addEventListener("click", function () {
      var most = nyitvaLatszik();
      mobilCsukva = false;
      Polc.nyit(!most);
    });
    fej.appendChild(csukoGomb);

    var tartalom = el("div", "shelf__body");
    tartalom.id = "konyvespolc-tartalom";
    lista = el("ul", "shelf__list");
    osszesites = el("div", "shelf__summary");
    var tovabb = el("a", "btn btn--dark shelf__checkout");
    tovabb.href = GYOKER + "rendeles.html";
    tovabb.innerHTML = "Tovább a rendeléshez&nbsp;<span class=\"btn__arrow\" aria-hidden=\"true\">→</span>";
    tartalom.appendChild(lista);
    tartalom.appendChild(osszesites);
    tartalom.appendChild(tovabb);

    hirdeto = el("p", "visually-hidden");
    hirdeto.setAttribute("aria-live", "polite");

    panel.appendChild(fej);
    panel.appendChild(tartalom);
    panel.appendChild(hirdeto);
    document.body.appendChild(panel);
  }

  function szamlalo(t) {
    var wrap = el("div", "qty");
    var minusz = el("button", "qty__btn", "−");
    minusz.type = "button";
    minusz.setAttribute("aria-label", t.termek.cim + ": eggyel kevesebb");
    minusz.disabled = t.db <= 1;
    minusz.addEventListener("click", function () { Polc.beallit(t.id, t.db - 1); });
    var mezo = el("input", "qty__input");
    mezo.type = "number";
    mezo.min = "1";
    mezo.max = String(MAX_DB);
    mezo.value = String(t.db);
    mezo.inputMode = "numeric";
    mezo.setAttribute("aria-label", t.termek.cim + " – darabszám");
    mezo.addEventListener("change", function () {
      var v = parseInt(mezo.value, 10);
      Polc.beallit(t.id, isNaN(v) ? 1 : v);
    });
    var plusz = el("button", "qty__btn", "+");
    plusz.type = "button";
    plusz.setAttribute("aria-label", t.termek.cim + ": eggyel több");
    plusz.disabled = t.db >= MAX_DB;
    plusz.addEventListener("click", function () { Polc.beallit(t.id, t.db + 1); });
    wrap.appendChild(minusz);
    wrap.appendChild(mezo);
    wrap.appendChild(plusz);
    return wrap;
  }

  function nyitvaLatszik() {
    return allapot.nyitva && !(mobil.matches && mobilCsukva);
  }

  function panelFrissit() {
    if (!panel) return;
    var tetelek = Polc.tetelek();
    var db = Polc.darab();
    panel.hidden = tetelek.length === 0;
    document.documentElement.classList.toggle("has-shelf", tetelek.length > 0);
    panel.classList.toggle("is-collapsed", !nyitvaLatszik());
    csukoGomb.setAttribute("aria-expanded", nyitvaLatszik() ? "true" : "false");
    darabJel.textContent = tetelek.length === 1 && tetelek[0].termek.tipus === "konyv" ? db + " kötet" : db + " tétel";

    lista.innerHTML = "";
    tetelek.forEach(function (t) {
      var li = el("li", "shelf__item");
      var kep = Polc.kisBorito(t.termek, "shelf__cover");
      var info = el("div", "shelf__info");
      info.appendChild(el("p", "shelf__name", t.termek.cim));
      var ar = el("p", "shelf__price");
      if (t.termek.eredetiAr) {
        var regi = el("del", "shelf__old", ft(t.termek.eredetiAr));
        ar.appendChild(regi);
        ar.appendChild(document.createTextNode(" "));
      }
      ar.appendChild(document.createTextNode(ft(t.termek.ar)));
      info.appendChild(ar);
      if (t.termek.tipus === "konyv") info.appendChild(szamlalo(t));
      else info.appendChild(el("p", "shelf__meta", katalogus.videoNap + " napos hozzáférés"));
      var jobb = el("div", "shelf__right");
      var torles = el("button", "shelf__remove");
      torles.type = "button";
      torles.setAttribute("aria-label", t.termek.cim + " törlése a megrendelésből");
      torles.innerHTML = "<span aria-hidden=\"true\">×</span>";
      torles.addEventListener("click", function () {
        Polc.torol(t.id);
        hirdeto.textContent = t.termek.cim + " kikerült a megrendelésedből.";
      });
      jobb.appendChild(torles);
      jobb.appendChild(el("p", "shelf__line", ft(t.osszeg)));
      li.appendChild(kep);
      li.appendChild(info);
      li.appendChild(jobb);
      lista.appendChild(li);
    });

    var szall = Polc.szallitas();
    osszesites.innerHTML = "";
    function sor(cimke, ertek, osztaly) {
      var s = el("p", "shelf__row" + (osztaly ? " " + osztaly : ""));
      s.appendChild(el("span", null, cimke));
      s.appendChild(el("span", null, ertek));
      osszesites.appendChild(s);
    }
    if (szall) {
      sor("Részösszeg", ft(Polc.reszosszeg()));
      sor(szall.nev, ft(szall.ar));
    }
    sor("Összesen", ft(Polc.vegosszeg()), "shelf__row--total");

    helyzetJelez();
  }

  // a lebegő csempemenü a panel alá kerül, hogy ne fedjék egymást
  function helyzetJelez() {
    var also = panel && !panel.hidden ? Math.round(panel.getBoundingClientRect().bottom) : 0;
    document.documentElement.style.setProperty("--shelf-bottom", also + "px");
  }

  /* ---------- „Megrendelem” gombok ---------- */
  function gombokBekot() {
    document.querySelectorAll("[data-polcra]").forEach(function (gomb) {
      var id = gomb.getAttribute("data-polcra");
      var k = katalogus.termekek[id];
      if (!k || !k.elerheto) { gomb.hidden = true; return; }
      gomb.addEventListener("click", function () {
        var uj = Polc.hozzaad(id);
        if (hirdeto) hirdeto.textContent = uj ? k.cim + " bekerült a megrendelésedbe." : k.cim + " már a megrendelésedben van.";
        if (panel) {
          panel.classList.remove("is-flash");
          void panel.offsetWidth;
          panel.classList.add("is-flash");
        }
      });
    });
  }

  /* ---------- indulás ---------- */
  function indul(adat) {
    var nap = adat.videoHozzaferesNap || 90;
    katalogus = { szallitas: adat.szallitas, termekek: {}, videoNap: nap };
    adat.termekek.forEach(function (t) {
      t.tipus = t.tipus || "konyv";
      katalogus.termekek[t.id] = t;
      if (t.tipus === "video") {
        katalogus.termekek[t.id + "-hosszabbitas"] = {
          id: t.id + "-hosszabbitas", tipus: "hosszabbitas", sorozat: t.id, modul: t.modul, romai: t.romai,
          cim: t.rovidCim + " – hosszabbítás (+" + nap + " nap)", ar: t.ar, eredetiAr: null, elerheto: !!t.elerheto
        };
      }
    });
    tisztit();
    Polc.kesz = true;

    var rendelesOldal = document.body.hasAttribute("data-rendeles") || document.body.hasAttribute("data-nincs-panel");
    if (!rendelesOldal) {
      panelEpit();
      Polc.feliratkozik(panelFrissit);
      panelFrissit();
      window.addEventListener("resize", helyzetJelez);
      window.addEventListener("scroll", helyzetJelez, { passive: true });
    }
    gombokBekot();
    // más fülön történt változás átvétele
    window.addEventListener("storage", function (e) {
      if (e.key === KULCS) { allapot = betolt(); tisztit(); feliratkozok.forEach(function (fn) { fn(); }); }
    });
    document.dispatchEvent(new CustomEvent("konyvespolc:kesz"));
  }

  fetch(adatUrl, { cache: "no-cache" })
    .then(function (v) { return v.json(); })
    .then(indul)
    .catch(function () { /* termékadatok nélkül a polc nem jelenik meg */ });
})();
