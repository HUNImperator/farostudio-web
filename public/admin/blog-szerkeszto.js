/*
 * FARO admin – blogszerkesztő.
 * Saját, könnyű szövegszerkesztő (contenteditable) eszköztárral; a mentett szöveget
 * a szerver mindig megtisztítja (_app/blog.php → blog_tisztit).
 */
(function () {
  "use strict";
  var doboz = document.querySelector("[data-blog-szerk]");
  if (!doboz) return;
  var csrf = doboz.getAttribute("data-csrf");
  var id = parseInt(doboz.getAttribute("data-id"), 10) || 0;
  var szoveg = document.getElementById("szoveg");
  var cim = document.getElementById("cim");
  var slug = document.getElementById("slug");
  var uzenet = document.querySelector("[data-mentes-uzenet]");
  var piszkos = false;
  var mentesFolyik = false;

  function $(s) { return doboz.querySelector(s); }
  function jelez(szoveg, hiba) {
    uzenet.textContent = szoveg;
    uzenet.style.color = hiba ? "#b3261e" : "";
  }

  /* ---------- automatikusan növő szövegmezők ---------- */
  function nyujt(t) { t.style.height = "auto"; t.style.height = t.scrollHeight + "px"; }
  [cim, document.getElementById("bevezeto")].forEach(function (t) {
    nyujt(t);
    t.addEventListener("input", function () { nyujt(t); });
    t.addEventListener("keydown", function (e) { if (e.key === "Enter" && t === cim) e.preventDefault(); });
  });

  /* ---------- webcím a címből, amíg kézzel nem írják át ---------- */
  function slugosit(s) {
    var m = { "á": "a", "é": "e", "í": "i", "ó": "o", "ö": "o", "ő": "o", "ú": "u", "ü": "u", "ű": "u" };
    return s.toLowerCase().replace(/[áéíóöőúüű]/g, function (c) { return m[c]; })
      .normalize("NFD").replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 80);
  }
  cim.addEventListener("input", function () { if (!slug.getAttribute("data-kezi")) slug.value = slugosit(cim.value); });
  slug.addEventListener("input", function () { slug.setAttribute("data-kezi", "1"); });
  slug.addEventListener("blur", function () { slug.value = slugosit(slug.value); });

  /* ---------- a szöveg előkészítése ---------- */
  document.execCommand("defaultParagraphSeparator", false, "p");
  function torloGomb(fig) {
    if (fig.querySelector(".szerk-torol")) return;
    var b = document.createElement("button");
    b.type = "button";
    b.className = "szerk-torol";
    b.title = fig.classList.contains("video") ? "Videó törlése" : "Kép törlése";
    b.setAttribute("aria-label", b.title);
    b.textContent = "×";
    fig.appendChild(b);
  }
  function figuraElokeszit(fig) {
    fig.setAttribute("contenteditable", "false");
    var fc = fig.querySelector("figcaption");
    if (!fc) { fc = document.createElement("figcaption"); fig.appendChild(fc); }
    fc.setAttribute("contenteditable", "true");
    fc.setAttribute("data-ures", fig.classList.contains("video") ? "Videó címe vagy aláírása (nem kötelező)" : "Képaláírás (nem kötelező)");
    torloGomb(fig);
  }
  function rendbeTesz() {
    Array.prototype.forEach.call(szoveg.querySelectorAll("figure"), figuraElokeszit);
    // mindig legyen szerkeszthető bekezdés a végén és a beágyazások után
    var utolso = szoveg.lastElementChild;
    if (!utolso || utolso.tagName === "FIGURE" || utolso.tagName === "HR" || utolso.tagName === "UL" || utolso.tagName === "OL" || utolso.tagName === "BLOCKQUOTE") {
      var p = document.createElement("p");
      p.innerHTML = "<br>";
      szoveg.appendChild(p);
    }
    // szöveg közvetlenül a szerkesztőben (pl. törlés után) → bekezdésbe
    Array.prototype.forEach.call(szoveg.childNodes, function (n) {
      if (n.nodeType === 3 && n.data.trim() !== "") {
        var p = document.createElement("p");
        szoveg.insertBefore(p, n);
        p.appendChild(n);
      }
    });
  }
  rendbeTesz();

  /* ---------- kijelölés megőrzése (párbeszéd, feltöltés idejére) ---------- */
  var tartomany = null;
  function jelolesMent() {
    var s = window.getSelection();
    if (s.rangeCount && szoveg.contains(s.anchorNode)) tartomany = s.getRangeAt(0).cloneRange();
  }
  function jelolesVissza() {
    szoveg.focus();
    if (tartomany) {
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(tartomany);
    }
  }
  document.addEventListener("selectionchange", function () {
    if (szoveg.contains(document.activeElement) || document.activeElement === szoveg) { jelolesMent(); gombAllapot(); }
  });

  /** A kurzort tartalmazó, közvetlenül a szerkesztőben lévő blokk. */
  function aktualisBlokk() {
    var s = window.getSelection();
    var n = s.rangeCount ? s.getRangeAt(0).startContainer : null;
    if (!n || !szoveg.contains(n)) n = tartomany ? tartomany.startContainer : null;
    while (n && n.parentNode !== szoveg) n = n.parentNode;
    return n && n.parentNode === szoveg ? n : null;
  }
  /** Blokkelem beszúrása az aktuális blokk után (üres bekezdés helyére). */
  function blokkBeszur(el) {
    var b = aktualisBlokk();
    if (b && b.tagName === "P" && b.textContent.trim() === "" && !b.querySelector("img")) {
      szoveg.replaceChild(el, b);
    } else if (b) {
      szoveg.insertBefore(el, b.nextSibling);
    } else {
      szoveg.appendChild(el);
    }
    var p = el.nextElementSibling;
    if (!p || p.tagName === "FIGURE" || p.tagName === "HR") {
      p = document.createElement("p");
      p.innerHTML = "<br>";
      szoveg.insertBefore(p, el.nextSibling);
    }
    var r = document.createRange();
    r.setStart(p, 0);
    r.collapse(true);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
    valtozott();
  }

  /* ---------- eszköztár ---------- */
  var gombok = doboz.querySelectorAll("[data-cmd]");
  function gombAllapot() {
    var b = aktualisBlokk();
    var tag = b ? b.tagName.toLowerCase() : "";
    Array.prototype.forEach.call(gombok, function (g) {
      var c = g.getAttribute("data-cmd");
      var be = false;
      try {
        if (c === "bold" || c === "italic") be = document.queryCommandState(c);
        else if (c === "ul") be = tag === "ul";
        else if (c === "ol") be = tag === "ol";
        else if (c === "h2" || c === "h3" || c === "blockquote" || c === "p") be = tag === c;
      } catch (e) {}
      g.classList.toggle("is-on", be);
    });
  }
  Array.prototype.forEach.call(gombok, function (g) {
    g.addEventListener("mousedown", function (e) { e.preventDefault(); });
    g.addEventListener("click", function () { parancs(g.getAttribute("data-cmd")); });
  });

  function blokkFormatum(tag) {
    var b = aktualisBlokk();
    if (b && b.tagName.toLowerCase() === tag && tag !== "p") tag = "p";   // ismételt kattintás: vissza bekezdésre
    document.execCommand("formatBlock", false, "<" + tag + ">");
  }

  function parancs(c) {
    if (c !== "kep" && c !== "video" && c !== "link") jelolesVissza();
    switch (c) {
      case "p": case "h2": case "h3": case "blockquote": blokkFormatum(c); break;
      case "bold": case "italic": document.execCommand(c); break;
      case "ul": document.execCommand("insertUnorderedList"); break;
      case "ol": document.execCommand("insertOrderedList"); break;
      case "unlink": document.execCommand("unlink"); break;
      case "undo": case "redo": document.execCommand(c); break;
      case "tisztit": document.execCommand("removeFormat"); document.execCommand("unlink"); document.execCommand("formatBlock", false, "<p>"); break;
      case "hr":
        var hr = document.createElement("hr");
        blokkBeszur(hr);
        break;
      case "link": linkBeszur(); break;
      case "kep": $("[data-kep-fajl]").click(); break;
      case "video": videoBeszur(); break;
    }
    rendbeTesz();
    gombAllapot();
    valtozott();
  }

  function linkBeszur() {
    jelolesMent();
    var s = window.getSelection();
    var a = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentNode).closest("a");
    var url = window.prompt("A link címe (pl. https://… vagy egy e-mail-cím):", a ? a.getAttribute("href") : "https://");
    if (url === null) return;
    url = url.trim();
    if (!url || url === "https://") return;
    if (/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(url)) url = "mailto:" + url;
    else if (!/^(https?:|mailto:|tel:|\/|#)/i.test(url)) url = "https://" + url;
    jelolesVissza();
    if (window.getSelection().isCollapsed && !a) {
      var link = document.createElement("a");
      link.href = url;
      link.textContent = url.replace(/^mailto:/, "");
      window.getSelection().getRangeAt(0).insertNode(link);
    } else {
      document.execCommand("createLink", false, url);
    }
  }

  function videoBeszur() {
    jelolesMent();
    var be = window.prompt("A YouTube-videó címe (link):", "");
    if (!be) return;
    var m = be.match(/(?:youtu\.be\/|v=|embed\/|shorts\/)([A-Za-z0-9_-]{11})/) || be.trim().match(/^([A-Za-z0-9_-]{11})$/);
    if (!m) { window.alert("Ebből nem tudtam kiolvasni a YouTube-videót. Másold be a videó linkjét a böngésző címsorából."); return; }
    jelolesVissza();
    var fig = document.createElement("figure");
    fig.className = "video";
    fig.setAttribute("data-yt", m[1]);
    var img = document.createElement("img");
    img.src = "https://i.ytimg.com/vi/" + m[1] + "/hqdefault.jpg";
    img.alt = "";
    fig.appendChild(img);
    figuraElokeszit(fig);
    blokkBeszur(fig);
  }

  /* ---------- képek: kicsinyítés a böngészőben, feltöltés ---------- */
  function kicsinyit(fajl, max) {
    return new Promise(function (ok) {
      if (!/^image\/(jpeg|png|webp)$/.test(fajl.type) || !window.createImageBitmap) return ok(fajl);
      createImageBitmap(fajl).then(function (bm) {
        var arany = Math.min(1, max / Math.max(bm.width, bm.height));
        if (arany === 1 && fajl.size < 4 * 1024 * 1024) return ok(fajl);
        var c = document.createElement("canvas");
        c.width = Math.round(bm.width * arany);
        c.height = Math.round(bm.height * arany);
        var x = c.getContext("2d");
        x.fillStyle = "#fff";
        x.fillRect(0, 0, c.width, c.height);
        x.drawImage(bm, 0, 0, c.width, c.height);
        c.toBlob(function (b) { ok(b ? new File([b], "kep.jpg", { type: "image/jpeg" }) : fajl); }, "image/jpeg", 0.9);
      }).catch(function () { ok(fajl); });
    });
  }
  function feltolt(fajl, fajta) {
    return kicsinyit(fajl, 2600).then(function (f) {
      var fd = new FormData();
      fd.append("kep", f);
      fd.append("fajta", fajta);
      return fetch("./?p=blog-kep", { method: "POST", body: fd, headers: { "X-CSRF": csrf }, credentials: "same-origin" });
    }).then(function (v) {
      return v.json().catch(function () { return { hiba: "A szerver nem válaszolt rendesen (" + v.status + ")." }; });
    }).then(function (j) {
      if (!j.ok) throw new Error(j.hiba || "A feltöltés nem sikerült.");
      return j;
    });
  }
  function kepBeszur(fajl) {
    if (!fajl || !/^image\//.test(fajl.type)) return;
    jelez("Kép feltöltése…");
    feltolt(fajl, "kep").then(function (j) {
      jelolesVissza();
      var fig = document.createElement("figure");
      fig.className = "kep";
      fig.setAttribute("data-kep", j.kulcs);
      var img = document.createElement("img");
      img.src = j.src;
      img.alt = "";
      fig.appendChild(img);
      figuraElokeszit(fig);
      blokkBeszur(fig);
      jelez("A kép bekerült. Írhatsz alá képaláírást.");
    }).catch(function (e) { jelez(e.message, true); });
  }
  $("[data-kep-fajl]").addEventListener("change", function () {
    var f = this.files[0];
    this.value = "";
    kepBeszur(f);
  });

  /* ---------- beillesztés és behúzás: csak tiszta formázás ---------- */
  var ENGEDETT = { P: "p", H1: "h2", H2: "h2", H3: "h3", H4: "h3", H5: "h3", H6: "h3", STRONG: "strong", B: "strong", EM: "em", I: "em",
    A: "a", UL: "ul", OL: "ol", LI: "li", BLOCKQUOTE: "blockquote", BR: "br", DIV: "p" };
  var TILTOTT = /^(SCRIPT|STYLE|META|LINK|TITLE|HEAD|O:P|XML|SVG|IFRAME|OBJECT|IMG|FIGURE|BUTTON|INPUT|SELECT|TEXTAREA)$/;
  function tisztaHtml(html) {
    var d = new DOMParser().parseFromString(html, "text/html");
    function bejar(n) {
      var ki = "";
      Array.prototype.forEach.call(n.childNodes, function (c) {
        if (c.nodeType === 3) { ki += c.data.replace(/[&<>]/g, function (x) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;" }[x]; }).replace(/\s+/g, " "); return; }
        if (c.nodeType !== 1 || TILTOTT.test(c.tagName)) return;
        var t = ENGEDETT[c.tagName];
        // Word: félkövér/dőlt stílussal jelölve
        if (!t && c.tagName === "SPAN") {
          var st = c.getAttribute("style") || "";
          if (/font-weight:\s*(bold|[6-9]00)/i.test(st)) t = "strong";
          else if (/font-style:\s*italic/i.test(st)) t = "em";
        }
        var bent = bejar(c);
        if (!t) { ki += bent; return; }
        if (t === "br") { ki += "<br>"; return; }
        if (t === "a") {
          var h = c.getAttribute("href") || "";
          if (!/^(https?:|mailto:|tel:)/i.test(h)) { ki += bent; return; }
          ki += '<a href="' + h.replace(/"/g, "&quot;") + '">' + bent + "</a>";
          return;
        }
        if (bent.replace(/<br>|\s|&nbsp;/g, "") === "" && t !== "li") return;
        ki += "<" + t + ">" + bent + "</" + t + ">";
      });
      return ki;
    }
    return bejar(d.body);
  }
  function szovegHtml(t) {
    return t.replace(/\r/g, "").split(/\n{2,}/).map(function (b) {
      b = b.trim();
      if (!b) return "";
      return "<p>" + b.replace(/[&<>]/g, function (x) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;" }[x]; }).replace(/\n/g, "<br>") + "</p>";
    }).join("");
  }
  szoveg.addEventListener("paste", function (e) {
    var cd = e.clipboardData;
    if (!cd) return;
    var kep = Array.prototype.find.call(cd.files || [], function (f) { return /^image\//.test(f.type); });
    e.preventDefault();
    if (kep) { jelolesMent(); kepBeszur(kep); return; }
    // képaláírásba csak sima szöveg
    var benneFc = document.activeElement && document.activeElement.tagName === "FIGCAPTION";
    var html = cd.getData("text/html");
    var t = cd.getData("text/plain");
    if (benneFc || !html) {
      if (benneFc || t.indexOf("\n") < 0) document.execCommand("insertText", false, t);
      else document.execCommand("insertHTML", false, szovegHtml(t));
    } else {
      document.execCommand("insertHTML", false, tisztaHtml(html) || szovegHtml(t));
    }
    rendbeTesz();
    valtozott();
  });
  szoveg.addEventListener("dragover", function (e) { if (e.dataTransfer && Array.prototype.some.call(e.dataTransfer.types, function (t) { return t === "Files"; })) e.preventDefault(); });
  szoveg.addEventListener("drop", function (e) {
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (!f || !/^image\//.test(f.type)) return;
    e.preventDefault();
    var r = document.caretRangeFromPoint ? document.caretRangeFromPoint(e.clientX, e.clientY) : null;
    if (r) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); jelolesMent(); }
    kepBeszur(f);
  });

  /* ---------- kép/videó törlése ---------- */
  szoveg.addEventListener("click", function (e) {
    var t = e.target.closest(".szerk-torol");
    if (!t) return;
    var fig = t.closest("figure");
    if (fig && window.confirm(fig.classList.contains("video") ? "Törlöd a videót az írásból?" : "Törlöd a képet az írásból?")) {
      fig.parentNode.removeChild(fig);
      rendbeTesz();
      valtozott();
    }
  });
  szoveg.addEventListener("keydown", function (e) {
    if (e.key !== "Enter" || e.shiftKey) return;
    // Enter a képaláírásban: ne törje a sort
    if (e.target.tagName === "FIGCAPTION") { e.preventDefault(); return; }
    // üres sorban Enter az idézetben: kilépés az idézetből
    var b = aktualisBlokk();
    if (!b || b.tagName !== "BLOCKQUOTE") return;
    var s = window.getSelection();
    var n = s.anchorNode === b ? null : s.anchorNode;
    while (n && n.parentNode !== b) n = n.parentNode;
    var ures = n ? (n.textContent || "").trim() === "" : b.textContent.trim() === "";
    if (!ures) return;
    e.preventDefault();
    if (n) b.removeChild(n);
    var p = document.createElement("p");
    p.innerHTML = "<br>";
    szoveg.insertBefore(p, b.nextSibling);
    if (!b.textContent.trim()) szoveg.removeChild(b);
    var r = document.createRange();
    r.setStart(p, 0);
    r.collapse(true);
    s.removeAllRanges();
    s.addRange(r);
    valtozott();
  });

  /* ---------- kezdőkép ---------- */
  var kezdoKulcs = document.getElementById("kezdokep");
  var kezdoKep = $("[data-kezdokep-kep]");
  function kezdoMutat(src) {
    kezdoKep.hidden = !src;
    if (src) kezdoKep.src = src; else kezdoKep.removeAttribute("src");
    $("[data-kezdokep-ures]").hidden = !!src;
    $("[data-kezdokep-torol]").hidden = !src;
    $("[data-kezdokep-valaszt]").textContent = src ? "Csere" : "Kép feltöltése";
  }
  $("[data-kezdokep-valaszt]").addEventListener("click", function () { $("[data-kezdokep-fajl]").click(); });
  $("[data-kezdokep-fajl]").addEventListener("change", function () {
    var f = this.files[0];
    this.value = "";
    if (!f) return;
    jelez("Kezdőkép feltöltése…");
    feltolt(f, "kezdo").then(function (j) {
      kezdoKulcs.value = j.kulcs;
      kezdoMutat(j.src);
      jelez("A kezdőkép feltöltve – mentéskor kerül a cikkhez.");
      valtozott();
    }).catch(function (e) { jelez(e.message, true); });
  });
  $("[data-kezdokep-torol]").addEventListener("click", function () {
    kezdoKulcs.value = "";
    kezdoMutat(null);
    valtozott();
  });

  /* ---------- számláló ---------- */
  var szamlalo = $("[data-szamlalo]");
  function szamol() {
    var n = (szoveg.innerText.match(/\S+/g) || []).length;
    szamlalo.textContent = n + " szó · kb. " + Math.max(1, Math.round(n / 200)) + " perc olvasás";
  }
  szamol();

  /* ---------- mentés ---------- */
  function valtozott() {
    piszkos = true;
    szamol();
    jelez("Nem mentett módosítás");
  }
  ["input", "change"].forEach(function (ev) {
    doboz.addEventListener(ev, function (e) {
      if (e.target.matches("[data-kep-fajl], [data-kezdokep-fajl]")) return;
      valtozott();
    });
  });

  function tartalomHtml() {
    var k = szoveg.cloneNode(true);
    Array.prototype.forEach.call(k.querySelectorAll(".szerk-torol"), function (b) { b.parentNode.removeChild(b); });
    Array.prototype.forEach.call(k.querySelectorAll("[contenteditable]"), function (e) { e.removeAttribute("contenteditable"); });
    Array.prototype.forEach.call(k.querySelectorAll("figure.video img"), function (e) { e.parentNode.removeChild(e); });
    return k.innerHTML;
  }
  function allapot() { var r = doboz.querySelector('input[name="allapot"]:checked'); return r ? r.value : "piszkozat"; }

  function ment(csendes) {
    if (mentesFolyik) return Promise.resolve(false);
    if (!cim.value.trim() && !csendes) { jelez("Adj címet az írásnak.", true); cim.focus(); return Promise.resolve(false); }
    mentesFolyik = true;
    if (!csendes) jelez("Mentés…");
    var adat = {
      id: id, cim: cim.value, slug: slug.value, bevezeto: document.getElementById("bevezeto").value,
      tartalom: tartalomHtml(), tema: document.getElementById("tema").value, kezdokep: kezdoKulcs.value,
      kezdokep_alt: document.getElementById("kezdokep_alt").value, meta: document.getElementById("meta").value,
      allapot: allapot(), megjelenes: document.getElementById("megjelenes").value
    };
    return fetch("./?p=blog-ment", {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF": csrf },
      body: JSON.stringify(adat)
    }).then(function (v) {
      return v.json().catch(function () { return { hiba: "A szerver nem válaszolt rendesen (" + v.status + ")." }; });
    }).then(function (j) {
      mentesFolyik = false;
      if (!j.ok) { jelez(j.hiba || "A mentés nem sikerült.", true); return false; }
      piszkos = false;
      if (!id) {
        id = j.id;
        doboz.setAttribute("data-id", id);
        history.replaceState(null, "", "./?p=blog-szerk&id=" + id);
      }
      slug.value = j.slug;
      slug.setAttribute("data-kezi", "1");
      var jel = $("[data-allapot-jel]");
      jel.textContent = j.allapotNev;
      jel.className = "badge badge--b-" + j.allapot;
      var megnyit = $("[data-megnyit]");
      megnyit.hidden = j.allapot !== "megjelent";
      megnyit.querySelector("a").href = "../gondolatok/" + j.slug;
      jelez((csendes ? "Automatikusan mentve " : "Mentve ") + j.ido + (j.allapot === "megjelent" ? " – a weboldalon is frissült." : j.allapot === "idozitve" ? " – időzítve." : " – piszkozat."));
      return true;
    }).catch(function () {
      mentesFolyik = false;
      jelez("A mentés nem sikerült – nincs kapcsolat a szerverrel. A szöveg itt maradt, próbáld újra.", true);
      return false;
    });
  }
  $("[data-ment]").addEventListener("click", function () { ment(false); });
  document.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") { e.preventDefault(); ment(false); }
  });
  $("[data-elonezet]").addEventListener("click", function () {
    var ablak = window.open("about:blank", "_blank");
    ment(false).then(function (ok) {
      if (ok || id) { if (ablak) ablak.location = "./?p=blog-elonezet&id=" + id; }
      else if (ablak) ablak.close();
    });
  });
  // piszkozat: automatikus mentés félpercenként; közzétett írás csak gombra frissül
  setInterval(function () {
    if (piszkos && allapot() === "piszkozat" && doboz.getAttribute("data-allapot") !== "kozzeteve" && cim.value.trim()) ment(true);
  }, 30000);
  window.addEventListener("beforeunload", function (e) {
    if (piszkos) { e.preventDefault(); e.returnValue = ""; }
  });
})();
