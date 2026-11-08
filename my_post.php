<?php
session_start();
require_once 'db.php';

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['userid'])) {
    header('Location: login.html');
    exit();
}

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
$avatar_path = !empty($user['profile_img']) ? 'img/profile_uploads/' . $user['profile_img'] : 'img/profile_uploads/default.jpg';

// นับจำนวนโพสต์ทั้งหมดของผู้ใช้
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM post WHERE userId = ?");
$stmt->execute([$_SESSION['userid']]);
$postCount = $stmt->fetch()['count'];

// นับจำนวนไลค์ทั้งหมดที่ได้รับ
$stmt = $pdo->prepare("
    SELECT SUM(likes) as total_likes 
    FROM post 
    WHERE userId = ?
");
$stmt->execute([$_SESSION['userid']]);
$totalLikes = $stmt->fetch()['total_likes'] ?: 0;

// นับจำนวนคอมเมนต์ทั้งหมดที่ได้รับ
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_comments 
    FROM comment c
    JOIN post p ON c.postID = p.postID
    WHERE p.userId = ? AND c.status = 'visible'
");
$stmt->execute([$_SESSION['userid']]);
$totalComments = $stmt->fetch()['total_comments'];
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โพสต์ของฉัน - Bin Buddy</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/share.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"></script>
    <style>
        .profile-header {
            background-image: linear-gradient(to right, rgb(112, 203, 145), rgb(83, 169, 114));
            padding: 1.5rem;
            color: white;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            object-fit: cover;
                background-color: #ffe0a6;
        }

        .stats-card {
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

      

        .post-filters button.active {
            background-color: #4CAF50;
            color: white;
        }

        .post-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .post-card {
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            background-color: white;
        }

        .post-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* Highlight target post when navigated from notification */
        .post-card.highlight-flash {
            outline: 3px solid #10b981;
            box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.25);
            animation: highlightPulse 1.2s ease-out 2;
        }
        @keyframes highlightPulse {
            0%   { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.35); }
            100% { box-shadow: 0 0 0 14px rgba(16, 185, 129, 0); }
        }

        .post-image-container {
            height: 200px;
            overflow: hidden;
            position: relative;
        }

        .post-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            background-color: white;
            border-radius: 0.75rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .pagination-controls button {
            transition: all 0.3s ease;
        }

        .pagination-controls button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .profile-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }

        .upload-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .profile-upload:hover .upload-overlay {
            opacity: 1;
        }
        .modal-content * {
            pointer-events: auto !important;
        }
        .profile-upload {
    position: relative;
    display: inline-block;
    cursor: pointer;
    z-index: 999999;
}
    </style>
    <style>
    /* Modal base */
    #editPostModal {
        z-index: 10000 !important;
    }
    #editPostModal .modal-content,
    #editPostModal input,
    #editPostModal textarea,
    #editPostModal select,
    #editPostModal label {
        pointer-events: auto !important;
        user-select: auto !important;
    }

    /* SweetAlert override */
    .swal2-container {
        z-index: 11000 !important;
    }

    /* Simplified readonly input styles - เน้น contrast สูงและ compatible กับ Safari */
    #editPostModal input[readonly],
    #editPostModal .form-control[readonly] {
        color: #000000 !important;                  /* สี text ดำชัดเจน */
        -webkit-text-fill-color: #000000 !important; /* สำหรับ Safari/Chrome iOS */
        background-color: #f3f4f6 !important;       /* พื้นหลังเทาอ่อน */
        opacity: 1 !important;                      /* Force ไม่ซีด */
        border: 1px solid #d1d5db !important;       /* ขอบชัด */
        box-shadow: none !important;
        cursor: not-allowed !important;
        font-weight: 500 !important;
        font-size: 16px !important;
        padding: 12px 16px !important;
        border-radius: 8px !important;
        -webkit-appearance: none !important;        /* ป้องกัน style default ใน iOS */
    }

    /* Hover/Focus states เพื่อ highlight */
    #editPostModal input[readonly]:hover,
    #editPostModal input[readonly]:focus {
        background-color: #e5e7eb !important;
        outline: 2px solid #10b981 !important;
        outline-offset: 2px;
    }

    /* ลบ placeholder ถ้าว่างเพื่อไม่ confuse */
    #editPostModal input[readonly]::placeholder {
        color: transparent !important;
    }

    /* Specific สำหรับช่อง waste* เพื่อ force visibility */
    #editPostModal input[name="wasteItem"],
    #editPostModal input[name="wasteType"],
    #editPostModal input[name="managementType"] {
        color: #000000 !important;
        -webkit-text-fill-color: #000000 !important;
        background-color: #f3f4f6 !important;
    }
   /* ช่องอัปโหลด */
.image-slot{
  position: relative;
  /* ใช้สัดส่วนคงที่ จะได้ไม่กระตุกเวลาโหลดรูป */
  aspect-ratio: 4 / 3;      /* ปรับเป็น 1/1 ถ้าชอบแบบสี่เหลี่ยมจัตุรัส */
  width: 100%;
  min-height: 180px;        /* กันไว้สำหรับจอเล็ก */
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  background: #fafafa;
  display:flex; align-items:center; justify-content:center;
  cursor: pointer;
  transition: all .15s ease;
  overflow: hidden;         /* ตัดขอบรูปเกิน */
}
.image-slot:hover{ border-color:#10b981; background:#f0fdf4; }

/* พื้นที่ไอคอน/ข้อความตอนยังไม่มีรูป */
.slot-empty{
  position:absolute; inset:0;           /* คลุมทั้งช่อง */
  display:flex; flex-direction:column;
  align-items:center; justify-content:center;
  color:#6b7280;
  pointer-events:none;
}

/* รูปตัวอย่างให้เต็มช่องแบบครอบตัด */
.slot-preview{
  position:absolute; inset:0;
  width:100%; height:100%;
  object-fit:cover;        /* เดิมใช้ contain ทำให้เห็นไอคอนด้านข้าง */
  border-radius:10px;
  display:none;            /* เริ่มต้นซ่อน */
}

/* โชว์รูปเมื่อมีคลาส has-image */
#editPostModal .image-slot.has-image .slot-preview { display:block !important; }
#editPostModal .image-slot.has-image .slot-remove { display:inline-flex !important; }

/* ปุ่มลบ */
.slot-remove{
  position:absolute;
  bottom:10px; left:50%;
  transform:translateX(-50%);
  background:#ffffff;
  border:1px solid #ef4444;
  color:#ef4444;
  padding:6px 12px;
  border-radius:9999px;
  font-size: 0.875rem;
  box-shadow: 0 1px 2px rgba(0,0,0,.06);
  display:none;
}
.image-slot.has-image .slot-remove{ display:inline-flex; }
.imageViewer{
z-index: 10000;    
}
.image-slot.has-image .slot-preview{ display:block !important; }
.image-slot.has-image .slot-remove{ display:inline-flex !important; }

</style>
<style>
.image-slot {
  position: relative;
  aspect-ratio: 4/3;
  width: 100%;
  min-height: 180px;
  border: 2px dashed #d1d5db;
  border-radius: 12px;
  background: #fafafa;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all .15s ease;
  overflow: hidden;
}

.image-slot.has-image {
  border-color: #10b981;
  background: #f0fdf4;
}

.slot-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #6b7280;
  pointer-events: none;
}

.slot-preview {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 10px;
  display: none;
}

.image-slot.has-image .slot-preview {
  display: block !important;
}

.slot-remove {
  position: absolute;
  bottom: 10px;
  left: 50%;
  transform: translateX(-50%);
  background: #ffffff;
  border: 1px solid #ef4444;
  color: #ef4444;
  padding: 6px 12px;
  border-radius: 9999px;
  font-size: 0.875rem;
  box-shadow: 0 1px 2px rgba(0,0,0,.06);
  display: none;
}

.image-slot.has-image .slot-remove {
  display: inline-flex !important;
}
.image-slot.has-image .slot-preview{ display:block !important; opacity:1; }
.image-slot.has-image .slot-empty{ display:none !important; }
.slot-remove{ display:none; }
.image-slot.has-image + .slot-remove{ display:inline-flex !important; }
/* html {
    scrollbar-gutter: stable;
} */

</style>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-gray-100 font-kanit">
    <?php include 'nav.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <h1 class="text-2xl md:text-3xl font-bold mb-6">โพสต์ของฉัน</h1>

        <div class="bg-gradient-to-r from-green-600 to-green-500 p-6 text-white">
            <div class="flex flex-col md:flex-row items-center md:items-start">
                <img src="<?php echo htmlspecialchars($avatar_path); ?>" alt="Profile" class="profile-avatar mb-4 md:mb-0 md:mr-6">

                <div class="text-center md:text-left">
                    <h2 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($user['name']); ?></h2>
                    <p class="text-green-100 mb-4"><?php echo htmlspecialchars($user['email']); ?></p>

                    <div class="flex flex-wrap justify-center md:justify-start gap-4 mb-4">
                        <a href="#" class="inline-flex items-center px-4 py-2 bg-white text-green-600 rounded-full hover:bg-green-50 transition-all duration-300" data-bs-toggle="modal" data-bs-target="#profileEditModal">
                            <i class="fas fa-user-edit mr-2"></i>แก้ไขโปรไฟล์
                        </a>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#wasteManagementModal" class="inline-flex items-center px-4 py-2 bg-white text-green-600 rounded-full hover:bg-green-50 transition-all duration-300">
                            <i class="fas fa-plus mr-2"></i>เพิ่มโพสต์ใหม่
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="stats-card p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mr-4">
                        <i class="fas fa-file-alt text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">โพสต์ทั้งหมด</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo $postCount; ?></p>
                    </div>
                </div>
            </div>

            <div class="stats-card p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mr-4">
                        <i class="fas fa-heart text-red-500 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">ไลค์ทั้งหมด</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo $totalLikes; ?></p>
                    </div>
                </div>
            </div>

            <div class="stats-card p-4">
                <div class="flex items-center">
                    <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mr-4">
                        <i class="fas fa-comment text-blue-500 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">คอมเมนต์ทั้งหมด</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo $totalComments; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white p-4 rounded-lg shadow-sm mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <div class="font-semibold text-gray-600">ตัวกรอง:</div>
                <div class="post-filters flex flex-wrap gap-2">
                    <button class="filter-btn active px-4 py-2 rounded-lg bg-gray-200 text-gray-700 hover:bg-green-500 hover:text-white transition-all" data-status="all">ทั้งหมด</button>
                    <button class="filter-btn px-4 py-2 rounded-lg bg-gray-200 text-gray-700 hover:bg-green-500 hover:text-white transition-all" data-status="approved">อนุมัติแล้ว</button>
                    <button class="filter-btn px-4 py-2 rounded-lg bg-gray-200 text-gray-700 hover:bg-green-500 hover:text-white transition-all" data-status="pending">รออนุมัติ</button>
                    <button class="filter-btn px-4 py-2 rounded-lg bg-gray-200 text-gray-700 hover:bg-green-500 hover:text-white transition-all" data-status="suspended">ถูกปฏิเสธ</button>
                </div>
                <div class="ml-auto">
                    <div class="relative">
                        <input type="text" id="searchInput" class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="ค้นหาโพสต์...">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                    </div>
                </div>
            </div>
        </div>

        <div id="myPostsGrid" class="post-grid mb-6">
            <div class="loading-spinner text-center py-8">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-green-500"></div>
                <p class="mt-2 text-gray-500">กำลังโหลดข้อมูล...</p>
            </div>
        </div>

        <div class="pagination-controls flex justify-center items-center gap-4 py-6">
            <button class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50" id="prevPageBtn">
                <i class="fas fa-chevron-left"></i> ก่อนหน้า
            </button>

            <span class="current-page font-semibold text-green-700" id="currentPageInfo">
                หน้า 1 จาก 1
            </span>

            <button class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50" id="nextPageBtn">
                ถัดไป <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <div id="postDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-[9999] hidden flex justify-center items-center overflow-hidden">
        <div class="bg-white rounded-lg w-full h-full md:w-4/5 md:h-[90vh] md:max-w-6xl flex flex-col overflow-hidden relative">
            <button id="closePostDetailModal" class="absolute top-4 right-4 text-4xl text-gray-400 hover:text-gray-700 z-[10000] p-2 bg-white bg-opacity-70 rounded-full w-14 h-14 flex items-center justify-center shadow-lg">
                &times;
            </button>

            <div class="flex flex-col md:flex-row flex-grow overflow-hidden">
                <div class="w-full md:w-2/3 bg-gray-100 flex items-center justify-center relative overflow-hidden">
                    <div id="postDetailModalImages" class="w-full h-full overflow-y-auto flex flex-col items-center justify-start">
                    </div>
                </div>

                <div id="postDetailRightSide" class="w-full md:w-1/3 flex flex-col bg-white border-t md:border-t-0 md:border-l">
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="profileEditModal" tabindex="-1" aria-labelledby="profileEditModalLabel" aria-hidden="true" style="z-index: 10001;">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-green-500 text-white">
                    <h5 class="modal-title" id="profileEditModalLabel">แก้ไขโปรไฟล์</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="profileEditModalBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-green-500" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
   
<?php include 'edit_post_modal.php'; ?>
<script>
document.addEventListener('change', (e) => {
  const t = e.target;
  if (t && t.matches('input[type="file"][id^="image"]')) {
    const slot = parseInt(String(t.id).replace('image',''), 10);
    if (!Number.isNaN(slot) && slot >= 1 && slot <= 3) {
      handleFileSelect(slot);
    }
  }
}, true);
</script>
<script>
let $editModal = null;
let existingImages = [];
let removedImages  = [];

// helper เลือก element ภายใน modal
const mq  = sel => $editModal.querySelector(sel);

/* ===== Image Viewer ===== */
function openImageViewer(src){
  const wrap = document.getElementById('imageViewer');
  const img  = document.getElementById('imageViewerImg');
  if (!wrap || !img) return;
  img.src = src;
  wrap.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function closeImageViewer(){
  const wrap = document.getElementById('imageViewer');
  const img  = document.getElementById('imageViewerImg');
  if (!wrap || !img) return;
  img.removeAttribute('src');
  wrap.classList.add('hidden');
  document.body.style.overflow = '';
}

// bind เมื่อ DOM พร้อม
document.addEventListener('DOMContentLoaded', () => {
  const wrap = document.getElementById('imageViewer');
  const btn  = document.getElementById('imageViewerClose');
  if (btn)  btn.addEventListener('click', closeImageViewer);
  if (wrap) wrap.addEventListener('click', (e)=>{ if (e.target === wrap) closeImageViewer(); });
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape' && wrap && !wrap.classList.contains('hidden')) closeImageViewer();
  });
}); // <-- ปิดให้ครบ

/* ===== ตัวช่วยนับตัวอักษร ===== */
function setCounter(el, counterEl, max){ counterEl.textContent = `${el.value.length}/${max}`; }
function bindCounters(){
  const title = mq('#edit_title'), detail = mq('#edit_detail');
  if (!title || !detail) return;
  setCounter(title,  mq('#titleCounter'), 50);
  setCounter(detail, mq('#detailCounter'), 500);
  title.oninput  = () => setCounter(title,  mq('#titleCounter'), 50);
  detail.oninput = () => setCounter(detail, mq('#detailCounter'), 500);
}

/* ===== จัดการช่องรูป ===== */


function clearImageSlots(){
  [1,2,3].forEach(clearOneSlot);
  existingImages = [];
  removedImages  = [];
  mq('#existingImagesField').value = '[]';
  mq('#removedImagesField').value  = '[]';
}
function mq(sel, root=document){ return root.querySelector(sel); }
function getSlotEls(slot){
  const container = mq(`.image-slot[data-slot="${slot}"]`);
  return {
    container,
    img:    container?.querySelector('img'),
    empty:  container?.querySelector('.slot-empty'),
    btn:    container?.querySelector('[data-action="remove-image"]'),
    fi:     document.getElementById(`image${slot}`),
  };
}
function setPreviewSlot(slot, url, isExisting, filename){
  const { container, img, btn, empty } = getSlotEls(slot);
  if (!container || !img) return;

  // แสดงรูป
  container.classList.add('has-image');
  img.style.display = 'block';
  img.src = url;
  
  if (empty) empty.style.display = 'none';
  if (btn) {
    btn.style.display = 'inline-flex';
    btn.dataset.isExisting = isExisting ? '1' : '0';
    btn.dataset.filename = filename || '';
  }
}



function applyPreview(slot, url){
  const { container, img, empty, btn } = getSlotEls(slot);
  if (!container || !img) return;

  container.classList.add('has-image');

  if (empty){ empty.classList.add('hidden'); empty.style.display = 'none'; }

  if (btn){
    btn.classList.remove('hidden');
    btn.style.display = 'inline-flex';
    btn.dataset.isExisting = '0';   // ✅ บอกว่าเป็นไฟล์ใหม่
    btn.dataset.filename   = '';    // ไม่มีชื่อไฟล์บนเซิร์ฟเวอร์
    btn.onclick = () => removeImage(slot);
  }

  img.classList.remove('hidden');
  img.style.display = 'block';
  img.dataset.objectUrl = url;      // ✅ เก็บไว้ revoke ตอนลบ
  img.decoding = 'async';
  img.loading  = 'eager';
  img.src = url;
}

function handleFileSelect(slot){
  const {fi} = getSlotEls(slot);
  if (!fi || !fi.files?.length) return;

  const f = fi.files[0];

  // กันไฟล์ที่พรีวิวไม่ได้ (เช่น HEIC)
  if (!/^(image\/(png|jpe?g|gif|webp))$/i.test(f.type)){
    fi.value = '';
    alert('ไฟล์รูปแบบนี้พรีวิวไม่ได้ (เช่น HEIC) กรุณาใช้ JPG/PNG/WebP');
    return;
  }

  // สร้าง URL แล้วพรีวิว
  if (fi.dataset.objectUrl) URL.revokeObjectURL(fi.dataset.objectUrl);
  const url = URL.createObjectURL(f);
  fi.dataset.objectUrl = url;
  fi.dataset.newfile = '1';

  applyPreview(slot, url);
}

// delegate ให้ทุก input#imageX

let __fileDelegationBound = false;
function ensureDelegatedFileChange(){
  if (__fileDelegationBound) return;
  document.addEventListener('change', (e) => {
    const t = e.target;
    // จับเฉพาะ input[type=file] ที่ id ขึ้นต้นด้วย image
    if (t && t.matches('input[type="file"][id^="image"]')) {
      const slot = parseInt(String(t.id).replace('image',''), 10);
      console.log('[delegated change detected]', t.id, '=> slot', slot);
      // ถ้า slot เป็นเลข 1..3 ค่อยเรียก
      if (!Number.isNaN(slot) && slot >= 1 && slot <= 3) {
        try { handleFileSelect(slot); } catch(err){ console.error(err); }
      }
    }
  }, true); // ใช้ capture กันบัฟเฟอร์
  __fileDelegationBound = true;
}
ensureDelegatedFileChange();
document.addEventListener('change', (e) => {
  const input = e.target;
  // ตรวจสอบว่าเป็น input file ใน modal แก้ไขโพสต์
  if (input.matches('#editPostModal input[type="file"][id^="image"]')) {
    const slot = parseInt(input.id.replace('image', ''), 10);
    if (!isNaN(slot) && slot >= 1 && slot <= 3) {
      handleFileSelect(slot);
    }
  }
}, true);

// ========== 2) สแกนและ log ช่องรูปหลังแสดง modal ==========
function debugScanSlots(stage=''){
  try {
    console.groupCollapsed('DEBUG scan slots -', stage);
    [1,2,3].forEach(i => {
      const fi = mq ? mq('#image'+i) : null;
      console.log(`slot ${i} -> input?`, !!fi, 'id=', fi?.id, 'inModal?', $editModal?.contains(fi));
      // ใช้ dumpSlot ที่คุณมีอยู่แล้ว
      if (typeof dumpSlot === 'function') dumpSlot(i, 'scan');
    });
  } finally { console.groupEnd?.(); }
}

// เรียกสแกนทุกครั้งหลัง modal แสดงเสร็จ
const __origAfterModalShown = typeof afterModalShown === 'function' ? afterModalShown : (()=>{});
window.afterModalShown = function(){
  __origAfterModalShown();
  setTimeout(() => debugScanSlots('afterModalShown'), 0);
};

// ========== 3) Safety: ถ้าไม่พบ $editModal ให้เตือน ==========
document.addEventListener('DOMContentLoaded', () => {
  if (!document.getElementById('editPostModal')){
    console.warn('[WARN] #editPostModal ไม่อยู่ใน DOM ตอน DOMContentLoaded (ตรวจ include edit_post_modal.php)');
  }
});
function dumpSlot(slot, label=''){
  const {fi, label:lb, container, img, btn} = getSlotEls(slot);
  console.groupCollapsed(`DEBUG slot ${slot} ${label}`);
  console.log({ fi, label: lb, container, img, btn });
  console.log('container.has .image-slot?', container?.classList.contains('image-slot'));
  console.log('img display =', img ? getComputedStyle(img).display : 'no img');
  console.log('container classList =', container?.className);
  console.groupEnd();
}



async function removeImage(slot){
  const { btn } = getSlotEls(slot);
  const isExisting = btn?.dataset?.isExisting === '1';
  const filename   = btn?.dataset?.filename || '';

  if (isExisting && filename){
    // ทำเครื่องหมายไว้ว่าไฟล์เก่าต้องลบเมื่อ submit
    markRemoveExisting(filename);
  }
  // รีเซ็ตช่องให้หายทันที
  resetSlot(slot);
}

/* ===== ปุ่มซูมใน modal ===== */
function bindPreviewZoom(){
  if (!$editModal) return;
  $editModal.addEventListener('click', (e)=>{
    const img = e.target.closest('img.slot-preview');
    if (!img) return;
    e.preventDefault();
    e.stopPropagation();
    openImageViewer(img.src);
  });
}
function afterModalShown(){
  [1,2,3].forEach(i=>{
    const img = $editModal?.querySelector(`#preview${i}`);
    if (img) img.classList.add('cursor-zoom-in');
  });
  bindPreviewZoom();
}

/* ===== เปิด modal แก้ไขโพสต์ ===== */
async function editPost(postId){
  $editModal = document.getElementById('editPostModal');
  $editModal.addEventListener('click', (e) => {
  const container = e.target.closest('.image-slot');
  if (!container) return;
  
  const slot = container.dataset.slot;
  const fileInput = mq(`#image${slot}`);
  if (fileInput && !container.classList.contains('has-image')) {
    fileInput.click();
  }
});
  const modal = bootstrap.Modal.getOrCreateInstance($editModal);

  mq('#editPostForm').reset();
  mq('#edit_post_id').value = postId;

  clearImageSlots();
  bindCounters();
[1,2,3].forEach(i=>{
  const fi = mq(`#image${i}`);
  if (fi){
    fi.value = '';
    // Bind change handler to the correct slot index
    fi.onchange = () => handleFileSelect(i);
  }
});

  modal.show();
  afterModalShown();

  try{
    const r = await fetch(`get_post_detail.php?postId=${encodeURIComponent(postId)}`, {headers:{Accept:'application/json'}});
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error('โหลดข้อมูลไม่สำเร็จ');
    const d = j.data;

    mq('#edit_item').value     = d.name ?? '';
    mq('#edit_category').value = d.Waste_type ?? '';
    mq('#edit_method').value   = d.Management_type ?? '';
    mq('#edit_title').value    = d.title ?? '';
    mq('#edit_detail').value   = d.details ?? '';
    setCounter(mq('#edit_title'),  mq('#titleCounter'), 50);
    setCounter(mq('#edit_detail'), mq('#detailCounter'), 500);

    // แสดงรูปเดิม (และให้คลิกเพื่อขยาย ไม่เปิดไฟล์ใหม่)
    let imgs = d.images ?? d.image ?? [];
    if (typeof imgs === 'string'){ try{ imgs = JSON.parse(imgs); }catch{ imgs = []; } }
    if (!Array.isArray(imgs)) imgs = [];
    existingImages = imgs.slice(0,3);
    existingImages.forEach((fn, idx) => setPreviewSlot(idx+1, `img/post_img/${fn}`, true, fn));
    mq('#existingImagesField').value = JSON.stringify(existingImages);
    mq('#removedImagesField').value  = '[]';
  }catch(e){
    console.error(e);
    Swal.fire({icon:'error', title:'เกิดข้อผิดพลาด', text:e.message, confirmButtonColor:'#059669'});
  }
}

/* ให้เรียกใช้จากปุ่มใน HTML */
window.editPost         = editPost;
window.removeImage      = removeImage;
window.handleFileChange = handleFileChange;
</script>
    <script>
        async function fetchMyPosts() {
            try {
                const response = await fetch(`get_my_posts.php?userId=${<?php echo $_SESSION['userid']; ?>}`);
                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                return data;
            } catch (error) {
                console.error('Error fetching posts:', error);
                return [];
            }
        }

        async function initMyPosts() {
            window.allPosts = await fetchMyPosts();
        }

        initMyPosts();
    </script>

<script type="module">
    import wasteManager from './waste-management.js';

    window.wasteManager = wasteManager;

    document.addEventListener('DOMContentLoaded', async function() {
        let allPosts = [];
        let filteredPosts = [];
        let currentPage = 1;
        const postsPerPage = 8;
        let currentFilter = 'all';
        let searchQuery = '';

        // เพิ่มฟังก์ชัน updateCharCount


        async function fetchMyPosts() {
            try {
                const response = await fetch(`get_my_posts.php?userId=${<?php echo $_SESSION['userid']; ?>}`);
                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                allPosts = data;
                return data;
            } catch (error) {
                console.error('Error fetching posts:', error);
                return [];
            }
        }

        window.openCommentsModal = async function(postId) {
            try {
                const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
                const postStatus = postCard ? postCard.getAttribute('data-post-status') : null;

                if (postStatus !== 'approved') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่สามารถดำเนินการได้',
                        text: postStatus === 'pending' ?
                            'โพสต์นี้กำลังรอการอนุมัติ ยังไม่สามารถดูหรือเพิ่มคอมเมนต์ได้' :
                            'โพสต์นี้ถูกปฏิเสธ ไม่สามารถดูหรือเพิ่มคอมเมนต์ได้',
                        confirmButtonColor: '#059669'
                    });
                    return;
                }

                await window.wasteManager.openCommentsModal(postId);
            } catch (error) {
                console.error('Error opening comments modal:', error);
            }
        };

        // window.handleLikeClick = async function(postId) {
        //     try {
        //         const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
        //         const postStatus = postCard ? postCard.getAttribute('data-post-status') : null;

        //         if (postStatus !== 'approved') {
        //             Swal.fire({
        //                 icon: 'warning',
        //                 title: 'ไม่สามารถดำเนินการได้',
        //                 text: postStatus === 'pending' ?
        //                     'โพสต์นี้กำลังรอการอนุมัติ ยังไม่สามารถไลค์ได้' :
        //                     'โพสต์นี้ถูกปฏิเสธ ไม่สามารถไลค์ได้',
        //                 confirmButtonColor: '#059669'
        //             });
        //             return;
        //         }

        //         const result = await window.wasteManager.toggleLike(postId);

        //         if (result && result.success) {
        //             if (postCard) {
        //                 const likeButton = postCard.querySelector('.like-button');
        //                 const likeIcon = likeButton.querySelector('i');
        //                 const likeCount = likeButton.querySelector('.likes-count');

        //                 likeButton.classList.toggle('active', result.is_liked);
        //                 likeIcon.classList.toggle('text-red-500', result.is_liked);
        //                 likeCount.textContent = result.likes;
        //             }

        //             const postIndex = window.allPosts.findIndex(post => post.post_id == postId);
        //             if (postIndex !== -1) {
        //                 window.allPosts[postIndex].is_liked = result.is_liked;
        //                 window.allPosts[postIndex].likes = result.likes;
        //             }
        //         }
        //     } catch (error) {
        //         console.error('เกิดข้อผิดพลาดในการไลค์:', error);
        //     }
        // };
        // Expose like handler used by inline onclick in cards
        window.handleLikeClick = async function(postId) {
            try {
                const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
                const postStatus = postCard ? postCard.getAttribute('data-post-status') : null;

                if (postStatus && postStatus !== 'approved') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่สามารถกดถูกใจได้',
                        text: postStatus === 'pending'
                            ? 'โพสต์กำลังรอตรวจสอบ ยังไม่สามารถกดถูกใจได้'
                            : 'โพสต์ถูกระงับชั่วคราว ยังไม่สามารถกดถูกใจได้',
                        confirmButtonColor: '#059669'
                    });
                    return;
                }

                const result = await window.wasteManager.toggleLike(postId);
                if (result && result.success && Array.isArray(window.allPosts)) {
                    const idx = window.allPosts.findIndex(p => p.post_id == postId);
                    if (idx !== -1) {
                        window.allPosts[idx].is_liked = result.is_liked;
                        window.allPosts[idx].likes = result.likes;
                    }
                }
            } catch (error) {
                console.error('Error toggling like on my_post:', error);
            }
        };
        function fillExistingImage(slot, imageUrl, filenameOnServer){
  const { container, img, empty, btn } = getSlotEls(slot);
  if (!container || !img) return;

  container.classList.add('has-image');
  if (empty){ empty.classList.add('hidden'); empty.style.display = 'none'; }

  if (btn){
    btn.classList.remove('hidden');
    btn.style.display = 'inline-flex';
    btn.dataset.isExisting = '1';          // ✅ ไฟล์เดิม
    btn.dataset.filename   = filenameOnServer; // เช่น 'post123_slot2.jpg'
    btn.onclick = () => removeImage(slot);
  }

  img.classList.remove('hidden');
  img.style.display = 'block';
  img.src = imageUrl;   // รูปเดิม ไม่ต้องมี objectURL
}
        function filterPosts(status, query = '') {
            if (status === 'all' && query === '') {
                return allPosts;
            }

            return allPosts.filter(post => {
                const statusMatch = status === 'all' || post.post_status === status;
                const queryLower = query.toLowerCase();
                const searchMatch = query === '' ||
                    post.title.toLowerCase().includes(queryLower) ||
                    post.details.toLowerCase().includes(queryLower) ||
                    post.Waste_type.toLowerCase().includes(queryLower) ||
                    post.Management_type.toLowerCase().includes(queryLower);

                return statusMatch && searchMatch;
            });
        }

        function renderPosts() {
            const grid = document.getElementById('myPostsGrid');

            const startIndex = (currentPage - 1) * postsPerPage;
            const endIndex = startIndex + postsPerPage;
            const paginatedPosts = filteredPosts.slice(startIndex, endIndex);

            if (paginatedPosts.length === 0) {
                grid.innerHTML = `
                    <div class="empty-state col-span-full">
                        <i class="fas fa-file-alt text-gray-300 text-5xl mb-4"></i>
                        <p class="text-gray-500">ไม่พบโพสต์ที่ตรงกับเงื่อนไข</p>
                    </div>
                `;
                updatePaginationControls();
                return;
            }

            grid.innerHTML = paginatedPosts.map(post => {
                let statusClass = '';
                let statusText = '';

                switch (post.post_status) {
                    case 'approved':
                        statusClass = 'bg-green-100 text-green-800';
                        statusText = 'อนุมัติแล้ว';
                        break;
                    case 'pending':
                        statusClass = 'bg-yellow-100 text-yellow-800';
                        statusText = 'รออนุมัติ';
                        break;
                    case 'suspended':
                        statusClass = 'bg-red-100 text-red-800';
                        statusText = 'ถูกปฏิเสธ';
                        break;
                }

                const hasVideo = (post.video_source === 'url' && post.video_url) || (post.video_source === 'file' && post.video_path);
                const imageCount = Array.isArray(post.images) ? post.images.length : 0;
                const videoHtml = ((window.wasteManager && typeof window.wasteManager.renderVideoHtml === 'function')
                    ? window.wasteManager.renderVideoHtml(post, false)
                    : `<div class=\"flex items-center justify-center h-full bg-black/5 text-gray-600\">\n                        <i class=\"fas fa-play-circle text-5xl mr-2\"></i>\n                        <span class=\"text-sm\">วิดีโอ${post.video_platform ? ' - ' + post.video_platform : ''}</span>\n                      </div>`);
                const mainMedia = hasVideo
                    ? videoHtml
                    : (imageCount > 0
                        ? `<img src=\"img/post_img/${post.images[0]}\" alt=\"${post.title}\" class=\"post-image\">`
                        : `<div class=\"flex items-center justify-center h-full bg-gray-200 text-gray-400\">\n                             <i class=\"fas fa-image text-4xl\"></i>\n                           </div>`);

                const isLiked = parseInt(post.is_liked) === 1;
                const isApproved = post.post_status === 'approved';

                return `
                    <div class="post-card" data-post-id="${post.post_id}" data-post-status="${post.post_status}">
                        <div class="post-image-container cursor-pointer" onclick="openMyPostDetail(${post.post_id})">
                            ${mainMedia}
                            <div class="absolute top-2 right-2">
                                <span class="px-2 py-1 rounded-full text-xs font-medium ${statusClass}">
                                    ${statusText}
                                </span>
                            </div>
                            ${hasVideo
                                ? (imageCount > 0
                                    ? `<div class=\"absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1\">+${imageCount} รูป</div>`
                                    : '')
                                : (imageCount > 1
                                    ? `<div class=\"absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1\">+${imageCount - 1} รูป</div>`
                                    : '')}
                        </div>
                        
                        <div class="p-4">
                            <h3 class="font-bold text-lg mb-2 hover:text-green-600 cursor-pointer" onclick="openMyPostDetail(${post.post_id})">
                                ${post.title}
                            </h3>
                            
                            <div class="flex items-center text-sm text-gray-500 mb-2">
                                <span class="mr-3"><i class="fas fa-trash mr-1"></i>${post.Waste_type}</span>
                                <span><i class="fas fa-recycle mr-1"></i>${post.Management_type}</span>
                            </div>
                            
                            <p class="text-gray-600 text-sm mb-4 line-clamp-2">${post.details}</p>
                            
                            <div class="flex justify-between items-center pt-2 border-t">
                                <div class="flex items-center space-x-4">
                                    ${isApproved ? `
                                        <button class="like-button ${isLiked ? 'active' : ''} flex items-center space-x-1 text-gray-500 hover:text-red-500" onclick="handleLikeClick(${post.post_id})">
                                            <i class="fas fa-heart ${isLiked ? 'text-red-500' : ''}"></i>
                                            <span class="likes-count">${post.likes || 0}</span>
                                        </button>
                                        
                                        <button class="flex items-center space-x-1 text-gray-500 hover:text-blue-500" onclick="window.openCommentsModal(${post.post_id})">
                                            <i class="fas fa-comment"></i>
                                            <span>${post.comment_count || 0}</span>
                                        </button>
                                    ` : `
                                        <button class="like-button flex items-center space-x-1 text-gray-400 cursor-not-allowed">
                                            <i class="fas fa-heart"></i>
                                            <span class="likes-count">${post.likes || 0}</span>
                                        </button>
                                        
                                        <button class="flex items-center space-x-1 text-gray-400 cursor-not-allowed" onclick="openMyPostDetail(${post.post_id})">
                                            <i class="fas fa-comment"></i>
                                            <span>${post.comment_count || 0}</span>
                                        </button>
                                    `}
                                </div>
                                
                                <div class="flex items-center">
                                    ${isApproved ? `
                                        <button class="text-gray-500 hover:text-green-600 mx-1" onclick="editPost(${post.post_id})">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    ` : `
                                        <button class="text-gray-400 cursor-not-allowed mx-1">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    `}
                                    <button class="text-gray-500 hover:text-red-600 mx-1" onclick="deletePost(${post.post_id})">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            updatePaginationControls();
        }

        function updatePaginationControls() {
            const totalPages = Math.ceil(filteredPosts.length / postsPerPage);
            const prevBtn = document.getElementById('prevPageBtn');
            const nextBtn = document.getElementById('nextPageBtn');
            const pageInfo = document.getElementById('currentPageInfo');

            pageInfo.textContent = `หน้า ${currentPage} จาก ${totalPages || 1}`;

            prevBtn.disabled = currentPage <= 1;
            nextBtn.disabled = currentPage >= totalPages || filteredPosts.length === 0;

            prevBtn.classList.toggle('opacity-50', currentPage <= 1);
            nextBtn.classList.toggle('opacity-50', currentPage >= totalPages || filteredPosts.length === 0);
        }

        function changePage(direction) {
            const totalPages = Math.ceil(filteredPosts.length / postsPerPage);
            const newPage = currentPage + direction;

            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                renderPosts();

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            }
        }

        function searchPosts(query) {
            searchQuery = query;
            currentPage = 1;

            filteredPosts = filterPosts(currentFilter, query);
            renderPosts();
        }

        allPosts = await fetchMyPosts();
        filteredPosts = allPosts;
        renderPosts();

        // หากมีพารามิเตอร์ highlight_post_id ให้เลื่อนไปยังโพสต์นั้นและเน้นให้เห็นชัดเจน
        function focusPostById(postId) {
            if (!postId) return;
            const idStr = String(postId);
            // หา index ของโพสต์ในรายการที่กรองแล้ว
            const idx = filteredPosts.findIndex(p => String(p.post_id) === idStr);
            if (idx === -1) return;
            const targetPage = Math.floor(idx / postsPerPage) + 1;
            if (targetPage !== currentPage) {
                currentPage = targetPage;
                renderPosts();
            }
            // หน่วงเล็กน้อยเพื่อรอ DOM render
            setTimeout(() => {
                const card = document.querySelector(`.post-card[data-post-id="${CSS.escape(idStr)}"]`);
                if (card) {
                    card.classList.add('highlight-flash');
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => card.classList.remove('highlight-flash'), 3000);
                }
            }, 100);
        }

        const params = new URLSearchParams(window.location.search);
        const highlightId = params.get('highlight_post_id');
        if (highlightId) {
            focusPostById(highlightId);
        }

        document.querySelectorAll('.filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                });

                this.classList.add('active');

                currentFilter = this.getAttribute('data-status');
                currentPage = 1;

                filteredPosts = filterPosts(currentFilter, searchQuery);
                renderPosts();
            });
        });

        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    searchPosts(e.target.value);
                }, 300);
            });
        }

        document.getElementById('prevPageBtn').addEventListener('click', () => changePage(-1));
        document.getElementById('nextPageBtn').addEventListener('click', () => changePage(1));

        window.openMyPostDetail = async function(postId) {
            try {
                const postCard = document.querySelector(`.post-card[data-post-id="${postId}"]`);
                const postStatus = postCard ? postCard.getAttribute('data-post-status') : null;

                window.wasteManager.currentOpenPostId = null;

                const response = await fetch(`get_post_detail.php?postId=${postId}`);
                const data = await response.json();

                if (data.success) {
                    data.data.post_status = postStatus;
                    await wasteManager.openPostDetailModal(data.data);

                    if (postStatus !== 'approved') {
                        setTimeout(() => {
                            const likeButton = document.getElementById(`modalLikeButton-${postId}`);
                            const commentButtons = document.querySelectorAll(`.comments-button`);

                            if (likeButton) {
                                likeButton.classList.add('text-gray-400', 'cursor-not-allowed');
                                likeButton.classList.remove('hover:text-red-500');
                                likeButton.onclick = null;
                            }

                            commentButtons.forEach(btn => {
                                btn.classList.add('text-gray-400', 'cursor-not-allowed');
                                btn.classList.remove('hover:text-green-600');
                                btn.onclick = null;
                            });

                            const buttonContainer = likeButton ? likeButton.parentElement : null;
                            if (buttonContainer) {
                                const warningMsg = document.createElement('p');
                                warningMsg.className = 'text-sm text-yellow-600 mt-3 text-center';
                                warningMsg.textContent = postStatus === 'pending' ?
                                    'โพสต์นี้กำลังรอการอนุมัติ ยังไม่สามารถไลค์หรือคอมเมนต์ได้' :
                                    'โพสต์นี้ถูกปฏิเสธ ไม่สามารถไลค์หรือคอมเมนต์ได้';
                                buttonContainer.parentElement.appendChild(warningMsg);
                            }
                        }, 100);
                    }
                }
            } catch (error) {
                console.error('Error opening post detail:', error);
            }
        };


       function resetSlot(slot){
  const { fi, container, img, empty, btn } = getSlotEls(slot);
  if (!container) return;

  // ล้าง objectURL เก่า (ถ้ามี)
  const url = img?.dataset?.objectUrl;
  if (url){ try{ URL.revokeObjectURL(url); }catch(e){} delete img.dataset.objectUrl; }

  if (img){ img.src=''; img.classList.add('hidden'); img.style.display='none'; }
  container.classList.remove('has-image');

  if (empty){ empty.classList.remove('hidden'); empty.style.display='flex'; }
  if (btn){
    btn.classList.add('hidden'); btn.style.display='none';
    btn.dataset.isExisting='0'; btn.dataset.filename='';
  }
  if (fi){
    try{ fi.value=''; }catch(e){
      const clone = fi.cloneNode(true); fi.replaceWith(clone);
    }
  }
}

// เพิ่มฟังก์ชัน updateCharCount ใน global scope หากยังไม่มี




        window.deletePost = async function(postId) {
            Swal.fire({
                title: 'คุณแน่ใจหรือไม่?',
                text: 'คุณต้องการลบโพสต์นี้หรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#d33',
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    try {
                        const formData = new FormData();
                        formData.append('postId', postId);

                        const response = await fetch('delete_post.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            allPosts = allPosts.filter(post => post.post_id != postId);
                            filteredPosts = filterPosts(currentFilter, searchQuery);

                            const totalPages = Math.ceil(filteredPosts.length / postsPerPage);
                            if (currentPage > totalPages && totalPages > 0) {
                                currentPage = totalPages;
                            }

                            renderPosts();

                            Swal.fire({
                                icon: 'success',
                                title: 'สำเร็จ',
                                text: 'ลบโพสต์เรียบร้อยแล้ว',
                                confirmButtonColor: '#059669'
                            });
                        } else {
                            throw new Error(result.message || 'เกิดข้อผิดพลาดในการลบโพสต์');
                        }
                    } catch (error) {
                        console.error('Error deleting post:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'เกิดข้อผิดพลาด: ' + error.message,
                            confirmButtonColor: '#059669'
                        });
                    }
                }
            });
        };

        document.getElementById('closePostDetailModal').addEventListener('click', function() {
            const modal = document.getElementById('postDetailModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        });

        document.getElementById('postDetailModal').addEventListener('click', function(event) {
            if (event.target === this) {
                this.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });

        // จัดการ Modal แก้ไขโปรไฟล์
        document.querySelectorAll('[data-bs-target="#profileEditModal"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const modal = new bootstrap.Modal(document.getElementById('profileEditModal'));
                modal.show();

                fetch('profile.php')
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const formSection = doc.querySelector('.max-w-4xl');
                        
                        if (formSection) {
                            document.getElementById('profileEditModalBody').innerHTML = formSection.innerHTML;
                            setupProfileForm();
                        } else {
                            throw new Error('ไม่พบส่วนฟอร์มใน response');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('profileEditModalBody').innerHTML = `
                            <div class="alert alert-danger p-4">
                                เกิดข้อผิดพลาดในการโหลดฟอร์ม: ${error.message}
                            </div>
                        `;
                    });
            });
        });

        function setupProfileForm() {
            const avatarContainer = document.querySelector('.profile-upload')?.parentElement;
            if (avatarContainer && !document.getElementById('randomAvatar')) {
                const randomBtn = document.createElement('button');
                randomBtn.id = 'randomAvatar';
                randomBtn.type = 'button';
                randomBtn.className = 'border border-gray-400 text-gray-600 rounded-full w-10 h-10 flex items-center justify-center hover:text-gray-800 hover:border-gray-600 mt-2';
                randomBtn.innerHTML = '<i class="fas fa-random"></i>';
                avatarContainer.appendChild(randomBtn);

                randomBtn.addEventListener('click', function() {
                    const seed = Math.random().toString(36).substring(7);
                    const previewImage = document.getElementById('previewImage');
                    previewImage.src = `https://api.dicebear.com/9.x/avataaars/svg?seed=${seed}`;
                });
            }

            const profileImage = document.getElementById('previewImage');
            const fileInput = document.getElementById('profileImageUpload');

            if (profileImage && fileInput) {
                profileImage.addEventListener('click', function() {
                    fileInput.click();
                });

                fileInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    if (file) {
                        if (file.size > 5 * 1024 * 1024) {
                            Swal.fire({
                                icon: 'error',
                                title: 'ไฟล์ใหญ่เกินไป',
                                text: 'ไฟล์ภาพต้องไม่เกิน 5MB',
                                confirmButtonColor: '#059669'
                            });
                            event.target.value = '';
                            return;
                        }

                        const reader = new FileReader();
                        reader.onload = function(e) {
                            profileImage.src = e.target.result;
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }

            const form = document.getElementById('profileForm');
            if (form) {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const formData = new FormData(this); // ต้องมี const ตรงนี้
                    const nameInput = document.getElementById('profileName');
                    const imageInput = document.getElementById('profileImageUpload');

                    formData.append('name', nameInput.value);

                    if (imageInput.files.length > 0) {
                        formData.append('profile_image', imageInput.files[0]);
                    } else if (profileImage.src.includes('dicebear')) {
                        const seed = profileImage.src.split('seed=')[1];
                        formData.append('avatarSeed', seed);
                    }

                    try {
                        const response = await fetch('update_profile.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'สำเร็จ',
                                text: 'อัปเดตโปรไฟล์เรียบร้อยแล้ว',
                                confirmButtonColor: '#059669'
                            }).then(() => {
                                bootstrap.Modal.getInstance(document.getElementById('profileEditModal')).hide();
                                if (result.newAvatarPath) {
                                    // อัปเดตรูปโปรไฟล์ในหน้านี้
                                    document.querySelector('.profile-avatar').src = result.newAvatarPath;
                                    // อัปเดตรูปบนแถบ nav แบบทันที และกันแคช
                                    if (window.updateNavAvatar) {
                                        window.updateNavAvatar(result.newAvatarPath);
                                    }
                                    // เผื่อหน้าอื่น ๆ ฟัง event นี้อยู่
                                    window.dispatchEvent(new CustomEvent('profile:avatarChanged', { detail: { url: result.newAvatarPath } }));
                                }
                                document.querySelector('.profile-avatar + div h2').textContent = result.name;
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: result.message || 'ไม่สามารถอัปเดตโปรไฟล์ได้',
                                confirmButtonColor: '#059669'
                            });
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                            confirmButtonColor: '#059669'
                        });
                    }
                });

                const cancelBtn = form.querySelector('button[type="button"][onclick^="window.location.href"]');
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function() {
                        bootstrap.Modal.getInstance(document.getElementById('profileEditModal')).hide();
                    });
                }
            }
        }
    });
    
</script>
    <?php include 'chat-button.php'; ?>
     
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!document.getElementById('editPostModal')) {
            console.error('Edit Post Modal not found in DOM after include');
        }
    });

</script>
   

<div id="imageViewer"
     class="fixed inset-0 hidden flex items-center justify-center bg-black bg-opacity-80 p-4"
     style="z-index: 12050;" role="dialog" aria-modal="true">
  <button id="imageViewerClose"
          class="absolute top-4 right-4 text-white text-4xl leading-none px-3 py-1 bg-black bg-opacity-40 rounded-full">
    &times;
  </button>
  <img id="imageViewerImg" class="max-w-[95vw] max-h-[90vh] rounded-lg shadow-2xl select-none" />
</div>
<?php include 'edit_post_modal.php'; ?>
<script>
// --- decode & detect ---
function decodeHtml(s){ return String(s||'')
  .replace(/&lt;/gi,'<').replace(/&gt;/gi,'>')
  .replace(/&amp;/gi,'&').replace(/&quot;/gi,'"').replace(/&#39;/g,"'"); }
function detectPlatform(u){
  try{const h=(new URL(u)).host.replace(/^www\./,'').toLowerCase();
    if(h.includes('tiktok.com')) return 'tiktok';
    if(h.includes('facebook.com')||h.includes('fb.watch')) return 'facebook';
    if(h.includes('youtube.com')||h.includes('youtu.be')) return 'youtube';
    if(h.includes('instagram.com')) return 'instagram'; return '';}catch{return '';}}

// --- parse โค้ดฝัง/URL ---
function parseEmbedOrUrl(input){
  const val = decodeHtml((input||'').trim());
  if(!val) return {url:'', platform:'', raw:''};
  if(val.includes('<') && val.includes('>')){
    // TikTok blockquote
    let m = val.match(/<blockquote[^>]*class="[^"]*tiktok-embed[^"]*"[^>]*cite="([^"]+)"/i);
    if(m) return {url:m[1], platform:'tiktok', raw:val};
    // Facebook fb-video
    m = val.match(/<div[^>]*class="[^"]*fb-video[^"]*"[^>]*data-href="([^"]+)"/i);
    if(m) return {url:m[1], platform:'facebook', raw:val};
    // Facebook plugin iframe (?href=...)
    m = val.match(/<iframe[^>]*src="([^"]+)"/i);
    if(m){
      try{
        const u = new URL(m[1], location.origin);
        const href = u.searchParams.get('href');
        if(href) return {url:decodeURIComponent(href), platform:'facebook', raw:val};
        return {url:m[1], platform:detectPlatform(m[1]), raw:val};
      }catch{ return {url:'', platform:'', raw:val}; }
    }
    // a[href]
    m = val.match(/href="([^"]+)"/i);
    if(m) return {url:m[1], platform:detectPlatform(m[1]), raw:val};
  }
  return {url:val, platform:detectPlatform(val), raw:val};
}

// --- ให้สคริปต์ TikTok พร้อม + parse ---
function ensureTikTokEmbedScriptAndParse(){
  if(!document.querySelector('script[data-tiktok-embed]')){
    const s=document.createElement('script');
    s.src='https://www.tiktok.com/embed.js'; s.async=true; s.setAttribute('data-tiktok-embed','1');
    document.body.appendChild(s);
  } else if (window.tiktokEmbedLoad){
    try { window.tiktokEmbedLoad(); } catch(_){}
  }
  setTimeout(()=>{ try { window.tiktokEmbedLoad && window.tiktokEmbedLoad(); } catch(_){} },0);
}

document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form'); if (form) form.setAttribute('novalidate','novalidate');
  const input = document.getElementById('video_url');
  const wrap  = document.getElementById('videoUrlPreviewWrap');
  const tag   = document.getElementById('videoPlatformTag');
  if(!input || !wrap || !tag) return;
  try{ if(input.type.toLowerCase()==='url') input.type='text'; }catch{}

  const render = () => {
    const {url, platform, raw} = parseEmbedOrUrl(input.value);
    tag.textContent = platform || '-';

    if(!url || !platform){ wrap.innerHTML=''; wrap.classList.add('hidden'); return; }

    if(platform==='tiktok'){
      // พรีวิวด้วย blockquote เสมอ (เสถียรสุดในเคสคุณ)
      const bq =
        (/<blockquote[^>]*tiktok-embed/i.test(raw) ? raw :
         `<blockquote class="tiktok-embed" cite="${url}" style="max-width:605px;min-width:325px;"><section></section></blockquote>`);
      wrap.innerHTML = bq; wrap.classList.remove('hidden');
      ensureTikTokEmbedScriptAndParse();
      return;
    }

    // Facebook
    if (/<iframe[^>]+facebook\.com\/plugins\/video\.php/i.test(raw)) {
      wrap.innerHTML = raw; wrap.classList.remove('hidden'); return;
    }
    if (platform==='facebook') {
      if(!document.getElementById('fb-root')){ const d=document.createElement('div'); d.id='fb-root'; document.body.appendChild(d); }
      wrap.innerHTML = `<div class="fb-video" data-href="${url}" data-width="auto" data-allowfullscreen="true"></div>`;
      wrap.classList.remove('hidden');
      if(!document.getElementById('facebook-jssdk')){
        const s=document.createElement('script'); s.id='facebook-jssdk'; s.async=true;
        s.src='https://connect.facebook.net/th_TH/sdk.js#xfbml=1&version=v19.0'; document.body.appendChild(s);
      } else if (window.FB?.XFBML?.parse) {
        setTimeout(()=>{ try{ FB.XFBML.parse(wrap); }catch(_){ } },0);
      }
      return;
    }

    // (YouTube/IG ตามโค้ดเดิมของคุณ)
    wrap.innerHTML=''; wrap.classList.add('hidden');
  };

  input.addEventListener('input', render);
  input.addEventListener('change', render);
  setTimeout(render, 0);

  const m = document.getElementById('wasteManagementModal');
  if (m && window.jQuery) window.jQuery(m).on('shown.bs.modal', render);
});
</script>
</body>
</html>
