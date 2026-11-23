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

  /* ---------- összesítő + dedikálás ---------- */
  function rajzol() {
    var P = window.Konyvespolc;
    var tetelek = P.tetelek();
    var ures = tetelek.length === 0;
    uresDoboz.hidden = !ures;
    urlap.hidden = ures;
    if (ures) return;

    var lista = document.querySelector("[data-osszesito]");
    lista.innerHTML = "";
    tetelek.forEach(function (t) {
      var li = el("li", "order-summary__item");
      var kep = el("img");
      kep.src = t.termek.borito; kep.alt = ""; kep.width = 44; kep.height = 66;
      var kozep = el("div");
      kozep.appendChild(el("strong", null, t.termek.cim));
      kozep.appendChild(el("span", null, P.ft(t.termek.ar) + " / db"));
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
      q.appendChild(m); q.appendChild(v); q.appendChild(p);
      var torol = el("button", "shelf__remove"); torol.type = "button";
      torol.setAttribute("aria-label", t.termek.cim + " levétele a polcról");
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
    document.querySelector("[data-szallitas]").textContent = P.ft(szall.ar);
    document.querySelector("[data-vegosszeg]").textContent = P.ft(P.reszosszeg() + szall.ar);

    // dedikálás könyvenként
    dedikDoboz.querySelectorAll("textarea").forEach(function (ta) { dedikaciok[ta.dataset.id] = ta.value; });
    dedikDoboz.innerHTML = "";
    tetelek.forEach(function (t) {
      var sor = el("div", "dedic__row");
      var kep = el("img"); kep.src = t.termek.borito; kep.alt = ""; kep.width = 40; kep.height = 60;
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
  var terkep = document.querySelector("[data-apt-terkep]");
  var valasztott = document.querySelector("[data-apt-valasztott]");
  var aptHiba = document.querySelector("[data-apt-hiba]");
  window.addEventListener("message", function (e) {
    if (e.origin !== FOXPOST_ORIGIN) return;
    var apt;
    try { apt = typeof e.data === "string" ? JSON.parse(e.data) : e.data; } catch (x) { return; }
    if (!apt || !(apt.place_id || apt.operator_id)) return;
    document.getElementById("apt_id").value = apt.place_id || apt.operator_id;
    document.getElementById("apt_nev").value = apt.name || "";
    document.getElementById("apt_cim").value = [apt.zip, apt.city, apt.street || apt.address].filter(Boolean).join(" ");
    document.querySelector("[data-apt-nev]").textContent = apt.name || "";
    document.querySelector("[data-apt-cim]").textContent = document.getElementById("apt_cim").value;
    document.querySelector("[data-apt-leiras]").textContent = apt.findme || "";
    valasztott.hidden = false;
    terkep.hidden = true;
    aptHiba.textContent = "";
  });
  document.querySelector("[data-apt-csere]").addEventListener("click", function () {
    terkep.hidden = false;
    valasztott.hidden = true;
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
    if (!/^(\+36|06|0036)(20|30|31|50|70)\d{7}$/.test(tisztaTel)) rossz(tel, "Kérlek, magyar mobilszámot adj meg (pl. +36 30 123 4567)."); else hiba(tel, "");
    if (!azonos.checked) kotelezo("szamla_nev", "Kérlek, add meg a számlázási nevet.");
    var irsz = document.getElementById("irsz");
    if (!/^\d{4}$/.test(irsz.value.trim())) rossz(irsz, "Négyjegyű irányítószám."); else hiba(irsz, "");
    kotelezo("telepules", "Kérlek, add meg a települést.");
    kotelezo("cim", "Kérlek, add meg az utcát és a házszámot.");
    if (cegkent.checked) {
      var ado = document.getElementById("adoszam");
      if (!/^\d{8}-\d-\d{2}$/.test(ado.value.trim())) rossz(ado, "Az adószám formátuma: 12345678-1-12."); else hiba(ado, "");
    }
    if (!document.getElementById("apt_id").value) {
      aptHiba.textContent = "Kérlek, válassz csomagautomatát a térképen.";
      if (!elso) elso = terkep;
    }
    var elfHiba = document.querySelector("[data-elfogadas-hiba]");
    if (!document.getElementById("aszf").checked || !document.getElementById("adatkezeles").checked) {
      elfHiba.textContent = "A rendeléshez el kell fogadnod az ÁSZF-et és az adatkezelési tájékoztatót.";
      if (!elso) elso = document.getElementById("aszf");
    } else elfHiba.textContent = "";
    if (elso) { elso.scrollIntoView({ behavior: "smooth", block: "center" }); if (elso.focus) elso.focus({ preventScroll: true }); }
    return !elso;
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
      foxpost: { id: f("apt_id"), nev: f("apt_nev"), cim: f("apt_cim") },
      tetelek: P.tetelek().map(function (t) {
        var ta = document.getElementById("dedik-" + t.id);
        return { id: t.id, db: t.db, dedikalas: ta ? ta.value.trim() : "" };
      }),
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
    fetch(BEKULDES_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(adatok())
    })
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
              rendelesszam: valasz.rendelesszam, vegosszeg: valasz.vegosszeg, fizetes: "kartya"
            }));
          } catch (x) {}
          gomb.textContent = "Átirányítás a fizetőoldalra…";
          window.location.href = valasz.atiranyitas;
          return;
        }
        try {
          sessionStorage.setItem("faro-utolso-rendeles", JSON.stringify({
            rendelesszam: valasz.rendelesszam, vegosszeg: valasz.vegosszeg, fizetes: valasz.fizetes
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
