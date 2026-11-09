# Hotel Oaza website — owner checklist

What only the hotel and the owner can do. Spec: `docs/superpowers/specs/2026-11-06-site-structure-design.md`.

## Before the first push of this work

- [ ] In GitHub → hotel-oaza-site → Settings → Pages, set **Source: GitHub Actions**. Until then the preview link stops working after the push, because the old root `index.html` is gone.
- [ ] Approve the push. Every push updates the preview link your friend uses.

## Facts the hotel must supply (the live build refuses to run without them)

Each appears on the site as `[[HOTEL: …]]`, highlighted in yellow on the preview.

- [ ] Deposit rules for stays and events: when, how much, by when (Terms)
- [ ] Cancellation and no-show rules for stays and events (Terms)
- [ ] Refund timing: within how many days (Terms, Card payments)
- [ ] How long enquiry emails are kept, and when they are deleted (Privacy)
- [ ] Registered address of MBS Ugostitelstvo i trgovija DOOEL Štip (Privacy, Card payments)
- [ ] Confirmation that companies get an invoice for events, and which company details are needed (Conferences FAQ)
- [ ] When the markers in the policy pages are filled in, also update their "last updated" date (`legal.updated` in `src/i18n/en/legal.json` and `src/i18n/mk/legal.json`).

## Content that improves the site (optional; the site works without it)

- [ ] Real photos of the halls and of past weddings (Weddings, Conferences)
- [ ] Extras included at weddings: decoration, music, cake (Weddings)
- [ ] A "from … per person" price, or package prices (Weddings, Conferences)
- [ ] Restaurant hours and menu highlights (Restaurant)

## Review

- [ ] A native speaker proofreads every Macedonian page, especially the new texts: landing pages, FAQ, Terms, Privacy, Card payments.
- [ ] Someone who knows the Law on Personal Data Protection reads Terms and Privacy. These are drafts, not legal advice.
- [ ] Open https://azlp.mk/ and confirm it is still the Agency's address (linked from Privacy).
- [ ] When CaSys sends its checklist for web payments, compare Card payments, Terms and Privacy against it.
- [ ] The Card payments page describes paying through cPay, which works only once the CaSys merchant contract is active. Keep it as it is for the CaSys review. If the site goes live before the contract is active, tell the developer, so the page can be taken out of the footer and sitemap until then.
- [ ] Text to translate (Macedonian still identical to English): none found. The only identical texts are names (AquaBella, English).

## Server, before launch

- [ ] In cPanel, confirm the PHP selector offers PHP 8.0 or newer with the `mbstring` and `openssl` extensions.
- [ ] Create the sending mailbox on oazahotel.com.mk (for example web@) and note its SMTP settings.
- [ ] Create `/home/oazahote/private/` outside `public_html`. Copy `deploy/private/config.example.php` there as `config.php` and fill in the mailbox, its password and a random salt.
- [ ] In cPanel → Email Deliverability, fix SPF and DKIM for oazahotel.com.mk if it reports problems.
- [ ] Set up WhatsApp (ideally WhatsApp Business) and Viber on +389 70 310 300, so the new buttons reach someone.
- [ ] Before launch, tap the Viber button on an Android phone, an iPhone and a Windows PC with Viber installed. Each must open a chat with +389 70 310 300. If one doesn't, tell the developer which device.
- [ ] Spam-trap count: the enquiry endpoint writes one line with the time and `spam_trap` to `/home/oazahote/private/logs/errors.log` each time its hidden field catches a bot (nothing about the sender). To see how many: `grep -c spam_trap /home/oazahote/private/logs/errors.log`. The same file lists `send_failed` lines when an email could not be sent; check it if guests say their enquiry failed.

## Launch (only on the owner's go)

1. Fill every marker above, then run `npm run build` (it must pass) and `npm run check:live` (not only `npm run check`). It fails unless `_site/` is a live build, so a preview or dev build that would hide the site from Google can't be uploaded by mistake.
2. Switch the account to PHP 8.x in cPanel.
3. Back up the old site from `public_html`, and list its addresses.
4. Upload `_site/` over SSH.
5. Add a 301 redirect to `.htaccess` for each old address, pointing to the matching new page.
6. Check that `https://www.oazahotel.com.mk/api/lib/bootstrap.php` and `/cpay/lib/placeholder.php` answer 403, and that `/cpay/ok.php` answers 200.
7. Send a real enquiry from the site and confirm it reaches info@oazahotel.com.mk.
8. Redirect oazahotel.com (if renewed) to https://www.oazahotel.com.mk/, and malodmor.mk to /restoran/.
9. Add the site to Google Search Console and submit `https://www.oazahotel.com.mk/sitemap.xml`.

## Later projects

- cPay card payments: the payment form, the staff page for Booking.com virtual cards and the card logos. They replace `src/cpay/`.
- The effects decision. Review the effects at any page with `?fx=panel`.
