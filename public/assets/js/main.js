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
