<?php
require_once 'db1.php';
header('Content-Type: application/json');

try {
    $query = "SELECT trashId, name, Waste_type FROM trash ORDER BY name ASC";
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("เกิดข้อผิดพลาดในการดึงข้อมูล: " . $conn->error);
    }
    
    $wastes = [];
    while ($row = $result->fetch_assoc()) {
        $wastes[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $wastes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>