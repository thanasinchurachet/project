<?php
// เชื่อมต่อกับฐานข้อมูล
require_once 'db1.php';

// ตรวจสอบว่ามีการส่ง ID มาหรือไม่
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // ถ้าไม่มี ID ให้ redirect กลับไปหน้าหลัก
    header('Location: manage_comment.html');
    exit;
}

$userId = $_GET['id'];

// รับพารามิเตอร์ค้นหาและจัดเรียง
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // จำนวนโพสต์ต่อหน้า
$offset = ($page - 1) * $limit;

// ดึงข้อมูลผู้ใช้
$userSql = "SELECT * FROM users WHERE userID = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();

if ($userResult->num_rows == 0) {
    // ถ้าไม่พบผู้ใช้ให้ redirect กลับไปหน้าหลัก
    header('Location: manage_comment.html');
    exit;
}

$user = $userResult->fetch_assoc();

// สร้างเงื่อนไขสำหรับการค้นหาและกรอง
$whereConditions = ["p.userId = ?"];
$params = [];
$params[] = $userId;

if (!empty($search)) {
    $whereConditions[] = "(p.title LIKE ? OR p.details LIKE ?)";
    $searchParam = "%" . $search . "%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($status != 'all') {
    $whereConditions[] = "p.status = ?";
    $params[] = $status;
}

$whereClause = implode(" AND ", $whereConditions);



// สร้างเงื่อนไขสำหรับการจัดเรียง
$orderClause = "";
switch ($sort) {
    case 'newest':
        $orderClause = "p.date DESC";
        break;
    case 'oldest':
        $orderClause = "p.date ASC";
        break;
    case 'title_asc':
        $orderClause = "p.title ASC";
        break;
    case 'title_desc':
        $orderClause = "p.title DESC";
        break;
    case 'comments':
        $orderClause = "(SELECT COUNT(*) FROM comment WHERE postID = p.postId) DESC";
        break;
    default:
        $orderClause = "p.date DESC";
}

// ดึงจำนวนโพสต์ทั้งหมดตามเงื่อนไข
$countSql = "SELECT COUNT(*) as total FROM post p WHERE $whereClause";
$countStmt = $conn->prepare($countSql);

// สร้าง bind_param สำหรับคำสั่ง COUNT
if (count($params) > 0) {
    // สร้าง type string ให้ตรงกับจำนวน params
    $types = str_repeat('s', count($params));
    
    // ใช้ call_user_func_array กับ bind_param
    $bindParamsRef = array();
    $bindParamsRef[] = &$types;
    
    foreach($params as $key => $value) {
        $bindParamsRef[] = &$params[$key];
    }
    
    call_user_func_array(array($countStmt, 'bind_param'), $bindParamsRef);
}

$countStmt->execute();
$countResult = $countStmt->get_result();
$totalPosts = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalPosts / $limit);

// ดึงข้อมูลโพสต์ตามเงื่อนไข
$sql = "SELECT p.*, t.name as trash_name, t.Waste_type,
               (SELECT COUNT(*) FROM comment WHERE postID = p.postId) as comment_count
        FROM post p
        JOIN trash t ON p.trashId = t.trashId
        WHERE $whereClause
        ORDER BY $orderClause
        LIMIT ?, ?";

// เตรียมพารามิเตอร์ทั้งหมดสำหรับคำสั่ง SQL หลัก
$allParams = $params;
$allParams[] = $offset;
$allParams[] = $limit;

// สร้าง type string - ทุก param ก่อนหน้าเป็น 's' และสองตัวสุดท้ายเป็น 'i'
$allTypes = str_repeat('s', count($params)) . 'ii';

// Prepare statement
$stmt = $conn->prepare($sql);

// Bind parameters
if (count($allParams) > 0) {
    $bindParamsRef = array();
    $bindParamsRef[] = &$allTypes;
    
    foreach($allParams as $key => $value) {
        $bindParamsRef[] = &$allParams[$key];
    }
    
    call_user_func_array(array($stmt, 'bind_param'), $bindParamsRef);
}

$stmt->execute();
$result = $stmt->get_result();
$posts = [];

while ($row = $result->fetch_assoc()) {
    // แปลงข้อมูลภาพ (JSON string) เป็น Array
    $images = json_decode($row['image'], true);
    if (!is_array($images) || empty($images)) {
        $row['thumbnail'] = 'default-post.jpg';
    } else {
        $row['thumbnail'] = $images[0];
    }
    
    $posts[] = $row;
}

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

    return "$day $month $year";
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
    <title>โพสต์ทั้งหมดของ <?php echo htmlspecialchars($user['name']); ?> - ระบบจัดการขยะ</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Kanit', sans-serif;
        }
        .post-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .post-card {
            transition: all 0.2s ease;
        }
        .post-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .post-image {
            height: 160px;
            object-fit: cover;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
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
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">โพสต์ทั้งหมดของผู้ใช้</h1>
                    <div class="flex items-center">
                        <img src="../img/profile_uploads/<?php echo $user['profile_img'] ?: 'default-avatar.jpg'; ?>" 
                            class="w-8 h-8 rounded-full object-cover mr-2" 
                            alt="รูปโปรไฟล์">
                        <p class="text-gray-600"><?php echo htmlspecialchars($user['name']); ?></p>
                    </div>
                </div>
                <div class="flex mt-4 md:mt-0">
                    <a href="user_detail.php?id=<?php echo $userId; ?>" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 flex items-center">
                        <i class="fas fa-user mr-2"></i>ดูโปรไฟล์
                    </a>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="bg-white rounded-xl shadow-lg p-4 mb-6">
                <form id="filterForm" action="user_posts.php" method="GET" class="flex flex-col md:flex-row md:items-end space-y-4 md:space-y-0 md:space-x-4">
                    <input type="hidden" name="id" value="<?php echo $userId; ?>">
                    
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">ค้นหา</label>
                        <input type="text" id="searchInput" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ค้นหาหัวข้อหรือเนื้อหา..." 
                               class="w-full rounded-lg border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">สถานะ</label>
                        <select id="statusFilter" name="status" class="rounded-lg border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>รออนุมัติ</option>
                            <option value="approved" <?php echo $status == 'approved' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                            <option value="suspended" <?php echo $status == 'suspended' ? 'selected' : ''; ?>>ระงับการแสดงผล</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">เรียงตาม</label>
                        <select id="sortFilter" name="sort" class="rounded-lg border-gray-300 shadow-sm focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>ล่าสุด</option>
                            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>เก่าสุด</option>
                            <option value="title_asc" <?php echo $sort == 'title_asc' ? 'selected' : ''; ?>>หัวข้อ (ก-ฮ)</option>
                            <option value="title_desc" <?php echo $sort == 'title_desc' ? 'selected' : ''; ?>>หัวข้อ (ฮ-ก)</option>
                            <option value="comments" <?php echo $sort == 'comments' ? 'selected' : ''; ?>>จำนวนความคิดเห็น</option>
                        </select>
                    </div>
                    
                    <!-- ซ่อนปุ่มค้นหา และเพิ่ม progress spinner -->
                    <div id="searchSpinner" class="hidden">
                        <div class="h-10 w-10 flex items-center justify-center">
                            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-green-500"></div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Posts Grid -->
            <div class="mb-6">
                <?php if (empty($posts)): ?>
                <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                    <i class="fas fa-clipboard text-gray-400 text-5xl mb-3"></i>
                    <h3 class="text-xl font-medium text-gray-700 mb-2">ไม่พบโพสต์</h3>
                    <p class="text-gray-500">
                        <?php if (!empty($search)): ?>
                        ไม่พบโพสต์ที่ตรงกับคำค้นหา "<?php echo htmlspecialchars($search); ?>"
                        <?php else: ?>
                        ผู้ใช้นี้ยังไม่มีโพสต์
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <!-- Stats -->
                <div class="bg-white rounded-xl shadow-lg p-4 mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="p-3 bg-gray-50 rounded-lg text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo $totalPosts; ?></div>
                            <div class="text-gray-600">โพสต์ทั้งหมด</div>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-lg text-center">
                            <div class="text-2xl font-bold text-blue-600">
                                <?php 
                                $approved = 0;
                                foreach ($posts as $post) {
                                    if ($post['status'] == 'approved') $approved++;
                                }
                                echo $approved; 
                                ?>
                            </div>
                            <div class="text-gray-600">อนุมัติแล้ว</div>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-lg text-center">
                            <div class="text-2xl font-bold text-yellow-600">
                                <?php 
                                $pending = 0;
                                foreach ($posts as $post) {
                                    if ($post['status'] == 'pending') $pending++;
                                }
                                echo $pending; 
                                ?>
                            </div>
                            <div class="text-gray-600">รออนุมัติ</div>
                        </div>
                    </div>
                </div>
                
                <div class="post-grid">
                    <?php foreach ($posts as $post): ?>
                    <a href="admin_post_detail.php?id=<?php echo $post['postId']; ?>" class="post-card bg-white rounded-xl shadow-lg overflow-hidden block">
                        <div class="relative">
                            <img src="../img/post_img/<?php echo $post['thumbnail']; ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" class="post-image w-full">
                            <div class="absolute top-2 right-2">
                                <span class="status-badge <?php 
                                    if ($post['status'] == 'pending') echo 'status-pending';
                                    elseif ($post['status'] == 'approved') echo 'status-approved';
                                    else echo 'status-suspended'; 
                                ?>">
                                    <?php echo statusText($post['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="p-4">
                            <h3 class="font-semibold text-gray-800 line-clamp-1 mb-1"><?php echo htmlspecialchars($post['title']); ?></h3>
                            <p class="text-sm text-gray-500 mb-2 line-clamp-2"><?php echo htmlspecialchars($post['details']); ?></p>
                            
                            <div class="flex justify-between items-center text-xs text-gray-600">
                                <div><?php echo thaiDate($post['date']); ?></div>
                                <div class="flex items-center space-x-2">
                                    <span class="flex items-center">
                                        <i class="fas fa-comment text-gray-400 mr-1"></i> <?php echo $post['comment_count']; ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-trash text-gray-400 mr-1"></i> <?php echo htmlspecialchars($post['trash_name']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <div class="flex justify-between">
                                    <span class="inline-flex items-center text-xs px-2 py-1 rounded bg-gray-100 text-gray-800">
                                        <i class="fas fa-recycle mr-1"></i> <?php echo managementTypeText($post['Management_type']); ?>
                                    </span>
                                    <span class="inline-flex items-center text-xs px-2 py-1 rounded bg-gray-100 text-gray-800">
                                        <i class="fas fa-layer-group mr-1"></i> <?php echo htmlspecialchars($post['Waste_type']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="mt-6 flex justify-center">
                    <nav class="flex space-x-1">
                        <?php if ($page > 1): ?>
                        <a href="?id=<?php echo $userId; ?>&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>" 
                           class="px-4 py-2 rounded-md bg-gray-200 text-gray-600 hover:bg-gray-300">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?id=<?php echo $userId; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>" 
                           class="px-4 py-2 rounded-md <?php echo $i == $page ? 'bg-green-600 text-white' : 'bg-gray-200 text-gray-600 hover:bg-gray-300'; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                        <a href="?id=<?php echo $userId; ?>&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=<?php echo $sort; ?>" 
                           class="px-4 py-2 rounded-md bg-gray-200 text-gray-600 hover:bg-gray-300">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Back Button -->
            <div class="mt-6 flex justify-center">
                <a href="manage_comment.html" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i>กลับไปหน้าหลัก
                </a>
            </div>
        </div>
    </div>

    <script>
        // สร้างฟังก์ชั่นดีเบาวน์สำหรับการค้นหา
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

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
        
        // ฟังก์ชั่นสำหรับส่งฟอร์ม
        function submitFilterForm() {
            document.getElementById('searchSpinner').classList.remove('hidden');
            document.getElementById('filterForm').submit();
        }
        
        // Initialize page on DOM content loaded
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                // Load navbar
                await loadNavbar();
                
                // เพิ่ม event listeners สำหรับการกรองและค้นหาแบบทันที
                
                // สำหรับช่องค้นหา - ใช้ debounce เพื่อไม่ให้ส่งคำขอมากเกินไป
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.addEventListener('input', debounce(() => {
                        submitFilterForm();
                    }, 500)); // รอ 500ms หลังจากผู้ใช้หยุดพิมพ์
                }
                
                // สำหรับ dropdown filters - ทำงานทันทีที่มีการเปลี่ยนค่า
                const statusFilter = document.getElementById('statusFilter');
                if (statusFilter) {
                    statusFilter.addEventListener('change', () => {
                        submitFilterForm();
                    });
                }
                
                const sortFilter = document.getElementById('sortFilter');
                if (sortFilter) {
                    sortFilter.addEventListener('change', () => {
                        submitFilterForm();
                    });
                }
                
            } catch (error) {
                console.error('Error initializing page:', error);
            }
        });
    </script>
</body>
</html>