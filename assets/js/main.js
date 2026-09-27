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
  var MAIL_TO = "info@oazahotel.com.mk";

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

    function update() {
      var y = window.scrollY;
      header.classList.toggle("is-scrolled", y > 24);
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

  /* ---------- Dates ---------- */
  function isoDate(d) {
    var tz = d.getTimezoneOffset() * 60000;
    return new Date(d.getTime() - tz).toISOString().slice(0, 10);
  }

  function addDays(iso, n) {
    var d = new Date(iso + "T12:00:00");
    d.setDate(d.getDate() + n);
    return isoDate(d);
  }

  function linkDatePair(arrival, departure) {
    var today = isoDate(new Date());
    arrival.min = today;
    departure.min = addDays(today, 1);
    arrival.addEventListener("change", function () {
      if (!arrival.value) return;
      var minDep = addDays(arrival.value, 1);
      departure.min = minDep;
      if (!departure.value || departure.value < minDep) departure.value = minDep;
    });
  }

  /* ---------- Booking bar → enquiry form ---------- */
  function initBooking() {
    var booking = $("#booking");
    var form = $("#enquiry");
    if (!booking || !form) return;
    linkDatePair(booking.elements.arrival, booking.elements.departure);
    linkDatePair(form.elements.arrival, form.elements.departure);

    booking.addEventListener("submit", function (e) {
      e.preventDefault();
      var b = booking.elements;
      if (!b.arrival.value) {
        b.arrival.focus();
        try {
          b.arrival.showPicker();
        } catch (err) {
          /* showPicker is unsupported or blocked here — focusing the field is enough */
        }
        return;
      }
      form.elements.arrival.value = b.arrival.value;
      form.elements.departure.value = b.departure.value;
      form.elements.guests.value = b.guests.value;
      setRadio(form, "type", "stay");
      goToForm(form);
    });

    $$("[data-room]").forEach(function (link) {
      link.addEventListener("click", function (e) {
        e.preventDefault();
        form.elements.room.value = link.getAttribute("data-room");
        setRadio(form, "type", "stay");
        goToForm(form);
      });
    });

    $$("[data-enquiry]").forEach(function (link) {
      link.addEventListener("click", function (e) {
        e.preventDefault();
        setRadio(form, "type", link.getAttribute("data-enquiry"));
        goToForm(form);
      });
    });
  }

  function setRadio(form, name, value) {
    $$('input[name="' + name + '"]', form).forEach(function (r) {
      r.checked = r.value === value;
    });
  }

  function goToForm(form) {
    var section = $("#contact");
    section.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });
    window.setTimeout(
      function () {
        form.elements.name.focus({ preventScroll: true });
      },
      reduceMotion ? 0 : 700
    );
  }

  /* ---------- Enquiry form → mailto ---------- */
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function setError(input, message) {
    var field = input.closest(".field");
    if (!field) return;
    field.classList.toggle("has-error", Boolean(message));
    input.setAttribute("aria-invalid", message ? "true" : "false");
    var slot = $(".field__error", field);
    if (slot) slot.textContent = message || "";
  }

  function validate(form) {
    var el = form.elements;
    var firstBad = null;
    var checks = [
      [el.name, el.name.value.trim() ? "" : i18n.t("err.required")],
      [el.email, !el.email.value.trim() ? i18n.t("err.required") : EMAIL_RE.test(el.email.value.trim()) ? "" : i18n.t("err.email")],
      [el.departure, el.arrival.value && el.departure.value && el.departure.value <= el.arrival.value ? i18n.t("err.dates") : ""]
    ];
    checks.forEach(function (pair) {
      setError(pair[0], pair[1]);
      if (pair[1] && !firstBad) firstBad = pair[0];
    });
    return firstBad;
  }

  function buildMail(form) {
    var el = form.elements;
    var type = (form.querySelector('input[name="type"]:checked') || { value: "stay" }).value;
    var roomOpt = el.room.options[el.room.selectedIndex];
    var guestOpt = el.guests.options[el.guests.selectedIndex];
    var none = i18n.t("mail.none");
    var typeLabel = i18n.t("type." + type);
    var lines = [
      i18n.t("mail.greeting"),
      "",
      i18n.t("mail.intro"),
      "",
      i18n.t("mail.type") + ": " + typeLabel,
      i18n.t("mail.room") + ": " + (roomOpt ? roomOpt.textContent.trim() : none),
      i18n.t("mail.arrival") + ": " + (el.arrival.value || none),
      i18n.t("mail.departure") + ": " + (el.departure.value || none),
      i18n.t("mail.guests") + ": " + (guestOpt ? guestOpt.textContent.trim() : none),
      "",
      i18n.t("mail.name") + ": " + el.name.value.trim(),
      i18n.t("mail.email") + ": " + el.email.value.trim(),
      i18n.t("mail.phone") + ": " + (el.phone.value.trim() || none),
      "",
      i18n.t("mail.message") + ":",
      el.message.value.trim() || none
    ];
    var subject = i18n.t("mail.subject", { type: typeLabel, name: el.name.value.trim() });
    return "mailto:" + MAIL_TO + "?subject=" + encodeURIComponent(subject) + "&body=" + encodeURIComponent(lines.join("\n"));
  }

  /* Tie each error slot to its control so the reason is re-read on focus. */
  function linkErrors(form) {
    $$(".field", form).forEach(function (field) {
      var control = $("input, select, textarea", field);
      var slot = $(".field__error", field);
      if (!control || !slot || !control.id) return;
      slot.id = control.id + "-error";
      control.setAttribute("aria-describedby", slot.id);
    });
  }

  function initEnquiry() {
    var form = $("#enquiry");
    if (!form) return;
    var status = $(".form__status", form);
    linkErrors(form);

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var bad = validate(form);
      if (bad) {
        status.textContent = i18n.t("status.fix");
        bad.focus();
        return;
      }
      status.textContent = i18n.t("status.sent");
      window.location.href = buildMail(form);
    });

    /* Once an error is showing, re-check live. Arrival is included because changing it can fix a departure error. */
    function revalidate() {
      if ($('[aria-invalid="true"]', form)) validate(form);
    }
    ["name", "email", "arrival", "departure"].forEach(function (name) {
      form.elements[name].addEventListener("input", revalidate);
      form.elements[name].addEventListener("change", revalidate);
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

  /* ---------- Mobile action bar + active nav ---------- */
  function initScrollSpy() {
    if (!("IntersectionObserver" in window)) return;
    var bar = $(".action-bar");
    var hero = $(".hero");
    var contact = $("#contact");
    var heroOut = false;
    var contactIn = false;

    function syncBar() {
      if (bar) bar.classList.toggle("is-visible", heroOut && !contactIn);
    }
    if (hero) {
      new IntersectionObserver(function (entries) {
        heroOut = !entries[0].isIntersecting;
        syncBar();
      }).observe(hero);
    }
    if (contact) {
      new IntersectionObserver(
        function (entries) {
          contactIn = entries[0].isIntersecting;
          syncBar();
        },
        { threshold: 0.15 }
      ).observe(contact);
    }

    var links = $$(".nav__link");
    var byId = {};
    links.forEach(function (a) {
      byId[a.getAttribute("href").slice(1)] = a;
    });
    var spy = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          var link = byId[entry.target.id];
          if (!link) return;
          if (entry.isIntersecting) {
            links.forEach(function (l) {
              l.classList.toggle("is-active", l === link);
            });
          }
        });
      },
      { rootMargin: "-45% 0px -50% 0px" }
    );
    Object.keys(byId).forEach(function (id) {
      var section = document.getElementById(id);
      if (section) spy.observe(section);
    });
  }

  function init() {
    initHeader();
    initMenu();
    initReveal();
    initCounters();
    initHeroMedia();
    initRooms();
    initBooking();
    initEnquiry();
    initMap();
    initScrollSpy();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
