<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php'; // หรือไฟล์เชื่อมต่อ DB เดิมของโปรเจกต์คุณ

try {
    // นับเนื้อหาต่อขยะ
    $sql = "SELECT trashId, COUNT(*) AS cnt FROM trash_content GROUP BY trashId";
    $stmt = $pdo->query($sql);

    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[$row['trashId']] = (int)$row['cnt'];
    }

    echo json_encode([
        'success' => true,
        'data' => [ 'counts' => $counts ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
