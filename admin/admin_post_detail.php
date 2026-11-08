<?php
// เชื่อมต่อกับฐานข้อมูล
require_once 'db1.php';

// ตรวจสอบว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // ถ้าไม่มี ID ให้ redirect กลับไปหน้าหลัก
    header('Location: admin_dashboard.html');
    exit;
}

$postId = $_GET['id'];

// ดึงข้อมูลโพสต์
$sql = "SELECT p.*, t.name as trash_name, t.Waste_type, u.name as user_name, u.profile_img, u.email, 
               (SELECT COUNT(*) FROM comment WHERE postID = p.postId) as comment_count
        FROM post p
        JOIN trash t ON p.trashId = t.trashId
        JOIN users u ON p.userId = u.userID
        WHERE p.postId = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $postId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // ถ้าไม่พบโพสต์ให้ redirect กลับไปหน้าหลัก
    header('Location: admin_dashboard.html');
    exit;
}

$post = $result->fetch_assoc();

// แปลงข้อมูลภาพ (JSON string) เป็น Array
$images = json_decode($post['image'], true);
if (!is_array($images)) {
    $images = [];
}

// ดึงข้อมูลความคิดเห็น
$commentSql = "SELECT c.*, u.name, u.profile_img 
              FROM comment c
              JOIN users u ON c.userID = u.userID
              WHERE c.postID = ?
              ORDER BY c.date_created DESC";

$commentStmt = $conn->prepare($commentSql);
$commentStmt->bind_param("i", $postId);
$commentStmt->execute();
$commentResult = $commentStmt->get_result();
$comments = [];

while ($row = $commentResult->fetch_assoc()) {
    $comments[] = $row;
}

// ลบส่วนดึงข้อมูลการรายงานโพสต์ เนื่องจากผู้ใช้ไม่สามารถรายงานโพสต์ได้

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
    <title>รายละเอียดโพสต์ #<?php echo $postId; ?> - ระบบจัดการขยะ</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Kanit', sans-serif;
        }
        /* Video embed (16:9) */
        .video-embed{ position:relative; width:100%; background:#000; border-radius:12px; overflow:hidden; }
        .video-embed::before{ content:""; display:block; padding-top:56.25%; }
        .video-embed iframe, .video-embed video{ position:absolute; inset:0; width:100%; height:100%; border:0; }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            grid-gap: 10px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-pending {
            background-color: #FEF3C7;
            color: #92400E;
        }
        .status-approved {
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
            <!-- Header & Status -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">รายละเอียดโพสต์ #<?php echo $postId; ?></h1>
                    <p class="text-gray-600">เผยแพร่เมื่อ: <?php echo thaiDate($post['date']); ?></p>
                </div>
                <div class="mt-4 md:mt-0 flex items-center">
                    <span class="status-badge <?php 
                        if ($post['status'] == 'pending') echo 'status-pending';
                        elseif ($post['status'] == 'approved') echo 'status-approved';
                        else echo 'status-suspended'; 
                    ?>">
                        <i class="fas <?php 
                            if ($post['status'] == 'pending') echo 'fa-clock';
                            elseif ($post['status'] == 'approved') echo 'fa-check-circle';
                            else echo 'fa-ban'; 
                        ?> mr-2"></i>
                        <?php echo statusText($post['status']); ?>
                    </span>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- ข้อมูลโพสต์ -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="p-6">
                            <h2 class="text-xl font-bold text-gray-800 mb-4"><?php echo htmlspecialchars($post['title']); ?></h2>
                            
                            <div class="flex items-center space-x-4 mb-6">
                                <div class="bg-gray-100 px-3 py-1 rounded-full text-sm text-gray-700">
                                    <i class="fas fa-recycle mr-1"></i> <?php echo managementTypeText($post['Management_type']); ?>
                                </div>
                                <div class="bg-gray-100 px-3 py-1 rounded-full text-sm text-gray-700">
                                    <i class="fas fa-trash mr-1"></i> <?php echo htmlspecialchars($post['trash_name']); ?>
                                </div>
                                <div class="bg-gray-100 px-3 py-1 rounded-full text-sm text-gray-700">
                                    <i class="fas fa-layer-group mr-1"></i> <?php echo htmlspecialchars($post['Waste_type']); ?>
                                </div>
                            </div>
                            
                            <!-- รายละเอียด -->
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold text-gray-700 mb-2">รายละเอียด</h3>
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                    <p class="text-gray-700 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($post['details'])); ?></p>
                                </div>
                            </div>

                            <?php
                            $video_source   = $post['video_source'] ?? 'none';
                            $video_url      = $post['video_url'] ?? '';
                            $video_platform = strtolower($post['video_platform'] ?? '');
                            $video_path     = $post['video_path'] ?? '';
                            $hasVideo = ($video_source === 'url' && !empty($video_url)) || ($video_source === 'file' && !empty($video_path));
                            ?>

                            <?php if ($hasVideo): ?>
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold text-gray-700 mb-2">วิดีโอ</h3>
                                <div class="video-embed">
                                    <?php if ($video_source === 'file'): ?>
                                        <video controls preload="metadata" src="../uploads/videos/<?php echo htmlspecialchars($video_path); ?>">
                                            <source src="../uploads/videos/<?php echo htmlspecialchars($video_path); ?>" type="video/mp4">
                                            ไม่สามารถเล่นวิดีโอได้
                                        </video>
                                    <?php else: ?>
                                        <?php
                                        $embed = '';
                                        if ($video_platform === 'youtube') {
                                            if (preg_match('~(?:v=|youtu\\.be/|embed/)([A-Za-z0-9_-]{6,})~', $video_url, $m)) {
                                                $vid = $m[1];
                                                $embed = "https://www.youtube.com/embed/{$vid}";
                                            }
                                        } elseif ($video_platform === 'instagram') {
                                            if (preg_match('~instagram\\.com/(p|reel|tv)/([A-Za-z0-9_-]+)~', $video_url, $m)) {
                                                $embed = "https://www.instagram.com/{$m[1]}/{$m[2]}/embed";
                                            }
                                        } elseif ($video_platform === 'facebook') {
                                            $embed = 'https://www.facebook.com/plugins/video.php?href=' . urlencode($video_url) . '&show_text=0';
                                        } elseif ($video_platform === 'tiktok') {
                                            if (preg_match('~video/(\d+)~', $video_url, $m)) {
                                                $embed = "https://www.tiktok.com/embed/v2/video/{$m[1]}";
                                            }
                                        }
                                        ?>
                                        <?php if (!empty($embed)): ?>
                                            <iframe src="<?php echo $embed; ?>" allowfullscreen loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- รูปภาพ -->
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold text-gray-700 mb-2">รูปภาพ (<?php echo count($images); ?>)</h3>
                                <?php if (empty($images)): ?>
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-center text-gray-500">
                                    <i class="fas fa-image fa-2x mb-2"></i>
                                    <p>ไม่มีรูปภาพ</p>
                                </div>
                                <?php else: ?>
                                <div class="image-gallery">
                                    <?php foreach($images as $img): ?>
                                    <div class="relative">
                                        <a href="../img/post_img/<?php echo $img; ?>" target="_blank" class="block">
                                            <img src="../img/post_img/<?php echo $img; ?>" alt="รูปภาพโพสต์" class="w-full h-40 object-cover rounded-lg border border-gray-200">
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- คอมเมนต์ -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-700 mb-2">
                                    ความคิดเห็น (<?php echo count($comments); ?>)
                                </h3>
                                
                                <?php if (empty($comments)): ?>
                                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-center text-gray-500">
                                    <i class="fas fa-comments fa-2x mb-2"></i>
                                    <p>ยังไม่มีความคิดเห็น</p>
                                </div>
                                <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach($comments as $comment): ?>
                                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                                        <div class="flex items-start space-x-3">
                                            <img src="../img/profile_uploads/<?php echo $comment['profile_img'] ?: 'default-avatar.jpg'; ?>" 
                                                 class="w-10 h-10 rounded-full object-cover" 
                                                 alt="รูปโปรไฟล์">
                                            <div class="flex-1">
                                                <div class="flex justify-between items-center">
                                                    <div class="font-medium text-gray-800"><?php echo htmlspecialchars($comment['name']); ?></div>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo thaiDate($comment['date_created']); ?>
                                                    </div>
                                                </div>
                                                <p class="mt-1 text-gray-700"><?php echo htmlspecialchars($comment['content']); ?></p>
                                                
                                                <?php if ($comment['status'] == 'hidden'): ?>
                                                <div class="mt-2 text-xs text-red-600 flex items-center">
                                                    <i class="fas fa-eye-slash mr-1"></i> คอมเมนต์นี้ถูกซ่อน
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ข้อมูลเพิ่มเติม และข้อมูลผู้โพสต์ -->
                <div class="space-y-6">
                    <!-- ข้อมูลผู้โพสต์ -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-800">ข้อมูลผู้โพสต์</h3>
                        </div>
                        <div class="p-6">
                            <div class="flex items-center space-x-3 mb-4">
                                <img src="../img/profile_uploads/<?php echo $post['profile_img'] ?: 'default-avatar.jpg'; ?>" 
                                     class="w-12 h-12 rounded-full object-cover" 
                                     alt="รูปโปรไฟล์">
                                <div>
                                    <div class="font-medium text-gray-800"><?php echo htmlspecialchars($post['user_name']); ?></div>
                                    <div class="text-sm text-gray-600"><?php echo htmlspecialchars($post['email']); ?></div>
                                </div>
                            </div>
                            
                            <div class="flex justify-between text-sm">
                                <a href="user_detail.php?id=<?php echo $post['userId']; ?>" class="text-green-600 hover:text-green-800 flex items-center">
                                    <i class="fas fa-user mr-1"></i> ดูโปรไฟล์
                                </a>
                                <a href="user_posts.php?id=<?php echo $post['userId']; ?>" class="text-green-600 hover:text-green-800 flex items-center">
                                    <i class="fas fa-list-alt mr-1"></i> ดูโพสต์ทั้งหมด
                                </a>
                            </div>
                        </div>
                    </div>
                    

                    
                    <!-- ข้อมูลทางเทคนิค -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                            <h3 class="font-semibold text-gray-800">ข้อมูลทางเทคนิค</h3>
                        </div>
                        <div class="p-6 space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">โพสต์ ID:</span>
                                <span class="font-medium"><?php echo $postId; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">ประเภทขยะ:</span>
                                <span class="font-medium"><?php echo htmlspecialchars($post['Waste_type']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">ประเภทการจัดการ:</span>
                                <span class="font-medium"><?php echo managementTypeText($post['Management_type']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">วันที่สร้าง:</span>
                                <span class="font-medium"><?php echo thaiDate($post['date']); ?></span>
                            </div>
                            <?php if ($post['date_modified']): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">วันที่แก้ไขล่าสุด:</span>
                                <span class="font-medium"><?php echo thaiDate($post['date_modified']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">สถานะ:</span>
                                <span class="font-medium"><?php echo statusText($post['status']); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ปุ่มย้อนกลับ -->
                    <div class="flex justify-center">
                        <a href="manage_comment.html" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i>กลับไปหน้าจัดการคอมเมนต์
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
