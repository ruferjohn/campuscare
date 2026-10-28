
<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$fullName = $_SESSION['full_name'] ?? 'User';

if (!in_array($role, ['ADMIN', 'MAINTENANCE', 'REPORTER'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


$search = trim($_GET['search'] ?? '');
$reports = [];
$error = '';
$success = '';

$allowedStatuses = [
    'SUBMITTED',
    'ASSIGNED',
    'IN_PROGRESS',
    'RESOLVED',
    'CLOSED'
];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_status'])) {

    if (!in_array($role, ['ADMIN', 'MAINTENANCE'], true)) {
        http_response_code(403);
        exit('You are not allowed to update report statuses.');
    }

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken)
        || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Invalid request token. Please reload the page.');
    }

    $reportId = filter_var(
        $_POST['report_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $newStatus = $_POST['status'] ?? '';

    if (!$reportId || !in_array($newStatus, $allowedStatuses, true)) {
        $error = 'Invalid report ID or status.';
    } else {
        try {
            $updateStmt = $pdo->prepare(
                "UPDATE reports
                SET status = :status
                WHERE report_id = :report_id"
            );

            $updateStmt->execute([
                'status' => $newStatus,
                'report_id' => $reportId
            ]);

            header('Location: index.php?updated=1'
                . ($search !== '' ? '&search=' . urlencode($search) : ''));
            exit;
        } catch (PDOException $exception) {
            error_log('Status update error: ' . $exception->getMessage());
            $error = 'The report status could not be updated.';
        }
    }
}

if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success = 'Report status updated successfully.';
}


try {
    
$sql = "
    SELECT
        r.report_id,
        r.title,
        r.description,
        r.priority,
        r.status,
        r.created_at,
        u.full_name AS reporter_name,
        c.category_name,
        b.building_name,
        rm.room_name
    FROM reports r
    LEFT JOIN users u
        ON r.reporter_id = u.user_id
    LEFT JOIN issue_categories c
        ON r.category_id = c.category_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    LEFT JOIN buildings b
        ON rm.building_id = b.building_id
    WHERE 1 = 1
";


    $params = [];

    if ($role === 'REPORTER') {
        $sql .= ' AND r.reporter_id = :user_id';
        $params['user_id'] = $userId;
    }

    if ($search !== '') {
        $sql .= " AND (
            r.title LIKE :search_title
            OR r.description LIKE :search_description
            OR c.category_name LIKE :search_category
            OR b.building_name LIKE :search_building
            OR rm.room_name LIKE :search_room
        )";

        $term = '%' . $search . '%';
        $params['search_title'] = $term;
        $params['search_description'] = $term;
        $params['search_category'] = $term;
        $params['search_building'] = $term;
        $params['search_room'] = $term;
    }

    $sql .= ' ORDER BY r.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();


} catch (PDOException $exception) {
    error_log('Facility Reports error: ' . $exception->getMessage());

    $error = 'Database error: ' . $exception->getMessage();
}

    
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facility Reports | CampusCare</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/reports.css">
</head>
<body>
<div class="reports-page">
    <header class="reports-header">
        <div>
            <h1>Facility Reports</h1>
            <p>
                Signed in as <?= e($fullName) ?> · <?= e($role) ?>
            </p>
        </div>
        <nav>
            <a href="../dashboard.php">Dashboard</a>
            <a href="../logout.php">Log Out</a>
        </nav>
    </header>

    <main class="reports-content">
        <section class="reports-intro">
            <h2>Campus Facility Issues</h2>
            <p>
                <?php if ($role === 'REPORTER'): ?>
                    View the facility reports submitted by your account.
                <?php else: ?>
                    View and search facility reports across the campus.
                <?php endif; ?>
            </p>
        </section>

        <form method="get" action="index.php" class="reports-search">
            <label for="search">Search reports</label>
            <div class="search-row">
                <input
                    id="search"
                    type="search"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search issue, category, building, or room"
                >
                <button type="submit">Search</button>
                <a href="index.php" class="reset-link">Clear</a>
            </div>
        </form>

        <?php if ($error !== ''): ?>
            <p class="reports-error"><?= e($error) ?></p>
        <?php else: ?>
            <p class="report-count">
                Reports found: <strong><?= count($reports) ?></strong>
            </p>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Issue</th>
                            <th>Category</th>
                            <th>Location</th>
                            <?php if ($role !== 'REPORTER'): ?>
                                <th>Reporter</th>
                            <?php endif; ?>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created</th>
                            <?php if (in_array($role, ['ADMIN', 'MAINTENANCE'], true)): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$reports): ?>
                        <tr>
                            <td colspan="<?= in_array($role, ['ADMIN', 'MAINTENANCE'], true) ? 9 : 7 ?>"
                                class="empty-state">
                                No reports found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td><?= (int) $report['report_id'] ?></td>
                                <td>
                                    <strong><?= e($report['title']) ?></strong>
                                    <div class="description">
                                        <?= e($report['description']) ?>
                                    </div>
                                </td>
                                <td><?= e($report['category_name'] ?? 'Uncategorized') ?></td>
                                <td>
                                    <?= e($report['building_name'] ?? 'Unknown building') ?>
                                    <?php if (!empty($report['room_name'])): ?>
                                        <div class="description">
                                            <?= e($report['room_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($role !== 'REPORTER'): ?>
                                    <td><?= e($report['reporter_name'] ?? 'Unknown') ?></td>
                                <?php endif; ?>
                                <td><?= e($report['priority']) ?></td>
                                <td>
                                    <span class="status">
                                        <?= e(str_replace('_', ' ', $report['status'])) ?>
                                    </span>
                                </td>
                                <td><?= e($report['created_at']) ?></td>
                                                                    
                                    <?php if (in_array($role, ['ADMIN', 'MAINTENANCE'], true)): ?>
                                        <td>
                                            <form method="post" action="index.php" class="status-form">
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION['csrf_token']) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="report_id"
                                                    value="<?= (int) $report['report_id'] ?>"
                                                >

                                                <?php if ($search !== ''): ?>
                                                    <input
                                                        type="hidden"
                                                        name="search"
                                                        value="<?= e($search) ?>"
                                                    >
                                                <?php endif; ?>

                                                <select name="status" aria-label="New status">
                                                    <?php foreach ($allowedStatuses as $statusOption): ?>
                                                        <option
                                                            value="<?= e($statusOption) ?>"
                                                            <?= $report['status'] === $statusOption ? 'selected' : '' ?>
                                                        >
                                                            <?= e(str_replace('_', ' ', $statusOption)) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <button type="submit" name="update_status" value="1">
                                                    Update
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
