/* Hotel Oaza — enquiry rules: lists, limits, dates, prefill and campaign fields.
   api/lib/validate.php applies the same rules on the server. No dependencies. */
(function (root) {
  "use strict";

  var TYPES = ["stay", "wedding", "conference", "group"];
  var EVENT_TYPES = ["wedding", "conference"];
  var GUESTS = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "10+"];
  var EVENT_GUESTS = ["30", "150", "250"];
  var ROOMS = ["any", "single", "double", "twin", "apartment"];
  var OVERNIGHT = ["no", "yes"];
  var LIMITS = { name: 100, email: 200, phone: 40, company: 150, message: 2000 };
  var UTM_KEYS = ["utm_source", "utm_medium", "utm_campaign"];
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var ISO_RE = /^\d{4}-\d{2}-\d{2}$/;

  function isEventType(type) {
    return EVENT_TYPES.indexOf(type) > -1;
  }

  function isIsoDate(value) {
    if (!ISO_RE.test(value)) return false;
    var d = new Date(value + "T00:00:00Z");
    return !isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value;
  }

  function addDays(iso, n) {
    var d = new Date(iso + "T00:00:00Z");
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }

  /* Today in the visitor's time zone, as YYYY-MM-DD. */
  function todayIso() {
    var d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }

  /* Characters, not UTF-16 units, to match the server's count. */
  function count(value) {
    return Array.from(value || "").length;
  }

  /* ?type=…&arrival=…&departure=…&guests=…&room=… from the contact page URL; anything invalid is dropped. */
  function parsePrefill(search, today) {
    var q = new URLSearchParams(search);
    var out = {};
    var type = q.get("type");
    var arrival = q.get("arrival");
    var departure = q.get("departure");
    if (TYPES.indexOf(type) > -1) out.type = type;
    if (arrival && isIsoDate(arrival) && arrival >= today) out.arrival = arrival;
    if (departure && isIsoDate(departure) && departure > (out.arrival || today)) out.departure = departure;
    if (GUESTS.indexOf(q.get("guests")) > -1) out.guests = q.get("guests");
    if (ROOMS.indexOf(q.get("room")) > -1) out.room = q.get("room");
    return out;
  }

  function utmFrom(search) {
    var q = new URLSearchParams(search);
    var out = {};
    UTM_KEYS.forEach(function (key) {
      var value = (q.get(key) || "").trim();
      if (value) out[key] = Array.from(value).slice(0, 100).join("");
    });
    return out;
  }

  function checkText(errors, values, field, required) {
    var value = (values[field] || "").trim();
    if (!value) {
      if (required) errors[field] = "required";
    } else if (count(value) > LIMITS[field]) {
      errors[field] = "long";
    }
  }

  function checkChoice(errors, values, field, list) {
    if (list.indexOf(values[field]) < 0) errors[field] = "choice";
  }

  /* Errors as { field: "required" | "email" | "long" | "date" | "dates" | "choice" }; empty when valid. */
  function validate(values, today) {
    var errors = {};
    var event = isEventType(values.type);
    checkChoice(errors, values, "type", TYPES);
    checkText(errors, values, "name", true);
    checkText(errors, values, "email", true);
    if (!errors.email && !EMAIL_RE.test(values.email.trim())) errors.email = "email";
    checkText(errors, values, "phone", false);
    checkText(errors, values, "message", false);
    if (values.type === "conference") {
      checkText(errors, values, "company", false);
      checkChoice(errors, values, "overnight", OVERNIGHT);
    }
    if (values.arrival && (!isIsoDate(values.arrival) || values.arrival < today)) errors.arrival = "date";
    if (event) {
      checkChoice(errors, values, "eventGuests", EVENT_GUESTS);
      return errors;
    }
    if (values.departure) {
      if (!isIsoDate(values.departure) || values.departure < today) errors.departure = "date";
      else if (values.arrival && !errors.arrival && values.departure <= values.arrival) errors.departure = "dates";
    }
    checkChoice(errors, values, "guests", GUESTS);
    checkChoice(errors, values, "room", ROOMS);
    return errors;
  }

  /* Arrival from today, departure at least a night later and moved along when arrival changes. */
  function linkDatePair(arrival, departure, today) {
    arrival.min = today;
    departure.min = addDays(today, 1);
    arrival.addEventListener("change", function () {
      if (!isIsoDate(arrival.value)) return;
      var minDep = addDays(arrival.value, 1);
      departure.min = minDep;
      if (!departure.value || departure.value < minDep) departure.value = minDep;
    });
  }

  root.oazaEnquiryRules = {
    TYPES: TYPES,
    EVENT_TYPES: EVENT_TYPES,
    GUESTS: GUESTS,
    EVENT_GUESTS: EVENT_GUESTS,
    ROOMS: ROOMS,
    OVERNIGHT: OVERNIGHT,
    LIMITS: LIMITS,
    isEventType: isEventType,
    isIsoDate: isIsoDate,
    addDays: addDays,
    todayIso: todayIso,
    parsePrefill: parsePrefill,
    utmFrom: utmFrom,
    validate: validate,
    linkDatePair: linkDatePair
  };
})(window);
