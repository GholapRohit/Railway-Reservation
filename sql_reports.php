<?php
// sql_reports.php - a FIXED list of approved SQL reports.
// The browser only sends a report NUMBER. The SQL text below is never built from user input,
// and there is no form for typing SQL, so arbitrary SQL cannot be executed from this page.
require_once __DIR__ . '/db.php';

$reports = [
    1 => [
        'name' => 'All Reservations',
        'concepts' => 'SELECT with a column list and ORDER BY (sorting by two columns, one descending).',
        'sql' => "SELECT reservation_id, passenger_id, train_id, src_station_id, dest_station_id,
       seat_number, total_fare, journey_date, status, booking_date
FROM reservation
ORDER BY journey_date DESC, reservation_id ASC",
    ],
    2 => [
        'name' => 'Passenger Booking Details',
        'concepts' => 'Multiple INNER JOINs. The station table is joined twice with different aliases (src, dst) to get both the source and the destination name.',
        'sql' => "SELECT r.reservation_id, p.name AS passenger_name, t.train_name,
       src.station_name AS source_station, dst.station_name AS destination_station,
       r.journey_date, r.status
FROM reservation r
INNER JOIN passenger p ON p.passenger_id = r.passenger_id
INNER JOIN train t     ON t.train_id     = r.train_id
INNER JOIN station src ON src.station_id = r.src_station_id
INNER JOIN station dst ON dst.station_id = r.dest_station_id
ORDER BY r.reservation_id",
    ],
    3 => [
        'name' => 'Reservation Count per Train',
        'concepts' => 'LEFT JOIN keeps trains that have no reservations; COUNT(r.reservation_id) counts only real matches, so those trains show 0; GROUP BY makes one row per train.',
        'sql' => "SELECT t.train_number, t.train_name, COUNT(r.reservation_id) AS total_reservations
FROM train t
LEFT JOIN reservation r ON r.train_id = t.train_id
GROUP BY t.train_id, t.train_number, t.train_name
ORDER BY total_reservations DESC, t.train_number",
    ],
    4 => [
        'name' => 'Total Revenue per Train',
        'concepts' => 'SUM and COUNT over joined tables with GROUP BY. Only payments with status Success are counted (condition inside the LEFT JOIN). COALESCE replaces NULL with 0 for trains without payments.',
        'sql' => "SELECT t.train_number, t.train_name,
       COUNT(p.payment_id)                 AS successful_payments,
       COALESCE(SUM(p.amount), 0)          AS total_collected,
       COALESCE(SUM(c.refund_amount), 0)   AS total_refunded,
       COALESCE(SUM(p.amount), 0) - COALESCE(SUM(c.refund_amount), 0) AS net_revenue
FROM train t
LEFT JOIN reservation r  ON r.train_id = t.train_id
LEFT JOIN payment p      ON p.reservation_id = r.reservation_id AND p.payment_status = 'Success'
LEFT JOIN cancellation c ON c.reservation_id = r.reservation_id
GROUP BY t.train_id, t.train_number, t.train_name
ORDER BY total_collected DESC",
    ],
    5 => [
        'name' => 'Most Popular Train',
        'concepts' => 'WHERE filters rows before grouping, GROUP BY counts per train, ORDER BY ... DESC puts the highest count first and LIMIT 1 keeps only that train (if two trains tie, only one is shown).',
        'sql' => "SELECT t.train_number, t.train_name, COUNT(*) AS confirmed_reservations
FROM train t
INNER JOIN reservation r ON r.train_id = t.train_id
WHERE r.status = 'Confirmed'
GROUP BY t.train_id, t.train_number, t.train_name
ORDER BY confirmed_reservations DESC
LIMIT 1",
    ],
    6 => [
        'name' => 'Above-Average Fare',
        'concepts' => 'A scalar subquery with AVG() in the WHERE clause: the inner query runs first and returns one number that the outer query compares against.',
        'sql' => "SELECT reservation_id, passenger_id, train_id, total_fare,
       (SELECT ROUND(AVG(total_fare), 2) FROM reservation) AS average_fare
FROM reservation
WHERE total_fare > (SELECT AVG(total_fare) FROM reservation)
ORDER BY total_fare DESC",
    ],
    7 => [
        'name' => 'Passengers Without Reservations',
        'concepts' => 'LEFT JOIN + WHERE ... IS NULL (an anti-join): passengers with no matching reservation row have NULL in the reservation columns.',
        'sql' => "SELECT p.passenger_id, p.name, p.email
FROM passenger p
LEFT JOIN reservation r ON r.passenger_id = p.passenger_id
WHERE r.reservation_id IS NULL
ORDER BY p.passenger_id",
    ],
    8 => [
        'name' => 'Monthly Revenue',
        'concepts' => 'Aggregate functions COUNT, SUM, AVG, MIN and MAX; DATE_FORMAT turns a date into a year-month label that is used for GROUP BY.',
        'sql' => "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS payment_month,
       COUNT(*)              AS successful_payments,
       SUM(amount)           AS total_revenue,
       ROUND(AVG(amount), 2) AS average_payment,
       MIN(amount)           AS smallest_payment,
       MAX(amount)           AS largest_payment
FROM payment
WHERE payment_status = 'Success'
GROUP BY payment_month
ORDER BY payment_month",
    ],
    9 => [
        'name' => 'Train Schedule Details (upcoming)',
        'concepts' => 'Four-table join (train, schedule, halt, station) with ORDER BY on several columns so that every route is listed in seq_number order.',
        'sql' => "SELECT t.train_number, t.train_name, ts.journey_date, h.seq_number,
       s.station_name, s.station_code, h.arrival_time, h.departure_time, h.halt_duration
FROM train t
INNER JOIN train_schedule ts ON ts.train_id    = t.train_id
INNER JOIN train_halt h      ON h.schedule_id  = ts.schedule_id
INNER JOIN station s         ON s.station_id   = h.station_id
WHERE ts.journey_date >= CURDATE()
ORDER BY t.train_number, ts.journey_date, h.seq_number",
    ],
    10 => [
        'name' => 'Reservation Summary',
        'concepts' => 'COUNT with GROUP BY: one row per status value (Pending, Confirmed, Cancelled).',
        'sql' => "SELECT status, COUNT(*) AS total_reservations
FROM reservation
GROUP BY status
ORDER BY total_reservations DESC",
    ],
    11 => [
        'name' => 'Busy Trains (extra: HAVING)',
        'concepts' => 'HAVING filters groups after aggregation (WHERE cannot use COUNT). Only trains with more than 2 reservations are shown.',
        'sql' => "SELECT t.train_number, t.train_name, COUNT(r.reservation_id) AS total_reservations
FROM train t
INNER JOIN reservation r ON r.train_id = t.train_id
GROUP BY t.train_id, t.train_number, t.train_name
HAVING COUNT(r.reservation_id) > 2
ORDER BY total_reservations DESC",
    ],
    12 => [
        'name' => 'Reservation View (extra: VIEW)',
        'concepts' => 'Querying the view v_reservation_details. The view stores the 5-table join, so this query is a simple SELECT.',
        'sql' => "SELECT reservation_id, passenger_name, train_name, source_station, destination_station,
       journey_date, total_fare, reservation_status
FROM v_reservation_details
WHERE total_fare >= 2000
ORDER BY total_fare DESC",
    ],
];

// Only a report number from the fixed list is accepted
$selected = (int)($_GET['report'] ?? 0);
$report = $reports[$selected] ?? null;
$rows = [];
$queryError = '';

if ($selected !== 0 && $report === null) {
    flash('warning', 'Unknown report. Please choose one from the list.');
}

if ($report !== null) {
    try {
        $rows = $pdo->query($report['sql'])->fetchAll();     // fixed SQL text, no user input
    } catch (PDOException $e) {
        $queryError = db_error_message($e, 'The report could not be run. Did you import schema.sql and sample_data.sql?');
    }
}

$pageTitle = 'SQL Reports';
require __DIR__ . '/header.php';
?>
<h1 class="h4 mb-3">SQL Reports</h1>

<div class="row g-4">
    <div class="col-lg-3">
        <div class="list-group">
            <?php foreach ($reports as $number => $r): ?>
                <a href="sql_reports.php?report=<?= e($number) ?>"
                   class="list-group-item list-group-item-action<?= $number === $selected ? ' active' : '' ?>">
                    <?= e($number) ?>. <?= e($r['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="col-lg-9">
        <?php if ($report === null): ?>
            <div class="card"><div class="card-body text-muted">
                Choose a report from the list. Each report shows its name, the SQL query, the result and the SQL concepts it demonstrates.
            </div></div>
        <?php else: ?>
            <div class="card mb-3">
                <div class="card-header">Report <?= e($selected) ?>: <?= e($report['name']) ?></div>
                <div class="card-body">
                    <h2 class="h6">SQL query</h2>
                    <pre class="sql-box mb-3"><?= e($report['sql']) ?></pre>
                    <h2 class="h6">SQL concepts demonstrated</h2>
                    <p class="mb-0"><?= e($report['concepts']) ?></p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Result (<?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?>)</div>
                <?php if ($queryError !== ''): ?>
                    <div class="card-body"><div class="alert alert-danger mb-0"><?= e($queryError) ?></div></div>
                <?php elseif (!$rows): ?>
                    <div class="card-body text-muted">The query returned no rows.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <?php foreach (array_keys($rows[0]) as $column): ?>
                                        <th><?= e($column) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= $value === null ? '<span class="text-muted">NULL</span>' : e($value) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
