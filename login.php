<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit();
}

// Admin account comes from .env (ADMIN_EMAIL / ADMIN_PASSWORD). Not set = no admin login.
$adminEmail = env('ADMIN_EMAIL');
$adminPass  = env('ADMIN_PASSWORD');
if ($adminEmail !== '' && $adminPass !== '' && hash_equals($adminEmail, $email) && hash_equals($adminPass, $password)) {
    $_SESSION['userid'] = 'admin';
    $_SESSION['name'] = 'Administrator';
    $_SESSION['role'] = 'admin';
    
    echo json_encode([
        'success' => true,
        'role' => 'admin',
        'name' => 'Administrator'
    ]);
    exit();
}

try {
    // ดึงข้อมูลผู้ใช้โดยไม่ตรวจสอบสถานะก่อน เพื่อให้สามารถแจ้งสถานะได้อย่างเหมาะสม
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // ไม่พบผู้ใช้
        echo json_encode(['success' => false, 'message' => 'ไม่พบบัญชีผู้ใช้นี้ในระบบ']);
        exit();
    }

    // ตรวจสอบสถานะบัญชี
    if ($user['status'] === 'suspended') {
        echo json_encode([
            'success' => false, 
            'message' => 'บัญชีของคุณถูกระงับ โปรดติดต่อผู้ดูแลระบบ',
            'suspended' => true
        ]);
        exit();
    }

    // ตรวจสอบรหัสผ่าน: hashed (password_hash) — accounts created before hashing still log in once
    // with their old plain-text password and are upgraded to a hash right away.
    $stored = (string) $user['password'];
    $ok = password_verify($password, $stored);
    if (!$ok && password_get_info($stored)['algoName'] === 'unknown' && $stored !== '' && hash_equals($stored, $password)) {
        $ok = true;
        $pdo->prepare("UPDATE users SET password = ? WHERE userID = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $user['userID']]);
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['userid'] = $user['userID'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['profile_img'] = $user['profile_img'];
        $_SESSION['role'] = 'user';
    
        echo json_encode([
            'success' => true,
            'role' => 'user',
            'userID' => $user['userID'],
            'name' => $user['name'],
            'profile_img' => $user['profile_img']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง']);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อกับฐานข้อมูล: ' . $e->getMessage()
    ]);
}
?>