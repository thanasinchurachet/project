<?php
// index.php - หน้าแรกสำหรับทุกคน
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BinBuddy - แยกขยะอย่างชาญฉลาด</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">

    <?php include 'nav.php'; // เรียกใช้ nav bar เวอร์ชันใหม่ ?>

    <div class="container mx-auto px-4" style="margin-top: 100px;">
        <div class="text-center bg-white p-8 rounded-lg shadow-md mb-8">
            <h1 class="text-4xl font-bold text-green-600 mb-2">ยินดีต้อนรับสู่ Bin Buddy</h1>
            <p class="text-lg text-gray-600">แพลตฟอร์มที่จะช่วยให้การจัดการขยะของคุณเป็นเรื่องง่าย สนุก และได้ความรู้</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <a href="<?php echo isset($_SESSION['userid']) ? 'scan.php' : 'login.html'; ?>" class="feature-card bg-white p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow">
                <div class="text-green-500 mb-4 text-4xl"><i class="fas fa-camera"></i></div>
                <h3 class="text-xl font-semibold mb-2">สแกนแยกขยะ</h3>
                <p class="text-gray-500">ใช้กล้องสแกนเพื่อจำแนกประเภทขยะ พร้อมคำแนะนำในการจัดการ</p>
            </a>
            
            <a href="waste-details.php" class="feature-card bg-white p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow">
                <div class="text-blue-500 mb-4 text-4xl"><i class="fas fa-trash-alt"></i></div>
                <h3 class="text-xl font-semibold mb-2">ฐานข้อมูลขยะ</h3>
                <p class="text-gray-500">เรียนรู้ข้อมูลและวิธีจัดการขยะแต่ละประเภทอย่างถูกวิธี</p>
            </a>

            <a href="share1.php" class="feature-card bg-white p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow">
                <div class="text-yellow-500 mb-4 text-4xl"><i class="fas fa-share-alt"></i></div>
                <h3 class="text-xl font-semibold mb-2">แบ่งปันไอเดีย</h3>
                <p class="text-gray-500">ชมและแชร์ไอเดียการจัดการขยะรีไซเคิลจากเพื่อนๆ ทั่วประเทศ</p>
            </a>

            <div id="ai-chat-card" class="feature-card bg-white p-6 rounded-lg shadow-md hover:shadow-xl transition-shadow cursor-pointer">
                <div class="text-purple-500 mb-4 text-4xl"><i class="fas fa-robot"></i></div>
                <h3 class="text-xl font-semibold mb-2">แชทกับ AI</h3>
                <p class="text-gray-500">สอบถามข้อสงสัยเกี่ยวกับการจัดการขยะได้ตลอด 24 ชั่วโมง</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const userIsLoggedIn = <?php echo isset($_SESSION['userid']) ? 'true' : 'false'; ?>;

        function requireLogin(message = 'คุณต้องเข้าสู่ระบบก่อน จึงจะสามารถใช้งานส่วนนี้ได้') {
            Swal.fire({
                icon: 'info',
                title: 'กรุณาเข้าสู่ระบบ',
                text: message,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#3085d6'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'login.html';
                }
            });
        }

        // --- Logic for AI Chat Card ---
        const aiChatCard = document.getElementById('ai-chat-card');
        if (aiChatCard) {
            aiChatCard.addEventListener('click', function(event) {
                if (userIsLoggedIn) {
                    // ถ้าล็อกอินแล้ว ให้ไปที่หน้า AI Chat (สมมติว่าชื่อ chat_ai.php)
                    window.location.href = 'chat_ai.php'; 
                } else {
                    event.preventDefault();
                    requireLogin('คุณต้องเข้าสู่ระบบเพื่อพูดคุยกับ AI ผู้ช่วย');
                }
            });
        }
    });
    </script>
</body>
</html>