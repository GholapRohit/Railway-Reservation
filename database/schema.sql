-- =====================================================================
-- schema.sql  -  Railway Reservation System (DBMS mini project)
-- Compatible with MySQL 8.0.16+ and MariaDB 10.4+ (the version in XAMPP).
-- CHECK constraints and generated columns need these versions.
--
-- Run order: 1) schema.sql  2) sample_data.sql
-- Re-running this file rebuilds the tables (existing data is deleted).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS railway_reservation
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE railway_reservation;

-- Drop in reverse dependency order so the file can be re-run safely.
DROP VIEW  IF EXISTS reservation_details_view;
DROP TABLE IF EXISTS cancellation;
DROP TABLE IF EXISTS payment;
DROP TABLE IF EXISTS reservation;
DROP TABLE IF EXISTS train_halt;
DROP TABLE IF EXISTS train_schedule;
DROP TABLE IF EXISTS station;
DROP TABLE IF EXISTS train;
DROP TABLE IF EXISTS passenger;

-- ---------------------------------------------------------------------
-- 1. PASSENGER
-- Age is a derived attribute in the ER diagram, so it is NOT stored.
-- It is calculated from dob:  TIMESTAMPDIFF(YEAR, dob, CURDATE())
-- ---------------------------------------------------------------------
CREATE TABLE passenger (
    passenger_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(120) NOT NULL,
    phone        VARCHAR(15)  NOT NULL,
    gender       ENUM('Male','Female','Other') NOT NULL,
    dob          DATE NOT NULL,
    PRIMARY KEY (passenger_id),
    UNIQUE KEY uq_passenger_email (email),
    CONSTRAINT chk_passenger_phone CHECK (phone REGEXP '^[0-9]{10,15}$')
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. TRAIN
-- ---------------------------------------------------------------------
CREATE TABLE train (
    train_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    train_number VARCHAR(10)  NOT NULL,
    train_name   VARCHAR(100) NOT NULL,
    train_type   ENUM('Rajdhani','Duronto','Superfast','Express','Intercity','Passenger') NOT NULL,
    PRIMARY KEY (train_id),
    UNIQUE KEY uq_train_number (train_number)      -- also serves as the index for searching by number
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. STATION
-- ---------------------------------------------------------------------
CREATE TABLE station (
    station_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_name VARCHAR(100) NOT NULL,
    station_code VARCHAR(10)  NOT NULL,
    city         VARCHAR(60)  NOT NULL,
    state        VARCHAR(60)  NOT NULL,
    PRIMARY KEY (station_id),
    UNIQUE KEY uq_station_code (station_code)      -- also serves as the index for searching by code
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. TRAIN_SCHEDULE   (one row = one train running on one date)
-- UNIQUE (train_id, journey_date) prevents duplicate schedules.
-- ---------------------------------------------------------------------
CREATE TABLE train_schedule (
    schedule_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    train_id     INT UNSIGNED NOT NULL,
    journey_date DATE NOT NULL,
    status       ENUM('Scheduled','Running','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    PRIMARY KEY (schedule_id),
    UNIQUE KEY uq_schedule_train_date (train_id, journey_date),
    KEY idx_schedule_date (journey_date),          -- speeds up "which trains run on this date?"
    CONSTRAINT fk_schedule_train FOREIGN KEY (train_id)
        REFERENCES train (train_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. TRAIN_HALT   (the ordered list of stations for one schedule)
-- seq_number gives the order along the route (1 = origin).
-- halt_duration is stored in minutes.
-- Times are time-of-day only; always use seq_number for ordering.
-- ---------------------------------------------------------------------
CREATE TABLE train_halt (
    halt_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id    INT UNSIGNED NOT NULL,
    station_id     INT UNSIGNED NOT NULL,
    seq_number     SMALLINT UNSIGNED NOT NULL,
    arrival_time   TIME NULL,                      -- NULL at the origin station
    departure_time TIME NULL,                      -- NULL at the final station
    halt_duration  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (halt_id),
    UNIQUE KEY uq_halt_schedule_seq     (schedule_id, seq_number),   -- unique order within a schedule
    UNIQUE KEY uq_halt_schedule_station (schedule_id, station_id),   -- a station appears once per schedule
    CONSTRAINT chk_halt_seq CHECK (seq_number >= 1),
    CONSTRAINT fk_halt_schedule FOREIGN KEY (schedule_id)
        REFERENCES train_schedule (schedule_id)
        ON DELETE CASCADE ON UPDATE CASCADE,       -- halts have no meaning without their schedule
    CONSTRAINT fk_halt_station FOREIGN KEY (station_id)
        REFERENCES station (station_id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. RESERVATION
-- Status values:  Pending   = booked, waiting for payment (seat is held)
--                 Confirmed = payment succeeded
--                 Cancelled = cancelled (row is kept as history)
--
-- seat_hold is a generated column: it equals seat_number while the
-- reservation is Pending/Confirmed and becomes NULL when Cancelled.
-- The UNIQUE key on (schedule_id, seat_hold) therefore stops two
-- active bookings of the same seat, even if two requests arrive together.
-- Cancelled rows have seat_hold = NULL, so the seat can be booked again.
--
-- schedule_id identifies the exact journey (a schedule is one train on one date), so
-- train_id and journey_date repeat what the schedule already says. They are kept because the
-- ER diagram lists them, and the API always copies them from the selected schedule.
--
-- All foreign keys refuse to delete or change a parent row that is still used
-- (RESTRICT / NO ACTION), so booking history is never lost by accident.
-- ---------------------------------------------------------------------
CREATE TABLE reservation (
    reservation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    passenger_id   INT UNSIGNED NOT NULL,
    train_id       INT UNSIGNED NOT NULL,
    schedule_id    INT UNSIGNED NOT NULL,      -- added to the ER diagram: the exact scheduled journey (see README)
    src_station_id  INT UNSIGNED NOT NULL,
    dest_station_id INT UNSIGNED NOT NULL,
    seat_number    SMALLINT UNSIGNED NOT NULL,
    total_fare     DECIMAL(10,2) NOT NULL,
    journey_date   DATE NOT NULL,
    status         ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
    booking_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    seat_hold      SMALLINT UNSIGNED
                   GENERATED ALWAYS AS (IF(status IN ('Pending','Confirmed'), seat_number, NULL)) STORED,
    PRIMARY KEY (reservation_id),
    UNIQUE KEY uq_active_seat (schedule_id, seat_hold),
    KEY idx_reservation_journey_date (journey_date),   -- speeds up date based searches and reports
    CONSTRAINT chk_reservation_stations CHECK (src_station_id <> dest_station_id),
    CONSTRAINT chk_reservation_fare     CHECK (total_fare > 0),
    CONSTRAINT chk_reservation_seat     CHECK (seat_number BETWEEN 1 AND 72),
    CONSTRAINT fk_reservation_passenger FOREIGN KEY (passenger_id)
        REFERENCES passenger (passenger_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reservation_train FOREIGN KEY (train_id)
        REFERENCES train (train_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reservation_schedule FOREIGN KEY (schedule_id)
        REFERENCES train_schedule (schedule_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    -- src/dest are used in chk_reservation_stations, and MySQL restricts explicit referential
    -- actions on CHECK columns. The default (NO ACTION) behaves exactly like RESTRICT in InnoDB.
    CONSTRAINT fk_reservation_src FOREIGN KEY (src_station_id)
        REFERENCES station (station_id),
    CONSTRAINT fk_reservation_dest FOREIGN KEY (dest_station_id)
        REFERENCES station (station_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. PAYMENT
-- Cardinality decision: ONE reservation can have MANY payment rows
-- (for example a Failed attempt followed by a Success), but at most ONE
-- Success row. success_key is a generated column that equals reservation_id
-- only for Success rows, and its UNIQUE key enforces "one success per reservation".
-- Payment is simulated; no real card data is stored.
-- ---------------------------------------------------------------------
CREATE TABLE payment (
    payment_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    transaction_id VARCHAR(40) NOT NULL,
    payment_method ENUM('UPI','Credit Card','Debit Card','Net Banking') NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    payment_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payment_status ENUM('Success','Failed') NOT NULL,
    success_key    INT UNSIGNED
                   GENERATED ALWAYS AS (IF(payment_status = 'Success', reservation_id, NULL)) STORED,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payment_transaction (transaction_id),
    UNIQUE KEY uq_one_success_per_reservation (success_key),
    KEY idx_payment_reservation (reservation_id),
    KEY idx_payment_date (payment_date),           -- speeds up the monthly revenue report
    CONSTRAINT chk_payment_amount CHECK (amount > 0),
    CONSTRAINT fk_payment_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservation (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. CANCELLATION
-- Cardinality decision: a reservation has 0 or 1 cancellation (optional entity).
-- UNIQUE (reservation_id) enforces the "at most one" part.
-- cancellation_date is an extra helper column so history can be displayed.
-- ---------------------------------------------------------------------
CREATE TABLE cancellation (
    cancellation_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id    INT UNSIGNED NOT NULL,
    reason            VARCHAR(255) NOT NULL,
    refund_amount     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cancellation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cancellation_id),
    UNIQUE KEY uq_cancellation_reservation (reservation_id),
    CONSTRAINT chk_cancellation_refund CHECK (refund_amount >= 0),
    CONSTRAINT fk_cancellation_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservation (reservation_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- VIEW: readable reservation details (used by the Reservations, Payments and Cancellations pages
-- and the "Reservation Details View" SQL report)
-- ---------------------------------------------------------------------
CREATE VIEW reservation_details_view AS
SELECT  r.reservation_id,
        r.passenger_id,
        p.name            AS passenger_name,
        r.train_id,
        t.train_number,
        t.train_name,
        src.station_name  AS source_station,
        src.station_code  AS source_code,
        dst.station_name  AS destination_station,
        dst.station_code  AS destination_code,
        r.journey_date,
        r.seat_number,
        r.total_fare,
        r.status          AS reservation_status,
        r.booking_date
FROM reservation r
INNER JOIN passenger p   ON p.passenger_id = r.passenger_id
INNER JOIN train t       ON t.train_id     = r.train_id
INNER JOIN station src   ON src.station_id = r.src_station_id
INNER JOIN station dst   ON dst.station_id = r.dest_station_id;
