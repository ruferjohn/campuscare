
<?php
session_start();

require_once __DIR__ . '/config/db.php';

$error = '';
$username = '';

function verifyCampusCarePassword(string $password, string $storedHash): bool
{
    $parts = explode(':', $storedHash);

    if (count($parts) !== 3) {
        return false;
    }

    [$iterationsText, $saltText, $hashText] = $parts;

    if (!ctype_digit($iterationsText)) {
        return false;
    }

    $iterations = (int) $iterationsText;

    // Accept only reasonable PBKDF2 iteration counts.
    if ($iterations < 10000 || $iterations > 2000000) {
        return false;
    }

    // Support either hexadecimal or Base64-encoded salt and hash.
    $salt = preg_match('/^[a-f0-9]+$/i', $saltText)
        && strlen($saltText) === 32
        ? hex2bin($saltText)
        : base64_decode($saltText, true);

    $expectedHash = preg_match('/^[a-f0-9]+$/i', $hashText)
        && strlen($hashText) === 64
        ? hex2bin($hashText)
        : base64_decode($hashText, true);

    if ($salt === false || $expectedHash === false
        || $salt === '' || $expectedHash === '') {
        return false;
    }

    $actualHash = hash_pbkdf2(
        'sha256',
        $password,
        $salt,
        $iterations,
        strlen($expectedHash),
        true
    );

    return hash_equals($expectedHash, $actualHash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT user_id, full_name, username, email,
                        password_hash, role
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );

            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && verifyCampusCarePassword(
                $password,
                $user['password_hash']
            )) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid username or password.';
        } catch (PDOException $e) {
            error_log('CampusCare login query failed: ' . $e->getMessage());
            $error = 'Login is temporarily unavailable. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | CampusCare</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #102f35;
            color: #f1f8f7;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 36px;
            background: #173d43;
            border: 1px solid #28575c;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, .25);
        }

        .brand {
            margin-bottom: 28px;
            text-align: center;
        }

        .brand h1 {
            margin: 0 0 8px;
            color: #55d6bd;
            font-size: 30px;
        }

        .brand p {
            margin: 0;
            color: #b5cfcc;
            font-size: 14px;
            line-height: 1.5;
        }

        h2 {
            margin: 0 0 22px;
            font-size: 22px;
        }

        label {
            display: block;
            margin: 17px 0 8px;
            font-size: 14px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #416b70;
            border-radius: 9px;
            background: #102f35;
            color: #f1f8f7;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #1d9b8a;
            border-color: #1d9b8a;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 9px;
            background: #1d9b8a;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #27b39e; }

        .error {
            padding: 12px;
            border-radius: 8px;
            background: #512f35;
            color: #ffd9de;
            font-size: 14px;
            line-height: 1.5;
        }

        .footer {
            margin-top: 22px;
            text-align: center;
            color: #b5cfcc;
            font-size: 14px;
        }

        a { color: #55d6bd; }

        @media (max-width: 480px) {
            .login-card { padding: 26px 22px; }
        }
    </style>
</head>
<body>
    <main class="login-card">
        <header class="brand">
            <h1>CampusCare</h1>
            <p>Facility Issue Reporting and<br>Maintenance Management</p>
        </header>

        <h2>Welcome back</h2>

        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="login.php">
            <label for="username">Username</label>
            <input
                id="username"
                name="username"
                type="text"
                maxlength="50"
                autocomplete="username"
                value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                required
            >

            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Log In</button>
        </form>

        <p class="footer">
            <a href="index.php">Back to Home</a>
        </p>
    </main>
</body>
</html>
