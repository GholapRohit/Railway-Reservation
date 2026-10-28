-- =====================================================================
-- queries.sql - SQL concepts demonstrated on the railway_reservation database
-- Run schema.sql and sample_data.sql first, then run the queries one by one
-- (select a query and execute it) in MySQL Workbench or phpMyAdmin.
-- Queries that change data are marked [CHANGES DATA].
-- =====================================================================

USE railway_reservation;

-- =====================================================================
-- 1. SELECT and WHERE
-- =====================================================================

-- 1.1 Confirmed reservations only
SELECT reservation_id, passenger_id, schedule_id, seat_number, total_fare
FROM reservation
WHERE status = 'Confirmed';

-- 1.2 Fares between 1000 and 2000 on trains 1 or 2 (AND, BETWEEN, IN)
SELECT reservation_id, train_id, total_fare
FROM reservation
WHERE total_fare BETWEEN 1000 AND 2000 AND train_id IN (1, 2);

-- 1.3 Passengers whose name starts with 'A' (LIKE). Age is calculated from dob, never stored.
SELECT passenger_id, name, dob, TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS age
FROM passenger
WHERE name LIKE 'A%';

-- =====================================================================
-- 2. INSERT, UPDATE, DELETE                                [CHANGES DATA]
-- =====================================================================

-- 2.1 INSERT a test passenger (passenger_id is generated automatically)
INSERT INTO passenger (name, email, phone, gender, dob)
VALUES ('Test Passenger', 'test.passenger@example.com', '9000000000', 'Other', '2000-01-01');

-- 2.2 UPDATE the phone number of the test passenger
UPDATE passenger SET phone = '9000000001' WHERE email = 'test.passenger@example.com';
SELECT passenger_id, name, phone FROM passenger WHERE email = 'test.passenger@example.com';

-- 2.3 DELETE the test passenger. This is safe because no reservation points to it.
DELETE FROM passenger WHERE email = 'test.passenger@example.com';

-- Deleting a passenger who HAS reservations fails (foreign key, error 1451) and deletes nothing:
-- DELETE FROM passenger WHERE passenger_id = 1;

-- =====================================================================
-- 3. INNER JOIN (only rows that match on both sides)
-- =====================================================================

-- Reservations with the passenger name and the train name
SELECT r.reservation_id, p.name AS passenger_name, t.train_name, r.journey_date, r.total_fare
FROM reservation r
INNER JOIN passenger p ON p.passenger_id = r.passenger_id
INNER JOIN train t     ON t.train_id     = r.train_id
ORDER BY r.reservation_id;

-- Train search: schedules on a date where the source comes BEFORE the destination.
-- h1 and h2 are two halts of the SAME schedule; seq_number gives the route order.
SELECT t.train_number, t.train_name, h1.departure_time AS source_departure,
       h2.arrival_time AS destination_arrival, ts.journey_date
FROM train_schedule ts
INNER JOIN train t       ON t.train_id = ts.train_id
INNER JOIN train_halt h1 ON h1.schedule_id = ts.schedule_id
INNER JOIN station s1    ON s1.station_id = h1.station_id AND s1.station_code = 'MMCT'
INNER JOIN train_halt h2 ON h2.schedule_id = ts.schedule_id
INNER JOIN station s2    ON s2.station_id = h2.station_id AND s2.station_code = 'NDLS'
WHERE ts.journey_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
  AND h1.seq_number < h2.seq_number;

-- =====================================================================
-- 4. LEFT JOIN (keeps rows without a match; their columns are NULL)
-- =====================================================================

-- All passengers, including those who never booked (reservation_id is NULL for them)
SELECT p.passenger_id, p.name, r.reservation_id
FROM passenger p
LEFT JOIN reservation r ON r.passenger_id = p.passenger_id
ORDER BY p.passenger_id;

-- Multiple-table join: the station table is joined twice (source and destination)
SELECT r.reservation_id, p.name AS passenger_name, t.train_name,
       src.station_name AS source_station, dst.station_name AS destination_station, r.status
FROM reservation r
INNER JOIN passenger p ON p.passenger_id = r.passenger_id
INNER JOIN train t     ON t.train_id     = r.train_id
INNER JOIN station src ON src.station_id = r.src_station_id
INNER JOIN station dst ON dst.station_id = r.dest_station_id
ORDER BY r.reservation_id;

-- =====================================================================
-- 5. GROUP BY and HAVING
-- =====================================================================

-- Number of reservations per status
SELECT status, COUNT(*) AS total FROM reservation GROUP BY status;

-- HAVING filters groups after aggregation: trains with more than 2 reservations
SELECT t.train_name, COUNT(r.reservation_id) AS total_reservations
FROM train t
INNER JOIN reservation r ON r.train_id = t.train_id
GROUP BY t.train_id, t.train_name
HAVING COUNT(r.reservation_id) > 2;

-- =====================================================================
-- 6. ORDER BY
-- =====================================================================

-- Most expensive reservations first; ties are broken by reservation_id
SELECT reservation_id, total_fare, journey_date
FROM reservation
ORDER BY total_fare DESC, reservation_id ASC;

-- =====================================================================
-- 7. Aggregate functions: COUNT, SUM, AVG, MIN, MAX
-- =====================================================================

-- Fare statistics of all reservations
SELECT COUNT(*) AS total_reservations, SUM(total_fare) AS total_fare_value,
       ROUND(AVG(total_fare), 2) AS average_fare, MIN(total_fare) AS cheapest, MAX(total_fare) AS most_expensive
FROM reservation;

-- Revenue from successful payments only (the Failed attempt is not counted)
SELECT COUNT(*) AS successful_payments, SUM(amount) AS total_revenue
FROM payment
WHERE payment_status = 'Success';

-- =====================================================================
-- 8. Subqueries
-- =====================================================================

-- 8.1 Scalar subquery: reservations with a fare above the average
SELECT reservation_id, total_fare
FROM reservation
WHERE total_fare > (SELECT AVG(total_fare) FROM reservation);

-- 8.2 IN subquery: passengers who have a cancelled reservation
SELECT passenger_id, name
FROM passenger
WHERE passenger_id IN (SELECT passenger_id FROM reservation WHERE status = 'Cancelled');

-- 8.3 NOT EXISTS (correlated subquery): trains nobody has booked
SELECT t.train_number, t.train_name
FROM train t
WHERE NOT EXISTS (SELECT 1 FROM reservation r WHERE r.train_id = t.train_id);

-- =====================================================================
-- 9. The view reservation_details_view (created in schema.sql)
-- =====================================================================

-- The view stores the 5-table join, so it can be queried like a table
SELECT * FROM reservation_details_view ORDER BY reservation_id;

SELECT passenger_name, train_name, source_station, destination_station, total_fare
FROM reservation_details_view
WHERE reservation_status = 'Confirmed' AND total_fare > 1500;

-- =====================================================================
-- 10. Indexes
-- =====================================================================

-- 10.1 List the indexes of a table (PRIMARY, UNIQUE and normal indexes)
SHOW INDEX FROM reservation;

-- 10.2 EXPLAIN shows which index MySQL uses. The "key" column should show
--      idx_reservation_journey_date for this query (the table is small, so MySQL may still scan it).
EXPLAIN SELECT reservation_id, total_fare
FROM reservation
WHERE journey_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY);

-- 10.3 Create and drop an index                            [CHANGES STRUCTURE]
CREATE INDEX idx_demo_passenger_name ON passenger (name);
EXPLAIN SELECT * FROM passenger WHERE name LIKE 'Ar%';
DROP INDEX idx_demo_passenger_name ON passenger;

-- Indexes created in schema.sql and why:
--   uq_train_number               fast lookup by train number and no duplicate numbers
--   uq_station_code               fast lookup by station code and no duplicate codes
--   idx_schedule_date             train search filters schedules by journey_date
--   idx_reservation_journey_date  reservations searched by journey date
--   idx_payment_date              payments grouped or filtered by date
--   uq_active_seat                stops two active bookings of one seat on a schedule

-- =====================================================================
-- 11. Transactions: START TRANSACTION, COMMIT, ROLLBACK
--     (run each block as a whole, from START TRANSACTION to COMMIT or ROLLBACK)
-- =====================================================================

-- 11.1 ROLLBACK undoes everything done inside the transaction      [CHANGES DATA]
START TRANSACTION;
UPDATE reservation SET total_fare = total_fare + 500 WHERE reservation_id = 3;
SELECT reservation_id, total_fare FROM reservation WHERE reservation_id = 3;   -- fare is 500 higher
ROLLBACK;
SELECT reservation_id, total_fare FROM reservation WHERE reservation_id = 3;   -- original fare is back

-- 11.2 COMMIT makes the changes permanent                          [CHANGES DATA]
START TRANSACTION;
INSERT INTO station (station_name, station_code, city, state)
VALUES ('Test Station', 'TST', 'Test City', 'Test State');
COMMIT;
SELECT * FROM station WHERE station_code = 'TST';
DELETE FROM station WHERE station_code = 'TST';                 -- clean up

-- 11.3 Payment + confirmation in one transaction (the idea used by POST /api/reservations/:id/pay).
--      Reservation 7 is Pending in the sample data.                [CHANGES DATA]
START TRANSACTION;
INSERT INTO payment (reservation_id, transaction_id, payment_method, amount, payment_status)
SELECT reservation_id, 'TXN-SQL-DEMO-1', 'UPI', total_fare, 'Success'
FROM reservation WHERE reservation_id = 7 AND status = 'Pending';
UPDATE reservation SET status = 'Confirmed' WHERE reservation_id = 7 AND status = 'Pending';
SELECT reservation_id, status FROM reservation WHERE reservation_id = 7;       -- Confirmed (not permanent yet)
ROLLBACK;   -- use COMMIT instead to keep the payment
SELECT reservation_id, status FROM reservation WHERE reservation_id = 7;       -- Pending again

-- 11.4 Constraints protecting the data (each statement should FAIL with an error)
-- Duplicate email (UNIQUE):
--   INSERT INTO passenger (name, email, phone, gender, dob)
--   VALUES ('X', 'aarav.sharma@example.com', '9000000002', 'Male', '2000-01-01');
-- Same source and destination (CHECK):
--   INSERT INTO reservation (passenger_id, train_id, schedule_id, src_station_id, dest_station_id, seat_number, total_fare, journey_date)
--   VALUES (1, 1, 1, 2, 2, 50, 1000, CURDATE());
-- Seat already booked (UNIQUE on schedule + active seat). Reservation 3 holds seat 7 on schedule 1:
--   INSERT INTO reservation (passenger_id, train_id, schedule_id, src_station_id, dest_station_id, seat_number, total_fare, journey_date)
--   SELECT 5, train_id, schedule_id, 2, 1, 7, 1000, journey_date FROM reservation WHERE reservation_id = 3;
