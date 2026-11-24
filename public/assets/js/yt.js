/*
 * YouTube-videók kattintásra: amíg a látogató nem indítja el, a YouTube-ról semmi
 * nem töltődik be (az előnézeti kép a saját tárhelyről jön). Indításkor a
 * youtube-nocookie.com beágyazott lejátszója jelenik meg.
 */
(function () {
  "use strict";
  document.addEventListener("click", function (e) {
    var gomb = e.target.closest && e.target.closest("button[data-yt]");
    if (!gomb) return;
    var id = gomb.getAttribute("data-yt");
    if (!/^[A-Za-z0-9_-]{11}$/.test(id)) return;
    var keret = document.createElement("div");
    keret.className = gomb.className + " is-playing";
    var ifr = document.createElement("iframe");
    ifr.src = "https://www.youtube-nocookie.com/embed/" + id + "?autoplay=1&rel=0&modestbranding=1";
    ifr.title = gomb.getAttribute("data-cim") || "YouTube-videó";
    ifr.allow = "autoplay; encrypted-media; picture-in-picture; fullscreen";
    ifr.allowFullscreen = true;
    ifr.referrerPolicy = "strict-origin-when-cross-origin";
    keret.appendChild(ifr);
    gomb.parentNode.replaceChild(keret, gomb);
    ifr.focus();
  });
})();
