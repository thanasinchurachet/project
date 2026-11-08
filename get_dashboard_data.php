<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $data = [];
    
    // จำนวนโพสต์ทั้งหมด
    $stmt = $pdo->query("SELECT COUNT(*) as total_posts FROM post");
    $data['total_posts'] = $stmt->fetch()['total_posts'];

    // จำนวนโพสต์ที่รอตรวจสอบ
    $stmt = $pdo->query("SELECT COUNT(*) as pending_posts FROM post WHERE status = 'pending'");
    $data['pending_posts'] = $stmt->fetch()['pending_posts'];

    // จำนวนผู้ใช้ทั้งหมด
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users WHERE status = 'active'");
    $data['total_users'] = $stmt->fetch()['total_users'];

    // จำนวนรายงานคอมเมนต์ที่ยังไม่ได้จัดการ
    $stmt = $pdo->query("SELECT COUNT(*) as comment_reports FROM comment_report WHERE status = 'visible'");
    $data['comment_reports'] = $stmt->fetch()['comment_reports'];

    // กิจกรรมล่าสุด (5 รายการ)
    $stmt = $pdo->query("
        SELECT 
            'post' as type,
            p.title as description,
            p.date as date,
            u.name as user_name
        FROM post p
        JOIN users u ON p.userId = u.userID
        WHERE p.status = 'pending'
        UNION ALL
        SELECT 
            'comment_report' as type,
            cr.reason as description,
            cr.Report_date as date,
            u.name as user_name
        FROM comment_report cr
        JOIN users u ON cr.userID = u.userID
        WHERE cr.status = 'visible'
        ORDER BY date DESC
        LIMIT 5
    ");
    $data['recent_activities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อกับฐานข้อมูล'
    ]);
}
?>