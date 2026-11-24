/* Előadás igénylése – űrlap ellenőrzése és beküldése (api/eloadas-igenyles.php) */
(function () {
  "use strict";
  var urlap = document.querySelector("[data-igenyles-urlap]");
  if (!urlap) return;
  var gomb = urlap.querySelector("[data-bekuld]");
  var uzenet = urlap.querySelector("[data-uzenet]");
  var koszonjuk = document.querySelector("[data-koszonjuk]");
  var elfHiba = urlap.querySelector("[data-elfogadas-hiba]");
  var betoltve = Date.now();

  function mezo(id) { return document.getElementById(id); }
  function hiba(input, szoveg) {
    var f = input.closest(".field");
    if (f) {
      f.classList.toggle("is-invalid", !!szoveg);
      var h = f.querySelector(".field__error");
      if (h) h.textContent = szoveg || "";
    }
    input.setAttribute("aria-invalid", szoveg ? "true" : "false");
  }

  function ellenoriz() {
    var elso = null;
    function rossz(input, szoveg) { hiba(input, szoveg); if (!elso) elso = input; }
    function kotelezo(id, szoveg) {
      var i = mezo(id);
      if (!i.value.trim()) rossz(i, szoveg); else hiba(i, "");
    }
    kotelezo("nev", "Kérlek, add meg a neved.");
    kotelezo("intezmeny", "Kérlek, add meg az intézmény vagy közösség nevét.");
    var email = mezo("email");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim())) rossz(email, "Kérlek, adj meg egy érvényes e-mail-címet."); else hiba(email, "");
    var tel = mezo("telefon");
    var t = tel.value.replace(/[\s\-()\/]/g, "");
    if (t && !/^\+?\d{6,15}$/.test(t)) rossz(tel, "Kérlek, ellenőrizd a telefonszámot."); else hiba(tel, "");
    kotelezo("helyszin", "Kérlek, add meg a települést vagy a helyszínt.");
    var elf = mezo("adatkezeles");
    if (!elf.checked) {
      elfHiba.textContent = "Az ajánlatkéréshez el kell fogadnod az adatkezelési tájékoztatót.";
      if (!elso) elso = elf;
    } else {
      elfHiba.textContent = "";
    }
    return elso;
  }

  urlap.addEventListener("input", function (e) {
    if (e.target.getAttribute("aria-invalid") === "true") hiba(e.target, "");
  });
  mezo("adatkezeles").addEventListener("change", function () {
    if (this.checked) elfHiba.textContent = "";
  });

  urlap.addEventListener("submit", function (e) {
    e.preventDefault();
    uzenet.hidden = true;
    var elso = ellenoriz();
    if (elso) {
      elso.focus();
      return;
    }
    var alkalom = urlap.querySelector("input[name=alkalom]:checked");
    var adat = {
      nev: mezo("nev").value,
      intezmeny: mezo("intezmeny").value,
      email: mezo("email").value,
      telefon: mezo("telefon").value,
      helyszin: mezo("helyszin").value,
      alkalom: alkalom ? alkalom.value : "",
      tema: mezo("tema").value,
      idopont: mezo("idopont").value,
      letszam: mezo("letszam").value,
      uzenet: mezo("uzenet").value,
      adatkezeles: true,
      weboldal: mezo("weboldal").value,
      ido: Math.round((Date.now() - betoltve) / 1000)
    };
    gomb.disabled = true;
    gomb.setAttribute("aria-busy", "true");
    fetch("api/eloadas-igenyles.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(adat)
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
