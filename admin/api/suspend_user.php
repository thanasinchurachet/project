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
if (!isset($data['userId']) || !isset($data['reason']) || !isset($data['duration'])) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

// ดึงข้อมูลจากคำขอ
$userId = $data['userId'];
$reason = $data['reason'];
$duration = $data['duration'];
$notify = isset($data['notify']) ? $data['notify'] : false;

/**
 * ระงับบัญชีผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @param string $reason เหตุผลในการระงับ
 * @param int|string $duration ระยะเวลาในการระงับ (จำนวนวันหรือ 'permanent')
 * @return array ผลลัพธ์การทำงาน
 */
function suspendUser($conn, $userId, $reason, $duration) {
    try {
        // 1. อัปเดตสถานะผู้ใช้เป็น 'suspended'
        $query = "UPDATE users SET status = 'suspended' WHERE userID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            return [
                'success' => false,
                'message' => 'ไม่พบผู้ใช้หรือผู้ใช้ถูกระงับไปแล้ว'
            ];
        }

        // 2. บันทึกข้อมูลการระงับในตาราง account_suspension
        $now = date('Y-m-d H:i:s');
        $endDate = null;
        
        if ($duration !== 'permanent') {
            $endDate = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
        }

        $query = "INSERT INTO account_suspension (userID, reason, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("isss", $userId, $reason, $now, $endDate);
        $stmt->execute();

        // 3. บันทึกใน admin_log
        $query = "INSERT INTO admin_log (action, targetID, target_type, reason) VALUES ('suspend_user', ?, 'user', ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $userId, $reason);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'ระงับบัญชีผู้ใช้สำเร็จ',
            'data' => [
                'userId' => $userId,
                'start_date' => $now,
                'end_date' => $endDate
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ];
    }
}

/**
 * ส่งการแจ้งเตือนไปยังผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @param string $message ข้อความแจ้งเตือน
 * @return bool สถานะการทำงาน
 */
function sendNotification($conn, $userId, $message) {
    try {
        $query = "INSERT INTO notifications (userID, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $userId, $message);
        return $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
}

// ระงับบัญชีผู้ใช้
$result = suspendUser($conn, $userId, $reason, $duration);

// ถ้าสำเร็จและต้องการส่งการแจ้งเตือน
if ($result['success'] && $notify) {
    // ดึงข้อมูลระยะเวลาการระงับเพื่อแสดงในการแจ้งเตือน
    $durationText = ($duration === 'permanent') ? 'ถาวร' : $duration . ' วัน';
    
    $message = "บัญชีของคุณถูกระงับเป็นเวลา {$durationText} เนื่องจาก: {$reason}";
    $notificationSent = sendNotification($conn, $userId, $message);
    
    // เพิ่มข้อมูลสถานะการส่งการแจ้งเตือนในผลลัพธ์
    $result['notification_sent'] = $notificationSent;
}

// ส่งผลลัพธ์
echo json_encode($result);
?>