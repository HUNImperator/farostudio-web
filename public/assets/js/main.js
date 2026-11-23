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

  new IntersectionObserver(function (entries) {
    nav.classList.toggle("is-visible", !entries[0].isIntersecting);
  }).observe(header);

  var links = Array.prototype.slice.call(nav.querySelectorAll(".tile-nav__link"));
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
