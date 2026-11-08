<?php
// กำหนดค่า header เพื่อให้ส่งข้อมูลเป็น JSON
header('Content-Type: application/json');

// เชื่อมต่อกับฐานข้อมูล
$db_host = 'localhost';
$db_user = 'root'; // แก้ไขตามการตั้งค่าของคุณ
$db_pass = '12345678'; // แก้ไขตามการตั้งค่าของคุณ
$db_name = 'waste_system';

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // เตรียมข้อมูลที่จะส่งกลับ
    $response = [
        'success' => true,
        'data' => []
    ];
    
    // สร้าง array สำหรับเก็บวันที่ 7 วันล่าสุด (รวมวันนี้)
    $days = [];
    $labels = [];
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $days[] = $date;
        
        // สร้างป้ายกำกับตามวันในสัปดาห์ภาษาไทย
        $dayOfWeek = date('w', strtotime("-$i days"));
        $thaiDays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
        $labels[] = $thaiDays[$dayOfWeek];
    }
    
    // ดึงข้อมูลโพสต์ใหม่ในแต่ละวัน
    $new_posts = [];
    foreach ($days as $day) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS count 
            FROM post 
            WHERE DATE(date) = ?
        ");
        $stmt->execute([$day]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_posts[] = (int)$result['count'];
    }
    
    // ดึงข้อมูลรายงานใหม่ในแต่ละวัน
    $new_reports = [];
    foreach ($days as $day) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS count 
            FROM comment_report 
            WHERE DATE(Report_date) = ?
        ");
        $stmt->execute([$day]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_reports[] = (int)$result['count'];
    }
    
    // เตรียมข้อมูลสำหรับแสดงในกราฟ
    $response['data'] = [
        'labels' => $labels,
        'new_posts' => $new_posts,
        'new_reports' => $new_reports
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