<?php
// trains.php - add, edit and delete trains; view the schedules and route (halts) of a train.
// A schedule can be added for a new date; it copies the route of the train's latest schedule.
require_once __DIR__ . '/db.php';

$trainTypes = ['Rajdhani', 'Duronto', 'Superfast', 'Express', 'Intercity', 'Passenger'];

function validate_train(array $input, array $trainTypes): array
{
    $errors = [];
    $data = [
        'train_number' => strtoupper(trim($input['train_number'] ?? '')),
        'train_name'   => trim($input['train_name'] ?? ''),
        'train_type'   => $input['train_type'] ?? '',
    ];
    if (in_array('', $data, true)) {
        $errors[] = 'All fields are required.';
        return [$errors, $data];
    }
    if (!preg_match('/^[A-Z0-9]{3,10}$/', $data['train_number'])) {
        $errors[] = 'Train number must be 3 to 10 letters or digits.';
    }
    if (mb_strlen($data['train_name']) < 3 || mb_strlen($data['train_name']) > 100) {
        $errors[] = 'Train name must be 3 to 100 characters long.';
    }
    if (!in_array($data['train_type'], $trainTypes, true)) {
        $errors[] = 'Please select a valid train type.';
    }
    return [$errors, $data];
}

$formData = ['train_number' => '', 'train_name' => '', 'train_type' => ''];
$editId = 0;
$viewId = (int)($_GET['view'] ?? 0);        // train whose schedules are shown
$scheduleId = (int)($_GET['schedule'] ?? 0); // schedule whose halts are shown

if (is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' || $action === 'update') {
            [$errors, $formData] = validate_train($_POST, $trainTypes);
            $editId = ($action === 'update') ? (int)($_POST['train_id'] ?? 0) : 0;

            if (!$errors) {
                if ($action === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO train (train_number, train_name, train_type) VALUES (:train_number, :train_name, :train_type)'
                    );
                    $stmt->execute($formData);
                    flash('success', 'Train added successfully.');
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE train SET train_number = :train_number, train_name = :train_name, train_type = :train_type
                         WHERE train_id = :id'
                    );
                    $stmt->execute($formData + ['id' => $editId]);
                    flash('success', 'Train updated successfully.');
                }
                redirect('trains.php');
            }
            foreach ($errors as $message) {
                flash('danger', $message);
            }

        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM train WHERE train_id = :id');
            $stmt->execute(['id' => (int)($_POST['train_id'] ?? 0)]);
            flash($stmt->rowCount() ? 'success' : 'warning',
                  $stmt->rowCount() ? 'Train deleted.' : 'Train not found.');
            redirect('trains.php');

        } elseif ($action === 'add_schedule') {
            // Add a schedule for a new date and copy the halts of the latest existing schedule
            $trainId = (int)($_POST['train_id'] ?? 0);
            $date = trim($_POST['journey_date'] ?? '');
            $back = 'trains.php?view=' . $trainId;

            if (!is_valid_date($date) || $date < date('Y-m-d')) {
                flash('danger', 'Please choose a journey date that is today or later.');
                redirect($back);
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT schedule_id FROM train_schedule WHERE train_id = :id ORDER BY journey_date DESC LIMIT 1');
                $stmt->execute(['id' => $trainId]);
                $templateId = $stmt->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO train_schedule (train_id, journey_date, status) VALUES (:train, :date, 'Scheduled')");
                $stmt->execute(['train' => $trainId, 'date' => $date]);
                $newScheduleId = (int)$pdo->lastInsertId();

                $copied = 0;
                if ($templateId) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO train_halt (schedule_id, station_id, seq_number, arrival_time, departure_time, halt_duration)
                         SELECT :new_id, station_id, seq_number, arrival_time, departure_time, halt_duration
                         FROM train_halt WHERE schedule_id = :old_id'
                    );
                    $stmt->execute(['new_id' => $newScheduleId, 'old_id' => $templateId]);
                    $copied = $stmt->rowCount();
                }
                $pdo->commit();
                flash('success', "Schedule added for $date with $copied halts copied from the previous schedule.");
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    flash('danger', 'This train already has a schedule on that date.');
                } else {
                    flash('danger', db_error_message($e, 'The schedule could not be added.'));
                }
            }
            redirect($back);

        } elseif ($action === 'delete_schedule') {
            $trainId = (int)($_POST['train_id'] ?? 0);
            $scheduleToDelete = (int)($_POST['schedule_id'] ?? 0);

            // Reservations are linked to a train and a date, so refuse if any exist for this schedule
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM reservation r
                 INNER JOIN train_schedule ts ON ts.train_id = r.train_id AND ts.journey_date = r.journey_date
                 WHERE ts.schedule_id = :id'
            );
            $stmt->execute(['id' => $scheduleToDelete]);
            if ($stmt->fetchColumn() > 0) {
                flash('danger', 'This schedule has reservations, so it cannot be deleted.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM train_schedule WHERE schedule_id = :id AND train_id = :train');
                $stmt->execute(['id' => $scheduleToDelete, 'train' => $trainId]);
                flash('success', 'Schedule deleted (its halts were deleted with it).');
            }
            redirect('trains.php?view=' . $trainId);
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'A train with this number already exists.');
        } else {
            flash('danger', db_error_message($e, 'The operation could not be completed.'));
        }
        if ($action === 'delete') {
            redirect('trains.php');
        }
    }
}

if (!is_post() && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT train_number, train_name, train_type FROM train WHERE train_id = :id');
    $stmt->execute(['id' => $editId]);
    $found = $stmt->fetch();
    if ($found) {
        $formData = $found;
    } else {
        flash('warning', 'Train not found.');
        $editId = 0;
    }
}

// ---------------------------------------------------------------------
// Load data for the page
// ---------------------------------------------------------------------
$trains = [];
$viewTrain = null;
$schedules = [];
$halts = [];
try {
    // LEFT JOIN + GROUP BY keeps trains that have no schedules
    $trains = $pdo->query(
        'SELECT t.train_id, t.train_number, t.train_name, t.train_type, COUNT(ts.schedule_id) AS schedule_count
         FROM train t
         LEFT JOIN train_schedule ts ON ts.train_id = t.train_id
         GROUP BY t.train_id, t.train_number, t.train_name, t.train_type
         ORDER BY t.train_number'
    )->fetchAll();

    if ($viewId) {
        $stmt = $pdo->prepare('SELECT train_id, train_number, train_name FROM train WHERE train_id = :id');
        $stmt->execute(['id' => $viewId]);
        $viewTrain = $stmt->fetch();

        if ($viewTrain) {
            $stmt = $pdo->prepare(
                'SELECT schedule_id, journey_date, status FROM train_schedule WHERE train_id = :id ORDER BY journey_date'
            );
            $stmt->execute(['id' => $viewId]);
            $schedules = $stmt->fetchAll();

            if ($scheduleId) {
                $stmt = $pdo->prepare(
                    'SELECT h.seq_number, s.station_name, s.station_code, h.arrival_time, h.departure_time, h.halt_duration
                     FROM train_halt h
                     INNER JOIN station s ON s.station_id = h.station_id
                     INNER JOIN train_schedule ts ON ts.schedule_id = h.schedule_id
                     WHERE h.schedule_id = :sid AND ts.train_id = :tid
                     ORDER BY h.seq_number'
                );
                $stmt->execute(['sid' => $scheduleId, 'tid' => $viewId]);
                $halts = $stmt->fetchAll();
            }
        }
    }
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load trains.'));
}

$pageTitle = 'Trains';
require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Trains</h1>
    <a href="search_trains.php" class="btn btn-primary">Search Trains Between Stations</a>
</div>

<div class="card mb-4">
    <div class="card-header"><?= $editId ? 'Edit train #' . e($editId) : 'Add a train' ?></div>
    <div class="card-body">
        <form method="post" action="trains.php" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editId ? 'update' : 'add' ?>">
            <?php if ($editId): ?><input type="hidden" name="train_id" value="<?= e($editId) ?>"><?php endif; ?>

            <div class="col-md-3">
                <label class="form-label" for="train_number">Train number</label>
                <input type="text" class="form-control" id="train_number" name="train_number" maxlength="10" pattern="[A-Za-z0-9]{3,10}" required value="<?= e($formData['train_number']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="train_name">Train name</label>
                <input type="text" class="form-control" id="train_name" name="train_name" maxlength="100" required value="<?= e($formData['train_name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="train_type">Type</label>
                <select class="form-select" id="train_type" name="train_type" required>
                    <option value="">Select...</option>
                    <?php foreach ($trainTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= $formData['train_type'] === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary"><?= $editId ? 'Update' : 'Add Train' ?></button>
                <?php if ($editId): ?><a href="trains.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">All trains (<?= count($trains) ?>)</div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr><th>ID</th><th>Number</th><th>Name</th><th>Type</th><th>Schedules</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$trains): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No trains found.</td></tr>
            <?php endif; ?>
            <?php foreach ($trains as $t): ?>
                <tr>
                    <td><?= e($t['train_id']) ?></td>
                    <td><?= e($t['train_number']) ?></td>
                    <td><?= e($t['train_name']) ?></td>
                    <td><?= e($t['train_type']) ?></td>
                    <td><?= e($t['schedule_count']) ?></td>
                    <td class="text-nowrap">
                        <a href="trains.php?view=<?= e($t['train_id']) ?>#schedules" class="btn btn-outline-secondary btn-sm">Schedules</a>
                        <a href="trains.php?edit=<?= e($t['train_id']) ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                        <form method="post" action="trains.php" class="d-inline"
                              onsubmit="return confirm('Delete this train?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="train_id" value="<?= e($t['train_id']) ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($viewTrain): ?>
<div class="card mb-4" id="schedules">
    <div class="card-header">Schedules of <?= e($viewTrain['train_number']) ?> - <?= e($viewTrain['train_name']) ?></div>
    <div class="card-body">
        <form method="post" action="trains.php" class="row g-2 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_schedule">
            <input type="hidden" name="train_id" value="<?= e($viewTrain['train_id']) ?>">
            <div class="col-auto">
                <input type="date" class="form-control" name="journey_date" min="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Add Schedule</button>
            </div>
            <div class="col-auto align-self-center text-muted small">The route (halts) is copied from this train's latest schedule.</div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Schedule ID</th><th>Journey date</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$schedules): ?>
                    <tr><td colspan="4" class="text-muted">No schedules for this train yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td><?= e($s['schedule_id']) ?></td>
                        <td><?= e($s['journey_date']) ?></td>
                        <td><?= status_badge($s['status']) ?></td>
                        <td class="text-nowrap">
                            <a href="trains.php?view=<?= e($viewTrain['train_id']) ?>&schedule=<?= e($s['schedule_id']) ?>#schedules"
                               class="btn btn-outline-secondary btn-sm">View route</a>
                            <form method="post" action="trains.php" class="d-inline"
                                  onsubmit="return confirm('Delete this schedule and its halts?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="train_id" value="<?= e($viewTrain['train_id']) ?>">
                                <input type="hidden" name="schedule_id" value="<?= e($s['schedule_id']) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($scheduleId): ?>
            <h2 class="h6 mt-3">Route for schedule #<?= e($scheduleId) ?> (ordered by seq_number)</h2>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>Seq</th><th>Station</th><th>Code</th><th>Arrival</th><th>Departure</th><th>Halt (min)</th></tr></thead>
                    <tbody>
                    <?php if (!$halts): ?>
                        <tr><td colspan="6" class="text-muted">No halts stored for this schedule.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($halts as $h): ?>
                        <tr>
                            <td><?= e($h['seq_number']) ?></td>
                            <td><?= e($h['station_name']) ?></td>
                            <td><?= e($h['station_code']) ?></td>
                            <td><?= $h['arrival_time'] === null ? '-' : e(substr($h['arrival_time'], 0, 5)) ?></td>
                            <td><?= $h['departure_time'] === null ? '-' : e(substr($h['departure_time'], 0, 5)) ?></td>
                            <td><?= e($h['halt_duration']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($viewId): ?>
    <div class="alert alert-warning">Train not found.</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
