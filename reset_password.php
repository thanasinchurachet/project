<?php
require_once 'db.php';
$token_invalid = false;
$token_expired = false;
$update_success = false;
$error_message = '';
$token = $_GET['token'] ?? '';

// --- ส่วนตรวจสอบ Token ---
if (!empty($token)) {
    try {
        $stmt = $pdo->prepare("SELECT userID, reset_token_expires_at FROM users WHERE reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $token_invalid = true;
        } else {
            // ตรวจสอบว่า token หมดอายุหรือยัง
            $expiry_time = strtotime($user['reset_token_expires_at']);
            if (time() > $expiry_time) {
                $token_expired = true;
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        $token_invalid = true;
    }
} else {
    $token_invalid = true;
}

// --- ส่วนประมวลผลฟอร์ม ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$token_invalid && !$token_expired) {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    $post_token = $_POST['token'];

    if (empty($password) || empty($password_confirm)) {
        $error_message = "กรุณากรอกรหัสผ่านทั้งสองช่อง";
    } elseif ($password !== $password_confirm) {
        $error_message = "รหัสผ่านทั้งสองช่องไม่ตรงกัน";
    } elseif (strlen($password) < 6) {
        $error_message = "รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร";
    } else {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE reset_token = ? AND reset_token_expires_at > NOW()");
            $stmt->execute([$hashed_password, $post_token]);
            $update_success = $stmt->rowCount() > 0;
            if (!$update_success) $error_message = "ลิงก์รีเซ็ตหมดอายุหรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่";
        } catch (PDOException $e) {
            $error_message = "เกิดข้อผิดพลาดในการอัปเดตข้อมูล";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>BinBuddy - ตั้งรหัสผ่านใหม่</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <style> body { font-family: 'Kanit', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
            <h1 class="text-3xl font-bold text-green-600 text-center mb-6">ตั้งรหัสผ่านใหม่</h1>

            <?php if ($update_success): ?>
                <div class="text-center p-4 bg-green-100 text-green-800 rounded-lg">
                    <p class="font-bold">ตั้งรหัสผ่านใหม่สำเร็จ!</p>
                    <p>คุณสามารถใช้รหัสผ่านใหม่เพื่อเข้าสู่ระบบได้แล้ว</p>
                    <a href="login.html" class="mt-4 inline-block bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700">กลับไปหน้าเข้าสู่ระบบ</a>
                </div>
            <?php elseif ($token_invalid || $token_expired): ?>
                <div class="text-center p-4 bg-red-100 text-red-800 rounded-lg">
                    <p class="font-bold">ลิงก์ไม่ถูกต้องหรือหมดอายุ</p>
                    <p>กรุณาทำรายการขอรีเซ็ตรหัสผ่านอีกครั้ง</p>
                    <a href="login.html" class="mt-4 inline-block bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700">กลับไปหน้าเข้าสู่ระบบ</a>
                </div>
            <?php else: ?>
                <?php if (!empty($error_message)): ?>
                <div class="p-4 mb-4 bg-red-100 text-red-800 rounded-lg">
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
                <?php endif; ?>
                <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="mb-4">
                        <label for="password" class="block text-gray-700 mb-2">รหัสผ่านใหม่</label>
                        <input type="password" name="password" id="password" class="w-full p-3 border rounded-lg" required>
                    </div>
                    <div class="mb-6">
                        <label for="password_confirm" class="block text-gray-700 mb-2">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" name="password_confirm" id="password_confirm" class="w-full p-3 border rounded-lg" required>
                    </div>
                    <button type="submit" class="w-full bg-green-600 text-white p-3 rounded-lg hover:bg-green-700">บันทึกรหัสผ่านใหม่</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>