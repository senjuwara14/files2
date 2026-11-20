<?php
// Shared helpers used by every page: session, login guard, messages,
// CSRF protection, formatting, stock logging and the top bar.
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function go($url) { header('Location: ' . $url); exit(); }

function home_for($role) {
    $m = ['Admin' => 'admin_dashboard.php', 'Staff' => 'staff_portal.php', 'Client' => 'pos_order.php'];
    return $m[$role] ?? 'login.php';
}
function require_role(array $roles) {
    if (!isset($_SESSION['user'], $_SESSION['role'])) { go('login.php'); }
    if (!in_array($_SESSION['role'], $roles, true)) { go(home_for($_SESSION['role'])); }
}

// One-time messages that survive a redirect (so refreshing never repeats an action)
function flash($type, $msg) { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function flash_html() {
    if (!isset($_SESSION['flash'])) { return ''; }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = ['ok' => 'msg', 'error' => 'msg error', 'info' => 'msg info'][$f['type']] ?? 'msg';
    return '<div class="' . $cls . '">' . e($f['msg']) . '</div>';
}

// CSRF: every form carries a secret token so other websites can't submit it for you
function csrf_token() {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
    $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    if (!$ok) {
        flash('error', 'That page expired. Please try again.');
        go(basename($_SERVER['SCRIPT_NAME']));
    }
}

// Formatting
function fmt_qty($v) { return rtrim(rtrim(number_format((float)$v, 2), '0'), '.'); }
function peso($v) { return '₱' . number_format((float)$v, 2); }
function when_str($ts) {
    $t = strtotime((string)$ts);
    if (!$t) { return ''; }
    return (date('Y-m-d', $t) === date('Y-m-d') ? 'Today ' : date('M j, ', $t)) . date('g:i a', $t);
}
function short_category($c) {
    $m = ['Meat & Poultry' => 'Meat', 'Produce' => 'Produce', 'Dry Goods & Seasonings' => 'Dry goods', 'Packaging & Disposables' => 'Packaging'];
    return $m[$c] ?? $c;
}
// low = at/below the alert level, soon = within 50% above it, ok = healthy
function stock_level($stock, $min) {
    $stock = (float)$stock; $min = (float)$min;
    $lvl = $stock <= $min ? 'low' : ($stock <= $min * 1.5 ? 'soon' : 'ok');
    $pct = (int)max(2, min(100, round($stock / max($min * 4, 0.01) * 100)));
    return [$lvl, $pct];
}
function level_label($lvl) { return ['low' => 'Low', 'soon' => 'Getting low', 'ok' => 'OK'][$lvl]; }
function action_label($type) {
    $m = [
        'STOCK IN' => ['Received', 'act-in'], 'STOCK OUT' => ['Removed', 'act-out'],
        'POS SALE' => ['Sale', 'act-sale'], 'WASTE/SPOILAGE' => ['Waste', 'act-out'],
        'OWNER EDIT' => ['Edited by owner', 'act-out'], 'VOID RETURN' => ['Order voided', 'act-in'],
    ];
    return $m[$type] ?? [$type, 'act-out'];
}

// Every stock change is written to the log with before/after numbers
function log_move($conn, $item_id, $name, $type, $qty, $unit, $before, $after, $by, $notes) {
    $st = $conn->prepare("INSERT INTO logs (item_id, item_name, action_type, quantity, unit, stock_before, stock_after, logged_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->bind_param("issdsddss", $item_id, $name, $type, $qty, $unit, $before, $after, $by, $notes);
    $st->execute();
}

// Top bar with logo, role-based tabs, user name and logout
function app_header($active) {
    $role = $_SESSION['role'] ?? '';
    $tabs = [];
    if ($role === 'Admin') {
        $tabs = [
            'admin'  => ['Dashboard', 'admin_dashboard.php'], 'pos'    => ['Cashier', 'pos_order.php'],
            'staff'  => ['Kitchen', 'staff_portal.php'],      'orders' => ['Orders', 'orders.php'],
            'manage' => ['Manage', 'admin_manage.php'],
        ];
    }
    $roleName = ['Admin' => 'Owner', 'Staff' => 'Kitchen', 'Client' => 'Cashier'][$role] ?? $role;
    echo '<div class="topbar"><div class="in">';
    echo '<img src="logo-badge.png" alt="Don Felix Sizzling House">';
    echo '<nav class="nav">';
    foreach ($tabs as $key => $t) {
        echo '<a href="' . $t[1] . '" class="' . ($key === $active ? 'on' : '') . '">' . $t[0] . '</a>';
    }
    echo '</nav>';
    echo '<div class="who"><span>' . e($_SESSION['user'] ?? '') . ' &middot; ' . e($roleName) . '</span><a href="logout.php">Log out</a></div>';
    echo '</div></div>';
}
