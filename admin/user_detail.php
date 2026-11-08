<?php
// เชื่อมต่อกับฐานข้อมูล
require_once 'db1.php';

// ตรวจสอบว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // ถ้าไม่มี ID ให้ redirect กลับไปหน้าหลัก
    header('Location: admin_dashboard.html');
    exit;
}

$userId = $_GET['id'];

// ดึงข้อมูลผู้ใช้
$sql = "SELECT u.*, 
               (SELECT COUNT(*) FROM post WHERE userId = u.userID) as post_count,
               (SELECT COUNT(*) FROM comment WHERE userID = u.userID) as comment_count
        FROM users u
        WHERE u.userID = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // ถ้าไม่พบผู้ใช้ให้ redirect กลับไปหน้าหลัก
    header('Location: admin_dashboard.html');
    exit;
}

$user = $result->fetch_assoc();

// ดึงข้อมูลโพสต์ล่าสุดของผู้ใช้
$postSql = "SELECT p.*, t.name as trash_name, t.Waste_type, 
                  (SELECT COUNT(*) FROM comment WHERE postID = p.postId) as comment_count
           FROM post p
           JOIN trash t ON p.trashId = t.trashId
           WHERE p.userId = ?
           ORDER BY p.date DESC
           LIMIT 5";

$postStmt = $conn->prepare($postSql);
$postStmt->bind_param("i", $userId);
$postStmt->execute();
$postResult = $postStmt->get_result();
$posts = [];

while ($row = $postResult->fetch_assoc()) {
    // แปลงข้อมูลภาพ (JSON string) เป็น Array
    $images = json_decode($row['image'], true);
    if (!is_array($images) || empty($images)) {
        $row['thumbnail'] = 'default-post.jpg';
    } else {
        $row['thumbnail'] = $images[0];
    }
    
    $posts[] = $row;
}

// ดึงข้อมูลคอมเมนต์ล่าสุดของผู้ใช้
$commentSql = "SELECT c.*, p.title as post_title
              FROM comment c
              JOIN post p ON c.postID = p.postId
              WHERE c.userID = ?
              ORDER BY c.date_created DESC
              LIMIT 5";

$commentStmt = $conn->prepare($commentSql);
$commentStmt->bind_param("i", $userId);
$commentStmt->execute();
$commentResult = $commentStmt->get_result();
$comments = [];

while ($row = $commentResult->fetch_assoc()) {
    $comments[] = $row;
}

// ดึงข้อมูลการระงับบัญชี (ถ้ามี)
$suspensionSql = "SELECT * FROM account_suspension 
                 WHERE userID = ? AND status = 'active' AND (end_date > NOW() OR end_date IS NULL) 
                 ORDER BY start_date DESC LIMIT 1";

$suspensionStmt = $conn->prepare($suspensionSql);
$suspensionStmt->bind_param("i", $userId);
$suspensionStmt->execute();
$suspensionResult = $suspensionStmt->get_result();
$suspension = $suspensionResult->fetch_assoc();

// ฟังก์ชันแปลงวันที่เป็นรูปแบบไทย
function thaiDate($datetime) {
    if (empty($datetime)) return "-";
    $thai_months = [
        "", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน",
        "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"
    ];

    $date = new DateTime($datetime);
    $day = $date->format('j');
    $month = $thai_months[(int)$date->format('n')];
    $year = $date->format('Y') + 543;
    $time = $date->format('H:i น.');

    return "$day $month $year เวลา $time";
}

// ฟังก์ชันแปลงสถานะเป็นข้อความภาษาไทย
function statusText($status) {
    switch ($status) {
        case 'pending': return 'รออนุมัติ';
        case 'approved': return 'อนุมัติแล้ว';
        case 'suspended': return 'ระงับการแสดงผล';
        default: return $status;
    }
}

// ฟังก์ชันแปลงสถานะผู้ใช้เป็นข้อความภาษาไทย
function userStatusText($status) {
    switch ($status) {
        case 'active': return 'ใช้งานปกติ';
        case 'suspended': return 'ถูกระงับการใช้งาน';
        default: return $status;
    }
}

// ฟังก์ชันแปลงประเภทการจัดการขยะเป็นข้อความภาษาไทย
function managementTypeText($type) {
    switch ($type) {
        case 'reuse': return 'การนำกลับมาใช้ใหม่';
        case 'recycle': return 'การรีไซเคิล';
        case 'dispose': return 'การกำจัด';
        default: return $type;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลผู้ใช้ <?php echo htmlspecialchars($user['name']); ?> - ระบบจัดการขยะ</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Kanit', sans-serif;
        }
        .user-status {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-active {
            background-color: #D1FAE5;
            color: #065F46;
        }
        .status-suspended {
            background-color: #FEE2E2;
            color: #B91C1C;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation containers -->
    <div id="navbar-container"></div>

    <!-- Main Content -->
    <div class="pt-16 md:ml-64">
        <div class="p-6">
            <!-- Header -->
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">ข้อมูลผู้ใช้</h1>
                    <p class="text-gray-600">ดูรายละเอียดข้อมูลของ <?php echo htmlspecialchars($user['name']); ?></p>
                </div>
                <div class="mt-4 lg:mt-0">
                    <a href="user_posts.php?id=<?php echo $userId; ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center">
                        <i class="fas fa-list-alt mr-2"></i>ดูโพสต์ทั้งหมด
                    </a>
                </div>
            </div>

            <!-- User Profile Card -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-6">
                <div class="md:flex">
                    <div class="md:flex-shrink-0 p-6 flex items-center justify-center bg-gray-50 md:w-64">
                        <img src="../img/profile_uploads/<?php echo $user['profile_img'] ?: 'default-avatar.jpg'; ?>" 
                             class="w-40 h-40 rounded-full object-cover border-4 border-white shadow" 
                             alt="รูปโปรไฟล์">
                    </div>
                    <div class="p-6 md:flex-1">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($user['name']); ?></h2>
                                <p class="text-gray-600"><?php echo htmlspecialchars($user['email']); ?></p>
                            </div>
                            <span class="user-status <?php echo ($user['status'] == 'active') ? 'status-active' : 'status-suspended'; ?>">
                                <i class="fas <?php echo ($user['status'] == 'active') ? 'fa-check-circle' : 'fa-ban'; ?> mr-2"></i>
                                <?php echo userStatusText($user['status']); ?>
                            </span>
                        </div>
                        
                        <?php if ($suspension): ?>
                        <div class="mt-4 bg-red-50 border border-red-200 rounded-lg p-4">
                            <h3 class="font-semibold text-red-800">บัญชีถูกระงับการใช้งาน</h3>
                            <p class="text-red-700 text-sm mt-1">
                                เริ่ม: <?php echo thaiDate($suspension['start_date']); ?>
                                <?php if ($suspension['end_date']): ?>
                                <br>สิ้นสุด: <?php echo thaiDate($suspension['end_date']); ?>
                                <?php else: ?>
                                <br>สิ้นสุด: ไม่กำหนด
                                <?php endif; ?>
                            </p>
                            <p class="text-red-700 text-sm mt-2">
                                <strong>เหตุผล:</strong> <?php echo htmlspecialchars($suspension['reason']); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                            <div class="bg-gray-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-green-600"><?php echo $user['post_count']; ?></div>
                                <div class="text-gray-600">โพสต์</div>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-blue-600"><?php echo $user['comment_count']; ?></div>
                                <div class="text-gray-600">ความคิดเห็น</div>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg text-center">
                                <div class="text-2xl font-bold text-purple-600">
                                    <?php 
                                    // คำนวณจำนวนวันที่เป็นสมาชิก
                                    $now = new DateTime();
                                    $registrationDate = isset($user['registration_date']) ? new DateTime($user['registration_date']) : $now;
                                    $diff = $now->diff($registrationDate);
                                    echo $diff->days;
                                    ?>
                                </div>
                                <div class="text-gray-600">วันที่เป็นสมาชิก</div>
                            </div>
                        </div>
                        
                        <?php if ($user['lat'] && $user['lng']): ?>
                        <div class="mt-6">
                            <h3 class="text-lg font-semibold mb-2">ตำแหน่งที่ตั้ง</h3>
                            <div class="bg-gray-50 rounded-lg h-48" id="map"></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Posts and Comments -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Posts -->
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="font-semibold text-gray-800">โพสต์ล่าสุด</h3>
                            <a href="user_posts.php?id=<?php echo $userId; ?>" class="text-green-600 hover:text-green-800 text-sm flex items-center">
                                <i class="fas fa-external-link-alt mr-1"></i> ดูทั้งหมด
                            </a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php if (empty($posts)): ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-clipboard fa-2x mb-2"></i>
                            <p>ยังไม่มีโพสต์</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                            <div class="p-4 hover:bg-gray-50 transition-colors">
                                <a href="admin_post_detail.php?id=<?php echo $post['postId']; ?>" class="flex items-start space-x-3">
                                    <div class="flex-shrink-0 w-16 h-16">
                                        <img src="../img/post_img/<?php echo $post['thumbnail']; ?>" 
                                             class="w-full h-full object-cover rounded" 
                                             alt="รูปโพสต์">
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 line-clamp-1"><?php echo htmlspecialchars($post['title']); ?></h4>
                                        <div class="text-xs text-gray-500 mt-1"><?php echo thaiDate($post['date']); ?></div>
                                        <div class="flex items-center space-x-2 mt-1">
                                            <span class="inline-flex items-center text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-800">
                                                <i class="fas fa-recycle mr-1"></i> <?php echo managementTypeText($post['Management_type']); ?>
                                            </span>
                                            <span class="inline-flex items-center text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-800">
                                                <i class="fas fa-comments mr-1"></i> <?php echo $post['comment_count']; ?>
                                            </span>
                                            <span class="inline-flex items-center text-xs px-2 py-0.5 rounded 
                                                <?php 
                                                if ($post['status'] == 'pending') echo 'bg-yellow-100 text-yellow-800';
                                                elseif ($post['status'] == 'approved') echo 'bg-green-100 text-green-800';
                                                else echo 'bg-red-100 text-red-800';
                                                ?>">
                                                <?php echo statusText($post['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Comments -->
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200">
                        <h3 class="font-semibold text-gray-800">ความคิดเห็นล่าสุด</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php if (empty($comments)): ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-comments fa-2x mb-2"></i>
                            <p>ยังไม่มีความคิดเห็น</p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                            <div class="p-4 hover:bg-gray-50 transition-colors">
                                <div>
                                    <div class="flex justify-between">
                                        <a href="admin_post_detail.php?id=<?php echo $comment['postID']; ?>" class="font-medium text-gray-800 hover:text-green-600">
                                            <?php echo htmlspecialchars($comment['post_title']); ?>
                                        </a>
                                        <div class="text-xs text-gray-500"><?php echo thaiDate($comment['date_created']); ?></div>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-1 line-clamp-2"><?php echo htmlspecialchars($comment['content']); ?></p>
                                    
                                    <?php if ($comment['status'] == 'hidden'): ?>
                                    <div class="mt-1 text-xs text-red-600 flex items-center">
                                        <i class="fas fa-eye-slash mr-1"></i> คอมเมนต์นี้ถูกซ่อน
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Back Button -->
            <div class="mt-6 flex justify-center">
                <a href="manage_comment.html" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i>กลับไปหน้าจัดการคอมเมนต์
                </a>
            </div>
        </div>
    </div>

    <?php if ($user['lat'] && $user['lng']): ?>
    <!-- Google Maps Script -->
    <script>
        function initMap() {
            const userLocation = { lat: <?php echo $user['lat']; ?>, lng: <?php echo $user['lng']; ?> };
            const map = new google.maps.Map(document.getElementById("map"), {
                zoom: 15,
                center: userLocation,
            });
            
            const marker = new google.maps.Marker({
                position: userLocation,
                map: map,
                title: "<?php echo htmlspecialchars($user['name']); ?>"
            });
        }
    </script>
    <script async defer
        src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY&callback=initMap">
    </script>
    <?php endif; ?>

    <script>
        // Async function to load navbar
        async function loadNavbar() {
            try {
                const response = await fetch('navbar.html');
                const html = await response.text();
                document.getElementById('navbar-container').innerHTML = html;
                
                // Run scripts in the navbar
                const scripts = document.getElementById('navbar-container').getElementsByTagName('script');
                for(let script of scripts) {
                    eval(script.innerHTML);
                }
            } catch (error) {
                console.error('Error loading navbar:', error);
            }
        }
        
        // Initialize page on DOM content loaded
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                // Load navbar
                await loadNavbar();
            } catch (error) {
                console.error('Error initializing page:', error);
            }
        });
    </script>
</body>
</html>