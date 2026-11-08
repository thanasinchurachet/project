<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

file_put_contents('debug_log.txt', 
    date('Y-m-d H:i:s') . 
    ' - SESSION: ' . json_encode($_SESSION) . 
    ' - POST: ' . json_encode($_POST) . 
    "\n", 
    FILE_APPEND);

try {
    // Check if user is logged in
    if (!isset($_SESSION['userid'])) {
        throw new Exception('กรุณาเข้าสู่ระบบก่อนแสดงความคิดเห็น');
    }

    $userId = $_SESSION['userid'];
    $postId = $_POST['postId'] ?? null;
    $commentContent = trim($_POST['comment'] ?? '');

    // Validate inputs
    if (!$postId) {
        throw new Exception('ไม่พบโพสต์');
    }

    if (empty($commentContent)) {
        throw new Exception('กรุณากรอกความคิดเห็น');
    }

    // ตรวจสอบการระงับการคอมเมนต์อีกครั้งที่ฝั่ง server
    $restrictionQuery = "
    SELECT 
        restriction_id, 
        end_date, 
        is_permanent,
        reason 
    FROM user_restrictions 
    WHERE 
        userID = :userId AND 
        restriction_type IN ('comment', 'both') AND 
        status = 'active' AND 
        (CAST(is_permanent AS UNSIGNED) = 1 OR (CAST(is_permanent AS UNSIGNED) = 0 AND end_date > NOW()))
    LIMIT 1
    ";

    $restrictionStmt = $pdo->prepare($restrictionQuery);
    $restrictionStmt->execute(['userId' => $userId]);
    $restriction = $restrictionStmt->fetch(PDO::FETCH_ASSOC);

    if ($restriction) {
        // มีการระงับการคอมเมนต์
        if ($restriction['is_permanent'] == 1) {
            throw new Exception("คุณถูกระงับการแสดงความคิดเห็นอย่างถาวร เนื่องจาก: {$restriction['reason']}");
        } else {
            $endDate = date('d/m/Y H:i', strtotime($restriction['end_date']));
            throw new Exception("คุณถูกระงับการแสดงความคิดเห็นจนถึงวันที่ {$endDate} เนื่องจาก: {$restriction['reason']}");
        }
    }

    // Prepare SQL to insert comment
    $query = "
        INSERT INTO comment (
            postID, 
            userID, 
            content, 
            date_created, 
            status
        ) VALUES (
            :postId, 
            :userId, 
            :content, 
            NOW(), 
            'visible'
        )
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':postId' => $postId,
        ':userId' => $userId,
        ':content' => $commentContent
    ]);

    // นับจำนวนคอมเมนต์ปัจจุบันของโพสต์
    $countQuery = "
        SELECT COUNT(*) AS comment_count 
        FROM comment 
        WHERE postID = :postId AND status = 'visible'
    ";
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute(['postId' => $postId]);
    $commentCount = $countStmt->fetch(PDO::FETCH_ASSOC)['comment_count'];

    // Return success response with comment count
    echo json_encode([
        'success' => true,
        'message' => 'เพิ่มความคิดเห็นสำเร็จ',
        'comment_count' => $commentCount
    ]);
} catch (Exception $e) {
    // Handle errors
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>