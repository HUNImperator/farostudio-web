/* FARO admin – Statisztika: vonaldiagram (célkereszt + tooltip), sávok/oszlopok tooltipje, admin-kizárás kapcsoló */
(function () {
  "use strict";
  var tip = document.querySelector(".stat-tip");
  var NS = "http://www.w3.org/2000/svg";
  var fmt = new Intl.NumberFormat("hu-HU");

  /* ---------- közös tooltip ---------- */
  function mutat(x, y, epit) {
    tip.innerHTML = "";
    epit(tip);
    tip.hidden = false;
    var w = tip.offsetWidth, h = tip.offsetHeight;
    var left = Math.min(window.innerWidth - w - 8, Math.max(8, x + 14));
    var top = y - h - 12 < 8 ? y + 18 : y - h - 12;
    tip.style.left = left + "px";
    tip.style.top = top + "px";
  }
  function rejt() { tip.hidden = true; }
  function egyszeru(szoveg) { return function (t) { var p = document.createElement("div"); p.textContent = szoveg; t.appendChild(p); }; }

  Array.prototype.forEach.call(document.querySelectorAll("[data-tip]"), function (el) {
    el.addEventListener("pointermove", function (e) { mutat(e.clientX, e.clientY, egyszeru(el.getAttribute("data-tip"))); });
    el.addEventListener("pointerleave", rejt);
    el.addEventListener("focus", function () { var r = el.getBoundingClientRect(); mutat(r.left + r.width / 2, r.top, egyszeru(el.getAttribute("data-tip"))); });
    el.addEventListener("blur", rejt);
  });

  /* ---------- vonaldiagram ---------- */
  function el(nev, attr, szulo) {
    var e = document.createElementNS(NS, nev);
    for (var k in attr) e.setAttribute(k, attr[k]);
    if (szulo) szulo.appendChild(e);
    return e;
  }
  function szepLepes(max) {
    if (max <= 4) return 1;
    var nyers = max / 4, nagy = Math.pow(10, Math.floor(Math.log10(nyers))), r = nyers / nagy;
    return (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * nagy;
  }
  function rovid(n) { return n >= 10000 ? fmt.format(Math.round(n / 1000)) + " e" : fmt.format(n); }

  Array.prototype.forEach.call(document.querySelectorAll("[data-vonal]"), function (doboz) {
    var adat = JSON.parse(doboz.getAttribute("data-adat"));
    var n = adat.cimkek.length;
    var aktiv = -1;
    var svg, kereszt, pontok = [], geo = null;

    function rajzol() {
      doboz.innerHTML = "";
      var W = doboz.clientWidth, H = 260, bal = 44, jobb = 14, fent = 12, lent = 30;
      var pw = W - bal - jobb, ph = H - fent - lent;
      var max = 0;
      adat.sorozatok.forEach(function (s) { s.ertekek.forEach(function (v) { if (v > max) max = v; }); });
      var lepes = szepLepes(max), felso = Math.max(lepes, Math.ceil(max / lepes) * lepes);
      var x = function (i) { return bal + (n === 1 ? pw / 2 : i * pw / (n - 1)); };
      var y = function (v) { return fent + ph - v / felso * ph; };
      geo = { x: x, y: y, bal: bal, pw: pw };
      svg = el("svg", { width: W, height: H, viewBox: "0 0 " + W + " " + H, "aria-hidden": "true" }, doboz);
      // rács és tengely
      for (var t = 0; t <= felso + 1e-9; t += lepes) {
        el("line", { x1: bal, x2: W - jobb, y1: y(t), y2: y(t), class: t === 0 ? "stat-axis" : "stat-grid" }, svg);
        el("text", { x: bal - 8, y: y(t) + 4, "text-anchor": "end", class: "stat-tick" }, svg).textContent = rovid(t);
      }
      var cimkeDb = Math.min(n, Math.max(2, Math.floor(pw / 90)));
      for (var c = 0; c < cimkeDb; c++) {
        var i = n === 1 ? 0 : Math.round(c * (n - 1) / (cimkeDb - 1));
        el("text", { x: x(i), y: H - 8, "text-anchor": c === 0 && n > 1 ? "start" : (c === cimkeDb - 1 && n > 1 ? "end" : "middle"), class: "stat-tick" }, svg).textContent = adat.cimkek[i].split(" ")[0];
      }
      kereszt = el("line", { y1: fent, y2: fent + ph, class: "stat-cross", visibility: "hidden" }, svg);
      pontok = [];
      adat.sorozatok.forEach(function (s) {
        var d = s.ertekek.map(function (v, i) { return (i ? "L" : "M") + x(i).toFixed(1) + " " + y(v).toFixed(1); }).join(" ");
        el("path", { d: d + " L" + x(n - 1) + " " + y(0) + " L" + x(0) + " " + y(0) + " Z", fill: s.szin, "fill-opacity": "0.08", stroke: "none" }, svg);
        el("path", { d: d, fill: "none", stroke: s.szin, "stroke-width": 2, "stroke-linejoin": "round", "stroke-linecap": "round" }, svg);
        if (n === 1) el("circle", { cx: x(0), cy: y(s.ertekek[0]), r: 4, fill: s.szin, stroke: "#fff", "stroke-width": 2 }, svg);
        pontok.push(el("circle", { r: 4.5, fill: s.szin, stroke: "#fff", "stroke-width": 2, visibility: "hidden" }, svg));
      });
      if (aktiv >= 0) jelol(aktiv, false);
    }

    function jelol(i, tooltip) {
      aktiv = i;
      kereszt.setAttribute("x1", geo.x(i));
      kereszt.setAttribute("x2", geo.x(i));
      kereszt.setAttribute("visibility", "visible");
      adat.sorozatok.forEach(function (s, k) {
        pontok[k].setAttribute("cx", geo.x(i));
        pontok[k].setAttribute("cy", geo.y(s.ertekek[i]));
        pontok[k].setAttribute("visibility", "visible");
      });
      if (tooltip) {
        var r = doboz.getBoundingClientRect();
        mutat(r.left + geo.x(i), r.top + geo.y(Math.max.apply(null, adat.sorozatok.map(function (s) { return s.ertekek[i]; }))), function (t) {
          var c = document.createElement("div");
          c.className = "stat-tip__cim";
          c.textContent = adat.cimkek[i];
          t.appendChild(c);
          adat.sorozatok.forEach(function (s) {
            var sor = document.createElement("div");
            sor.className = "stat-tip__sor";
            var kulcs = document.createElement("span");
            kulcs.className = "stat-key stat-key--line";
            kulcs.style.setProperty("--c", s.szin);
            var ertek = document.createElement("strong");
            ertek.textContent = fmt.format(s.ertekek[i]);
            sor.appendChild(kulcs);
            sor.appendChild(ertek);
            sor.appendChild(document.createTextNode(" " + s.nev));
            t.appendChild(sor);
          });
        });
      }
    }
    function elenged() {
      kereszt.setAttribute("visibility", "hidden");
      pontok.forEach(function (p) { p.setAttribute("visibility", "hidden"); });
      aktiv = -1;
      rejt();
    }

    doboz.addEventListener("pointermove", function (e) {
      var r = doboz.getBoundingClientRect();
      var rel = (e.clientX - r.left - geo.bal) / geo.pw;
      jelol(Math.max(0, Math.min(n - 1, Math.round(rel * (n - 1)))), true);
    });
    doboz.addEventListener("pointerleave", elenged);
    doboz.addEventListener("blur", elenged);
    doboz.addEventListener("keydown", function (e) {
      if (e.key !== "ArrowLeft" && e.key !== "ArrowRight" && e.key !== "Home" && e.key !== "End") return;
      e.preventDefault();
      var i = aktiv < 0 ? n - 1 : aktiv;
      if (e.key === "ArrowLeft") i = Math.max(0, i - 1);
      else if (e.key === "ArrowRight") i = Math.min(n - 1, i + 1);
      else if (e.key === "Home") i = 0;
      else i = n - 1;
      jelol(i, true);
    });
    doboz.addEventListener("focus", function () { jelol(aktiv < 0 ? n - 1 : aktiv, true); });

    rajzol();
    var idozito;
    window.addEventListener("resize", function () { clearTimeout(idozito); idozito = setTimeout(rajzol, 120); });
  });

  /* ---------- ez a böngésző beleszámítson-e ---------- */
  var valt = document.querySelector("[data-szamol-valt]");
  if (valt) {
    var szamol = function () { try { return localStorage.getItem("faro-szamol-admin") === "1"; } catch (e) { return false; } };
    var felirat = function () { valt.textContent = szamol() ? "Ezt a böngészőt ne számold bele" : "Ezt a böngészőt mégis számold bele"; };
    felirat();
    valt.addEventListener("click", function (e) {
      e.preventDefault();
      try {
        if (szamol()) { localStorage.removeItem("faro-szamol-admin"); localStorage.setItem("faro-nem-szamol", "1"); }
        else { localStorage.setItem("faro-szamol-admin", "1"); localStorage.removeItem("faro-nem-szamol"); }
      } catch (x) {}
      felirat();
    });
  }
})();
