/* ==========================================================
   Rendelés oldal – összesítő, Foxpost-választó, dedikálás,
   ellenőrzés és beküldés
   ========================================================== */
(function () {
  "use strict";

  var FOXPOST_ORIGIN = "https://cdn.foxpost.hu";
  var BEKULDES_URL = "api/rendeles.php";

  var urlap = document.querySelector("[data-urlap]");
  if (!urlap) return;
  var uresDoboz = document.querySelector("[data-ures]");
  var dedikDoboz = document.querySelector("[data-dedikalas]");
  var uzenet = document.querySelector("[data-uzenet]");
  var dedikaciok = {};   // id -> szöveg (újrarajzoláskor megmarad)

  function el(tag, osztaly, szoveg) {
    var e = document.createElement(tag);
    if (osztaly) e.className = osztaly;
    if (szoveg != null) e.textContent = szoveg;
    return e;
  }

  /* ---------- részek a megrendelés tartalma szerint ---------- */
  var vanKonyv = false, vanVideo = false, vanHossz = false, vanProgram = false, vanDigit = false;
  function reszek() {
    var P = window.Konyvespolc;
    vanKonyv = P.vanTipus("konyv");
    vanVideo = P.vanTipus("video");
    vanHossz = P.vanTipus("hosszabbitas");
    vanProgram = P.vanTipus("program");
    vanDigit = vanVideo || vanHossz || vanProgram;
    document.querySelectorAll("[data-csak-konyv]").forEach(function (e) { e.hidden = !vanKonyv; });
    document.querySelectorAll("[data-csak-video]").forEach(function (e) { e.hidden = !vanVideo; });
    document.querySelectorAll("[data-csak-digitalis]").forEach(function (e) { e.hidden = !vanDigit; });
    document.querySelector("[data-telefon-cimke]").textContent = vanKonyv ? "Mobilszám" : "Telefonszám";
    var vissza = document.querySelector("[data-vissza]");
    if (vissza && !vanKonyv && (vanVideo || vanHossz)) { vissza.href = "oktato-videok.html"; vissza.textContent = "← Vissza a videósorozatokhoz"; }
    // a látható lépések sorszámozása
    var n = 0;
    document.querySelectorAll(".checkout__form > fieldset.step").forEach(function (f) {
      if (f.hidden) return;
      n++;
      var num = f.querySelector(".step__num");
      if (num) num.textContent = (n < 10 ? "0" : "") + n;
    });
  }

  /* ---------- összesítő + dedikálás ---------- */
  function rajzol() {
    var P = window.Konyvespolc;
    var tetelek = P.tetelek();
    var ures = tetelek.length === 0;
    uresDoboz.hidden = !ures;
    urlap.hidden = ures;
    if (ures) return;
    reszek();

    var lista = document.querySelector("[data-osszesito]");
    lista.innerHTML = "";
    tetelek.forEach(function (t) {
      var li = el("li", "order-summary__item");
      var kep = P.kisBorito(t.termek);
      var kozep = el("div");
      kozep.appendChild(el("strong", null, t.termek.cim));
      var konyv = t.termek.tipus === "konyv";
      kozep.appendChild(el("span", null, konyv ? P.ft(t.termek.ar) + " / db" : (t.termek.hozzaferes || "90 napos online hozzáférés")));
      var q = el("div", "qty");
      var m = el("button", "qty__btn", "−"); m.type = "button"; m.disabled = t.db <= 1;
      m.setAttribute("aria-label", t.termek.cim + ": eggyel kevesebb");
      m.addEventListener("click", function () { P.beallit(t.id, t.db - 1); });
      var v = el("input", "qty__input"); v.type = "number"; v.min = "1"; v.max = String(P.MAX_DB); v.value = String(t.db);
      v.setAttribute("aria-label", t.termek.cim + " – darabszám");
      v.addEventListener("change", function () { var n = parseInt(v.value, 10); P.beallit(t.id, isNaN(n) ? 1 : n); });
      var p = el("button", "qty__btn", "+"); p.type = "button"; p.disabled = t.db >= P.MAX_DB;
      p.setAttribute("aria-label", t.termek.cim + ": eggyel több");
      p.addEventListener("click", function () { P.beallit(t.id, t.db + 1); });
      if (konyv) { q.appendChild(m); q.appendChild(v); q.appendChild(p); }
      var torol = el("button", "shelf__remove"); torol.type = "button";
      torol.setAttribute("aria-label", t.termek.cim + " törlése a megrendelésből");
      torol.innerHTML = "<span aria-hidden=\"true\">×</span>";
      torol.addEventListener("click", function () { P.torol(t.id); });
      q.appendChild(torol);
      kozep.appendChild(q);
      li.appendChild(kep);
      li.appendChild(kozep);
      li.appendChild(el("span", null, P.ft(t.osszeg)));
      lista.appendChild(li);
    });

    var szall = P.szallitas();
    document.querySelector("[data-reszosszeg]").textContent = P.ft(P.reszosszeg());
    document.querySelector("[data-szallitas]").textContent = szall ? P.ft(szall.ar) : "";
    document.querySelector("[data-vegosszeg]").textContent = P.ft(P.vegosszeg());

    // dedikálás könyvenként
    dedikDoboz.querySelectorAll("textarea").forEach(function (ta) { dedikaciok[ta.dataset.id] = ta.value; });
    dedikDoboz.innerHTML = "";
    tetelek.forEach(function (t) {
      if (t.termek.tipus !== "konyv") return;
      var sor = el("div", "dedic__row");
      var kep = el("img"); kep.src = P.GYOKER + t.termek.borito; kep.alt = ""; kep.width = 40; kep.height = 60;
      var mezo = el("div", "field");
      var cimke = el("label");
      cimke.htmlFor = "dedik-" + t.id;
      cimke.innerHTML = "";
      cimke.appendChild(document.createTextNode(t.termek.cim + (t.db > 1 ? " (" + t.db + " db)" : "") + " "));
      cimke.appendChild(el("span", "opt", "– kinek dedikáljam? (nem kötelező)"));
      var ta = el("textarea");
      ta.id = "dedik-" + t.id; ta.dataset.id = t.id; ta.maxLength = 300; ta.rows = 2;
      ta.placeholder = t.db > 1 ? "Pl. 1. példány: Anna, 2. példány: Péter" : "Pl. Annának, szeretettel";
      ta.value = dedikaciok[t.id] || "";
      ta.style.minHeight = "56px";
      mezo.appendChild(cimke);
      mezo.appendChild(ta);
      sor.appendChild(kep);
      sor.appendChild(mezo);
      dedikDoboz.appendChild(sor);
    });
  }

  /* ---------- számlázás kapcsolói ---------- */
  var azonos = document.getElementById("szamla-nev-azonos");
  var szamlaNev = document.querySelector("[data-szamlanev]");
  azonos.addEventListener("change", function () { szamlaNev.hidden = azonos.checked; });
  var cegkent = document.getElementById("cegkent");
  var ceg = document.querySelector("[data-ceg]");
  cegkent.addEventListener("change", function () {
    ceg.hidden = !cegkent.checked;
    if (cegkent.checked) { azonos.checked = false; szamlaNev.hidden = false; }
  });

  /* ---------- Foxpost-választó ---------- */
  // A Foxpost egyes pontoknál (pl. Packeta Z-Pont) HTML-t küld a leírásban, benne fizetési
  // és szolgáltatási adatokkal. Ebből csak a hely leírását tartjuk meg, sima szövegként.
  // A DOMParser „inert” dokumentumot készít, így a szövegben lévő kód nem fut le.
  function tisztaLeiras(html) {
    if (!html) return "";
    var elso = String(html).split(/<b>/i)[0].replace(/<br\s*\/?>/gi, "\n");
    var szoveg = new DOMParser().parseFromString(elso, "text/html").body.textContent || "";
    return szoveg.replace(/[ \t]+/g, " ").replace(/\s*\n\s*/g, "\n").trim();
  }

  // A térkép csak teljes képernyős ablakban jelenik meg; az első megnyitáskor töltődik be.
  var terkep = document.querySelector("[data-apt-terkep]");
  var keret = terkep.querySelector("iframe");
  var nyitGomb = document.querySelector("[data-apt-nyit]");
  var bezarGomb = document.querySelector("[data-apt-bezar]");
  var csereGomb = document.querySelector("[data-apt-csere]");
  var valasztott = document.querySelector("[data-apt-valasztott]");
  var aptHiba = document.querySelector("[data-apt-hiba]");
  var visszaFokusz = null;
  function nyitva() { return terkep.classList.contains("is-full"); }
  function terkepNyit(honnan) {
    if (!keret.getAttribute("src")) keret.setAttribute("src", keret.getAttribute("data-src"));
    visszaFokusz = honnan || nyitGomb;
    terkep.classList.add("is-full");
    document.body.classList.add("apt-nyitva");
    bezarGomb.focus({ preventScroll: true });
  }
  function terkepZar() {
    if (!nyitva()) return;
    terkep.classList.remove("is-full");
    document.body.classList.remove("apt-nyitva");
    var f = visszaFokusz && !visszaFokusz.closest("[hidden]") ? visszaFokusz : (valasztott.hidden ? nyitGomb : csereGomb);
    f.focus({ preventScroll: true });
    f.scrollIntoView({ block: "nearest" });
  }
  nyitGomb.addEventListener("click", function () { terkepNyit(nyitGomb); });
  csereGomb.addEventListener("click", function () { terkepNyit(csereGomb); });
  bezarGomb.addEventListener("click", terkepZar);
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && nyitva()) terkepZar();
  });

  window.addEventListener("message", function (e) {
    if (e.origin !== FOXPOST_ORIGIN) return;
    var apt;
    try { apt = typeof e.data === "string" ? JSON.parse(e.data) : e.data; } catch (x) { return; }
    if (!apt || !(apt.place_id || apt.operator_id)) return;
    // a Foxpost Web API az automata operátori azonosítóját várja (pl. hu5516)
    document.getElementById("apt_id").value = apt.operator_id || apt.place_id;
    document.getElementById("apt_tipus").value = apt.variant || "";
    document.getElementById("apt_nev").value = apt.name || "";
    document.getElementById("apt_cim").value = [apt.zip, apt.city, apt.street || apt.address].filter(Boolean).join(" ");
    document.querySelector("[data-apt-nev]").textContent = apt.name || "";
    document.querySelector("[data-apt-cim]").textContent = document.getElementById("apt_cim").value;
    document.querySelector("[data-apt-leiras]").textContent = tisztaLeiras(apt.findme);
    valasztott.hidden = false;
    nyitGomb.hidden = true;
    aptHiba.textContent = "";
    visszaFokusz = csereGomb;
    terkepZar();
  });

  /* ---------- ellenőrzés ---------- */
  function hiba(input, szoveg) {
    var mezo = input.closest(".field");
    if (!mezo) return;
    mezo.classList.toggle("is-invalid", !!szoveg);
    var h = mezo.querySelector(".field__error");
    if (h) h.textContent = szoveg || "";
    input.setAttribute("aria-invalid", szoveg ? "true" : "false");
  }
  function ellenoriz() {
    var elso = null;
    function rossz(input, szoveg) { hiba(input, szoveg); if (!elso) elso = input; }
    function kotelezo(id, szoveg) {
      var i = document.getElementById(id);
      if (!i.value.trim()) rossz(i, szoveg); else hiba(i, "");
      return i;
    }
    kotelezo("nev", "Kérlek, add meg a neved.");
    var email = document.getElementById("email");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim())) rossz(email, "Kérlek, adj meg egy érvényes e-mail-címet."); else hiba(email, "");
    var tel = document.getElementById("telefon");
    var tisztaTel = tel.value.replace(/[\s\-()\/]/g, "");
    if (vanKonyv) {
      if (!/^(\+36|06|0036)(20|30|31|50|70)\d{7}$/.test(tisztaTel)) rossz(tel, "Kérlek, magyar mobilszámot adj meg (pl. +36 30 123 4567)."); else hiba(tel, "");
    } else if (!/^\+?\d{6,15}$/.test(tisztaTel)) rossz(tel, "Kérlek, add meg a telefonszámod."); else hiba(tel, "");
    if (!azonos.checked) kotelezo("szamla_nev", "Kérlek, add meg a számlázási nevet.");
    var irsz = document.getElementById("irsz");
    if (!/^\d{4}$/.test(irsz.value.trim())) rossz(irsz, "Négyjegyű irányítószám."); else hiba(irsz, "");
    kotelezo("telepules", "Kérlek, add meg a települést.");
    kotelezo("cim", "Kérlek, add meg az utcát és a házszámot.");
    if (cegkent.checked) {
      var ado = document.getElementById("adoszam");
      if (!/^\d{8}-\d-\d{2}$/.test(ado.value.trim())) rossz(ado, "Az adószám formátuma: 12345678-1-12."); else hiba(ado, "");
    }
    if (vanKonyv && !document.getElementById("apt_id").value) {
      aptHiba.textContent = "Kérlek, válassz csomagautomatát.";
      if (!elso) elso = nyitGomb;
    }
    if (vanVideo) {
      var modDoboz = document.querySelector("[data-modulok]");
      var jelolt = modulok();
      var hianyzo = window.Konyvespolc.tetelek().filter(function (t) {
        return t.termek.tipus === "video" && jelolt.indexOf(t.termek.modul) === -1;
      });
      if (!jelolt.length) rossz(modDoboz.querySelector("input"), "Kérlek, jelöld be, mely modulokat végezted el.");
      else if (hianyzo.length) rossz(modDoboz.querySelector("input"), "Csak elvégzett modulhoz rendelhetsz videósorozatot: " + hianyzo.map(function (t) { return t.termek.rovidCim; }).join(", ") + ". Jelöld be, ha elvégezted, vagy töröld a megrendelésből.");
      else hiba(modDoboz.querySelector("input"), "");
      var fHiba = fajlHiba();
      if (fHiba) rossz(fajlMezo, fHiba); else hiba(fajlMezo, "");
    }
    var elfHiba = document.querySelector("[data-elfogadas-hiba]");
    var digit = document.getElementById("digitalis");
    if (!document.getElementById("aszf").checked || !document.getElementById("adatkezeles").checked) {
      elfHiba.textContent = "A rendeléshez el kell fogadnod az ÁSZF-et és az adatkezelési tájékoztatót.";
      if (!elso) elso = document.getElementById("aszf");
    } else if (vanDigit && !digit.checked) {
      elfHiba.textContent = "A digitális tartalom rendeléséhez el kell fogadnod a rá vonatkozó nyilatkozatot.";
      if (!elso) elso = digit;
    } else elfHiba.textContent = "";
    if (elso) { elso.scrollIntoView({ behavior: "smooth", block: "center" }); if (elso.focus) elso.focus({ preventScroll: true }); }
    return !elso;
  }

  /* ---------- elvégzett modulok, igazolás feltöltése ---------- */
  function modulok() {
    return Array.prototype.map.call(urlap.querySelectorAll("input[name=modulok]:checked"), function (c) { return parseInt(c.value, 10); });
  }
  urlap.querySelectorAll("input[name=modulok]").forEach(function (c) {
    c.addEventListener("change", function () { hiba(urlap.querySelector("input[name=modulok]"), ""); });
  });
  var fajlMezo = document.getElementById("igazolas");
  var fajlLista = document.querySelector("[data-feltoltes-lista]");
  var fajlok = [];
  var TIPUSOK = ["image/jpeg", "image/png", "image/webp", "application/pdf"];
  var MAX_DB = 3, MAX_OSSZES = 10 * 1024 * 1024;
  function meret(b) { return b > 1048576 ? (b / 1048576).toFixed(1).replace(".", ",") + " MB" : Math.max(1, Math.round(b / 1024)) + " kB"; }
  function fajlRajzol() {
    fajlLista.innerHTML = "";
    fajlok.forEach(function (f, i) {
      var li = el("li", "upload__item");
      li.appendChild(el("span", "upload__name", f.name));
      li.appendChild(el("span", "upload__size", meret(f.size)));
      var x = el("button", "shelf__remove"); x.type = "button";
      x.setAttribute("aria-label", f.name + " eltávolítása");
      x.innerHTML = "<span aria-hidden=\"true\">×</span>";
      x.addEventListener("click", function () { fajlok.splice(i, 1); fajlRajzol(); });
      li.appendChild(x);
      fajlLista.appendChild(li);
    });
  }
  fajlMezo.addEventListener("change", function () {
    Array.prototype.forEach.call(fajlMezo.files, function (f) {
      if (fajlok.length < MAX_DB + 2) fajlok.push(f);
    });
    fajlMezo.value = "";
    fajlRajzol();
    hiba(fajlMezo, fajlHiba() === "Kérlek, töltsd fel az útleveled vagy az okleveled fotóját." ? "" : fajlHiba());
  });
  function fajlHiba() {
    if (!fajlok.length) return "Kérlek, töltsd fel az útleveled vagy az okleveled fotóját.";
    if (fajlok.length > MAX_DB) return "Legfeljebb " + MAX_DB + " fájlt tölthetsz fel.";
    var rossz = fajlok.filter(function (f) { return TIPUSOK.indexOf(f.type) === -1 && !/\.(jpe?g|png|webp|pdf)$/i.test(f.name); });
    if (rossz.length) return "Csak JPG, PNG, WEBP képet vagy PDF-et tölthetsz fel.";
    var pdf = fajlok.filter(function (f) { return !/^image\//.test(f.type); }).reduce(function (s, f) { return s + f.size; }, 0);
    if (pdf > MAX_OSSZES) return "A fájlok összesen legfeljebb 10 MB-osak lehetnek.";
    return "";
  }
  // a nagy fotókat feltöltés előtt kicsinyítjük (max. 2200 px, JPEG), hogy biztosan beférjenek
  function elokeszit(f) {
    var kep = /^image\/(jpeg|png|webp)$/.test(f.type);
    if (!kep || f.size < 1200 * 1024 || !window.createImageBitmap) return Promise.resolve({ blob: f, nev: f.name });
    return createImageBitmap(f).then(function (bm) {
      var k = Math.min(1, 2200 / Math.max(bm.width, bm.height));
      var c = document.createElement("canvas");
      c.width = Math.round(bm.width * k); c.height = Math.round(bm.height * k);
      c.getContext("2d").drawImage(bm, 0, 0, c.width, c.height);
      return new Promise(function (ok) {
        c.toBlob(function (b) { ok({ blob: b || f, nev: f.name.replace(/\.[^.]+$/, "") + ".jpg" }); }, "image/jpeg", 0.85);
      });
    }).catch(function () { return { blob: f, nev: f.name }; });
  }

  /* ---------- előtöltés a videós oldalról (hosszabbítás, új sorozat) ---------- */
  try {
    var elo = JSON.parse(sessionStorage.getItem("faro-video-vevo") || "null");
    if (elo) {
      if (elo.email && !document.getElementById("email").value) document.getElementById("email").value = elo.email;
      if (elo.nev && !document.getElementById("nev").value) document.getElementById("nev").value = elo.nev;
    }
  } catch (x) {}

  function tipusok() {
    return ["konyv", "video", "hosszabbitas", "program"].filter(function (t) { return window.Konyvespolc.vanTipus(t); });
  }

  /* ---------- beküldés ---------- */
  function adatok() {
    var P = window.Konyvespolc;
    var f = function (id) { return document.getElementById(id).value.trim(); };
    return {
      vevo: { nev: f("nev"), email: f("email"), telefon: f("telefon") },
      szamlazas: {
        nev: azonos.checked ? f("nev") : f("szamla_nev"),
        irsz: f("irsz"), telepules: f("telepules"), cim: f("cim"),
        adoszam: cegkent.checked ? f("adoszam") : ""
      },
      foxpost: { id: f("apt_id"), nev: f("apt_nev"), cim: f("apt_cim"), tipus: f("apt_tipus") },
      tetelek: P.tetelek().map(function (t) {
        var ta = document.getElementById("dedik-" + t.id);
        return { id: t.id, db: t.db, dedikalas: ta ? ta.value.trim() : "" };
      }),
      modulok: vanVideo ? modulok() : [],
      digitalis: vanDigit && document.getElementById("digitalis").checked,
      fizetes: urlap.querySelector("input[name=fizetes]:checked").value,
      megjegyzes: f("megjegyzes"),
      weboldal: (document.getElementById("weboldal") || {}).value || ""
    };
  }

  function mutat(szoveg, hibas) {
    uzenet.textContent = szoveg;
    uzenet.classList.toggle("order-msg--error", !!hibas);
    uzenet.hidden = false;
  }

  urlap.addEventListener("submit", function (e) {
    e.preventDefault();
    uzenet.hidden = true;
    if (!ellenoriz()) return;
    var gomb = document.querySelector("[data-bekuld]");
    gomb.disabled = true;
    var keres;
    if (vanVideo) {
      var fd = new FormData();
      fd.append("adatok", JSON.stringify(adatok()));
      keres = Promise.all(fajlok.map(elokeszit)).then(function (kesz) {
        kesz.forEach(function (f) { fd.append("igazolas[]", f.blob, f.nev); });
        return fetch(BEKULDES_URL, { method: "POST", body: fd });
      });
    } else {
      keres = fetch(BEKULDES_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(adatok())
      });
    }
    keres
      .then(function (v) {
        var tipus = v.headers.get("Content-Type") || "";
        if (v.status === 404 || v.status === 501 || tipus.indexOf("application/json") === -1) throw new Error("nincs-hatter");
        return v.json().then(function (j) { if (!v.ok) throw new Error(j.hiba || "hiba"); return j; });
      })
      .then(function (valasz) {
        if (valasz.atiranyitas) {
          // bankkártya: a polc csak a sikeres fizetés után ürül ki (köszönő oldal)
          try {
            sessionStorage.setItem("faro-utolso-rendeles", JSON.stringify({
              rendelesszam: valasz.rendelesszam, vegosszeg: valasz.vegosszeg, fizetes: "kartya", tipusok: tipusok()
            }));
          } catch (x) {}
          gomb.textContent = "Átirányítás a fizetőoldalra…";
          window.location.href = valasz.atiranyitas;
          return;
        }
        try {
          sessionStorage.setItem("faro-utolso-rendeles", JSON.stringify({
            rendelesszam: valasz.rendelesszam, vegosszeg: valasz.vegosszeg, fizetes: valasz.fizetes, tipusok: tipusok()
          }));
        } catch (x) {}
        window.Konyvespolc.urit();
        window.location.href = "rendeles-koszonjuk.html?rendeles=" + encodeURIComponent(valasz.rendelesszam || "");
      })
      .catch(function (err) {
        gomb.disabled = false;
        if (err.message === "nincs-hatter") {
          mutat("Tesztüzemmód: az adatok rendben vannak. A rendelés beküldése a következő fejlesztési lépésben készül el.");
        } else {
          mutat("Hiba történt a rendelés beküldésekor: " + err.message + " Kérlek, próbáld újra, vagy írj az info@farostudio.hu címre.", true);
        }
      });
  });

  /* ---------- megszakított bankkártyás fizetés ---------- */
  var megszakitva = new URLSearchParams(location.search).get("megszakitva");
  if (megszakitva) {
    var doboz = document.querySelector("[data-megszakitva]");
    if (doboz) {
      doboz.textContent = "A bankkártyás fizetés megszakadt, a " + megszakitva +
        " számú rendelés nincs kifizetve. Ellenőrizd az adatokat, és küldd el újra a rendelést – bankkártyával vagy átutalással.";
      doboz.hidden = false;
    }
  }

  /* ---------- indulás ---------- */
  function indul() {
    rajzol();
    window.Konyvespolc.feliratkozik(rajzol);
  }
  if (window.Konyvespolc && window.Konyvespolc.kesz) indul();
  else document.addEventListener("konyvespolc:kesz", indul);
})();
