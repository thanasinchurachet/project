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
if (!isset($data['userId']) || !isset($data['featureType']) || !isset($data['reason']) || !isset($data['duration'])) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

// ดึงข้อมูลจากคำขอ
$userId = $data['userId'];
$featureType = $data['featureType']; // 'post', 'comment', 'both'
$reason = $data['reason'];
$duration = $data['duration']; // จำนวนวัน หรือ 'permanent'
$notify = isset($data['notify']) ? $data['notify'] : false;

/**
 * ตรวจสอบว่าตารางมีอยู่หรือไม่
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param string $tableName ชื่อตาราง
 * @return bool ผลการตรวจสอบ
 */
function tableExists($conn, $tableName) {
    $sql = "SHOW TABLES LIKE '{$tableName}'";
    $result = $conn->query($sql);
    return $result->num_rows > 0;
}

/**
 * สร้างตาราง user_restrictions ถ้ายังไม่มี
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @return bool ผลการทำงาน
 */
function createRestrictionTableIfNotExists($conn) {
    if (!tableExists($conn, 'user_restrictions')) {
        $sql = "CREATE TABLE user_restrictions (
            restriction_id INT(11) NOT NULL AUTO_INCREMENT,
            userID INT(11) NOT NULL,
            restriction_type ENUM('post', 'comment', 'both') NOT NULL,
            reason TEXT,
            start_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            end_date DATETIME,
            is_permanent BOOLEAN DEFAULT FALSE,
            status ENUM('active', 'lifted') DEFAULT 'active',
            PRIMARY KEY (restriction_id),
            FOREIGN KEY (userID) REFERENCES users(userID)
        )";
        return $conn->query($sql);
    }
    return true;
}

/**
 * จำกัดการใช้งานผู้ใช้ (การโพสต์หรือคอมเมนต์)
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @param string $featureType ประเภทการจำกัด ('post', 'comment', 'both')
 * @param string $reason เหตุผลในการจำกัด
 * @param int|string $duration ระยะเวลาในการจำกัด (จำนวนวัน หรือ 'permanent')
 * @return array ผลลัพธ์การทำงาน
 */
function restrictUserFeatures($conn, $userId, $featureType, $reason, $duration) {
    try {
        // สร้างตาราง user_restrictions ถ้ายังไม่มี
        $tableCreated = createRestrictionTableIfNotExists($conn);
        
        if (!$tableCreated) {
            return [
                'success' => false,
                'message' => 'ไม่สามารถสร้างตารางการจำกัดการใช้งานได้'
            ];
        }

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

        // กำหนด end_date และ is_permanent
        $now = date('Y-m-d H:i:s');
        $endDate = null;
        $isPermanent = false;

        if ($duration === 'permanent') {
            $isPermanent = true;
        } else {
            $endDate = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
        }

        // บันทึกข้อมูลการจำกัดการใช้งาน
        $query = "INSERT INTO user_restrictions (
            userID, 
            restriction_type, 
            reason, 
            start_date, 
            end_date, 
            is_permanent, 
            status
        ) VALUES (?, ?, ?, ?, ?, ?, 'active')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param(
            "issssi", 
            $userId, 
            $featureType, 
            $reason, 
            $now, 
            $endDate, 
            $isPermanent
        );
        $stmt->execute();

        // บันทึกใน admin_log
        $action = "restrict_user_" . $featureType;
        $query = "INSERT INTO admin_log (action, targetID, target_type, reason) VALUES (?, ?, 'user', ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sis", $action, $userId, $reason);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'จำกัดการใช้งานผู้ใช้สำเร็จ',
            'data' => [
                'userId' => $userId,
                'featureType' => $featureType,
                'start_date' => $now,
                'end_date' => $endDate ? $endDate : 'ถาวร',
                'is_permanent' => $isPermanent
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

// จำกัดการใช้งานผู้ใช้
$result = restrictUserFeatures($conn, $userId, $featureType, $reason, $duration);

// ถ้าสำเร็จและต้องการส่งการแจ้งเตือน
if ($result['success'] && $notify) {
    $featureText = '';
    $durationText = $result['data']['is_permanent'] ? 'ถาวร' : "{$duration} วัน";
    
    switch ($featureType) {
        case 'post':
            $featureText = 'การโพสต์';
            break;
        case 'comment':
            $featureText = 'การคอมเมนต์';
            break;
        case 'both':
            $featureText = 'การโพสต์และการคอมเมนต์';
            break;
    }
    
    $message = "คุณถูกจำกัดการใช้งาน{$featureText}เป็นเวลา {$durationText} เนื่องจาก: {$reason}";
    $notificationSent = sendNotification($conn, $userId, $message);
    
    // เพิ่มข้อมูลสถานะการส่งการแจ้งเตือนในผลลัพธ์
    $result['notification_sent'] = $notificationSent;
}

// ส่งผลลัพธ์
echo json_encode($result);
?>