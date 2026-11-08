<?php
// กำหนดค่า header สำหรับ API
header('Content-Type: application/json');

// เชื่อมต่อฐานข้อมูล
require_once '../db1.php';

/**
 * ดึงข้อมูลผู้ใช้ทั้งหมดตามเงื่อนไข
 * 
 * @param int $page หน้าที่ต้องการ
 * @param int $limit จำนวนรายการต่อหน้า
 * @param string $search คำค้นหา
 * @param string $status สถานะผู้ใช้ ('all', 'active', 'suspended')
 * @return array ข้อมูลผู้ใช้
 */
function getUsers($conn, $page = 1, $limit = 10, $search = '', $status = 'all') {
    try {
        $offset = ($page - 1) * $limit;
        
        // สร้างคำสั่ง SQL พื้นฐาน
        $query = "SELECT u.*, 
                 (SELECT COUNT(*) FROM post WHERE userId = u.userID) AS total_posts,
                 (SELECT COUNT(*) FROM comment WHERE userID = u.userID) AS total_comments,
                 (SELECT COUNT(*) FROM post_report WHERE userID = u.userID) + 
                 (SELECT COUNT(*) FROM comment_report WHERE userID = u.userID) AS total_reports,
                 (SELECT MAX(date) FROM post WHERE userId = u.userID) AS last_post_date,
                 (SELECT MAX(date_created) FROM comment WHERE userID = u.userID) AS last_comment_date";
                 
        
        // เพิ่มเงื่อนไขการค้นหา
        $query .= " FROM users u WHERE 1=1";
        
        // เพิ่มเงื่อนไขสถานะ
        if ($status !== 'all') {
            $query .= " AND status = ?";
        }
        
        // เพิ่มเงื่อนไขคำค้นหา
        if (!empty($search)) {
            $query .= " AND (name LIKE ? OR email LIKE ?)";
        }
        
        // เพิ่มการเรียงลำดับและการแบ่งหน้า
        $query .= " ORDER BY userID DESC LIMIT ?, ?";
        
        // เตรียมคำสั่ง SQL
        $stmt = $conn->prepare($query);
        
        // ผูกพารามิเตอร์
        if ($status !== 'all' && !empty($search)) {
            $searchParam = "%{$search}%";
            $stmt->bind_param("ssii", $status, $searchParam, $searchParam, $offset, $limit);
        } else if ($status !== 'all') {
            $stmt->bind_param("sii", $status, $offset, $limit);
        } else if (!empty($search)) {
            $searchParam = "%{$search}%";
            $stmt->bind_param("ssii", $searchParam, $searchParam, $offset, $limit);
        } else {
            $stmt->bind_param("ii", $offset, $limit);
        }
        
        // ประมวลผลคำสั่ง SQL
        $stmt->execute();
        $result = $stmt->get_result();
        
        // ดึงข้อมูลจากผลลัพธ์
        $users = [];
        while ($row = $result->fetch_assoc()) {
            // เพิ่มข้อมูลการจำกัดการใช้งาน
            $row['restrictions'] = getUserRestrictions($conn, $row['userID']);
            $row['profile_img'] = !empty($row['profile_img']) 
    ? $row['profile_img'] 
    : 'default.jpg';
            // กำหนดวันที่กิจกรรมล่าสุด
            $lastPostDate = $row['last_post_date'] ?? null;
            $lastCommentDate = $row['last_comment_date'] ?? null;
            
            if ($lastPostDate && $lastCommentDate) {
                $row['last_activity'] = strtotime($lastPostDate) > strtotime($lastCommentDate) ? $lastPostDate : $lastCommentDate;
            } else if ($lastPostDate) {
                $row['last_activity'] = $lastPostDate;
            } else if ($lastCommentDate) {
                $row['last_activity'] = $lastCommentDate;
            } else {
                $row['last_activity'] = null;
            }
            
            // ลบฟิลด์ที่ไม่จำเป็น
            unset($row['last_post_date']);
            unset($row['last_comment_date']);
            
            $users[] = $row;
        }
        
        // นับจำนวนผู้ใช้ทั้งหมดที่ตรงกับเงื่อนไข (สำหรับการแบ่งหน้า)
        $countQuery = "SELECT COUNT(*) as total FROM users u WHERE 1=1";
        
        if ($status !== 'all') {
            $countQuery .= " AND status = ?";
        }
        
        if (!empty($search)) {
            $countQuery .= " AND (name LIKE ? OR email LIKE ?)";
        }
        
        $countStmt = $conn->prepare($countQuery);
        
        if ($status !== 'all' && !empty($search)) {
            $searchParam = "%{$search}%";
            $countStmt->bind_param("sss", $status, $searchParam, $searchParam);
        } else if ($status !== 'all') {
            $countStmt->bind_param("s", $status);
        } else if (!empty($search)) {
            $searchParam = "%{$search}%";
            $countStmt->bind_param("ss", $searchParam, $searchParam);
        }
        
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalUsers = $countResult->fetch_assoc()['total'];
        
        return [
            'success' => true,
            'data' => [
                'users' => $users,
                'total' => (int)$totalUsers,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'pages' => ceil($totalUsers / $limit)
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
 * ดึงข้อมูลการจำกัดการใช้งานของผู้ใช้
 * 
 * @param mysqli $conn การเชื่อมต่อฐานข้อมูล
 * @param int $userId รหัสผู้ใช้
 * @return array ข้อมูลการจำกัดการใช้งานที่ยังใช้งานอยู่
 */
function getUserRestrictions($conn, $userId) {
    try {
        // ดึงข้อมูลการระงับบัญชี
        $query = "SELECT * FROM account_suspension WHERE userID = ? AND status = 'active' AND (end_date IS NULL OR end_date > NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $restrictions = [];
        
        // เพิ่มข้อมูลการระงับบัญชี
        while ($row = $result->fetch_assoc()) {
            $restrictions[] = [
                'type' => 'full',
                'reason' => $row['reason'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'] ?? 'permanent'
            ];
        }
        
        // ดึงข้อมูลการจำกัดการใช้งานอื่นๆ (ถ้ามีตาราง user_restrictions)
        if (tableExists($conn, 'user_restrictions')) {
            $query = "SELECT * FROM user_restrictions WHERE userID = ? AND status = 'active' AND end_date > NOW()";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // เพิ่มข้อมูลการจำกัดการใช้งาน
            while ($row = $result->fetch_assoc()) {
                $restrictions[] = [
                    'type' => $row['restriction_type'],
                    'reason' => $row['reason'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date']
                ];
            }
        }
        
        return $restrictions;
    } catch (Exception $e) {
        return [];
    }
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

// ดึงพารามิเตอร์
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

// ดึงข้อมูลผู้ใช้
$result = getUsers($conn, $page, $limit, $search, $status);

// ส่งผลลัพธ์
echo json_encode($result);
?>