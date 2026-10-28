
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$role = strtoupper($_SESSION['role'] ?? '');

if ($role !== 'ADMIN') {
    http_response_code(403);
    exit('Access denied. Only administrators can manage buildings.');
}

require_once __DIR__ . '/../config/db.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = '';
$editBuilding = null;
$buildingName = '';

if (isset($_GET['success'])) {
    $success = 'Building changes saved successfully.';
}

if (isset($_GET['deleted'])) {
    $success = 'Building deleted successfully.';
}

/* Add or update a building */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $buildingName = trim($_POST['building_name'] ?? '');

    if ($buildingName === '' || mb_strlen($buildingName) > 100) {
        $errors[] = 'Building name is required and must not exceed 100 characters.';
    }

    if ($action === 'save' && !$errors) {
        $buildingId = filter_input(INPUT_POST, 'building_id', FILTER_VALIDATE_INT);

        try {
            if ($buildingId) {
                $statement = $pdo->prepare(
                    'UPDATE buildings
                     SET building_name = :name
                     WHERE building_id = :id'
                );

                $statement->execute([
                    'name' => $buildingName,
                    'id' => $buildingId
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO buildings (building_name)
                     VALUES (:name)'
                );

                $statement->execute(['name' => $buildingName]);
            }

            header('Location: buildings.php?success=1');
            exit;
        } catch (PDOException $e) {
            error_log('Buildings save error: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'That building name may already exist or be restricted by related records.';
            } else {
                $errors[] = 'Unable to save the building. Check the PHP server terminal.';
            }
        }
    }

    if ($action === 'delete') {
        $deleteId = filter_var(
            $_POST['building_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$deleteId || $deleteId < 1) {
            $errors[] = 'Invalid building selected.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'DELETE FROM buildings WHERE building_id = :id'
                );
                $statement->execute(['id' => $deleteId]);

                if ($statement->rowCount() > 0) {
                    header('Location: buildings.php?deleted=1');
                    exit;
                }

                $errors[] = 'The selected building was not found.';
            } catch (PDOException $e) {
                error_log('Buildings delete error: ' . $e->getMessage());
                $errors[] = 'This building may have rooms or reports linked to it. Remove or reassign those records first.';
            }
        }
    }
}

/* Load a building for editing */
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $statement = $pdo->prepare(
            'SELECT building_id, building_name
             FROM buildings
             WHERE building_id = :id'
        );
        $statement->execute(['id' => $editId]);
        $editBuilding = $statement->fetch();

        if ($editBuilding) {
            $buildingName = $editBuilding['building_name'];
        } else {
            $errors[] = 'The selected building was not found.';
        }
    }
}

/* List buildings and their room counts */
try {
    $buildings = $pdo->query(
        'SELECT b.building_id, b.building_name,
                COUNT(r.room_id) AS room_count
         FROM buildings b
         LEFT JOIN rooms r ON r.building_id = b.building_id
         GROUP BY b.building_id, b.building_name
         ORDER BY b.building_name'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Buildings list error: ' . $e->getMessage());
    $buildings = [];
    $errors[] = 'Unable to load buildings. Check the PHP server terminal.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buildings | CampusCare</title>
    <link rel="stylesheet" href="../assets/css/buildings.css">
</head>
<body>
<header class="topbar">
    <div>
        <h1>CampusCare</h1>
        <p>Building Management</p>
    </div>
    <nav>
        <a href="../dashboard.php">Dashboard</a>
        <a href="../reports/index.php">Facility Reports</a>
        <a href="../logout.php">Log Out</a>
    </nav>
</header>

<main class="container">
    <section class="page-heading">
        <p class="eyebrow">ADMINISTRATION</p>
        <h2>Buildings</h2>
        <p>Manage campus buildings and review how many rooms belong to each one.</p>
    </section>

    <?php if ($success): ?>
        <div class="notice success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="notice error">
            <strong>Please check:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h3><?= $editBuilding ? 'Edit Building' : 'Add a Building' ?></h3>

        <form method="POST" action="buildings.php<?= $editBuilding ? '?edit=' . (int) $editBuilding['building_id'] : '' ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($editBuilding): ?>
                <input type="hidden" name="building_id" value="<?= (int) $editBuilding['building_id'] ?>">
            <?php endif; ?>

            <label for="building_name">Building Name</label>
            <input
                type="text"
                id="building_name"
                name="building_name"
                maxlength="100"
                value="<?= e($buildingName) ?>"
                placeholder="e.g. Main Academic Building"
                required
            >

            <div class="form-actions">
                <?php if ($editBuilding): ?>
                    <a class="button secondary" href="buildings.php">Cancel</a>
                <?php endif; ?>
                <button class="button primary" type="submit">
                    <?= $editBuilding ? 'Save Changes' : 'Add Building' ?>
                </button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="list-heading">
            <div>
                <h3>Campus Buildings</h3>
                <p><?= count($buildings) ?> building(s) registered</p>
            </div>
        </div>

        <?php if (!$buildings): ?>
            <p class="empty-state">No buildings found yet.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Building Name</th>
                        <th>Rooms</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($buildings as $building): ?>
                        <tr>
                            <td><?= (int) $building['building_id'] ?></td>
                            <td><?= e($building['building_name']) ?></td>
                            <td><?= (int) $building['room_count'] ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="button secondary small"
                                       href="buildings.php?edit=<?= (int) $building['building_id'] ?>">
                                        Edit
                                    </a>

                                    <form method="POST" action="buildings.php"
                                          onsubmit="return confirm('Delete this building? This may fail if rooms or reports reference it.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="building_id"
                                               value="<?= (int) $building['building_id'] ?>">
                                        <button class="button danger small" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
