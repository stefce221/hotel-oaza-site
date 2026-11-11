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

- [x] The account runs PHP 8.3 since 2026-11-10 (cPanel → Select PHP Version → Account Global Settings → 8.3 → Apply), with `mbstring` and `openssl` on. cPanel also offers 8.1, 8.2 and 8.4.
- [x] The sending mailbox web@oazahotel.com.mk exists since 2026-11-10, and `config.php` names it as the sender.
- [x] The site must sit directly in `/home/oazahote/public_html`, because the endpoint looks for `config.php` in `/home/oazahote/private`. cPanel confirms it: oazahotel.com.mk is the main domain and its folder is `/public_html` (2026-11-10).
- [x] `/home/oazahote/private/` (permissions 700) holds `config.php` (600), complete since 2026-11-10.
- [ ] The salt in `config.php` is short. A long random one is better (64 characters from `openssl rand -hex 32`); it can be swapped in at any time with cPanel → File Manager → Edit, followed by one test enquiry.
- [x] The mail login: `mail.oazahotel.com.mk` presents a valid certificate for that name on port 465 (checked on 2026-11-10). `username` in `config.php` must stay the same mailbox as `from`. Never switch off certificate checks to make it work; tell the developer instead.
- [x] Mail for oazahotel.com.mk is received at MKhost (the domain's mail address points to the same server and cPanel's routing is local, 2026-11-10), so Email Routing stays as it is. If the hotel ever moves its mail to Gmail or Microsoft 365, set cPanel → Email Routing to **Remote Mail Exchanger** then.
- [ ] Ask MKhost whether a proxy sits in front of the site. Seen from outside there is none: www.oazahotel.com.mk and oazahotel.com.mk point straight to MKhost's server (176.9.126.180) and it answers as Apache (2026-11-10). If MKhost says otherwise, tell the developer before launch: the visitor limit on enquiries, the redirect to HTTPS and the recognition of cPay's address all depend on it.
- [ ] Keep the `push_ips` line of the `cpay` section in `config.php` as it is in the example. It names the address cPay sends its payment notices from (`80.77.147.43`, given by CaSys in January 2025). The hotel charges cards in batches, and only notices from that address are kept without the hourly cap. If CaSys ever names a new address, change it there. The other `cpay` lines are filled in under "Charging Booking.com virtual cards" below.
- [ ] After every later change to `config.php`, send one test enquiry from the site. If it fails, `config.php` has a mistake, and until it is fixed cPay's payment notices are not recorded (cPay is answered with an error and tries again later), and the staff page `/naplata/` answers with an error, so no card can be charged.
- [ ] Ask MKhost support to confirm that their firewall and ModSecurity let `80.77.147.43` send POST requests to `/cpay/ok.php` and `/cpay/fail.php`, also when dozens arrive within a few minutes.
- [x] SPF and DKIM for oazahotel.com.mk are valid (cPanel's own check, 2026-11-10).
- [ ] The old site is out of `public_html` since 2026-11-10. It sits in `/home/oazahote/old-site-2026-11/`, and the files a break-in had left in it are in cPanel's trash (File Manager → View Trash). Neither can be opened from the internet any more. The owner has since changed the passwords of the MKhost portal, of cPanel and of the mailboxes. Still to do: empty the trash; delete the old-site folder once nobody needs it.
- [ ] Set up WhatsApp (ideally WhatsApp Business) and Viber on +389 70 310 300, so the new buttons reach someone.
- [ ] Before launch, tap the Viber button on an Android phone, an iPhone and a Windows PC with Viber installed. Each must open a chat with +389 70 310 300. If one doesn't, tell the developer which device.
- [ ] Spam-trap count: the enquiry endpoint writes one line with the time and `spam_trap` to `/home/oazahote/private/logs/errors.log` each time its hidden field catches a bot (nothing about the sender). To see how many: `grep -c spam_trap /home/oazahote/private/logs/errors.log`. The same file lists `send_failed` lines when an email could not be sent; check it if guests say their enquiry failed.

## Right after the first upload

Open each address in a browser (a private window is fine). If anything below differs from what is written, tell the developer which address and what you saw. Some hosts run LiteSpeed instead of Apache, and a few rules then behave differently.

- [x] These must answer **403 Forbidden**: `/api/lib/validate.php`, `/api/vendor/phpmailer/PHPMailer.php`, `/api/vendor/phpmailer/VERSION`, `/cpay/lib/placeholder.php`, `/.oaza-build.json`, `/api/.user.ini`. All six did on 2026-11-10.
- [x] Opening `/api/enquiry.php` in the browser must answer **405** with a short line of JSON text (not a blank page, not a PHP error). It did on 2026-11-10.
- [x] `/cpay/ok.php` must answer **200** and show the short "payment status received" page in Macedonian and English. It did on 2026-11-10.
- [x] If the whole site, every page, answers "500 Internal Server Error" after the upload, the host does not allow `Options` in `.htaccess`. Tell the developer; it is one line to remove. Not the case on 2026-11-10.

## Launch (only on the owner's go)

Steps 1 to 8 were done on 2026-11-10 with commit `7f67dd9`. For step 8, the site accepted a test enquiry and the server's delivery log shows it delivered to info@oazahotel.com.mk; the hotel should still look for it in the inbox. Steps 9 to 11 are open. A later upload repeats steps 1, 4 and 7.

1. Run `npm run build` (it must pass; it fails if a `[[HOTEL: …]]` marker is left) and `npm run check:live` (not only `npm run check`). It fails unless `_site/` is a live build, so a preview or dev build that would hide the site from Google can't be uploaded by mistake.
2. Move the old site out of `public_html` into a folder beside it (for example `/home/oazahote/old-site-2026-11/`), so it can be looked at later but no longer opened from the internet. Delete the break-in files instead of moving them. MKhost also keeps its own daily backups (portal → Hosting → Backup на податоци).
3. Only two folders stay in `public_html`: `.well-known` (the certificate renews through it) and `malodmor.mk` (the folder of the addon domain; it holds nothing but its own `.well-known`).
4. Upload. SSH is switched off for this account, so pack the contents of `_site/` with `tar` (built from a checkout with Unix line endings, files 644, folders 755, `.htaccess` and the other dotfiles inside), upload the archive with cPanel → File Manager into a folder beside `public_html` (`/home/oazahote/site-upload/`), extract it there, then move its contents into `public_html`. The archive of each upload stays in `_upload/` on the developer's PC, outside git. If SSH is switched on later (portal → Hosting → SSH пристап, with a public key), `rsync -av _site/ <ssh-user>@<server>:public_html/` does the same; the trailing slash also copies the dotfiles, a `_site/*` glob would skip them.
5. Set PHP 8.3 in cPanel → Select PHP Version → Account Global Settings. It is a setting of the account, not a line in `.htaccess`, so a later upload keeps it.
6. The redirects for the old site's addresses are already in `.htaccess`; `test/redirects.test.mjs` lists them. Open `/about-us`, `/contacts` and `/reservations` once to see them work; `/reservations` must open the contact form with a stay enquiry chosen. An extra `?…` on an old address is dropped: `/reservations?a=b` lands on `/kontakt/?type=stay` (seen on 2026-11-10).
7. Do the checks under "Right after the first upload" above (the 403, 405 and 200 answers). Once the staff page is uploaded, `/cpay/lib/placeholder.php` no longer exists: probe `/cpay/lib/notice.php` and `/naplata/.user.ini` in its place (both must answer 403), with the other addresses as listed.
8. Send a real enquiry from the site and confirm it reaches info@oazahotel.com.mk.
9. Redirect oazahotel.com (if renewed) to https://www.oazahotel.com.mk/, and malodmor.mk to /restoran/. malodmor.mk is not served from MKhost: its address points to Firebase Hosting (Google) (2026-11-10), so its redirect is set there, by whoever runs that page.
10. Add the site to Google Search Console and submit `https://www.oazahotel.com.mk/sitemap.xml`.
11. On the hotel's Facebook page, change the website link from oazahotel.com to https://www.oazahotel.com.mk/. The site's footer now links to that page.

## Charging Booking.com virtual cards (the staff page `/naplata/`)

Built and uploaded on 2026-11-11 (commit `2e526fd`); the first real charge went through the same day. Reception opens `https://www.oazahotel.com.mk/naplata/`, signs in, types the amount in denars and the Booking reservation number, and is sent to cPay to type the card. The card number never passes through the hotel's site. The page then lists each charge with its result. The list is a help for reception; what was really paid is decided by the cPay merchant portal and the bank statement.

### Setting it up (once, after the upload)

- [x] Upload the new build (Launch steps 1, 4 and 7 above). Done on 2026-11-11 with commit `2e526fd`: the archive was extracted over `public_html`, which kept `.well-known` and `malodmor.mk`. The checks of step 7 gave the same answers as on 2026-11-10.
- [x] An upload does not remove old files, so afterwards delete `cpay/lib/placeholder.php` from `public_html`; the new build no longer uses it. Moved to cPanel's trash on 2026-11-11.
- [x] `/naplata/` asks for a name and password. Until a staff user exists, whatever is typed ends in a server error (500), and Cancel shows 401. That is expected: the server refuses everyone while there is no password file.
- [x] Create the staff users (the first one was made on 2026-11-11; cPanel showed the folder as already protected, under the name "Hotel Oaza"): cPanel → Directory Privacy → `public_html` → `naplata` → Create User. Do not change "Password protect this directory" there: the folder's own `.htaccess` already protects it, so only create users. One user per person (Latin letters, digits and dots only, no Cyrillic and no spaces, for example the person's first name), with the password cPanel's Password Generator makes. Keep the passwords in a password manager, not in e-mail or chat. The same place removes a user who leaves. After creating users, do the checks below (again).
- [x] Check in a private window, and tell the developer if anything differs. Checked on 2026-11-11: all as described, with one difference on plain http (see that line).
  - `https://www.oazahotel.com.mk/naplata/`, `/naplata/index.php` and `/naplata/start.php` each ask for the user name and password; pressing Cancel shows "401" (not the page).
  - `http://www.oazahotel.com.mk/naplata/` (http, without the s) must not ask for a password. Browsers switch to https on their own, so the developer checks this one with `curl -sI http://www.oazahotel.com.mk/naplata/`: it must answer 403 with no `WWW-Authenticate` line. On MKhost it answers 301 to `https://www.oazahotel.com.mk/403.shtml`, with no `WWW-Authenticate` line: the folder refuses the request, and cPanel's error page for that refusal is then sent through the site's redirect to https. No password is asked for over http, which is what matters.
  - `https://www.oazahotel.com.mk/naplata` (without the slash at the end) must not answer 403 Forbidden: it asks for the password and then opens `/naplata/`.
  - `/naplata/.user.ini`, `/cpay/lib/notice.php` and `/cpay/lib/checksum.php` answer **403 Forbidden**.
  - After signing in, the page says "Најавени сте како:" and your user name. If it shows `staff_no_user` instead, the server does not pass the user name on; tell the developer.
- [x] The developer fills in the cPay lines of `config.php` that are not secret (merchant number, merchant name, the two addresses, the largest amount). The `key` line is typed by the owner: the checksum key CaSys sent on 2025-05-20, between the two single quotes that are already there (`'key' => '…',`). Never use double quotes: a `$` in the key would cut it short. The key never goes into e-mail, chat or git. Done on 2026-11-11.
- [x] After saving `config.php`, send one test enquiry from the site (a typing mistake in the file would stop the enquiry form too). Then open `/naplata/`: the form must be there. If it says "Наплатата не е поставена до крај", a cPay line is missing or wrong; a line `config_warning` in `private/logs/errors.log` means PHP warned about `config.php` itself, most often a key between double quotes. If it says "Наплатата не е започната", the setup is fine but the site could not do its part (for example write its files); nothing was charged, and if it keeps saying so, tell the developer. Checked on 2026-11-11 with an empty enquiry: the form answered that fields are missing (so `config.php` loads), no `config_warning` was logged, and `/naplata/` showed the form.

### The first charge

The first charge is also the test payment cPay asks for after a change of domain.

- [x] Before it, the developer sends a made-up payment notice to `/cpay/ok.php` and `/cpay/fail.php`. Both must answer 200 and leave a file in `private/cpay-inbox`. This shows that the host's filters let cPay's notices through. Not needed in the end: the first real charge came first, and cPay's own notices arrived.
- [x] Before it, the developer runs an SSL test of www.oazahotel.com.mk (for example SSL Labs). It must show TLS 1.2 with an AES-256 suite and a certificate from a public authority (AutoSSL), not a self-signed one: cPay's notices need both to arrive. Not needed in the end, for the same reason: cPay's notices arrived over the site's https.
- [x] Charge one real virtual card from `/naplata/`. Done on 2026-11-11. Points 1, 2 and 5 held: the page said "Наплатено:", the row says "Наплатено" and "потврдено од cPay", and the record holds two OK notices marked `push` and the browser's return, with no second payment and nothing in `private/logs/errors.log`. Points 3 and 4 are the owner's to look at. It passed when all five are true:
  1. the browser came back to a page that says "Наплатено:" and the amount;
  2. the row in the list says "Наплатено" and "потврдено од cPay";
  3. the "Order Status" e-mail from cPay arrived;
  4. the cPay merchant portal shows the same amount and reference;
  5. the developer sees two OK notices marked `push` for that charge (cPay sends each result twice), in its record in `private/cpay-payments` or in `private/cpay-inbox`.
- [ ] If cPay sends an e-mail about a "failed merchant notification", its notices are not reaching the site; tell the developer.
- [ ] If cPay shows an error instead of the card form: stop, charge nothing more, and tell the developer what the page said. The usual causes: the key is no longer valid after the domain change (ask the bank, e-commerce@kb.mk, with CaSys, ecommerce@casys.com.mk, in copy), or the merchant name in `config.php` is not written exactly as registered.
- [ ] If, a few minutes after the charge, the row says "Наплатено" but not "потврдено од cPay", or it stays "Чека одговор" or "Нема одговор" although the cPay portal shows the payment, cPay's own notices do not reach the site. The missing mark does not mean the card was not charged: the cPay portal decides that. The usual causes: MKhost's firewall or ModSecurity blocks POST requests from `80.77.147.43` to `/cpay/ok.php` (ask MKhost); the site's IP address is not the one in CaSys's merchant definition (the site has been on 176.9.126.180 since 2026-11-10); the site's TLS settings or certificate (see the SSL test above); or cPay sends from another address now (ask the bank, with CaSys in copy). If cPay's address has changed, its notices still arrive and mark the row "Наплатено" (without the mark), and `private/logs/errors.log` has a line `cpay_push_from_unlisted` with the new address.
- [ ] Report the result to the bank (e-commerce@kb.mk) and to CaSys (ecommerce@casys.com.mk) with the cPay reference (`cPayPaymentRef`) of that charge, under the subject `cPay_<merchant name>_<request>_YYYYMMDD`. The developer drafts the e-mail; the owner sends it.
- [ ] Until the first charge has passed all five checks, look up every row that says "Не е наплатено", "Чека одговор" or "Нема одговор" in the cPay portal before charging that card again.

### Day to day

- Never charge a card again while its row says "Чека одговор". After 45 minutes the row says "Нема одговор": look the charge up in the cPay portal by its code (the column "Шифра") before trying again.
- A row marked "Две наплати" means cPay reported two payments for one charge. Look in the cPay portal and call the bank if both went through.
- If the site's IP address ever changes (MKhost moves it to another server, a new host, a proxy or CDN in front of it), tell the bank (e-commerce@kb.mk) beforehand, so CaSys updates the merchant definition: cPay sends its notices only to the registered address.
- The site keeps the charge records for 18 months and cPay's raw notices for 90 days (`private/cpay-payments` and `private/cpay-inbox`). Older ones are deleted the next time someone opens the page.
- Two things were assumed, not confirmed in writing: the checksum key issued on 2025-05-20 still works after the domain change, and cPay still sends its notices from `80.77.147.43`. The first charge on 2026-11-11 showed that both hold: cPay accepted the request and signed its notices with that key, and they came from the listed address (no `cpay_push_from_unlisted` line).

## Later projects

- cPay, what is left: a payment page for guests, the card logos, and "Pay by Link" (asked from the bank on 2026-11-02; the merchant portal shows a Pay by Link page, but on 2026-11-11 the owner reported that it does not work, so the staff page `/naplata/` is the way to charge cards). cPay also has newer payment addresses (`vpos.cpay.com.mk`) with a different checksum; CaSys recommends them for existing merchants "in coordination with CaSys". The site stays on the address and checksum that worked for the hotel in October 2026 until CaSys asks for the change.
  - On 2026-11-09 Komercijalna banka confirmed that the hotel's cPay merchant is registered to www.oazahotel.com.mk with `/cpay/ok.php` and `/cpay/fail.php`.
  - The payment request to cPay must be a POST with the `application/x-www-form-urlencoded` content type. CaSys asked for this in June and September 2026, and the old Wix page was changed to it in July 2026. The staff page sends it that way.
- The effects decision. Review the effects at any page with `?fx=panel`.
