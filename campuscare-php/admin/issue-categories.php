
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Access denied. Only administrators can manage issue categories.');
}

require_once __DIR__ . '/../config/db.php';

$message = '';
$messageType = 'success';
$editCategory = null;

// Add or update a category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
    $categoryName = trim($_POST['category_name'] ?? '');

    if ($categoryName === '') {
        $message = 'Please enter a category name.';
        $messageType = 'error';
    } elseif (mb_strlen($categoryName) > 60) {
        $message = 'Category name must not exceed 60 characters.';
        $messageType = 'error';
    } else {
        try {
            if ($categoryId) {
                $stmt = $pdo->prepare(
                    'UPDATE issue_categories
                     SET category_name = :category_name
                     WHERE category_id = :category_id'
                );

                $stmt->execute([
                    'category_name' => $categoryName,
                    'category_id' => $categoryId
                ]);

                $message = 'Issue category updated successfully.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO issue_categories (category_name)
                     VALUES (:category_name)'
                );

                $stmt->execute([
                    'category_name' => $categoryName
                ]);

                $message = 'Issue category added successfully.';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'That category already exists or cannot be changed because it is being used.';
            } else {
                error_log($e->getMessage());
                $message = 'Unable to save the category. Please check the database configuration.';
            }

            $messageType = 'error';
        }
    }
}

// Delete a category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

    if (!$categoryId) {
        $message = 'Invalid category selected.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM issue_categories
                 WHERE category_id = :category_id'
            );

            $stmt->execute(['category_id' => $categoryId]);

            if ($stmt->rowCount() > 0) {
                $message = 'Issue category deleted successfully.';
            } else {
                $message = 'Category not found.';
                $messageType = 'error';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $message = 'This category is linked to existing reports and cannot be deleted.';
            } else {
                error_log($e->getMessage());
                $message = 'Unable to delete the category.';
            }

            $messageType = 'error';
        }
    }
}

// Load category for editing
if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if ($editId) {
        $stmt = $pdo->prepare(
            'SELECT category_id, category_name
             FROM issue_categories
             WHERE category_id = :category_id'
        );

        $stmt->execute(['category_id' => $editId]);
        $editCategory = $stmt->fetch();

        if (!$editCategory) {
            $message = 'Category not found.';
            $messageType = 'error';
        }
    }
}

// Load categories and number of reports using each category
$stmt = $pdo->query(
    'SELECT
        c.category_id,
        c.category_name,
        COUNT(r.report_id) AS report_count
     FROM issue_categories c
     LEFT JOIN reports r ON r.category_id = c.category_id
     GROUP BY c.category_id, c.category_name
     ORDER BY c.category_name ASC'
);

$categories = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Categories | CampusCare</title>
    <link rel="stylesheet" href="../assets/css/issue-categories.css">
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
            <a href="issue-categories.php" class="active">Issue Categories</a>
        </nav>

        <div class="sidebar-bottom">
            <p>Signed in as</p>
            <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Administrator') ?></strong>
            <span>ADMINISTRATOR</span>
            <a href="../logout.php" class="logout-link">Log out</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <div>
                <p class="eyebrow">SYSTEM CONFIGURATION</p>
                <h1>Issue Categories</h1>
                <p class="subtitle">Manage the categories used when reporting campus facility issues.</p>
            </div>

            <div class="admin-badge">ADMIN</div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="content-grid">

            <div class="panel form-panel">
                <div class="panel-heading">
                    <div>
                        <h2><?= $editCategory ? 'Edit Category' : 'Add Category' ?></h2>
                        <p><?= $editCategory ? 'Update the selected category.' : 'Create a new issue type.' ?></p>
                    </div>
                    <span class="heading-icon">+</span>
                </div>

                <form method="POST" action="issue-categories.php<?= $editCategory ? '?edit=' . (int) $editCategory['category_id'] : '' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="category_id"
                               value="<?= (int) $editCategory['category_id'] ?>">
                    <?php endif; ?>

                    <label for="category_name">Category Name</label>
                    <input
                        type="text"
                        id="category_name"
                        name="category_name"
                        maxlength="60"
                        placeholder="e.g. Electrical"
                        value="<?= htmlspecialchars($editCategory['category_name'] ?? '') ?>"
                        required
                    >

                    <button type="submit" name="save_category" class="primary-button">
                        <?= $editCategory ? 'Save Changes' : 'Add Category' ?>
                    </button>

                    <?php if ($editCategory): ?>
                        <a href="issue-categories.php" class="cancel-link">Cancel editing</a>
                    <?php endif; ?>
                </form>

                <div class="form-note">
                    <strong>Tip</strong>
                    <p>Use clear category names so reporters can identify the type of facility issue.</p>
                </div>
            </div>

            <div class="panel table-panel">
                <div class="panel-heading">
                    <div>
                        <h2>Existing Categories</h2>
                        <p>Categories currently stored in the database.</p>
                    </div>
                    <span class="count-badge"><?= count($categories) ?> total</span>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category Name</th>
                                <th>Reports</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        No issue categories found. Add your first category.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td class="id-cell">
                                            #<?= (int) $category['category_id'] ?>
                                        </td>

                                        <td>
                                            <span class="category-name">
                                                <?= htmlspecialchars($category['category_name']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="report-count">
                                                <?= (int) $category['report_count'] ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="action-group">
                                                <a
                                                    href="issue-categories.php?edit=<?= (int) $category['category_id'] ?>"
                                                    class="edit-button"
                                                >Edit</a>

                                                <form method="POST" action="issue-categories.php">
                                                    <input
                                                        type="hidden"
                                                        name="category_id"
                                                        value="<?= (int) $category['category_id'] ?>"
                                                    >
                                                    <button
                                                        type="submit"
                                                        name="delete_category"
                                                        class="delete-button"
                                                    >Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
