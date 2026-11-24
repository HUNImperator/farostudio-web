/* Kezdőlap, Gondolatok csempe: a témakörök és a legutóbbi 3 írás a blogból */
(function () {
  "use strict";
  var doboz = document.querySelector("[data-blog-csempe]");
  if (!doboz || !window.fetch) return;
  fetch("api/blog.php", { headers: { Accept: "application/json" } })
    .then(function (v) { return v.ok ? v.json() : null; })
    .then(function (d) {
      if (!d) return;
      var cimkek = doboz.querySelector(".topic-tags");
      if (cimkek && d.temak && d.temak.length) {
        cimkek.innerHTML = "";
        d.temak.forEach(function (t) {
          var li = document.createElement("li");
          var a = document.createElement("a");
          a.href = "gondolatok/#" + t.kulcs;
          a.textContent = t.nev;
          li.appendChild(a);
          cimkek.appendChild(li);
        });
      }
      if (d.legutobbi && d.legutobbi.length) {
        var ul = document.createElement("ul");
        ul.className = "notes__legutobbi";
        ul.setAttribute("aria-label", "Legutóbbi írások");
        d.legutobbi.forEach(function (c) {
          var li = document.createElement("li");
          var a = document.createElement("a");
          a.href = c.url;
          var cim = document.createElement("span");
          cim.className = "notes__cim";
          cim.textContent = c.cim;
          var dat = document.createElement("span");
          dat.className = "notes__datum";
          dat.textContent = c.datum;
          a.appendChild(cim);
          a.appendChild(dat);
          li.appendChild(a);
          ul.appendChild(li);
        });
        var gomb = doboz.querySelector(".btn");
        gomb.parentNode.insertBefore(ul, gomb);
      }
    })
    .catch(function () {});
})();
