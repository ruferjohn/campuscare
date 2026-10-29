
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
    http_response_code(403);
    exit('Access denied. Only administrators can manage rooms.');
}

require_once __DIR__ . '/../config/db.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = '';
$roomName = '';
$buildingId = '';
$editRoom = null;

if (isset($_GET['success'])) {
    $success = 'Room saved successfully.';
} elseif (isset($_GET['deleted'])) {
    $success = 'Room deleted successfully.';
}

/* Load buildings for the form */
try {
    $buildings = $pdo->query(
        'SELECT building_id, building_name
         FROM buildings
         ORDER BY building_name'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Rooms building dropdown error: ' . $e->getMessage());
    $buildings = [];
    $errors[] = 'Unable to load buildings.';
}

/* Add or update a room */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $roomName = trim($_POST['room_name'] ?? '');
    $buildingId = trim($_POST['building_id'] ?? '');

    if (!in_array($action, ['save', 'delete'], true)) {
        $errors[] = 'Invalid action.';
    }

    if ($action === 'save') {
        if ($roomName === '' || mb_strlen($roomName) > 100) {
            $errors[] = 'Room name is required and must not exceed 100 characters.';
        }

        if (!ctype_digit($buildingId) || (int) $buildingId < 1) {
            $errors[] = 'Please select a valid building.';
        } else {
            $checkBuilding = $pdo->prepare(
                'SELECT building_id FROM buildings WHERE building_id = :id'
            );
            $checkBuilding->execute(['id' => (int) $buildingId]);

            if (!$checkBuilding->fetch()) {
                $errors[] = 'The selected building does not exist.';
            }
        }

        if (!$errors) {
            $roomId = filter_var(
                $_POST['room_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            try {
                if ($roomId && $roomId > 0) {
                    $statement = $pdo->prepare(
                        'UPDATE rooms
                         SET room_name = :room_name,
                             building_id = :building_id
                         WHERE room_id = :room_id'
                    );

                    $statement->execute([
                        'room_name' => $roomName,
                        'building_id' => (int) $buildingId,
                        'room_id' => $roomId
                    ]);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO rooms (room_name, building_id)
                         VALUES (:room_name, :building_id)'
                    );

                    $statement->execute([
                        'room_name' => $roomName,
                        'building_id' => (int) $buildingId
                    ]);
                }

                header('Location: rooms.php?success=1');
                exit;
            } catch (PDOException $e) {
                error_log('Rooms save error: ' . $e->getMessage());

                if ($e->getCode() === '23000') {
                    $errors[] = 'This room may already exist in that building, or the change conflicts with related records.';
                } else {
                    $errors[] = 'Unable to save the room. Check the PHP server terminal.';
                }
            }
        }
    }

    /* Delete a room */
    if ($action === 'delete') {
        $deleteId = filter_var(
            $_POST['room_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$deleteId || $deleteId < 1) {
            $errors[] = 'Invalid room selected.';
        } else {
            try {
                $statement = $pdo->prepare(
                    'DELETE FROM rooms WHERE room_id = :id'
                );
                $statement->execute(['id' => $deleteId]);

                if ($statement->rowCount() > 0) {
                    header('Location: rooms.php?deleted=1');
                    exit;
                }

                $errors[] = 'The selected room was not found.';
            } catch (PDOException $e) {
                error_log('Rooms delete error: ' . $e->getMessage());
                $errors[] = 'This room may be linked to existing reports. It cannot be deleted until those references are handled.';
            }
        }
    }
}

/* Load a room into the edit form */
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $statement = $pdo->prepare(
            'SELECT room_id, room_name, building_id
             FROM rooms
             WHERE room_id = :id'
        );
        $statement->execute(['id' => $editId]);
        $editRoom = $statement->fetch();

        if ($editRoom) {
            $roomName = $editRoom['room_name'];
            $buildingId = (string) $editRoom['building_id'];
        } else {
            $errors[] = 'The selected room was not found.';
        }
    }
}

/* List rooms with their building names */
try {
    $rooms = $pdo->query(
        'SELECT r.room_id, r.room_name, r.building_id,
                b.building_name
         FROM rooms r
         LEFT JOIN buildings b ON b.building_id = r.building_id
         ORDER BY b.building_name, r.room_name'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Rooms list error: ' . $e->getMessage());
    $rooms = [];
    $errors[] = 'Unable to load the rooms list.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms | CampusCare</title>
    <link rel="stylesheet" href="../assets/css/rooms.css">
</head>
<body>
<header class="topbar">
    <div>
        <h1>CampusCare</h1>
        <p>Room Management</p>
    </div>
    <nav>
        <a href="../dashboard.php">Dashboard</a>
        <a href="buildings.php">Buildings</a>
        <a href="../reports/index.php">Facility Reports</a>
        <a href="../logout.php">Log Out</a>
    </nav>
</header>

<main class="container">
    <section class="page-heading">
        <p class="eyebrow">ADMINISTRATION</p>
        <h2>Rooms</h2>
        <p>Organize campus rooms and assign each room to its building.</p>
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
        <h3><?= $editRoom ? 'Edit Room' : 'Add a Room' ?></h3>

        <?php if (!$buildings): ?>
            <p class="empty-state">Add a building before creating a room.</p>
        <?php else: ?>
            <form method="POST" action="rooms.php<?= $editRoom ? '?edit=' . (int) $editRoom['room_id'] : '' ?>">
                <input type="hidden" name="action" value="save">

                <?php if ($editRoom): ?>
                    <input type="hidden" name="room_id"
                           value="<?= (int) $editRoom['room_id'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="room_name">Room Name</label>
                        <input
                            type="text"
                            id="room_name"
                            name="room_name"
                            maxlength="100"
                            value="<?= e($roomName) ?>"
                            placeholder="e.g. Computer Laboratory 1"
                            required
                        >
                    </div>

                    <div class="form-field">
                        <label for="building_id">Building</label>
                        <select id="building_id" name="building_id" required>
                            <option value="">Select building</option>
                            <?php foreach ($buildings as $building): ?>
                                <option
                                    value="<?= (int) $building['building_id'] ?>"
                                    <?= $buildingId === (string) $building['building_id'] ? 'selected' : '' ?>
                                >
                                    <?= e($building['building_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <?php if ($editRoom): ?>
                        <a class="button secondary" href="rooms.php">Cancel</a>
                    <?php endif; ?>
                    <button class="button primary" type="submit">
                        <?= $editRoom ? 'Save Changes' : 'Add Room' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="list-heading">
            <div>
                <h3>Campus Rooms</h3>
                <p><?= count($rooms) ?> room(s) registered</p>
            </div>
        </div>

        <?php if (!$rooms): ?>
            <p class="empty-state">No rooms found yet.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Room Name</th>
                        <th>Building</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rooms as $room): ?>
                        <tr>
                            <td><?= (int) $room['room_id'] ?></td>
                            <td><?= e($room['room_name']) ?></td>
                            <td><?= e($room['building_name'] ?? 'Unassigned') ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="button secondary small"
                                       href="rooms.php?edit=<?= (int) $room['room_id'] ?>">
                                        Edit
                                    </a>

                                    <form method="POST" action="rooms.php"
                                          onsubmit="return confirm('Delete this room? Existing reports may reference it.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="room_id"
                                               value="<?= (int) $room['room_id'] ?>">
                                        <button class="button danger small" type="submit">
                                            Delete
                                        </button>
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
