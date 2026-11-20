<?php
session_start();
// Already logged in? Go straight to the right screen.
if (isset($_SESSION['role'])) {
    $go = ['Admin' => 'admin_dashboard.php', 'Client' => 'pos_order.php', 'Staff' => 'staff_portal.php'][$_SESSION['role']] ?? 'login.php';
    header("Location: $go");
    exit();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Inventory - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="landing">
    <img src="logo.png" alt="Don Felix Sizzling House">
    <div class="cta">
        <h1>Smart Inventory</h1>
        <a href="login.php" class="btn">Log in</a>
        <a href="register.php" class="btn ghost">Create account</a>
        <small>Web Development 2 Term Project &bull; OLFU Antipolo</small>
    </div>
</div>
</body>
</html>
