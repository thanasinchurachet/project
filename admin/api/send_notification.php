<?php
// กำหนดค่า header สำหรับ API
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
require_once '../db1.php';

// ตรวจสอบว่าเป็นคำขอ POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'ต้องใช้คำขอ POST']);
    exit;
}

// รับข้อมูล JSON จากคำขอ
$data = json_decode(file_get_contents('php://input'), true);

// ตรวจสอบข้อมูลที่จำเป็น
if (!isset($data['userId']) || !isset($data['message'])) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

// ดึงข้อมูลจากคำขอ
$userId = $data['userId'];
$message = $data['message'];
$type = isset($data['type']) ? $data['type'] : 'info'; // 'info', 'warning', 'error', 'success'

/**
 * ส่งการแจ้งเตือนไปยังผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @param string $message ข้อความแจ้งเตือน
 * @param string $type ประเภทการแจ้งเตือน
 * @return array ผลลัพธ์การทำงาน
 */
function sendNotification($conn, $userId, $message, $type = 'info') {
    try {
        // ตรวจสอบว่าผู้ใช้มีอยู่หรือไม่
        $query = "SELECT userID FROM users WHERE userID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'ไม่พบผู้ใช้'
            ];
        }

        // บันทึกการแจ้งเตือน
        $query = "INSERT INTO notifications (userID, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $userId, $message);
        $stmt->execute();
        
        $notificationId = $conn->insert_id;
        
        if ($notificationId) {
            return [
                'success' => true,
                'message' => 'ส่งการแจ้งเตือนสำเร็จ',
                'data' => [
                    'notificationId' => $notificationId,
                    'userId' => $userId,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'ไม่สามารถส่งการแจ้งเตือนได้'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ];
    }
}

// ส่งการแจ้งเตือน
$result = sendNotification($conn, $userId, $message, $type);

// ส่งผลลัพธ์
echo json_encode($result);
?>