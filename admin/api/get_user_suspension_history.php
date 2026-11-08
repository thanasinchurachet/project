<?php
// กำหนดค่า header สำหรับ API
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
require_once '../db1.php';

// ตรวจสอบว่ามีพารามิเตอร์ userId
if (!isset($_GET['userId'])) {
    echo json_encode(['success' => false, 'message' => 'ต้องระบุรหัสผู้ใช้']);
    exit;
}

$userId = (int) $_GET['userId'];

/**
 * ตรวจสอบไฟล์รูปโปรไฟล์
 * 
 * @param string $profileImg ชื่อไฟล์รูปโปรไฟล์
 * @return string ชื่อไฟล์รูปโปรไฟล์ที่ถูกต้อง
 */
function getProfileImage($profileImg) {
    $defaultAvatar = 'default-avatar.jpg';
    $uploadPath = '../img/profile_uploads/';

    // ถ้าไม่มีรูปโปรไฟล์
    if (empty($profileImg)) {
        return $defaultAvatar;
    }

    // ตรวจสอบว่าไฟล์มีอยู่จริง
    $fullPath = $uploadPath . $profileImg;
    if (file_exists($fullPath)) {
        return $profileImg;
    }

    // ถ้าไฟล์ไม่มี ให้ใช้รูปดีฟอลต์
    return $defaultAvatar;
}

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
 * ดึงประวัติการระงับและจำกัดการใช้งานของผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @return array ข้อมูลประวัติการระงับและจำกัดการใช้งาน
 */
function getUserSuspensionHistory($conn, $userId) {
    try {
        // ตรวจสอบว่าผู้ใช้มีอยู่หรือไม่
        $query = "SELECT userID, name, email, status, profile_img FROM users WHERE userID = ?";
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
        
        $userData = $result->fetch_assoc();
        
        // จัดการรูปโปรไฟล์
        $userData['profile_img'] = getProfileImage($userData['profile_img']);
        
        // ดึงข้อมูลการระงับบัญชี
        $query = "SELECT suspensionID, reason, start_date, end_date, status 
                 FROM account_suspension 
                 WHERE userID = ? 
                 ORDER BY start_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $suspensions = [];
        while ($row = $result->fetch_assoc()) {
            $suspensions[] = [
                'id' => $row['suspensionID'],
                'type' => 'full',
                'reason' => $row['reason'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'] ?? 'permanent',
                'status' => $row['status']
            ];
        }
        
        // ดึงข้อมูลการจำกัดการใช้งาน (ถ้ามีตาราง user_restrictions)
        $restrictions = [];
        if (tableExists($conn, 'user_restrictions')) {
            $query = "SELECT restriction_id, restriction_type, reason, start_date, end_date, status 
                     FROM user_restrictions 
                     WHERE userID = ? 
                     ORDER BY start_date DESC";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $restrictions[] = [
                    'id' => $row['restriction_id'],
                    'type' => $row['restriction_type'],
                    'reason' => $row['reason'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'status' => $row['status']
                ];
            }
        }
        
        return [
            'success' => true,
            'data' => [
                'user' => $userData,
                'suspensions' => $suspensions,
                'restrictions' => $restrictions
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ];
    }
}

// ดึงประวัติการระงับและจำกัดการใช้งานของผู้ใช้
$result = getUserSuspensionHistory($conn, $userId);

// ส่งผลลัพธ์
echo json_encode($result);
?>