<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $avatarSeed = isset($_POST['avatarSeed']) ? $_POST['avatarSeed'] : null;
    
    // Handle profile image upload or use DiceBear avatar
    $profileImg = 'default-avatar.svg';
    $uploadDir = 'img/profile_uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $fileSize = $_FILES['profile_image']['size'];
        if ($fileSize > 5 * 1024 * 1024) { // 5MB limit
            echo json_encode(['success' => false, 'message' => 'ขนาดไฟล์รูปภาพเกิน 5MB']);
            exit;
        }
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = $_FILES['profile_image']['type'];
        if (!in_array($fileType, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะไฟล์ JPG, PNG, GIF เท่านั้น']);
            exit;
        }
        $filename = uniqid() . '.' . pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $filePath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $filePath)) {
            $profileImg = $filename;
        } else {
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ']);
            exit;
        }
    } elseif (!empty($avatarSeed)) {
        // Use DiceBear avatar
        $dicebearUrl = "https://api.dicebear.com/9.x/avataaars/svg?seed=" . urlencode($avatarSeed);
        $svgContent = @file_get_contents($dicebearUrl);
        if ($svgContent !== false) {
            $filename = uniqid() . '.svg';
            $filePath = $uploadDir . $filename;
            if (file_put_contents($filePath, $svgContent) !== false) {
                $profileImg = $filename;
            } else {
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกไฟล์ SVG']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถดาวน์โหลดรูปภาพจาก DiceBear']);
            exit;
        }
    }

    $errors = [];

    if (empty($name)) {
        $errors[] = 'กรุณากรอกชื่อ';
    } elseif (strlen($name) > 100) {
        $errors[] = 'ชื่อต้องมีความยาวไม่เกิน 100 ตัวอักษร';
    }

    if (empty($email)) {
        $errors[] = 'กรุณากรอกอีเมล';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (strlen($email) > 75) {
        $errors[] = 'อีเมลต้องมีความยาวไม่เกิน 75 ตัวอักษร';
    }

    if (empty($password)) {
        $errors[] = 'กรุณากรอกรหัสผ่าน';
    } elseif (strlen($password) < 6) {
        $errors[] = 'รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
    } elseif (strlen($password) > 100) {
        $errors[] = 'รหัสผ่านต้องมีความยาวไม่เกิน 100 ตัวอักษร';
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit;
    }

    try {
        // Check if email or name already exists
        $stmt = $pdo->prepare("SELECT userID FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'อีเมลนี้ถูกใช้งานแล้ว']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT userID FROM users WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'ชื่อนี้ถูกใช้งานแล้ว']);
            exit;
        }

        // Hash password before storing (bcrypt via password_hash)
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user into the database
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, profile_img) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $hashedPassword, $profileImg])) {
            // Auto-login after successful registration
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['userid'] = $user['userID'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['profile_img'] = $user['profile_img'];
                $_SESSION['role'] = 'user';
            }
            echo json_encode(['success' => true, 'message' => 'ลงทะเบียนสำเร็จ']);
        } else {
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการลงทะเบียน']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>