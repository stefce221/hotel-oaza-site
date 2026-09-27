/* Hotel Oaza — bilingual layer (EN in the HTML, MK here).
   Elements opt in with data-i18n="key" (inner HTML) and
   data-i18n-attr="attr:key,attr2:key2" (attributes). */
(function () {
  "use strict";

  var STORAGE_KEY = "oaza-lang";
  var SUPPORTED = ["en", "mk"];

  var MK = {
    "meta.title": "Хотел Оаза — хотел со четири ѕвезди во срцето на Штип",
    "meta.description": "Хотел Оаза е хотел со четири ѕвезди во центарот на Штип: 57 соби и апартмани, ресторан Мал одмор, три сали за до 250 гости и летниот комплекс AquaBella.",

    "skip": "Прескокни до содржината",
    "brand.home": "Хотел Оаза — почетна",
    "nav.label": "Главна навигација",
    "nav.rooms": "Соби",
    "nav.dining": "Ресторан",
    "nav.events": "Настани",
    "nav.stip": "Штип",
    "nav.contact": "Контакт",
    "nav.book": "Резервирај",
    "lang.label": "Јазик",
    "menu.open": "Отвори мени",
    "menu.close": "Затвори мени",
    "menu.reception": "Рецепцијата е отворена 24 часа, на македонски и англиски.",

    "hero.eyebrow": "Штип · Северна Македонија",
    "hero.stars": "Хотел со четири ѕвезди",
    "hero.title": '<span class="line"><span><em>Оаза</em></span></span><span class="line"><span>во срцето</span></span><span class="line"><span>на Штип</span></span>',
    "hero.lede": "Мирни соби во даб и лен, ресторан под сводови од тула и сали за до 250 гости — сè во препознатливата кула со фасада од теракота, во центарот на градот.",
    "hero.cta": "Проверете достапност",
    "hero.cta2": "Погледнете ги собите",
    "hero.alt": "Кулата на Хотел Оаза во Штип: модернистичка зграда со бели столбови и фасада од фасетирани панели во боја на теракота, со снежни ридови во позадина",
    "hero.insetAlt": "Внатрешност на апартман со маса од даб, тркалезно огледало, фотелја во крем боја и ѕид од црн мермер",
    "hero.inset": "Апартман · даб, мермер и лен",
    "hero.badge": "Хотел Оаза · Штип · Македонија ·",

    "booking.arrival": "Пристигнување",
    "booking.departure": "Заминување",
    "booking.guests": "Гости",
    "booking.note": "Испратете ни ги датумите — ќе ви одговориме со достапност и цени.",

    "intro.eyebrow": "Добредојдовте во Оаза",
    "intro.statement": "Името кажува сè: <em>оаза</em> — мирно и уредно засолниште среде Штип, на неколку минути од стариот дел на градот, галеријата Безистен и патеката кон тврдината Исар.",
    "intro.side": "Зад фасадата од теракота ве чека хотел воден со вистинско македонско гостопримство: рецепција која никогаш не затвора, кујна која готви онака како што се јаде во овој крај и тим кој со задоволство ќе ви помогне на македонски или англиски јазик.",
    "stat.rooms": "Соби и апартмани",
    "stat.halls": "Сали за настани",
    "stat.guests": "Гости во најголемата сала",
    "stat.reception": "Рецепција, на македонски и англиски",
    "stat.aqua": "Летен комплекс AquaBella",

    "rooms.eyebrow": "Соби и апартмани",
    "rooms.title": "Одмор, <em>едноставно</em> убав",
    "rooms.lede": "Педесет и седум соби во смирени, природни тонови — секоја со клима-уред, вистинска работна маса, сателитска телевизија и бесплатен Wi-Fi.",
    "rooms.tabs": "Видови соби",
    "room.cta": "Прашајте за оваа соба",

    "room.single.name": "Еднокреветна соба",
    "room.single.count": "33 соби",
    "room.single.meta": "1 гостин · 33 соби",
    "room.single.desc": "Мирна и добро осмислена соба за еден гостин — создадена за деловни патувања, со вистинска работна маса, добро осветлување и удобен кревет.",
    "room.single.features": "<li>Клима-уред</li><li>Работна маса</li><li>Сателитска ТВ</li><li>Бесплатен Wi-Fi</li><li>Сопствено купатило</li>",
    "room.single.alt": "Соба со маса од даб и тркалезно огледало, плакар со стаклени врати, фотелја покрај прозорецот и ѕид за ТВ од црн мермер",

    "room.double.name": "Соба со брачен кревет",
    "room.double.count": "9 соби",
    "room.double.meta": "2 гости · брачен кревет",
    "room.double.desc": "Широк брачен кревет со тапацирано узглавје и топол даб, со мека дневна светлина од широкиот прозорец.",
    "room.double.features": "<li>Брачен кревет</li><li>Клима-уред</li><li>Работна маса</li><li>Сателитска ТВ</li><li>Бесплатен Wi-Fi</li><li>Сопствено купатило</li>",
    "room.double.alt": "Соба со голем брачен кревет, сиво тапацирано узглавје со панели од даб, бела постелнина и завеси во сиво-кафена боја",

    "room.twin.name": "Соба со два кревета",
    "room.twin.count": "12 соби",
    "room.twin.meta": "2 гости · два одвоени кревети",
    "room.twin.desc": "Два одвоени кревети за колеги, пријатели или семејство, со доволно простор за распакување и огледало во цела висина.",
    "room.twin.features": "<li>Два одвоени кревети</li><li>Клима-уред</li><li>Работна маса</li><li>Сателитска ТВ</li><li>Бесплатен Wi-Fi</li><li>Сопствено купатило</li>",
    "room.twin.alt": "Соба со два одвоени кревети на зелени бази, маса од даб со ТВ, тркалезно огледало и високо лачно огледало",

    "room.apartment.name": "Апартман",
    "room.apartment.count": "3 апартмани · 30 m²",
    "room.apartment.meta": "30 m² · работен простор и минибар",
    "room.apartment.desc": "Нашиот најпростран избор: триесет квадратни метри со посебен работен простор, минибар и фотелја покрај прозорецот.",
    "room.apartment.features": "<li>30 m²</li><li>Работен простор</li><li>Минибар</li><li>Фотелја и масичка</li><li>Сателитска ТВ</li><li>Бесплатен Wi-Fi</li>",
    "room.apartment.alt": "Апартман со голем кревет, маса од даб и тркалезно огледало, фотелја во крем боја покрај прозорецот и ѕид од црн мермер",

    "form.date": "Датум на настанот",
    "form.eg.30": "До 30 гости",
    "form.eg.150": "До 150 гости",
    "form.eg.250": "До 250 гости",
    "hanger.title": "Добро е да знаете",
    "hanger.in": "Пријавување",
    "hanger.out": "Одјавување",
    "hanger.rec": "Рецепција",
    "hanger.note": "На македонски и англиски",
    "amen.label": "Во секоја соба",
    "amen.ac": "Клима-уред",
    "amen.wifi": "Бесплатен Wi-Fi",
    "amen.tv": "Сателитска ТВ",
    "amen.desk": "Работна маса",
    "amen.bath": "Сопствено купатило",
    "amen.reception": "Рецепција 24 часа",
    "amen.parking": "Паркинг",

    "dining.eyebrow": "Ресторан Мал одмор",
    "dining.title": "Бавни оброци <em>под сводови од тула</em>",
    "dining.body": "Сводови од тула, дрвени греди и витражни прозорци се сцената за традиционални македонски јадења и европски класици. Дојдете на долг ручек, семејна вечера или опуштена вечер со пријатели.",
    "dining.alt": "Ресторан Мал одмор: сводови од тула, темни дрвени греди, бели чаршафи и лачни витражни прозорци",
    "dining.motto": "— мотото на нашиот ресторан",
    "dining.l1.t": "Кујна",
    "dining.l1.d": "Традиционална и европска",
    "dining.l2.t": "Лоби бар",
    "dining.l2.d": "Кафе, аперитиви и вечерни пијалаци",
    "dining.l3.t": "Банкети",
    "dining.l3.d": "Голема сала за до 250 гости",
    "dining.l4.t": "Приватни прослави",
    "dining.l4.d": "Мала сала за до 30 гости",
    "dining.cta": "Резервирајте маса",

    "events.eyebrow": "Свадби · Прослави · Конференции",
    "events.title": "Три сали, <em>за секоја пригода</em>",
    "events.lede": "Од семеен ручек до свадба со 250 гости или целодневен семинар — нашиот тим се грижи за менито, распоредот, опремата и за сместувањето на гостите што доаѓаат од подалеку.",
    "events.note": "Илустративна фотографија",
    "events.wedAlt": "Тркалезна свечена маса со бел чаршаф, бели хортензии и рози, свеќи и чаши",
    "events.semAlt": "Поставка за семинар со долга бела маса, блокчиња, бокали со вода и тапацирани столици покрај прозорци со завеси",
    "events.wed.t": "Свадби и прослави",
    "events.wed.d": "Свршувачки, свадби, крштевки и родендени — со мени прилагодено на вашиот ден и тим кој знае како се прави вистинско македонско веселие.",
    "events.sem.t": "Конференции и семинари",
    "events.sem.d": "Проектор, озвучување и безжичен интернет за обуки и семинари — со кафе-паузи, ручеци и ноќевања организирани на едно место.",
    "events.wed.k": "Прослави",
    "events.wed.tags": "<li>Свршувачки</li><li>Свадби</li><li>Крштевки</li><li>Родендени</li>",
    "events.sem.k": "Деловни настани",
    "events.sem.tags": "<li>Проектор</li><li>Озвучување</li><li>Безжичен интернет</li><li>Кафе-паузи</li>",
    "halls.title": "Нашите сали",
    "halls.sub": "Капацитетот зависи од распоредот — ќе ви предложиме најдобро поставување за вашиот број гости.",
    "halls.guests": "гости",
    "hall1.name": "Мала сала",
    "hall1.uses": "Родендени, крштевки, приватни вечери и состаноци.",
    "hall2.name": "Сала за семинари",
    "hall2.uses": "Семинари, обуки и презентации, со аудио-визуелна опрема и безжичен интернет.",
    "hall3.name": "Голема сала",
    "hall3.uses": "Свадби, гала вечери и конференции.",
    "events.cta": "Испланирајте го настанот",

    "aqua.eyebrow": "AquaBella · летна сезона",
    "aqua.title": "Летни денови <em>покрај базен</em>",
    "aqua.body": "AquaBella е нашиот воден и спортски комплекс од 22.000 m²: три базени — со тобогани и посебен базен за деца — терени за тенис, кошарка и одбојка на песок, и летен бар за ладни пијалаци меѓу две пливања.",
    "aqua.card": "На прв поглед",
    "aqua.f1": "Воден и спортски комплекс",
    "aqua.f2": "Базени, вклучувајќи детски",
    "aqua.f3": "Тениски терени",
    "aqua.f4": "Кошарка и одбојка на песок",
    "aqua.small": "Отворено во летната сезона. Спортските терени се бесплатни за посетителите на комплексот, од 10 до 18 часот. Цела година во хотелот: сауна и фитнес.",

    "stip.eyebrow": "Градот",
    "stip.title": "Штип, <em>пешки</em>",
    "stip.body": "Еден од најстарите градови во Македонија, Штип се шири околу ридот на тврдината Исар. Од нашата врата, стариот дел на градот, галериите, кафулињата и универзитетот се на дофат — а ве чека и пастрмајлијата, најпознатото штипско јадење.",
    "stip.alt": "Кулата на Хотел Оаза над центарот на Штип, со куќи со црвени покриви на ридовите позади",
    "stip.isar": "Тврдина Исар",
    "stip.river": "Брегалница",
    "stip.greet": "Поздрав од Штип",
    "stip.postmark": "Штип · Северна Македонија ·",
    "lm1.t": "Тврдина Исар",
    "lm1.d": "Средновековна тврдина на ридот над градот, со широк поглед кон долината на Брегалница.",
    "lm2.t": "Безистен",
    "lm2.d": "Покриен пазар од османлискиот период, денес градска уметничка галерија.",
    "lm3.t": "Ново Село",
    "lm3.d": "Стариот дел на Штип, со куќи и цркви од XIX век.",
    "lm4.t": "Бања Кежовица",
    "lm4.d": "Термоминерални извори и бања на работ од градот.",
    "lm5.t": "Пастрмајлија",
    "lm5.d": "Штипскиот специјалитет — издолжено тесто со сушено месо, кое секоја година добива свој фестивал.",
    "map.pin": "Хотел Оаза · Тошо Арсов 32",
    "map.load": "Прикажи интерактивна мапа",
    "map.note": "Се вчитува Google Maps",
    "map.title": "Мапа со локацијата на Хотел Оаза во Штип",
    "get.title": "Како да стигнете",
    "get.addr.t": "Адреса",
    "get.addr.d": "ул. Тошо Арсов бр. 32, 2000 Штип, Северна Македонија",
    "get.air.t": "Од аеродромот Скопје",
    "get.air.d": "Околу еден час со автомобил — трансфер по барање",
    "get.park.t": "Паркинг",
    "get.park.d": "Паркинг за гостите на хотелот",
    "get.dir": "Упатства за патот",

    "contact.eyebrow": "Прашања и резервации",
    "contact.title": "Испланирајте го <em>престојот</em>",
    "contact.lede": "Кажете ни ги датумите и што ви е потребно. Ќе ви одговориме со достапност и цени.",
    "contact.rec.t": "Рецепција",
    "contact.rec.d": "Отворена 24 часа · македонски и англиски",
    "contact.tel": "Телефон",
    "contact.mail": "Е-пошта",
    "contact.times.t": "Пријавување и одјавување",
    "contact.times.d": "Од 14:00 · до 12:00",

    "form.title": "Вашето барање",
    "form.type": "Ме интересира",
    "form.type.stay": "Сместување",
    "form.type.wedding": "Свадба или прослава",
    "form.type.conf": "Конференција или семинар",
    "form.type.group": "Групна резервација",
    "form.name": "Име и презиме",
    "form.email": "Е-пошта",
    "form.phone": "Телефон",
    "form.optional": "(незадолжително)",
    "form.room": "Тип на соба",
    "form.room.any": "Без предност",
    "form.message": "Порака",
    "form.message.ph": "Сè што треба да знаеме — повод, време на пристигнување, посебни барања.",
    "form.hint": 'Се испраќа од вашата апликација за е-пошта, со сè веќе пополнето. Повеќе сакате да разговараме? Јавете се на <a href="tel:+38932390899">+389 32 390 899</a>.',
    "form.submit": "Испрати барање",

    "footer.lead": "Оаза во <em>срцето на Штип</em>.",
    "footer.visit": "Посета",
    "footer.contact": "Контакт",
    "footer.explore": "Истражете",
    "footer.addr1": "ул. Тошо Арсов бр. 32",
    "footer.addr2": "2000 Штип, Северна Македонија",
    "footer.rights": "© 2026 Хотел Оаза, Штип. Сите права се задржани.",
    "footer.top": "Назад горе ↑",

    "bar.label": "Брзи акции",
    "bar.call": "Јави се",
    "bar.enquire": "Резервирај"
  };

  /* Strings used only by scripts (both languages). */
  var RUNTIME = {
    en: {
      "guest.one": "{n} guest",
      "guest.many": "{n} guests",
      "guest.more": "{n} guests",
      "err.required": "Please fill in this field.",
      "err.email": "Please enter a valid email address.",
      "err.dates": "Departure must be after arrival.",
      "status.sent": "Your email app should open now. If it doesn't, write to us at info@oazahotel.com.mk.",
      "status.fix": "Please check the highlighted fields.",
      "mail.subject": "Enquiry — {type} — {name}",
      "mail.greeting": "Hello Hotel Oaza,",
      "mail.intro": "I would like to enquire about the following:",
      "mail.type": "Interested in",
      "mail.room": "Room type",
      "mail.arrival": "Arrival",
      "mail.departure": "Departure",
      "mail.date": "Event date",
      "nights.one": "{n} night",
      "nights.many": "{n} nights",
      "mail.guests": "Guests",
      "mail.name": "Name",
      "mail.email": "Email",
      "mail.phone": "Phone",
      "mail.message": "Message",
      "mail.none": "—",
      "type.stay": "A stay",
      "type.wedding": "Wedding or celebration",
      "type.conference": "Conference or seminar",
      "type.group": "Group booking",
      "menu.open": "Open menu",
      "menu.close": "Close menu",
      "map.title": "Map showing Hotel Oaza in Štip"
    },
    mk: {
      "guest.one": "{n} гостин",
      "guest.many": "{n} гости",
      "guest.more": "{n} гости",
      "err.required": "Ве молиме пополнете го ова поле.",
      "err.email": "Внесете валидна е-адреса.",
      "err.dates": "Заминувањето мора да биде по пристигнувањето.",
      "status.sent": "Сега треба да се отвори вашата апликација за е-пошта. Ако не се отвори, пишете ни на info@oazahotel.com.mk.",
      "status.fix": "Ве молиме проверете ги означените полиња.",
      "mail.subject": "Барање — {type} — {name}",
      "mail.greeting": "Почитувани од Хотел Оаза,",
      "mail.intro": "Би сакал/а да се информирам за следново:",
      "mail.type": "Ме интересира",
      "mail.room": "Тип на соба",
      "mail.arrival": "Пристигнување",
      "mail.departure": "Заминување",
      "mail.date": "Датум на настанот",
      "nights.one": "{n} ноќ",
      "nights.many": "{n} ноќи",
      "mail.guests": "Гости",
      "mail.name": "Име",
      "mail.email": "Е-пошта",
      "mail.phone": "Телефон",
      "mail.message": "Порака",
      "mail.none": "—",
      "type.stay": "Сместување",
      "type.wedding": "Свадба или прослава",
      "type.conference": "Конференција или семинар",
      "type.group": "Групна резервација",
      "menu.open": MK["menu.open"],
      "menu.close": MK["menu.close"],
      "map.title": MK["map.title"]
    }
  };

  var originals = new WeakMap();
  var originalAttrs = new WeakMap();
  var listeners = [];
  var current = "en";
  var defaultTitle = document.title;
  var metaDesc = document.querySelector('meta[name="description"]');
  var defaultDesc = metaDesc ? metaDesc.getAttribute("content") : "";

  function setContent(el, value) {
    if (el instanceof SVGElement) {
      el.textContent = value;
    } else {
      el.innerHTML = value;
    }
  }

  function applyText(lang) {
    document.querySelectorAll("[data-i18n]").forEach(function (el) {
      var key = el.getAttribute("data-i18n");
      if (!originals.has(el)) {
        originals.set(el, el instanceof SVGElement ? el.textContent : el.innerHTML);
      }
      var value = lang === "mk" ? MK[key] : originals.get(el);
      if (typeof value === "string") {
        setContent(el, value);
      }
    });
  }

  function applyAttrs(lang) {
    document.querySelectorAll("[data-i18n-attr]").forEach(function (el) {
      var pairs = el.getAttribute("data-i18n-attr").split(",");
      var stored = originalAttrs.get(el) || {};
      pairs.forEach(function (pair) {
        var parts = pair.split(":");
        var attr = parts[0].trim();
        var key = (parts[1] || "").trim();
        if (!(attr in stored)) {
          stored[attr] = el.getAttribute(attr);
        }
        var value = lang === "mk" ? MK[key] : stored[attr];
        if (typeof value === "string") {
          el.setAttribute(attr, value);
        }
      });
      originalAttrs.set(el, stored);
    });
  }

  function t(key, vars) {
    var table = RUNTIME[current] || RUNTIME.en;
    var str = table[key] || RUNTIME.en[key] || key;
    if (vars) {
      Object.keys(vars).forEach(function (k) {
        str = str.split("{" + k + "}").join(vars[k]);
      });
    }
    return str;
  }

  function relabelGuestSelects() {
    document.querySelectorAll("select[data-guests]").forEach(function (select) {
      Array.prototype.forEach.call(select.options, function (opt) {
        var raw = opt.value;
        var n = parseInt(raw, 10);
        var key = raw.indexOf("+") > -1 ? "guest.more" : n === 1 ? "guest.one" : "guest.many";
        opt.textContent = t(key, { n: raw });
      });
    });
  }

  function persist(lang) {
    try {
      window.localStorage.setItem(STORAGE_KEY, lang);
    } catch (e) {
      /* storage unavailable (private mode) — language still applies for this visit */
    }
  }

  function readStored() {
    try {
      return window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  }

  function setLang(lang, opts) {
    if (SUPPORTED.indexOf(lang) === -1) {
      lang = "en";
    }
    current = lang;
    document.documentElement.lang = lang;
    document.title = lang === "mk" ? MK["meta.title"] : defaultTitle;
    if (metaDesc) {
      metaDesc.setAttribute("content", lang === "mk" ? MK["meta.description"] : defaultDesc);
    }
    applyText(lang);
    applyAttrs(lang);
    relabelGuestSelects();

    document.querySelectorAll("[data-lang]").forEach(function (btn) {
      btn.setAttribute("aria-pressed", String(btn.getAttribute("data-lang") === lang));
    });

    if (!opts || opts.persist !== false) {
      persist(lang);
    }
    listeners.forEach(function (fn) {
      fn(lang);
    });
  }

  function initialLang() {
    var stored = readStored();
    if (stored && SUPPORTED.indexOf(stored) > -1) {
      return stored;
    }
    var nav = (navigator.language || "en").toLowerCase();
    return nav.indexOf("mk") === 0 ? "mk" : "en";
  }

  window.oazaI18n = {
    t: t,
    get lang() {
      return current;
    },
    set: setLang,
    onChange: function (fn) {
      listeners.push(fn);
    }
  };

  function init() {
    document.querySelectorAll("[data-lang]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        setLang(btn.getAttribute("data-lang"));
      });
    });
    var lang = initialLang();
    if (lang !== "en") {
      setLang(lang, { persist: false });
    } else {
      relabelGuestSelects();
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
