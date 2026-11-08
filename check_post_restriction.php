<?php
session_start();
header('Content-Type: application/json');

// บันทึกข้อมูลการเรียกใช้งานสำหรับการแก้ไขข้อผิดพลาด
file_put_contents('post_debug_log.txt', 
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
            'canPost' => false, 
            'message' => 'กรุณาเข้าสู่ระบบก่อนโพสต์'
        ]);
        exit;
    }

    $userId = $_SESSION['userid'];
    
    // ตรวจสอบการระงับการโพสต์
    $query = "
        SELECT 
            restriction_id, 
            end_date, 
            is_permanent,
            reason 
        FROM user_restrictions 
        WHERE 
            userID = ? AND 
            restriction_type IN ('post', 'both') AND 
            status = 'active' AND 
            (CAST(is_permanent AS UNSIGNED) = 1 OR (CAST(is_permanent AS UNSIGNED) = 0 AND end_date > NOW()))
        LIMIT 1
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $restriction = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($restriction) {
        // มีการระงับการโพสต์
        if ($restriction['is_permanent'] == 1) {
            $message = "คุณถูกระงับการโพสต์อย่างถาวร เนื่องจาก: {$restriction['reason']}";
        } else {
            $endDate = date('d/m/Y H:i', strtotime($restriction['end_date']));
            $message = "คุณถูกระงับการโพสต์จนถึงวันที่ {$endDate} เนื่องจาก: {$restriction['reason']}";
        }

        echo json_encode([
            'canPost' => false, 
            'message' => $message
        ]);
    } else {
        // ไม่มีการระงับการโพสต์
        echo json_encode([
            'canPost' => true
        ]);
    }
} catch (Exception $e) {
    error_log("Error in check_post_restriction.php: " . $e->getMessage());
    echo json_encode([
        'canPost' => false, 
        'message' => 'เกิดข้อผิดพลาดในการตรวจสอบสถานะ: ' . $e->getMessage()
    ]);
}
?>