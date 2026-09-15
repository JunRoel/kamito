# KAMITO — Landing Page (HTML/CSS/JS + PHP/MySQL on XAMPP)

A 1:1 rebuild of the Kamito landing page PDF, wired up to a real PHP/MySQL
backend so the "Pre-Order", "Join" (newsletter), and comparison-table /
testimonial content are backed by an actual database instead of hardcoded
text.

## What's inside

```
kamito/
├── index.php                 Main page (PHP reads specs from MySQL)
├── css/style.css             All styling
├── js/script.js              Nav, counters, modal, fetch() calls
├── php/
│   ├── db_connect.php        MySQL connection (edit credentials here)
│   ├── preorder_handler.php  Saves the "Pre-Order Now" form to `preorders`
│   ├── newsletter_handler.php Saves the footer email form to `newsletter_signups`
│   └── get_testimonials.php  Returns testimonials as JSON
└── database/
    └── kamito_db.sql         Full schema + seed data
```

## Set up with XAMPP

1. **Copy the folder.** Move the entire `kamito/` folder into your XAMPP
   `htdocs` directory, e.g.:
   - Windows: `C:\xampp\htdocs\kamito`
   - macOS: `/Applications/XAMPP/htdocs/kamito`
   - Linux: `/opt/lampp/htdocs/kamito`

2. **Start Apache and MySQL** from the XAMPP Control Panel.

3. **Create the database.**
   - Open `http://localhost/phpmyadmin`
   - Click **Import**, choose `database/kamito_db.sql`, and run it.
   - This creates the `kamito_db` database with `paddles`, `preorders`,
     `newsletter_signups`, and `testimonials` tables, plus seed rows for
     the Series J-PRO vs. conventional-paddle comparison and the two
     athlete quotes.

4. **Check credentials.** Default XAMPP MySQL is `root` with no password —
   already set in `php/db_connect.php`. Edit `DB_HOST` / `DB_USER` /
   `DB_PASS` there if your setup differs.

5. **Visit the site:**
   `http://localhost/kamito/index.php`

If MySQL isn't running or the database hasn't been imported yet, the page
still renders (it falls back to static spec data) and shows a small note
under the comparison table so you know to finish the DB setup.

## Features beyond a static clone

- **Pre-order form** (opens from "Order Series J-PRO", "Shop Series
  J-PRO", and "Pre-Order Now") — submits via `fetch()` as JSON to
  `php/preorder_handler.php`, which validates and inserts into the
  `preorders` table.
- **Newsletter signup** in the footer — posts to
  `php/newsletter_handler.php`, stored in `newsletter_signups` with a
  unique constraint on email so duplicates are handled gracefully.
- **Live spec table** — the "Outperforming the Standard" table is rendered
  server-side in `index.php` from the `paddles` table, so updating a row
  in MySQL updates the page with no code changes.
- **Live testimonials** — `js/script.js` fetches
  `php/get_testimonials.php` on load and replaces the static quote cards
  if the database is reachable, so new athlete quotes can be added purely
  through SQL.
- **Animated stat counters** (2400 RPM / +30% / 0.14mm) that count up
  when scrolled into view.
- Responsive layout down to mobile, with a slide-down nav menu.

## Notes on visuals

The original PDF uses photographed paddle and material renders. Since
there's no product photography to license here, the hero paddle cluster
and material swatches are built as layered CSS/SVG gradients that mirror
the composition and color story (cool-to-lime gradient paddle fan,
carbon-weave / honeycomb / brushed-throat swatches). Swap in real product
photography by replacing the `.paddle`, `.swatch-*`, and `.avatar-*`
elements with `<img>` tags — the layout won't need to change.
