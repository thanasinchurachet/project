<?php
// กำหนดค่า header สำหรับ API
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
require_once '../db1.php';

// เพิ่ม logging สำหรับการแก้ไขข้อผิดพลาด
error_log("Unsuspend user API called");

// ตรวจสอบว่าเป็นคำขอ POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'ต้องใช้คำขอ POST']);
    exit;
}

// รับข้อมูล JSON จากคำขอ
$jsonData = file_get_contents('php://input');
error_log("Received data: " . $jsonData);
$data = json_decode($jsonData, true);

// ตรวจสอบข้อมูลที่จำเป็น
if (!isset($data['userId'])) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

// ดึงข้อมูลจากคำขอ
$userId = $data['userId'];
$notify = isset($data['notify']) ? $data['notify'] : false;

error_log("Processing unsuspend for userId: $userId, notify: " . ($notify ? 'yes' : 'no'));

/**
 * ยกเลิกการระงับบัญชีผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @return array ผลลัพธ์การทำงาน
 */
function unsuspendUser($conn, $userId) {
    try {
        // ตรวจสอบว่ามีผู้ใช้นี้หรือไม่
        $checkQuery = "SELECT status FROM users WHERE userID = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            error_log("User not found: $userId");
            return [
                'success' => false,
                'message' => 'ไม่พบผู้ใช้'
            ];
        }
        
        $userStatus = $checkResult->fetch_assoc()['status'];
        error_log("Current user status: $userStatus");
        
        // 1. อัปเดตสถานะผู้ใช้เป็น 'active'
        $query = "UPDATE users SET status = 'active' WHERE userID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        $updatedRows = $stmt->affected_rows;
        error_log("Updated user status, affected rows: $updatedRows");

        // 2. อัปเดตสถานะในตาราง account_suspension
        $query = "UPDATE account_suspension SET status = 'lifted', end_date = NOW() WHERE userID = ? AND status = 'active'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        $updatedSuspensions = $stmt->affected_rows;
        error_log("Updated suspensions, affected rows: $updatedSuspensions");

        // 3. บันทึกใน admin_log
        $reason = "ยกเลิกการระงับบัญชีผู้ใช้";
        $query = "INSERT INTO admin_log (action, targetID, target_type, reason) VALUES ('unsuspend_user', ?, 'user', ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $userId, $reason);
        $stmt->execute();
        
        $logInserted = $stmt->affected_rows;
        error_log("Inserted log entry, affected rows: $logInserted");

        return [
            'success' => true,
            'message' => 'ยกเลิกการระงับบัญชีผู้ใช้สำเร็จ',
            'data' => [
                'userId' => $userId,
                'previousStatus' => $userStatus,
                'updatedRows' => $updatedRows,
                'updatedSuspensions' => $updatedSuspensions,
                'logCreated' => $logInserted > 0,
                'unsuspend_date' => date('Y-m-d H:i:s')
            ]
        ];
    } catch (Exception $e) {
        error_log("Error in unsuspendUser: " . $e->getMessage());
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
        $success = $stmt->execute();
        error_log("Notification sent: " . ($success ? 'yes' : 'no'));
        return $success;
    } catch (Exception $e) {
        error_log("Error sending notification: " . $e->getMessage());
        return false;
    }
}

// ยกเลิกการระงับบัญชีผู้ใช้
$result = unsuspendUser($conn, $userId);

// ถ้าสำเร็จและต้องการส่งการแจ้งเตือน
if ($result['success'] && $notify) {
    $message = "บัญชีของคุณได้รับการยกเลิกการระงับแล้ว คุณสามารถเข้าใช้งานได้ตามปกติ";
    $notificationSent = sendNotification($conn, $userId, $message);
    
    // เพิ่มข้อมูลสถานะการส่งการแจ้งเตือนในผลลัพธ์
    $result['notification_sent'] = $notificationSent;
}

// ส่งผลลัพธ์
$jsonResult = json_encode($result);
error_log("Sending response: " . $jsonResult);
echo $jsonResult;
?>