<?php session_start() ?>

<!DOCTYPE html>
<html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>แชร์วิธีจัดการขยะ - ระบบจัดการขยะอัจฉริยะ</title>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="css/share.css" rel="stylesheet">
        <!-- เพิ่มลิงก์ Google Fonts สำหรับฟอนต์ Kanit -->
        <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
        <!-- เพิ่ม SweetAlert -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"></script>

<style>
    .group:hover .carousel-control {
    background-color: red; /* ใช้สีเพื่อทดสอบ */
}
.group:hover .group-hover\:opacity-100 {
    opacity: 1 !important;
}

    .carousel-control {
           opacity: 0;
        transition: opacity 0.3s ease;
        z-index: 10;
    }
    .carousel-item {
        transition: opacity 0.5s ease;
    }
    /* เพิ่มการกำหนดค่าเริ่มต้นให้ชัดเจน */
    .carousel-control {
        opacity: 0;
    }
    .modal-dialog {
        padding-top: 100px;
    }
    
.relative:hover #sortDropdownMenu {
    display: block;
}

#sortDropdownMenu {
    border: 1px solid #e5e7eb;
    right: 0;
}
    .modal-content * {
    pointer-events: auto;
}
#sortDropdownMenu {
    display: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
    opacity: 0;
    transform: translateY(-10px);
}

#sortDropdownMenu.show {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

/* ป้องกันการแสดงผลเมื่อ hover */
.relative:hover #sortDropdownMenu:not(.show) {
    display: none !important;
}

/* ===== Filters Bar Layout Fix ===== */
#filtersBar {
    background: #f3fbf5;
    border-bottom: 1px solid #e5efe6;
    padding: 12px 16px;
}

#filtersBar .hidden.lg\:flex {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    gap: 20px;
}

/* Left side - Filters */
#filtersBar .hidden.lg\:flex > div:first-child {
    display: flex;
    align-items: center;
    gap: 24px;
    flex: 1;
}

/* Filter items styling */
#filtersBar .filter-item {
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

#filtersBar .filter-label {
    color: #6b7280;
    font-weight: 500;
    font-size: 14px;
    min-width: 80px;
}

#filtersBar .filter-chip {
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #fff;
    min-width: 140px;
    font-size: 14px;
    cursor: pointer;
}

/* Right side - Search & Add Button */
#filtersBar .hidden.lg\:flex > div:last-child {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
}

/* Search input styling */
#filtersBar .search-input {
    width: 300px;
    padding: 10px 16px 10px 40px;
    border: 1px solid #d1d5db;
    border-radius: 22px;
    background: #fff;
    font-size: 14px;
}

#filtersBar .search-bar .fa-search {
    left: 14px;
    color: #6b7280;
}

/* Add button styling */
#filtersBar .green-circle {
    background-color: #218860;
    color: #ffffff;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 0.3s ease;
    flex-shrink: 0;
}

#filtersBar .green-circle:hover {
    background-color: rgb(24, 95, 68);
}

/* Sort dropdown specific styling */
#filtersBar #sortDropdownBtn.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    justify-content: space-between;
}

#sortDropdownMenu {
    border: 1px solid #e5e7eb;
    right: 0;
    z-index: 1060;
}

/* Responsive adjustments */
@media (max-width: 1280px) {
    #filtersBar .hidden.lg\:flex > div:first-child {
        gap: 16px;
    }
    
    #filtersBar .filter-label {
        min-width: 70px;
        font-size: 13px;
    }
    
    #filtersBar .filter-chip {
        min-width: 120px;
        padding: 8px 12px;
    }
    
    #filtersBar .search-input {
        width: 250px;
    }
}

@media (max-width: 1024px) {
    #filtersBar .hidden.lg\:flex {
        display: none !important;
    }
}

/* Ensure mobile layout is properly hidden on desktop */
@media (min-width: 1024px) {
    .lg\:hidden {
        display: none !important;
    }
}

/* Fix for select elements in filters */
#filtersBar select.filter-chip {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px;
    padding-right: 40px;
}

/* Hover states */
#filtersBar .filter-chip:hover {
    border-color: #218860;
}

#filtersBar .filter-chip:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(33, 136, 96, 0.1);
    border-color: #218860;
}
</style>
 
    
        <style>
            /* dropdown z-index fix */
            .dropdown-menu { z-index: 1060; }
        </style>
    <style>
/* ========== Video wrapper & fixed ratio ========== */
.video-embed{
  position:relative; width:100%; background:#000; border-radius:12px; overflow:hidden;
}
/* ค่าเริ่มต้นสำหรับการ์ดใน feed: ให้ทุกใบเท่ากัน (16:9) */
.video-embed::before{ content:""; display:block; padding-top:56.25%; }  /* 16:9 */
.video-embed > iframe, .video-embed > video{
  position:absolute; inset:0; width:100%; height:100%; border:0;
}

/* อัตราส่วนสำหรับ modal (จะใส่ class ให้ด้วย JS) */
.video-embed.ratio-16x9::before{ padding-top:56.25%; }   /* แนวนอน */
.video-embed.ratio-9x16::before{  padding-top:177.78%; } /* แนวตั้ง */
.video-embed.ratio-1x1::before{   padding-top:100%; }    /* สี่เหลี่ยมจัตุรัส */
.video-embed.ratio-4x5::before{   padding-top:125%; }    /* 4:5 แนวตั้ง */

/* โมดัลให้กว้างขึ้นพอดีจอ */
.modal .modal-dialog{ width:auto; max-width:min(92vw, 1100px); }
@media (max-width:768px){
  .modal .modal-dialog{ max-width:96vw; }
}
</style>
    </head>
    <body>
        <?php include 'nav.php'; ?>

  <div class="border-b px-3 py-4" id="filtersBar">
    <!-- Desktop Layout -->
    <div class="hidden lg:flex lg:flex-row lg:items-center lg:justify-between w-full">
        <!-- Left: Filters -->
        <div class="flex items-center gap-4 flex-nowrap"> <!-- เปลี่ยนจาก gap-6 เป็น gap-4 -->
            <!-- ประเภทขยะ -->
            <div class="filter-item flex items-center gap-1"> <!-- เปลี่ยนจาก gap-2 เป็น gap-1 -->
                <span class="filter-label text-sm text-gray-600 whitespace-nowrap">ขยะ:</span>
                <select id="wasteTypeSelect" data-type="select"
                    class="filter-chip border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
                    <option value="all">ทั้งหมด</option>
                    <option value="general">ทั่วไป</option>
                    <option value="recycle">รีไซเคิล</option>
                    <option value="hazardous">อันตราย</option>
                </select>
            </div>

            <!-- วิธีการจัดการ -->
            <div class="filter-item flex items-center gap-1"> <!-- เปลี่ยนจาก gap-2 เป็น gap-1 -->
                <span class="filter-label text-sm text-gray-600 whitespace-nowrap">วิธีการจัดการ:</span>
                <select id="managementTypeSelect" data-management-type="select"
                    class="filter-chip border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
                    <option value="all">ทั้งหมด</option>
                    <option value="recycle">รีไซเคิล</option>
                    <option value="reuse">นำกลับมาใช้ใหม่</option>
                    <option value="dispose">กังกลบ</option>
                </select>
            </div>

            <!-- จัดเรียง -->
            <div class="filter-item flex items-center gap-1"> <!-- เปลี่ยนจาก gap-2 เป็น gap-1 -->
                <span class="filter-label text-sm text-gray-600 whitespace-nowrap">จัดเรียง:</span>
                <div class="relative">
                    <button class="filter-chip flex items-center gap-1 px-3 py-2 text-sm border border-gray-300 rounded-lg" id="sortDropdownBtn">
                        <i class="fas fa-sort text-gray-600"></i>
                        <span id="sortLabel">ล่าสุด</span>
                        <i class="fas fa-chevron-down text-xs ml-1"></i> <!-- เพิ่ม ml-1 -->
                    </button>
                    <div class="absolute hidden mt-1 w-48 bg-white rounded-md shadow-lg z-10 border" id="sortDropdownMenu">
                        <div class="py-1">
                            <a href="#" class="sort-option block px-4 py-2 text-sm hover:bg-gray-100" data-sort="date" data-dir="desc">ล่าสุด</a>
                            <a href="#" class="sort-option block px-4 py-2 text-sm hover:bg-gray-100" data-sort="date" data-dir="asc">เก่าสุด</a>
                            <a href="#" class="sort-option block px-4 py-2 text-sm hover:bg-gray-100" data-sort="title" data-dir="asc">ชื่อ ก-ฮ</a>
                            <a href="#" class="sort-option block px-4 py-2 text-sm hover:bg-gray-100" data-sort="title" data-dir="desc">ชื่อ ฮ-ก</a>
                            <a href="#" class="sort-option block px-4 py-2 text-sm hover:bg-gray-100" data-sort="likes" data-dir="desc">ยอดถูกใจ</a>
                            <a href="#" class="sort-option block px-4 py-2 text-sm hover:bg-gray-100" data-sort="comments" data-dir="desc">ยอดความคิดเห็น</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Search & Add Button -->
        <div class="flex items-center gap-3 flex-shrink-0"> <!-- เปลี่ยนจาก gap-4 เป็น gap-3 -->
            <div class="search-bar">
                <div class="relative">
                    <input type="text" class="search-input" placeholder="ค้นหาจากชื่อโพสต์/ชื่อผู้โพสต์/รายละเอียด...">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                </div>
            </div>
            <a href="#" id="addPostButton" data-bs-toggle="modal" data-bs-target="#wasteManagementModal">
                <button class="green-circle flex items-center justify-center">
                    <i class="fas fa-plus"></i>
                </button>
            </a>
        </div>
    </div>
    
    <!-- Mobile Layout -->
    <div class="lg:hidden flex flex-col gap-3">
        <div class="flex items-center justify-between w-full">
            <div class="flex gap-2 flex-wrap w-full">
                <!-- ประเภทขยะ (Mobile) -->
                <select id="wasteTypeSelectMobile" data-type="select"
                    class="filter-chip border border-gray-300 rounded-lg px-3 py-2 text-sm w-full sm:w-auto">
                    <option value="all">ทั้งหมด</option>
                    <option value="general">ทั่วไป</option>
                    <option value="recycle">รีไซเคิล</option>
                    <option value="hazardous">อันตราย</option>
                </select>

                <!-- วิธีการจัดการ (Mobile) -->
                <select id="managementTypeSelectMobile" data-management-type="select"
                    class="filter-chip border border-gray-300 rounded-lg px-3 py-2 text-sm w-full sm:w-auto">
                    <option value="all">ทั้งหมด</option>
                    <option value="recycle">รีไซเคิล</option>
                    <option value="reuse">นำกลับมาใช้ใหม่</option>
                    <option value="dispose">กังกลบ</option>
                </select>

                <!-- จัดเรียง (Mobile) -->
                <select id="sortSelectMobile" class="filter-chip border border-gray-300 rounded-lg px-3 py-2 text-sm w-full sm:w-auto">
                    <option value="date-desc">ล่าสุด</option>
                    <option value="date-asc">เก่าสุด</option>
                    <option value="title-asc">ชื่อ ก-ฮ</option>
                    <option value="title-desc">ชื่อ ฮ-ก</option>
                    <option value="likes-desc">ยอดถูกใจ</option>
                    <option value="comments-desc">ยอดความคิดเห็น</option>
                </select>
            </div>
        </div>
        
        <div class="flex items-center gap-3 w-full">
            <div class="search-bar w-full">
                <div class="relative w-full">
                    <input type="text" class="search-input w-full" placeholder="ค้นหาจากชื่อโพสต์/ชื่อผู้โพสต์/รายละเอียด...">
                    <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                </div>
            </div>
            <a href="#" id="addPostButtonMobile" data-bs-toggle="modal" data-bs-target="#wasteManagementModal">
                <button class="green-circle flex items-center justify-center">
                    <i class="fas fa-plus"></i>
                </button>
            </a>
        </div>
    </div>
</div>

        <!-- กริดแสดงโพสต์ -->
        <div class="share-grid" id="wasteGrid">
            <!-- การ์ดจะถูกเพิ่มที่นี่ด้วย JavaScript -->
        </div>
        <!-- เพิ่มส่วน Pagination -->
        <div class="pagination-controls flex justify-center items-center gap-4 py-6">
            <button class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50"
                    id="prevPage">
                <i class="fas fa-chevron-left"></i> ก่อนหน้า
            </button>
            
            <span class="current-page font-semibold text-green-700" id="currentPage">
                หน้า 1
            </span>
        
            <button class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50"
                    id="nextPage">
                ถัดไป <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <?php include 'chat-button.php'; ?>
        <?php include 'edit_post_modal.php'; ?>
        
        <script type="module">
    // นำเข้า wasteManager
    import wasteManager from './waste-management.js';
    // ทำให้ wasteManager เป็น global ทันทีที่โหลดไฟล์
    window.wasteManager = wasteManager;

    // รอให้ DOM โหลดเสร็จก่อน
    document.addEventListener("DOMContentLoaded", async function () {
        // ดึงและแสดงโพสต์
        await wasteManager.fetchPosts();
        await wasteManager.renderPosts();
        wasteManager.bindSortControls();
        // เผื่อกรณีสคริปต์โหลดต่างลำดับ ให้ผูกตัวควบคุมการจัดเรียงที่มาจาก waste-management.js
        if (window.wasteManager && typeof window.wasteManager.bindSortControls === 'function') {
            window.wasteManager.bindSortControls();
        }

    
        // จัดการกับปุ่มกรองบนเดสก์ท็อป
        document.querySelectorAll('.filter-chip[data-type]').forEach(button => {
            if (button.tagName === 'BUTTON') {
                button.addEventListener('click', function() {
                    const type = this.getAttribute('data-type');
                    window.wasteManager.filterWaste(type);
                });
            }
        });

        // จัดการกับปุ่มกรองประเภทการจัดการ
        document.querySelectorAll('.filter-chip[data-management-type]').forEach(button => {
            if (button.tagName === 'BUTTON') {
                button.addEventListener('click', function() {
                    const type = this.getAttribute('data-management-type');
                    window.wasteManager.filterManagement(type);
                });
            }
        });

        // จัดการกับ dropdowns บนมือถือ (bind ทุกตัว ไม่ใช่ตัวแรก)
        try {
            const currentWaste = (window.wasteManager?.currentFilter?.wasteType) || 'all';
            const currentMgmt  = (window.wasteManager?.currentFilter?.managementType) || 'all';

            document.querySelectorAll('select[data-type="select"]').forEach(sel => {
                try { sel.value = currentWaste; } catch(e){}
                sel.addEventListener('change', (e) => {
                    window.wasteManager.filterWaste(e.target.value);
                });
            });
            document.querySelectorAll('select[data-management-type="select"]').forEach(sel => {
                try { sel.value = currentMgmt; } catch(e){}
                sel.addEventListener('change', (e) => {
                    window.wasteManager.filterManagement(e.target.value);
                });
            });
        } catch (e) { console.warn('mobile filter binds failed', e); }

        // จัดการกับการจัดเรียง (อัปเดต label เท่านั้น ปล่อยให้ waste-management.js จัดการ logic)
        const sortSelect = document.getElementById('sortSelect');
        const sortLabelEl = document.getElementById('sortLabel');
        const sortOptions = document.querySelectorAll('.sort-option');
        const updateSortLabel = (text) => { if (sortLabelEl && text) sortLabelEl.textContent = text; };
        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                const selectedText = this.options[this.selectedIndex]?.text?.replace('จัดเรียง: ','') || '';
                updateSortLabel(selectedText);
            });
        }
        if (sortOptions && sortOptions.length) {
            sortOptions.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const label = this.textContent?.trim() || '';
                    updateSortLabel(label);
                    try {
                        const dd = bootstrap.Dropdown.getOrCreateInstance(document.getElementById('sortDropdownBtn'));
                        dd.hide();
                    } catch(e){}
                });
            });
        }
    
        // จัดการกับการค้นหา
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    window.wasteManager.searchPosts(e.target.value);
                }, 300);
            });
        }
        
        // เพิ่ม: จัดการกับปุ่มเปลี่ยนหน้า
        const prevPageBtn = document.getElementById('prevPage');
        const nextPageBtn = document.getElementById('nextPage');
        
        if (prevPageBtn) {
            prevPageBtn.addEventListener('click', function() {
                console.log('Previous page button clicked');
                window.wasteManager.changePage(-1);
            });
        }
        
        if (nextPageBtn) {
            nextPageBtn.addEventListener('click', function() {
                console.log('Next page button clicked');
                window.wasteManager.changePage(1);
            });
        }
    });
</script>
<script src="wm_tiktok_iframe_patch.js"></script>

<script>
  // จำกัดฝั่งหน้าเว็บ (อย่ามากกว่า post_max_size)
  (function () {
    var MAX_BYTES = 200 * 1024 * 1024; // 200MB
    var fileInput = document.getElementById('video_file'); // id ของ input ไฟล์ใน modal

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) return;
        if (f.size > MAX_BYTES) {
          alert('ไฟล์วิดีโอใหญ่เกิน 200MB — กรุณาเลือกไฟล์ที่เล็กลง');
          fileInput.value = '';
        }
      });
    }
  })();
</script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const wasteSel = document.getElementById('wasteTypeSelect');
  const mgmtSel  = document.getElementById('managementTypeSelect');

  if (wasteSel) {
    // sync ค่าเริ่มต้นตาม state ปัจจุบัน
    try { wasteSel.value = (window.wasteManager?.currentFilter?.wasteType) || 'all'; } catch(e){}
    wasteSel.addEventListener('change', e => window.wasteManager.filterWaste(e.target.value));
  }
  if (mgmtSel) {
    try { mgmtSel.value = (window.wasteManager?.currentFilter?.managementType) || 'all'; } catch(e){}
    mgmtSel.addEventListener('change', e => window.wasteManager.filterManagement(e.target.value));
  }

  // เผื่อโหลดลำดับสคริปต์ไม่แน่นอน ให้เรียก bind อีกชั้น
  if (window.wasteManager && typeof window.wasteManager.bindFilterControls === 'function') {
    window.wasteManager.bindFilterControls();
  }
});
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
    const dropdownBtn = document.getElementById('sortDropdownBtn');
    const dropdownMenu = document.getElementById('sortDropdownMenu');
    
    if (dropdownBtn && dropdownMenu) {
        // เมื่อคลิกปุ่ม ให้แสดง/ซ่อน dropdown
        dropdownBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdownMenu.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!dropdownBtn.contains(e.target) && !dropdownMenu.contains(e.target)) {
                dropdownMenu.classList.remove('show');
            }
        });
        
        const sortOptions = document.querySelectorAll('.sort-option');
        sortOptions.forEach(option => {
            option.addEventListener('click', function() {
                dropdownMenu.classList.remove('show');
            });
        });
        
        // ป้องกันการ bubbling เมื่อคลิกใน dropdown
        dropdownMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
});
    // ตรวจสอบการระงับการโพสต์เมื่อคลิกปุ่มเพิ่มโพสต์ใหม่
    document.addEventListener('DOMContentLoaded', function() {
        const addPostButton = document.getElementById('addPostButton');
        
        if (addPostButton) {
            addPostButton.addEventListener('click', async function(event) {
                try {
                    // แสดง loading
                    Swal.fire({
                        title: 'กำลังตรวจสอบ...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // เรียกใช้ API ตรวจสอบการระงับ
                    const response = await fetch('check_post_restriction.php');
                    const data = await response.json();
                    
                    // ปิด loading
                    Swal.close();
                    
                    if (!data.canPost) {
                        // ถ้าถูกระงับ แสดง SweetAlert แจ้งเตือน
                        Swal.fire({
                            icon: 'error',
                            title: 'การโพสต์ถูกระงับ',
                            text: data.message,
                            confirmButtonColor: '#4CAF50'
                        });
                    } else {
                        // ถ้าไม่ถูกระงับ ให้นำทางไปยังหน้าโพสต์
                        window.location.href = '#';
                    }
                } catch (error) {
                    console.error('เกิดข้อผิดพลาดในการตรวจสอบการระงับการโพสต์:', error);
                    
                    // ปิด loading
                    Swal.close();
                    
                    // แสดงข้อผิดพลาด
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'ไม่สามารถตรวจสอบสถานะการโพสต์ได้ในขณะนี้',
                        confirmButtonColor: '#4CAF50'
                    });
                }
            });
        }
    });
</script>
        
<script src="modal-video-fixes.js"></script>
<link rel="stylesheet" href="css/components/video.css">

<script>
/* EMBED REPARSE HOOK */
document.addEventListener('DOMContentLoaded', function(){
  var m = document.getElementById('postDetailModal');
  if (m && window.jQuery) {
    window.jQuery(m).on('shown.bs.modal', function(){
      // TikTok
      if (window.tiktokEmbedLoad) { try { window.tiktokEmbedLoad(); } catch(_){} }
      // Facebook
      if (window.FB && window.FB.XFBML && window.FB.XFBML.parse) {
        try { FB.XFBML.parse(m); } catch(_){}
      }
    });
  }
});
</script>
<script>
// ให้ TikTok blockquote ในโมดอล parse ทุกครั้งที่เปิด
(function(){
  function ensureTikTokEmbedScriptAndParse(){
    if (!document.querySelector('script[data-tiktok-embed]')) {
      const s = document.createElement('script');
      s.src = 'https://www.tiktok.com/embed.js';
      s.async = true;
      s.setAttribute('data-tiktok-embed','1');
      document.body.appendChild(s);
    } else if (window.tiktokEmbedLoad) {
      try { window.tiktokEmbedLoad(); } catch(_){}
    }
    setTimeout(()=>{ try { window.tiktokEmbedLoad && window.tiktokEmbedLoad(); } catch(_){} },0);
  }

  document.addEventListener('DOMContentLoaded', function(){
    var modal = document.getElementById('postDetailModal'); // id โมดอลรายละเอียดของคุณ
    if (modal && window.jQuery) {
      window.jQuery(modal).on('shown.bs.modal', function(){
        ensureTikTokEmbedScriptAndParse();
      });
    } else {
      // fallback ถ้าไม่ได้ใช้ bootstrap modal
      ensureTikTokEmbedScriptAndParse();
    }
  });
})();
</script>
</body>
</html>
