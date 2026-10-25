<?php
// index.php - home page: short description, live counts and links to the main features.
$pageTitle = 'Home';
require_once __DIR__ . '/header.php';

// Each statistic is a separate COUNT(*) query (fixed SQL, no user input).
$statQueries = [
    'Passengers'   => 'SELECT COUNT(*) FROM passenger',
    'Trains'       => 'SELECT COUNT(*) FROM train',
    'Reservations' => 'SELECT COUNT(*) FROM reservation',
    'Payments'     => 'SELECT COUNT(*) FROM payment',
];

$stats = [];
try {
    foreach ($statQueries as $label => $sql) {
        $stats[$label] = (int)$pdo->query($sql)->fetchColumn();
    }
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">' . e(db_error_message($e, 'Could not load the statistics. Did you import schema.sql and sample_data.sql?')) . '</div>';
}
?>
<div class="p-4 mb-4 bg-white border rounded">
    <h1 class="h3">Railway Reservation System</h1>
    <p class="mb-0 text-muted">
        Book your train journey with ease. Search for trains between stations, check seat availability and fares, and manage your reservations. View your booking details, track your upcoming journeys, and cancel tickets when needed.
    </p>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($stats as $label => $count): ?>
        <div class="col-6 col-lg-3">
            <div class="card text-center h-100">
                <div class="card-body">
                    <div class="stat-number"><?= e($count) ?></div>
                    <div class="text-muted">Total <?= e($label) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <?php
    $links = [
        ['passengers.php',    'Passengers',        'Add, search, edit and delete passengers.'],
        ['trains.php',        'Trains',            'Manage trains, view schedules and routes.'],
        ['search_trains.php', 'Search Trains',     'Find trains between two stations on a date.'],
        ['stations.php',      'Stations',          'Manage railway stations.'],
        ['reservations.php',  'Reservations',      'Book a seat and view all reservations.'],
        ['payments.php',      'Payments',          'Simulate a payment to confirm a booking.'],
        ['cancellations.php', 'Cancellations',     'Cancel a reservation and see the refund.'],
        ['sql_reports.php',   'SQL Reports',       'Run ready-made SQL reports and see the queries.'],
    ];
    foreach ($links as [$file, $title, $text]): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <h2 class="h6"><?= e($title) ?></h2>
                    <p class="text-muted small flex-grow-1"><?= e($text) ?></p>
                    <a href="<?= e($file) ?>" class="btn btn-primary btn-sm align-self-start">Open</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
