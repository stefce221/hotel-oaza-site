/* Hotel Oaza — interactions. No dependencies. */
(function () {
  "use strict";

  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var i18n = window.oazaI18n || {
    t: function (k) {
      return k;
    },
    lang: "en",
    onChange: function () {}
  };
  var BASE = document.documentElement.getAttribute("data-base") || "/"; /* site root with the path prefix, e.g. "/" or "/hotel-oaza-site/" */

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  /* ---------- Header: solid on scroll, hide on scroll down ---------- */
  function initHeader() {
    var header = $("#header");
    if (!header) return;
    var lastY = window.scrollY;
    var ticking = false;
    /* Inner pages have no hero behind the header, so it stays solid. */
    var solid = document.body.classList.contains("page-inner");

    function update() {
      var y = window.scrollY;
      header.classList.toggle("is-scrolled", solid || y > 24);
      var goingDown = y > lastY + 4;
      var goingUp = y < lastY - 4;
      if (!document.body.classList.contains("menu-open")) {
        if (goingDown && y > 640) header.classList.add("is-hidden");
        if (goingUp || y < 640) header.classList.remove("is-hidden");
      }
      lastY = y;
      ticking = false;
    }

    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true }
    );
    update();
  }

  /* ---------- Mobile menu ---------- */
  function initMenu() {
    var toggle = $(".menu-toggle");
    var menu = $("#menu");
    if (!toggle || !menu) return;
    /* Everything behind the overlay goes inert while it is open, which also traps focus in header + menu. */
    var behind = [$("#main"), $(".site-footer"), $(".action-bar")].filter(Boolean);

    function setOpen(open) {
      if (!open && menu.contains(document.activeElement)) toggle.focus({ preventScroll: true });
      document.body.classList.toggle("menu-open", open);
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", i18n.t(open ? "menu.close" : "menu.open"));
      menu.inert = !open;
      behind.forEach(function (el) {
        el.inert = open;
      });
      if (open) {
        $("#header").classList.remove("is-hidden");
        var first = $("a", menu);
        if (first) first.focus({ preventScroll: true });
      }
    }

    toggle.addEventListener("click", function () {
      setOpen(!document.body.classList.contains("menu-open"));
    });
    $$("a", menu).forEach(function (a) {
      a.addEventListener("click", function () {
        setOpen(false);
      });
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && document.body.classList.contains("menu-open")) {
        setOpen(false);
        toggle.focus();
      }
    });
    /* Past the desktop breakpoint the overlay is hidden, so release the body lock and the inert page. */
    var desktop = window.matchMedia("(min-width: 1180px)");
    var onDesktop = function (e) {
      if (e.matches && document.body.classList.contains("menu-open")) setOpen(false);
    };
    if (desktop.addEventListener) desktop.addEventListener("change", onDesktop);
    else desktop.addListener(onDesktop);
  }

  /* ---------- Reveal on scroll ---------- */
  function initReveal() {
    var items = $$("[data-reveal]");
    if (!("IntersectionObserver" in window) || reduceMotion) {
      items.forEach(function (el) {
        el.classList.add("is-in");
      });
      return;
    }
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-in");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -6% 0px" }
    );
    items.forEach(function (el) {
      io.observe(el);
    });
  }

  /* ---------- Counters ---------- */
  function formatNumber(n) {
    try {
      return new Intl.NumberFormat(i18n.lang === "mk" ? "mk-MK" : "en-GB").format(n);
    } catch (e) {
      return String(n);
    }
  }

  function initCounters() {
    var counters = $$("[data-count]");
    function finish(el) {
      el.textContent = formatNumber(parseInt(el.getAttribute("data-count"), 10));
      el.setAttribute("data-done", "");
    }
    i18n.onChange(function () {
      counters.forEach(function (el) {
        if (el.hasAttribute("data-done")) finish(el);
      });
    });
    if (!("IntersectionObserver" in window) || reduceMotion) {
      counters.forEach(finish);
      return;
    }
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          io.unobserve(entry.target);
          animateCount(entry.target, finish);
        });
      },
      { threshold: 0.6 }
    );
    counters.forEach(function (el) {
      el.textContent = "0";
      io.observe(el);
    });
  }

  function animateCount(el, done) {
    var target = parseInt(el.getAttribute("data-count"), 10);
    var duration = 1800;
    var start = null;
    function step(ts) {
      if (start === null) start = ts;
      var p = Math.min((ts - start) / duration, 1);
      var eased = 1 - Math.pow(2, -10 * p);
      el.textContent = formatNumber(Math.round(target * eased));
      if (p < 1) {
        window.requestAnimationFrame(step);
      } else {
        done(el);
      }
    }
    window.requestAnimationFrame(step);
  }

  /* ---------- Hero parallax + optional video ---------- */
  function initHeroMedia() {
    var arch = $("[data-parallax]");
    if (!arch) return;
    var media = $("img", arch);

    var videoSrc = arch.getAttribute("data-video");
    if (videoSrc && videoSrc.charAt(0) === "/") videoSrc = BASE + videoSrc.slice(1); /* data-* values are not rewritten by the path prefix */
    if (videoSrc && !reduceMotion) {
      var video = document.createElement("video");
      video.muted = true;
      video.loop = true;
      video.playsInline = true;
      video.autoplay = true;
      video.setAttribute("aria-hidden", "true");
      video.poster = media.currentSrc || media.src;
      video.style.cssText = "position:absolute;inset:0;opacity:0;transition:opacity 1.2s";
      video.addEventListener("canplay", function () {
        video.style.opacity = "1";
      });
      video.addEventListener("error", function () {
        video.remove();
      });
      video.src = videoSrc;
      arch.appendChild(video);
    }

    if (reduceMotion) return;
    var ticking = false;
    function update() {
      var y = Math.min(window.scrollY, window.innerHeight);
      var shift = y * 0.07;
      $$("img, video", arch).forEach(function (el) {
        el.style.transform = "translate3d(0," + shift.toFixed(1) + "px,0) scale(1.12)";
      });
      ticking = false;
    }
    window.addEventListener(
      "scroll",
      function () {
        if (!ticking && window.scrollY < window.innerHeight * 1.2) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true }
    );
  }

  /* ---------- Rooms: tabs with gentle autoplay ---------- */
  function initRooms() {
    var body = $("[data-rooms]");
    if (!body) return;
    var tabs = $$('[role="tab"]', body);
    var panels = $$('[role="tabpanel"]', body);
    var autoplay = !reduceMotion;
    if (!autoplay) body.classList.add("is-manual");

    function activate(index, focus) {
      tabs.forEach(function (tab, i) {
        var on = i === index;
        tab.setAttribute("aria-selected", String(on));
        tab.tabIndex = on ? 0 : -1;
        panels[i].classList.toggle("is-active", on);
        if (on && focus) tab.focus();
      });
    }

    function stopAutoplay() {
      autoplay = false;
      body.classList.add("is-manual");
    }

    tabs.forEach(function (tab, i) {
      tab.addEventListener("click", function () {
        stopAutoplay();
        activate(i, false);
      });
      tab.addEventListener("keydown", function (e) {
        var next = null;
        if (e.key === "ArrowDown" || e.key === "ArrowRight") next = (i + 1) % tabs.length;
        if (e.key === "ArrowUp" || e.key === "ArrowLeft") next = (i - 1 + tabs.length) % tabs.length;
        if (e.key === "Home") next = 0;
        if (e.key === "End") next = tabs.length - 1;
        if (next !== null) {
          e.preventDefault();
          stopAutoplay();
          activate(next, true);
        }
      });
      var bar = $(".room-tab__bar", tab);
      bar.addEventListener("animationend", function () {
        if (autoplay && tab.getAttribute("aria-selected") === "true") {
          activate((i + 1) % tabs.length, false);
        }
      });
    });

    function setPaused(paused) {
      body.classList.toggle("is-paused", paused);
    }
    body.addEventListener("mouseenter", function () {
      setPaused(true);
    });
    body.addEventListener("mouseleave", function () {
      setPaused(false);
    });
    body.addEventListener("focusin", function () {
      setPaused(true);
    });
    body.addEventListener("focusout", function () {
      setPaused(false);
    });

    if ("IntersectionObserver" in window) {
      body.classList.add("is-paused");
      new IntersectionObserver(
        function (entries) {
          setPaused(!entries[0].isIntersecting);
        },
        { threshold: 0.35 }
      ).observe(body);
    }
  }

  /* ---------- Availability form: submits to the contact page; an empty arrival opens the picker ---------- */
  function initBooking() {
    var booking = $("#booking");
    var rules = window.oazaEnquiryRules;
    if (!booking || !rules) return;
    var arrival = booking.elements.arrival;
    rules.linkDatePair(arrival, booking.elements.departure, rules.todayIso());

    booking.addEventListener("submit", function (e) {
      if (arrival.value) return;
      e.preventDefault();
      arrival.focus();
      try {
        arrival.showPicker();
      } catch (err) {
        /* showPicker is unsupported or blocked here — focusing the field is enough */
      }
    });
  }

  /* ---------- Map: load Google Maps only on request ---------- */
  function initMap() {
    var map = $("[data-map]");
    if (!map) return;
    var btn = $("[data-map-load]", map);
    btn.addEventListener("click", function () {
      var iframe = document.createElement("iframe");
      iframe.src = "https://www.google.com/maps?q=Hotel+Oaza,+Tosho+Arsov+32,+Shtip,+North+Macedonia&z=15&output=embed";
      iframe.title = i18n.t("map.title");
      iframe.loading = "lazy";
      iframe.referrerPolicy = "no-referrer-when-downgrade";
      iframe.setAttribute("allowfullscreen", "");
      map.innerHTML = "";
      map.appendChild(iframe);
    });
  }

  /* ---------- Phone bar: after the hero (at once on inner pages), hidden while a form is on screen ---------- */
  function initActionBar() {
    var bar = $(".action-bar");
    if (!bar || !("IntersectionObserver" in window)) return;
    var hero = $(".hero, .page-hero");
    var formSection = $("[data-enquiry-section]");
    var heroOut = !hero;
    var formIn = false;

    function sync() {
      bar.classList.toggle("is-visible", heroOut && !formIn);
    }
    if (hero) {
      new IntersectionObserver(function (entries) {
        heroOut = !entries[0].isIntersecting;
        sync();
      }).observe(hero);
    }
    if (formSection) {
      new IntersectionObserver(
        function (entries) {
          formIn = entries[0].isIntersecting;
          sync();
        },
        { threshold: 0.15 }
      ).observe(formSection);
    }
    if (!hero && !formSection) sync();
  }

  function init() {
    initHeader();
    initMenu();
    initReveal();
    initCounters();
    initHeroMedia();
    initRooms();
    initBooking();
    initMap();
    initActionBar();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
