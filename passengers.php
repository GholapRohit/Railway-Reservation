<?php
// passengers.php - add, search, edit and delete passengers. Age is calculated from DOB.
require_once __DIR__ . '/db.php';

$genders = ['Male', 'Female', 'Other'];

// Check the form fields. Returns [list of error messages, cleaned values].
function validate_passenger(array $input, array $genders): array
{
    $errors = [];
    $data = [
        'name'   => trim($input['name'] ?? ''),
        'email'  => trim($input['email'] ?? ''),
        'phone'  => trim($input['phone'] ?? ''),
        'gender' => $input['gender'] ?? '',
        'dob'    => trim($input['dob'] ?? ''),
    ];

    if ($data['name'] === '' || $data['email'] === '' || $data['phone'] === '' || $data['gender'] === '' || $data['dob'] === '') {
        $errors[] = 'All fields are required.';
        return [$errors, $data];
    }
    if (mb_strlen($data['name']) < 2 || mb_strlen($data['name']) > 100) {
        $errors[] = 'Name must be 2 to 100 characters long.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 120) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!preg_match('/^[0-9]{10,15}$/', $data['phone'])) {
        $errors[] = 'Phone number must contain 10 to 15 digits only.';
    }
    if (!in_array($data['gender'], $genders, true)) {
        $errors[] = 'Please select a valid gender.';
    }
    if (!is_valid_date($data['dob'])) {
        $errors[] = 'Date of birth is not a valid date.';
    } elseif ($data['dob'] > date('Y-m-d')) {
        $errors[] = 'Date of birth cannot be in the future.';
    } elseif ($data['dob'] < date('Y-m-d', strtotime('-120 years'))) {
        $errors[] = 'Date of birth is too far in the past.';
    }
    return [$errors, $data];
}

// ---------------------------------------------------------------------
// Handle form submissions (all changes use POST + CSRF check + redirect)
// ---------------------------------------------------------------------
$formData = ['name' => '', 'email' => '', 'phone' => '', 'gender' => '', 'dob' => ''];
$editId = 0;

if (is_post()) {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add' || $action === 'update') {
            [$errors, $formData] = validate_passenger($_POST, $genders);
            $editId = ($action === 'update') ? (int)($_POST['passenger_id'] ?? 0) : 0;

            if (!$errors) {
                if ($action === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO passenger (name, email, phone, gender, dob) VALUES (:name, :email, :phone, :gender, :dob)'
                    );
                    $stmt->execute($formData);
                    flash('success', 'Passenger added successfully.');
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE passenger SET name = :name, email = :email, phone = :phone, gender = :gender, dob = :dob
                         WHERE passenger_id = :id'
                    );
                    $stmt->execute($formData + ['id' => $editId]);
                    flash('success', 'Passenger updated successfully.');
                }
                redirect('passengers.php');
            }
            foreach ($errors as $message) {
                flash('danger', $message);
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM passenger WHERE passenger_id = :id');
            $stmt->execute(['id' => (int)($_POST['passenger_id'] ?? 0)]);
            flash($stmt->rowCount() ? 'success' : 'warning',
                  $stmt->rowCount() ? 'Passenger deleted.' : 'Passenger not found.');
            redirect('passengers.php');
        }
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'A passenger with this email already exists.');
        } else {
            flash('danger', db_error_message($e, 'The passenger could not be saved.'));
        }
        if ($action === 'delete') {
            redirect('passengers.php');
        }
    }
}

// Edit mode: load the passenger into the form when ?edit=ID is in the URL
if (!is_post() && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT name, email, phone, gender, dob FROM passenger WHERE passenger_id = :id');
    $stmt->execute(['id' => $editId]);
    $found = $stmt->fetch();
    if ($found) {
        $formData = $found;
    } else {
        flash('warning', 'Passenger not found.');
        $editId = 0;
    }
}

// ---------------------------------------------------------------------
// Load the list (optionally filtered by the search box)
// ---------------------------------------------------------------------
$search = trim($_GET['q'] ?? '');
$sql = 'SELECT passenger_id, name, email, phone, gender, dob,
               TIMESTAMPDIFF(YEAR, dob, CURDATE()) AS age
        FROM passenger';
$params = [];
if ($search !== '') {
    // Numbers search the ID, any text searches the name
    $sql .= ' WHERE passenger_id = :id OR name LIKE :name';
    $params = ['id' => ctype_digit($search) ? (int)$search : 0, 'name' => '%' . $search . '%'];
}
$sql .= ' ORDER BY passenger_id';

$passengers = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $passengers = $stmt->fetchAll();
} catch (PDOException $e) {
    flash('danger', db_error_message($e, 'Could not load passengers.'));
}

$pageTitle = 'Passengers';
require __DIR__ . '/header.php';
?>
<h1 class="h4 mb-3">Passengers</h1>

<div class="card mb-4">
    <div class="card-header"><?= $editId ? 'Edit passenger #' . e($editId) : 'Add a passenger' ?></div>
    <div class="card-body">
        <form method="post" action="passengers.php" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editId ? 'update' : 'add' ?>">
            <?php if ($editId): ?><input type="hidden" name="passenger_id" value="<?= e($editId) ?>"><?php endif; ?>

            <div class="col-md-4">
                <label class="form-label" for="name">Name</label>
                <input type="text" class="form-control" id="name" name="name" maxlength="100" required value="<?= e($formData['name']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="120" required value="<?= e($formData['email']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="phone">Phone (digits only)</label>
                <input type="text" class="form-control" id="phone" name="phone" maxlength="15" pattern="[0-9]{10,15}" required value="<?= e($formData['phone']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="gender">Gender</label>
                <select class="form-select" id="gender" name="gender" required>
                    <option value="">Select...</option>
                    <?php foreach ($genders as $g): ?>
                        <option value="<?= e($g) ?>" <?= $formData['gender'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dob">Date of birth</label>
                <input type="date" class="form-control" id="dob" name="dob" max="<?= e(date('Y-m-d')) ?>" required value="<?= e($formData['dob']) ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary"><?= $editId ? 'Update Passenger' : 'Add Passenger' ?></button>
                <?php if ($editId): ?><a href="passengers.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>All passengers (<?= count($passengers) ?>)</span>
        <form method="get" action="passengers.php" class="d-flex gap-2">
            <input type="text" class="form-control form-control-sm" name="q" placeholder="Search by ID or name" value="<?= e($search) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <?php if ($search !== ''): ?><a href="passengers.php" class="btn btn-outline-secondary btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Gender</th><th>DOB</th><th>Age</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$passengers): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No passengers found.</td></tr>
            <?php endif; ?>
            <?php foreach ($passengers as $p): ?>
                <tr>
                    <td><?= e($p['passenger_id']) ?></td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['email']) ?></td>
                    <td><?= e($p['phone']) ?></td>
                    <td><?= e($p['gender']) ?></td>
                    <td><?= e($p['dob']) ?></td>
                    <td><?= e($p['age']) ?></td>
                    <td class="text-nowrap">
                        <a href="passengers.php?edit=<?= e($p['passenger_id']) ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                        <form method="post" action="passengers.php" class="d-inline"
                              onsubmit="return confirm('Delete this passenger? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="passenger_id" value="<?= e($p['passenger_id']) ?>">
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
