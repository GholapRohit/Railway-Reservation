# Railway Reservation System (DBMS mini project)

React + Node.js/Express + MySQL. Eight tables from the ER diagram: Passenger, Train, Train_Schedule, Train_Halt, Station, Reservation, Payment, Cancellation.

```
client/   React (Vite) front end          server/   Express API (db.js, server.js, routes.js)
database/ schema.sql, sample_data.sql, queries.sql
```

## Setup (10 steps)

Needs Node.js 18+ and MySQL 8.0.16+ (CHECK constraints are enforced from 8.0.16; MariaDB 10.4+ also works).

1. **Install MySQL** and make sure it is running.
2. **Open a terminal in the project folder** (`railway-reservation-system`).
3. **Create the tables:** `mysql -u root -p < database/schema.sql`
4. **Load the sample data:** `mysql -u root -p < database/sample_data.sql`
5. **Install the server packages:** `cd server && npm install`
6. **Create the server settings file:** `cp .env.example .env` (Windows: `copy .env.example .env`), then open `server/.env` and put your MySQL password in `DB_PASSWORD`.
7. **Start the API:** `npm start` (still inside `server/`). Expect `Connected to MySQL database "railway_reservation".` Leave it running.
8. **Install the client packages** in a second terminal: `cd client && npm install`
9. **Start the client:** `npm run dev`
10. **Open** http://localhost:5173

The API runs on port 5000. If it is elsewhere, create `client/.env` with `VITE_API_URL=http://localhost:5000/api`.
To reset the data, run steps 3 and 4 again (`schema.sql` drops and recreates the tables).

## Pages

| Page | What it does |
|---|---|
| Home | Counts of passengers, trains and reservations (`COUNT(*)`). |
| Passengers | Add, list, search by name. Age is computed from the date of birth with `TIMESTAMPDIFF`, not stored. |
| Trains | Train list and add form. **Search:** source, destination and date; shows only trains where both stations belong to the same schedule, the source comes before the destination (`seq_number`) and the date matches. |
| Reservations | Create (passenger, train and date, source, destination, seat), list, **Pay** and **Cancel**. The payment's transaction ID and the refund are shown in the table. |
| SQL Reports | Six fixed reports with the SQL text, an explanation and the result table. |

## Rules implemented on the server

- **Added column `reservation.schedule_id`.** The ER diagram links a reservation only to a train and a date, which cannot tell two schedules of one train on one day apart. The column (foreign key to `train_schedule`) names the exact journey. `train_id` and `journey_date` stay because the ER lists them; the API copies them from the schedule.
- **Fare** = stations travelled x rate per train type (Rajdhani 900, Duronto 800, Superfast 600, Express 500, Intercity 400, Passenger 250). The server calculates it; a different fare from the browser is rejected. Example: Mumbai Central to New Delhi on the Rajdhani = 3 x 900 = Rs. 2700.
- **Create:** status starts as `Pending`; journey date comes from the schedule.
- **Pay (simulated, no real money):** one transaction inserts the payment (unique transaction ID) and changes Pending to Confirmed. A second payment is blocked.
- **Cancel:** only `Confirmed` reservations with a journey date of today or later. One transaction inserts the cancellation (refund = 80% of the amount paid), sets the status to `Cancelled`. A second cancellation is blocked.
- **Seats:** the schedule row is locked (`SELECT ... FOR UPDATE`) during booking, and a generated column with `UNIQUE (schedule_id, seat_hold)` stops double booking even if the application check is bypassed. A cancelled seat can be booked again.
- **Errors:** the API returns `{success, message}`; raw SQL errors are only printed in the server console. All SQL values use `?` placeholders. Submit buttons are disabled while a request runs.

## Database objects

- View `reservation_details_view` (reservation + passenger + train + two stations); used by the reservations list and report 6.
- Indexes: `idx_schedule_date`, `idx_reservation_journey_date`, `idx_payment_date` plus the unique keys. `database/queries.sql` shows `EXPLAIN` before and after an index.
- `database/queries.sql`: practice queries (joins, GROUP BY, subqueries, view, indexes, transaction with ROLLBACK/COMMIT). Run it in MySQL Workbench or `mysql`.

## SQL reports

1. Reservation Details: INNER JOIN of 3 tables.
2. Reservation Count per Train: LEFT JOIN, COUNT, GROUP BY (shows 0 for trains with no reservation).
3. Total Revenue: SUM, COUNT, AVG, MIN, MAX of successful payments.
4. Passengers Without Reservations: LEFT JOIN ... IS NULL.
5. Above-Average Fare: subquery with AVG.
6. Reservation Status Summary: GROUP BY on the view.

Only the report number is sent from the browser; the SQL text is fixed in `server/routes.js`, so arbitrary SQL cannot be run.

## Demo values (with the sample data)

10 passengers, 5 trains, 10 stations, 10 schedules (2 to 8 days from today), 10 reservations (6 Confirmed, 2 Pending, 2 Cancelled), 2 cancellations. Total revenue = 11,800 (8 successful payments, gross: refunds are not subtracted); report 4 lists Arjun Desai and Pooja Kulkarni; report 2 shows train 22119 with 0; report 5 returns 5 reservations (average fare 1430).
Demo ideas: searching New Delhi to Mumbai Central on any date returns nothing (wrong direction), while Mumbai Central to New Delhi on the date of a Rajdhani schedule finds it. Pay reservation 6 or 7 (Pending), then cancel a Confirmed one and watch the 80% refund.

## Limitations

- A `Pending` reservation cannot be cancelled (only Confirmed ones can, per the project rules) and it keeps its seat. The simple fix is to cancel it in SQL (`UPDATE reservation SET status='Cancelled' ...`).
- Payments are simulated and always succeed; failed payment rows exist only in the sample data.
- No login, no edit/delete screens, seats are numbered 1 to 72 without a seat map.
