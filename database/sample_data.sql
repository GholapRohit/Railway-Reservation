-- =====================================================================
-- sample_data.sql - demonstration data for the Railway Reservation System
-- Run AFTER schema.sql. All people are fictional.
--
-- Journey dates are written relative to today (CURDATE() + n days), so the demo
-- schedules are always in the future and train search always has results.
-- Fares follow the backend rule: stations travelled x rate per train type
-- (Rajdhani 900, Duronto 800, Superfast 600, Express 500, Intercity 400, Passenger 250).
-- Passengers 9 and 10 have no reservations; train 22119 has none either (used by the reports).
-- =====================================================================

USE railway_reservation;

-- Passengers (10)
INSERT INTO passenger (passenger_id, name, email, phone, gender, dob) VALUES
(1, 'Aarav Sharma', 'aarav.sharma@example.com', '9876500001', 'Male', '1998-04-12'),
(2, 'Diya Patel', 'diya.patel@example.com', '9876500002', 'Female', '2001-09-30'),
(3, 'Rohan Mehta', 'rohan.mehta@example.com', '9876500003', 'Male', '1985-01-22'),
(4, 'Sneha Iyer', 'sneha.iyer@example.com', '9876500004', 'Female', '1992-07-08'),
(5, 'Vikram Singh', 'vikram.singh@example.com', '9876500005', 'Male', '1979-11-03'),
(6, 'Ananya Reddy', 'ananya.reddy@example.com', '9876500006', 'Female', '2003-02-17'),
(7, 'Karan Malhotra', 'karan.malhotra@example.com', '9876500007', 'Male', '1990-06-25'),
(8, 'Meera Nair', 'meera.nair@example.com', '9876500008', 'Female', '1995-12-14'),
(9, 'Arjun Desai', 'arjun.desai@example.com', '9876500009', 'Male', '1988-03-09'),
(10, 'Pooja Kulkarni', 'pooja.kulkarni@example.com', '9876500010', 'Female', '1999-08-19');

-- Trains (5)
INSERT INTO train (train_id, train_number, train_name, train_type) VALUES
(1, '12951', 'Mumbai Delhi Rajdhani Express', 'Rajdhani'),
(2, '12301', 'Howrah Delhi Superfast Express', 'Superfast'),
(3, '12627', 'Bengaluru Delhi Karnataka Express', 'Express'),
(4, '12163', 'Chennai Mumbai Express', 'Express'),
(5, '22119', 'Jaipur Pune Intercity Express', 'Intercity');

-- Stations (10)
INSERT INTO station (station_id, station_name, station_code, city, state) VALUES
(1, 'New Delhi', 'NDLS', 'New Delhi', 'Delhi'),
(2, 'Mumbai Central', 'MMCT', 'Mumbai', 'Maharashtra'),
(3, 'Pune Junction', 'PUNE', 'Pune', 'Maharashtra'),
(4, 'Howrah Junction', 'HWH', 'Kolkata', 'West Bengal'),
(5, 'Chennai Central', 'MAS', 'Chennai', 'Tamil Nadu'),
(6, 'KSR Bengaluru City', 'SBC', 'Bengaluru', 'Karnataka'),
(7, 'Bhopal Junction', 'BPL', 'Bhopal', 'Madhya Pradesh'),
(8, 'Nagpur Junction', 'NGP', 'Nagpur', 'Maharashtra'),
(9, 'Ahmedabad Junction', 'ADI', 'Ahmedabad', 'Gujarat'),
(10, 'Jaipur Junction', 'JP', 'Jaipur', 'Rajasthan');

-- Schedules (10): every train runs on two future dates
INSERT INTO train_schedule (schedule_id, train_id, journey_date, status) VALUES
(1, 1, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(2, 1, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Scheduled'),
(3, 2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(4, 2, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 'Scheduled'),
(5, 3, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Scheduled'),
(6, 3, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Scheduled'),
(7, 4, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(8, 4, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'Scheduled'),
(9, 5, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Scheduled'),
(10, 5, DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'Scheduled');

-- Train halts: ordered by seq_number; halt_duration is in minutes
INSERT INTO train_halt (schedule_id, station_id, seq_number, arrival_time, departure_time, halt_duration) VALUES
(1, 2, 1, NULL, '16:55:00', 0),
(1, 9, 2, '22:35:00', '22:45:00', 10),
(1, 10, 3, '05:10:00', '05:20:00', 10),
(1, 1, 4, '08:35:00', NULL, 0),
(2, 2, 1, NULL, '16:55:00', 0),
(2, 9, 2, '22:35:00', '22:45:00', 10),
(2, 10, 3, '05:10:00', '05:20:00', 10),
(2, 1, 4, '08:35:00', NULL, 0),
(3, 4, 1, NULL, '16:55:00', 0),
(3, 7, 2, '07:20:00', '07:35:00', 15),
(3, 1, 3, '10:50:00', NULL, 0),
(4, 4, 1, NULL, '16:55:00', 0),
(4, 7, 2, '07:20:00', '07:35:00', 15),
(4, 1, 3, '10:50:00', NULL, 0),
(5, 6, 1, NULL, '19:20:00', 0),
(5, 8, 2, '11:50:00', '12:00:00', 10),
(5, 7, 3, '17:30:00', '17:40:00', 10),
(5, 1, 4, '05:00:00', NULL, 0),
(6, 6, 1, NULL, '19:20:00', 0),
(6, 8, 2, '11:50:00', '12:00:00', 10),
(6, 7, 3, '17:30:00', '17:40:00', 10),
(6, 1, 4, '05:00:00', NULL, 0),
(7, 5, 1, NULL, '07:00:00', 0),
(7, 6, 2, '12:20:00', '12:30:00', 10),
(7, 3, 3, '03:45:00', '03:55:00', 10),
(7, 2, 4, '08:30:00', NULL, 0),
(8, 5, 1, NULL, '07:00:00', 0),
(8, 6, 2, '12:20:00', '12:30:00', 10),
(8, 3, 3, '03:45:00', '03:55:00', 10),
(8, 2, 4, '08:30:00', NULL, 0),
(9, 10, 1, NULL, '06:30:00', 0),
(9, 9, 2, '13:10:00', '13:20:00', 10),
(9, 2, 3, '20:05:00', '20:15:00', 10),
(9, 3, 4, '23:55:00', NULL, 0),
(10, 10, 1, NULL, '06:30:00', 0),
(10, 9, 2, '13:10:00', '13:20:00', 10),
(10, 2, 3, '20:05:00', '20:15:00', 10),
(10, 3, 4, '23:55:00', NULL, 0);

-- Reservations (10): 6 Confirmed, 2 Pending, 2 Cancelled (seat_hold is generated automatically)
INSERT INTO reservation (reservation_id, passenger_id, train_id, schedule_id, src_station_id, dest_station_id,
                         seat_number, total_fare, journey_date, status, booking_date) VALUES
(1, 1, 1, 1, 2, 1, 12, 2700.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(NOW(), INTERVAL 289 HOUR)),
(2, 2, 1, 1, 2, 10, 14, 1800.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(NOW(), INTERVAL 265 HOUR)),
(3, 3, 1, 1, 9, 1, 7, 1800.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(NOW(), INTERVAL 241 HOUR)),
(4, 4, 2, 3, 4, 1, 21, 1200.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(NOW(), INTERVAL 217 HOUR)),
(5, 5, 3, 5, 6, 8, 30, 500.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Confirmed', DATE_SUB(NOW(), INTERVAL 193 HOUR)),
(6, 6, 4, 7, 5, 2, 9, 1500.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Pending', DATE_SUB(NOW(), INTERVAL 25 HOUR)),
(7, 7, 3, 5, 8, 1, 18, 1000.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Pending', DATE_SUB(NOW(), INTERVAL 25 HOUR)),
(8, 8, 2, 3, 4, 7, 5, 600.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Cancelled', DATE_SUB(NOW(), INTERVAL 145 HOUR)),
(9, 1, 1, 2, 2, 1, 33, 2700.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Cancelled', DATE_SUB(NOW(), INTERVAL 121 HOUR)),
(10, 2, 4, 8, 6, 3, 22, 500.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'Confirmed', DATE_SUB(NOW(), INTERVAL 97 HOUR));

-- Payments (9): 8 successful and 1 failed attempt (reservation 6 is still Pending)
INSERT INTO payment (payment_id, reservation_id, transaction_id, payment_method, amount, payment_date, payment_status) VALUES
(1, 1, 'TXN-DEMO-0001', 'UPI', 2700.00, DATE_SUB(NOW(), INTERVAL 288 HOUR), 'Success'),
(2, 2, 'TXN-DEMO-0002', 'Credit Card', 1800.00, DATE_SUB(NOW(), INTERVAL 264 HOUR), 'Success'),
(3, 3, 'TXN-DEMO-0003', 'Net Banking', 1800.00, DATE_SUB(NOW(), INTERVAL 240 HOUR), 'Success'),
(4, 4, 'TXN-DEMO-0004', 'Debit Card', 1200.00, DATE_SUB(NOW(), INTERVAL 216 HOUR), 'Success'),
(5, 5, 'TXN-DEMO-0005', 'UPI', 500.00, DATE_SUB(NOW(), INTERVAL 192 HOUR), 'Success'),
(6, 8, 'TXN-DEMO-0006', 'Credit Card', 600.00, DATE_SUB(NOW(), INTERVAL 144 HOUR), 'Success'),
(7, 9, 'TXN-DEMO-0007', 'Net Banking', 2700.00, DATE_SUB(NOW(), INTERVAL 120 HOUR), 'Success'),
(8, 10, 'TXN-DEMO-0008', 'Debit Card', 500.00, DATE_SUB(NOW(), INTERVAL 96 HOUR), 'Success'),
(9, 6, 'TXN-DEMO-0009', 'Credit Card', 1500.00, DATE_SUB(NOW(), INTERVAL 24 HOUR), 'Failed');

-- Cancellations (2): refund = 80% of the amount paid
INSERT INTO cancellation (cancellation_id, reservation_id, reason, refund_amount, cancellation_date) VALUES
(1, 8, 'Change of travel plans', 480.00, DATE_SUB(NOW(), INTERVAL 24 HOUR)),
(2, 9, 'Medical emergency in the family', 2160.00, DATE_SUB(NOW(), INTERVAL 48 HOUR));
