/* ==========================================================
   One Brain® videós oldal: lejátszó (hls.js), vízjel, teljes képernyő,
   folytatás onnan, ahol abbahagytad; hosszabbítás és új sorozat rendelése.
   ========================================================== */
(function () {
  "use strict";

  var tar = {
    get: function (k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };
  var LATOTT = "faro-video-latott";
  function latott() { return tar.get(LATOTT) || {}; }

  /* ---------- hosszabbítás / új sorozat: a Megrendelésembe, majd a rendelési oldalra ---------- */
  function rendelesre(id) {
    var body = document.body;
    try {
      sessionStorage.setItem("faro-video-vevo", JSON.stringify({
        email: body.getAttribute("data-vevo-email") || "",
        nev: body.getAttribute("data-vevo-nev") || ""
      }));
    } catch (e) {}
    function tovabb() {
      window.Konyvespolc.hozzaad(id);
      window.location.href = window.Konyvespolc.GYOKER + "rendeles.html";
    }
    if (window.Konyvespolc && window.Konyvespolc.kesz) tovabb();
    else document.addEventListener("konyvespolc:kesz", tovabb, { once: true });
  }
  document.querySelectorAll("[data-hosszabbit]").forEach(function (b) {
    b.addEventListener("click", function () { rendelesre(b.getAttribute("data-hosszabbit") + "-hosszabbitas"); });
  });
  document.querySelectorAll("[data-megrendel]").forEach(function (b) {
    b.addEventListener("click", function () { rendelesre(b.getAttribute("data-megrendel")); });
  });

  // „Megnézem”: ott folytatja, ahol legutóbb abbahagyta
  document.querySelectorAll("[data-folytat]").forEach(function (a) {
    var utolso = tar.get("faro-video-utolso-" + a.getAttribute("data-folytat"));
    if (utolso) {
      a.href = "./?p=nezo&s=" + encodeURIComponent(a.getAttribute("data-folytat")) + "&v=" + utolso;
      a.firstChild.textContent = "Folytatom ";
    }
  });

  // a sorozat listájában a már megnézett videók jelölése
  var l = latott();
  document.querySelectorAll(".watch__list a[data-kulcs]").forEach(function (a) {
    if (l[a.getAttribute("data-kulcs")]) a.classList.add("is-seen");
  });

  /* ---------- lejátszó ---------- */
  var doboz = document.querySelector("[data-player]");
  if (!doboz) return;
  var video = doboz.querySelector("video");
  var uzenet = doboz.querySelector(".player__msg");
  var src = doboz.getAttribute("data-src");
  var kulcs = doboz.getAttribute("data-kulcs");
  var sorozat = kulcs.replace(/-\d{2}$/, "");
  var sorszam = parseInt(kulcs.slice(-2), 10);
  tar.set("faro-video-utolso-" + sorozat, sorszam);

  function hiba(szoveg) {
    uzenet.innerHTML = "";
    uzenet.appendChild(document.createTextNode(szoveg + " "));
    var a = document.createElement("a");
    a.href = location.href;
    a.textContent = "Újratöltés";
    uzenet.appendChild(a);
    uzenet.hidden = false;
  }

  function folytatas() {
    var poz = tar.get("faro-video-poz-" + kulcs);
    if (poz && poz > 5 && video.duration && poz < video.duration - 10) video.currentTime = poz;
  }
  video.addEventListener("loadedmetadata", folytatas, { once: true });

  if (window.Hls && window.Hls.isSupported()) {
    var hls = new window.Hls({ xhrSetup: function (xhr) { xhr.withCredentials = true; }, maxBufferLength: 30 });
    hls.on(window.Hls.Events.ERROR, function (e, d) {
      if (!d.fatal) return;
      var kod = d.response && d.response.code;
      if (kod === 403) {
        hls.destroy();
        hiba("A lejátszás megszakadt: lejárt a belépés, vagy másik eszközön beléptél.");
      } else if (d.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
        hls.startLoad();
      } else if (d.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
        hls.recoverMediaError();
      } else {
        hls.destroy();
        hiba("A videó most nem játszható le.");
      }
    });
    hls.loadSource(src);
    hls.attachMedia(video);
  } else if (video.canPlayType("application/vnd.apple.mpegurl")) {
    video.src = src;   // Safari / iOS: beépített HLS
    video.addEventListener("error", function () { hiba("A videó most nem játszható le."); });
  } else {
    hiba("Ez a böngésző nem tudja lejátszani a videót. Kérlek, használj friss Chrome, Edge, Firefox vagy Safari böngészőt.");
  }

  // pozíció mentése, megnézett videó jelölése
  var mentve = 0;
  video.addEventListener("timeupdate", function () {
    if (Math.abs(video.currentTime - mentve) >= 5) {
      mentve = video.currentTime;
      tar.set("faro-video-poz-" + kulcs, Math.floor(video.currentTime));
    }
    if (video.duration && video.currentTime > video.duration * 0.9) {
      var x = latott();
      if (!x[kulcs]) { x[kulcs] = 1; tar.set(LATOTT, x); var a = document.querySelector('.watch__list a[data-kulcs="' + kulcs + '"]'); if (a) a.classList.add("is-seen"); }
    }
  });
  video.addEventListener("ended", function () { tar.set("faro-video-poz-" + kulcs, 0); });

  // nincs jobb klikkes „Videó mentése”
  doboz.addEventListener("contextmenu", function (e) { e.preventDefault(); });

  /* ---------- teljes képernyő (a dobozra, hogy a vízjel is látsszon) ---------- */
  var fsGomb = doboz.querySelector(".player__fs");
  function teljes() { return document.fullscreenElement || document.webkitFullscreenElement; }
  function valt() {
    if (teljes()) {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    } else if (doboz.requestFullscreen) {
      doboz.requestFullscreen().catch(function () {});
    } else if (doboz.webkitRequestFullscreen) {
      doboz.webkitRequestFullscreen();
    } else if (video.webkitEnterFullscreen) {
      video.webkitEnterFullscreen();   // iPhone: csak a videó lehet teljes képernyős
    }
  }
  fsGomb.addEventListener("click", valt);
  video.addEventListener("dblclick", function (e) { e.preventDefault(); valt(); });
  ["fullscreenchange", "webkitfullscreenchange"].forEach(function (ev) {
    document.addEventListener(ev, function () {
      doboz.classList.toggle("is-full", !!teljes());
      fsGomb.setAttribute("aria-label", teljes() ? "Kilépés a teljes képernyőből" : "Teljes képernyő");
    });
  });

  /* ---------- vízjel: a néző e-mail-címe, ritkán (kb. 2 percenként) más helyen ---------- */
  var wm = doboz.querySelector(".player__wm");
  wm.textContent = doboz.getAttribute("data-wm");
  var helyek = [[8, 10], [62, 12], [10, 78], [60, 80], [36, 46], [14, 44], [58, 46]];
  var utolsoHely = -1;
  function athelyez() {
    var i;
    do { i = Math.floor(Math.random() * helyek.length); } while (i === utolsoHely);
    utolsoHely = i;
    wm.style.opacity = "0";
    setTimeout(function () {
      wm.style.left = helyek[i][0] + "%";
      wm.style.top = helyek[i][1] + "%";
      wm.style.opacity = "";
    }, 1200);
  }
  athelyez();
  setInterval(athelyez, 120000);
})();
