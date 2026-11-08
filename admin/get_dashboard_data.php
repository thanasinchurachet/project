<?php
// กำหนดค่า header เพื่อให้ส่งข้อมูลเป็น JSON
header('Content-Type: application/json');

// เชื่อมต่อกับฐานข้อมูล
$db_host = 'localhost';
$db_user = 'root'; // แก้ไขตามการตั้งค่าของคุณ
$db_pass = ''; // แก้ไขตามการตั้งค่าของคุณ
$db_name = 'waste_system';

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // เตรียมข้อมูลที่จะส่งกลับ
    $response = [
        'success' => true,
        'data' => []
    ];
    
    // ดึงจำนวนโพสต์ทั้งหมด
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM post");
    $response['data']['total_posts'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // ดึงจำนวนโพสต์ที่รอตรวจสอบ
    $stmt = $conn->query("SELECT COUNT(*) AS pending FROM post WHERE status = 'pending'");
    $response['data']['pending_posts'] = $stmt->fetch(PDO::FETCH_ASSOC)['pending'];
    
    // ดึงจำนวนรายงานคอมเมนต์ที่รอดำเนินการ
    $stmt = $conn->query("SELECT COUNT(*) AS reports FROM comment_report WHERE action_status = 'pending'");
    $response['data']['comment_reports'] = $stmt->fetch(PDO::FETCH_ASSOC)['reports'];
    
    // ดึงจำนวนผู้ใช้ทั้งหมด
    $stmt = $conn->query("SELECT COUNT(*) AS total FROM users WHERE status = 'active'");
    $response['data']['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // ดึงข้อมูลกิจกรรมล่าสุด (ผสมระหว่างโพสต์ใหม่และรายงานคอมเมนต์)
    $recent_posts_query = "
        SELECT 
            'post' AS type,
            p.title AS description,
            u.name AS user_name,
            u.profile_img AS user_image,
            p.date AS date,
            p.postId AS id
        FROM post p
        JOIN users u ON p.userId = u.userID
        WHERE p.status = 'pending' OR p.date > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY p.date DESC
        LIMIT 5
    ";
    
    $recent_reports_query = "
        SELECT 
            'report' AS type,
            CONCAT('รายงานคอมเมนต์: ', LEFT(c.content, 30), IF(LENGTH(c.content) > 30, '...', '')) AS description,
            u.name AS user_name,
            u.profile_img AS user_image,
            cr.Report_date AS date,
            cr.commentID AS id
        FROM comment_report cr
        JOIN users u ON cr.userID = u.userID
        JOIN comment c ON cr.commentID = c.commentID
        WHERE cr.action_status = 'pending'
        ORDER BY cr.Report_date DESC
        LIMIT 5
    ";
    
    $stmt = $conn->query($recent_posts_query);
    $recent_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $conn->query($recent_reports_query);
    $recent_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // รวมและเรียงข้อมูลกิจกรรมตามวันที่
    $activities = array_merge($recent_posts, $recent_reports);
    usort($activities, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    // ตัดให้เหลือแค่ 10 รายการ
    $activities = array_slice($activities, 0, 10);
    $response['data']['recent_activities'] = $activities;
    
    // สรุปข้อมูลเพิ่มเติม
    
    // ผู้ใช้ที่มีกิจกรรมมากที่สุด (จากโพสต์และคอมเมนต์)
    $stmt = $conn->query("
        SELECT 
            u.name, 
            COUNT(p.postId) as post_count 
        FROM users u
        LEFT JOIN post p ON u.userID = p.userId
        GROUP BY u.userID
        ORDER BY post_count DESC
        LIMIT 1
    ");
    $most_active = $stmt->fetch(PDO::FETCH_ASSOC);
    $most_active_user = $most_active ? $most_active['name'] . ' (' . $most_active['post_count'] . ' โพสต์)' : '-';
    
    // ประเภทขยะยอดนิยม
    $stmt = $conn->query("
        SELECT 
            t.Waste_type,
            COUNT(p.postId) as post_count,
            ROUND((COUNT(p.postId) * 100.0 / (SELECT COUNT(*) FROM post)), 1) as percentage
        FROM trash t
        JOIN post p ON t.trashId = p.trashId
        GROUP BY t.Waste_type
        ORDER BY post_count DESC
        LIMIT 1
    ");
    $popular_trash = $stmt->fetch(PDO::FETCH_ASSOC);
    $popular_trash_type = $popular_trash ? $popular_trash['Waste_type'] . ' (' . $popular_trash['percentage'] . '%)' : '-';
    
    // อัตราการอนุมัติโพสต์ (เปลี่ยนเป็นจำนวนอนุมัติ / จำนวนโพสต์ทั้งหมด)
    $stmt = $conn->query("
        SELECT 
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
            COUNT(*) as total
        FROM post
        WHERE status IN ('approved', 'pending', 'suspended')
    ");
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
    $approval_rate = $approval ? $approval['approved'] . ' / ' . $approval['total'] : '0 / 0';
    
    // รายงานที่ดำเนินการแล้ว
    $stmt = $conn->query("
        SELECT 
            COUNT(CASE WHEN action_status IN ('hidden', 'ignored') THEN 1 END) as handled,
            COUNT(*) as total
        FROM comment_report
    ");
    $reports = $stmt->fetch(PDO::FETCH_ASSOC);
    $handled_reports = $reports ? $reports['handled'] . '/' . $reports['total'] : '-';
    
    $response['data']['summary'] = [
        'most_active_user' => $most_active_user,
        'popular_trash_type' => $popular_trash_type,
        'approval_rate' => $approval_rate,
        'handled_reports' => $handled_reports
    ];
    
    // ส่งข้อมูลเป็น JSON
    echo json_encode($response);
    
} catch (PDOException $e) {
    // กรณีเกิดข้อผิดพลาด
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>