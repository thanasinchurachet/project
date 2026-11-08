<?php
session_start();
require_once 'db.php';

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['userid'])) {
    header('Location: login.html');
    exit();
}

// ดึงข้อมูลผู้ใช้
$stmt = $pdo->prepare("SELECT * FROM users WHERE userID = ?");
$stmt->execute([$_SESSION['userid']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.html');
    exit();
}

// รับค่าการกรอง
$sort_order = isset($_GET['sort']) ? $_GET['sort'] : 'desc';
$waste_type = isset($_GET['waste_type']) ? $_GET['waste_type'] : '';

// สร้าง ORDER BY clause
$order_clause = $sort_order === 'asc' ? 'ASC' : 'DESC';

// สร้าง WHERE clause สำหรับประเภทขยะ
$waste_where = '';
$params = [$_SESSION['userid']];
if (!empty($waste_type)) {
    $waste_where = ' AND t.Waste_type = ?';
    $params[] = $waste_type;
}

// ดึงโพสต์ที่ถูกใจ
$stmt = $pdo->prepare("
    SELECT p.postID, p.userID, p.title, p.details, p.image, p.date, 
           p.trashId, p.Management_type, p.status,
           p.video_source, p.video_url, p.video_platform, p.video_path, p.video_mime, p.video_size,
           u.name AS username, u.profile_img AS avatar_path, 
           t.Waste_type, 
           COUNT(c.commentID) AS comment_count,
           (SELECT COUNT(*) FROM likes WHERE postId = p.postID) AS likes
    FROM post p
    JOIN likes l ON p.postID = l.postId
    JOIN users u ON p.userID = u.userID
    JOIN trash t ON p.trashId = t.trashId
    LEFT JOIN comment c ON p.postID = c.postId
    WHERE l.userId = ? AND p.status = 'approved' $waste_where
    GROUP BY p.postID, u.name, u.profile_img, t.Waste_type
    ORDER BY p.date $order_clause
");
$stmt->execute($params);
$likedPosts = $stmt->fetchAll();

// ดึงประเภทขยะทั้งหมดสำหรับ filter
$stmt = $pdo->prepare("
    SELECT DISTINCT t.Waste_type 
    FROM post p
    JOIN likes l ON p.postID = l.postId
    JOIN trash t ON p.trashId = t.trashId
    WHERE l.userId = ? AND p.status = 'approved'
    ORDER BY t.Waste_type
");
$stmt->execute([$_SESSION['userid']]);
$wasteTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ตรวจสอบและแปลงข้อมูล images เป็น array
foreach ($likedPosts as &$post) {
    $post['images'] = !empty($post['image']) && is_string($post['image']) ? 
        json_decode($post['image'], true) : [];
    $post['video_source'] = $post['video_source'] ?? 'none';
    $post['video_url'] = $post['video_url'] ?? null;
    $post['video_platform'] = $post['video_platform'] ?? null;
    $post['video_path'] = $post['video_path'] ?? null;
    
    // ตรวจสอบและกำหนดค่าเริ่มต้นสำหรับข้อมูลที่อาจไม่มี
    $post['title'] = $post['title'] ?? 'ไม่มีชื่อ';
    $post['details'] = $post['details'] ?? 'ไม่มีรายละเอียด';
    $post['Waste_type'] = $post['Waste_type'] ?? 'ไม่ระบุ';
    $post['Management_type'] = $post['Management_type'] ?? 'ไม่ระบุ';
    $post['likes'] = $post['likes'] ?? 0;
    $post['comment_count'] = $post['comment_count'] ?? 0;
}
unset($post);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โพสต์ที่ถูกใจ - Bin Buddy</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/share.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #22c55e;
            --primary-hover: #16a34a;
            --secondary-color: #f8fafc;
            --accent-color: #ef4444;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --border-color: #e5e7eb;
            --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 8px 25px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'Kanit', sans-serif;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            min-height: 100vh;
        }

        /* Filter Controls */
        .filter-bar {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .filter-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .filter-select {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: white;
            color: var(--text-primary);
            font-size: 0.875rem;
            transition: var(--transition);
            min-width: 150px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }

        .apply-filter-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
            align-self: end;
        }

        .apply-filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Grid Layout */
        .posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 2px;
            padding: 0;
        }

        @media (max-width: 640px) {
            .posts-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1px;
            }
        }

        @media (min-width: 641px) and (max-width: 768px) {
            .posts-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 769px) {
            .posts-grid {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        @media (min-width: 1200px) {
            .posts-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        /* Grid Item */
        .grid-item {
            position: relative;
            aspect-ratio: 1;
            cursor: pointer;
            overflow: hidden;
            background: #f3f4f6;
            transition: var(--transition);
            border-radius: 4px;
        }

        .grid-item:hover {
            transform: scale(1.02);
            z-index: 10;
            box-shadow: var(--shadow-lg);
        }

        .grid-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            transition: var(--transition);
        }

        .grid-item:hover img {
            transform: scale(1.1);
        }

        /* Overlay */
        .grid-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.3) 100%);
            opacity: 0;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 0.75rem;
        }

        .grid-item:hover .grid-overlay {
            opacity: 1;
        }

        .post-type-badge {
            background: rgba(34, 197, 94, 0.9);
            color: white;
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: 500;
            align-self: flex-start;
        }

        .post-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            font-size: 0.75rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-item i {
            font-size: 0.8rem;
        }

        /* Multiple images indicator */
        .multiple-indicator {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(0,0,0,0.7);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 2px dashed var(--border-color);
        }

        .empty-state i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1.5rem;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

       .btn-primary {
    /* background: linear-gradient(135deg, #6b7280, #000000); */
    color: #616161;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    transition: var(--transition);
    text-decoration: none;
    display: inline-block;
}

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: #3a3a3aff;;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            color: var(--accent-color);
        }

        .view-toggle {
            display: flex;
            gap: 0.5rem;
        }

        .view-btn {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-secondary);
        }

        .view-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .view-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .view-btn.active:hover {
            color: white;
        }

        /* Results counter */
        .results-counter {
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        /* Loading Animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .filter-select {
                min-width: unset;
                width: 100%;
            }
        }

        /* Smooth Scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Focus States */
        .grid-item:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-green-50 to-emerald-50">
    <?php include 'nav.php'; ?>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-heart"></i>
                โพสต์ที่ถูกใจ
            </h1>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-controls">
                <div class="filter-group">
                    <label class="filter-label">เรียงลำดับ</label>
                    <select name="sort" class="filter-select">
                        <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>ใหม่ไปเก่า</option>
                        <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>เก่าไปใหม่</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">ประเภทขยะ</label>
                    <select name="waste_type" class="filter-select">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($wasteTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                    <?php echo $waste_type === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

               
            </form>
        </div>

        <!-- Results Counter -->
        <?php if (!empty($likedPosts)): ?>
            <div class="results-counter">
                <i class="fas fa-heart text-red-500 mr-2"></i>
                พบโพสต์ที่ถูกใจ <?php echo count($likedPosts); ?> รายการ
                <?php if (!empty($waste_type)): ?>
                    <span class="ml-2">ประเภท: <?php echo htmlspecialchars($waste_type); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Posts Grid -->
        <div class="posts-grid">
            <?php if (empty($likedPosts)): ?>
                <div class="empty-state">
                    <i class="fas fa-heart"></i>
                    <h3>ยังไม่มีโพสต์ที่ถูกใจ</h3>
                    <p>เริ่มต้นการเดินทางด้วยการถูกใจโพสต์ที่น่าสนใจ</p>
                    <a href="share1.php" class="btn-primary">
                        <i class="fas fa-search mr-2"></i>
                        สำรวจโพสต์ทั้งหมด
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($likedPosts as $post): ?>
    <?php 
    // ตรวจสอบและแปลงค่า postID ให้ถูกต้อง
    $postId = isset($post['postID']) ? (int)$post['postID'] : 0;
    if ($postId === 0) {
        // ถ้าไม่พบ postID ให้ข้ามโพสต์นี้
        continue;
    }
    $imageCount = is_array($post['images']) ? count($post['images']) : 0;
    $hasVideo = ($post['video_source'] === 'url' && !empty($post['video_url'])) || ($post['video_source'] === 'file' && !empty($post['video_path']));
    $firstImage = $imageCount > 0 ? $post['images'][0] : null;
    ?>
    
    <div class="grid-item" 
         data-post-id="<?php echo $postId; ?>"
         onclick="window.openPostDetailModal(<?php echo $postId; ?>)"
         tabindex="0"
         role="button"
         aria-label="เปิดโพสต์: <?php echo htmlspecialchars($post['title']); ?>">
        
        <?php if ($hasVideo): ?>
            <div class="w-full h-full">
                <div
                    class="video-slot w-full h-full"
                    data-post-id="<?php echo $postId; ?>"
                    data-user-id="<?php echo htmlspecialchars($post['userID'] ?? ''); ?>"
                    data-video-source="<?php echo htmlspecialchars($post['video_source']); ?>"
                    data-video-url="<?php echo htmlspecialchars($post['video_url'] ?? ''); ?>"
                    data-video-platform="<?php echo htmlspecialchars($post['video_platform'] ?? ''); ?>"
                    data-video-path="<?php echo htmlspecialchars($post['video_path'] ?? ''); ?>"
                >
                    <div class="w-full h-full flex items-center justify-center bg-black/5 text-gray-600">
                        <i class="fas fa-play-circle text-5xl mr-2"></i>
                        <span class="text-sm">
                            วิดีโอ<?php echo $post['video_platform'] ? ' - '.htmlspecialchars($post['video_platform']) : ''; ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php elseif ($firstImage): ?>
            <img src="img/post_img/<?php echo htmlspecialchars($firstImage); ?>" 
                 alt="<?php echo htmlspecialchars($post['title']); ?>"
                 loading="lazy">
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center bg-gray-100 text-gray-400">
                <i class="fas fa-image text-4xl"></i>
            </div>
        <?php endif; ?>
        
        <?php if ($hasVideo && $imageCount > 0): ?>
            <div class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1">+<?php echo $imageCount; ?> รูป</div>
        <?php elseif (!$hasVideo && $imageCount > 1): ?>
            <div class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1">+<?php echo $imageCount - 1; ?> รูป</div>
        <?php endif; ?>
        
        <div class="grid-overlay">
            <div class="post-type-badge">
                <?php echo htmlspecialchars($post['Waste_type']); ?>
            </div>
            
            <div class="post-stats">
                <div class="stat-item">
                    <i class="fas fa-heart text-red-400"></i>
                    <span><?php echo $post['likes'] ?: 0; ?></span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-comment text-blue-400"></i>
                    <span><?php echo $post['comment_count'] ?: 0; ?></span>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
 <?php include 'chat-button.php'; ?>
   <script type="module">
    
    import wasteManager from './waste-management.js';
    window.wasteManager = wasteManager;

    // ประกาศฟังก์ชันเปิด modal ใน global scope
    window.openPostDetailModal = async function(postId) {
        // ตรวจสอบค่า postId ก่อนดำเนินการ
        if (!postId || postId === 0) {
            console.error('Invalid post ID:', postId);
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: 'ไม่พบข้อมูลโพสต์ที่ต้องการ',
                confirmButtonColor: '#22c55e'
            });
            return;
        }

        try {
            // แสดง loading
            Swal.fire({
                title: 'กำลังโหลด...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // ตรวจสอบ URL ที่ใช้เรียกข้อมูล
            const apiUrl = `get_post_detail.php?postId=${postId}`;
            console.log('Fetching post data from:', apiUrl);

            const response = await fetch(apiUrl);
            
            // ตรวจสอบสถานะการตอบกลับ
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Received post data:', data);

            Swal.close(); // ปิด loading

            if (data.success) {
                await wasteManager.openPostDetailModal(data.data);
            } else {
                throw new Error(data.message || 'ไม่สามารถโหลดข้อมูลโพสต์ได้');
            }
        } catch (error) {
            console.error('Error opening post detail:', error);
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: error.message || 'ไม่สามารถเปิดรายละเอียดโพสต์ได้ในขณะนี้',
                confirmButtonColor: '#22c55e'
            });
        }
    };

        // Enhanced Intersection Observer for smooth animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '50px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'scale(1)';
                }
            });
        }, observerOptions);

        document.addEventListener('DOMContentLoaded', function() {
            // Render video embeds for cards that have only video (no images)
            try {
                document.querySelectorAll('.video-slot').forEach(slot => {
                    const post = {
                        post_id: Number(slot.dataset.postId || 0),
                        userID: slot.dataset.userId ? String(slot.dataset.userId) : undefined,
                        video_source: slot.dataset.videoSource || 'none',
                        video_url: slot.dataset.videoUrl || null,
                        video_platform: slot.dataset.videoPlatform || null,
                        video_path: slot.dataset.videoPath || null,
                        images: []
                    };

                    if (window.wasteManager && typeof window.wasteManager.renderVideoHtml === 'function') {
                        // Use modal=true to avoid overlay, then disable pointer events to keep card clickable
                        const html = window.wasteManager.renderVideoHtml(post, true);
                        if (html) {
                            slot.innerHTML = `<div style="pointer-events:none">${html}</div>`;
                        }
                    }
                });
            } catch (e) {
                console.warn('Rendering video slots failed:', e);
            }

            // Animate grid items on load
            document.querySelectorAll('.grid-item').forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'scale(0.9)';
                item.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                
                setTimeout(() => {
                    item.style.opacity = '1';
                    item.style.transform = 'scale(1)';
                }, index * 50);

                // Add keyboard support
                item.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        const postId = this.getAttribute('data-post-id');
                        if (postId) {
                            window.openPostDetailModal(postId);
                        }
                    }
                });

                // Add to intersection observer
                observer.observe(item);
            });

            // Keyboard navigation for grid
            document.addEventListener('keydown', function(e) {
                const activeElement = document.activeElement;
                const gridItems = Array.from(document.querySelectorAll('.grid-item'));
                const currentIndex = gridItems.indexOf(activeElement);

                if (currentIndex === -1) return;

                let nextIndex = currentIndex;
                const columns = getColumnsCount();

                switch(e.key) {
                    case 'ArrowRight':
                        nextIndex = Math.min(currentIndex + 1, gridItems.length - 1);
                        break;
                    case 'ArrowLeft':
                        nextIndex = Math.max(currentIndex - 1, 0);
                        break;
                    case 'ArrowDown':
                        nextIndex = Math.min(currentIndex + columns, gridItems.length - 1);
                        break;
                    case 'ArrowUp':
                        nextIndex = Math.max(currentIndex - columns, 0);
                        break;
                    case 'Home':
                        nextIndex = 0;
                        break;
                    case 'End':
                        nextIndex = gridItems.length - 1;
                        break;
                    default:
                        return;
                }

                e.preventDefault();
                gridItems[nextIndex].focus();
            });

            // Get current columns count for navigation
            function getColumnsCount() {
                const width = window.innerWidth;
                if (width <= 640) return 3;
                if (width <= 768) return 4;
                if (width <= 1200) return 5;
                return 6;
            }

            // Smooth scroll to top button
            const scrollToTop = document.createElement('button');
            scrollToTop.innerHTML = '<i class="fas fa-arrow-up"></i>';
            scrollToTop.className = 'fixed bottom-6 right-6 bg-green-500 text-white p-3 rounded-full shadow-lg hover:bg-green-600 transition-all duration-300 z-50';
            scrollToTop.style.display = 'none';
            document.body.appendChild(scrollToTop);

            scrollToTop.onclick = () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            };

            window.addEventListener('scroll', () => {
                if (window.pageYOffset > 300) {
                    scrollToTop.style.display = 'block';
                } else {
                    scrollToTop.style.display = 'none';
                }
            });

            // Auto-submit form on select change for better UX
            document.querySelectorAll('.filter-select').forEach(select => {
                select.addEventListener('change', function() {
                    this.closest('form').submit();
                });
            });
        });

        // Error handling
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);
        });

        // Performance monitoring
        if ('performance' in window) {
            window.addEventListener('load', () => {
                const perfData = performance.timing;
                const pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;
                console.log(`Page load time: ${pageLoadTime}ms`);
            });
        }
    </script>
</body>
</html>
