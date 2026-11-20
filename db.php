<?php
// ---------------------------------------------------------------
// Database connection + settings
// ---------------------------------------------------------------
define('DEBUG', true);   // true = show technical details on errors. Set to false when the system goes live.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'don_felix_inventory');

date_default_timezone_set('Asia/Manila');           // PHP clock
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // database errors become exceptions
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');

function friendly_error_page($title, $hint, $detail = '') {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Oops</title></head>';
    echo '<body style="font-family:Segoe UI,Arial,sans-serif;background:#fff8ee;color:#2a1a14;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;">';
    echo '<div style="background:#fff;border:1px solid #f0e3d2;border-top:5px solid #d91e2a;border-radius:14px;padding:28px;max-width:460px;">';
    echo '<h2 style="margin:0 0 8px;">' . htmlspecialchars($title) . '</h2><p style="margin:0 0 14px;color:#7a6a62;">' . htmlspecialchars($hint) . '</p>';
    echo '<a href="index.php" style="color:#d91e2a;font-weight:600;">Back to start</a>';
    if (DEBUG && $detail !== '') {
        echo '<details style="margin-top:16px;color:#7a6a62;font-size:13px;"><summary>Technical details</summary><pre style="white-space:pre-wrap;">' . htmlspecialchars($detail) . '</pre></details>';
    }
    echo '</div></body></html>';
    exit();
}

set_exception_handler(function ($e) {
    error_log('Don Felix: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    friendly_error_page('Something went wrong', "Your last action wasn't saved. Please try again, or tell the owner if it keeps happening.", $e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine());
});

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");       // Philippine time for every timestamp
} catch (mysqli_sql_exception $e) {
    error_log('Don Felix DB connect: ' . $e->getMessage());
    friendly_error_page("Can't reach the database", 'Make sure MySQL is running in XAMPP and that the database "' . DB_NAME . '" has been created and imported.', $e->getMessage());
}

// ---------------------------------------------------------------
// Automatic upgrade: adds the order history + audit columns the
// first time the new version runs, so no extra SQL import is needed.
// ---------------------------------------------------------------
function ensure_schema(mysqli $db) {
    $r = $db->query("SHOW COLUMNS FROM logs LIKE 'stock_before'");
    if ($r && $r->num_rows > 0) { return; }

    $db->query("ALTER TABLE logs MODIFY action_type ENUM('STOCK IN','STOCK OUT','POS SALE','WASTE/SPOILAGE','OWNER EDIT','VOID RETURN') NOT NULL");
    $db->query("CREATE TABLE IF NOT EXISTS orders (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cashier VARCHAR(100) NOT NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Completed','Voided') NOT NULL DEFAULT 'Completed',
        voided_by VARCHAR(100) NULL,
        voided_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query("CREATE TABLE IF NOT EXISTS order_items (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        menu_item_id INT NULL,
        dish_name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        qty INT NOT NULL,
        omitted VARCHAR(255) NULL,
        KEY order_id (order_id),
        CONSTRAINT order_items_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $db->query("CREATE TABLE IF NOT EXISTS order_deductions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        ingredient_id INT NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL,
        unit VARCHAR(20) NOT NULL,
        KEY order_id (order_id),
        CONSTRAINT order_deductions_fk FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // This one goes last: its presence marks the upgrade as finished.
    $db->query("ALTER TABLE logs ADD COLUMN stock_before DECIMAL(10,2) NULL AFTER unit, ADD COLUMN stock_after DECIMAL(10,2) NULL AFTER stock_before");
}
ensure_schema($conn);
