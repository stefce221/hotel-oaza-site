# Hotel Oaza website

Bilingual site for Hotel Oaza, Štip: Macedonian at `/`, English under `/en/`. Built with Eleventy 3 from Nunjucks templates. The enquiry form posts to a small PHP endpoint on MKhost.

- Preview: https://stefce221.github.io/hotel-oaza-site/ (built by `.github/workflows/pages.yml` on every push to `main`)
- Live (after launch): https://www.oazahotel.com.mk/
- Design: `docs/superpowers/specs/2026-11-06-site-structure-design.md`
- What the hotel still has to supply: `docs/owner-checklist.md`

## Commands

| Command | What it does |
|---|---|
| `npm start` | Dev server at http://127.0.0.1:5510 (no PHP: the form shows its "reach us directly" state) |
| `npm test` | Node and PHP unit tests, plus HTTP tests against PHP's built-in server (needs PHP 8 on PATH, or `PHP_BIN`) |
| `npm run build:preview` | Preview build for GitHub Pages (path prefix `/hotel-oaza-site/`, hotel markers highlighted) |
| `npm run build` | Live build for MKhost; fails while any `[[HOTEL: …]]` marker remains |
| `npm run check` | After a build: links, page pairs, head tags, dictionaries, CSS split, markers (live) |
| `npm run check:live` | The same checks, and fails unless `_site/` is a live build, so a preview or dev build can't be uploaded by mistake |

Browser and pixel checks live in the Playwright toolkit:

```bash
cd /k/ClaudeProjects/playwright-toolkit && npx playwright test --config=tests/hotel-oaza/playwright.config.ts
```

## Where things are

| Path | What |
|---|---|
| `src/pages/` | One template per page, built for `mk` and `en` |
| `src/_includes/` | Layout, today's sections, partials, policy texts (`legal/<page>.<lang>.njk`) |
| `src/i18n/{mk,en}/*.json` | All page text; `src/i18n/runtime/*.json` holds script strings |
| `src/_data/` | Contact facts (`site.json`), page addresses (`pages.json`), halls, share images |
| `src/assets/css/` | `sections/` is today's CSS split and never edited; new rules go in `additions/` |
| `src/api/` | `enquiry.php` and its libraries; PHPMailer in `src/api/vendor/phpmailer/` |
| `src/cpay/` | Placeholders for the addresses registered with CaSys |
| `deploy/private/config.example.php` | Template for the server's private config |

## Text changes

Change a text in both `src/i18n/mk/…` and `src/i18n/en/…`. The build refuses a key that exists in only one language. A fact only the hotel can confirm goes in as `[[HOTEL: what is needed]]`. Previews show it highlighted, and the live build refuses it.

## Server

The PHP code targets PHP 8.0 or newer. Secrets live only in `/home/oazahote/private/config.php` on MKhost, outside `public_html` and outside git. Enquiries are emailed to reception and not stored on the server. Launch steps are in `docs/owner-checklist.md`.
