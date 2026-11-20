<?php
require 'app.php';
$msg = "";

// Display banner if redirected from registration
if (isset($_GET['registered'])) {
    $msg = "<div class='msg'>Account created. You can log in now.</div>";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Prepared statement against SQL injection
    $stmt = $conn->prepare("SELECT id, fullname, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['user']     = $user['fullname'];
            $_SESSION['role']     = $user['role'];

            // 3-Tier Role Routing
            if ($user['role'] === 'Admin') {
                header("Location: admin_dashboard.php");
            } elseif ($user['role'] === 'Client') {
                header("Location: pos_order.php");
            } else {
                header("Location: staff_portal.php");
            }
            exit();
        } else {
            $msg = "<div class='msg error'>Wrong username or password.</div>";
        }
    } else {
        $msg = "<div class='msg error'>Wrong username or password.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in - Don Felix Sizzling House</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth">
    <div class="hero"><img src="logo.png" alt="Don Felix Sizzling House"></div>
    <div class="side">
        <div class="box">
            <h1>Welcome back</h1>
            <p class="sub">Log in to manage stock and orders.</p>
            <?php echo $msg; ?>
            <form action="login.php" method="POST">
                <div class="field"><label for="username">Username</label><input id="username" type="text" name="username" autocomplete="username" required autofocus></div>
                <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required></div>
                <button type="submit" class="btn big">Log in</button>
            </form>
            <p class="foot">New here? <a href="register.php">Create an account</a></p>
        </div>
    </div>
</div>
</body>
</html>
