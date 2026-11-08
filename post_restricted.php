<?php
session_start();

// ตรวจสอบว่ามีข้อมูลการระงับหรือไม่
if (!isset($_SESSION['restriction_info'])) {
    header('Location: main.php'); 
    exit;
}

// แปลงข้อมูล JSON เป็น array
$restriction = json_decode($_SESSION['restriction_info'], true);

// สร้างข้อความแจ้งเตือน
if ($restriction['is_permanent'] == 1) {
    $message = "คุณถูกระงับการโพสต์อย่างถาวร เนื่องจาก: {$restriction['reason']}";
} else {
    $endDate = date('d/m/Y H:i', strtotime($restriction['end_date']));
    $message = "คุณถูกระงับการโพสต์จนถึงวันที่ {$endDate} เนื่องจาก: {$restriction['reason']}";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>การโพสต์ถูกระงับ - Bin Buddy</title>
    
    <!-- CSS Libraries -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">

    <!-- JS Dependencies -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- เพิ่ม CSS สำหรับ navbar -->
    <link href="css/share.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Kanit', sans-serif;
        }
        
        /* แก้ไขปัญหา z-index สำหรับ sidebar */
        .sidebar {
            z-index: 1030;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include 'nav.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-lg mx-auto bg-white p-8 rounded-lg shadow-md">
            <div class="text-center mb-6">
                <i class="fas fa-exclamation-circle text-red-500 text-6xl mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">การโพสต์ถูกระงับ</h1>
                <p class="text-gray-600"><?php echo htmlspecialchars($message); ?></p>
            </div>
            
            <div class="border-t border-gray-200 pt-6">
                <p class="text-gray-600 mb-4">หากคุณเชื่อว่านี่เป็นข้อผิดพลาด กรุณาติดต่อผู้ดูแลระบบเพื่อขอความช่วยเหลือ</p>
                
                <div class="flex justify-center">
                    <a href="main.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg transition-colors">
                        <i class="fas fa-home mr-2"></i>กลับสู่หน้าหลัก
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- เพิ่ม JavaScript สำหรับทำงานกับ navbar -->
    <script>
        // เมื่อโหลดเสร็จแล้ว
        $(document).ready(function() {
            // ทำให้ sidebar สามารถเปิด-ปิดได้
            $('.sidebar-toggler').on('click', function() {
                $('.sidebar').toggleClass('open');
                $('.overlay').toggleClass('show');
            });
            
            // ปิด sidebar เมื่อคลิกที่ overlay
            $('.overlay').on('click', function() {
                $('.sidebar').removeClass('open');
                $('.overlay').removeClass('show');
            });
            
            // ทำให้เมนูย่อยใน sidebar สามารถเปิด-ปิดได้
            $('.has-submenu').on('click', function(e) {
                e.preventDefault();
                $(this).toggleClass('open');
                $(this).next('.submenu').slideToggle();
            });
        });
    </script>
</body></html>
<?php
// ล้างข้อมูลการระงับออกจาก session หลังจากแสดงผลแล้ว
unset($_SESSION['restriction_info']);
?>