<?php
// search_trains.php - find trains that run from a source station to a destination station on a date.
// The key rule: both stations must be halts of the same schedule AND the source must have a
// smaller seq_number than the destination (the train must reach the source first).
require_once __DIR__ . '/db.php';

$stations = [];
$upcomingDates = [];
$results = null;          // null = no search yet, array = search done
$searchErrors = [];

try {
    $stations = $pdo->query('SELECT station_id, station_name, station_code FROM station ORDER BY station_name')->fetchAll();

    // Hint for the user: the next dates that have scheduled trains
    $upcomingDates = $pdo->query(
        "SELECT DISTINCT journey_date FROM train_schedule
         WHERE journey_date >= CURDATE() AND status = 'Scheduled'
         ORDER BY journey_date LIMIT 8"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load stations.'));
}

$source = (int)($_GET['source'] ?? 0);
$destination = (int)($_GET['destination'] ?? 0);
$date = trim($_GET['date'] ?? date('Y-m-d', strtotime('+2 days')));

if (isset($_GET['search'])) {
    if ($source === 0 || $destination === 0) {
        $searchErrors[] = 'Please select both the source and the destination station.';
    } elseif ($source === $destination) {
        $searchErrors[] = 'Source and destination stations must be different.';
    }
    if (!is_valid_date($date)) {
        $searchErrors[] = 'Please enter a valid journey date.';
    } elseif ($date < date('Y-m-d')) {
        $searchErrors[] = 'The journey date cannot be in the past.';
    }

    if (!$searchErrors) {
        try {
            // h1 = halt at the source, h2 = halt at the destination (same schedule).
            // The correlated subquery counts seats already held (Pending/Confirmed) for that train and date.
            $stmt = $pdo->prepare(
                "SELECT t.train_id, t.train_number, t.train_name, t.train_type,
                        ts.schedule_id, ts.journey_date, ts.status,
                        src.station_name AS source_name, h1.departure_time AS source_departure,
                        dst.station_name AS destination_name, h2.arrival_time AS destination_arrival,
                        (h2.seq_number - h1.seq_number) AS stations_travelled,
                        (:max_seats - (SELECT COUNT(*) FROM reservation r
                                       WHERE r.train_id = ts.train_id
                                         AND r.journey_date = ts.journey_date
                                         AND r.status IN ('Pending', 'Confirmed'))) AS seats_left
                 FROM train_schedule ts
                 INNER JOIN train t       ON t.train_id = ts.train_id
                 INNER JOIN train_halt h1 ON h1.schedule_id = ts.schedule_id AND h1.station_id = :source
                 INNER JOIN train_halt h2 ON h2.schedule_id = ts.schedule_id AND h2.station_id = :destination
                 INNER JOIN station src   ON src.station_id = h1.station_id
                 INNER JOIN station dst   ON dst.station_id = h2.station_id
                 WHERE ts.journey_date = :journey_date
                   AND h1.seq_number < h2.seq_number
                 ORDER BY h1.departure_time, t.train_number"
            );
            $stmt->execute([
                'max_seats'    => MAX_SEAT_NUMBER,
                'source'       => $source,
                'destination'  => $destination,
                'journey_date' => $date,
            ]);
            $results = $stmt->fetchAll();
        } catch (PDOException $e) {
            $searchErrors[] = db_error_message($e, 'The search could not be completed.');
        }
    }
}

$pageTitle = 'Search Trains';
require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Search Trains</h1>
    <a href="trains.php" class="btn btn-outline-secondary btn-sm">Manage Trains</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" action="search_trains.php" class="row g-3">
            <input type="hidden" name="search" value="1">
            <div class="col-md-4">
                <label class="form-label" for="source">Source station</label>
                <select class="form-select" id="source" name="source" required>
                    <option value="">Select source...</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= e($s['station_id']) ?>" <?= $source === (int)$s['station_id'] ? 'selected' : '' ?>>
                            <?= e($s['station_name']) ?> (<?= e($s['station_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="destination">Destination station</label>
                <select class="form-select" id="destination" name="destination" required>
                    <option value="">Select destination...</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= e($s['station_id']) ?>" <?= $destination === (int)$s['station_id'] ? 'selected' : '' ?>>
                            <?= e($s['station_name']) ?> (<?= e($s['station_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date">Journey date</label>
                <input type="date" class="form-control" id="date" name="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e($date) ?>" required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </form>
        <?php if ($upcomingDates): ?>
            <p class="text-muted small mt-3 mb-0">Dates that have scheduled trains: <?= e(implode(', ', $upcomingDates)) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php foreach ($searchErrors as $message): ?>
    <div class="alert alert-danger"><?= e($message) ?></div>
<?php endforeach; ?>

<?php if ($results !== null): ?>
    <?php if (!$results): ?>
        <div class="alert alert-warning">No trains are available for the selected route and date. Try another date or station pair.</div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">Trains found: <?= count($results) ?></div>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Train</th><th>Type</th><th>Date</th><th>Departs</th><th>Arrives</th>
                            <th>Stations</th><th>Schedule</th><th>Seats left</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $r): ?>
                        <tr>
                            <td><strong><?= e($r['train_number']) ?></strong><br><span class="small"><?= e($r['train_name']) ?></span></td>
                            <td><?= e($r['train_type']) ?></td>
                            <td><?= e($r['journey_date']) ?></td>
                            <td><?= e(substr((string)$r['source_departure'], 0, 5)) ?><br><span class="small text-muted"><?= e($r['source_name']) ?></span></td>
                            <td><?= e(substr((string)$r['destination_arrival'], 0, 5)) ?><br><span class="small text-muted"><?= e($r['destination_name']) ?></span></td>
                            <td><?= e($r['stations_travelled']) ?> stop(s)</td>
                            <td>#<?= e($r['schedule_id']) ?> <?= status_badge($r['status']) ?></td>
                            <td><?= e(max(0, (int)$r['seats_left'])) ?></td>
                            <td>
                                <?php if ($r['status'] === 'Scheduled'): ?>
                                    <a class="btn btn-primary btn-sm"
                                       href="reservations.php?<?= e(http_build_query([
                                           'train' => $r['train_id'], 'source' => $source,
                                           'destination' => $destination, 'date' => $r['journey_date'],
                                       ])) ?>#create">Book</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small">
                "Seats left" counts seats of the whole train on that date (<?= e(MAX_SEAT_NUMBER) ?> per train); it does not track which part of the route each seat covers.
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
