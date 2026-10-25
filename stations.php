<?php
// stations.php - add, search, edit and delete stations.
require_once __DIR__ . '/db.php';

function validate_station(array $input): array
{
    $errors = [];
    $data = [
        'station_name' => trim($input['station_name'] ?? ''),
        'station_code' => strtoupper(trim($input['station_code'] ?? '')),
        'city'         => trim($input['city'] ?? ''),
        'state'        => trim($input['state'] ?? ''),
    ];

    if (in_array('', $data, true)) {
        $errors[] = 'All fields are required.';
        return [$errors, $data];
    }
    if (mb_strlen($data['station_name']) > 100 || mb_strlen($data['city']) > 60 || mb_strlen($data['state']) > 60) {
        $errors[] = 'Station name (max 100), city (max 60) and state (max 60) are too long.';
    }
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $data['station_code'])) {
        $errors[] = 'Station code must be 2 to 10 letters or digits (for example NDLS).';
    }
    return [$errors, $data];
}

$formData = ['station_name' => '', 'station_code' => '', 'city' => '', 'state' => ''];
$editId = 0;

if (is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' || $action === 'update') {
            [$errors, $formData] = validate_station($_POST);
            $editId = ($action === 'update') ? (int)($_POST['station_id'] ?? 0) : 0;

            if (!$errors) {
                if ($action === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO station (station_name, station_code, city, state)
                         VALUES (:station_name, :station_code, :city, :state)'
                    );
                    $stmt->execute($formData);
                    flash('success', 'Station added successfully.');
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE station SET station_name = :station_name, station_code = :station_code,
                                city = :city, state = :state
                         WHERE station_id = :id'
                    );
                    $stmt->execute($formData + ['id' => $editId]);
                    flash('success', 'Station updated successfully.');
                }
                redirect('stations.php');
            }
            foreach ($errors as $message) {
                flash('danger', $message);
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM station WHERE station_id = :id');
            $stmt->execute(['id' => (int)($_POST['station_id'] ?? 0)]);
            flash($stmt->rowCount() ? 'success' : 'warning',
                  $stmt->rowCount() ? 'Station deleted.' : 'Station not found.');
            redirect('stations.php');
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'A station with this code already exists.');
        } elseif ($action === 'delete') {
            flash('danger', db_error_message($e, 'The station could not be deleted.'));
            redirect('stations.php');
        } else {
            flash('danger', db_error_message($e, 'The station could not be saved.'));
        }
    }
}

if (!is_post() && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT station_name, station_code, city, state FROM station WHERE station_id = :id');
    $stmt->execute(['id' => $editId]);
    $found = $stmt->fetch();
    if ($found) {
        $formData = $found;
    } else {
        flash('warning', 'Station not found.');
        $editId = 0;
    }
}

// Search by name or code
$search = trim($_GET['q'] ?? '');
$sql = 'SELECT station_id, station_name, station_code, city, state FROM station';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE station_name LIKE :name OR station_code LIKE :code';
    $params = ['name' => '%' . $search . '%', 'code' => '%' . $search . '%'];
}
$sql .= ' ORDER BY station_name';

$stations = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stations = $stmt->fetchAll();
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load stations.'));
}

$pageTitle = 'Stations';
require __DIR__ . '/header.php';
?>
<h1 class="h4 mb-3">Stations</h1>

<div class="card mb-4">
    <div class="card-header"><?= $editId ? 'Edit station #' . e($editId) : 'Add a station' ?></div>
    <div class="card-body">
        <form method="post" action="stations.php" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editId ? 'update' : 'add' ?>">
            <?php if ($editId): ?><input type="hidden" name="station_id" value="<?= e($editId) ?>"><?php endif; ?>

            <div class="col-md-3">
                <label class="form-label" for="station_name">Station name</label>
                <input type="text" class="form-control" id="station_name" name="station_name" maxlength="100" required value="<?= e($formData['station_name']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="station_code">Code</label>
                <input type="text" class="form-control text-uppercase" id="station_code" name="station_code" maxlength="10" pattern="[A-Za-z0-9]{2,10}" required value="<?= e($formData['station_code']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="city">City</label>
                <input type="text" class="form-control" id="city" name="city" maxlength="60" required value="<?= e($formData['city']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="state">State</label>
                <input type="text" class="form-control" id="state" name="state" maxlength="60" required value="<?= e($formData['state']) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary"><?= $editId ? 'Update' : 'Add Station' ?></button>
                <?php if ($editId): ?><a href="stations.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>All stations (<?= count($stations) ?>)</span>
        <form method="get" action="stations.php" class="d-flex gap-2">
            <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by name or code" value="<?= e($search) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($search !== ''): ?><a href="stations.php" class="btn btn-outline-secondary btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr><th>ID</th><th>Station name</th><th>Code</th><th>City</th><th>State</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$stations): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No stations found.</td></tr>
            <?php endif; ?>
            <?php foreach ($stations as $s): ?>
                <tr>
                    <td><?= e($s['station_id']) ?></td>
                    <td><?= e($s['station_name']) ?></td>
                    <td><span class="badge bg-secondary"><?= e($s['station_code']) ?></span></td>
                    <td><?= e($s['city']) ?></td>
                    <td><?= e($s['state']) ?></td>
                    <td class="text-nowrap">
                        <a href="stations.php?edit=<?= e($s['station_id']) ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                        <form method="post" action="stations.php" class="d-inline"
                              onsubmit="return confirm('Delete this station?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="station_id" value="<?= e($s['station_id']) ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
