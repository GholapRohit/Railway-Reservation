-- =====================================================================
-- sample_data.sql  -  demonstration data for the Railway Reservation System
-- Run AFTER schema.sql.  All people are fictional.
--
-- Journey dates are written relative to today (CURDATE() +/- n days), so the
-- demo schedules are always in the future and train search always has results.
-- Passenger 11 and 12 have no reservations (used by SQL Report 7).
-- Train 22119 has no reservations (used by SQL Report 3).
-- =====================================================================

USE railway_reservation;

-- Passengers (12)
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
(10, 'Pooja Kulkarni', 'pooja.kulkarni@example.com', '9876500010', 'Female', '1999-08-19'),
(11, 'Imran Qureshi', 'imran.qureshi@example.com', '9876500011', 'Male', '1983-05-27'),
(12, 'Kavya Joshi', 'kavya.joshi@example.com', '9876500012', 'Female', '2000-10-05');

-- Trains (6)
INSERT INTO train (train_id, train_number, train_name, train_type) VALUES
(1, '12951', 'Mumbai Delhi Rajdhani Express', 'Rajdhani'),
(2, '12301', 'Howrah Delhi Superfast Express', 'Superfast'),
(3, '12627', 'Bengaluru Delhi Karnataka Express', 'Express'),
(4, '12163', 'Chennai Mumbai Express', 'Express'),
(5, '12801', 'Pune Howrah Duronto Express', 'Duronto'),
(6, '22119', 'Jaipur Pune Intercity Express', 'Intercity');

-- Stations (12)
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
(10, 'Jaipur Junction', 'JP', 'Jaipur', 'Rajasthan'),
(11, 'Lucknow Charbagh', 'LKO', 'Lucknow', 'Uttar Pradesh'),
(12, 'Secunderabad Junction', 'SC', 'Hyderabad', 'Telangana');

-- Train schedules (14): past ones are Completed, future ones are Scheduled
INSERT INTO train_schedule (schedule_id, train_id, journey_date, status) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Completed'),
(2, 1, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(3, 1, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Scheduled'),
(4, 2, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Completed'),
(5, 2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(6, 2, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 'Scheduled'),
(7, 3, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Scheduled'),
(8, 3, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Scheduled'),
(9, 4, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(10, 4, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'Scheduled'),
(11, 5, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Scheduled'),
(12, 5, DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'Scheduled'),
(13, 6, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Scheduled'),
(14, 6, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Scheduled');

-- Train halts: the same route is used for every schedule of a train.
-- seq_number = order along the route; halt_duration is in minutes.
INSERT INTO train_halt (schedule_id, station_id, seq_number, arrival_time, departure_time, halt_duration) VALUES
(1, 2, 1, NULL, '16:55:00', 0),
(1, 9, 2, '22:35:00', '22:45:00', 10),
(1, 10, 3, '05:10:00', '05:20:00', 10),
(1, 1, 4, '08:35:00', NULL, 0),
(2, 2, 1, NULL, '16:55:00', 0),
(2, 9, 2, '22:35:00', '22:45:00', 10),
(2, 10, 3, '05:10:00', '05:20:00', 10),
(2, 1, 4, '08:35:00', NULL, 0),
(3, 2, 1, NULL, '16:55:00', 0),
(3, 9, 2, '22:35:00', '22:45:00', 10),
(3, 10, 3, '05:10:00', '05:20:00', 10),
(3, 1, 4, '08:35:00', NULL, 0),
(4, 4, 1, NULL, '16:55:00', 0),
(4, 11, 2, '07:20:00', '07:35:00', 15),
(4, 1, 3, '10:50:00', NULL, 0),
(5, 4, 1, NULL, '16:55:00', 0),
(5, 11, 2, '07:20:00', '07:35:00', 15),
(5, 1, 3, '10:50:00', NULL, 0),
(6, 4, 1, NULL, '16:55:00', 0),
(6, 11, 2, '07:20:00', '07:35:00', 15),
(6, 1, 3, '10:50:00', NULL, 0),
(7, 6, 1, NULL, '19:20:00', 0),
(7, 12, 2, '05:10:00', '05:25:00', 15),
(7, 8, 3, '11:50:00', '12:00:00', 10),
(7, 7, 4, '17:30:00', '17:40:00', 10),
(7, 1, 5, '05:00:00', NULL, 0),
(8, 6, 1, NULL, '19:20:00', 0),
(8, 12, 2, '05:10:00', '05:25:00', 15),
(8, 8, 3, '11:50:00', '12:00:00', 10),
(8, 7, 4, '17:30:00', '17:40:00', 10),
(8, 1, 5, '05:00:00', NULL, 0),
(9, 5, 1, NULL, '07:00:00', 0),
(9, 6, 2, '12:20:00', '12:30:00', 10),
(9, 3, 3, '03:45:00', '03:55:00', 10),
(9, 2, 4, '08:30:00', NULL, 0),
(10, 5, 1, NULL, '07:00:00', 0),
(10, 6, 2, '12:20:00', '12:30:00', 10),
(10, 3, 3, '03:45:00', '03:55:00', 10),
(10, 2, 4, '08:30:00', NULL, 0),
(11, 3, 1, NULL, '21:00:00', 0),
(11, 8, 2, '08:15:00', '08:20:00', 5),
(11, 4, 3, '20:45:00', NULL, 0),
(12, 3, 1, NULL, '21:00:00', 0),
(12, 8, 2, '08:15:00', '08:20:00', 5),
(12, 4, 3, '20:45:00', NULL, 0),
(13, 10, 1, NULL, '06:30:00', 0),
(13, 9, 2, '13:10:00', '13:20:00', 10),
(13, 2, 3, '20:05:00', '20:15:00', 10),
(13, 3, 4, '23:55:00', NULL, 0),
(14, 10, 1, NULL, '06:30:00', 0),
(14, 9, 2, '13:10:00', '13:20:00', 10),
(14, 2, 3, '20:05:00', '20:15:00', 10),
(14, 3, 4, '23:55:00', NULL, 0);

-- Reservations (14): 8 Confirmed, 3 Pending, 3 Cancelled
-- (seat_hold is generated automatically, so it is not listed)
INSERT INTO reservation (reservation_id, passenger_id, train_id, src_station_id, dest_station_id,
                         seat_number, total_fare, journey_date, status, booking_date) VALUES
(1, 1, 1, 2, 1, 12, 2850.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 45 DAY), INTERVAL 1 HOUR)),
(2, 2, 1, 2, 10, 14, 2100.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 44 DAY), INTERVAL 1 HOUR)),
(3, 3, 1, 9, 1, 7, 1950.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 12 DAY), INTERVAL 1 HOUR)),
(4, 4, 2, 4, 1, 21, 2300.00, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 33 DAY), INTERVAL 1 HOUR)),
(5, 5, 2, 4, 11, 5, 1450.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 9 DAY), INTERVAL 1 HOUR)),
(6, 6, 3, 6, 8, 30, 1650.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 7 DAY), INTERVAL 1 HOUR)),
(7, 7, 3, 12, 1, 18, 1900.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Pending', DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 1 HOUR)),
(8, 8, 4, 5, 2, 9, 1750.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 5 DAY), INTERVAL 1 HOUR)),
(9, 9, 4, 6, 3, 22, 1200.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'Pending', DATE_SUB(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 HOUR)),
(10, 1, 5, 3, 4, 40, 2400.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'Confirmed', DATE_SUB(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL 1 HOUR)),
(11, 2, 1, 2, 1, 33, 2850.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Cancelled', DATE_SUB(DATE_SUB(NOW(), INTERVAL 20 DAY), INTERVAL 1 HOUR)),
(12, 3, 3, 8, 7, 15, 900.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'Cancelled', DATE_SUB(DATE_SUB(NOW(), INTERVAL 15 DAY), INTERVAL 1 HOUR)),
(13, 4, 5, 3, 8, 11, 1100.00, DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'Cancelled', DATE_SUB(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 HOUR)),
(14, 10, 2, 11, 1, 44, 800.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 'Pending', DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 1 HOUR));

-- Payments (12): 11 successful and 1 failed attempt (reservation 14 is still Pending)
INSERT INTO payment (payment_id, reservation_id, transaction_id, payment_method, amount, payment_date, payment_status) VALUES
(1, 1, 'TXN-DEMO-0001', 'UPI', 2850.00, DATE_SUB(NOW(), INTERVAL 45 DAY), 'Success'),
(2, 2, 'TXN-DEMO-0002', 'Credit Card', 2100.00, DATE_SUB(NOW(), INTERVAL 44 DAY), 'Success'),
(3, 3, 'TXN-DEMO-0003', 'Net Banking', 1950.00, DATE_SUB(NOW(), INTERVAL 12 DAY), 'Success'),
(4, 4, 'TXN-DEMO-0004', 'Debit Card', 2300.00, DATE_SUB(NOW(), INTERVAL 33 DAY), 'Success'),
(5, 5, 'TXN-DEMO-0005', 'UPI', 1450.00, DATE_SUB(NOW(), INTERVAL 9 DAY), 'Success'),
(6, 6, 'TXN-DEMO-0006', 'UPI', 1650.00, DATE_SUB(NOW(), INTERVAL 7 DAY), 'Success'),
(7, 8, 'TXN-DEMO-0007', 'Credit Card', 1750.00, DATE_SUB(NOW(), INTERVAL 5 DAY), 'Success'),
(8, 10, 'TXN-DEMO-0008', 'Net Banking', 2400.00, DATE_SUB(NOW(), INTERVAL 3 DAY), 'Success'),
(9, 11, 'TXN-DEMO-0009', 'UPI', 2850.00, DATE_SUB(NOW(), INTERVAL 20 DAY), 'Success'),
(10, 12, 'TXN-DEMO-0010', 'Debit Card', 900.00, DATE_SUB(NOW(), INTERVAL 15 DAY), 'Success'),
(11, 13, 'TXN-DEMO-0011', 'UPI', 1100.00, DATE_SUB(NOW(), INTERVAL 2 DAY), 'Success'),
(12, 14, 'TXN-DEMO-0012', 'Credit Card', 800.00, DATE_SUB(NOW(), INTERVAL 1 DAY), 'Failed');

-- Cancellations (3): refund = 80% of the fare paid (the rule used by cancellations.php)
INSERT INTO cancellation (cancellation_id, reservation_id, reason, refund_amount, cancellation_date) VALUES
(1, 11, 'Change of travel plans', 2280.00, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 12, 'Medical emergency in the family', 720.00, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 13, 'Booked a different train', 880.00, DATE_SUB(NOW(), INTERVAL 3 DAY));
