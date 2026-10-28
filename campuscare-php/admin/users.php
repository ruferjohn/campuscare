<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Access denied. Only administrators can manage users.');
}

require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'success';
$editUser = null;

$allowedRoles = ['ADMIN', 'MAINTENANCE', 'REPORTER'];

// Process submitted forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    // Add or update a user
    if (isset($_POST['save_user'])) {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';

        if (
            $fullName === '' ||
            $username === '' ||
            $email === '' ||
            mb_strlen($fullName) > 100 ||
            mb_strlen($username) > 50 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            mb_strlen($email) > 150 ||
            !in_array($role, $allowedRoles, true)
        ) {
            $message = 'Please check the required fields and enter valid information.';
            $messageType = 'error';
        } elseif (
            (!$userId && strlen($password) < 8) ||
            ($password !== '' && strlen($password) < 8)
        ) {
            $message = 'Passwords must contain at least 8 characters.';
            $messageType = 'error';
        } else {
            try {
                if ($userId) {
                    // Verify that the user exists
                    $check = $pdo->prepare(
                        'SELECT user_id FROM users WHERE user_id = ?'
                    );
                    $check->execute([$userId]);

                    if (!$check->fetch()) {
                        throw new RuntimeException('User not found.');
                    }

                    // Prevent removing your own administrator access
                    if (
                        $userId === (int) $_SESSION['user_id'] &&
                        $role !== 'ADMIN'
                    ) {
                        throw new RuntimeException(
                            'You cannot change your own role from ADMIN.'
                        );
                    }

                    if ($password !== '') {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET full_name = ?, username = ?, email = ?,
                                 role = ?, password_hash = ?
                             WHERE user_id = ?'
                        );

                        $stmt->execute([
                            $fullName,
                            $username,
                            $email,
                            $role,
                            password_hash($password, PASSWORD_DEFAULT),
                            $userId
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE users
                             SET full_name = ?, username = ?, email = ?, role = ?
                             WHERE user_id = ?'
                        );

                        $stmt->execute([
                            $fullName,
                            $username,
                            $email,
                            $role,
                            $userId
                        ]);
                    }

                    $message = 'User updated successfully.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO users
                            (full_name, username, email, password_hash, role)
                         VALUES (?, ?, ?, ?, ?)'
                    );

                    $stmt->execute([
                        $fullName,
                        $username,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $role
                    ]);

                    $message = 'User account created successfully.';
                }
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = 'That username or email is already registered.';
                } else {
                    error_log($e->getMessage());
                    $message = 'Unable to save the user. Check the database configuration.';
                }

                $messageType = 'error';
            }
        }
    }

    // Delete a user
    if (isset($_POST['delete_user'])) {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$userId) {
            $message = 'Invalid user selected.';
            $messageType = 'error';
        } elseif ($userId === (int) $_SESSION['user_id']) {
            $message = 'You cannot delete your own account while signed in.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'DELETE FROM users WHERE user_id = ?'
                );
                $stmt->execute([$userId]);

                $message = $stmt->rowCount() > 0
                    ? 'User deleted successfully.'
                    : 'User not found.';

                if ($stmt->rowCount() === 0) {
                    $messageType = 'error';
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = 'This user is linked to existing reports and cannot be deleted.';
                } else {
                    error_log($e->getMessage());
                    $message = 'Unable to delete the user.';
                }

                $messageType = 'error';
            }
        }
    }
}

// Load user for editing
if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if ($editId) {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, username, email, role
             FROM users
             WHERE user_id = ?'
        );
        $stmt->execute([$editId]);
        $editUser = $stmt->fetch();

        if (!$editUser) {
            $message = 'User not found.';
            $messageType = 'error';
        }
    }
}

// Load all users
$stmt = $pdo->query(
    'SELECT user_id, full_name, username, email, role, created_at
     FROM users
     ORDER BY user_id DESC'
);
$users = $stmt->fetchAll();

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | CampusCare</title>
    <link rel="stylesheet" href="../assets/css/users.css">
</head>
<body>

<div class="page-container">

    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">C</div>
            <div>
                <h2>CampusCare</h2>
                <p>FACILITY MANAGEMENT</p>
            </div>
        </div>

        <p class="menu-label">MAIN MENU</p>

        <nav class="navigation">
            <a href="../dashboard.php">Dashboard</a>
            <a href="../reports/index.php">Facility Reports</a>
            <a href="buildings.php">Buildings</a>
            <a href="rooms.php">Rooms</a>
            <a href="issue-categories.php">Issue Categories</a>
            <a href="users.php" class="active">User Management</a>
        </nav>

        <div class="sidebar-bottom">
            <p>Signed in as</p>
            <strong><?= e($_SESSION['full_name'] ?? 'Administrator') ?></strong>
            <span>ADMINISTRATOR</span>
            <a href="../logout.php" class="logout-link">Log out</a>
        </div>
    </aside>

    <main class="main-content">

        <header class="page-header">
            <div>
                <p class="eyebrow">ADMINISTRATION</p>
                <h1>User Management</h1>
                <p class="subtitle">
                    Manage accounts, contact information, and system roles.
                </p>
            </div>
            <span class="admin-badge">ADMIN</span>
        </header>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <section class="panel form-panel">
            <div class="panel-heading">
                <div>
                    <h2><?= $editUser ? 'Edit User' : 'Create User Account' ?></h2>
                    <p>
                        <?= $editUser
                            ? 'Update the selected account details.'
                            : 'Add an account to the CampusCare system.' ?>
                    </p>
                </div>
                <span class="heading-icon">+</span>
            </div>

            <form method="POST" action="users.php<?= $editUser ? '?edit=' . (int) $editUser['user_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                <?php if ($editUser): ?>
                    <input type="hidden" name="user_id" value="<?= (int) $editUser['user_id'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label for="full_name">Full Name</label>
                        <input
                            id="full_name"
                            name="full_name"
                            type="text"
                            maxlength="100"
                            required
                            value="<?= e($editUser['full_name'] ?? '') ?>"
                            placeholder="Enter full name"
                        >
                    </div>

                    <div class="field">
                        <label for="username">Username</label>
                        <input
                            id="username"
                            name="username"
                            type="text"
                            maxlength="50"
                            required
                            value="<?= e($editUser['username'] ?? '') ?>"
                            placeholder="Enter username"
                        >
                    </div>

                    <div class="field">
                        <label for="email">Email Address</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            maxlength="150"
                            required
                            value="<?= e($editUser['email'] ?? '') ?>"
                            placeholder="name@example.com"
                        >
                    </div>

                    <div class="field">
                        <label for="role">System Role</label>
                        <select id="role" name="role" required>
                            <?php foreach ($allowedRoles as $role): ?>
                                <option
                                    value="<?= e($role) ?>"
                                    <?= ($editUser['role'] ?? 'REPORTER') === $role ? 'selected' : '' ?>
                                >
                                    <?= e($role) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field full-width">
                        <label for="password">
                            <?= $editUser ? 'New Password (Optional)' : 'Initial Password' ?>
                        </label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            minlength="8"
                            <?= $editUser ? '' : 'required' ?>
                            autocomplete="new-password"
                            placeholder="<?= $editUser ? 'Leave blank to keep current password' : 'At least 8 characters' ?>"
                        >
                        <small>
                            Passwords are securely hashed before being saved.
                        </small>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit" name="save_user">
                        <?= $editUser ? 'Save Changes' : 'Create Account' ?>
                    </button>

                    <?php if ($editUser): ?>
                        <a href="users.php" class="cancel-link">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel table-panel">
            <div class="panel-heading">
                <div>
                    <h2>Registered Users</h2>
                    <p>All accounts currently registered in CampusCare.</p>
                </div>
                <span class="count-badge"><?= count($users) ?> users</span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="6" class="empty-state">No users found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td class="id-cell">#<?= (int) $user['user_id'] ?></td>
                                    <td>
                                        <strong class="user-name"><?= e($user['full_name']) ?></strong>
                                        <span class="username">@<?= e($user['username']) ?></span>
                                    </td>
                                    <td><?= e($user['email']) ?></td>
                                    <td>
                                        <span class="role-badge role-<?= strtolower(e($user['role'])) ?>">
                                            <?= e($user['role']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= e($user['created_at'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <a class="edit-button"
                                               href="users.php?edit=<?= (int) $user['user_id'] ?>">
                                                Edit
                                            </a>

                                            <?php if ((int) $user['user_id'] !== (int) $_SESSION['user_id']): ?>
                                                <form method="POST" action="users.php">
                                                    <input type="hidden" name="csrf_token"
                                                           value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="user_id"
                                                           value="<?= (int) $user['user_id'] ?>">
                                                    <button class="delete-button"
                                                            type="submit"
                                                            name="delete_user">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="self-label">Your account</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="page-footer">
            <span>CampusCare Facility Issue Reporting System</span>
            <span>Deep Teal Interface</span>
        </footer>

    </main>
</div>

</body>
</html>

