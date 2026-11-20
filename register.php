<?php
require 'app.php';
$msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    $raw_password = $_POST['password'] ?? '';

    // Enforce allowed ENUM values matching the database schema
    // Owner (Admin) accounts can't be self-registered; the owner already exists.
    $allowed_roles = ['Staff', 'Client'];
    if (!in_array($role, $allowed_roles, true)) {
        $msg = "<div class='msg error'>Choose Kitchen staff or Cashier.</div>";
    } elseif ($fullname === '' || $username === '') {
        $msg = "<div class='msg error'>Please enter your name and a username.</div>";
    } elseif (strlen($raw_password) < 6) {
        $msg = "<div class='msg error'>Password needs at least 6 characters.</div>";
    } else {
        // Check if username already exists using prepared statements
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $msg = "<div class='msg error'>That username is taken. Try another.</div>";
        } else {
            $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);
            $insert_stmt = $conn->prepare("INSERT INTO users (fullname, username, role, password) VALUES (?, ?, ?, ?)");
            $insert_stmt->bind_param("ssss", $fullname, $username, $role, $hashed_password);

            if ($insert_stmt->execute()) {
                header("Location: login.php?registered=1");
                exit();
            } else {
                $msg = "<div class='msg error'>Couldn't create the account. Try again.</div>";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth">
    <div class="hero"><img src="logo.png" alt="Don Felix Sizzling House"></div>
    <div class="side">
        <div class="box">
            <h1>Create account</h1>
            <p class="sub">For kitchen staff and cashiers.</p>
            <?php echo $msg; ?>
            <form action="register.php" method="POST">
                <div class="field"><label for="fullname">Full name</label><input id="fullname" type="text" name="fullname" placeholder="Juan Dela Cruz" required></div>
                <div class="field"><label for="username">Username</label><input id="username" type="text" name="username" autocomplete="username" required></div>
                <div class="field"><label for="role">I work as</label>
                    <select id="role" name="role" required>
                        <option value="Staff">Kitchen staff</option>
                        <option value="Client">Cashier</option>
                    </select>
                </div>
                <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" minlength="6" autocomplete="new-password" required></div>
                <button type="submit" class="btn big">Create account</button>
            </form>
            <p class="foot">Already registered? <a href="login.php">Log in</a></p>
        </div>
    </div>
</div>
</body>
</html>
