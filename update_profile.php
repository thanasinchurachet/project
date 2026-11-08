<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['userid'])) {
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาเข้าสู่ระบบก่อน'
    ]);
    exit;
}

$response = ['success' => false, 'message' => ''];
$updates = [];

try {
    // ชื่อ
    if (isset($_POST['name']) && !empty($_POST['name'])) {
        $name = trim($_POST['name']);
        if (strlen($name) > 100) {
            $response['message'] = 'ชื่อต้องมีความยาวไม่เกิน 100 ตัวอักษร';
            echo json_encode($response);
            exit;
        }
        $updates['name'] = $name;
    }

    // รูปโปรไฟล์ (อัปโหลดไฟล์)
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $response['message'] = 'รูปโปรไฟล์ต้องเป็นไฟล์ jpg, jpeg, png หรือ gif เท่านั้น';
            echo json_encode($response);
            exit;
        }

        if ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
            $response['message'] = 'ขนาดรูปโปรไฟล์ต้องไม่เกิน 5MB';
            echo json_encode($response);
            exit;
        }

        $new_filename = 'profile_' . $_SESSION['userid'] . '_' . time() . '.' . $ext;
        $upload_path = 'img/profile_uploads/' . $new_filename;

        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
            $updates['profile_img'] = $new_filename;
        } else {
            $response['message'] = 'เกิดข้อผิดพลาดในการอัปโหลดรูปโปรไฟล์';
            echo json_encode($response);
            exit;
        }
    }
    // รูปโปรไฟล์ (สุ่มจาก DiceBear)
    elseif (isset($_POST['avatarSeed']) && !empty($_POST['avatarSeed'])) {
        $seed = $_POST['avatarSeed'];
        $avatarUrl = "https://api.dicebear.com/9.x/avataaars/svg?seed=$seed";
        $new_filename = 'profile_' . $_SESSION['userid'] . '_' . time() . '.svg';
        $upload_path = 'img/profile_uploads/' . $new_filename;
        
        // ดาวน์โหลดและบันทึกรูปภาพ
        $avatarContent = file_get_contents($avatarUrl);
        if ($avatarContent !== false) {
            if (file_put_contents($upload_path, $avatarContent)) {
                $updates['profile_img'] = $new_filename;
            }
        }
    }

    // หากไม่มี errors ให้อัปเดตข้อมูล
    if (!empty($updates)) {
        $updateFields = implode(', ', array_map(function($field) { 
            return "$field = :$field"; 
        }, array_keys($updates)));
        
        $sql = "UPDATE users SET $updateFields WHERE userID = :userID";
        $stmt = $pdo->prepare($sql);
        
        foreach ($updates as $field => $value) {
            $stmt->bindValue(":$field", $value);
        }
        $stmt->bindValue(':userID', $_SESSION['userid'], PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $response = [
                'success' => true,
                'message' => 'อัปเดตโปรไฟล์สำเร็จ',
                'name' => $updates['name'] ?? null,
                'newAvatarPath' => isset($updates['profile_img']) ? 'img/profile_uploads/' . $updates['profile_img'] : null
            ];
        } else {
            $response['message'] = 'ไม่สามารถอัปเดตข้อมูลได้';
        }
    } else {
        $response = [
            'success' => true,
            'message' => 'ไม่มีข้อมูลที่ต้องอัปเดต'
        ];
    }
} catch (PDOException $e) {
    $response['message'] = 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล';
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>