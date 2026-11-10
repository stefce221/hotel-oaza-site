# Hotel Oaza website — owner checklist

What only the hotel and the owner can do. Spec: `docs/superpowers/specs/2026-11-06-site-structure-design.md`.

## Before the first push of this work

- [ ] In GitHub → hotel-oaza-site → Settings → Pages, set **Source: GitHub Actions**. Until then the preview link stops working after the push, because the old root `index.html` is gone.
- [ ] If the first push already happened before the source was switched, switch it now, then run the workflow once: Actions → Preview → "Run workflow". If GitHub offers a starter workflow during the switch, don't commit it; the repo already has `.github/workflows/pages.yml`.
- [ ] Approve the push. Every push updates the preview link your friend uses.

## Facts the hotel supplied (filled in on 2026-11-10)

The live build still refuses any `[[HOTEL: …]]` marker, so a new open question can be added the same way.

- [x] Deposit (Terms): the offer says whether a deposit is needed, how much it is and by when it is paid. The owner's choice.
- [x] Cancellation and no-show (Terms): a stay can be cancelled free of charge up to 3 days before arrival; after that, or when the guest does not arrive, the first night is charged. This is the "Flexible – 3 days" policy from the Booking.com extranet; the no-show part was suggested by the developer and approved by the owner. Events follow the offer.
- [x] Refund timing (Terms, Card payments): within 14 days of the cancellation. Suggested by the developer, approved by the owner.
- [x] Enquiry emails (Privacy): kept for 12 months after the last message, then deleted. Suggested by the developer, approved by the owner.
- [x] Registered address of MBS (Privacy, Card payments): Tošo Arsov 32, 2000 Štip, as in the invoice details of the Booking.com extranet.
- [x] Invoices for companies (Conferences FAQ): yes; the hotel needs the company's full name, address and tax number (ЕДБ). Suggested by the developer, approved by the owner.
- [x] "Last updated" date of the policy pages: 10 November 2026. Change it again whenever these texts change (`legal.updated` in `src/i18n/en/legal.json` and `src/i18n/mk/legal.json`).

What these answers still ask of the hotel:

- [ ] Compare the registered address with the company's extract from the Central Registry (тековна состојба). If it differs, tell the developer.
- [ ] Decide who deletes enquiry emails older than 12 months from the info@ mailbox, and how often. The Privacy page promises it.
- [ ] Booking.com shows other times than this site: check-in from 12:00 and check-out until 11:00 there, from 14:00 and until 12:00 here. Decide which is right and correct the other.
- [ ] Booking.com lists room service and a free airport shuttle. This site offers neither. If the hotel doesn't offer them, remove them from the Booking.com listing.

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
- [ ] The site must sit directly in `/home/oazahote/public_html`, because the endpoint looks for `config.php` in `/home/oazahote/private`. If the domain's folder is somewhere else (for example an addon domain), tell the developer before upload.
- [ ] Create `/home/oazahote/private/` outside `public_html` with permissions **700** (cPanel File Manager → Change Permissions). Then copy `deploy/private/config.example.php` there as `config.php` and fill in the mailbox, its password and a random salt.
- [ ] The mail login: `username` in `config.php` must be the same mailbox as `from`, and `mail.oazahotel.com.mk` must present a valid certificate for that name (the mail settings page at MKhost says which host name to use). Never switch off certificate checks to make it work; tell the developer instead.
- [ ] If info@oazahotel.com.mk is read somewhere other than MKhost (for example Gmail or Microsoft 365), set cPanel → Email Routing to **Remote Mail Exchanger** for oazahotel.com.mk. Otherwise enquiries land in an unused mailbox on the server.
- [ ] Ask MKhost whether a proxy or CDN sits in front of the site. If one does, tell the developer before launch: the visitor limit on enquiries, the redirect to HTTPS and the recognition of cPay's address all depend on it.
- [ ] Keep the `cpay` lines in `config.php` as they are in the example. They name the address cPay sends its payment notices from (`80.77.147.43`, given by CaSys in January 2025). The hotel charges cards in batches, and only notices from that address are kept without the hourly cap. If CaSys ever names a new address, change it there.
- [ ] After every later change to `config.php`, send one test enquiry from the site. If it fails, `config.php` has a mistake, and until it is fixed cPay's payment notices are answered but not saved.
- [ ] Ask MKhost support to confirm that their firewall and ModSecurity let `80.77.147.43` send POST requests to `/cpay/ok.php` and `/cpay/fail.php`, also when dozens arrive within a few minutes.
- [ ] In cPanel → Email Deliverability, fix SPF and DKIM for oazahotel.com.mk if it reports problems.
- [ ] Set up WhatsApp (ideally WhatsApp Business) and Viber on +389 70 310 300, so the new buttons reach someone.
- [ ] Before launch, tap the Viber button on an Android phone, an iPhone and a Windows PC with Viber installed. Each must open a chat with +389 70 310 300. If one doesn't, tell the developer which device.
- [ ] Spam-trap count: the enquiry endpoint writes one line with the time and `spam_trap` to `/home/oazahote/private/logs/errors.log` each time its hidden field catches a bot (nothing about the sender). To see how many: `grep -c spam_trap /home/oazahote/private/logs/errors.log`. The same file lists `send_failed` lines when an email could not be sent; check it if guests say their enquiry failed.

## Right after the first upload

Open each address in a browser (a private window is fine). If anything below differs from what is written, tell the developer which address and what you saw. Some hosts run LiteSpeed instead of Apache, and a few rules then behave differently.

- [ ] These must answer **403 Forbidden**: `/api/lib/validate.php`, `/api/vendor/phpmailer/PHPMailer.php`, `/api/vendor/phpmailer/VERSION`, `/cpay/lib/placeholder.php`, `/.oaza-build.json`, `/api/.user.ini`.
- [ ] Opening `/api/enquiry.php` in the browser must answer **405** with a short line of JSON text (not a blank page, not a PHP error).
- [ ] `/cpay/ok.php` must answer **200** and show the short "payment status received" page in Macedonian and English.
- [ ] If the whole site, every page, answers "500 Internal Server Error" after the upload, the host does not allow `Options` in `.htaccess`. Tell the developer; it is one line to remove.

## Launch (only on the owner's go)

1. Run `npm run build` (it must pass; it fails if a `[[HOTEL: …]]` marker is left) and `npm run check:live` (not only `npm run check`). It fails unless `_site/` is a live build, so a preview or dev build that would hide the site from Google can't be uploaded by mistake.
2. Back up the old site from `public_html`, and list its addresses.
3. Empty `public_html`, keeping `.well-known`.
4. Switch the account to PHP 8.x in cPanel.
5. Upload with `rsync -av _site/ <ssh-user>@<server>:public_html/` (fill in the real SSH user and host). The trailing slash also copies `.htaccess` and other dotfiles; a `_site/*` glob would skip them.
6. Add a 301 redirect to `.htaccess` for each old address, pointing to the matching new page.
7. Do the checks under "Right after the first upload" above (the 403, 405 and 200 answers).
8. Send a real enquiry from the site and confirm it reaches info@oazahotel.com.mk.
9. Redirect oazahotel.com (if renewed) to https://www.oazahotel.com.mk/, and malodmor.mk to /restoran/.
10. Add the site to Google Search Console and submit `https://www.oazahotel.com.mk/sitemap.xml`.
11. On the hotel's Facebook page, change the website link from oazahotel.com to https://www.oazahotel.com.mk/. The site's footer now links to that page.

## Later projects

- cPay card payments: the payment form, the staff page for Booking.com virtual cards and the card logos. They replace `src/cpay/`. The cPay project must also add a retention period and privacy text for the payment-status records the placeholders keep in `private/cpay-inbox` and `cpay-ratelimit.json`.
  - On 2026-11-09 Komercijalna banka confirmed that the hotel's cPay merchant is registered to www.oazahotel.com.mk with `/cpay/ok.php` and `/cpay/fail.php`. No card can be charged online until the payment page exists on that domain.
  - The payment request to cPay must be a POST with the `application/x-www-form-urlencoded` content type. CaSys asked for this in June and September 2026; the old Wix page sent a GET.
  - Two things are assumed, not confirmed in writing: the checksum key issued on 2025-05-20 still works after the domain change, and cPay still sends from `80.77.147.43`. If the first test payment fails its checksum, or its notices don't arrive, ask CaSys (ecommerce@casys.com.mk) about these two first.
  - Right after the payment page goes live, make one test payment and check that its notices arrive: a file in `private/cpay-inbox` and the "Order Status" email from cPay. Then report the result to the bank (e-commerce@kb.mk) and CaSys, as the cPay specification asks after a domain change.
- The effects decision. Review the effects at any page with `?fx=panel`.
