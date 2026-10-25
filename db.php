<?php
// db.php - settings, database connection and small helper functions.
// Every page includes this file (through header.php or directly).

// ---------------------------------------------------------------------
// 1. SETTINGS - change these to match your XAMPP / MySQL installation
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'railway_reservation');
define('DB_USER', 'root');
define('DB_PASS', '');               // XAMPP default: empty password

define('APP_ENV', 'development');    // 'development' shows technical error details; use 'production' to hide them

// Business rules used by the pages
define('REFUND_PERCENT', 80);        // cancellation refund = 80% of the amount paid (Pending/unpaid = 0)
define('MAX_SEAT_NUMBER', 72);       // seats are numbered 1..72 (must match the CHECK in schema.sql)
define('MAX_FARE', 50000);           // largest fare accepted by the reservation form

date_default_timezone_set('Asia/Kolkata');

if (APP_ENV !== 'development') {
    ini_set('display_errors', '0');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();                 // sessions are only used for flash messages and the CSRF token
}

// ---------------------------------------------------------------------
// 2. DATABASE CONNECTION (PDO with prepared statements)
// ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // errors become exceptions
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,                   // real prepared statements
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;margin:2rem">';
    echo '<h2>Cannot connect to the database</h2>';
    echo '<p>Check that MySQL is running, that you imported <code>database/schema.sql</code>, ';
    echo 'and that the settings at the top of <code>db.php</code> are correct.</p>';
    if (APP_ENV === 'development') {
        echo '<p><small>Details (development mode only): ' . htmlspecialchars($e->getMessage()) . '</small></p>';
    }
    echo '</body></html>';
    exit;
}

// ---------------------------------------------------------------------
// 3. HELPER FUNCTIONS
// ---------------------------------------------------------------------

// Escape text before printing it in HTML.
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Send the browser to another page and stop.
function redirect(string $page): void
{
    header('Location: ' . $page);
    exit;
}

// Flash messages: stored in the session and shown once by header.php.
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];   // type: success, danger, warning, info
}

// CSRF protection: every POST form contains a hidden token that must match the session.
function csrf_field(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function check_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        exit('Invalid or missing security token. Go back, reload the page and try again.');
    }
}

// True when the page was opened by submitting a POST form.
function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

// Check that a string is a real date written as YYYY-MM-DD.
function is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

// Turn a database exception into a safe message for the user.
// The technical message goes to the PHP error log; it is shown on screen only in development mode.
function db_error_message(PDOException $e, string $fallback = 'A database error occurred. Please try again.'): string
{
    error_log('Database error: ' . $e->getMessage());
    $code = (int)($e->errorInfo[1] ?? 0);

    if ($code === 1451) return 'This record is used by other records, so the database does not allow it to be deleted.';
    if ($code === 1452) return 'A related record does not exist.';
    if ($code === 1062) return 'A record with the same unique value already exists.';
    if ($code === 3819 || $code === 4025) return 'The data was rejected by a database constraint.';

    return APP_ENV === 'development' ? $fallback . ' (' . $e->getMessage() . ')' : $fallback;
}

// Format a money value, e.g. 2850 -> Rs. 2,850.00
function money($amount): string
{
    return 'Rs. ' . number_format((float)$amount, 2);
}

// Bootstrap colour for a status word.
function status_badge(string $status): string
{
    $colours = [
        'Confirmed' => 'success', 'Success' => 'success', 'Scheduled' => 'primary', 'Running' => 'info',
        'Pending' => 'warning', 'Cancelled' => 'danger', 'Failed' => 'danger', 'Completed' => 'secondary',
    ];
    $colour = $colours[$status] ?? 'secondary';
    $textClass = ($colour === 'warning' || $colour === 'info') ? ' text-dark' : '';
    return '<span class="badge bg-' . $colour . $textClass . '">' . e($status) . '</span>';
}
