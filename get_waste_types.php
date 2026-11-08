<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $query = "SELECT DISTINCT Waste_type FROM trash";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $wasteTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // แปลงเป็นภาษาไทย
    $translatedTypes = array_map(function($type) {
        return [
            'value' => $type, // เก็บค่าภาษาอังกฤษไว้เป็น value
            'label' => $type  // ใช้ค่าภาษาไทยที่มีอยู่แล้วเป็น label
        ];
    }, $wasteTypes);
    
    echo json_encode(['success' => true, 'types' => $translatedTypes]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>