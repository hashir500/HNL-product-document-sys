<?php
session_start();

// Check logged in status and superuser privilege
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['superuser']) || (int)$_SESSION['superuser'] !== 1) {
    http_response_code(403);
    die("Access Denied: You do not have permission to manage users.");
}

require_once __DIR__ . '/db.php';

$message = '';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $useremail = strtolower(trim($_POST['useremail'] ?? ''));
    $is_super  = isset($_POST['superuser']) && $_POST['superuser'] == '1' ? 1 : 0;

    if (empty($username) || empty($useremail)) {
        $message = "Please fill in all required fields.";
        $status = "error";
    } elseif (!filter_var($useremail, FILTER_VALIDATE_EMAIL)) {
        $message = "Please provide a valid email address.";
        $status = "error";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(useremail) = ?");
        $check_stmt->bind_param("s", $useremail);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $message = "A user with this email address already exists.";
            $status = "error";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, useremail, superuser) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $username, $useremail, $is_super);

            if ($stmt->execute()) {
                $message = "User '{$username}' added successfully!";
                $status = "success";
            } else {
                $message = "Database error: Unable to add user.";
                $status = "error";
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Highnoon | Add New User</title>

    <style>
        :root {
            --hn-orange: #f25a22;
            --hn-orange-hover: #d94b18;
            --hn-bg: #f4f6f9;
            --hn-card-bg: #ffffff;
            --hn-text-dark: #2c3e50;
            --hn-text-muted: #8c98a4;
            --hn-border: #edf2f7;
            --font-main: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font-main); background-color: var(--hn-bg); color: var(--hn-text-dark); line-height: 1.5; }
        
        .header { background-color: var(--hn-orange); height: 85px; padding: 0 40px; display: flex; align-items: center; justify-content: space-between; }
        .header-logo { font-size: 37px; font-weight: 800; color: #ffffff; text-decoration: none; }
        .user-nav { display: flex; align-items: center; gap: 12px; color: #ffffff; }
        .user-info { font-size: 14px; font-weight: 500; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: #ffffff; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; transition: background 0.2s ease; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.35); }

        .main-container { max-width: 1400px; margin: 25px auto; padding: 0 20px; }
        .breadcrumb { font-size: 13px; color: var(--hn-text-muted); margin-bottom: 20px; }
        .breadcrumb a { color: var(--hn-orange); text-decoration: none; font-weight: 500; }
        .breadcrumb span { color: var(--hn-text-muted); }

        .form-card { 
            background: var(--hn-card-bg); 
            border-radius: 12px; 
            border: 1px solid var(--hn-border); 
            padding: 32px; 
            max-width: 600px; 
            margin: 0 auto; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.03); 
        }

        .card-heading { 
            font-size: 20px; 
            font-weight: 700; 
            border-bottom: 2px solid var(--hn-border); 
            padding-bottom: 12px; 
            margin-bottom: 24px; 
            color: var(--hn-text-dark); 
        }

        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
        .form-label { font-size: 13px; font-weight: 700; color: var(--hn-text-dark); }
        .form-input { 
            width: 100%; 
            padding: 10px 14px; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            font-size: 14px; 
            outline: none; 
            transition: border-color 0.2s ease;
        }
        .form-input:focus { border-color: var(--hn-orange); }

        .checkbox-group { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .checkbox-group input { width: 16px; height: 16px; accent-color: var(--hn-orange); cursor: pointer; }
        .checkbox-group label { font-size: 13px; font-weight: 600; color: var(--hn-text-dark); cursor: pointer; }

        .btn-submit { 
            background: var(--hn-orange); 
            color: #ffffff; 
            border: none; 
            padding: 12px 20px; 
            border-radius: 8px; 
            font-size: 14px; 
            font-weight: 700; 
            cursor: pointer; 
            transition: background 0.2s ease; 
            width: 100%;
            margin-top: 8px;
        }
        .btn-submit:hover { background: var(--hn-orange-hover); }

        .alert-box { 
            padding: 12px 16px; 
            border-radius: 8px; 
            font-size: 13px; 
            font-weight: 600; 
            margin-bottom: 20px; 
        }
        .alert-success { background-color: #def7ec; color: #03543f; border: 1px solid #bcf0da; }
        .alert-error { background-color: #fde8e8; color: #9b1c1c; border: 1px solid #fbd5d5; }

        .action-row { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; }
        .back-link { font-size: 13px; color: var(--hn-text-muted); text-decoration: none; font-weight: 500; }
        .back-link:hover { color: var(--hn-orange); text-decoration: underline; }
    </style>
</head>
<body>

    <header class="header">
        <a href="index.php" class="header-logo">Highnoon</a>
        <div class="user-nav">
            <span class="user-info"><?= htmlspecialchars($_SESSION['username'] ?? $_SESSION['useremail']) ?></span>
            <a href="index.php" class="btn-logout">Portal</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </header>

    <div class="main-container">
        <div class="breadcrumb">
            <a href="index.php">Highnoon</a> / <span>Add User</span>
        </div>

        <div class="form-card">
            <h3 class="card-heading">Add New Authorized User</h3>

            <?php if (!empty($message)): ?>
                <div class="alert-box <?= $status === 'success' ? 'alert-success' : 'alert-error' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form action="add_user.php" method="POST">
                <div class="form-group">
                    <label class="form-label" for="username">Full Name / Username</label>
                    <input type="text" id="username" name="username" class="form-input" placeholder="e.g. Hashir" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label class="form-label" for="useremail">Microsoft Email Address</label>
                    <input type="email" id="useremail" name="useremail" class="form-input" placeholder="e.g. hashir.hassan@highnoon.com.pk" required autocomplete="off">
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="superuser" name="superuser" value="1">
                    <label for="superuser">Grant Superuser (Admin) Privileges</label>
                </div>

                <button type="submit" class="btn-submit">Add Authorized User</button>
            </form>

            <div class="action-row">
                <a href="index.php" class="back-link">← Back to Document Portal</a>
            </div>
        </div>
    </div>

</body>
</html>