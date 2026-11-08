<?php
session_start();
header('Content-Type: application/json');

file_put_contents('debug_log.txt', 
    date('Y-m-d H:i:s') . 
    ' - SESSION: ' . json_encode($_SESSION) . 
    ' - POST: ' . json_encode($_POST) . 
    "\n", 
    FILE_APPEND);
require_once 'db.php';

try {
    // ตรวจสอบว่าผู้ใช้เข้าสู่ระบบหรือไม่
    if (!isset($_SESSION['userid'])) {
        echo json_encode([
            'canComment' => false, 
            'message' => 'กรุณาเข้าสู่ระบบก่อนแสดงความคิดเห็น'
        ]);
        exit;
    }

    $userId = $_SESSION['userid'];
    error_log("Checking restrictions for user ID: $userId");

    // ตรวจสอบการระงับการคอมเมนต์
    $query = "
        SELECT 
            restriction_id, 
            end_date, 
            is_permanent,
            reason 
        FROM user_restrictions 
        WHERE 
            userID = ? AND 
            restriction_type IN ('comment', 'both') AND 
            status = 'active' AND 
            (CAST(is_permanent AS UNSIGNED) = 1 OR (CAST(is_permanent AS UNSIGNED) = 0 AND end_date > NOW()))
        LIMIT 1
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $restriction = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Restriction found: " . ($restriction ? 'Yes' : 'No'));
    if ($restriction) {
        error_log("Restriction details: " . print_r($restriction, true));
        
        // มีการระงับการคอมเมนต์
        if ($restriction['is_permanent'] == 1) {
            $message = "คุณถูกระงับการแสดงความคิดเห็นอย่างถาวร เนื่องจาก: {$restriction['reason']}";
        } else {
            $endDate = date('d/m/Y H:i', strtotime($restriction['end_date']));
            $message = "คุณถูกระงับการแสดงความคิดเห็นจนถึงวันที่ {$endDate} เนื่องจาก: {$restriction['reason']}";
        }

        echo json_encode([
            'canComment' => false, 
            'message' => $message
        ]);
    } else {
        // ไม่มีการระงับการคอมเมนต์
        echo json_encode([
            'canComment' => true
        ]);
    }
} catch (Exception $e) {
    error_log("Error in check_comment_restriction.php: " . $e->getMessage());
    echo json_encode([
        'canComment' => false, 
        'message' => 'เกิดข้อผิดพลาดในการตรวจสอบสถานะ: ' . $e->getMessage()
    ]);
}
?>