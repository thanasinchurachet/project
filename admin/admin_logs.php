<?php
// เพิ่มการเชื่อมต่อฐานข้อมูล
$db_host = 'localhost';
$db_user = 'root'; 
$db_pass = ''; 
$db_name = 'waste_system';

// ตัวแปรสำหรับ pagination
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$records_per_page = isset($_GET['limit']) ? intval($_GET['limit']) : 15;
$offset = ($current_page - 1) * $records_per_page;

// ตัวแปรสำหรับค้นหาและกรอง
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$date_from = isset($_GET['from']) ? $_GET['from'] : '';
$date_to = isset($_GET['to']) ? $_GET['to'] : '';

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // สร้าง query พื้นฐาน
    $query = "SELECT * FROM admin_log";
    $count_query = "SELECT COUNT(*) FROM admin_log";
    
    // เพิ่มเงื่อนไขการค้นหาหรือกรอง
    $conditions = [];
    $params = [];
    
    if (!empty($action_filter)) {
        $conditions[] = "action = :action";
        $params[':action'] = $action_filter;
    }
    
    if (!empty($search_term)) {
        $conditions[] = "(reason LIKE :search OR targetID LIKE :search)";
        $params[':search'] = '%' . $search_term . '%';
    }
    
    if (!empty($date_from)) {
        $conditions[] = "date_action >= :date_from";
        $params[':date_from'] = $date_from . ' 00:00:00';
    }
    
    if (!empty($date_to)) {
        $conditions[] = "date_action <= :date_to";
        $params[':date_to'] = $date_to . ' 23:59:59';
    }
    
    // เพิ่มเงื่อนไขเข้าไปใน query
    if (count($conditions) > 0) {
        $query .= " WHERE " . implode(' AND ', $conditions);
        $count_query .= " WHERE " . implode(' AND ', $conditions);
    }
    
    // เพิ่ม ORDER BY และ LIMIT
    $query .= " ORDER BY date_action DESC LIMIT :offset, :limit";
    
    // เตรียม statement สำหรับดึงข้อมูลรายการ
    $stmt = $conn->prepare($query);
    
    // สำหรับ pagination
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $records_per_page, PDO::PARAM_INT);
    
    // ผูกพารามิเตอร์สำหรับเงื่อนไขอื่นๆ
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // นับจำนวนรายการทั้งหมด
    $count_stmt = $conn->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);
    
    // ดึงข้อมูลการกระทำที่มีในระบบเพื่อใช้ในตัวกรอง
    $action_stmt = $conn->query("SELECT DISTINCT action FROM admin_log");
    $available_actions = $action_stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการทำงานของแอดมิน - BinBuddy</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script>
    <style>
        body {
            font-family: 'Kanit', sans-serif;
        }
        
        .log-row {
            transition: all 0.2s ease;
        }
        
        .log-row:hover {
            transform: translateX(5px);
            background-color: #f0fdf4;
        }
        
        .badge {
            transition: all 0.3s ease;
        }
        
        .badge:hover {
            transform: scale(1.05);
        }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cccccc;
            border-radius: 10px;
        }
        
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #b3b3b3;
        }

        /* Tooltip styles */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
        
        /* Filter badge */
        .filter-badge {
            background-color: #EFF6FF;
            color: #3B82F6;
            padding: 0.25rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .filter-badge button {
            margin-left: 0.25rem;
            color: #3B82F6;
        }
        
        .filter-badge button:hover {
            color: #1E40AF;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation containers -->
    <div id="navbar-container"></div>

    <!-- Main Content -->
    <div class="pt-16 md:ml-64 transition-all duration-300">
        <div class="p-4 md:p-6 max-w-7xl mx-auto">
            <!-- Header Section -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">ประวัติการทำงานของแอดมิน</h1>
                        <p class="text-gray-600">แสดงประวัติการดำเนินการทั้งหมดในระบบ</p>
                    </div>
                    <a href="admin_dashboard.html" class="mt-4 md:mt-0 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>
                        กลับไปยังแดชบอร์ด
                    </a>
                </div>
            </div>

            <!-- Filter and Search Section -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                <div class="md:flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 mb-3 md:mb-0">ค้นหาและกรอง</h2>
                    
                    <!-- Active Filters -->
                    <div class="flex flex-wrap" id="activeFilters">
                        <?php if (!empty($action_filter)): ?>
                        <div class="filter-badge" id="actionFilterBadge">
                            <span>ประเภท: <?php echo htmlspecialchars($action_filter); ?></span>
                            <button onclick="clearFilter('action')"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($search_term)): ?>
                        <div class="filter-badge" id="searchFilterBadge">
                            <span>ค้นหา: <?php echo htmlspecialchars($search_term); ?></span>
                            <button onclick="clearFilter('search')"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($date_from) || !empty($date_to)): ?>
                        <div class="filter-badge" id="dateFilterBadge">
                            <span>วันที่: <?php echo htmlspecialchars($date_from) . ' - ' . htmlspecialchars($date_to); ?></span>
                            <button onclick="clearFilter('date')"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($action_filter) || !empty($search_term) || !empty($date_from) || !empty($date_to)): ?>
                        <div class="filter-badge bg-red-50 text-red-600">
                            <span>ล้างทั้งหมด</span>
                            <button onclick="clearAllFilters()"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <form id="filterForm" method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label for="action" class="block text-sm font-medium text-gray-700 mb-1">ประเภทการกระทำ</label>
                        <select id="action" name="action" class="w-full rounded-lg border-gray-300 focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($available_actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action === $action_filter ? 'selected' : ''; ?>>
                                    <?php 
                                    // แปลความหมายของการกระทำ
                                    switch($action) {
                                        case 'suspend_user':
                                            echo 'ระงับผู้ใช้';
                                            break;
                                        case 'delete_post':
                                            echo 'ปฎิเสธโพสต์';
                                            break;
                                        case 'delete_comment':
                                            echo 'ลบคอมเมนต์';
                                            break;
                                        case 'add_trash':
                                            echo 'เพิ่มขยะ';
                                            break;
                                        case 'edit_trash':
                                            echo 'แก้ไขขยะ';
                                            break;
                                        case 'delete_trash':
                                            echo 'ลบขยะ';
                                            break;
                                        case 'approve_post':
                                            echo 'อนุมัติโพสต์';
                                            break;
                                        default:
                                            echo htmlspecialchars($action);
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">ค้นหา</label>
                        <div class="relative">
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="ค้นหาเหตุผลหรือไอดี..." class="w-full rounded-lg border-gray-300 pl-10 focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label for="from" class="block text-sm font-medium text-gray-700 mb-1">ตั้งแต่วันที่</label>
                        <input type="text" id="from" name="from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full rounded-lg border-gray-300 focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50 datepicker" placeholder="เลือกวันที่เริ่มต้น">
                    </div>
                    
                    <div>
                        <label for="to" class="block text-sm font-medium text-gray-700 mb-1">ถึงวันที่</label>
                        <input type="text" id="to" name="to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full rounded-lg border-gray-300 focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50 datepicker" placeholder="เลือกวันที่สิ้นสุด">
                    </div>
                    
                    <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-filter mr-2"></i>
                            กรองข้อมูล
                        </button>
                    </div>
                </form>
            </div>

            <!-- Logs Table Section -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-gray-800">
                        รายการกิจกรรม
                        <?php if($total_records > 0): ?>
                        <span class="text-sm text-gray-500 font-normal">(พบ <?php echo $total_records; ?> รายการ)</span>
                        <?php endif; ?>
                    </h2>
                    <div class="flex items-center space-x-2">
                        <select id="limit" name="limit" class="rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring focus:ring-green-200 focus:ring-opacity-50" onchange="changeLimit(this.value)">
                            <option value="15" <?php echo $records_per_page == 15 ? 'selected' : ''; ?>>15 รายการ</option>
                            <option value="30" <?php echo $records_per_page == 30 ? 'selected' : ''; ?>>30 รายการ</option>
                            <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50 รายการ</option>
                            <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100 รายการ</option>
                        </select>
                        <button id="refreshButton" class="p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-colors">
                            <i class="fas fa-sync-alt text-gray-600"></i>
                        </button>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ลำดับ</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">วันที่และเวลา</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ประเภทการกระทำ</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เป้าหมาย</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($logs) > 0): ?>
                                <?php foreach ($logs as $index => $log): ?>
                                    <tr class="log-row">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $offset + $index + 1; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('d M Y', strtotime($log['date_action'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('H:i:s', strtotime($log['date_action'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                            // แสดงประเภทการกระทำด้วย badge
                                            $badge_class = '';
                                            $action_text = '';
                                            $icon = '';
                                            
                                            switch($log['action']) {
                                                case 'suspend_user':
                                                    $badge_class = 'bg-red-100 text-red-800';
                                                    $action_text = 'ระงับผู้ใช้';
                                                    $icon = 'fa-user-slash';
                                                    break;
                                                case 'delete_post':
                                                    $badge_class = 'bg-orange-100 text-red-800';
                                                    $action_text = 'ปฎิเสธโพสต์';
                                                    $icon = 'fa-hand-paper';
                                                    break;
                                                case 'delete_comment':
                                                    $badge_class = 'bg-red-100 text-red-800';
                                                    $action_text = 'ลบคอมเมนต์';
                                                    $icon = 'fa-comment-slash';
                                                    break;
                                                case 'add_trash':
                                                    $badge_class = 'bg-green-100 text-green-800';
                                                    $action_text = 'เพิ่มขยะ';
                                                    $icon = 'fa-plus';
                                                    break;
                                                case 'edit_trash':
                                                    $badge_class = 'bg-blue-100 text-blue-800';
                                                    $action_text = 'แก้ไขขยะ';
                                                    $icon = 'fa-edit';
                                                    break;
                                                case 'delete_trash':
                                                    $badge_class = 'bg-red-100 text-red-800';
                                                    $action_text = 'ลบขยะ';
                                                    $icon = 'fa-trash';
                                                    break;
                                                case 'approve_post':
                                                    $badge_class = 'bg-green-100 text-green-800';
                                                    $action_text = 'อนุมัติโพสต์';
                                                    $icon = 'fa-check';
                                                    break;
                                                default:
                                                    $badge_class = 'bg-gray-100 text-gray-800';
                                                    $action_text = $log['action'];
                                                    $icon = 'fa-cog';
                                            }
                                            ?>
                                            <span class="badge px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $badge_class; ?>">
                                                <i class="fas <?php echo $icon; ?> mr-1"></i>
                                                <?php echo $action_text; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            // แสดงประเภทเป้าหมาย
                                            $target_color = '';
                                            $target_icon = '';
                                            $target_link = '#';
                                            
                                            if ($log['target_type'] == 'user') {
                                                $target_color = 'text-purple-600';
                                                $target_icon = 'fa-user';
                                                $target_link = 'manage-users.html?id=' . $log['targetID'];
                                            } elseif ($log['target_type'] == 'post') {
                                                $target_color = 'text-blue-600';
                                                $target_icon = 'fa-file-alt';
                                                $target_link = 'manage_posts.html?id=' . $log['targetID'];
                                            } elseif ($log['target_type'] == 'comment') {
                                                $target_color = 'text-green-600';
                                                $target_icon = 'fa-comment';
                                                $target_link = 'manage_comment.html?id=' . $log['targetID'];
                                            } else {
                                                $target_color = 'text-gray-600';
                                                $target_icon = 'fa-cube';
                                                $target_link = 'manage_waste.html';
                                            }
                                            ?>
                                            
                                            <div class="flex items-center">
                                                <i class="fas <?php echo $target_icon; ?> <?php echo $target_color; ?> mr-1"></i>
                                                <div class="text-sm text-gray-900">
                                                    <?php if ($log['targetID'] > 0): ?>
                                                        <a href="<?php echo $target_link; ?>" class="hover:text-blue-600 hover:underline">
                                                            ID: <?php echo htmlspecialchars($log['targetID']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span>-</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900 tooltip">
                                                <?php 
                                                $reason = $log['reason'] ? htmlspecialchars($log['reason']) : '-';
                                                // ตัดข้อความให้แสดงแค่ 50 ตัวอักษร
                                                $short_reason = (strlen($reason) > 50) ? substr($reason, 0, 50) . '...' : $reason;
                                                echo $short_reason;
                                                
                                                // ถ้าข้อความยาวเกิน 50 ตัวอักษรให้แสดง tooltip
                                                if (strlen($reason) > 50):
                                                ?>
                                                <span class="tooltip-text"><?php echo $reason; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-gray-500">
                                        <i class="fas fa-search fa-2x mb-3"></i>
                                        <p>ไม่พบข้อมูลกิจกรรม</p>
                                        <?php if (!empty($action_filter) || !empty($search_term) || !empty($date_from) || !empty($date_to)): ?>
                                            <p class="mt-1 text-sm">ลองเปลี่ยนเงื่อนไขการค้นหา</p>
                                            <button onclick="clearAllFilters()" class="mt-3 px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 text-sm">
                                                ล้างตัวกรองทั้งหมด
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?php echo $current_page - 1; ?>&action=<?php echo urlencode($action_filter); ?>&search=<?php echo urlencode($search_term); ?>&from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&limit=<?php echo $records_per_page; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    ก่อนหน้า
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?php echo $current_page + 1; ?>&action=<?php echo urlencode($action_filter); ?>&search=<?php echo urlencode($search_term); ?>&from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&limit=<?php echo $records_per_page; ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    ถัดไป
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    แสดงรายการที่ <span class="font-medium"><?php echo $offset + 1; ?></span> ถึง <span class="font-medium"><?php echo min($offset + $records_per_page, $total_records); ?></span> จากทั้งหมด <span class="font-medium"><?php echo $total_records; ?></span> รายการ
                                </p>
                            </div>
                            
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <!-- ปุ่มก่อนหน้า -->
                                    <?php if ($current_page > 1): ?>
                                        <a href="?page=<?php echo $current_page - 1; ?>&action=<?php echo urlencode($action_filter); ?>&search=<?php echo urlencode($search_term); ?>&from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&limit=<?php echo $records_per_page; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">ก่อนหน้า</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                                            <span class="sr-only">ก่อนหน้า</span>
                                            <i class="fas fa-chevron-left"></i>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <!-- ปุ่มหน้า -->
                                    <?php
                                    // จำนวนปุ่มที่จะแสดงรอบๆ หน้าปัจจุบัน
                                    $radius = 2;
                                    $start_page = max(1, $current_page - $radius);
                                    $end_page = min($total_pages, $current_page + $radius);
                                    
                                    // ปรับค่าเริ่มต้นและสิ้นสุดในกรณีที่อยู่ใกล้ขอบ
                                    if ($end_page - $start_page < $radius * 2) {
                                        if ($start_page == 1) {
                                            $end_page = min($total_pages, $start_page + $radius * 2);
                                        } else if ($end_page == $total_pages) {
                                            $start_page = max(1, $end_page - $radius * 2);
                                        }
                                    }
                                    
                                    // แสดงปุ่มหน้าแรกและจุดไข่ปลาถ้าจำเป็น
                                    if ($start_page > 1) {
                                        echo '<a href="?page=1&action=' . urlencode($action_filter) . '&search=' . urlencode($search_term) . '&from=' . urlencode($date_from) . '&to=' . urlencode($date_to) . '&limit=' . $records_per_page . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>';
                                        
                                        if ($start_page > 2) {
                                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                                        }
                                    }
                                    
                                    // แสดงปุ่มหน้า
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        if ($i == $current_page) {
                                            echo '<span aria-current="page" class="relative inline-flex items-center px-4 py-2 border border-green-500 bg-green-50 text-sm font-medium text-green-600">' . $i . '</span>';
                                        } else {
                                            echo '<a href="?page=' . $i . '&action=' . urlencode($action_filter) . '&search=' . urlencode($search_term) . '&from=' . urlencode($date_from) . '&to=' . urlencode($date_to) . '&limit=' . $records_per_page . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $i . '</a>';
                                        }
                                    }
                                    
                                    // แสดงจุดไข่ปลาและปุ่มหน้าสุดท้ายถ้าจำเป็น
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                                        }
                                        
                                        echo '<a href="?page=' . $total_pages . '&action=' . urlencode($action_filter) . '&search=' . urlencode($search_term) . '&from=' . urlencode($date_from) . '&to=' . urlencode($date_to) . '&limit=' . $records_per_page . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $total_pages . '</a>';
                                    }
                                    ?>
                                    
                                    <!-- ปุ่มถัดไป -->
                                    <?php if ($current_page < $total_pages): ?>
                                        <a href="?page=<?php echo $current_page + 1; ?>&action=<?php echo urlencode($action_filter); ?>&search=<?php echo urlencode($search_term); ?>&from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&limit=<?php echo $records_per_page; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">ถัดไป</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                                            <span class="sr-only">ถัดไป</span>
                                            <i class="fas fa-chevron-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Summary Section -->
            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Action Types Summary -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">สรุปประเภทการกระทำ</h3>
                    <div class="space-y-4">
                        <?php
                        // ดึงข้อมูลสรุปประเภทการกระทำ
                        try {
                            $action_summary_query = "SELECT action, COUNT(*) as count FROM admin_log GROUP BY action ORDER BY count DESC";
                            $action_summary_stmt = $conn->query($action_summary_query);
                            $action_summary = $action_summary_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($action_summary as $summary):
                                // แสดงกราฟแท่งสำหรับแต่ละประเภท
                                $percentage = ($summary['count'] / $total_records) * 100;
                                
                                // กำหนดสีและไอคอนตามประเภท
                                $color = '';
                                $icon = '';
                                $action_text = '';
                                
                                switch($summary['action']) {
                                    case 'suspend_user':
                                        $color = 'bg-red-500';
                                        $icon = 'fa-user-slash';
                                        $action_text = 'ระงับผู้ใช้';
                                        break;
                                    case 'delete_post':
                                        $color = 'bg-orange-400';
                                        $icon = 'fa-hand-paper';
                                        $action_text = 'ปฎิเสธโพสต์';
                                        break;
                                    case 'delete_comment':
                                        $color = 'bg-red-300';
                                        $icon = 'fa-comment-slash';
                                        $action_text = 'ลบคอมเมนต์';
                                        break;
                                    case 'add_trash':
                                        $color = 'bg-green-500';
                                        $icon = 'fa-plus';
                                        $action_text = 'เพิ่มขยะ';
                                        break;
                                    case 'edit_trash':
                                        $color = 'bg-blue-500';
                                        $icon = 'fa-edit';
                                        $action_text = 'แก้ไขขยะ';
                                        break;
                                    case 'delete_trash':
                                        $color = 'bg-red-500';
                                        $icon = 'fa-trash';
                                        $action_text = 'ลบขยะ';
                                        break;
                                    case 'approve_post':
                                        $color = 'bg-green-500';
                                        $icon = 'fa-check';
                                        $action_text = 'อนุมัติโพสต์';
                                        break;
                                    default:
                                        $color = 'bg-gray-500';
                                        $icon = 'fa-cog';
                                        $action_text = $summary['action'];
                                }
                        ?>
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <div class="flex items-center">
                                    <i class="fas <?php echo $icon; ?> text-gray-600 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700"><?php echo $action_text; ?></span>
                                </div>
                                <span class="text-sm text-gray-600"><?php echo $summary['count']; ?> (<?php echo round($percentage, 1); ?>%)</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                <div class="<?php echo $color; ?> h-2.5 rounded-full" style="width: <?php echo min($percentage, 100); ?>%"></div>
                            </div>
                        </div>
                        <?php 
                            endforeach;
                        } catch (PDOException $e) {
                            echo '<p class="text-red-500">Error loading action summary: ' . $e->getMessage() . '</p>';
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Target Types Summary -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">สรุปเป้าหมายการกระทำ</h3>
                    <div class="space-y-4">
                        <?php
                        // ดึงข้อมูลสรุปเป้าหมายการกระทำ
                        try {
                            $target_summary_query = "SELECT target_type, COUNT(*) as count FROM admin_log WHERE target_type IS NOT NULL GROUP BY target_type ORDER BY count DESC";
                            $target_summary_stmt = $conn->query($target_summary_query);
                            $target_summary = $target_summary_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // นับจำนวนเป้าหมายที่เป็น NULL หรือว่างเปล่า
                            $null_target_query = "SELECT COUNT(*) as count FROM admin_log WHERE target_type IS NULL OR target_type = ''";
                            $null_target_stmt = $conn->query($null_target_query);
                            $null_target = $null_target_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // เพิ่มข้อมูล NULL เข้าไปใน array
                            if ($null_target['count'] > 0) {
                                $target_summary[] = [
                                    'target_type' => 'other',
                                    'count' => $null_target['count']
                                ];
                            }
                            
                            foreach ($target_summary as $summary):
                                // แสดงกราฟแท่งสำหรับแต่ละประเภท
                                $percentage = ($summary['count'] / $total_records) * 100;
                                
                                // กำหนดสีและไอคอนตามประเภท
                                $color = '';
                                $icon = '';
                                $target_text = '';
                                
                                switch($summary['target_type']) {
                                    case 'user':
                                        $color = 'bg-purple-500';
                                        $icon = 'fa-user';
                                        $target_text = 'ผู้ใช้';
                                        break;
                                    case 'post':
                                        $color = 'bg-blue-500';
                                        $icon = 'fa-file-alt';
                                        $target_text = 'โพสต์';
                                        break;
                                    case 'comment':
                                        $color = 'bg-green-500';
                                        $icon = 'fa-comment';
                                        $target_text = 'คอมเมนต์';
                                        break;
                                    default:
                                        $color = 'bg-gray-500';
                                        $icon = 'fa-cube';
                                        $target_text = 'อื่นๆ';
                                }
                        ?>
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <div class="flex items-center">
                                    <i class="fas <?php echo $icon; ?> text-gray-600 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700"><?php echo $target_text; ?></span>
                                </div>
                                <span class="text-sm text-gray-600"><?php echo $summary['count']; ?> (<?php echo round($percentage, 1); ?>%)</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                                <div class="<?php echo $color; ?> h-2.5 rounded-full" style="width: <?php echo min($percentage, 100); ?>%"></div>
                            </div>
                        </div>
                        <?php 
                            endforeach;
                        } catch (PDOException $e) {
                            echo '<p class="text-red-500">Error loading target summary: ' . $e->getMessage() . '</p>';
                        }
                        ?>
                    </div>
                </div>
                
                <!-- Time Trend Summary -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">กิจกรรมตามช่วงเวลา</h3>
                    <?php
                    // ดึงข้อมูลกิจกรรมตามช่วงเวลา (รายวัน)
                    try {
                        $time_trend_query = "
                            SELECT 
                                DATE(date_action) as action_date,
                                COUNT(*) as count
                            FROM admin_log
                            GROUP BY DATE(date_action)
                            ORDER BY action_date DESC
                            LIMIT 7
                        ";
                        $time_trend_stmt = $conn->query($time_trend_query);
                        $time_trend = $time_trend_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // กลับลำดับข้อมูลเพื่อให้เรียงจากเก่าไปใหม่
                        $time_trend = array_reverse($time_trend);
                        
                        // หาค่าสูงสุดเพื่อใช้คำนวณความสูงของกราฟ
                        $max_count = 0;
                        foreach ($time_trend as $day) {
                            if ($day['count'] > $max_count) {
                                $max_count = $day['count'];
                            }
                        }
                    ?>
                    <div class="flex items-end h-40 space-x-2">
                        <?php foreach ($time_trend as $day): 
                            // คำนวณความสูงของแท่งกราฟ
                            $height = ($day['count'] / $max_count) * 100;
                            // แปลงวันที่เป็นวันในสัปดาห์ภาษาไทย
                            $day_of_week = date('w', strtotime($day['action_date']));
                            $thai_days = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
                            $thai_day = $thai_days[$day_of_week];
                        ?>
                        <div class="flex-1 flex flex-col items-center">
                            <div class="tooltip w-full">
                                <div class="bg-green-500 hover:bg-green-600 rounded-t-md transition-all" style="height: <?php echo $height; ?>%"></div>
                                <span class="tooltip-text"><?php echo $day['action_date']; ?>: <?php echo $day['count']; ?> กิจกรรม</span>
                            </div>
                            <div class="text-xs text-gray-600 mt-1"><?php echo $thai_day; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4 text-xs text-gray-500 text-center">
                        แสดงข้อมูล 7 วันล่าสุด (<?php echo date('d/m/Y', strtotime($time_trend[0]['action_date'])); ?> - <?php echo date('d/m/Y', strtotime(end($time_trend)['action_date'])); ?>)
                    </div>
                    <?php
                    } catch (PDOException $e) {
                        echo '<p class="text-red-500">Error loading time trend: ' . $e->getMessage() . '</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load Navbar
       
    
    // จากนั้นจึงเริ่มต้นโหลดข้อมูลแดชบอร์ด
    fetchDashboardData();
    initActivityChart();
    
    // Set up refresh button
    document.getElementById('refreshButton').addEventListener('click', function() {
        this.classList.add('animate-spin');
        Promise.all([
            fetchDashboardData(),
            updateActivityChart(),
            fetchSystemStatus()
        ]).finally(() => {
            setTimeout(() => {
                this.classList.remove('animate-spin');
            }, 500);
        });
    });
    
    // Set up error alert close button
    document.getElementById('closeErrorBtn')?.addEventListener('click', function() {
        document.getElementById('errorAlert').classList.add('hidden');
    });
    
    // Auto-refresh dashboard data every 5 minutes
    setInterval(() => {
        fetchDashboardData();
        updateActivityChart();
    }, 300000);
});

        // Initialize date pickers
        function initDatePickers() {
            const dateOptions = {
                dateFormat: "Y-m-d",
                locale: "th",
                maxDate: "today",
                disableMobile: "true"
            };
            
            flatpickr("#from", dateOptions);
            flatpickr("#to", dateOptions);
        }

        // Clear filter functions
        function clearFilter(filterType) {
            let params = new URLSearchParams(window.location.search);
            
            if (filterType === 'action') {
                params.delete('action');
            } else if (filterType === 'search') {
                params.delete('search');
            } else if (filterType === 'date') {
                params.delete('from');
                params.delete('to');
            }
            
            // Reset to page 1 when clearing filters
            params.set('page', '1');
            
            window.location.href = window.location.pathname + '?' + params.toString();
        }

        function clearAllFilters() {
            let params = new URLSearchParams(window.location.search);
            
            // Keep only the limit parameter
            const limit = params.get('limit');
            params = new URLSearchParams();
            
            if (limit) {
                params.set('limit', limit);
            }
            
            params.set('page', '1');
            
            window.location.href = window.location.pathname + '?' + params.toString();
        }

        // Change number of records per page
        function changeLimit(limit) {
            let params = new URLSearchParams(window.location.search);
            params.set('limit', limit);
            params.set('page', '1'); // Reset to page 1 when changing limit
            window.location.href = window.location.pathname + '?' + params.toString();
        }

        // Refresh button functionality
        document.getElementById('refreshButton').addEventListener('click', function() {
            this.classList.add('animate-spin');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadNavbar();
            initDatePickers();
        });
    </script>
     <script src="navbar.js"></script>
</body>
</html>