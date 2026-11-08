<?php

/**
 * API สำหรับจัดการข้อมูลขยะ (CRUD)
 */
header('Content-Type: application/json');
require_once '../db1.php';
$method = $_SERVER['REQUEST_METHOD'];
// สนับสนุน method override สำหรับ PUT
if ($method === 'POST' && isset($_GET['_method']) && $_GET['_method'] === 'PUT') {
    $method = 'PUT';
}
// รับ HTTP method

// รับ ID จาก URL parameter ถ้ามี (เช่น api/trash.php?id=1)
$trashId = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {
        // ดึงข้อมูลขยะ
    case 'GET':
        getTrashItems($conn, $trashId);
        break;

        // เพิ่มข้อมูลขยะใหม่
    case 'POST':
        addTrashItem($conn);
        break;

        // แก้ไขข้อมูลขยะ
    case 'PUT':
        updateTrashItem($conn, $trashId);
        break;

        // ลบข้อมูลขยะ
    case 'DELETE':
        deleteTrashItem($conn, $trashId);
        break;

    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

/**
 * ฟังก์ชั่นดึงข้อมูลขยะ
 * @param mysqli $conn เชื่อมต่อฐานข้อมูล
 * @param int|null $id ID ของขยะ (ถ้าต้องการข้อมูลเฉพาะรายการ)
 */
function getTrashItems($conn, $id = null)
{
    try {
        // เพิ่ม subquery เพื่อนับจำนวนโพสต์ที่เกี่ยวข้อง
        $sql = "SELECT t.*, 
                       (SELECT COUNT(*) FROM post WHERE trashId = t.trashId) as post_count
                FROM trash t
                WHERE t.trashId > 0";

        if ($id !== null) {
            $sql .= " AND t.trashId = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
        } else {
            $stmt = $conn->prepare($sql);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if ($id !== null) {
            // กรณีดึงข้อมูลรายการเดียว
            if ($result->num_rows === 0) {
                http_response_code(404); // Not Found
                echo json_encode(['success' => false, 'message' => 'Item not found']);
                return;
            }

            $item = $result->fetch_assoc();

            // ดึงวิธีการจัดการขยะจากตาราง waste_management
            $managementSql = "SELECT category FROM waste_management WHERE trashId = ?";
            $managementStmt = $conn->prepare($managementSql);
            $managementStmt->bind_param("i", $id);
            $managementStmt->execute();
            $managementResult = $managementStmt->get_result();

            $methods = [];
            while ($row = $managementResult->fetch_assoc()) {
                $methods[] = $row['category'];
            }

            $item['management_methods'] = $methods;

            echo json_encode(['success' => true, 'data' => $item]);
        } else {
            // กรณีดึงข้อมูลทั้งหมด
            $items = [];
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }

            echo json_encode(['success' => true, 'data' => $items]);
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}
// เพิ่มฟังก์ชันสำหรับอัปโหลดรูปภาพ
function uploadImage($file)
{
    $target_dir = "../../img/trash_images/";

    // ตรวจสอบว่าไดเร็กทอรีมีอยู่หรือไม่ ถ้าไม่มีให้สร้าง
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    // สร้างชื่อไฟล์ใหม่เพื่อป้องกันการซ้ำกัน
    $imageFileType = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $newFileName = uniqid() . "." . $imageFileType;
    $target_file = $target_dir . $newFileName;

    // ตรวจสอบว่าเป็นไฟล์รูปภาพจริงหรือไม่
    $check = getimagesize($file["tmp_name"]);
    if ($check === false) {
        throw new Exception("ไฟล์ที่อัปโหลดไม่ใช่รูปภาพ");
    }

    // ตรวจสอบนามสกุลไฟล์
    if ($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg") {
        throw new Exception("อนุญาตเฉพาะไฟล์ JPG, JPEG, PNG เท่านั้น");
    }

    // ตรวจสอบขนาดไฟล์ (ไม่เกิน 2MB)
    if ($file["size"] > 2 * 1024 * 1024) {
        throw new Exception("ไฟล์มีขนาดใหญ่เกินไป (ไม่เกิน 2MB)");
    }

    // อัปโหลดไฟล์
    if (!move_uploaded_file($file["tmp_name"], $target_file)) {
        throw new Exception("เกิดข้อผิดพลาดในการอัปโหลดไฟล์");
    }

    return $newFileName;
}

/**
 * ฟังก์ชั่นเพิ่มข้อมูลขยะใหม่
 * @param mysqli $conn เชื่อมต่อฐานข้อมูล
 */
function addTrashItem($conn)
{
    try {
        // รับข้อมูลจาก FormData
        $name = $_POST['name'] ?? '';
        $waste_type = $_POST['Waste_type'] ?? '';
        $management_methods = isset($_POST['management_methods']) ? json_decode($_POST['management_methods'], true) : [];

        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($name) || empty($waste_type)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        // ป้องกันชื่อขยะซ้ำ (ชื่อเดียวกันห้ามซ้ำ ไม่ว่าประเภทใด)
        $dupSql = "SELECT trashId FROM trash WHERE LOWER(name) = LOWER(?) LIMIT 1";
        $dupStmt = $conn->prepare($dupSql);
        $dupStmt->bind_param("s", $name);
        $dupStmt->execute();
        $dupRes = $dupStmt->get_result();
        if ($dupRes && $dupRes->num_rows > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'มีชื่อขยะนี้อยู่แล้วในระบบ (ห้ามซ้ำ)']);
            return;
        }

        // หา ID ที่ใหญ่ที่สุดในตาราง trash และ + 1
        $maxIdSql = "SELECT MAX(trashId) as max_id FROM trash";
        $maxStmt = $conn->prepare($maxIdSql);
        $maxStmt->execute();
        $result = $maxStmt->get_result();
        $row = $result->fetch_assoc();
        $newId = ($row['max_id'] ?? 0) + 1;

        // อัปโหลดรูปภาพ (ถ้ามี)
        $image = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $image = uploadImage($_FILES['image']);
        }

        // เริ่ม transaction
        $conn->begin_transaction();

        // เพิ่มข้อมูลโดยระบุ ID ด้วย
        if ($image) {
            $sql = "INSERT INTO trash (trashId, name, Waste_type, image) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isss", $newId, $name, $waste_type, $image);
        } else {
            $sql = "INSERT INTO trash (trashId, name, Waste_type) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $newId, $name, $waste_type);
        }
        $stmt->execute();

        // เพิ่ม waste_management (กันค่าซ้ำในคำขอ)
        if (!empty($management_methods)) {
            $management_methods = array_values(array_unique($management_methods));
            $managementSql = "INSERT INTO waste_management (trashId, category) VALUES (?, ?)";
            $managementStmt = $conn->prepare($managementSql);

            foreach ($management_methods as $method) {
                $managementStmt->bind_param("is", $newId, $method);
                $managementStmt->execute();
            }
        }

        // บันทึกประวัติ (อย่าลืม execute!)
        $logSql = "INSERT INTO admin_log (action, targetID, target_type, reason) VALUES ('add_trash', ?, 'trash', ?)";
        $reason = "เพิ่มขยะใหม่: " . $name;
        $logStmt = $conn->prepare($logSql);
        $logStmt->bind_param("is", $newId, $reason);
        $logStmt->execute();

        // ส่งการแจ้งเตือน
        $notificationSql = "INSERT INTO notifications (userID, message, is_read, created_at) 
                          SELECT userID, ?, 0, NOW() FROM users WHERE status = 'active'";
        $notificationMessage = "ระบบได้เพิ่มข้อมูลขยะใหม่: " . $name . " (" . $waste_type . ") แล้ว";
        $notificationStmt = $conn->prepare($notificationSql);
        $notificationStmt->bind_param("s", $notificationMessage);
        $notificationStmt->execute();

        // Commit
        $conn->commit();

        // ส่งผลลัพธ์
        echo json_encode([
            'success' => true,
            'message' => 'Item added successfully',
            'data' => ['trashId' => $newId]
        ]);
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollback();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}


/**
 * ฟังก์ชั่นแก้ไขข้อมูลขยะ
 * @param mysqli $conn เชื่อมต่อฐานข้อมูล
 * @param int $id ID ของขยะที่ต้องการแก้ไข
 */
function updateTrashItem($conn, $id)
{
    try {
        // ตรวจสอบว่ามี ID หรือไม่
        if ($id === null) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'ID is required']);
            return;
        }

        // รับข้อมูลจาก PUT request
        $inputData = file_get_contents('php://input');

        // หากมีการส่งข้อมูลแบบ multipart/form-data
        if ($_SERVER['CONTENT_TYPE'] && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
            // ใช้ $_POST สำหรับข้อมูลทั่วไป และ $_FILES สำหรับไฟล์
            $name = $_POST['name'] ?? '';
            $waste_type = $_POST['Waste_type'] ?? '';
            $management_methods = isset($_POST['management_methods']) ? json_decode($_POST['management_methods'], true) : [];
        } else {
            // Parse JSON input
            parse_str($inputData, $putData);
            $name = $putData['name'] ?? '';
            $waste_type = $putData['Waste_type'] ?? '';
            $management_methods = isset($putData['management_methods']) ? json_decode($putData['management_methods'], true) : [];
        }

        // ตรวจสอบว่ามีข้อมูลที่จะอัปเดตหรือไม่
        $hasDataToUpdate = !empty($name) || !empty($waste_type) || isset($_FILES['image']);
        if (!$hasDataToUpdate) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'No data to update']);
            return;
        }

        // เริ่ม transaction
        $conn->begin_transaction();

        // ดึงข้อมูลเดิมเพื่อเปรียบเทียบการเปลี่ยนแปลง
        $getOriginalSql = "SELECT name, Waste_type, image FROM trash WHERE trashId = ?";
        $getOriginalStmt = $conn->prepare($getOriginalSql);
        $getOriginalStmt->bind_param("i", $id);
        $getOriginalStmt->execute();
        $originalResult = $getOriginalStmt->get_result();

        if ($originalResult->num_rows === 0) {
            // Rollback transaction
            $conn->rollback();

            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Item not found']);
            return;
        }

        $originalData = $originalResult->fetch_assoc();
        $nameChanged = !empty($name) && $name !== $originalData['name'];
        $typeChanged = !empty($waste_type) && $waste_type !== $originalData['Waste_type'];
        $imageChanged = false;

        // ถ้าเปลี่ยนชื่อ ให้ตรวจสอบไม่ให้ซ้ำกับรายการอื่น
        if ($nameChanged) {
            $dupSql = "SELECT trashId FROM trash WHERE LOWER(name) = LOWER(?) AND trashId <> ? LIMIT 1";
            $dupStmt = $conn->prepare($dupSql);
            $dupStmt->bind_param("si", $name, $id);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            if ($dupRes && $dupRes->num_rows > 0) {
                $conn->rollback();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'ชื่อขยะซ้ำกับรายการอื่นในระบบ']);
                return;
            }
        }

        // อัปโหลดรูปภาพ (ถ้ามี)
        $newImage = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            try {
                $newImage = uploadImage($_FILES['image']);
                $imageChanged = true;
            } catch (Exception $e) {
                // Rollback transaction
                $conn->rollback();

                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                return;
            }
        }

        // สร้าง SQL สำหรับอัปเดต
        $updateFields = [];
        $params = [];
        $types = '';


        if (!empty($name)) {
            $updateFields[] = "name = ?";
            $params[] = $name;
            $types .= 's';
        }

        if (!empty($waste_type)) {
            $updateFields[] = "Waste_type = ?";
            $params[] = $waste_type;
            $types .= 's';
        }

        if ($newImage) {
            $updateFields[] = "image = ?";
            $params[] = $newImage;
            $types .= 's';

            // ลบรูปเก่าออก (ถ้ามี)
            if (!empty($originalData['image'])) {
                $oldImagePath = "../../img/trash_images/" . $originalData['image'];
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }
        }

        if (empty($updateFields)) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'No valid fields to update']);
            return;
        }

        // เพิ่ม ID เข้าไปใน params
        $params[] = $id;
        $types .= 'i';

        // อัปเดตข้อมูลในตาราง trash
        $sql = "UPDATE trash SET " . implode(", ", $updateFields) . " WHERE trashId = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // ตรวจสอบว่ามีการอัปเดตหรือไม่
        if ($stmt->affected_rows === 0 && $stmt->errno !== 0) {
            // Rollback transaction
            $conn->rollback();

            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Item not found or no changes made']);
            return;
        }

        // อัปเดตวิธีการจัดการขยะ (ถ้ามี) และกันข้อมูลซ้ำ
        if (!empty($management_methods)) {
            $management_methods = array_values(array_unique($management_methods));
            // ลบวิธีการจัดการเดิม
            $deleteSql = "DELETE FROM waste_management WHERE trashId = ?";
            $deleteStmt = $conn->prepare($deleteSql);
            $deleteStmt->bind_param("i", $id);
            $deleteStmt->execute();

            // เพิ่มวิธีการจัดการใหม่
            $managementSql = "INSERT INTO waste_management (trashId, category) VALUES (?, ?)";
            $managementStmt = $conn->prepare($managementSql);

            foreach ($management_methods as $method) {
                $managementStmt->bind_param("is", $id, $method);
                $managementStmt->execute();
            }
        }

        // บันทึกประวัติการแก้ไขขยะในตาราง admin_log
        $logSql = "INSERT INTO admin_log (action, targetID, target_type, reason) VALUES ('edit_trash', ?, 'trash', ?)";
        $reason = "แก้ไขข้อมูลขยะ: " . ($name ?: $originalData['name']);
        $logStmt = $conn->prepare($logSql);
        $logStmt->bind_param("is", $id, $reason);
        $logStmt->execute();

        // ส่งการแจ้งเตือนไปยังผู้ใช้เฉพาะเมื่อมีการเปลี่ยนแปลงที่สำคัญ
        if ($nameChanged || $typeChanged || $imageChanged) {
            $notificationSql = "INSERT INTO notifications (userID, message, is_read, created_at) 
                               SELECT userID, ?, 0, NOW() FROM users WHERE status = 'active'";

            $notificationMessage = "ระบบได้อัปเดตข้อมูลขยะ: ";
            $notificationDetails = [];

            if ($nameChanged) {
                $notificationDetails[] = "เปลี่ยนชื่อจาก \"" . $originalData['name'] . "\" เป็น \"" . $name . "\"";
            }

            if ($typeChanged) {
                $notificationDetails[] = "เปลี่ยนประเภทจาก " . $originalData['Waste_type'] . " เป็น " . $waste_type;
            }

            if ($imageChanged) {
                $notificationDetails[] = "อัปเดตรูปภาพ";
            }

            $notificationMessage .= implode(" และ ", $notificationDetails);

            $notificationStmt = $conn->prepare($notificationSql);
            $notificationStmt->bind_param("s", $notificationMessage);
            $notificationStmt->execute();
        }

        // Commit transaction
        $conn->commit();

        // ส่งผลลัพธ์กลับ
        echo json_encode([
            'success' => true,
            'message' => 'Item updated successfully',
            'data' => [
                'trashId' => $id,
                'updatedFields' => $updateFields
            ]
        ]);
    } catch (Exception $e) {
        // Rollback transaction หากเกิดข้อผิดพลาด
        // Rollback transaction หากเกิดข้อผิดพลาด
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollback();
        }

        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}
// อัปโหลดรูปภาพ (ถ้ามี)


/**
 * ฟังก์ชั่นลบข้อมูลขยะ
 * @param mysqli $conn เชื่อมต่อฐานข้อมูล
 * @param int $id ID ของขยะที่ต้องการลบ
 */
function deleteTrashItem($conn, $id)
{
    try {
        // ตรวจสอบว่ามี ID หรือไม่
        if ($id === null) {
            http_response_code(400); // Bad Request
            echo json_encode(['success' => false, 'message' => 'ID is required']);
            return;
        }

        // ตรวจสอบว่ามีโพสต์ที่เกี่ยวข้องหรือไม่
        $checkSql = "SELECT COUNT(*) as post_count FROM post WHERE trashId = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $postCount = $result->fetch_assoc()['post_count'];

        if ($postCount > 0) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete item with associated posts',
                'data' => ['post_count' => $postCount]
            ]);
            return;
        }

        // เริ่ม transaction
        $conn->begin_transaction();

        // ดึงข้อมูลขยะเพื่อใช้ในการบันทึกประวัติและแจ้งเตือน
        $getNameSql = "SELECT name, Waste_type FROM trash WHERE trashId = ?";
        $getNameStmt = $conn->prepare($getNameSql);
        $getNameStmt->bind_param("i", $id);
        $getNameStmt->execute();
        $nameResult = $getNameStmt->get_result();

        if ($nameResult->num_rows === 0) {
            // Rollback transaction
            $conn->rollback();

            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Item not found']);
            return;
        }

        $trashData = $nameResult->fetch_assoc();
        $trashName = $trashData['name'];
        $trashType = $trashData['Waste_type'];

        // ลบวิธีการจัดการขยะก่อน (foreign key constraint)
        $managementSql = "DELETE FROM waste_management WHERE trashId = ?";
        $managementStmt = $conn->prepare($managementSql);
        $managementStmt->bind_param("i", $id);
        $managementStmt->execute();

        // ลบข้อมูลขยะในตาราง trash_content (ถ้ามี)
        $contentSql = "DELETE FROM trash_content WHERE trashId = ?";
        $contentStmt = $conn->prepare($contentSql);
        $contentStmt->bind_param("i", $id);
        $contentStmt->execute();

        // ลบข้อมูลขยะ
        $sql = "DELETE FROM trash WHERE trashId = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // ตรวจสอบว่ามีการลบหรือไม่
        if ($stmt->affected_rows === 0) {
            // Rollback transaction
            $conn->rollback();

            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Item not found or already deleted']);
            return;
        }

        // บันทึกประวัติการลบขยะในตาราง admin_log
        $logSql = "INSERT INTO admin_log (action, targetID, target_type, reason) VALUES ('delete_trash', ?, 'trash', ?)";
        $reason = "ลบข้อมูลขยะ: " . $trashName;
        $logStmt = $conn->prepare($logSql);
        $logStmt->bind_param("is", $id, $reason);
        $logStmt->execute();

        // ส่งการแจ้งเตือนไปยังผู้ใช้ทุกคนเกี่ยวกับการลบขยะ
        $notificationSql = "INSERT INTO notifications (userID, message, is_read, created_at) 
                           SELECT userID, ?, 0, NOW() FROM users WHERE status = 'active'";
        $notificationMessage = "ระบบได้ลบข้อมูลขยะ: " . $trashName . " (" . $trashType . ") ออกจากระบบแล้ว";
        $notificationStmt = $conn->prepare($notificationSql);
        $notificationStmt->bind_param("s", $notificationMessage);
        $notificationStmt->execute();

        // Commit transaction
        $conn->commit();

        // ส่งผลลัพธ์กลับ
        echo json_encode([
            'success' => true,
            'message' => 'Item deleted successfully'
        ]);
    } catch (Exception $e) {
        // Rollback transaction หากเกิดข้อผิดพลาด
        $conn->rollback();

        http_response_code(500); // Internal Server Error
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}
