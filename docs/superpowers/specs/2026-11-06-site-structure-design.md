# Hotel Oaza website: multi-page structure (design)

Date: 2026-11-06
Status: approved section by section in brainstorming; waiting for the written review

## 1. Goal and scope

Today the site is one page (`index.html`) whose text switches between EN and MK with a script. This project turns it into a bilingual multi-page site that:

- **keeps the current look exactly**: the same CSS, fonts, frames and effects;
- **gives weddings and conferences their own pages**, each able to serve as an ad landing page;
- **adds Viber and WhatsApp** for +389 70 310 300;
- **adds the policy pages** a card-payment site needs;
- **sends enquiries through a server script on MKhost**, replacing the email-app (`mailto:`) link.

**Out of scope.** Each item below gets its own spec or decision later:

- **cPay card payments.** These have their own spec once CaSys confirms the new registration (www.oazahotel.com.mk, IP 176.9.126.180). This design only reserves the places cPay will need (section 7).
- **The effects decision.** Choosing which effects stay is separate; this project only hides the review panel on the live build.
- **New photography.**
- **The launch itself** (pointing domains, replacing the old PHP site). It happens only on the owner's explicit go; section 9 lists the steps.

## 2. Pages and addresses

Macedonian is the main language and lives at the root; English lives under `/en/`. The site has 11 pages in 2 languages, plus one bilingual 404, for 23 HTML files in total.

| Key | Page | MK | EN | Content |
|---|---|---|---|---|
| home | Home | `/` | `/en/` | Today's hero (with the availability form) and intro, unchanged. Then one teaser per page, in today's order: rooms, restaurant, events (the two existing event cards, now linking to weddings and conferences), AquaBella, Štip. A teaser is a shortened version of today's section: eyebrow, heading, lede, one image and a link. The full enquiry form moves to Contact, so Home ends with the Štip teaser and the footer. |
| rooms | Rooms | `/sobi/` | `/en/rooms/` | Today's rooms section in full: 4 room types, amenities, check-in card |
| weddings | Weddings & celebrations | `/svadbi/` | `/en/weddings/` | Landing page (section 3) |
| conferences | Conferences & seminars | `/seminari/` | `/en/conferences/` | Landing page (section 3) |
| restaurant | Restaurant Mal Odmor | `/restoran/` | `/en/restaurant/` | Today's dining section. Opening hours and menu highlights are added as an optional block once the hotel supplies them. |
| aquabella | AquaBella | `/aquabella/` | `/en/aquabella/` | Today's AquaBella section |
| stip | Štip | `/shtip/` | `/en/stip/` | Today's location section: postcard, landmarks, map, getting here |
| contact | Contact | `/kontakt/` | `/en/contact/` | Today's contact section (details and the enquiry form) plus the getting-here box. Viber and WhatsApp are added to the details. |
| terms | Terms & cancellation | `/uslovi/` | `/en/terms/` | Booking, deposit and cancellation rules (section 8) |
| privacy | Privacy | `/privatnost/` | `/en/privacy/` | Personal-data policy (section 8) |
| payments | Card payments | `/plakjanje/` | `/en/payments/` | How card payment works (section 8). The cPay form is added later. |
| 404 | Not found | `/404.html` | (same file) | A friendly message in MK, then EN, with links to Home, Rooms and Contact |

**Navigation**

- **Header menu:** Rooms · Weddings · Conferences · Restaurant · AquaBella · Štip · Contact. The current page carries `aria-current="page"`.
- **Book a stay** opens `/kontakt/` (or `/en/contact/`) with "A stay" preselected.
- **EN/MK** is a link to the same page in the other language, not a text swap.
- **Language links for Google:** every page carries `<link rel="alternate" hreflang="mk|en|x-default">`, with `x-default` set to MK.
- **No automatic redirect by browser language.** Ads link directly to the right language.

## 3. Weddings and conferences landing pages

Each page works on its own: a visitor arriving from an ad finds everything, including the form, without visiting the home page.

| # | Weddings | Conferences |
|---|---|---|
| 1 Hero | Large photo; H1 "Weddings & celebrations in Štip"; buttons: Request a date (jumps to the form) · Viber · WhatsApp | Seminar-hall photo; H1 "Conferences & seminars in Štip"; buttons: Request an offer · Viber · WhatsApp |
| 2 | Occasions: engagements, weddings, christenings, birthdays | Equipment: projector, sound, Wi-Fi, coffee breaks |
| 3 Halls | Halls block, Grand hall (250) first | Halls block, Seminar hall (80–150) first |
| 4 | What we take care of: menu, layout, equipment, rooms upstairs for guests travelling in | Packages: a day package (hall, coffee breaks, lunch) and one with an overnight stay. Each shows "price on request" until the hotel supplies prices. |
| 5 | How it works: send the date and guest count → we call back and you visit → you confirm → your day | Getting here: address, about an hour from Skopje Airport, on-site parking, link to the Štip page |
| 6 | Gallery of real weddings (optional block) | Rooms for participants, with a link to Rooms |
| 7 FAQ | Deposit and cancellation (both answered by linking to Terms), parking, guest rooms | Invoices for companies, cancellation (linking to Terms), parking |
| 8 Form | Shared enquiry form, "Wedding or celebration" preselected | Shared enquiry form, "Conference or seminar" preselected |

**Facts and photos**

- **Only facts already on today's site are used.** Hall capacities are 20–30, 80–150 and 250 guests. The equipment is projector, sound and Wi-Fi. Parking is on site, and Skopje Airport is about an hour away.
- **Two kinds of missing content are handled differently:**
  - Optional blocks (the wedding gallery, a "what's included" list of extras, a "from … per person" price, package prices, restaurant hours and menu) are left out of the page until the hotel supplies them. Package prices fall back to "price on request".
  - Required facts are hotel markers that block the live build (section 8). On these pages that is only the confirmation that companies get invoices.
- **Photos.** Until real ones exist, the two current "Illustrative styling" images stay, with their caption.

**SEO.** Both pages get their own title, description and share image (Open Graph) in each language.

## 4. Shared components

### Contact facts (one data file)

| Item | Value |
|---|---|
| Phone | `tel:+38970310300`, shown as +389 70 310 300 |
| Viber | `viber://chat?number=%2B38970310300` |
| WhatsApp | `https://wa.me/38970310300?text=<message for the page>` |
| Email | info@oazahotel.com.mk |
| Address | Tošo Arsov 32, 2000 Štip |
| Operator (MK) | МБС Угостителство и трговија ДООЕЛ Штип · ЕДБ 4029018520675 |
| Operator (EN) | MBS Ugostitelstvo i trgovija DOOEL Štip · Tax no. 4029018520675 |

### WhatsApp ready messages

| Pages | MK | EN |
|---|---|---|
| weddings | Здраво, ве контактирам за свадба или прослава. | Hello, I'm getting in touch about a wedding or celebration. |
| conferences | Здраво, ве контактирам за семинар или конференција. | Hello, I'm getting in touch about a seminar or conference. |
| home, rooms | Здраво, би сакал/а да резервирам соба. | Hello, I'd like to book a room. |
| all others | Здраво, имам прашање за Хотел Оаза. | Hello, I have a question for Hotel Oaza. |

### Header

- Today's header, unchanged in look, with page links instead of anchors.
- Below the width where seven items, the language switch and **Book a stay** fit, the menu collapses into today's full-screen menu, numbered 01–07. The exact breakpoint is set during the build by measuring the MK labels.

### Footer

- Today's footer and columns.
- The Contact column adds "Viber / WhatsApp: 070 310 300", with both links.
- The bottom row carries Terms · Privacy · Card payments, the operator line and ©.
- Card logos (Visa, Mastercard, Visa Secure, Mastercard ID Check) are added in the cPay project.

### Phone bar: Call · Viber · WhatsApp · Enquire

- **Same widths and behaviour as today's bar.** It is visible on phones only.
- **Icons are monochrome in the site's ink colour**, not the brand green and purple.
- **The 4th button depends on the page.** On weddings and conferences it jumps to the page's own form, labelled "Request a date" or "Request an offer". On every other page it opens Contact.
- **Viber and WhatsApp both open a chat**, from which the guest taps call. Neither app has a reliable direct-call link.
- **The phone on +389 70 310 300 must be signed in to both apps and watched by reception.** The free WhatsApp Business app adds working hours and an automatic away message. This is a step for the hotel, not part of the build.

### Enquiry form (one shared component)

- **Today's look and its context-aware fields:**
  - a stay asks for arrival, departure, guests and room type;
  - a wedding or conference asks for the event date and hall-size guest count.
- **The conference type adds** a company field (optional) and an overnight stay field (yes/no).
- **Preselection** comes from the page, or from the query string on `/kontakt/`: `?type=stay|wedding|conference|group&arrival=YYYY-MM-DD&departure=YYYY-MM-DD&guests=1..10+`.
  - The home availability form sends visitors there with their dates.
  - Values are checked when read: unknown types, malformed dates and unknown guest counts are ignored.
- **Hidden fields:** `page` (the page key) and `lang`, plus `utm_source`, `utm_medium` and `utm_campaign` when present in the page URL.
- **Privacy notice.** A short line under the button links to the Privacy page. There is no checkbox.
- **Spam trap.** A hidden honeypot field that people never fill in.
- **Sending:** a `fetch` POST to `/api/enquiry.php` (section 6). The states are:
  - **sending:** the button is disabled and shows a spinner;
  - **success:** the form is replaced by a thank-you message;
  - **field errors:** shown under each field. The browser checks first and the server repeats the same rules.
  - **failure** (server error, rate limit, or no server, as on the GitHub preview): a message offering phone, Viber and WhatsApp, with the guest's text kept in the form.

## 5. Build

**Tooling:** Eleventy 3 with Nunjucks templates, on Node 22 (installed). There is no front-end framework.

```
src/
  _data/      site.json        contact facts, operator line, WhatsApp messages
              mk.json, en.json page text, same keys as today's data-i18n keys
              halls.json       the three halls and their capacities
              rooms.json       the four room types
              pages.json       page keys → MK/EN slugs, titles, descriptions
  _includes/  layouts/base.njk
              partials/header.njk, footer.njk, action-bar.njk,
                       enquiry-form.njk, halls.njk, faq.njk
              sections/        today's sections as includes (hero, intro, rooms, ...)
  pages/      one template per page key; each is paginated over ["mk", "en"]
  assets/     css/, js/, fonts (copied)
  images/     copied unchanged
  api/        enquiry.php and its library (copied unchanged)
  cpay/       reserved (section 7)
  .htaccess   HTTPS, 404 page, cache headers, redirects (section 9)
_site/        build output, git-ignored
```

**Each page is written once.** A page template is built twice, once per language, with text from `mk.json` and `en.json`, so the two languages cannot drift apart.

**Moving today's text:**

- A one-off script extracts the text into the data files:
  - the English for every `data-i18n` key from `index.html`;
  - the Macedonian from the `MK` table in `i18n.js`;
  - both languages of the runtime strings from the `RUNTIME` table in `i18n.js`.
- A check confirms every key exists in both languages.
- `i18n.js` is then removed.
- `main.js` keeps calling `i18n.t()`. The build provides `window.oazaI18n` per language, with `lang`, `t()` and a no-op `onChange`, from the runtime strings. The language-swap logic and the anchor-based navigation are removed from `main.js`.

**CSS:**

- `sections.css` (2,478 lines) is split into one file per section, without editing any rule.
- The build joins tokens → base → sections (in the original order) → fx into one `site.css`.
- A check confirms that joining the section files reproduces the original `sections.css` exactly.

**Other build details:**

- **Version strings.** The build appends a content hash to CSS and JS links, replacing the hand-edited `?v=…` strings.
- **Effects panel.** `SHOW_PANEL` is false in every build. The panel stays available locally behind a `?fx=panel` URL flag, so reviewing effects remains possible.
- **sitemap.xml and robots.txt** are generated.

**Commands:**

| Command | Purpose |
|---|---|
| `npm start` | Local dev server on port 5510. `.claude/launch.json` is updated to run it, replacing the Python server. |
| `npm run build` | Live build: base path `/`, and it fails if any hotel marker remains (section 8) |
| `npm run build:preview` | GitHub Pages build: path prefix `/hotel-oaza-site/`, with markers shown highlighted |
| `npm run check` | Build checks (section 10) |

**Preview link.** `https://stefce221.github.io/hotel-oaza-site/` keeps working. A GitHub Actions workflow (`.github/workflows/pages.yml`) runs `build:preview` on every push to `main` and publishes `_site/`. The owner switches the repository's Pages source to "GitHub Actions" once, at the first push of this work. Pushing still needs the owner's OK every time.

## 6. Enquiry back end (MKhost)

**`/api/enquiry.php`**

- **Requests:** POST only, form-encoded. Requests whose `Origin` is not the site's own host are rejected.
- **Validation** (the same rules as the browser):
  - name: required, at most 100 characters;
  - email: required, valid (`filter_var`), at most 200 characters;
  - type: one of stay, wedding, conference or group;
  - dates: ISO format, not before today (Skopje time), and departure after arrival for a stay;
  - guest values: from the form's lists only;
  - phone: at most 40 characters;
  - company: at most 150 characters;
  - message: at most 2,000 characters.
- **Honeypot.** If the hidden field is filled, the script answers success and sends nothing.
- **Rate limit.** At most 5 enquiries per hour per visitor, keyed on a salted hash of the IP and stored in the private folder. Entries expire after an hour.
- **Sending:**
  - The script uses PHPMailer over authenticated SMTP with a mailbox on oazahotel.com.mk, and sends to info@oazahotel.com.mk.
  - `Reply-To` is the guest's address. CR and LF are stripped from every header value.
  - The email is plain text and written in Macedonian whatever language the guest used. It includes a "Guest's language: EN/MK" line, the page, and the ad campaign fields.
- **No automatic email to the guest.** It could be abused to send mail to strangers.
- **Response format:** `{"success": true|false, "data": null, "error": null | {"code": "...", "fields": [...]}}`. The error codes are:
  - `invalid`, with the list of fields;
  - `rate_limited`;
  - `send_failed`;
  - `bad_request`.
- **Errors** are logged to the private folder with a timestamp and code only, never personal data. Enquiries themselves are not stored on the server.
- **Secrets.** SMTP credentials live in `/home/oazahote/private/config.php`, outside `public_html` and outside git. The repository keeps a `config.example.php` with empty values.
- **PHP version.** The script targets PHP 8.x. At launch the account moves from PHP 7.1 (needed only by the old site being replaced) to PHP 8.x in cPanel. Before relying on this, confirm that MKhost's version selector offers PHP 8.
- **Mail setup.** Before launch, check the domain's SPF and DKIM in cPanel (Email Deliverability) so the messages reach the inbox.

## 7. Places reserved for cPay (built in the cPay project)

- **`/cpay/ok.php` and `/cpay/fail.php`** at exactly the addresses on the call sheet: `https://www.oazahotel.com.mk/cpay/ok.php` and `/cpay/fail.php`. This project creates the folder with a placeholder so the registered addresses never return 404:
  - It answers HTTP 200 with a short "payment status received" page in both languages, so CaSys doesn't retry or send "failed merchant notification" emails.
  - It saves each received POST to the private folder with a timestamp, so no push is lost before the real handlers exist. cPay pushes carry no card numbers.
- **`/home/oazahote/private/`** holds the checksum key and the order records the bank requires to be kept for 12+ months. It is never in git and never in `public_html`.
- **The Card payments page exists now.** The payment form, the staff page for redeeming Booking.com virtual cards and the card logos come with the cPay project.

## 8. Policy pages

**Terms, Privacy and Card payments** are drafted in MK and EN.

- **Terms & cancellation:**
  - how to book (enquiry → offer → confirmation);
  - check-in from 14:00 and check-out until 12:00;
  - deposit, cancellation and no-show rules;
  - prices in MKD.
- **Privacy** follows the Macedonian Law on Personal Data Protection (2020):
  - the controller (MBS, with address and ЕДБ);
  - what the form collects and why (to answer the enquiry);
  - who receives it (hotel reception by email);
  - how long it is kept;
  - the map loads from Google only on request;
  - rights, and how to exercise them (info@oazahotel.com.mk);
  - the right to complain to the Personal Data Protection Agency (АЗЛП).
- **Card payments:**
  - amounts are in MKD;
  - card details are entered only on the cPay (CaSys) page and never reach the hotel;
  - 3-D Secure (Visa Secure, Mastercard ID Check);
  - refunds go back to the same card;
  - the operator's legal name, address, ЕДБ, phone and email.

**Hotel markers.** Facts only the hotel can give are written as `[[HOTEL: what is needed]]`:

- the deposit rules;
- the cancellation and no-show rules;
- the refund timing;
- how long enquiry emails are kept;
- the confirmation that companies get invoices.

`npm run build` fails, listing every remaining marker. `npm run build:preview` shows markers highlighted. When the bank sends its checklist for web payments, the pages are compared against it.

## 9. Launch (only on the owner's go; not part of the build work)

1. Fill every hotel marker; set up the SMTP mailbox and `private/config.php`; switch PHP to 8.x.
2. Back up the old PHP site from `public_html`, and list its URLs.
3. Upload `_site/` over SSH.
4. In `.htaccess`:
   - redirect HTTP to HTTPS;
   - redirect each old-site URL (301) to the matching new page;
   - set `ErrorDocument 404 /404.html`.
5. Send a real test enquiry, and confirm it arrives at info@.
6. Redirect `oazahotel.com` (if renewed) to `https://www.oazahotel.com.mk/`, and `malodmor.mk` to `/restoran/`.
7. Add the site to Google Search Console and submit the sitemap.

## 10. Checks

- **Visual parity.**
  - Before migrating, playwright-cli captures every section of today's `index.html` at 390×844 and 1440×900, saved in the git-ignored `_backup/baseline/`.
  - After migrating, the same sections are captured on the new pages and compared.
  - Allowed differences: header links, the active-page state, Viber and WhatsApp additions, teaser shortening on Home, and the form's sending text.
- **`npm run check`, run after every build:**
  - every internal link and asset resolves;
  - every MK page has its EN pair, and the reverse;
  - every page has `lang`, a title, a description, hreflang links and one H1;
  - every data key exists in both languages;
  - there are no hotel markers (live build);
  - the joined section CSS equals the original `sections.css`.
- **PHP tests.** PHP 8 is installed locally (it is not installed now). Tests run against PHP's built-in server with a fake mailer that writes to a file. They cover:
  - a valid enquiry for each type;
  - each missing or invalid field;
  - over-long values;
  - CR/LF in name and email;
  - the honeypot;
  - the rate limit;
  - a wrong `Origin`;
  - GET requests;
  - the response format.
- **Browser tests** live in the Playwright toolkit at `K:\ClaudeProjects\playwright-toolkit\tests\hotel-oaza\`. They check that:
  - each page preselects the right form type;
  - the Call, Viber and WhatsApp links (with the right ready message) are correct;
  - EN/MK opens the paired page;
  - the phone bar appears only at phone widths;
  - the `/kontakt/` query prefill works;
  - the failure state appears when the API is unreachable.
- **Before launch:** one real enquiry sent from the MKhost server to info@.

## 11. Content needed from the hotel

| Item | Used on | If missing |
|---|---|---|
| Deposit, cancellation and no-show rules; refund timing | Terms, FAQs | Live build blocked |
| How long enquiry emails are kept | Privacy | Live build blocked |
| Confirmation that companies get invoices | Conferences FAQ | Live build blocked |
| Real photos of the halls and past weddings | Weddings, Conferences | Illustrative images stay; gallery left out |
| Extras included at weddings (decoration, music, cake) | Weddings | Block left out |
| "From … per person" price; package prices | Weddings, Conferences | Left out / "price on request" |
| Restaurant hours, menu highlights | Restaurant | Block left out |
