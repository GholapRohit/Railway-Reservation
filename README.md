# Railway Reservation System (PHP + MySQL)

A simple web-based railway reservation system for a college DBMS mini project. The goal is to show SQL concepts
(tables and keys, CRUD, joins, aggregates, GROUP BY/HAVING, subqueries, views, indexes, transactions and constraints)
through a small PHP website. Payments are **simulated**; no real payment gateway is used.

**Stack:** HTML5, CSS3, Bootstrap 5 (loaded from a CDN, so an internet connection is needed for styling), core PHP (PDO + prepared statements), MySQL / MariaDB (InnoDB).

## Project files

```
railway-reservation/
├── index.php            Home page: COUNT() statistics and links
├── passengers.php       Passenger CRUD, search by ID/name, age calculated from DOB
├── trains.php           Train CRUD, view schedules and routes, add/delete schedules
├── search_trains.php    Search trains between two stations on a date
├── stations.php         Station CRUD and search by name/code
├── reservations.php     Create reservation + list reservations (uses the view)
├── payments.php         Simulated payment (transaction)
├── cancellations.php    Cancel with refund + history (transaction)
├── sql_reports.php      12 fixed SQL reports (10 required + 2 extra)
├── db.php               Database settings, PDO connection, helper functions
├── header.php           Shared page top + navigation bar
├── footer.php           Shared page bottom
├── style.css            Small style additions
├── database/
│   ├── schema.sql       Database, 8 tables, constraints, indexes, view
│   ├── sample_data.sql  Demonstration data
│   └── queries.sql      Standalone SQL examples for all required concepts
└── README.md
```

## 1. Installation and setup

1. **Install XAMPP** (https://www.apachefriends.org) or any PHP 8 + MySQL 8 / MariaDB 10.4+ environment. MySQL 8.0.16+ or MariaDB 10.4+ is required because the schema uses CHECK constraints and generated columns.
2. **Start Apache and MySQL** from the XAMPP Control Panel.
3. **Copy the project folder** `railway-reservation` into XAMPP's web folder:
   - Windows: `C:\xampp\htdocs\railway-reservation`
   - macOS: `/Applications/XAMPP/htdocs/railway-reservation`
   - Linux: `/opt/lampp/htdocs/railway-reservation`
4. **Import the database** (do the files in this order). `schema.sql` creates the database `railway_reservation` itself, so do not select a database first.
   - **phpMyAdmin:** open http://localhost/phpmyadmin → click **Import** (top menu, at server level) → choose `database/schema.sql` → **Import**. Repeat with `database/sample_data.sql`.
   - **MySQL Workbench:** *File → Open SQL Script…* → pick `schema.sql` → click the lightning-bolt **Execute** button. Repeat with `sample_data.sql`.
   - **Command line:** `mysql -u root < database/schema.sql` then `mysql -u root < database/sample_data.sql`.
5. **Check the settings in `db.php`.** XAMPP defaults (user `root`, empty password, host `localhost`) already work. Change `DB_USER` / `DB_PASS` if your MySQL has a password. `APP_ENV` is `development` (shows technical error details); set it to `production` to hide them.
6. **Open the website:** http://localhost/railway-reservation/

If you re-import `schema.sql`, all tables are rebuilt and existing data is deleted, so import `sample_data.sql` again afterwards.

> **About dates:** the sample schedules are created relative to today (for example "today + 2 days"), so there are always upcoming trains to search. The search page shows the dates that have trains. In the test cases below, "D+2" means *today plus 2 days*.

## 2. Database design

| Table | Purpose | Key points |
|---|---|---|
| `passenger` | People who travel | `email` UNIQUE; phone CHECK (10-15 digits); **no age column** (age = `TIMESTAMPDIFF(YEAR, dob, CURDATE())`) |
| `train` | Trains | `train_number` UNIQUE |
| `station` | Stations | `station_code` UNIQUE |
| `train_schedule` | One train running on one date | UNIQUE (`train_id`, `journey_date`) stops duplicate schedules |
| `train_halt` | Ordered stops of a schedule | UNIQUE (`schedule_id`, `seq_number`) and UNIQUE (`schedule_id`, `station_id`); `seq_number` gives the route order; `halt_duration` is in minutes |
| `reservation` | A booking | CHECK source ≠ destination, fare > 0, seat 1-72; generated column `seat_hold` + UNIQUE (`train_id`, `journey_date`, `seat_hold`) prevents double-booking a seat |
| `payment` | Payment attempts | One reservation can have many rows (e.g. Failed then Success) but at most **one Success** (generated column `success_key` + UNIQUE) |
| `cancellation` | Cancelled bookings | UNIQUE (`reservation_id`): 0 or 1 cancellation per reservation (optional entity) |

**Relationships:** passenger 1-N reservation; train 1-N schedule; schedule 1-N halt; station 1-N halt; train 1-N reservation; station 1-N reservation (as source and as destination); reservation 1-N payment (at most one successful); reservation 1-0..1 cancellation.

**Foreign-key rules:** halts are deleted together with their schedule (`ON DELETE CASCADE`). Every other foreign key is `RESTRICT` on delete, so you cannot delete a passenger, train or station that is still used, and booking history is never lost. A cancelled reservation keeps its row (status `Cancelled`).

**Status values**

| Where | Values | Meaning |
|---|---|---|
| `reservation.status` | `Pending` | booked, waiting for payment (the seat is held) |
| | `Confirmed` | simulated payment succeeded |
| | `Cancelled` | cancelled; the seat becomes free again |
| `payment.payment_status` | `Success`, `Failed` | result of the simulated payment |
| `train_schedule.status` | `Scheduled`, `Running`, `Completed`, `Cancelled` | only `Scheduled` can be booked |

**Seat protection:** `seat_hold` equals `seat_number` while a reservation is Pending or Confirmed and is `NULL` once Cancelled. MySQL allows many NULLs in a UNIQUE index, so cancelled seats can be rebooked, but two active bookings of the same seat are rejected by the database even if two requests arrive at the same moment.

**Indexes** (besides primary keys and foreign keys)

| Index | Why it is useful |
|---|---|
| `uq_train_number` (UNIQUE) | fast lookup by train number and no duplicate numbers |
| `uq_station_code` (UNIQUE) | fast lookup by station code and no duplicate codes |
| `idx_schedule_date` | train search filters `train_schedule` by `journey_date` |
| `idx_reservation_journey_date` | date-based reservation searches and reports |
| `idx_payment_date` | the monthly revenue report groups by payment date |
| `uq_active_seat` (UNIQUE) | prevents double booking (and is an index on train + date + seat) |

**View:** `v_reservation_details` joins reservation, passenger, train and the station table twice (source and destination). It is used by `reservations.php`, `payments.php`, `cancellations.php` and SQL reports 11-12.

## 3. Business rules

- **Reservation:** needs an existing passenger, train and two different stations; a `Scheduled` schedule for that train and date; both stations on that schedule's route with source `seq_number` < destination `seq_number`; journey date today or later; seat 1-72 not already held; fare between 0.01 and 50,000. It is saved as `Pending`.
- **Payment (transaction):** only a `Pending` reservation with a journey date today or later can be paid. The reservation row is locked (`SELECT ... FOR UPDATE`), a payment row with a generated unique transaction ID (e.g. `TXN20261025153000A1B2C3`) is inserted, and the reservation becomes `Confirmed`. Any failure rolls everything back. The "make this attempt fail" checkbox records a `Failed` payment and keeps the reservation `Pending`.
- **Cancellation (transaction):** allowed for `Pending` or `Confirmed` reservations whose journey date is today or later.
  - **Refund policy:** a Confirmed reservation gets **80% of the amount paid** back (`REFUND_PERCENT` in `db.php`). A Pending reservation (nothing paid) can be cancelled to release its seat and gets a refund of 0. No real refund is made.
  - Steps: begin → lock and verify the reservation → verify the successful payment → calculate refund → insert cancellation → set status `Cancelled` → commit (rollback on any error).
- **Security:** PDO prepared statements everywhere, `htmlspecialchars()` on all output, CSRF token on every POST form, POST for all changes, redirect after success, confirmation prompts for deletes/cancellations, no raw SQL errors shown (details appear only when `APP_ENV` is `development`), and SQL reports run only from a fixed list inside `sql_reports.php`.

## 4. How to test (with the sample data)

Sample data: 12 passengers, 6 trains, 12 stations, 14 schedules, 14 reservations (8 Confirmed, 3 Pending, 3 Cancelled), 12 payments (11 Success, 1 Failed), 3 cancellations.

| # | Test | Steps | Expected result |
|---|---|---|---|
| 1 | Home counts | Open **Home** | Passengers 12, Trains 6, Reservations 14, Payments 12 |
| 2 | Passenger management | **Passengers**: add *Test User*, `test@example.com`, `9000000000`, DOB `2000-01-01`. Then search `Test`, edit the phone, delete it | Success messages; age is shown from DOB; after delete it disappears |
| 3 | Passenger validation | Add a passenger with email `aarav.sharma@example.com` | Error "A passenger with this email already exists." |
| 4 | Delete protection | Delete *Aarav Sharma* (passenger 1) | Error: record is used by other records; nothing is deleted |
| 5 | Train search (found) | **Trains & Search → Search Trains**: Mumbai Central → New Delhi, date D+2 | 1 result: 12951 Mumbai Delhi Rajdhani Express, departs 16:55, arrives 08:35 |
| 6 | Train search (wrong order) | New Delhi → Mumbai Central, date D+2 | "No trains are available for the selected route and date." (route order is checked with `seq_number`) |
| 7 | Train search (middle stops) | Ahmedabad → New Delhi, D+2 | 12951 (departs 22:45). Chennai Central → Mumbai Central, D+2 finds 12163 |
| 8 | Create reservation | **Reservations**: Diya Patel, train 12951, MMCT → NDLS, date D+2, seat 20, fare 2850 | Success: new reservation with status **Pending** |
| 9 | Duplicate seat | Repeat test 8 with seat 7 (held by reservation 3) | Error "Seat 7 is already booked…" |
| 10 | Other reservation errors | Same source and destination; a past date; New Delhi → Mumbai on 12951; train 12951 on a date with no schedule | Each shows its own clear error message |
| 11 | Simulate payment | **Payments**: choose the new reservation (or Pending #7), method UPI, click **Simulate Payment** | Message with a transaction ID and "Payment status: Success"; reservation becomes **Confirmed**; it is no longer in the dropdown |
| 12 | Failed payment | Pay reservation #9 with the "make this attempt fail" box ticked | A Failed payment is recorded; reservation #9 stays Pending and can still be paid later |
| 13 | Cancel a reservation | **Cancellations**: select reservation #3 (fare 1950, paid), enter a reason, confirm | Refund **Rs. 1,560.00** (80%); reservation #3 status **Cancelled**; a row appears in the history. Seat 7 can be booked again |
| 14 | Cancel a Pending reservation | Cancel reservation #9 | Refund Rs. 0.00 (nothing was paid) |
| 15 | Cancel twice / past journey | Open `cancellations.php?reservation=3` again; try reservation #1 (journey in the past) | Not listed as eligible / "not eligible" message |
| 16 | SQL reports | **SQL Reports**, click each report | See the table below |
| 17 | Standalone SQL | Run `database/queries.sql` section by section in phpMyAdmin or Workbench | Each query returns rows or demonstrates the concept described in its comment |

Tests 8-14 change data. Re-import `sample_data.sql` (after `schema.sql`) to reset.

**Expected SQL report results on a fresh import**

| Report | Expected |
|---|---|
| 1 All Reservations | 14 rows, newest journey first |
| 2 Passenger Booking Details | 14 rows with names instead of IDs |
| 3 Reservation Count per Train | 12951: 4, 12301: 3, 12627: 3, 12163: 2, 12801: 2, **22119: 0** |
| 4 Revenue per Train | total collected: 12951 = 9,750; 12301 = 3,750; 12627 = 2,550; 12163 = 1,750; 12801 = 3,500; 22119 = 0 (refunded: 2,280 / 0 / 720 / 0 / 880 / 0) |
| 5 Most Popular Train | 12951 with 3 confirmed reservations |
| 6 Above-Average Fare | average fare is 1,800.00; 7 reservations: #1, #11, #10, #4, #2, #3, #7 |
| 7 Passengers Without Reservations | Imran Qureshi (11) and Kavya Joshi (12) |
| 8 Monthly Revenue | successful payments grouped by month (the sample payments are 2-45 days old, so 2 or 3 months appear) |
| 9 Train Schedule Details | all upcoming schedules, each route in `seq_number` order |
| 10 Reservation Summary | Confirmed 8, Pending 3, Cancelled 3 |
| 11 Busy Trains (HAVING) | 12951 (4), 12301 (3), 12627 (3) |
| 12 Reservation View | reservations with fare ≥ 2000 (5 rows) |

## 5. Troubleshooting

- **"Cannot connect to the database"**: MySQL is not running, the database was not imported, or the user/password in `db.php` is wrong.
- **Page looks unstyled**: Bootstrap is loaded from `cdn.jsdelivr.net`; check your internet connection (or download Bootstrap and change the two links in `header.php` / `footer.php`).
- **Import error about CHECK or GENERATED**: your MySQL/MariaDB is too old. Use MySQL 8.0.16+ or MariaDB 10.4+ (current XAMPP versions are fine).
- **"Invalid or missing security token"**: reload the page and submit the form again (the session expired).
- **Blank page**: check `php_error_log` in XAMPP (`xampp/php/logs`) or keep `APP_ENV` as `development`.

## 6. Notes and limits

- Seat availability is deliberately simple: a seat number is held for the whole train on that date, not per route segment.
- Times in `train_halt` are time-of-day values. Overnight journeys therefore show an arrival time that looks earlier than the departure; always read the route by `seq_number`.
- There is no login system, because it is not needed for the demonstration.
