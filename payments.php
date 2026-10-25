<?php
// payments.php - SIMULATED payment. No real card data is collected and no gateway is used.
//
// Data model decision: one reservation may have several payment rows (for example a Failed
// attempt and then a Success), but at most ONE Success row (enforced by a UNIQUE key in schema.sql).
// A successful payment changes the reservation status from Pending to Confirmed.
require_once __DIR__ . '/db.php';

$paymentMethods = ['UPI', 'Credit Card', 'Debit Card', 'Net Banking'];

if (is_post()) {
    check_csrf();

    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $method = $_POST['payment_method'] ?? '';
    $simulateFailure = isset($_POST['simulate_failure']);

    if ($reservationId <= 0 || !in_array($method, $paymentMethods, true)) {
        flash('danger', 'Please select a reservation and a payment method.');
        redirect('payments.php');
    }

    try {
        $pdo->beginTransaction();

        // Lock the reservation row so two simultaneous payments cannot both succeed
        $stmt = $pdo->prepare(
            'SELECT reservation_id, status, total_fare, journey_date FROM reservation WHERE reservation_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $reservationId]);
        $reservation = $stmt->fetch();

        if (!$reservation) {
            throw new RuntimeException('The reservation does not exist.');
        }
        if ($reservation['status'] === 'Cancelled') {
            throw new RuntimeException('A cancelled reservation cannot be paid for.');
        }
        if ($reservation['status'] === 'Confirmed') {
            throw new RuntimeException('Payment has already been recorded for this reservation.');
        }
        if ($reservation['journey_date'] < date('Y-m-d')) {
            throw new RuntimeException('The journey date has passed, so this reservation cannot be paid for.');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment WHERE reservation_id = :id AND payment_status = 'Success'");
        $stmt->execute(['id' => $reservationId]);
        if ($stmt->fetchColumn() > 0) {
            throw new RuntimeException('Payment has already been recorded for this reservation.');
        }

        // Unique transaction id, for example TXN20261025153000A1B2C3
        $transactionId = 'TXN' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        $paymentStatus = $simulateFailure ? 'Failed' : 'Success';

        $stmt = $pdo->prepare(
            'INSERT INTO payment (reservation_id, transaction_id, payment_method, amount, payment_status)
             VALUES (:reservation, :txn, :method, :amount, :status)'
        );
        $stmt->execute([
            'reservation' => $reservationId, 'txn' => $transactionId, 'method' => $method,
            'amount' => $reservation['total_fare'], 'status' => $paymentStatus,
        ]);

        if ($paymentStatus === 'Success') {
            $stmt = $pdo->prepare("UPDATE reservation SET status = 'Confirmed' WHERE reservation_id = :id AND status = 'Pending'");
            $stmt->execute(['id' => $reservationId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('The reservation could not be confirmed.');
            }
        }

        $pdo->commit();

        if ($paymentStatus === 'Success') {
            flash('success', "Payment successful. Transaction ID: $transactionId | Payment status: Success | Reservation #$reservationId is now Confirmed.");
        } else {
            flash('warning', "Simulated payment FAILED. Transaction ID: $transactionId | Payment status: Failed | Reservation #$reservationId is still Pending.");
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();       // nothing is saved if any statement failed
        }
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'Payment has already been recorded for this reservation.');
            error_log('Duplicate payment: ' . $e->getMessage());
        } else {
            flash('danger', db_error_message($e, 'The payment could not be completed. Nothing was saved.'));
        }
    }
    redirect('payments.php');
}

// ---------------------------------------------------------------------
// Load eligible reservations and payment history
// ---------------------------------------------------------------------
$eligible = $history = [];
try {
    // Eligible = Pending, journey not in the past, and no successful payment yet
    $eligible = $pdo->query(
        "SELECT v.reservation_id, v.passenger_name, v.train_name, v.source_code, v.destination_code,
                v.journey_date, v.total_fare
         FROM v_reservation_details v
         WHERE v.reservation_status = 'Pending'
           AND v.journey_date >= CURDATE()
           AND NOT EXISTS (SELECT 1 FROM payment p
                           WHERE p.reservation_id = v.reservation_id AND p.payment_status = 'Success')
         ORDER BY v.reservation_id"
    )->fetchAll();

    $history = $pdo->query(
        'SELECT p.payment_id, p.transaction_id, p.reservation_id, v.passenger_name, v.train_name,
                p.payment_method, p.amount, p.payment_date, p.payment_status
         FROM payment p
         INNER JOIN v_reservation_details v ON v.reservation_id = p.reservation_id
         ORDER BY p.payment_id DESC'
    )->fetchAll();
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load payments.'));
}

$selected = (int)($_GET['reservation'] ?? 0);
$selectedExists = false;
foreach ($eligible as $r) {
    if ((int)$r['reservation_id'] === $selected) {
        $selectedExists = true;
    }
}
if ($selected && !$selectedExists) {
    flash('warning', 'Reservation #' . $selected . ' is not waiting for payment.');
    $selected = 0;
}

$pageTitle = 'Payments';
require __DIR__ . '/header.php';
?>
<h1 class="h4 mb-3">Payments</h1>

<div class="card mb-4">
    <div class="card-header">Pay for a reservation</div>
    <div class="card-body">
        <?php if (!$eligible): ?>
            <p class="text-muted mb-0">There are no reservations waiting for payment. Create one on the
                <a href="reservations.php#create">Reservations</a> page.</p>
        <?php else: ?>
            <form method="post" action="payments.php" class="row g-3">
                <?= csrf_field() ?>
                <div class="col-md-6">
                    <label class="form-label" for="reservation_id">Reservation waiting for payment</label>
                    <select class="form-select" id="reservation_id" name="reservation_id" required>
                        <option value="" data-fare="">Select reservation...</option>
                        <?php foreach ($eligible as $r): ?>
                            <option value="<?= e($r['reservation_id']) ?>" data-fare="<?= e(money($r['total_fare'])) ?>"
                                <?= $selected === (int)$r['reservation_id'] ? 'selected' : '' ?>>
                                #<?= e($r['reservation_id']) ?> - <?= e($r['passenger_name']) ?> - <?= e($r['train_name']) ?>
                                (<?= e($r['source_code']) ?> to <?= e($r['destination_code']) ?>, <?= e($r['journey_date']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="payment_method">Payment method</label>
                    <select class="form-select" id="payment_method" name="payment_method" required>
                        <?php foreach ($paymentMethods as $m): ?>
                            <option value="<?= e($m) ?>"><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-label">Amount to pay</div>
                    <div class="fs-5 fw-semibold" id="fare_display">-</div>
                </div>
                <div class="col-12">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="simulate_failure" name="simulate_failure" value="1">
                        <label class="form-check-label" for="simulate_failure">Demo only: make this attempt fail (records a Failed payment, reservation stays Pending)</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Make Payment</button>
                </div>
            </form>
            <script>
                // Show the fare of the selected reservation (read from the option's data-fare attribute)
                var select = document.getElementById('reservation_id');
                function showFare() {
                    var fare = select.options[select.selectedIndex].getAttribute('data-fare');
                    document.getElementById('fare_display').textContent = fare ? fare : '-';
                }
                select.addEventListener('change', showFare);
                showFare();
            </script>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Payment history (<?= count($history) ?>)</div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr><th>ID</th><th>Transaction ID</th><th>Reservation</th><th>Passenger</th><th>Train</th>
                    <th>Method</th><th>Amount</th><th>Date</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">No payments recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $p): ?>
                <tr>
                    <td><?= e($p['payment_id']) ?></td>
                    <td class="small"><?= e($p['transaction_id']) ?></td>
                    <td>#<?= e($p['reservation_id']) ?></td>
                    <td><?= e($p['passenger_name']) ?></td>
                    <td><?= e($p['train_name']) ?></td>
                    <td><?= e($p['payment_method']) ?></td>
                    <td><?= e(money($p['amount'])) ?></td>
                    <td><?= e($p['payment_date']) ?></td>
                    <td><?= status_badge($p['payment_status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
