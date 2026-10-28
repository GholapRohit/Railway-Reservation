// routes.js - every API endpoint with its SQL, plus the business rules and the error handler.
// All SQL that uses user input is parameterized (? placeholders), so input is never pasted into SQL text.
const express = require('express');
const crypto = require('crypto');
const { pool, withTransaction } = require('./db');

const router = express.Router();

// ---------------------------------------------------------------------
// Business rules
// ---------------------------------------------------------------------
const MAX_SEAT_NUMBER = 72;   // seats 1..72 (same limit as the CHECK in schema.sql)
const REFUND_PERCENT = 80;    // cancellation refund = 80% of the amount paid
const GENDERS = ['Male', 'Female', 'Other'];
const TRAIN_TYPES = ['Rajdhani', 'Duronto', 'Superfast', 'Express', 'Intercity', 'Passenger'];
const PAYMENT_METHODS = ['UPI', 'Credit Card', 'Debit Card', 'Net Banking'];

// FARE RULE (calculated by the server, never taken from the browser):
//   fare = number of stations travelled x rate per station for the train type
// Example: Mumbai Central -> New Delhi on the Rajdhani is 3 stations x 900 = Rs. 2700.
const FARE_PER_STATION = { Rajdhani: 900, Duronto: 800, Superfast: 600, Express: 500, Intercity: 400, Passenger: 250 };

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------
// An error we throw on purpose: it carries an HTTP status and a message that is safe to show.
class AppError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

// Express 4 does not catch errors thrown inside async functions, so each route is wrapped.
const asyncHandler = (handler) => (req, res, next) => {
  Promise.resolve(handler(req, res, next)).catch(next);
};

// Every successful response looks like { success, message, data }.
function sendSuccess(res, message, data = null, status = 200) {
  res.status(status).json({ success: true, message, data });
}

function toPositiveInt(value, label) {
  const text = String(value ?? '').trim();
  if (!/^[0-9]+$/.test(text) || Number(text) < 1) throw new AppError(400, `${label} must be a positive whole number.`);
  return Number(text);
}

// True only for real dates written as YYYY-MM-DD (rejects 2026-02-31).
function isValidDate(text) {
  if (typeof text !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(text)) return false;
  const date = new Date(`${text}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === text;
}

function todayString() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

const calculateFare = (trainType, stationsTravelled) => (FARE_PER_STATION[trainType] || 0) * stationsTravelled;
const calculateRefund = (amountPaid) => Math.round((amountPaid || 0) * REFUND_PERCENT) / 100;

// Unique id for a simulated payment, e.g. TXN20261027101530A1B2C3
function generateTransactionId() {
  const stamp = new Date().toISOString().replace(/[-:T.Z]/g, '').slice(0, 14);
  return `TXN${stamp}${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
}

// ---------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------
// GET /api/dashboard   (COUNT queries for the home page)
router.get('/dashboard', asyncHandler(async (req, res) => {
  const [passengers, trains, reservations] = await Promise.all([
    pool.query('SELECT COUNT(*) AS total FROM passenger'),
    pool.query('SELECT COUNT(*) AS total FROM train'),
    pool.query('SELECT COUNT(*) AS total FROM reservation'),
  ]);
  sendSuccess(res, 'Dashboard loaded.', {
    passengers: passengers[0][0].total,
    trains: trains[0][0].total,
    reservations: reservations[0][0].total,
  });
}));

// ---------------------------------------------------------------------
// Passengers
// ---------------------------------------------------------------------
// GET /api/passengers?search=name   (age is calculated from dob, it is not stored)
router.get('/passengers', asyncHandler(async (req, res) => {
  const search = String(req.query.search ?? '').trim();
  const [rows] = await pool.query(
    `SELECT passenger_id, name, email, phone, gender, dob, TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS age
     FROM passenger
     WHERE name LIKE ?
     ORDER BY passenger_id`,
    [`%${search}%`]
  );
  sendSuccess(res, 'Passengers loaded.', rows);
}));

// POST /api/passengers
router.post('/passengers', asyncHandler(async (req, res) => {
  const p = {
    name: String(req.body.name ?? '').trim(),
    email: String(req.body.email ?? '').trim(),
    phone: String(req.body.phone ?? '').trim(),
    gender: String(req.body.gender ?? '').trim(),
    dob: String(req.body.dob ?? '').trim(),
  };
  if (Object.values(p).some((value) => value === '')) {
    throw new AppError(400, 'Name, email, phone, gender and date of birth are all required.');
  }
  if (p.name.length < 2 || p.name.length > 100) throw new AppError(400, 'Name must be 2 to 100 characters long.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p.email) || p.email.length > 120) throw new AppError(400, 'Please enter a valid email address.');
  if (!/^[0-9]{10,15}$/.test(p.phone)) throw new AppError(400, 'Phone number must contain 10 to 15 digits only.');
  if (!GENDERS.includes(p.gender)) throw new AppError(400, 'Gender must be Male, Female or Other.');
  if (!isValidDate(p.dob)) throw new AppError(400, 'Date of birth is not a valid date (use YYYY-MM-DD).');
  if (p.dob > todayString()) throw new AppError(400, 'Date of birth cannot be in the future.');
  if (p.dob < '1900-01-01') throw new AppError(400, 'Date of birth is too far in the past.');

  // A duplicate email is rejected by the UNIQUE key and reported as 409 by errorHandler
  const [result] = await pool.query(
    'INSERT INTO passenger (name, email, phone, gender, dob) VALUES (?, ?, ?, ?, ?)',
    [p.name, p.email, p.phone, p.gender, p.dob]
  );
  sendSuccess(res, 'Passenger added successfully', { passenger_id: result.insertId }, 201);
}));

// ---------------------------------------------------------------------
// Trains and stations
// ---------------------------------------------------------------------
// GET /api/trains
router.get('/trains', asyncHandler(async (req, res) => {
  const [rows] = await pool.query('SELECT train_id, train_number, train_name, train_type FROM train ORDER BY train_number');
  sendSuccess(res, 'Trains loaded.', rows);
}));

// POST /api/trains
router.post('/trains', asyncHandler(async (req, res) => {
  const t = {
    train_number: String(req.body.train_number ?? '').trim().toUpperCase(),
    train_name: String(req.body.train_name ?? '').trim(),
    train_type: String(req.body.train_type ?? '').trim(),
  };
  if (Object.values(t).some((value) => value === '')) throw new AppError(400, 'Train number, name and type are all required.');
  if (!/^[A-Z0-9]{3,10}$/.test(t.train_number)) throw new AppError(400, 'Train number must be 3 to 10 letters or digits.');
  if (t.train_name.length < 3 || t.train_name.length > 100) throw new AppError(400, 'Train name must be 3 to 100 characters long.');
  if (!TRAIN_TYPES.includes(t.train_type)) throw new AppError(400, `Train type must be one of: ${TRAIN_TYPES.join(', ')}.`);

  const [result] = await pool.query(
    'INSERT INTO train (train_number, train_name, train_type) VALUES (?, ?, ?)',
    [t.train_number, t.train_name, t.train_type]
  );
  sendSuccess(res, 'Train added successfully', { train_id: result.insertId }, 201);
}));

// GET /api/stations   (fills the drop-downs)
router.get('/stations', asyncHandler(async (req, res) => {
  const [rows] = await pool.query('SELECT station_id, station_name, station_code, city, state FROM station ORDER BY station_name');
  sendSuccess(res, 'Stations loaded.', rows);
}));

// ---------------------------------------------------------------------
// Train search
// ---------------------------------------------------------------------
// GET /api/search-trains?sourceStationId=1&destinationStationId=2&journeyDate=2026-11-01
//
// h1 = halt at the source, h2 = halt at the destination. Both must belong to the SAME schedule
// and the source must have the smaller seq_number, otherwise the train would reach the
// destination first (wrong direction).
router.get('/search-trains', asyncHandler(async (req, res) => {
  const sourceId = toPositiveInt(req.query.sourceStationId, 'Source station');
  const destinationId = toPositiveInt(req.query.destinationStationId, 'Destination station');
  const journeyDate = String(req.query.journeyDate ?? '').trim();

  if (sourceId === destinationId) throw new AppError(400, 'The source and destination stations are identical.');
  if (!isValidDate(journeyDate)) throw new AppError(400, 'Journey date is not a valid date (use YYYY-MM-DD).');
  if (journeyDate < todayString()) throw new AppError(400, 'The journey date cannot be in the past.');

  const [rows] = await pool.query(
    `SELECT t.train_number, t.train_name, ts.schedule_id, ts.journey_date,
            src.station_name AS source_station, h1.departure_time AS source_departure,
            dst.station_name AS destination_station, h2.arrival_time AS destination_arrival
     FROM train_schedule ts
     INNER JOIN train t       ON t.train_id = ts.train_id
     INNER JOIN train_halt h1 ON h1.schedule_id = ts.schedule_id AND h1.station_id = ?
     INNER JOIN train_halt h2 ON h2.schedule_id = ts.schedule_id AND h2.station_id = ?
     INNER JOIN station src   ON src.station_id = h1.station_id
     INNER JOIN station dst   ON dst.station_id = h2.station_id
     WHERE ts.journey_date = ?
       AND h1.seq_number < h2.seq_number
     ORDER BY h1.departure_time, t.train_number`,
    [sourceId, destinationId, journeyDate]
  );
  sendSuccess(res, rows.length === 0 ? 'No trains found for the selected route and date.' : 'Trains found.', rows);
}));

// ---------------------------------------------------------------------
// Schedules (used by the booking form)
// ---------------------------------------------------------------------
// GET /api/schedules   (upcoming schedules that can still be booked)
router.get('/schedules', asyncHandler(async (req, res) => {
  const [rows] = await pool.query(
    `SELECT ts.schedule_id, t.train_number, t.train_name, ts.journey_date
     FROM train_schedule ts
     INNER JOIN train t ON t.train_id = ts.train_id
     WHERE ts.status = 'Scheduled' AND ts.journey_date >= CURDATE()
     ORDER BY ts.journey_date, t.train_number`
  );
  sendSuccess(res, 'Schedules loaded.', rows);
}));

// GET /api/schedules/:id/halts   (the stations of one schedule in route order)
router.get('/schedules/:id/halts', asyncHandler(async (req, res) => {
  const id = toPositiveInt(req.params.id, 'Schedule');
  const [rows] = await pool.query(
    `SELECT h.seq_number, s.station_id, s.station_name, s.station_code
     FROM train_halt h
     INNER JOIN station s ON s.station_id = h.station_id
     WHERE h.schedule_id = ?
     ORDER BY h.seq_number`,
    [id]
  );
  sendSuccess(res, 'Route loaded.', rows);
}));

// ---------------------------------------------------------------------
// Reservations
// ---------------------------------------------------------------------
// Checks the schedule and the route and returns the schedule details and the calculated fare.
// `db` is the pool (fare preview) or a transaction connection (booking).
// `lock` = true locks the schedule row so bookings for the same schedule queue up one after another.
async function checkTrip(db, scheduleId, sourceId, destinationId, lock) {
  if (sourceId === destinationId) throw new AppError(400, 'The source and destination stations are identical.');

  const [schedules] = await db.query(
    `SELECT schedule_id, train_id, journey_date, status FROM train_schedule WHERE schedule_id = ?${lock ? ' FOR UPDATE' : ''}`,
    [scheduleId]
  );
  if (schedules.length === 0) throw new AppError(404, 'The selected schedule does not exist.');
  const schedule = schedules[0];
  if (schedule.status !== 'Scheduled') throw new AppError(409, `This train schedule is ${schedule.status} and cannot be booked.`);
  if (schedule.journey_date < todayString()) throw new AppError(400, 'The journey date of this schedule has passed.');

  const [stations] = await db.query('SELECT station_id FROM station WHERE station_id IN (?, ?)', [sourceId, destinationId]);
  if (stations.length !== 2) throw new AppError(404, 'The source or destination station does not exist.');

  // Both stations must be halts of this schedule, and the source must come first (smaller seq_number)
  const [halts] = await db.query(
    'SELECT station_id, seq_number FROM train_halt WHERE schedule_id = ? AND station_id IN (?, ?)',
    [scheduleId, sourceId, destinationId]
  );
  const sequence = {};
  halts.forEach((halt) => { sequence[halt.station_id] = halt.seq_number; });
  if (sequence[sourceId] === undefined || sequence[destinationId] === undefined) {
    throw new AppError(400, 'This train does not stop at both selected stations.');
  }
  if (sequence[sourceId] >= sequence[destinationId]) {
    throw new AppError(400, "The source station occurs after the destination station on this train's route.");
  }

  const [trains] = await db.query('SELECT train_type FROM train WHERE train_id = ?', [schedule.train_id]);
  const stationsTravelled = sequence[destinationId] - sequence[sourceId];
  return {
    train_id: schedule.train_id,
    journey_date: schedule.journey_date,
    train_type: trains[0].train_type,
    rate_per_station: FARE_PER_STATION[trains[0].train_type],
    stations_travelled: stationsTravelled,
    fare: calculateFare(trains[0].train_type, stationsTravelled),
  };
}

// GET /api/fare?scheduleId=1&sourceStationId=2&destinationStationId=1   (shows the fare the server will charge)
router.get('/fare', asyncHandler(async (req, res) => {
  const scheduleId = toPositiveInt(req.query.scheduleId, 'Schedule');
  const sourceId = toPositiveInt(req.query.sourceStationId, 'Source station');
  const destinationId = toPositiveInt(req.query.destinationStationId, 'Destination station');
  const trip = await checkTrip(pool, scheduleId, sourceId, destinationId, false);
  sendSuccess(res, 'Fare calculated.', trip);
}));

// GET /api/reservations   (rows come from the view; the LEFT JOIN adds the refund of cancelled ones)
router.get('/reservations', asyncHandler(async (req, res) => {
  const [rows] = await pool.query(
    `SELECT v.*, c.refund_amount, pay.transaction_id
     FROM reservation_details_view v
     LEFT JOIN cancellation c ON c.reservation_id = v.reservation_id
     LEFT JOIN payment pay ON pay.reservation_id = v.reservation_id AND pay.payment_status = 'Success'
     ORDER BY v.reservation_id DESC`
  );
  sendSuccess(res, 'Reservations loaded.', rows);
}));

// POST /api/reservations   body: { passenger_id, schedule_id, src_station_id, dest_station_id, seat_number, total_fare? }
router.post('/reservations', asyncHandler(async (req, res) => {
  const passengerId = toPositiveInt(req.body.passenger_id, 'Passenger');
  const scheduleId = toPositiveInt(req.body.schedule_id, 'Schedule');
  const sourceId = toPositiveInt(req.body.src_station_id, 'Source station');
  const destinationId = toPositiveInt(req.body.dest_station_id, 'Destination station');
  const seatNumber = toPositiveInt(req.body.seat_number, 'Seat number');
  if (seatNumber > MAX_SEAT_NUMBER) throw new AppError(400, `Seat number must be between 1 and ${MAX_SEAT_NUMBER}.`);

  const reservationId = await withTransaction(async (connection) => {
    const [passengers] = await connection.query('SELECT passenger_id FROM passenger WHERE passenger_id = ?', [passengerId]);
    if (passengers.length === 0) throw new AppError(404, 'The selected passenger does not exist.');

    // Validates the route and locks the schedule row
    const trip = await checkTrip(connection, scheduleId, sourceId, destinationId, true);

    // The fare is always calculated here. A different fare sent by the browser is rejected.
    const sentFare = req.body.total_fare;
    if (sentFare !== undefined && sentFare !== null && sentFare !== '' && Math.abs(Number(sentFare) - trip.fare) > 0.001) {
      throw new AppError(400, `The fare does not match the calculated fare of Rs. ${trip.fare.toFixed(2)}.`);
    }

    const [taken] = await connection.query(
      "SELECT reservation_id FROM reservation WHERE schedule_id = ? AND seat_number = ? AND status IN ('Pending', 'Confirmed')",
      [scheduleId, seatNumber]
    );
    if (taken.length > 0) throw new AppError(409, 'The selected seat is already booked.');

    // Last line of defence: the UNIQUE key uq_active_seat rejects a double booking (reported as 409)
    const [result] = await connection.query(
      `INSERT INTO reservation (passenger_id, train_id, schedule_id, src_station_id, dest_station_id,
                                seat_number, total_fare, journey_date, status)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')`,
      [passengerId, trip.train_id, scheduleId, sourceId, destinationId, seatNumber, trip.fare, trip.journey_date]
    );
    return result.insertId;
  });

  sendSuccess(res, 'Reservation created with status Pending. Pay to confirm it.', { reservation_id: reservationId }, 201);
}));

// POST /api/reservations/:id/pay   body: { payment_method }   (SIMULATED payment, no real money)
router.post('/reservations/:id/pay', asyncHandler(async (req, res) => {
  const reservationId = toPositiveInt(req.params.id, 'Reservation');
  const method = String(req.body.payment_method ?? 'UPI').trim();
  if (!PAYMENT_METHODS.includes(method)) throw new AppError(400, `Payment method must be one of: ${PAYMENT_METHODS.join(', ')}.`);

  const result = await withTransaction(async (connection) => {
    // 1. Lock the reservation (stops two simultaneous payments) and check it is eligible
    const [rows] = await connection.query(
      'SELECT reservation_id, status, total_fare, journey_date FROM reservation WHERE reservation_id = ? FOR UPDATE',
      [reservationId]
    );
    if (rows.length === 0) throw new AppError(404, 'Reservation not found.');
    const reservation = rows[0];
    if (reservation.status === 'Cancelled') throw new AppError(409, 'A cancelled reservation cannot be paid for.');
    if (reservation.status === 'Confirmed') throw new AppError(409, 'Payment was already completed for this reservation.');
    if (reservation.journey_date < todayString()) throw new AppError(409, 'The journey date has passed, so this reservation cannot be paid for.');

    // 2. Payment record with a unique transaction id (the amount comes from the database)
    const transactionId = generateTransactionId();
    await connection.query(
      "INSERT INTO payment (reservation_id, transaction_id, payment_method, amount, payment_status) VALUES (?, ?, ?, ?, 'Success')",
      [reservationId, transactionId, method, reservation.total_fare]
    );

    // 3. Confirm the reservation. If this fails, the payment insert is rolled back too.
    const [update] = await connection.query(
      "UPDATE reservation SET status = 'Confirmed' WHERE reservation_id = ? AND status = 'Pending'",
      [reservationId]
    );
    if (update.affectedRows !== 1) throw new AppError(409, 'The reservation could not be confirmed.');

    return {
      reservation_id: reservationId, transaction_id: transactionId, payment_method: method,
      amount: reservation.total_fare, payment_status: 'Success', reservation_status: 'Confirmed',
    };
  }); // COMMIT happens inside withTransaction; any error above causes a ROLLBACK instead

  sendSuccess(res, 'Payment successful. The reservation is now Confirmed.', result, 201);
}));

// POST /api/reservations/:id/cancel   body: { reason }
// Only Confirmed (paid) reservations whose journey date is today or later can be cancelled.
// Refund = REFUND_PERCENT % of the amount paid. The reservation row is kept with status Cancelled.
router.post('/reservations/:id/cancel', asyncHandler(async (req, res) => {
  const reservationId = toPositiveInt(req.params.id, 'Reservation');
  const reason = String(req.body.reason ?? '').trim();
  if (reason.length < 5 || reason.length > 255) throw new AppError(400, 'Please enter a cancellation reason of 5 to 255 characters.');

  const result = await withTransaction(async (connection) => {
    // 1. Lock the reservation and check it can be cancelled
    const [rows] = await connection.query(
      'SELECT reservation_id, status, journey_date FROM reservation WHERE reservation_id = ? FOR UPDATE',
      [reservationId]
    );
    if (rows.length === 0) throw new AppError(404, 'Reservation not found.');
    const reservation = rows[0];
    if (reservation.status === 'Cancelled') throw new AppError(409, 'This reservation is already cancelled.');
    if (reservation.status !== 'Confirmed') throw new AppError(409, 'Only confirmed reservations can be cancelled.');
    if (reservation.journey_date < todayString()) throw new AppError(409, 'The journey date has passed, so this reservation cannot be cancelled.');

    // 2. The payment must have succeeded; the refund is a percentage of what was paid
    const [payments] = await connection.query(
      "SELECT amount FROM payment WHERE reservation_id = ? AND payment_status = 'Success'",
      [reservationId]
    );
    if (payments.length === 0) throw new AppError(409, 'No successful payment was found for this reservation.');
    const refundAmount = calculateRefund(payments[0].amount);

    // 3. Cancellation record (the UNIQUE key on reservation_id prevents a second one)
    await connection.query(
      'INSERT INTO cancellation (reservation_id, reason, refund_amount) VALUES (?, ?, ?)',
      [reservationId, reason, refundAmount]
    );

    // 4. Update the status (this also frees the seat for new bookings)
    const [update] = await connection.query(
      "UPDATE reservation SET status = 'Cancelled' WHERE reservation_id = ? AND status = 'Confirmed'",
      [reservationId]
    );
    if (update.affectedRows !== 1) throw new AppError(409, 'The reservation could not be cancelled.');

    return { reservation_id: reservationId, refund_amount: refundAmount, reservation_status: 'Cancelled' };
  }); // COMMIT inside withTransaction, ROLLBACK if anything above threw an error

  sendSuccess(res, 'Reservation cancelled successfully', result, 201);
}));

// ---------------------------------------------------------------------
// SQL reports: a FIXED list. The browser sends only the report number; the SQL text lives here,
// and the SAME text is executed and shown on the page. Arbitrary SQL can never be run.
// ---------------------------------------------------------------------
const REPORTS = {
  1: {
    title: 'Reservation Details',
    concepts: 'INNER JOIN: only rows that match in every joined table are returned. Three tables (reservation, passenger, train) are joined through their keys, and ORDER BY sorts the result.',
    sql: `SELECT r.reservation_id, p.name AS passenger_name, t.train_name,
       r.journey_date, r.seat_number, r.total_fare, r.status
FROM reservation r
INNER JOIN passenger p ON p.passenger_id = r.passenger_id
INNER JOIN train t     ON t.train_id     = r.train_id
ORDER BY r.reservation_id`,
  },
  2: {
    title: 'Reservation Count per Train',
    concepts: 'LEFT JOIN keeps trains that have no reservations; COUNT(r.reservation_id) counts only real matches, so those trains show 0; GROUP BY makes one row per train.',
    sql: `SELECT t.train_number, t.train_name, COUNT(r.reservation_id) AS total_reservations
FROM train t
LEFT JOIN reservation r ON r.train_id = t.train_id
GROUP BY t.train_id, t.train_number, t.train_name
ORDER BY total_reservations DESC, t.train_number`,
  },
  3: {
    title: 'Total Revenue',
    concepts: 'Aggregate functions COUNT, SUM, AVG, MIN and MAX over many rows. The WHERE clause keeps only successful payments, so Failed attempts are not counted as revenue.',
    sql: `SELECT COUNT(*)              AS successful_payments,
       SUM(amount)           AS total_revenue,
       ROUND(AVG(amount), 2) AS average_payment,
       MIN(amount)           AS smallest_payment,
       MAX(amount)           AS largest_payment
FROM payment
WHERE payment_status = 'Success'`,
  },
  4: {
    title: 'Passengers Without Reservations',
    concepts: 'LEFT JOIN + WHERE ... IS NULL (an anti-join): passengers with no matching reservation row have NULL in the reservation columns.',
    sql: `SELECT p.passenger_id, p.name, p.email
FROM passenger p
LEFT JOIN reservation r ON r.passenger_id = p.passenger_id
WHERE r.reservation_id IS NULL
ORDER BY p.passenger_id`,
  },
  5: {
    title: 'Above-Average Fare',
    concepts: 'A scalar subquery with AVG() in the WHERE clause: the inner query runs first and returns one number that the outer query compares each fare against.',
    sql: `SELECT reservation_id, passenger_id, train_id, total_fare,
       (SELECT ROUND(AVG(total_fare), 2) FROM reservation) AS average_fare
FROM reservation
WHERE total_fare > (SELECT AVG(total_fare) FROM reservation)
ORDER BY total_fare DESC`,
  },
  6: {
    title: 'Reservation Status Summary',
    concepts: 'GROUP BY with COUNT gives one row per status. The query reads from the database VIEW reservation_details_view, which stores the 5-table join of reservation, passenger, train and the station table twice.',
    sql: `SELECT reservation_status, COUNT(*) AS total_reservations
FROM reservation_details_view
GROUP BY reservation_status
ORDER BY total_reservations DESC`,
  },
};

// GET /api/reports   (list of reports for the menu)
router.get('/reports', asyncHandler(async (req, res) => {
  sendSuccess(res, 'Reports loaded.', Object.entries(REPORTS).map(([id, report]) => ({ id: Number(id), title: report.title })));
}));

// GET /api/reports/:reportId   (title, SQL text, explanation and result rows)
router.get('/reports/:reportId', asyncHandler(async (req, res) => {
  const report = Object.prototype.hasOwnProperty.call(REPORTS, req.params.reportId) ? REPORTS[req.params.reportId] : null;
  if (!report) throw new AppError(404, 'Report not found.');

  const [rows, fields] = await pool.query(report.sql);
  sendSuccess(res, 'Report executed.', {
    id: Number(req.params.reportId),
    title: report.title,
    sql: report.sql,
    concepts: report.concepts,
    columns: fields.map((field) => field.name),
    rows,
  });
}));

// ---------------------------------------------------------------------
// Error handler: turns every error into a JSON response. The browser never receives raw SQL
// errors or stack traces; those are only printed in the server console.
// ---------------------------------------------------------------------
// Friendly messages for UNIQUE-key violations (the key names are defined in schema.sql)
const DUPLICATE_MESSAGES = {
  uq_passenger_email: 'A passenger with this email already exists.',
  uq_train_number: 'A train with this number already exists.',
  uq_active_seat: 'The selected seat is already booked.',
  uq_one_success_per_reservation: 'Payment was already completed for this reservation.',
  uq_cancellation_reservation: 'This reservation is already cancelled.',
};

// Must have four parameters so Express treats it as an error handler.
// eslint-disable-next-line no-unused-vars
function errorHandler(error, req, res, next) {
  if (error instanceof AppError) {
    return res.status(error.status).json({ success: false, message: error.message });
  }
  if (error.type === 'entity.parse.failed') {
    return res.status(400).json({ success: false, message: 'The request body is not valid JSON.' });
  }
  switch (error.code) {
    case 'ER_DUP_ENTRY': {
      const key = Object.keys(DUPLICATE_MESSAGES).find((name) => String(error.sqlMessage).includes(name));
      return res.status(409).json({ success: false, message: key ? DUPLICATE_MESSAGES[key] : 'A record with the same unique value already exists.' });
    }
    case 'ER_NO_REFERENCED_ROW_2':
      return res.status(400).json({ success: false, message: 'A related record does not exist.' });
    case 'ER_CHECK_CONSTRAINT_VIOLATED':
    case 'ER_CONSTRAINT_FAILED':
    case 'ER_DATA_TOO_LONG':
    case 'ER_TRUNCATED_WRONG_VALUE':
      return res.status(400).json({ success: false, message: 'The data was rejected by a database rule.' });
    case 'ECONNREFUSED':
    case 'PROTOCOL_CONNECTION_LOST':
    case 'ER_ACCESS_DENIED_ERROR':
    case 'ER_BAD_DB_ERROR':
    case 'ER_NO_SUCH_TABLE':
      console.error('Database problem:', error.code, error.message);
      return res.status(500).json({ success: false, message: 'The database is not available or is not set up. Check the server console and the README.' });
    default:
      console.error('Unexpected error:', error);
      return res.status(500).json({ success: false, message: 'Something went wrong on the server.' });
  }
}

module.exports = { router, errorHandler };
