<?php
// Shared top bar: logo, role-based navigation, user name, logout.
function app_header($active) {
    $role = $_SESSION['role'] ?? '';
    $tabs = [];
    if ($role === 'Admin') {
        $tabs = ['admin' => ['Dashboard', 'admin_dashboard.php'], 'pos' => ['Cashier', 'pos_order.php'], 'staff' => ['Kitchen', 'staff_portal.php']];
    }
    echo '<div class="topbar"><div class="in">';
    echo '<img src="logo-badge.png" alt="Don Felix Sizzling House">';
    echo '<nav class="nav">';
    foreach ($tabs as $key => $t) {
        echo '<a href="' . $t[1] . '" class="' . ($key === $active ? 'on' : '') . '">' . $t[0] . '</a>';
    }
    echo '</nav>';
    echo '<div class="who"><span>' . htmlspecialchars($_SESSION['user'] ?? '') . ' &middot; ' . htmlspecialchars($role) . '</span><a href="logout.php">Log out</a></div>';
    echo '</div></div>';
}
