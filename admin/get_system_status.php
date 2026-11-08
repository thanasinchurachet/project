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
    
    // ดึงข้อมูลขนาดของตาราง users
    $stmt = $conn->query("
        SELECT 
            ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'users'
    ");
    $users_size = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'];
    
    // ดึงข้อมูลขนาดของตาราง post
    $stmt = $conn->query("
        SELECT 
            ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'post'
    ");
    $posts_size = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'];
    
    // ดึงข้อมูลขนาดของตาราง comment
    $stmt = $conn->query("
        SELECT 
            ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = 'comment'
    ");
    $comments_size = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'];
    
    // คำนวณขนาดรวมของฐานข้อมูล
    $stmt = $conn->query("
        SELECT 
            ROUND(SUM((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '$db_name'
    ");
    $total_db_size = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'];
    
    // คำนวณเปอร์เซ็นต์การใช้พื้นที่ (สมมติว่ามีพื้นที่ทั้งหมด 100MB)
    $disk_usage_percent = min(round(($total_db_size / 100) * 100), 100);
    
    // ดึงข้อมูล log ล่าสุดเพื่อหา uptime
    $stmt = $conn->query("
        SELECT MIN(date_action) AS first_action, MAX(date_action) AS last_action
        FROM admin_log
    ");
    $log_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $first_action = strtotime($log_data['first_action']);
    $last_action = strtotime($log_data['last_action']);
    $uptime_days = ceil((time() - $first_action) / (60 * 60 * 24));
    
    // คำนวณการใช้งาน CPU และ RAM (ค่าจำลอง - ในระบบจริงควรอ่านจากระบบ)
    $cpu_usage = rand(10, 45); // 10-45%
    $memory_usage = rand(30, 60); // 30-60%
    
    // เตรียมข้อมูลสถานะระบบ
    $response['data'] = [
        'cpu_usage' => $cpu_usage,
        'memory_usage' => $memory_usage,
        'disk_usage' => $disk_usage_percent,
        'uptime' => [
            'days' => $uptime_days,
            'hours' => rand(0, 23)
        ],
        'db_stats' => [
            'users_size' => $users_size,
            'posts_size' => $posts_size,
            'comments_size' => $comments_size,
            'total_size' => $total_db_size
        ]
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