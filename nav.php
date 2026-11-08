<?php
date_default_timezone_set('Asia/Bangkok');
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['userid'])) {
    header('Location: login.html');
    exit();
}

$default_avatar = 'img/profile_uploads/default.jpg';

try {
    // ดึงข้อมูลผู้ใช้จากฐานข้อมูล
    $stmt = $pdo->prepare("SELECT * FROM users WHERE userID = ?");
    $stmt->execute([$_SESSION['userid']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: login.html');
        exit();
    }
    
    // ถ้าไม่มีรูปโปรไฟล์ ใช้รูปเริ่มต้น
    $avatar_path = !empty($user['profile_img']) ? 'img/profile_uploads/' . $user['profile_img'] : $default_avatar;

    // ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT n.id) as unread 
        FROM notifications n
        LEFT JOIN post p ON p.title = SUBSTRING_INDEX(
            SUBSTRING_INDEX(n.message, '\"', 2),
            '\"', -1
        )
        WHERE n.userID = ? 
        AND n.read_at IS NULL
        AND (
            CASE 
                WHEN p.Suspended_date IS NOT NULL THEN p.Suspended_date >= NOW() - INTERVAL 24 HOUR
                ELSE n.created_at >= NOW() - INTERVAL 24 HOUR
            END
        )
    ");
    $stmt->execute([$_SESSION['userid']]);
    $unreadCount = $stmt->fetch()['unread'];

    // ดึงการแจ้งเตือนพร้อมเวลาที่อนุมัติ/ปฏิเสธจากตาราง post โดยใช้ DISTINCT เพื่อป้องกันซ้ำ
    $stmt = $pdo->prepare("
        SELECT DISTINCT n.*, p.Suspended_date, p.title as post_title, p.postID AS post_id
        FROM notifications n
        LEFT JOIN post p ON p.title = SUBSTRING_INDEX(
            SUBSTRING_INDEX(n.message, '\"', 2),
            '\"', -1
        )
        WHERE n.userID = ? 
        AND (
            CASE 
                WHEN p.Suspended_date IS NOT NULL THEN p.Suspended_date >= NOW() - INTERVAL 24 HOUR
                ELSE n.created_at >= NOW() - INTERVAL 24 HOUR
            END
            OR n.is_read = 0
        )
        ORDER BY COALESCE(p.Suspended_date, n.created_at) DESC
    ");
    $stmt->execute([$_SESSION['userid']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicate notifications by post title (keep the most recent one)
    // This prevents showing both "approved" and "rejected" entries for the same post.
    $dedup = [];
    $seenKeys = [];
    foreach ($notifications as $n) {
        // Prefer stable post_id from JOIN; fallback to title or quoted name
        $key = $n['post_id'] ?? null;
        if (!$key) $key = $n['post_title'] ?? null;
        if (!$key || trim($key) === '') {
            if (preg_match('/\"(.*?)\"/', $n['message'] ?? '', $m)) {
                $key = $m[1];
            } else {
                $key = 'msg-' . $n['id']; // fallback unique to avoid hiding unrelated rows
            }
        }
        $lk = strtolower(trim((string)$key));
        if (!isset($seenKeys[$lk])) {
            $seenKeys[$lk] = true;
            $dedup[] = $n; // list is already ordered DESC, so first is most recent
        }
    }
    $notifications = $dedup;

    // Recompute unread count based on deduplicated list
$unreadCount = 0;
foreach ($notifications as $n) {
    $isRead = ((isset($n['is_read']) && (int)$n['is_read'] === 1) || (!empty($n['read_at'])));
    if (!$isRead) $unreadCount++;
}

// ระบุสิทธิ์ผู้ดูแลระบบ (พยายามตรวจจับจากหลายฟิลด์; ถ้าไม่มีให้ false)
$isAdmin = false;
try {
    $roleFields = [
        strtolower((string)($user['role']         ?? '')),
        strtolower((string)($user['user_role']    ?? '')),
        strtolower((string)($user['user_type']    ?? '')),
        strtolower((string)($user['type']         ?? '')),
    ];
    if (in_array('admin', $roleFields, true) || in_array('administrator', $roleFields, true)) {
        $isAdmin = true;
    }
    if (!$isAdmin && isset($user['is_admin']) && (int)$user['is_admin'] === 1) {
        $isAdmin = true;
    }
    if (!$isAdmin && isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
        $isAdmin = true;
    }
    if (!$isAdmin && isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin','administrator'], true)) {
        $isAdmin = true;
    }
} catch (Throwable $__) {}

} catch (PDOException $e) {
    error_log("Database error in nav.php: " . $e->getMessage());
    session_destroy();
    header('Location: login.html?error=system');
    exit();
}

// ตรวจสอบ URL ปัจจุบันเพื่อกำหนดเมนูที่ active
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar bg-green-500 shadow-lg fixed top-0 left-0 w-full z-50">
    <div class="container-fluid px-4">
        <div class="w-full flex justify-between items-center">
            <div class="flex items-center">
                <a href="main.php" class="flex items-center py-4 px-3 text-white hover:bg-green-600 rounded-lg transition-all duration-300">
                    <i class="fas fa-recycle mr-2 text-xl"></i>
                    <span class="font-bold text-xl">Bin Buddy</span>
                </a>
            </div>

            <div class="flex items-center space-x-4">
                <div class="hidden lg:flex items-center space-x-4">
                    <div class="flex items-center space-x-1 bg-green-600/30 rounded-lg px-2">
                        <a href="main.php" class="py-3 px-3 text-white <?php echo $current_page === 'main.php' ? 'bg-green-600' : 'hover:bg-green-600'; ?> rounded-lg text-sm lg:text-base transition-all duration-300">
                            <i class="fas fa-home mr-2"></i><span class="hidden sm:inline">หน้าหลัก</span>
                        </a>
                        <a href="share1.php" class="py-3 px-3 text-white <?php echo $current_page === 'share1.php' ? 'bg-green-600' : 'hover:bg-green-600'; ?> rounded-lg text-sm lg:text-base transition-all duration-300">
                            <i class="fas fa-share-alt mr-2"></i><span class="hidden sm:inline">แบ่งปัน</span>
                        </a>
                        <a href="waste-details.php" class="py-3 px-3 text-white <?php echo $current_page === 'waste-details.php' ? 'bg-green-600' : 'hover:bg-green-600'; ?> rounded-lg text-sm lg:text-base transition-all duration-300">
                            <i class="fas fa-trash mr-2"></i><span class="hidden sm:inline">ข้อมูลขยะ</span>
                        </a>
                        <a href="scan.php" class="py-3 px-3 text-white <?php echo $current_page === 'scan.php' ? 'bg-green-600' : 'hover:bg-green-600'; ?> rounded-lg text-sm lg:text-base transition-all duration-300">
                            <i class="fas fa-camera mr-2"></i><span class="hidden sm:inline">สแกน</span>
                        </a>
                        <a href="locations.php" class="py-3 px-3 text-white <?php echo $current_page === 'locations.php' ? 'bg-green-600' : 'hover:bg-green-600'; ?> rounded-lg text-sm lg:text-base transition-all duration-300">
                            <i class="fas fa-map-marker-alt mr-2"></i><span class="hidden sm:inline">ศูนย์จัดการ</span>
                        </a>
                        <?php if ($isAdmin): ?>
                        <a href="admin/train_model.php" class="py-3 px-3 text-white <?php echo $current_page === 'train_model.php' ? 'bg-green-600' : 'hover:bg-green-600'; ?> rounded-lg text-sm lg:text-base transition-all duration-300">
                            <i class="fas fa-brain mr-2"></i><span class="hidden sm:inline">เทรนโมเดล</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center space-x-3 bg-green-600/30 rounded-lg px-2 py-1">
                    <!-- Notification Bell -->
                    <div class="relative">
                        <button onclick="toggleNotifications()" class="p-2 text-white hover:bg-green-600 transition-all duration-300" aria-label="Notifications" style="border-radius: 0.25rem;">
                            <i class="fas fa-bell"></i>
                            <span id="nav-notification-count" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center <?php echo ((int)$unreadCount > 0) ? '' : 'hidden'; ?>">
                                <?php echo (int)$unreadCount; ?>
                            </span>
                        </button>

                        <!-- Notification Dropdown -->
                        <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg z-50 max-h-96 overflow-y-auto border-none">
                            <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                                <h3 class="text-lg font-semibold">การแจ้งเตือน</h3>
                                <button id="markAllReadBtn" type="button" onclick="markAllAsRead()" class="text-green-600 hover:text-green-700 text-sm font-medium">อ่านทั้งหมด</button>
                            </div>
                            <div class="max-h-80 overflow-y-auto">
                                <?php if (empty($notifications)): ?>
                                    <div class="p-4 text-center text-gray-500">
                                        ไม่มีการแจ้งเตือน
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifications as $notification):
                                        if ($notification['Suspended_date']) {
                                            $notifyTime = new DateTime($notification['Suspended_date']);
                                        } else {
                                            $notifyTime = new DateTime($notification['created_at']);
                                        }

                                        $now = new DateTime();
                                        $interval = $now->diff($notifyTime);

                                        if ($interval->d > 0) {
                                            $timeAgo = $interval->d . " วันที่แล้ว";
                                        } elseif ($interval->h > 0) {
                                            $timeAgo = $interval->h . " ชั่วโมงที่แล้ว";
                                        } elseif ($interval->i > 0) {
                                            $timeAgo = $interval->i . " นาทีที่แล้ว";
                                        } else {
                                            $timeAgo = "เมื่อสักครู่";
                                        }

                                        $isApproved = strpos($notification['message'], 'ได้รับการอนุมัติ') !== false;
                                        $isRejected = strpos($notification['message'], 'ถูกปฏิเสธ') !== false;

                                        $bgColor = $isApproved ? 'bg-green-50' : ($isRejected ? 'bg-red-50' : 'bg-white');
                                        $borderColor = $isApproved ? 'border-green-200' : ($isRejected ? 'border-red-200' : 'border-gray-200');
                                        $iconColor = $isApproved ? 'text-green-500' : ($isRejected ? 'text-red-500' : 'text-gray-500');

                                        // ประเภทการแจ้งเตือน + payload สำหรับการคลิก
                                        $postIdForNotify = isset($notification['post_id']) && $notification['post_id'] !== null
                                            ? (int)$notification['post_id']
                                            : null;
                                        if ($postIdForNotify) {
                                            $notifyType = 'post';
                                        } else if (
                                            (strpos($notification['message'], 'คอมเมนต์') !== false) ||
                                            (strpos($notification['message'], 'รายงาน') !== false)
                                        ) {
                                            $notifyType = 'comment_report';
                                        } else if (
                                            (strpos($notification['message'], 'ขยะ') !== false) ||
                                            (strpos($notification['message'], 'ขยะชิ้นใหม่') !== false)
                                        ) {
                                            $notifyType = 'waste';
                                        } else {
                                            $notifyType = 'modal';
                                        }
                                        $payloadJson = htmlspecialchars(json_encode(['postId' => $postIdForNotify]), ENT_QUOTES, 'UTF-8');
                                        $messageJson = htmlspecialchars(json_encode($notification['message']), ENT_QUOTES, 'UTF-8');
                                    ?>
                                        <div class="p-4 border-b border-gray-200 hover:bg-opacity-80 cursor-pointer <?php $read = ((int)($notification['is_read'] ?? 0) === 1) || !empty($notification['read_at']); echo $read ? '' : 'bg-blue-50'; ?> <?php echo $bgColor; ?> border-l-4 <?php echo $borderColor; ?>"
                                            onclick="handleNotificationClick(<?php echo (int)$notification['id']; ?>, '<?php echo $notifyType; ?>', <?php echo $payloadJson; ?>, <?php echo $messageJson; ?>)">
                                            <div class="flex items-start">
                                                <div class="flex-shrink-0 mr-3">
                                                    <?php if ($isApproved): ?>
                                                        <i class="fas fa-check-circle <?php echo $iconColor; ?> text-xl"></i>
                                                    <?php elseif ($isRejected): ?>
                                                        <i class="fas fa-times-circle <?php echo $iconColor; ?> text-xl"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-bell <?php echo $iconColor; ?> text-xl"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-sm mb-1 <?php echo $isApproved ? 'text-green-800' : ($isRejected ? 'text-red-800' : 'text-gray-800'); ?>">
                                                        <?php echo htmlspecialchars($notification['message']); ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500">
                                                        <?php echo htmlspecialchars($notifyTime->format('d/m/Y H:i')); ?>
                                                        (<?php echo $timeAgo; ?>)
                                                    </p>
                                                </div>
                                                <?php if ($notification['read_at'] === null): ?>
                                                    <div class="flex-shrink-0 ml-2">
                                                        <span class="w-2 h-2 bg-blue-500 rounded-full block"></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <button onclick="toggleProfile()" class="p-2 text-white hover:bg-green-600 rounded-lg transition-all duration-300 flex items-center space-x-1">
                            <img id="nav-avatar" src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Profile" class="w-8 h-8 md:w-10 md:h-10 rounded-full border-2 border-white" style="object-fit: contain;">
                            <span class="hidden md:inline text-sm"><?php echo htmlspecialchars($user['name']); ?></span>
                            <i class="fas fa-chevron-down text-xs"></i>
                        </button>
                        
                        <div id="profileDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg z-50 max-h-64 overflow-y-auto">
                           <a href="my_post.php" class="flex items-center p-3 text-gray-700 <?php echo $current_page === 'my_post.php' ? 'bg-gray-100 font-semibold' : 'hover:bg-gray-50'; ?> border-b border-gray-100">
                                <i class="fas fa-user-circle mr-3 text-green-600 w-5 text-center"></i>
                                <span>โปรไฟล์</span>
                            </a>
                            <a href="liked_posts.php" class="flex items-center p-3 text-gray-700 <?php echo $current_page === 'liked_posts.php' ? 'bg-gray-100 font-semibold' : 'hover:bg-green-50'; ?> group transition-all duration-300">
                                <i class="fas fa-heart mr-3 text-green-500 group-hover:text-green-600 w-5 text-center"></i>
                                <span>โพสต์ที่ถูกใจ</span>
                            </a>
                            <a href="#" data-bs-toggle="modal" data-bs-target="#wasteManagementModal"
                            class="flex items-center p-3 text-gray-700 hover:bg-green-50 group transition-all duration-300">
                                <i class="fas fa-plus mr-3 text-green-500 group-hover:text-green-600 w-5 text-center"></i>
                                <span>เพิ่มโพสต์</span>
                            </a>
                            <a href="logout.php" class="flex items-center p-3 text-red-600 hover:bg-gray-50 transition-all duration-300">
                                <i class="fas fa-sign-out-alt mr-3 w-5 text-center"></i>
                                <span>ออกจากระบบ</span>
                            </a>
                        </div>
                    </div>

                    <button class="navbar-toggler text-white hover:bg-green-600 p-2 rounded-lg transition-all duration-300 lg:hidden" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasMenu" aria-controls="offcanvasMenu">
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Offcanvas Menu -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasMenu" aria-labelledby="offcanvasMenuLabel">
    <div class="offcanvas-body p-4" style="margin-top: 120px;">
        <div class="space-y-2">
            <a href="my_post.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'my_post.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-user-circle w-8 text-center text-lg"></i>
                <span>โปรไฟล์ของฉัน</span>
            </a>
            <a href="liked_posts.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'liked_posts.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-heart w-8 text-center text-lg"></i>
                <span>โพสต์ที่ถูกใจ</span>
            </a>
            <a href="main.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'main.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-home w-8 text-center text-lg"></i>
                <span>หน้าหลัก</span>
            </a>
            <a href="share1.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'share1.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-share-alt w-8 text-center text-lg"></i>
                <span>แบ่งปัน</span>
            </a>
            <a href="waste-details.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'waste-details.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-trash-alt w-8 text-center text-lg"></i>
                <span>ข้อมูลขยะ</span>
            </a>
            <a href="scan.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'scan.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-camera w-8 text-center text-lg"></i>
                <span>สแกน</span>
            </a>
            <a href="locations.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'locations.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-map-marker-alt w-8 text-center text-lg"></i>
                <span>ศูนย์จัดการ</span>
            </a>
            <?php if ($isAdmin): ?>
            <a href="admin/train_model.php" class="flex items-center p-3 text-gray-700 rounded-lg transition-all duration-200 <?php echo $current_page === 'train_model.php' ? 'active bg-gray-100 font-semibold' : 'hover:bg-gray-100 hover:text-green-600'; ?>">
                <i class="fas fa-brain w-8 text-center text-lg"></i>
                <span>เทรนโมเดล</span>
            </a>
            <?php endif; ?>

            <hr class="my-3 border-gray-200">

            <a href="logout.php" class="flex items-center p-3 text-red-600 hover:bg-red-50 rounded-lg transition-all duration-200">
                <i class="fas fa-sign-out-alt w-8 text-center text-lg"></i>
                <span>ออกจากระบบ</span>
            </a>
        </div>
    </div>
</div>

<style>
    /* รีเซ็ต margin และ padding ของ body และ html */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    html, body {
        margin: 0;
        padding: 0;
    }

    /* แก้ไขขีดเส้นใต้ของลิงก์ทั้งหมด */
    a {
        text-decoration: none !important;
    }

    /* ตั้งค่า navbar ให้ติดด้านบนและอยู่คงที่ */
    .navbar {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        height: 110px !important;
        min-height: 110px !important;
        z-index: 9998 !important;
        margin: 0 !important;
        padding: 0 !important;
        background-color: #10b981 !important; /* bg-green-500 */
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
    }

    /* ตั้งค่า container ภายใน navbar */
    .navbar .container-fluid {
        height: 100% !important;
        display: flex !important;
        align-items: center !important;
        padding: 0 1rem !important;
        margin: 0 !important;
    }

    /* เพิ่ม padding-top ให้ body เพื่อไม่ให้เนื้อหาถูกบัง */
    body {
        padding-top: 110px !important;
        margin: 0 !important;
    }

    /* Dropdown styles */
    #notificationDropdown, #profileDropdown {
        position: absolute;
        right: 0;
        margin-top: 0.5rem;
        background: white;
        border-radius: 0.5rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        z-index: 9998;
        border: 1px solid #e5e7eb;
    }

    #notificationDropdown {
        width: 320px;
        max-height: 400px;
    }

    #profileDropdown {
        width: 192px;
        max-height: 300px;
        min-width: 180px;
    }

    /* จัดตำแหน่งข้อความในเมนู profile dropdown */
    #profileDropdown a {
        display: flex !important;
        align-items: center !important;
        text-align: left !important;
        width: 100% !important;
    }

    #profileDropdown a i {
        flex-shrink: 0 !important;
        width: 20px !important;
        text-align: center !important;
    }

    #profileDropdown a span {
        flex: 1 !important;
        text-align: left !important;
    }

    /* สไตล์สำหรับเมนูที่ active */
    a.bg-green-600 {
        color: white !important;
        font-weight: 600;
    }

    .offcanvas-body a.active {
        background-color: #f3f4f6 !important;
        color: #065f46 !important;
        font-weight: 600;
    }

    /* แก้ไขปัญหา offcanvas backdrop */
    .offcanvas-backdrop {
        opacity: 0.5 !important;
        transition: opacity 0.15s linear !important;
        cursor: pointer !important;
        z-index: 1040 !important;
        background-color: rgba(0, 0, 0, 0.5) !important;
    }
    
    .offcanvas-backdrop.fade {
        opacity: 0 !important;
    }
    
    .offcanvas-backdrop.show {
        opacity: 0.5 !important;
    }
    
    .offcanvas {
        z-index: 1045 !important;
    }

    /* บังคับรีเซ็ต backdrop เมื่อไม่มี offcanvas เปิดอยู่ */
    body:not(.offcanvas-open) .offcanvas-backdrop {
        display: none !important;
        opacity: 0 !important;
    }

    /* แก้ไขปัญหา opacity ติดใน body */
    body {
        opacity: 1 !important;
    }

    /* Responsive styles */
    @media (max-width: 979px) {
        .navbar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            height: 95px !important;
            min-height: 95px !important;
            z-index: 1050 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        body {
            padding-top: 95px !important;
            margin: 0 !important;
        }
        
        .navbar .hidden.lg\:flex {
            display: none !important;
        }
        
        
        #profileDropdown {
            width: 80%;
        }
        #notificationDropdown, #profileDropdown {
        position: fixed;
        left: 73%;
        transform: translateX(-50%);
        width: 90%;
        max-width: 163px;
        top: 105px !important;
    }
    }
</style>

<script>
    // ฟังก์ชันสำหรับ toggle notification dropdown
    function toggleNotifications() {
        const dropdown = document.getElementById('notificationDropdown');
        const profileDropdown = document.getElementById('profileDropdown');
        
        // ปิด profile dropdown ถ้าเปิดอยู่
        if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
            profileDropdown.classList.add('hidden');
        }
        
        // Toggle notification dropdown
        if (dropdown) {
            dropdown.classList.toggle('hidden');
            
            if (!dropdown.classList.contains('hidden')) {
                document.addEventListener('click', closeNotificationsOnClickOutside);
            } else {
                document.removeEventListener('click', closeNotificationsOnClickOutside);
            }
        }
    }

    function closeNotificationsOnClickOutside(event) {
        const dropdown = document.getElementById('notificationDropdown');
        const bellButton = event.target.closest('button[onclick="toggleNotifications()"]');

        if (dropdown && !dropdown.contains(event.target) && !bellButton) {
            dropdown.classList.add('hidden');
            document.removeEventListener('click', closeNotificationsOnClickOutside);
        }
    }

    // ฟังก์ชันสำหรับ toggle profile dropdown
    function toggleProfile() {
        const dropdown = document.getElementById('profileDropdown');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        // ปิด notification dropdown ถ้าเปิดอยู่
        if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
            notificationDropdown.classList.add('hidden');
        }
        
        // Toggle profile dropdown
        if (dropdown) {
            dropdown.classList.toggle('hidden');
            
            if (!dropdown.classList.contains('hidden')) {
                document.addEventListener('click', closeProfileOnClickOutside);
            } else {
                document.removeEventListener('click', closeProfileOnClickOutside);
            }
        }
    }

    function closeProfileOnClickOutside(event) {
        const dropdown = document.getElementById('profileDropdown');
        const profileButton = event.target.closest('button[onclick="toggleProfile()"]');

        if (dropdown && !dropdown.contains(event.target) && !profileButton) {
            dropdown.classList.add('hidden');
            document.removeEventListener('click', closeProfileOnClickOutside);
        }
    }

    // ฟังก์ชันสำหรับทำเครื่องหมายว่าอ่านแล้ว
    async function markAsRead(notificationId) {
        try {
            const response = await fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    notificationId: notificationId
                })
            });

            if (response.ok) {
                // รีโหลดหน้าเพื่ออัพเดทสถานะ
                window.location.reload();
            } else {
                console.error('Failed to mark notification as read');
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

    // ทำเครื่องหมายว่าอ่านแล้ว (ไม่รีโหลดหน้า)
    async function markAsReadSilently(notificationId) {
        try {
            const response = await fetch('mark_notification_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notificationId })
            });
            if (response.ok) {
                // อัปเดต badge ที่ nav แบบทันที (optimistic)
                const badge = document.getElementById('nav-notification-count');
                if (badge) {
                    const current = parseInt(badge.textContent, 10) || 0;
                    const next = Math.max(0, current - 1);
                    badge.textContent = String(next);
                    if (next <= 0) {
                        badge.classList.add('hidden');
                    } else {
                        badge.classList.remove('hidden');
                    }
                }
                // แจ้ง event และซิงก์กับเซิร์ฟเวอร์อีกรอบ
                window.dispatchEvent(new Event('notifications:changed'));
                if (typeof refreshNavNotifications === 'function') {
                    refreshNavNotifications();
                }
            } else {
                console.warn('Failed to mark notification as read');
            }
        } catch (e) {
            console.warn('Error marking notification as read:', e);
        }
    }

    // ทำเครื่องหมายว่าอ่านทั้งหมด
    async function markAllAsRead() {
        const btn = document.getElementById('markAllReadBtn');
        try {
            if (btn) {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
            }
            const res = await fetch('mark_all_notifications_read.php', { method: 'POST' });
            if (!res.ok) {
                console.warn('Failed to mark all notifications as read');
                return;
            }
            const data = await res.json().catch(() => ({}));
            // ปรับ UI ทันที
            const badge = document.getElementById('nav-notification-count');
            if (badge) {
                badge.textContent = '0';
                badge.classList.add('hidden');
            }
            document.querySelectorAll('#notificationDropdown .bg-blue-50').forEach(el => el.classList.remove('bg-blue-50'));
            // แจ้ง event และซิงก์กับ server เพื่อความชัวร์
            window.dispatchEvent(new Event('notifications:changed'));
            if (typeof refreshNavNotifications === 'function') {
                refreshNavNotifications();
            }
        } catch (err) {
            console.warn('Error marking all as read', err);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }
    }

    // รีเฟรชจำนวนแจ้งเตือนที่มุมขวาบน
    async function refreshNavNotifications() {
        try {
            const res = await fetch('check_new_notifications.php', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            const count = Number(data.unread_count ?? data.count ?? 0);
            const badge = document.getElementById('nav-notification-count');
            if (badge) {
                badge.textContent = String(count);
                if (count > 0) {
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }
        } catch (err) {
            console.warn('Refresh notifications failed', err);
        }
    }

    // อัปเดตรูปโปรไฟล์บน nav (กันแคชด้วย timestamp)
    function updateNavAvatar(url) {
        try {
            if (!url) return;
            const el = document.getElementById('nav-avatar');
            if (!el) return;
            const sep = url.includes('?') ? '&' : '?';
            el.src = url + sep + 't=' + Date.now();
        } catch (_) {}
    }

    // เผยเป็น global ให้หน้าอื่นเรียกได้
    window.refreshNavNotifications = refreshNavNotifications;
    window.updateNavAvatar = updateNavAvatar;

    // auto sync ช่วงๆ และรองรับ custom events
    refreshNavNotifications();
    setInterval(refreshNavNotifications, 15000);
    window.addEventListener('notifications:changed', refreshNavNotifications);
    window.addEventListener('profile:avatarChanged', function (e) { updateNavAvatar(e?.detail?.url); });

    // แสดงโมดอลการแจ้งเตือนแบบง่าย (ใช้ Bootstrap ถ้ามี)
    function showNotificationModal(message, title = 'การแจ้งเตือน', imageUrl = null) {
        try {
            const titleEl = document.getElementById('notificationModalTitle');
            const bodyEl = document.getElementById('notificationModalBody');
            if (titleEl && bodyEl) {
                titleEl.textContent = title;
                if (imageUrl) {
                    bodyEl.innerHTML = `
                        <div class="mb-2 text-gray-700">${message ? String(message) : ''}</div>
                        <img src="${imageUrl}" alt="waste image" class="img-fluid rounded" style="max-height:260px;object-fit:contain;justify-self: center;" onerror="this.style.display='none'">
                    `;
                } else {
                    bodyEl.textContent = message;
                }
                const modalEl = document.getElementById('notificationModal');
                if (modalEl && window.bootstrap && bootstrap.Modal) {
                    const m = bootstrap.Modal.getOrCreateInstance(modalEl);
                    m.show();
                    return;
                }
            }
        } catch (_) {}
        // fallback
        alert(message);
    }

    // ดึงรูปขยะล่าสุดจากฐานข้อมูลผ่าน endpoint ฝั่งแอดมิน
    async function getLatestWasteImageUrl() {
        try {
            const r = await fetch('admin/get_trash_content.php?limit=1&page=1', { headers: { 'Accept': 'application/json' } });
            if (!r.ok) return null;
            const data = await r.json();
            const item = data && data.data && Array.isArray(data.data.contents) && data.data.contents[0];
            if (item && item.image) {
                return 'img/trash_images/' + item.image;
            }
        } catch (e) {
            console.warn('Failed to fetch latest waste image', e);
        }
        return null;
    }

    // จัดการคลิกที่การแจ้งเตือนตามประเภท
    async function handleNotificationClick(notificationId, type, payload, message) {
        // ทำเป็นอ่านแล้วก่อน
        await markAsReadSilently(notificationId);

        try {
            if (type === 'post' && payload && payload.postId) {
                // ลิงก์ไปยังหน้าโพสต์ของฉัน พร้อมไฮไลต์โพสต์นั้น
                window.location.href = 'my_post.php?highlight_post_id=' + encodeURIComponent(payload.postId);
                return;
            }

            // ประเภทอื่นๆ แสดงเป็นโมดอล
            let modalTitle = 'การแจ้งเตือน';
            if (type === 'comment_report') modalTitle = 'ผลการรายงานคอมเมนต์';
            if (type === 'waste') {
                modalTitle = 'อัปเดตเนื้อหาขยะใหม่';
                const imgUrl = await getLatestWasteImageUrl();
                showNotificationModal(message, modalTitle, imgUrl);
                return;
            }
            showNotificationModal(message, modalTitle);
        } catch (err) {
            console.error('Error handling notification click:', err);
            showNotificationModal(message || 'ไม่พบรายละเอียด');
        }
    }

    // ปิด dropdown เมื่อกด ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const notificationDropdown = document.getElementById('notificationDropdown');
            const profileDropdown = document.getElementById('profileDropdown');
            
            if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                notificationDropdown.classList.add('hidden');
                document.removeEventListener('click', closeNotificationsOnClickOutside);
            }
            
            if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                profileDropdown.classList.add('hidden');
                document.removeEventListener('click', closeProfileOnClickOutside);
            }
        }
    });

    // ปรับ padding-top ให้ body อัตโนมัติ
    function adjustBodyPadding() {
        const navbar = document.querySelector('.navbar');
        if (navbar) {
            const navbarHeight = navbar.offsetHeight;
            document.body.style.paddingTop = navbarHeight + 'px';
        }
    }

    // ฟังก์ชันจัดการการคลิก backdrop
    function handleBackdropClick(event) {
        event.preventDefault();
        event.stopPropagation();
        const offcanvasMenu = document.getElementById('offcanvasMenu');
        const offcanvasInstance = bootstrap.Offcanvas.getInstance(offcanvasMenu);
        if (offcanvasInstance) {
            offcanvasInstance.hide();
        }
    }

    // เรียกใช้เมื่อหน้าเพจโหลดเสร็จ
    document.addEventListener('DOMContentLoaded', function() {
        adjustBodyPadding();
        
        // ปรับ padding เมื่อขนาดหน้าจอเปลี่ยน
        window.addEventListener('resize', adjustBodyPadding);
        
        const offcanvasMenu = document.getElementById('offcanvasMenu');
        if (offcanvasMenu) {
            // เพิ่มการตรวจสอบและทำความสะอาด backdrop ก่อนเปิด offcanvas
            offcanvasMenu.addEventListener('show.bs.offcanvas', function () {
                // ทำความสะอาด backdrop เก่าก่อน
                const oldBackdrops = document.querySelectorAll('.offcanvas-backdrop, .modal-backdrop');
                oldBackdrops.forEach(backdrop => {
                    backdrop.remove();
                });
                
                // รีเซ็ต body styles
                document.body.style.opacity = '1';
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                
                const profileDropdown = document.getElementById('profileDropdown');
                const notificationDropdown = document.getElementById('notificationDropdown');

                // สั่งซ่อนเมนูโปรไฟล์ที่อาจเปิดค้างไว้
                if (profileDropdown && !profileDropdown.classList.contains('hidden')) {
                    profileDropdown.classList.add('hidden');
                    document.removeEventListener('click', closeProfileOnClickOutside);
                }
                
                // สั่งซ่อนเมนูการแจ้งเตือนที่อาจเปิดค้างไว้
                if (notificationDropdown && !notificationDropdown.classList.contains('hidden')) {
                    notificationDropdown.classList.add('hidden');
                    document.removeEventListener('click', closeNotificationsOnClickOutside);
                }
            });

            // แก้ไขปัญหา backdrop - เพิ่ม event listener ที่แก้ไขแล้ว
            offcanvasMenu.addEventListener('shown.bs.offcanvas', function() {
                // รอให้ backdrop สร้างเสร็จก่อน
                setTimeout(() => {
                    const backdrop = document.querySelector('.offcanvas-backdrop');
                    if (backdrop) {
                        // ลบ event listener เดิมออกก่อน (ถ้ามี)
                        backdrop.removeEventListener('click', handleBackdropClick);
                        // เพิ่ม event listener ใหม่
                        backdrop.addEventListener('click', handleBackdropClick);
                        // บังคับให้ backdrop มี opacity ที่ถูกต้อง
                        backdrop.style.opacity = '0.5';
                        backdrop.classList.add('show');
                    }
                }, 50); // ลดเวลารอเหลือ 50ms
            });

            // ทำความสะอาดเมื่อเริ่มปิด offcanvas
            offcanvasMenu.addEventListener('hide.bs.offcanvas', function() {
                // ลบ event listener จาก backdrop ก่อน
                const backdrop = document.querySelector('.offcanvas-backdrop');
                if (backdrop) {
                    backdrop.removeEventListener('click', handleBackdropClick);
                }
            });

            // ทำความสะอาดเมื่อปิด offcanvas สมบูรณ์
            offcanvasMenu.addEventListener('hidden.bs.offcanvas', function() {
                // หน่วงเวลาเล็กน้อยเพื่อให้ Bootstrap ทำงานเสร็จก่อน
                setTimeout(() => {
                    // ลบ backdrop ที่อาจค้างอยู่ทั้งหมด
                    const backdrops = document.querySelectorAll('.offcanvas-backdrop, .modal-backdrop');
                    backdrops.forEach(backdrop => {
                        backdrop.remove();
                    });
                    
                    // ลบ class ที่อาจค้างอยู่ใน body
                    document.body.classList.remove('offcanvas-open', 'modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                    
                    // รีเซ็ต opacity ของ body (กรณีที่ติด)
                    document.body.style.opacity = '';
                }, 100);
            });

            // เพิ่มการจัดการ event สำหรับการปิดโดยการคลิกนอกพื้นที่
            document.addEventListener('click', function(event) {
                if (offcanvasMenu.classList.contains('show')) {
                    const backdrop = document.querySelector('.offcanvas-backdrop');
                    if (backdrop && event.target === backdrop) {
                        handleBackdropClick(event);
                    }
                }
            });
        }
    });
</script>

<!-- Notification Modal (generic) -->
<div class="modal fade" id="notificationModal" tabindex="-1" aria-labelledby="notificationModalTitle" aria-hidden="true" style="z-index: 10500;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="notificationModalTitle">การแจ้งเตือน</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="notificationModalBody">-</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
      </div>
    </div>
  </div>
</div>

<!-- Include the Add Post Modal -->
<?php include 'modal.php'; ?>
