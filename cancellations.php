<?php
// cancellations.php - cancel a reservation, calculate the refund and show cancellation history.
//
// REFUND POLICY (change REFUND_PERCENT in db.php to adjust it):
//   * Confirmed reservation with a successful payment: refund = REFUND_PERCENT % of the amount paid.
//   * Pending reservation (nothing paid yet): it can still be cancelled to release the seat, refund = 0.
//   * Only reservations whose journey date is today or later can be cancelled.
//   * The reservation row is kept (status = Cancelled) so the booking history is preserved.
// No real refund is made; the amount is only stored in the cancellation table.
require_once __DIR__ . '/db.php';

if (is_post()) {
    check_csrf();

    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($reservationId <= 0) {
        flash('danger', 'Please select a reservation to cancel.');
        redirect('cancellations.php');
    }
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
        flash('danger', 'Please enter a cancellation reason of 5 to 255 characters.');
        redirect('cancellations.php?reservation=' . $reservationId);
    }

    try {
        // 1. start the transaction
        $pdo->beginTransaction();

        // 2. verify the reservation can be cancelled (row is locked until commit/rollback)
        $stmt = $pdo->prepare(
            'SELECT reservation_id, status, journey_date FROM reservation WHERE reservation_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $reservationId]);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            throw new RuntimeException('The reservation does not exist.');
        }
        if ($reservation['status'] === 'Cancelled') {
            throw new RuntimeException('This reservation is already cancelled.');
        }
        if ($reservation['journey_date'] < date('Y-m-d')) {
            throw new RuntimeException('The journey date has passed, so this reservation cannot be cancelled.');
        }

        // 3. verify that the payment is successful (a Confirmed reservation must have one)
        $stmt = $pdo->prepare("SELECT amount FROM payment WHERE reservation_id = :id AND payment_status = 'Success'");
        $stmt->execute(['id' => $reservationId]);
        $amountPaid = $stmt->fetchColumn();

        if ($reservation['status'] === 'Confirmed' && $amountPaid === false) {
            throw new RuntimeException('No successful payment was found for this reservation, so it cannot be cancelled.');
        }

        // 4. calculate the refund
        $refund = ($amountPaid === false) ? 0.00 : round((float)$amountPaid * REFUND_PERCENT / 100, 2);

        // 5. insert the cancellation record (UNIQUE key on reservation_id prevents duplicates)
        $stmt = $pdo->prepare('INSERT INTO cancellation (reservation_id, reason, refund_amount) VALUES (:id, :reason, :refund)');
        $stmt->execute(['id' => $reservationId, 'reason' => $reason, 'refund' => $refund]);

        // 6. update the reservation status (this also frees the seat for new bookings)
        $stmt = $pdo->prepare("UPDATE reservation SET status = 'Cancelled' WHERE reservation_id = :id");
        $stmt->execute(['id' => $reservationId]);

        // 7. commit
        $pdo->commit();                                                     

        flash('success', 'Reservation #' . $reservationId . ' cancelled. Refund amount: ' . money($refund)
            . ' | Updated reservation status: Cancelled.');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            // 8. roll back on any problem
            $pdo->rollBack();                                               
        }
        flash('danger', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'This reservation is already cancelled.');
        } else {
            flash('danger', db_error_message($e, 'The cancellation could not be completed. Nothing was changed.'));
        }
    }
    redirect('cancellations.php');
}

// ---------------------------------------------------------------------
// Load eligible reservations, the selected reservation and history
// ---------------------------------------------------------------------
$eligible = $history = [];
$selected = null;
try {
    // LEFT JOIN so Pending reservations (no payment yet) are listed too
    $eligible = $pdo->query(
        "SELECT v.reservation_id, v.passenger_name, v.train_name, v.source_code, v.destination_code,
                v.journey_date, v.seat_number, v.total_fare, v.reservation_status, p.amount AS amount_paid
         FROM v_reservation_details v
         LEFT JOIN payment p ON p.reservation_id = v.reservation_id AND p.payment_status = 'Success'
         WHERE v.reservation_status IN ('Pending', 'Confirmed') AND v.journey_date >= CURDATE()
         ORDER BY v.journey_date, v.reservation_id"
    )->fetchAll();

    $history = $pdo->query(
        'SELECT c.cancellation_id, c.reservation_id, v.passenger_name, v.train_name, v.source_code, v.destination_code,
                v.journey_date, v.total_fare, c.refund_amount, c.reason, c.cancellation_date, v.reservation_status
         FROM cancellation c
         INNER JOIN v_reservation_details v ON v.reservation_id = c.reservation_id
         ORDER BY c.cancellation_id DESC'
    )->fetchAll();
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load cancellations.'));
}

if (isset($_GET['reservation'])) {
    foreach ($eligible as $r) {
        if ((int)$r['reservation_id'] === (int)$_GET['reservation']) {
            $selected = $r;
        }
    }
    if (!$selected) {
        flash('warning', 'Reservation #' . (int)$_GET['reservation'] . ' is not eligible for cancellation.');
    }
}

// Refund preview shown before the user confirms (same rule as in the POST code above)
$previewRefund = 0.00;
if ($selected && $selected['amount_paid'] !== null) {
    $previewRefund = round((float)$selected['amount_paid'] * REFUND_PERCENT / 100, 2);
}

$pageTitle = 'Cancellations';
require __DIR__ . '/header.php';
?>
<h1 class="h4 mb-3">Cancellations</h1>

<div class="alert alert-info">
    <strong>Refund policy:</strong> a paid (Confirmed) reservation gets <?= e(REFUND_PERCENT) ?>% of the amount paid back.
    An unpaid (Pending) reservation can be cancelled with no refund. Journeys in the past cannot be cancelled.
</div>

<?php if ($selected): ?>
<div class="card mb-4 border-danger">
    <div class="card-header">Cancel reservation #<?= e($selected['reservation_id']) ?></div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-6">
                <div><strong>Passenger:</strong> <?= e($selected['passenger_name']) ?></div>
                <div><strong>Train:</strong> <?= e($selected['train_name']) ?></div>
                <div><strong>Route:</strong> <?= e($selected['source_code']) ?> to <?= e($selected['destination_code']) ?>
                     on <?= e($selected['journey_date']) ?>, seat <?= e($selected['seat_number']) ?></div>
            </div>
            <div class="col-md-6">
                <div><strong>Status:</strong> <?= status_badge($selected['reservation_status']) ?></div>
                <div><strong>Fare:</strong> <?= e(money($selected['total_fare'])) ?></div>
                <div><strong>Amount paid:</strong> <?= $selected['amount_paid'] === null ? 'Nothing paid yet' : e(money($selected['amount_paid'])) ?></div>
                <div class="fs-5"><strong>Refund if cancelled:</strong> <?= e(money($previewRefund)) ?></div>
            </div>
        </div>
        <form method="post" action="cancellations.php"
              onsubmit="return confirm('Cancel this reservation? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_id" value="<?= e($selected['reservation_id']) ?>">
            <div class="mb-3">
                <label class="form-label" for="reason">Cancellation reason</label>
                <textarea class="form-control" id="reason" name="reason" rows="2" minlength="5" maxlength="255" required></textarea>
            </div>
            <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
            <a href="cancellations.php" class="btn btn-outline-secondary">Back</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Reservations that can be cancelled (<?= count($eligible) ?>)</div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr><th>ID</th><th>Passenger</th><th>Train</th><th>Route</th><th>Date</th><th>Seat</th>
                    <th>Fare</th><th>Status</th><th>Refund</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$eligible): ?>
                <tr><td colspan="10" class="text-center text-muted py-3">No reservations can be cancelled right now.</td></tr>
            <?php endif; ?>
            <?php foreach ($eligible as $r): ?>
                <tr>
                    <td><?= e($r['reservation_id']) ?></td>
                    <td><?= e($r['passenger_name']) ?></td>
                    <td><?= e($r['train_name']) ?></td>
                    <td><?= e($r['source_code']) ?> to <?= e($r['destination_code']) ?></td>
                    <td><?= e($r['journey_date']) ?></td>
                    <td><?= e($r['seat_number']) ?></td>
                    <td><?= e(money($r['total_fare'])) ?></td>
                    <td><?= status_badge($r['reservation_status']) ?></td>
                    <td><?= e(money($r['amount_paid'] === null ? 0 : round((float)$r['amount_paid'] * REFUND_PERCENT / 100, 2))) ?></td>
                    <td><a href="cancellations.php?reservation=<?= e($r['reservation_id']) ?>" class="btn btn-outline-danger btn-sm">Select</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Cancellation history (<?= count($history) ?>)</div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr><th>ID</th><th>Reservation</th><th>Passenger</th><th>Train</th><th>Route</th><th>Journey</th>
                    <th>Fare</th><th>Refund</th><th>Reason</th><th>Cancelled on</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="11" class="text-center text-muted py-3">No cancellations yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $c): ?>
                <tr>
                    <td><?= e($c['cancellation_id']) ?></td>
                    <td>#<?= e($c['reservation_id']) ?></td>
                    <td><?= e($c['passenger_name']) ?></td>
                    <td><?= e($c['train_name']) ?></td>
                    <td><?= e($c['source_code']) ?> to <?= e($c['destination_code']) ?></td>
                    <td><?= e($c['journey_date']) ?></td>
                    <td><?= e(money($c['total_fare'])) ?></td>
                    <td><?= e(money($c['refund_amount'])) ?></td>
                    <td><?= e($c['reason']) ?></td>
                    <td><?= e($c['cancellation_date']) ?></td>
                    <td><?= status_badge($c['reservation_status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
