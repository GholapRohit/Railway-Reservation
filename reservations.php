<?php
// reservations.php - create a reservation (status Pending) and list all reservations.
// A reservation becomes Confirmed only after the simulated payment on payments.php succeeds.
require_once __DIR__ . '/db.php';

// Values typed in the form (or pre-filled by the "Book" button on the search page)
$form = [
    'passenger_id' => (int)($_GET['passenger'] ?? 0),
    'train_id'     => (int)($_GET['train'] ?? 0),
    'src'          => (int)($_GET['source'] ?? 0),
    'dest'         => (int)($_GET['destination'] ?? 0),
    'journey_date' => trim($_GET['date'] ?? ''),
    'seat_number'  => '',
    'total_fare'   => '',
];

// Returns a list of error messages (empty list = the reservation is valid and can be saved).
function validate_reservation(PDO $pdo, array $f): array
{
    $errors = [];

    // --- simple input checks ---
    if ($f['passenger_id'] <= 0 || $f['train_id'] <= 0 || $f['src'] <= 0 || $f['dest'] <= 0
        || $f['journey_date'] === '' || $f['seat_number'] === '' || $f['total_fare'] === '') {
        return ['All fields are required.'];
    }
    if ($f['src'] === $f['dest']) {
        $errors[] = 'Source and destination stations must be different.';
    }
    if (!is_valid_date($f['journey_date'])) {
        $errors[] = 'The journey date is invalid.';
    } elseif ($f['journey_date'] < date('Y-m-d')) {
        $errors[] = 'The journey date cannot be in the past.';
    }
    if (!ctype_digit((string)$f['seat_number']) || (int)$f['seat_number'] < 1 || (int)$f['seat_number'] > MAX_SEAT_NUMBER) {
        $errors[] = 'Seat number must be a whole number from 1 to ' . MAX_SEAT_NUMBER . '.';
    }
    if (!preg_match('/^[0-9]{1,5}(\.[0-9]{1,2})?$/', (string)$f['total_fare']) || (float)$f['total_fare'] <= 0
        || (float)$f['total_fare'] > MAX_FARE) {
        $errors[] = 'Fare must be a positive amount (at most ' . MAX_FARE . ') with up to 2 decimals.';
    }
    if ($errors) {
        return $errors;
    }

    // --- passenger, train and stations must exist ---
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM passenger WHERE passenger_id = :id');
    $stmt->execute(['id' => $f['passenger_id']]);
    if (!$stmt->fetchColumn()) {
        $errors[] = 'The selected passenger does not exist.';
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM train WHERE train_id = :id');
    $stmt->execute(['id' => $f['train_id']]);
    if (!$stmt->fetchColumn()) {
        $errors[] = 'The selected train does not exist.';
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM station WHERE station_id IN (:a, :b)');
    $stmt->execute(['a' => $f['src'], 'b' => $f['dest']]);
    if ((int)$stmt->fetchColumn() !== 2) {
        $errors[] = 'The source or destination station does not exist.';
    }
    if ($errors) {
        return $errors;
    }

    // --- the train must run on this date ---
    $stmt = $pdo->prepare('SELECT schedule_id, status FROM train_schedule WHERE train_id = :train AND journey_date = :date');
    $stmt->execute(['train' => $f['train_id'], 'date' => $f['journey_date']]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        return ['No schedule exists for this train on the selected date.'];
    }
    if ($schedule['status'] !== 'Scheduled') {
        return ['This train schedule is ' . $schedule['status'] . ' and cannot be booked.'];
    }

    // --- both stations must be on the route, and the source must come first ---
    $stmt = $pdo->prepare(
        'SELECT station_id, seq_number FROM train_halt WHERE schedule_id = :sid AND station_id IN (:a, :b)'
    );
    $stmt->execute(['sid' => $schedule['schedule_id'], 'a' => $f['src'], 'b' => $f['dest']]);
    $seq = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);      // station_id => seq_number
    if (!isset($seq[$f['src']]) || !isset($seq[$f['dest']])) {
        return ['This train does not stop at both selected stations on that date.'];
    }
    if ($seq[$f['src']] >= $seq[$f['dest']]) {
        return ['The source station occurs after the destination station on this train\'s route.'];
    }

    // --- seat availability (the database UNIQUE key also enforces this when saving) ---
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservation
         WHERE train_id = :train AND journey_date = :date AND seat_number = :seat AND status IN ('Pending', 'Confirmed')"
    );
    $stmt->execute(['train' => $f['train_id'], 'date' => $f['journey_date'], 'seat' => $f['seat_number']]);
    if ($stmt->fetchColumn() > 0) {
        return ['Seat ' . (int)$f['seat_number'] . ' is already booked on this train for that date. Please choose another seat.'];
    }
    return [];
}

// ---------------------------------------------------------------------
// Handle the "Create reservation" form
// ---------------------------------------------------------------------
if (is_post()) {
    check_csrf();

    $form = [
        'passenger_id' => (int)($_POST['passenger_id'] ?? 0),
        'train_id'     => (int)($_POST['train_id'] ?? 0),
        'src'          => (int)($_POST['src_station_id'] ?? 0),
        'dest'         => (int)($_POST['dest_station_id'] ?? 0),
        'journey_date' => trim($_POST['journey_date'] ?? ''),
        'seat_number'  => trim($_POST['seat_number'] ?? ''),
        'total_fare'   => trim($_POST['total_fare'] ?? ''),
    ];

    try {
        $errors = validate_reservation($pdo, $form);

        if (!$errors) {
            $stmt = $pdo->prepare(
                "INSERT INTO reservation (passenger_id, train_id, src_station_id, dest_station_id,
                                          seat_number, total_fare, journey_date, status)
                 VALUES (:passenger, :train, :src, :dest, :seat, :fare, :date, 'Pending')"
            );
            $stmt->execute([
                'passenger' => $form['passenger_id'], 'train' => $form['train_id'],
                'src' => $form['src'], 'dest' => $form['dest'],
                'seat' => (int)$form['seat_number'], 'fare' => $form['total_fare'], 'date' => $form['journey_date'],
            ]);
            flash('success', 'Reservation #' . $pdo->lastInsertId() . ' created with status Pending. Go to Payments to confirm it.');
            redirect('reservations.php');
        }
        foreach ($errors as $message) {
            flash('danger', $message);
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            // Two people tried to book the same seat at the same moment: the UNIQUE key stopped the second one
            flash('danger', 'That seat has just been booked by someone else. Please choose another seat.');
        } else {
            flash('danger', db_error_message($e, 'The reservation could not be saved.'));
        }
    }
}

// ---------------------------------------------------------------------
// Load dropdown values and the reservation list
// ---------------------------------------------------------------------
$passengers = $trains = $stations = $reservations = [];
$details = null;
$detailPayments = [];
$filter = $_GET['status'] ?? '';
$validStatuses = ['Pending', 'Confirmed', 'Cancelled'];

try {
    $passengers = $pdo->query('SELECT passenger_id, name FROM passenger ORDER BY name')->fetchAll();
    $trains = $pdo->query('SELECT train_id, train_number, train_name FROM train ORDER BY train_number')->fetchAll();
    $stations = $pdo->query('SELECT station_id, station_name, station_code FROM station ORDER BY station_name')->fetchAll();

    // The view v_reservation_details already joins reservation, passenger, train and station (twice)
    $sql = 'SELECT * FROM v_reservation_details';
    $params = [];
    if (in_array($filter, $validStatuses, true)) {
        $sql .= ' WHERE reservation_status = :status';
        $params['status'] = $filter;
    }
    $sql .= ' ORDER BY reservation_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();

    // Details panel for ?details=ID
    if (isset($_GET['details'])) {
        $stmt = $pdo->prepare(
            'SELECT v.*, c.refund_amount, c.reason, c.cancellation_date
             FROM v_reservation_details v
             LEFT JOIN cancellation c ON c.reservation_id = v.reservation_id
             WHERE v.reservation_id = :id'
        );
        $stmt->execute(['id' => (int)$_GET['details']]);
        $details = $stmt->fetch() ?: null;

        if ($details) {
            $stmt = $pdo->prepare(
                'SELECT transaction_id, payment_method, amount, payment_date, payment_status
                 FROM payment WHERE reservation_id = :id ORDER BY payment_id'
            );
            $stmt->execute(['id' => $details['reservation_id']]);
            $detailPayments = $stmt->fetchAll();
        } else {
            flash('warning', 'Reservation not found.');
        }
    }
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load reservations.'));
}

$pageTitle = 'Reservations';
require __DIR__ . '/header.php';
$today = date('Y-m-d');
?>
<h1 class="h4 mb-3">Reservations</h1>

<?php if ($details): ?>
<div class="card mb-4 border-primary">
    <div class="card-header d-flex justify-content-between">
        <span>Reservation #<?= e($details['reservation_id']) ?> details</span>
        <a href="reservations.php" class="btn-close" aria-label="Close"></a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div><strong>Passenger:</strong> <?= e($details['passenger_name']) ?></div>
                <div><strong>Train:</strong> <?= e($details['train_number']) ?> - <?= e($details['train_name']) ?></div>
                <div><strong>Route:</strong> <?= e($details['source_station']) ?> (<?= e($details['source_code']) ?>)
                     to <?= e($details['destination_station']) ?> (<?= e($details['destination_code']) ?>)</div>
            </div>
            <div class="col-md-4">
                <div><strong>Journey date:</strong> <?= e($details['journey_date']) ?></div>
                <div><strong>Seat:</strong> <?= e($details['seat_number']) ?></div>
                <div><strong>Fare:</strong> <?= e(money($details['total_fare'])) ?></div>
                <div><strong>Status:</strong> <?= status_badge($details['reservation_status']) ?></div>
                <div><strong>Booked on:</strong> <?= e($details['booking_date']) ?></div>
            </div>
            <div class="col-md-4">
                <?php if ($details['refund_amount'] !== null): ?>
                    <div><strong>Cancelled on:</strong> <?= e($details['cancellation_date']) ?></div>
                    <div><strong>Reason:</strong> <?= e($details['reason']) ?></div>
                    <div><strong>Refund:</strong> <?= e(money($details['refund_amount'])) ?></div>
                <?php else: ?>
                    <span class="text-muted">Not cancelled.</span>
                <?php endif; ?>
            </div>
        </div>
        <h2 class="h6 mt-3">Payment attempts</h2>
        <?php if (!$detailPayments): ?>
            <p class="text-muted mb-0">No payment has been made yet.</p>
        <?php else: ?>
            <table class="table table-sm mb-0">
                <thead><tr><th>Transaction ID</th><th>Method</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($detailPayments as $p): ?>
                    <tr>
                        <td><?= e($p['transaction_id']) ?></td><td><?= e($p['payment_method']) ?></td>
                        <td><?= e(money($p['amount'])) ?></td><td><?= e($p['payment_date']) ?></td>
                        <td><?= status_badge($p['payment_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4" id="create">
    <div class="card-header">A. Create reservation</div>
    <div class="card-body">
        <form method="post" action="reservations.php" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label" for="passenger_id">Passenger</label>
                <select class="form-select" id="passenger_id" name="passenger_id" required>
                    <option value="">Select passenger...</option>
                    <?php foreach ($passengers as $p): ?>
                        <option value="<?= e($p['passenger_id']) ?>" <?= $form['passenger_id'] === (int)$p['passenger_id'] ? 'selected' : '' ?>>
                            #<?= e($p['passenger_id']) ?> - <?= e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="train_id">Train</label>
                <select class="form-select" id="train_id" name="train_id" required>
                    <option value="">Select train...</option>
                    <?php foreach ($trains as $t): ?>
                        <option value="<?= e($t['train_id']) ?>" <?= $form['train_id'] === (int)$t['train_id'] ? 'selected' : '' ?>>
                            <?= e($t['train_number']) ?> - <?= e($t['train_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="journey_date">Journey date</label>
                <input type="date" class="form-control" id="journey_date" name="journey_date" min="<?= e(date('Y-m-d')) ?>" required value="<?= e($form['journey_date']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="src_station_id">Source station</label>
                <select class="form-select" id="src_station_id" name="src_station_id" required>
                    <option value="">Select source...</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= e($s['station_id']) ?>" <?= $form['src'] === (int)$s['station_id'] ? 'selected' : '' ?>>
                            <?= e($s['station_name']) ?> (<?= e($s['station_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dest_station_id">Destination station</label>
                <select class="form-select" id="dest_station_id" name="dest_station_id" required>
                    <option value="">Select destination...</option>
                    <?php foreach ($stations as $s): ?>
                        <option value="<?= e($s['station_id']) ?>" <?= $form['dest'] === (int)$s['station_id'] ? 'selected' : '' ?>>
                            <?= e($s['station_name']) ?> (<?= e($s['station_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="seat_number">Seat (1-<?= e(MAX_SEAT_NUMBER) ?>)</label>
                <input type="number" class="form-control" id="seat_number" name="seat_number" min="1" max="<?= e(MAX_SEAT_NUMBER) ?>" required value="<?= e($form['seat_number']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="total_fare">Total fare (Rs.)</label>
                <input type="number" class="form-control" id="total_fare" name="total_fare" min="1" max="<?= e(MAX_FARE) ?>" step="0.01" required value="<?= e($form['total_fare']) ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Create Reservation</button>
                <span class="text-muted small ms-2">The reservation starts as Pending and is confirmed after payment.</span>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>B. All reservations (<?= count($reservations) ?>)</span>
        <form method="get" action="reservations.php" class="d-flex gap-2">
            <select class="form-select form-select-sm" name="status">
                <option value="">All statuses</option>
                <?php foreach ($validStatuses as $st): ?>
                    <option value="<?= e($st) ?>" <?= $filter === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th><th>Passenger</th><th>Train</th><th>From</th><th>To</th>
                    <th>Date</th><th>Seat</th><th>Fare</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$reservations): ?>
                <tr><td colspan="10" class="text-center text-muted py-3">No reservations found.</td></tr>
            <?php endif; ?>
            <?php foreach ($reservations as $r):
                $canCancel = in_array($r['reservation_status'], ['Pending', 'Confirmed'], true) && $r['journey_date'] >= $today;
                $canPay = $r['reservation_status'] === 'Pending' && $r['journey_date'] >= $today;
            ?>
                <tr>
                    <td><?= e($r['reservation_id']) ?></td>
                    <td><?= e($r['passenger_name']) ?></td>
                    <td><?= e($r['train_name']) ?><br><span class="small text-muted"><?= e($r['train_number']) ?></span></td>
                    <td><?= e($r['source_station']) ?></td>
                    <td><?= e($r['destination_station']) ?></td>
                    <td><?= e($r['journey_date']) ?></td>
                    <td><?= e($r['seat_number']) ?></td>
                    <td><?= e(money($r['total_fare'])) ?></td>
                    <td><?= status_badge($r['reservation_status']) ?></td>
                    <td class="text-nowrap">
                        <a href="reservations.php?details=<?= e($r['reservation_id']) ?>" class="btn btn-outline-secondary btn-sm">Details</a>
                        <?php if ($canPay): ?>
                            <a href="payments.php?reservation=<?= e($r['reservation_id']) ?>" class="btn btn-outline-primary btn-sm">Pay</a>
                        <?php endif; ?>
                        <?php if ($canCancel): ?>
                            <a href="cancellations.php?reservation=<?= e($r['reservation_id']) ?>" class="btn btn-outline-danger btn-sm">Cancel</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
