-- =====================================================================
-- queries.sql  -  SQL concepts demonstrated on the railway_reservation database
-- Run schema.sql and sample_data.sql first, then run these queries one by one
-- (select a query and execute it) in phpMyAdmin or MySQL Workbench.
-- Queries that change data are marked [CHANGES DATA].
-- =====================================================================

USE railway_reservation;

-- =====================================================================
-- 1. SELECT with WHERE
-- =====================================================================

-- 1.1 Confirmed reservations only
SELECT reservation_id, passenger_id, train_id, seat_number, total_fare, journey_date
FROM reservation
WHERE status = 'Confirmed';

-- 1.2 Fares between 1500 and 2500 on trains 1 or 2 (AND / OR / BETWEEN / IN)
SELECT reservation_id, train_id, total_fare
FROM reservation
WHERE total_fare BETWEEN 1500 AND 2500
  AND train_id IN (1, 2);

-- 1.3 Passengers whose name starts with 'A' (LIKE); age is calculated, not stored
SELECT passenger_id, name, dob, TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS age
FROM passenger
WHERE name LIKE 'A%';

-- =====================================================================
-- 2. INSERT                                                [CHANGES DATA]
-- =====================================================================

-- Add a test passenger (passenger_id is generated automatically)
INSERT INTO passenger (name, email, phone, gender, dob)
VALUES ('Test Passenger', 'test.passenger@example.com', '9000000000', 'Other', '2000-01-01');

-- Check that the row was added
SELECT * FROM passenger WHERE email = 'test.passenger@example.com';

-- =====================================================================
-- 3. UPDATE                                                [CHANGES DATA]
-- =====================================================================

-- Change the phone number of the test passenger
UPDATE passenger
SET phone = '9000000001'
WHERE email = 'test.passenger@example.com';

SELECT passenger_id, name, phone FROM passenger WHERE email = 'test.passenger@example.com';

-- =====================================================================
-- 4. DELETE on a test record                               [CHANGES DATA]
-- =====================================================================

-- Safe because this passenger has no reservations (no foreign key points to it)
DELETE FROM passenger
WHERE email = 'test.passenger@example.com';

-- Deleting a passenger who HAS reservations fails because of the foreign key
-- (ON DELETE RESTRICT). Try it to see error 1451, then nothing is deleted:
-- DELETE FROM passenger WHERE passenger_id = 1;

-- =====================================================================
-- 5. INNER JOIN  (only rows that match on both sides)
-- =====================================================================

-- Reservations with the passenger name
SELECT r.reservation_id, p.name AS passenger_name, r.journey_date, r.total_fare
FROM reservation r
INNER JOIN passenger p ON p.passenger_id = r.passenger_id
ORDER BY r.reservation_id;

-- =====================================================================
-- 6. LEFT JOIN  (keeps rows with no match; unmatched columns are NULL)
-- =====================================================================

-- All passengers, including those who never booked (reservation columns are NULL)
SELECT p.passenger_id, p.name, r.reservation_id
FROM passenger p
LEFT JOIN reservation r ON r.passenger_id = p.passenger_id
ORDER BY p.passenger_id;

-- =====================================================================
-- 7. Multiple-table join (reservation + passenger + train + 2x station)
-- =====================================================================

-- The same station table is joined twice, once for the source and once for the destination
SELECT r.reservation_id,
       p.name           AS passenger_name,
       t.train_name,
       src.station_name AS source_station,
       dst.station_name AS destination_station,
       r.journey_date,
       r.status
FROM reservation r
INNER JOIN passenger p ON p.passenger_id  = r.passenger_id
INNER JOIN train t     ON t.train_id      = r.train_id
INNER JOIN station src ON src.station_id  = r.src_station_id
INNER JOIN station dst ON dst.station_id  = r.dest_station_id
ORDER BY r.reservation_id;

-- Train search: schedules on a date where the source comes BEFORE the destination.
-- Change the station codes and the date offset to try other routes.
SELECT t.train_number, t.train_name,
       h1.departure_time AS source_departure,
       h2.arrival_time   AS destination_arrival,
       ts.journey_date
FROM train_schedule ts
INNER JOIN train t       ON t.train_id = ts.train_id
INNER JOIN train_halt h1 ON h1.schedule_id = ts.schedule_id
INNER JOIN station s1    ON s1.station_id = h1.station_id AND s1.station_code = 'MMCT'
INNER JOIN train_halt h2 ON h2.schedule_id = ts.schedule_id
INNER JOIN station s2    ON s2.station_id = h2.station_id AND s2.station_code = 'NDLS'
WHERE ts.journey_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
  AND h1.seq_number < h2.seq_number;

-- =====================================================================
-- 8. GROUP BY
-- =====================================================================

-- Number of reservations in each status
SELECT status, COUNT(*) AS total
FROM reservation
GROUP BY status;

-- =====================================================================
-- 9. HAVING  (filters groups after aggregation; WHERE filters rows before)
-- =====================================================================

-- Trains with more than 2 reservations
SELECT t.train_name, COUNT(r.reservation_id) AS total_reservations
FROM train t
INNER JOIN reservation r ON r.train_id = t.train_id
GROUP BY t.train_id, t.train_name
HAVING COUNT(r.reservation_id) > 2;

-- =====================================================================
-- 10. ORDER BY
-- =====================================================================

-- Most expensive reservations first; ties broken by the earlier journey
SELECT reservation_id, total_fare, journey_date
FROM reservation
ORDER BY total_fare DESC, journey_date ASC;

-- =====================================================================
-- 11. Aggregate functions: COUNT, SUM, AVG, MIN, MAX
-- =====================================================================

-- Overall fare statistics for all reservations
SELECT COUNT(*)                 AS total_reservations,
       SUM(total_fare)          AS total_fare_value,
       ROUND(AVG(total_fare),2) AS average_fare,
       MIN(total_fare)          AS cheapest,
       MAX(total_fare)          AS most_expensive
FROM reservation;

-- Revenue from successful payments only
SELECT COUNT(*) AS successful_payments, SUM(amount) AS total_revenue
FROM payment
WHERE payment_status = 'Success';

-- =====================================================================
-- 12. Subqueries
-- =====================================================================

-- 12.1 Scalar subquery: reservations above the average fare
SELECT reservation_id, total_fare
FROM reservation
WHERE total_fare > (SELECT AVG(total_fare) FROM reservation);

-- 12.2 IN subquery: passengers who have at least one cancelled reservation
SELECT passenger_id, name
FROM passenger
WHERE passenger_id IN (SELECT passenger_id FROM reservation WHERE status = 'Cancelled');

-- 12.3 NOT EXISTS (correlated subquery): trains nobody has booked
SELECT t.train_number, t.train_name
FROM train t
WHERE NOT EXISTS (SELECT 1 FROM reservation r WHERE r.train_id = t.train_id);

-- 12.4 Subquery in the SELECT list: reservations per passenger
SELECT p.name,
       (SELECT COUNT(*) FROM reservation r WHERE r.passenger_id = p.passenger_id) AS reservations
FROM passenger p
ORDER BY reservations DESC;

-- =====================================================================
-- 13. Views
-- =====================================================================

-- The view v_reservation_details is created in schema.sql. It stores the 5-table join,
-- so it can be queried like a table.
SELECT * FROM v_reservation_details ORDER BY reservation_id;

SELECT passenger_name, train_name, source_station, destination_station, total_fare
FROM v_reservation_details
WHERE reservation_status = 'Confirmed' AND total_fare > 2000;

-- How the view was defined (run this to see the definition)
SHOW CREATE VIEW v_reservation_details;

-- =====================================================================
-- 14. Indexes
-- =====================================================================

-- 14.1 List the indexes of a table (PRIMARY, UNIQUE and normal indexes)
SHOW INDEX FROM reservation;

-- 14.2 EXPLAIN shows whether an index is used. The 'key' column should show
--      idx_reservation_journey_date for this query.
EXPLAIN SELECT reservation_id, total_fare
FROM reservation
WHERE journey_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY);

-- 14.3 Create and drop an index [CHANGES STRUCTURE]. Searching passengers by name prefix
--      benefits from an index on name. (The sample table is small, so MySQL may still
--      choose a full scan; the index matters on large tables.)
CREATE INDEX idx_demo_passenger_name ON passenger (name);
EXPLAIN SELECT * FROM passenger WHERE name LIKE 'Ar%';
DROP INDEX idx_demo_passenger_name ON passenger;

-- Indexes created in schema.sql and why:
--   uq_train_number               fast lookup by train number and no duplicate numbers
--   uq_station_code              fast lookup by station code and no duplicate codes
--   idx_schedule_date            train search filters schedules by journey_date
--   idx_reservation_journey_date date based reservation searches and reports
--   idx_payment_date             monthly revenue report groups by payment date
--   uq_active_seat               stops two active bookings of one seat (also an index)

-- =====================================================================
-- 15. Transactions: START TRANSACTION, COMMIT, ROLLBACK
--     (run each block as a whole, from START TRANSACTION to COMMIT/ROLLBACK)
-- =====================================================================

-- 15.1 ROLLBACK: undo changes made inside a transaction            [CHANGES DATA]
START TRANSACTION;
UPDATE reservation SET total_fare = total_fare + 500 WHERE reservation_id = 3;
SELECT reservation_id, total_fare FROM reservation WHERE reservation_id = 3;   -- shows the raised fare
ROLLBACK;
SELECT reservation_id, total_fare FROM reservation WHERE reservation_id = 3;   -- original fare is back

-- 15.2 COMMIT: make changes permanent                              [CHANGES DATA]
START TRANSACTION;
INSERT INTO station (station_name, station_code, city, state)
VALUES ('Test Station', 'TST', 'Test City', 'Test State');
COMMIT;
SELECT * FROM station WHERE station_code = 'TST';
-- Clean up the test row
DELETE FROM station WHERE station_code = 'TST';

-- 15.3 Atomic payment + confirmation, the same idea the PHP payments page uses.
--      Reservation 7 is Pending in the sample data.                  [CHANGES DATA]
START TRANSACTION;
INSERT INTO payment (reservation_id, transaction_id, payment_method, amount, payment_status)
SELECT reservation_id, 'TXN-SQL-DEMO-1', 'UPI', total_fare, 'Success'
FROM reservation
WHERE reservation_id = 7 AND status = 'Pending';
UPDATE reservation SET status = 'Confirmed' WHERE reservation_id = 7 AND status = 'Pending';
SELECT reservation_id, status FROM reservation WHERE reservation_id = 7;      -- Confirmed (not yet permanent)
ROLLBACK;   -- use COMMIT instead to keep it
SELECT reservation_id, status FROM reservation WHERE reservation_id = 7;      -- Pending again

-- 15.4 Constraints protecting the data (each statement should FAIL with an error)
-- Duplicate email (UNIQUE):
--   INSERT INTO passenger (name,email,phone,gender,dob) VALUES ('X','aarav.sharma@example.com','9000000002','Male','2000-01-01');
-- Same source and destination (CHECK):
--   INSERT INTO reservation (passenger_id,train_id,src_station_id,dest_station_id,seat_number,total_fare,journey_date)
--   VALUES (1,1,2,2,50,1000,CURDATE());
-- Seat already booked (UNIQUE on train, date, active seat). Reservation 3 holds seat 7 on train 1:
--   INSERT INTO reservation (passenger_id,train_id,src_station_id,dest_station_id,seat_number,total_fare,journey_date)
--   SELECT 5, train_id, 2, 1, seat_number, 1000, journey_date FROM reservation WHERE reservation_id = 3;
