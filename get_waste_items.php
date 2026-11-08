<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%';
    $trashId = isset($_GET['trashId']) ? (int)$_GET['trashId'] : null;

    $query = "SELECT trashId, name, Waste_type FROM trash WHERE name LIKE :search";
    $params = [':search' => $search];

    if ($trashId) {
        $query .= " AND trashId = :trashId";
        $params[':trashId'] = $trashId;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>