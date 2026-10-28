
<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}


$fullName = $_SESSION['full_name'] ?? 'User';
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];

require_once __DIR__ . '/config/db.php';

$reportFilter = '';
$params = [];

if ($role === 'REPORTER') {
    $reportFilter = ' WHERE reporter_id = :user_id';
    $params['user_id'] = $userId;
}

$sql = "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status IN ('SUBMITTED', 'ASSIGNED')), 0) AS pending,
        COALESCE(SUM(status = 'IN_PROGRESS'), 0) AS in_progress,
        COALESCE(SUM(status IN ('RESOLVED', 'CLOSED')), 0) AS resolved
    FROM reports
    $reportFilter
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalReports = (int) ($stats['total'] ?? 0);
$pendingReports = (int) ($stats['pending'] ?? 0);
$inProgressReports = (int) ($stats['in_progress'] ?? 0);
$resolvedReports = (int) ($stats['resolved'] ?? 0);


$recentReports = [];

$reportsSql = "
    SELECT
        r.report_id,
        r.title,
        r.priority,
        r.status,
        r.created_at,
        c.category_name,
        rm.room_name,
        b.building_name
    FROM reports r
    LEFT JOIN issue_categories c
        ON r.category_id = c.category_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    LEFT JOIN buildings b
        ON rm.building_id = b.building_id
";

$reportsParams = [];

if ($role === 'REPORTER') {
    $reportsSql .= " WHERE r.reporter_id = :user_id";
    $reportsParams['user_id'] = $userId;
}

$reportsSql .= " ORDER BY r.created_at DESC";

$reportsStmt = $pdo->prepare($reportsSql);
$reportsStmt->execute($reportsParams);
$recentReports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);


$allowedRoles = ['ADMIN', 'MAINTENANCE', 'REPORTER'];

if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access denied.');
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <title>Dashboard | CampusCare</title>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h1 class="brand">CampusCare</h1>
        <p class="subtitle">
            FACILITY ISSUE REPORTING<br>
            AND MAINTENANCE MANAGEMENT
        </p>

        <p class="nav-label">WORKSPACE</p>
        <a class="nav-link active" href="dashboard.php">Dashboard</a>
        <a class="nav-link" href="reports/index.php">Facility Reports</a>

        <?php if ($role === 'ADMIN'): ?>
            <p class="nav-label">ADMINISTRATION</p>
            <a class="nav-link" href="admin/buildings.php">Buildings</a>
            <a class="nav-link" href="admin/rooms.php">Rooms</a>
            <a class="nav-link" href="admin/issue-categories.php">Issue Categories</a>
            <a class="nav-link" href="admin/users.php">User Management</a>
            <a class="logout" href="logout.php">Log Out</a>
        <?php endif; ?>

        <?php if ($role === 'REPORTER'): ?>
            <p class="nav-label">REPORT AN ISSUE</p>
            <a class="nav-link" href="reporter/create-report.php">Submit a Report</a>
        <?php endif; ?>
    </aside>

    <main class="main">
        <header class="topbar">
            <h2>Dashboard</h2>
            <div>
                <div class="user-info">
                    <?= e($fullName) ?> (<?= e($username) ?>)
                </div>
                <a class="logout" href="logout.php">Log Out</a>
            </div>
        </header>

        <section class="content">
            <div class="welcome">
                <h1>Welcome, <?= e($fullName) ?>!</h1>
                <p>
                    Here's your CampusCare workspace.
                    Manage facility issues and track maintenance progress.
                </p>
                <span class="role"><?= e($role) ?></span>
            </div>

            <div class="cards">
                <article class="card">
                    <h3>Total Reports</h3>
                    <p><?= $totalReports ?></p>
                </article>
                <article class="card">
                    <h3>Pending</h3>
                    <p><?= $pendingReports ?></p>
                </article>
                <article class="card">
                    <h3>In Progress</h3>
                    <p><?= $inProgressReports ?></p>
                </article>
                <article class="card">
                    <h3>Resolved</h3>
                    <p><?= $resolvedReports ?></p>
                </article>
            </div>

            <section class="reports-section">
                <div class="reports-heading">
                    <div>
                        <h2>Recent Facility Reports</h2>
                        <p>Monitor reported issues and maintenance progress.</p>
                    </div>
                    <a href="reports/index.php" class="view-all">
                        View All Reports
                    </a>
                </div>

                <div class="reports-table-wrapper">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Issue</th>
                                <th>Category</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentReports)): ?>
                                <tr>
                                    <td colspan="7" class="empty-reports">
                                        No reports found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentReports as $report): ?>
                                    <tr>
                                        <td><?= (int) $report['report_id'] ?></td>
                                        <td>
                                            <strong><?= e($report['title']) ?></strong>
                                        </td>
                                        <td>
                                            <?= e($report['category_name'] ?? 'Uncategorized') ?>
                                        </td>
                                        <td>
                                            <?= e($report['building_name'] ?? 'Unknown building') ?>
                                            <?php if (!empty($report['room_name'])): ?>
                                                <div class="table-subtext">
                                                    <?= e($report['room_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($report['priority']) ?></td>
                                        <td>
                                            <span class="table-status">
                                                <?= e(str_replace('_', ' ', $report['status'])) ?>
                                            </span>
                                        </td>
                                        <td><?= e($report['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="notice">
                <strong>Stay updated with campus facilities.</strong><br>
                View report statistics and track maintenance progress
                from your dashboard.
            </div>
        </section>
    </main>
</div>
</body>
</html>
