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

// Edit Mode state
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT id, username, useremail, superuser FROM users WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Handle POST: Add or Update User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? 'add';
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
        if ($action === 'edit') {
            $user_id = (int)$_POST['user_id'];

            // Check duplicate email for OTHER users
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(useremail) = ? AND id != ?");
            $check_stmt->bind_param("si", $useremail, $user_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $message = "Another user with this email address already exists.";
                $status = "error";
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, useremail = ?, superuser = ? WHERE id = ?");
                $stmt->bind_param("ssii", $username, $useremail, $is_super, $user_id);
                if ($stmt->execute()) {
                    $message = "User '{$username}' updated successfully!";
                    $status = "success";
                    $edit_user = null; // Exit edit mode
                } else {
                    $message = "Database error: Unable to update user.";
                    $status = "error";
                }
                $stmt->close();
            }
            $check_stmt->close();
        } else {
            // Add New User
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(useremail) = ?");
            $check_stmt->bind_param("s", $useremail);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
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
}

// Fetch all existing users
$users_result = $conn->query("SELECT id, username, useremail, superuser FROM users ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Highnoon | User Management</title>

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

        .grid-layout { display: grid; grid-template-columns: 380px 1fr; gap: 24px; }
        @media (max-width: 992px) { .grid-layout { grid-template-columns: 1fr; } }

        .form-card, .table-card { 
            background: var(--hn-card-bg); 
            border-radius: 12px; 
            border: 1px solid var(--hn-border); 
            padding: 24px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.03); 
        }

        .card-heading { 
            font-size: 18px; 
            font-weight: 700; 
            border-bottom: 2px solid var(--hn-border); 
            padding-bottom: 12px; 
            margin-bottom: 20px; 
            color: var(--hn-text-dark); 
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        .form-label { font-size: 13px; font-weight: 700; color: var(--hn-text-dark); }
        .form-input { 
            width: 100%; 
            padding: 9px 12px; 
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
            padding: 10px 16px; 
            border-radius: 8px; 
            font-size: 14px; 
            font-weight: 700; 
            cursor: pointer; 
            transition: background 0.2s ease; 
            width: 100%;
        }
        .btn-submit:hover { background: var(--hn-orange-hover); }
        .btn-cancel { background: #e2e8f0; color: var(--hn-text-dark); text-align: center; text-decoration: none; padding: 10px; border-radius: 8px; font-size: 13px; font-weight: 600; display: block; margin-top: 8px; }

        .alert-box { 
            padding: 10px 14px; 
            border-radius: 8px; 
            font-size: 13px; 
            font-weight: 600; 
            margin-bottom: 16px; 
        }
        .alert-success { background-color: #def7ec; color: #03543f; border: 1px solid #bcf0da; }
        .alert-error { background-color: #fde8e8; color: #9b1c1c; border: 1px solid #fbd5d5; }

        /* User Table Styles */
        .user-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        .user-table th { background: #f8fafc; padding: 10px 14px; border-bottom: 2px solid var(--hn-border); color: var(--hn-text-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .user-table td { padding: 12px 14px; border-bottom: 1px solid var(--hn-border); }
        .user-table tr:hover { background-color: #f8fafc; }

        .badge-super { background: #fff3ed; color: var(--hn-orange); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 12px; border: 1px solid #ffd8c7; }
        .badge-user { background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 12px; }

        .btn-edit { color: var(--hn-orange); text-decoration: none; font-weight: 600; font-size: 13px; }
        .btn-edit:hover { text-decoration: underline; }
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
            <a href="index.php">Highnoon</a> / <span>User Management</span>
        </div>

        <div class="grid-layout">
            <!-- Left Column: Add / Edit Form -->
            <div class="form-card">
                <h3 class="card-heading">
                    <?= $edit_user ? 'Edit User' : 'Add New User' ?>
                </h3>

                <?php if (!empty($message)): ?>
                    <div class="alert-box <?= $status === 'success' ? 'alert-success' : 'alert-error' ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form action="add_user.php" method="POST">
                    <input type="hidden" name="action" value="<?= $edit_user ? 'edit' : 'add' ?>">
                    <?php if ($edit_user): ?>
                        <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label" for="username">Full Name / Username</label>
                        <input type="text" id="username" name="username" class="form-input" 
                               value="<?= htmlspecialchars($edit_user['username'] ?? '') ?>" 
                               placeholder="e.g. Hashir" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="useremail">Microsoft Email Address</label>
                        <input type="email" id="useremail" name="useremail" class="form-input" 
                               value="<?= htmlspecialchars($edit_user['useremail'] ?? '') ?>" 
                               placeholder="e.g. hashir.hassan@highnoon.com.pk" required autocomplete="off">
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="superuser" name="superuser" value="1" 
                               <?= isset($edit_user) && $edit_user['superuser'] == 1 ? 'checked' : '' ?>>
                        <label for="superuser">Grant Superuser (Admin) Privileges</label>
                    </div>

                    <button type="submit" class="btn-submit">
                        <?= $edit_user ? 'Update User Details' : 'Add Authorized User' ?>
                    </button>

                    <?php if ($edit_user): ?>
                        <a href="add_user.php" class="btn-cancel">Cancel Editing</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Right Column: Registered Users Table -->
            <div class="table-card">
                <h3 class="card-heading">
                    Registered Users
                    <span style="font-size: 13px; font-weight: 500; color: var(--hn-text-muted);">Total: <?= $users_result->num_rows ?></span>
                </h3>

                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $users_result->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                                <td style="color: #64748b;"><?= htmlspecialchars($row['useremail']) ?></td>
                                <td>
                                    <?php if ($row['superuser'] == 1): ?>
                                        <span class="badge-super">Superuser</span>
                                    <?php else: ?>
                                        <span class="badge-user">User</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="add_user.php?edit=<?= $row['id'] ?>" class="btn-edit">Edit</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>