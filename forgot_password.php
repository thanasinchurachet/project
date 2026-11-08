<?php
require 'vendor/autoload.php';
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$email = $_POST['email'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกอีเมล']);
    exit();
}

try {
    // 1. ตรวจสอบว่ามีอีเมลนี้ในระบบหรือไม่
    $stmt = $pdo->prepare("SELECT userID FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // เพื่อความปลอดภัย, ไม่ต้องแจ้งว่าไม่มีอีเมลนี้ในระบบ
        // แค่ส่งข้อความสำเร็จไปเหมือนกัน เพื่อป้องกันการเดาอีเมล
        echo json_encode(['success' => true, 'message' => 'หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งลิงก์รีเซ็ตรหัสผ่านไปให้แล้ว']);
        exit();
    }

    // 2. สร้าง Token ที่ปลอดภัยและกำหนดวันหมดอายุ
    $token = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", time() + 3600); // หมดอายุใน 1 ชั่วโมง

    // 3. บันทึก Token ลงฐานข้อมูล
    $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE email = ?");
    $stmt->execute([$token, $expires, $email]);

    // 4. ส่งอีเมลด้วย PHPMailer
    $mail = new PHPMailer(true);

    // ตั้งค่า Server
    $mail->isSMTP();
    $mail->Host       = env('SMTP_HOST', 'smtp.gmail.com');
    $mail->SMTPAuth   = true;
    $mail->Username   = env('SMTP_USER');            // .env
    $mail->Password   = env('SMTP_PASSWORD');        // .env (Gmail app password)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int) env('SMTP_PORT', 587);
    $mail->CharSet    = 'UTF-8';

    // ตั้งค่าผู้รับ-ผู้ส่ง
    $mail->setFrom(env('SMTP_FROM', env('SMTP_USER')), 'BinBuddy');
    $mail->addAddress($email);

    // สร้างลิงก์สำหรับรีเซ็ตรหัสผ่าน (APP_URL ใน .env เช่น http://localhost/binbuddy)
    $resetLink = rtrim(env('APP_URL', 'http://localhost/binbuddy'), '/') . "/reset_password.php?token=" . $token;

    // เนื้อหาอีเมล
    $mail->isHTML(true);
    $mail->Subject = 'คำขอรีเซ็ตรหัสผ่านสำหรับ BinBuddy';
    $mail->Body    = "สวัสดีครับ,<br><br>เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณ<br>"
                   . "กรุณาคลิกที่ลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่:<br>"
                   . "<a href='$resetLink'>$resetLink</a><br><br>"
                   . "หากคุณไม่ได้เป็นผู้ร้องขอ กรุณาเพิกเฉยต่ออีเมลนี้<br><br>"
                   . "ขอบคุณครับ,<br>ทีมงาน BinBuddy";
    $mail->AltBody = "กรุณาคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์เพื่อรีเซ็ตรหัสผ่าน: $resetLink";

    $mail->send();

    echo json_encode(['success' => true, 'message' => 'หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งลิงก์รีเซ็ตรหัสผ่านไปให้แล้ว']);

} catch (Exception $e) {
    // กรณีส่งไม่สำเร็จ
    echo json_encode(['success' => false, 'message' => "เกิดข้อผิดพลาดในการส่งอีเมล: {$mail->ErrorInfo}"]);
}

?>