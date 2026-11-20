# Don Felix Sizzling House - Smart Inventory System

Web Development 2 term project (OLFU Antipolo). Tracks ingredient stock, deducts it automatically when orders are charged, and keeps a record of every change.

## What it does
- **Cashier**: tap dishes to build one order, leave out ingredients ("no egg"), see plates left, charge. Orders are numbered.
- **Kitchen**: log deliveries, waste, and shelf counts. Low items show first.
- **Owner**: dashboard (sales today, stock levels, buy list, plates you can still make), order history with **Void** (stock goes back), activity log of who changed what, manage ingredients / menu / recipes / accounts, and a **backup download**.
- Recipes drive everything: charging an order deducts each ingredient's recipe amount automatically.

## Setup (XAMPP)
1. Copy this folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. In phpMyAdmin create a database named `don_felix_inventory` and import `schema.sql`.
4. Open `http://localhost/don-felix-inventory-main/`.

Upgrading from the first version? Just copy the new files over. The first page load adds the new tables and log columns automatically. Your data is kept.

## Good to know
- Times use Philippine time (set in `db.php`).
- `db.php` has `DEBUG`. Keep it `true` while testing; set it to `false` when the system goes live so visitors never see technical details.
- Public sign-up creates Kitchen or Cashier accounts only. The owner can reset passwords and delete accounts under **Manage**.
- Change the default passwords before real use, and use a dedicated MySQL user instead of `root` with no password.
- Download a backup from the owner Dashboard regularly.

## Tech
HTML, CSS, JavaScript, PHP (prepared statements, sessions, CSRF tokens), MySQL / MariaDB.
