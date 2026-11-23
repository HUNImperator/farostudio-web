// Mobil menü nyitása / zárása
(function () {
  var toggle = document.querySelector(".nav-toggle");
  if (!toggle) return;
  var root = document.documentElement;

  function setOpen(open) {
    root.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    toggle.setAttribute("aria-label", open ? "Menü bezárása" : "Menü megnyitása");
  }

  toggle.addEventListener("click", function () {
    setOpen(!root.classList.contains("nav-open"));
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") setOpen(false);
  });
  document.querySelectorAll(".nav__link").forEach(function (link) {
    link.addEventListener("click", function () { setOpen(false); });
  });
})();

// Lebegő csempemenü: akkor jelenik meg, amikor a fejléc kikerül a képernyőről,
// a kezdőlapon pedig jelzi, melyik csempénél tart az olvasó.
(function () {
  var nav = document.querySelector(".tile-nav");
  var header = document.querySelector(".site-header");
  if (!nav || !header || !("IntersectionObserver" in window)) {
    if (nav) nav.classList.add("is-visible");
    return;
  }

  var toggle = nav.querySelector(".tile-nav__toggle");
  function setOpen(open) {
    nav.classList.toggle("is-open", open);
    if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
  }
  if (toggle) {
    toggle.addEventListener("click", function () {
      setOpen(!nav.classList.contains("is-open"));
    });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") setOpen(false);
  });

  new IntersectionObserver(function (entries) {
    var visible = !entries[0].isIntersecting;
    nav.classList.toggle("is-visible", visible);
    if (!visible) setOpen(false);
  }).observe(header);

  var links = Array.prototype.slice.call(nav.querySelectorAll(".tile-nav__link"));
  links.forEach(function (a) {
    a.addEventListener("click", function () { setOpen(false); });
  });
  var sections = links
    .map(function (a) { return document.getElementById(a.getAttribute("data-tile")); })
    .filter(Boolean);
  if (!sections.length) return; // aloldalon a négyzetek a kezdőlapra visznek

  function setActive(id) {
    links.forEach(function (a) {
      if (a.getAttribute("data-tile") === id) a.setAttribute("aria-current", "true");
      else a.removeAttribute("aria-current");
    });
  }

  var spy = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) setActive(e.target.id);
    });
  }, { rootMargin: "-45% 0px -50% 0px" });
  sections.forEach(function (s) { spy.observe(s); });
})();

// Gondolatok oldal: témakör-szűrő (a kezdőlapi címkékről #tema hivatkozással is működik)
(function () {
  var buttons = Array.prototype.slice.call(document.querySelectorAll(".filter"));
  if (!buttons.length) return;
  var desc = document.querySelector(".filter-desc");
  var posts = Array.prototype.slice.call(document.querySelectorAll(".post"));
  var empty = document.querySelector("[data-empty]");

  function apply(tema) {
    var found = 0;
    buttons.forEach(function (b) {
      var on = b.getAttribute("data-filter") === tema;
      b.setAttribute("aria-pressed", on ? "true" : "false");
      if (on && desc) desc.textContent = b.getAttribute("data-desc") || "";
    });
    posts.forEach(function (p) {
      var show = tema === "osszes" || p.getAttribute("data-tema") === tema;
      p.hidden = !show;
      if (show) found++;
    });
    if (empty) empty.hidden = found > 0;
  }

  buttons.forEach(function (b) {
    b.addEventListener("click", function () {
      var tema = b.getAttribute("data-filter");
      apply(tema);
      history.replaceState(null, "", tema === "osszes" ? location.pathname : "#" + tema);
    });
  });

  var start = location.hash.replace("#", "");
  var valid = buttons.some(function (b) { return b.getAttribute("data-filter") === start; });
  apply(valid ? start : "osszes");
})();
