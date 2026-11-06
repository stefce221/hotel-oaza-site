/* ==========================================================================
   Hotel Oaza — optional effects layer.
   Ideas picked from the 21st.dev component catalogue and rebuilt in plain
   JS/CSS (the originals are React + Motion/GSAP). Nothing here is required
   by main.js; every effect is additive and can be removed on its own.

   Three ways back:
   1. The "New effects" panel (bottom-left) switches each effect on or off.
   2. Adding ?fx=off to the address turns all of them off for that visit.
   3. _backup/2026-09-27_before-21st/revert.ps1 restores the previous site.
   ========================================================================== */
(function () {
  "use strict";

  var STORE_KEY = "oaza-fx";
  var SHOW_PANEL = true; /* review tool: set to false once the effects are decided */
  var root = document.documentElement;
  var BASE = root.getAttribute("data-base") || "/"; /* site root with the path prefix, e.g. "/" or "/hotel-oaza-site/" */
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var finePointer = window.matchMedia("(hover: hover) and (pointer: fine)").matches;
  var i18n = window.oazaI18n || { lang: "en", onChange: function () {} };

  var EFFECTS = [
    { id: "words", name: "Scroll word reveal", where: "Welcome statement", source: "Scroll Word Reveal · Motion" },
    { id: "ribbon", name: "Photo ribbon", where: "After the welcome", source: "Infinite Slider · ibelick" },
    { id: "roll", name: "Letter roll on hover", where: "Menu, buttons, links", source: "Text Roll · ibelick" },
    { id: "magnetic", name: "Magnetic buttons", where: "Booking buttons", source: "Motion Footer · Hossain Jahed" },
    { id: "spotlight", name: "Spotlight glow", where: "AquaBella card, halls, Štip", source: "Spotlight Card · Hossain Jahed" },
    { id: "ripples", name: "Water ripples", where: "AquaBella", source: "Shaders category" },
    { id: "blur", name: "Progressive blur header", where: "Header while scrolling", source: "Progressive Blur · ibelick" },
    { id: "curtain", name: "Curtain footer reveal", where: "Footer", source: "Motion Footer · Hossain Jahed" }
  ];

  function $$(sel, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(sel));
  }

  function clamp(n, min, max) {
    return Math.min(Math.max(n, min), max);
  }

  /* ---------- State: which effects are on ---------- */
  function readState() {
    var forced = new URLSearchParams(window.location.search).get("fx");
    var state = {};
    EFFECTS.forEach(function (fx) {
      state[fx.id] = forced !== "off";
    });
    if (forced === "on" || forced === "off") return state;
    try {
      var saved = JSON.parse(window.localStorage.getItem(STORE_KEY) || "{}");
      EFFECTS.forEach(function (fx) {
        if (typeof saved[fx.id] === "boolean") state[fx.id] = saved[fx.id];
      });
    } catch (e) {
      /* storage blocked or unreadable: every effect stays on */
    }
    return state;
  }

  function saveState(state) {
    try {
      window.localStorage.setItem(STORE_KEY, JSON.stringify(state));
      return true;
    } catch (e) {
      return false;
    }
  }

  /* ---------- 1. Scroll word reveal (intro statement) ---------- */
  function splitWords(el) {
    /* i18n may hand back our own markup as the "original", so unwrap first */
    $$(".fx-word", el).forEach(function (w) {
      w.replaceWith(document.createTextNode(w.textContent));
    });
    el.normalize();
    var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    var nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function (node) {
      var frag = document.createDocumentFragment();
      node.textContent.split(/(\s+)/).forEach(function (part) {
        if (!part) return;
        if (/^\s+$/.test(part)) {
          frag.appendChild(document.createTextNode(part));
          return;
        }
        var span = document.createElement("span");
        span.className = "fx-word";
        span.textContent = part;
        frag.appendChild(span);
      });
      node.parentNode.replaceChild(frag, node);
    });
    return $$(".fx-word", el);
  }

  function initWords() {
    var el = document.querySelector(".statement");
    if (!el || reduceMotion) return;
    var words = splitWords(el);
    var ticking = false;

    function update() {
      ticking = false;
      var r = el.getBoundingClientRect();
      var vh = window.innerHeight;
      if (r.bottom < -vh || r.top > vh * 2) return; /* far off-screen: leave the words as they are */
      /* starts when the paragraph enters the lower screen, done when its end reaches mid-screen */
      var p = clamp((vh * 0.88 - r.top) / (vh * 0.38 + r.height), 0, 1);
      var lit = p * words.length;
      words.forEach(function (w, i) {
        w.style.setProperty("--lit", clamp(lit - i, 0, 1).toFixed(3));
      });
    }

    function request() {
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(update);
      }
    }

    window.addEventListener("scroll", request, { passive: true });
    window.addEventListener("resize", request);
    i18n.onChange(function () {
      words = splitWords(el);
      update();
    });
    update();
  }

  /* ---------- 2. Photo ribbon ---------- */
  var RIBBON_TEXT = {
    en: { label: "Photos of the hotel", eyebrow: "Inside Oaza", hint: "Drag to explore", pause: "Pause", play: "Play" },
    mk: { label: "Фотографии од хотелот", eyebrow: "Во Оаза", hint: "Повлечете за да разгледате", pause: "Пауза", play: "Пушти" }
  };

  var PHOTOS = [
    { file: "exterior", sizes: [640, 960, 1440], shape: "wide", en: "The terracotta tower", mk: "Кулата во теракота" },
    { file: "room-large-bed", sizes: [640, 1000], shape: "arch", en: "Oak & linen", mk: "Даб и лен" },
    { file: "restaurant", sizes: [640, 1000], shape: "wide", en: "Restaurant Mal Odmor", mk: "Ресторан Мал одмор" },
    { file: "room-inset", sizes: [480, 960], shape: "square", en: "Apartment · oak & marble", mk: "Апартман · даб и мермер" },
    { file: "location", sizes: [600, 960], shape: "wide", en: "Above central Štip", mk: "Над центарот на Штип" },
    { file: "room-two-beds", sizes: [640, 1000], shape: "arch", en: "Two-bed room", mk: "Соба со два кревета" },
    { file: "room-interior", sizes: [640, 1000], shape: "wide", en: "Calm, natural tones", mk: "Мирни, природни тонови" }
  ];

  function ribbonItem(photo, index) {
    var li = document.createElement("li");
    li.className = "fx-ribbon__item fx-ribbon__item--" + photo.shape + (index % 2 ? " fx-ribbon__item--low" : "");
    var srcset = photo.sizes
      .map(function (w) {
        return BASE + "images/" + photo.file + "-" + w + ".webp " + w + "w";
      })
      .join(", ");
    var img = document.createElement("img");
    img.src = BASE + "images/" + photo.file + "-" + photo.sizes[0] + ".webp";
    img.srcset = srcset;
    img.sizes = "(max-width: 760px) 70vw, 560px";
    img.alt = "";
    img.loading = "lazy";
    img.decoding = "async";
    img.draggable = false;
    var frame = document.createElement("div");
    frame.className = "fx-ribbon__frame";
    frame.appendChild(img);
    var cap = document.createElement("p");
    cap.className = "fx-ribbon__cap";
    cap.innerHTML = "<b>" + String(index + 1).padStart(2, "0") + "</b><span></span>";
    li.appendChild(frame);
    li.appendChild(cap);
    return li;
  }

  function buildRibbon() {
    var section = document.createElement("section");
    section.className = "fx-ribbon";
    section.innerHTML =
      '<div class="wrap fx-ribbon__head">' +
      '<p class="eyebrow" data-fx-text="eyebrow"></p>' +
      '<div class="fx-ribbon__tools"><span class="fx-ribbon__hint" data-fx-text="hint"></span>' +
      '<button class="fx-ribbon__toggle" type="button" aria-pressed="false"><i aria-hidden="true"></i><span data-fx-text="pause"></span></button></div>' +
      "</div>" +
      '<div class="fx-ribbon__viewport" aria-hidden="true"><ul class="fx-ribbon__track"></ul></div>';
    var track = section.querySelector(".fx-ribbon__track");
    var copies = reduceMotion ? 1 : 2;
    for (var c = 0; c < copies; c++) {
      PHOTOS.forEach(function (photo, i) {
        track.appendChild(ribbonItem(photo, i));
      });
    }
    return section;
  }

  function labelRibbon(section, paused) {
    var lang = i18n.lang === "mk" ? "mk" : "en";
    var text = RIBBON_TEXT[lang];
    section.setAttribute("aria-label", text.label);
    $$("[data-fx-text]", section).forEach(function (el) {
      var key = el.getAttribute("data-fx-text");
      el.textContent = key === "pause" && paused ? text.play : text[key];
    });
    $$(".fx-ribbon__cap span", section).forEach(function (el, i) {
      el.textContent = PHOTOS[i % PHOTOS.length][lang];
    });
  }

  function initRibbon() {
    var intro = document.getElementById("about");
    if (!intro) return;
    var section = buildRibbon();
    intro.insertAdjacentElement("afterend", section);
    var toggle = section.querySelector(".fx-ribbon__toggle");
    var motion = reduceMotion ? null : runRibbon(section);
    if (!motion) {
      section.classList.add("is-static");
      toggle.hidden = true;
    }
    labelRibbon(section, false);
    i18n.onChange(function () {
      labelRibbon(section, motion ? motion.isPaused() : false);
    });
    toggle.addEventListener("click", function () {
      var paused = !motion.isPaused();
      motion.setPaused(paused);
      toggle.setAttribute("aria-pressed", String(paused));
      section.classList.toggle("is-paused", paused);
      labelRibbon(section, paused);
    });
  }

  function runRibbon(section) {
    var viewport = section.querySelector(".fx-ribbon__viewport");
    var track = section.querySelector(".fx-ribbon__track");
    var s = { x: 0, setW: 0, boost: 0, paused: false, hovered: false, visible: false, drag: null, last: 0, lastY: window.scrollY };
    var SPEED = 34; /* px per second */

    function measure() {
      s.setW = track.scrollWidth / 2;
    }

    function wrap() {
      if (!s.setW) return;
      while (s.x <= -s.setW) s.x += s.setW;
      while (s.x > 0) s.x -= s.setW;
    }

    function frame(t) {
      if (!s.visible) return;
      var dt = s.last ? Math.min((t - s.last) / 1000, 0.05) : 0;
      s.last = t;
      if (!s.drag && !s.paused) {
        s.x -= ((s.hovered ? 0 : SPEED) + s.boost) * dt;
      }
      s.boost *= Math.exp(-dt * 3.5);
      wrap();
      track.style.transform = "translate3d(" + s.x.toFixed(2) + "px,0,0)";
      window.requestAnimationFrame(frame);
    }

    new IntersectionObserver(function (entries) {
      var was = s.visible;
      s.visible = entries[0].isIntersecting;
      if (s.visible && !was) {
        s.last = 0;
        window.requestAnimationFrame(frame);
      }
    }).observe(section);

    window.addEventListener(
      "scroll",
      function () {
        var y = window.scrollY;
        if (s.visible && !s.paused) s.boost = clamp(s.boost + (y - s.lastY) * 4, -900, 900);
        s.lastY = y;
      },
      { passive: true }
    );
    window.addEventListener("resize", measure);
    viewport.addEventListener("pointerenter", function (e) {
      if (e.pointerType === "mouse") s.hovered = true;
    });
    viewport.addEventListener("pointerleave", function () {
      s.hovered = false;
    });
    bindRibbonDrag(viewport, s);
    measure();

    return {
      isPaused: function () {
        return s.paused;
      },
      setPaused: function (value) {
        s.paused = value;
        s.boost = 0;
      }
    };
  }

  function bindRibbonDrag(viewport, s) {
    function end(e) {
      if (!s.drag || e.pointerId !== s.drag.id) return;
      s.boost = s.paused ? 0 : -s.drag.v;
      s.drag = null;
      viewport.classList.remove("is-dragging");
    }
    viewport.addEventListener("pointerdown", function (e) {
      if (e.button !== 0) return;
      s.drag = { id: e.pointerId, x: e.clientX, t: performance.now(), v: 0 };
      viewport.setPointerCapture(e.pointerId);
      viewport.classList.add("is-dragging");
    });
    viewport.addEventListener("pointermove", function (e) {
      if (!s.drag || e.pointerId !== s.drag.id) return;
      var now = performance.now();
      var dx = e.clientX - s.drag.x;
      s.x += dx;
      s.drag.v = (dx / Math.max(now - s.drag.t, 1)) * 1000;
      s.drag.x = e.clientX;
      s.drag.t = now;
    });
    viewport.addEventListener("pointerup", end);
    viewport.addEventListener("pointercancel", end);
  }

  /* ---------- 3. Letter roll on hover ---------- */
  function graphemes(text) {
    if (window.Intl && Intl.Segmenter) {
      return Array.from(new Intl.Segmenter(undefined, { granularity: "grapheme" }).segment(text), function (s) {
        return s.segment;
      });
    }
    return Array.from(text);
  }

  function rollLine(chars, extra) {
    var line = document.createElement("span");
    line.className = "fx-roll__line" + (extra || "");
    chars.forEach(function (ch, i) {
      var span = document.createElement("span");
      span.className = "fx-ch";
      span.style.setProperty("--i", i);
      span.textContent = ch;
      line.appendChild(span);
    });
    return line;
  }

  function buildRoll(holder) {
    var sr = holder.querySelector(".fx-roll__sr");
    var label = (sr ? sr.textContent : holder.textContent).trim();
    if (!label) return;
    var chars = graphemes(label);
    var roll = document.createElement("span");
    roll.className = "fx-roll";
    roll.setAttribute("aria-hidden", "true");
    roll.appendChild(rollLine(chars));
    roll.appendChild(rollLine(chars, " fx-roll__line--b"));
    var hidden = document.createElement("span");
    hidden.className = "visually-hidden fx-roll__sr";
    hidden.textContent = label;
    holder.replaceChildren(roll, hidden);
  }

  function rollHolders() {
    var direct = $$(".nav__link, .header-cta");
    var nested = $$(".menu__link, .link-arrow, .btn:not(.header-cta)")
      .map(function (link) {
        return link.querySelector(":scope > span:not([class])") || link.querySelector(":scope > .fx-roll-holder");
      })
      .filter(Boolean);
    return direct.concat(nested);
  }

  function initRoll() {
    if (reduceMotion) return;
    function build() {
      rollHolders().forEach(function (holder) {
        holder.classList.add("fx-roll-holder");
        buildRoll(holder);
      });
    }
    build();
    i18n.onChange(build);
  }

  /* ---------- 4. Magnetic buttons ---------- */
  function initMagnetic() {
    if (!finePointer || reduceMotion) return;
    var buttons = $$(".header-cta, .hero__ctas .btn, .booking__submit, .form__foot .btn");
    var pointer = null;
    var ticking = false;
    buttons.forEach(function (btn) {
      btn.classList.add("fx-mag");
    });

    function update() {
      ticking = false;
      buttons.forEach(function (btn) {
        var r = btn.getBoundingClientRect();
        var dx = pointer ? pointer.x - (r.left + r.width / 2) : 0;
        var dy = pointer ? pointer.y - (r.top + r.height / 2) : 0;
        var reach = Math.max(r.width, r.height) * 0.5 + 56;
        var pull = Math.hypot(dx, dy) < reach ? 0.22 : 0;
        btn.style.setProperty("--mx", (dx * pull).toFixed(2) + "px");
        btn.style.setProperty("--my", (dy * pull * 1.3).toFixed(2) + "px");
      });
    }

    function request(e) {
      pointer = e ? { x: e.clientX, y: e.clientY } : null;
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(update);
      }
    }

    document.addEventListener("pointermove", request, { passive: true });
    document.documentElement.addEventListener("mouseleave", function () {
      request(null);
    });
  }

  /* ---------- 5. Spotlight glow ---------- */
  function initSpotlight() {
    if (!finePointer) return;
    $$(".aqua__card, .hall, .landmark").forEach(function (card) {
      card.classList.add("fx-spot");
      card.addEventListener("pointermove", function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty("--sx", (e.clientX - r.left).toFixed(0) + "px");
        card.style.setProperty("--sy", (e.clientY - r.top).toFixed(0) + "px");
      });
    });
  }

  /* ---------- 6. Water ripples (AquaBella) ---------- */
  function initRipples() {
    var section = document.querySelector(".aqua");
    var layer = section && section.querySelector(".aqua__water");
    if (!layer || reduceMotion) return;
    var last = { x: -1e4, y: -1e4, t: 0 };
    var live = 0;

    function drop(x, y, big) {
      if (live >= 14) return;
      var ring = document.createElement("span");
      ring.className = "fx-ripple" + (big ? " fx-ripple--big" : "");
      ring.style.left = x + "px";
      ring.style.top = y + "px";
      layer.appendChild(ring);
      live++;
      window.setTimeout(function () {
        ring.remove();
        live--;
      }, 2400);
    }

    function point(e) {
      var r = layer.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    section.addEventListener("pointermove", function (e) {
      if (e.pointerType !== "mouse") return;
      var p = point(e);
      var now = performance.now();
      if (now - last.t < 110 || Math.hypot(p.x - last.x, p.y - last.y) < 60) return;
      last = { x: p.x, y: p.y, t: now };
      drop(p.x, p.y, false);
    });
    section.addEventListener("pointerdown", function (e) {
      var p = point(e);
      drop(p.x, p.y, true);
    });
  }

  /* ---------- 7. Progressive blur under the header ---------- */
  function initBlur() {
    var header = document.getElementById("header");
    if (!header) return;
    var tail = document.createElement("div");
    tail.className = "fx-pblur";
    tail.setAttribute("aria-hidden", "true");
    for (var i = 0; i < 4; i++) tail.appendChild(document.createElement("i"));
    header.insertAdjacentElement("afterend", tail);
  }

  /* ---------- 8. Curtain footer reveal ---------- */
  function initCurtain() {
    var main = document.getElementById("main");
    var footer = document.querySelector(".site-footer");
    if (!main || !footer) return;
    var ticking = false;

    function update() {
      ticking = false;
      var shown = window.innerHeight - main.getBoundingClientRect().bottom;
      footer.style.setProperty("--fx-reveal", clamp(shown / footer.offsetHeight, 0, 1).toFixed(3));
    }

    window.addEventListener(
      "scroll",
      function () {
        if (!ticking) {
          ticking = true;
          window.requestAnimationFrame(update);
        }
      },
      { passive: true }
    );
    window.addEventListener("resize", update);
    update();
  }

  /* ---------- Review panel ---------- */
  function panelMarkup(state) {
    var on = EFFECTS.filter(function (fx) {
      return state[fx.id];
    }).length;
    var rows = EFFECTS.map(function (fx) {
      return (
        '<li><label class="fx-panel__row"><input type="checkbox" data-fx-id="' + fx.id + '"' + (state[fx.id] ? " checked" : "") + ">" +
        "<span><strong>" + fx.name + "</strong><small>" + fx.where + " · <em>" + fx.source + "</em></small></span></label></li>"
      );
    }).join("");
    return (
      '<button class="fx-panel__pill" type="button" aria-expanded="false" aria-controls="fx-panel-body">' +
      '<span aria-hidden="true">✦</span> New effects <b>' + on + "/" + EFFECTS.length + "</b></button>" +
      '<div class="fx-panel__body" id="fx-panel-body" hidden>' +
      '<p class="fx-panel__title">Effects preview</p>' +
      '<p class="fx-panel__sub">Ideas from the 21st.dev catalogue, rebuilt for this site. Untick anything you don\'t like.</p>' +
      '<ul class="fx-panel__list">' + rows + "</ul>" +
      '<div class="fx-panel__actions"><button type="button" data-fx-all="on">All on</button><button type="button" data-fx-all="off">All off</button></div>' +
      '<p class="fx-panel__note" role="status">Each change reloads the page.</p>' +
      "</div>"
    );
  }

  function applyChoice(next, note) {
    if (!saveState(next)) {
      note.textContent = "Your browser is blocking storage, so choices can't be saved. Add ?fx=off to the address instead.";
      return;
    }
    /* drop any ?fx= override so the saved choice wins; a hash-only change would not reload */
    if (window.location.search) {
      window.location.replace(window.location.pathname + window.location.hash);
    } else {
      window.location.reload();
    }
  }

  function buildPanel(state) {
    var panel = document.createElement("aside");
    panel.className = "fx-panel";
    panel.setAttribute("aria-label", "Effects preview");
    panel.innerHTML = panelMarkup(state);
    var pill = panel.querySelector(".fx-panel__pill");
    var body = panel.querySelector(".fx-panel__body");
    var note = panel.querySelector(".fx-panel__note");

    pill.addEventListener("click", function () {
      var open = pill.getAttribute("aria-expanded") !== "true";
      pill.setAttribute("aria-expanded", String(open));
      body.hidden = !open;
    });
    panel.addEventListener("change", function (e) {
      var id = e.target.getAttribute("data-fx-id");
      if (!id) return;
      var next = Object.assign({}, state);
      next[id] = e.target.checked;
      applyChoice(next, note);
    });
    $$("[data-fx-all]", panel).forEach(function (btn) {
      btn.addEventListener("click", function () {
        var value = btn.getAttribute("data-fx-all") === "on";
        var next = {};
        EFFECTS.forEach(function (fx) {
          next[fx.id] = value;
        });
        applyChoice(next, note);
      });
    });
    document.body.appendChild(panel);
  }

  /* ---------- Boot ---------- */
  var INIT = {
    words: initWords,
    ribbon: initRibbon,
    roll: initRoll,
    magnetic: initMagnetic,
    spotlight: initSpotlight,
    ripples: initRipples,
    blur: initBlur,
    curtain: initCurtain
  };

  function boot() {
    var state = readState();
    EFFECTS.forEach(function (fx) {
      if (!state[fx.id]) return;
      /* "fx-on-" prefix keeps these switches distinct from component classes like .fx-ribbon */
      root.classList.add("fx-on-" + fx.id);
      try {
        INIT[fx.id]();
      } catch (err) {
        /* one broken effect must never take the page down: drop its styles and carry on */
        root.classList.remove("fx-on-" + fx.id);
        if (window.console) window.console.warn("[oaza fx] " + fx.id + " disabled:", err);
      }
    });
    if (SHOW_PANEL) buildPanel(state);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
