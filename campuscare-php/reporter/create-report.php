
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = strtoupper($_SESSION['role'] ?? '');

if (!in_array($role, ['ADMIN', 'MAINTENANCE', 'REPORTER'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../config/db.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Count characters safely even when the mbstring extension is unavailable. */
function text_length(string $value): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

$errors = [];
$title = '';
$description = '';
$priority = 'MEDIUM';
$categoryId = '';
$buildingId = '';
$roomId = '';

try {
    $categories = $pdo->query(
        'SELECT category_id, category_name
         FROM issue_categories
         ORDER BY category_name'
    )->fetchAll();

    $buildings = $pdo->query(
        'SELECT building_id, building_name
         FROM buildings
         ORDER BY building_name'
    )->fetchAll();

    $rooms = $pdo->query(
        'SELECT room_id, room_name, building_id
         FROM rooms
         ORDER BY room_name'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Create report dropdown error: ' . $e->getMessage());
    $categories = [];
    $buildings = [];
    $rooms = [];
    $errors[] = 'Unable to load categories, buildings, or rooms.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = strtoupper(trim($_POST['priority'] ?? ''));
    $categoryId = trim($_POST['category_id'] ?? '');
    $buildingId = trim($_POST['building_id'] ?? '');
    $roomId = trim($_POST['room_id'] ?? '');

    if ($title === '' || text_length($title) > 150) {
        $errors[] = 'Enter a title between 1 and 150 characters.';
    }

    if ($description === '' || text_length($description) > 5000) {
        $errors[] = 'Enter a description of up to 5000 characters.';
    }

    if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH'], true)) {
        $errors[] = 'Choose a valid priority.';
    }

    if (!ctype_digit($categoryId) || (int) $categoryId < 1) {
        $errors[] = 'Choose an issue category.';
    }

    if (!ctype_digit($buildingId) || (int) $buildingId < 1) {
        $errors[] = 'Choose a building.';
    }

    if (!ctype_digit($roomId) || (int) $roomId < 1) {
        $errors[] = 'Choose a room.';
    }

    if (!$errors) {
        try {
            $checkRoom = $pdo->prepare(
                'SELECT room_id
                 FROM rooms
                 WHERE room_id = :room_id
                   AND building_id = :building_id'
            );

            $checkRoom->execute([
                'room_id' => (int) $roomId,
                'building_id' => (int) $buildingId
            ]);

            if (!$checkRoom->fetch()) {
                $errors[] = 'The selected room does not belong to that building.';
            }

            $checkCategory = $pdo->prepare(
                'SELECT category_id
                 FROM issue_categories
                 WHERE category_id = :category_id'
            );

            $checkCategory->execute([
                'category_id' => (int) $categoryId
            ]);

            if (!$checkCategory->fetch()) {
                $errors[] = 'The selected issue category is invalid.';
            }

            if (!$errors) {
                $insert = $pdo->prepare(
                    "INSERT INTO reports
                        (reporter_id, category_id, room_id,
                         title, description, priority, status, created_at)
                     VALUES
                        (:reporter_id, :category_id, :room_id,
                         :title, :description, :priority, 'SUBMITTED', NOW())"
                );

                $insert->execute([
                    'reporter_id' => (int) $_SESSION['user_id'],
                    'category_id' => (int) $categoryId,
                    'room_id' => (int) $roomId,
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority
                ]);

                header('Location: ../reports/index.php?created=1');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Create report insert error: ' . $e->getMessage());
            $errors[] = 'The report could not be saved. Please check the server error log.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Report | CampusCare</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/create-report.css">
</head>
<body class="create-report-page">

<header class="create-report-header">
    <div>
        <h1>CampusCare</h1>
        <p>Facility Issue Reporting</p>
    </div>

    <nav>
        <a href="../dashboard.php">Dashboard</a>
        <a href="../reports/index.php">Facility Reports</a>
        <a href="../logout.php">Log Out</a>
    </nav>
</header>

<main class="create-report-container">
    <section class="create-report-intro">
        <p class="eyebrow">REPORT A FACILITY ISSUE</p>
        <h2>Create a Report</h2>
        <p>Provide accurate details so the campus maintenance team can address the issue.</p>
    </section>

    <?php if ($errors): ?>
        <div class="form-alert" role="alert">
            <strong>Please check the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$categories || !$buildings || !$rooms): ?>
        <p class="form-alert">
            Some dropdown options are unavailable. Check that the database contains issue categories, buildings, and rooms.
        </p>
    <?php else: ?>

    <form method="POST" action="" class="report-form">
        <div class="form-field">
            <label for="title">Issue Title</label>
            <input
                type="text"
                id="title"
                name="title"
                maxlength="150"
                value="<?= e($title) ?>"
                placeholder="e.g. Broken classroom light"
                required
            >
        </div>

        <div class="form-field">
            <label for="description">Description</label>
            <textarea
                id="description"
                name="description"
                rows="5"
                maxlength="5000"
                placeholder="Describe the issue and where it is located."
                required
            ><?= e($description) ?></textarea>
        </div>

        <div class="form-grid">
            <div class="form-field">
                <label for="category_id">Issue Category</label>
                <select id="category_id" name="category_id" required>
                    <option value="">Select category</option>
                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= e($category['category_id']) ?>"
                            <?= (string) $categoryId === (string) $category['category_id'] ? 'selected' : '' ?>
                        >
                            <?= e($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label for="priority">Priority</label>
                <select id="priority" name="priority" required>
                    <option value="LOW" <?= $priority === 'LOW' ? 'selected' : '' ?>>Low</option>
                    <option value="MEDIUM" <?= $priority === 'MEDIUM' ? 'selected' : '' ?>>Medium</option>
                    <option value="HIGH" <?= $priority === 'HIGH' ? 'selected' : '' ?>>High</option>
                </select>
            </div>

            <div class="form-field">
                <label for="building_id">Building</label>
                <select id="building_id" name="building_id" required>
                    <option value="">Select building</option>
                    <?php foreach ($buildings as $building): ?>
                        <option
                            value="<?= e($building['building_id']) ?>"
                            <?= (string) $buildingId === (string) $building['building_id'] ? 'selected' : '' ?>
                        >
                            <?= e($building['building_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label for="room_id">Room</label>
                <select id="room_id" name="room_id" required>
                    <option value="">Select room</option>
                    <?php foreach ($rooms as $room): ?>
                        <option
                            value="<?= e($room['room_id']) ?>"
                            <?= (string) $roomId === (string) $room['room_id'] ? 'selected' : '' ?>
                        >
                            <?= e($room['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Make sure the selected room belongs to the selected building.</small>
            </div>
        </div>

        <div class="form-actions">
            <a class="cancel-button" href="../reports/index.php">Cancel</a>
            <button type="submit" class="submit-report-button">Submit Report</button>
        </div>
    </form>
    <?php endif; ?>
</main>

</body>
</html>
