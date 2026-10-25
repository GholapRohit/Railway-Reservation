<?php
// header.php - top of every page: HTML head, navigation bar and flash messages.
// A page sets $pageTitle before including this file.
require_once __DIR__ . '/db.php';

$currentPage = basename($_SERVER['SCRIPT_NAME']);
$pageTitle = $pageTitle ?? 'Railway Reservation System';

$navLinks = [
    'index.php'        => 'Home',
    'passengers.php'   => 'Passengers',
    'trains.php'       => 'Trains & Search',
    'stations.php'     => 'Stations',
    'reservations.php' => 'Reservations',
    'payments.php'     => 'Payments',
    'cancellations.php' => 'Cancellations',
    'sql_reports.php'  => 'SQL Reports',
];
// search_trains.php belongs to the "Trains & Search" menu item
$activePage = ($currentPage === 'search_trains.php') ? 'trains.php' : $currentPage;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - Railway Reservation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navbar-railway">
    <div class="container">
        <a class="navbar-brand" href="index.php">Railway Reservation</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto">
                <?php foreach ($navLinks as $file => $label): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $file === $activePage ? ' active' : '' ?>" href="<?= e($file) ?>"><?= e($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="container my-4">
<?php
// Show and clear flash messages (success / error notices set before a redirect)
if (!empty($_SESSION['flash'])) {
    foreach ($_SESSION['flash'] as $msg) {
        echo '<div class="alert alert-' . e($msg['type']) . ' alert-dismissible fade show" role="alert">'
           . e($msg['message'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
}
