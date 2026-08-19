# SHRA — Stallion Horse Riding Academy

A mobile-first management app for the academy: riders, packages, session
attendance, billing with QR payments, trainers and leads.

Plain PHP + MySQL. **No Composer, no build step, no external services** — upload
the folder to any PHP VPS or cPanel host and run the installer.

---

## 1. Requirements

| | |
|---|---|
| PHP | 8.0 or newer, with `pdo_mysql` (GD optional — only for PNG QR downloads) |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Web server | Apache (an `.htaccess` is included) or Nginx (see §6) |

## 2. Install

1. Upload the contents of `shra/` to your web root, e.g. `/var/www/html/`
   (or a subfolder such as `/var/www/html/app/`).
2. Make sure PHP can write `inc/` once, so the installer can save the DB
   credentials: `chmod 755 inc`
3. Open `https://your-domain/install.php` in a browser and fill in:
   - MySQL host, database name, user and password (the database is created
     automatically if the user has permission)
   - the administrator name, email and password
4. **Delete `install.php` from the server.** The Settings page warns you until
   you do.
5. Sign in at `https://your-domain/login.php`.

The installer creates every table and loads your published fee card — guest
rides, 2-week, monthly, quarterly and annual packages for children and adults,
at both the original and the 30 %-off prices.

## 3. First things to set up

Go to **Settings** and fill in:

- **UPI ID** — required for the scan-to-pay QR codes on invoices, the rider
  portal and the counter poster. Without it, bills still work but show no UPI QR.
- **Public site URL** — leave blank to auto-detect; set it if you sit behind a
  proxy or CDN so QR links point at the right domain.
- **Tax** — set to 0 if you do not charge GST on riding fees.
- **Invoice prefix** and the terms printed on every bill.

Then add your **Trainers**, and print the counter QR from
**Self-Billing QR** (there is a rider-portal poster on the same page).

To use your own logo, replace these two files — everything else (sidebar,
invoices, posters, portal) follows automatically:

```
assets/img/logo-mark.svg
assets/img/favicon.svg
```

Keep the `viewBox="0 0 200 200"` and use `fill="currentColor"` so the mark
picks up the surrounding colour.

## 4. Who can see what

| Role | Can do |
|---|---|
| **Administrator** | Everything, including staff logins and settings |
| **Manager** | Everything except staff logins and settings |
| **Front desk / Employee** | Riders, guest rides, attendance, packages, billing, leads |
| **Trainer** | Attendance and rider profiles only — no money, no leads |

Trainers see their own session counts on the dashboard, never academy revenue.

## 5. How the day-to-day flows work

**Walk-in guest** → *Guest Ride*. One screen registers the rider, logs the ride,
raises the bill and takes payment. New riders are auto-classified child/adult
from date of birth so the right fee applies.

**Package rider** → *New package* on the rider's profile. Choose a plan; the
sessions, validity, price and invoice are filled in for you. Each *Mark
attendance* burns exactly one session; *Undo* credits it back. The package
closes itself when the last session is used or the validity date passes.

**Billing by staff** → *New bill*, multiple line items, discount, tax, part
payments. Every invoice carries a QR that opens a public payment page, plus a
UPI QR for the exact balance. *Send on WhatsApp* pre-fills a message with the
link.

**Billing by the customer** → they scan the counter QR, enter name and mobile,
pick a plan, and pay by UPI. The rider record and invoice are created for them,
and a package plan starts the subscription automatically. Their payment lands as
**pending** and appears in *Payments* for the desk to verify — a self-declared
payment never settles an invoice on its own.

**Leads** → capture, assign, log calls with the next follow-up date; overdue
follow-ups surface on the dashboard. *Convert to rider* carries the details over
and links the two records.

**Riders** get their own portal (mobile number + 4-digit PIN from the desk):
sessions remaining, ride history with trainer notes, and their bills.

## 6. Nginx instead of Apache

`.htaccess` is ignored by Nginx, so add this to the server block:

```nginx
location ^~ /inc/ { deny all; return 404; }
location = /install.php { allow 203.0.113.4; deny all; }  # your IP, then delete the file
```

## 7. Backups

Everything lives in the database. A nightly dump is enough:

```bash
mysqldump -u USER -p DBNAME | gzip > shra-$(date +%F).sql.gz
```

## 8. Security notes

- Passwords are stored with `password_hash()` (bcrypt); sessions are HttpOnly
  and SameSite=Lax, and the session ID is regenerated on login.
- Every state-changing form is CSRF-protected; every query is a prepared
  statement.
- Public payment pages are reachable only via a 20-character random token, and
  a wrong token returns 404 — invoices cannot be enumerated.
- The self-billing page never echoes back stored customer details, so it cannot
  be used to probe your rider list from a phone number.
- Serve the site over HTTPS. Self-billing and the rider portal are public by
  design; the rest requires a login.
