<?php
// ไฟล์: get_admin_notifications.php (ปรับปรุงใหม่)
header('Content-Type: application/json');
require_once 'db1.php'; // ตรวจสอบที่อยู่ของไฟล์เชื่อมต่อฐานข้อมูล

try {
    // ดึงการแจ้งเตือนล่าสุดเฉพาะที่เกี่ยวข้องกับแอดมิน
    $query = "SELECT 
                'post' AS type,
                p.postId AS id,
                p.title,
                u.name AS username,
                p.date AS date,
                'Post pending approval' AS message
              FROM post p
              JOIN users u ON p.userId = u.userID
              WHERE p.status = 'pending'
              
              UNION
              
              SELECT 
                'comment' AS type,
                cr.commentID AS id,
                SUBSTRING(c.content, 1, 50) AS title,
                u.name AS username,
                cr.Report_date AS date,
                'Comment reported' AS message
              FROM comment_report cr
              JOIN comment c ON cr.commentID = c.commentID
              JOIN users u ON c.userID = u.userID
              WHERE cr.action_status = 'pending'
              
              ORDER BY date DESC
              LIMIT 10";
    
    $result = $conn->query($query);
    $notifications = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // สร้างข้อความแจ้งเตือนที่เหมาะสม
            if ($row['type'] == 'post') {
                $row['message'] = "มีโพสต์ใหม่รออนุมัติ: \"{$row['title']}\" จาก {$row['username']}";
            } else {
                $row['message'] = "มีคอมเมนต์ถูกรายงาน: \"{$row['title']}\" โดย {$row['username']}";
            }
            
            // แปลงวันที่ให้อยู่ในรูปแบบที่อ่านง่าย
            $date = new DateTime($row['date']);
            $row['formatted_date'] = $date->format('d/m/Y H:i');
            
            $notifications[] = $row;
        }
    }
    
    echo json_encode($notifications);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
$conn->close();
?>