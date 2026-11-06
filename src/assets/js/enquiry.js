/* Hotel Oaza — enquiry form: stay/event fields, prefill, checks, and sending to /api/enquiry.php. */
(function () {
  "use strict";

  var rules = window.oazaEnquiryRules;
  var i18n = window.oazaI18n;
  /* Fields that have an error slot under them. */
  var SLOTS = ["arrival", "departure", "company", "name", "email", "phone", "message"];

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function selectedType(form) {
    var checked = form.querySelector('input[name="type"]:checked');
    return checked ? checked.value : "stay";
  }

  /* Weddings and conferences ask for one date and a hall size; conferences add company and overnight stay. */
  function syncMode(form) {
    var type = selectedType(form);
    form.setAttribute("data-type", type);
    form.setAttribute("data-mode", rules.isEventType(type) ? "event" : "stay");
    if (rules.isEventType(type)) setError(form.elements.departure, "");
  }

  /* Also marks empty date inputs so their mm/dd/yyyy mask can be dimmed. */
  function updateNights(form) {
    [form.elements.arrival, form.elements.departure].forEach(function (input) {
      input.toggleAttribute("data-empty", !input.value);
    });
    var out = $("[data-nights]", form);
    var a = form.elements.arrival.value;
    var d = form.elements.departure.value;
    var n = a && d ? Math.round((Date.parse(d) - Date.parse(a)) / 864e5) : 0;
    if (out) out.textContent = n > 0 ? i18n.t(n === 1 ? "nights.one" : "nights.many", { n: n }) : "";
  }

  function setError(input, message) {
    var field = input.closest(".field");
    if (!field) return;
    field.classList.toggle("has-error", Boolean(message));
    input.setAttribute("aria-invalid", message ? "true" : "false");
    var slot = $(".field__error", field);
    if (slot) slot.textContent = message || "";
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

  function values(form) {
    var data = {};
    new FormData(form).forEach(function (value, key) {
      data[key] = String(value);
    });
    return data;
  }

  /* Write each slot's message (or clear it); returns the first field with an error. */
  function showErrors(form, errors, fromServer) {
    var first = null;
    SLOTS.forEach(function (name) {
      var code = errors[name];
      setError(form.elements[name], code ? i18n.t(fromServer ? "err.server" : "err." + code) : "");
      if (code && !first) first = form.elements[name];
    });
    return first;
  }

  function prefill(form) {
    if (!form.hasAttribute("data-prefill")) return;
    var p = rules.parsePrefill(window.location.search, rules.todayIso());
    if (p.type) {
      $$('input[name="type"]', form).forEach(function (radio) {
        radio.checked = radio.value === p.type;
      });
    }
    ["arrival", "departure", "guests", "room"].forEach(function (name) {
      if (p[name]) form.elements[name].value = p[name];
    });
  }

  function fillUtm(form) {
    var utm = rules.utmFrom(window.location.search);
    Object.keys(utm).forEach(function (key) {
      form.elements[key].value = utm[key];
    });
  }

  function showFail(ui, kind) {
    $(".form__fail-text", ui.fail).textContent = ui.fail.getAttribute("data-fail-" + kind);
    ui.fail.hidden = false;
  }

  function showDone(form, ui) {
    form.hidden = true;
    ui.done.hidden = false;
    ui.done.focus({ preventScroll: true });
    ui.done.scrollIntoView({ block: "start" });
  }

  function handleResult(form, ui, result) {
    var body = result.body;
    if (result.ok && body && body.success) {
      showDone(form, ui);
      return;
    }
    var error = body && body.error ? body.error : { code: "send_failed" };
    if (error.code === "invalid") {
      var errors = {};
      (error.fields || []).forEach(function (field) {
        errors[field] = "server";
      });
      var bad = showErrors(form, errors, true);
      ui.status.textContent = i18n.t("status.fix");
      if (bad) bad.focus();
      return;
    }
    /* Server error, rate limit, or no server at all (the GitHub preview): offer phone, Viber and WhatsApp. */
    showFail(ui, error.code === "rate_limited" ? "rate" : "generic");
  }

  function send(form, ui) {
    var button = $('button[type="submit"]', form);
    ui.fail.hidden = true;
    button.disabled = true;
    button.classList.add("is-sending");
    ui.status.textContent = i18n.t("status.sending");

    function finish(result) {
      button.disabled = false;
      button.classList.remove("is-sending");
      ui.status.textContent = "";
      handleResult(form, ui, result);
    }

    fetch(form.action, { method: "POST", body: new URLSearchParams(new FormData(form)), headers: { Accept: "application/json" } })
      .then(function (res) {
        return res.json().then(
          function (body) {
            return { ok: res.ok, body: body };
          },
          function () {
            return { ok: false, body: null };
          }
        );
      })
      .catch(function () {
        return { ok: false, body: null };
      })
      .then(finish);
  }

  function init() {
    var form = $("#enquiry");
    if (!form || !rules || !i18n) return;
    var ui = { status: $(".form__status", form), fail: $(".form__fail", form), done: $("#enquiry-done") };
    prefill(form);
    fillUtm(form);
    rules.linkDatePair(form.elements.arrival, form.elements.departure, rules.todayIso());
    linkErrors(form);
    syncMode(form);
    updateNights(form);

    form.addEventListener("change", function (e) {
      if (e.target.name === "type") syncMode(form);
      if (e.target.name === "arrival" || e.target.name === "departure") updateNights(form);
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var errors = rules.validate(values(form), rules.todayIso());
      var bad = showErrors(form, errors, false);
      if (Object.keys(errors).length) {
        ui.status.textContent = i18n.t("status.fix");
        if (bad) bad.focus();
        return;
      }
      send(form, ui);
    });

    /* Once an error is showing, re-check live. Arrival is included because changing it can fix a departure error. */
    function revalidate() {
      if ($('[aria-invalid="true"]', form)) showErrors(form, rules.validate(values(form), rules.todayIso()), false);
    }
    SLOTS.forEach(function (name) {
      form.elements[name].addEventListener("input", revalidate);
      form.elements[name].addEventListener("change", revalidate);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
