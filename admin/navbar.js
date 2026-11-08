// ฟังก์ชันโหลด navbar
async function loadNavbar() {
    try {
        const response = await fetch('navbar.html');
        const html = await response.text();
        document.getElementById('navbar-container').innerHTML = html;
        console.log('Navbar loaded successfully');
        
        // ดึงข้อมูลการแจ้งเตือนหลังจากโหลด navbar เสร็จ
        fetchNotifications();
        
        // ไฮไลต์เมนูที่กำลังใช้งาน
        highlightActiveMenu();
    } catch (error) {
        console.error('Error loading navbar:', error);
    }
}

// ฟังก์ชันเปิด/ปิดการแจ้งเตือน
window.toggleNotifications = function() {
    var dropdown = document.getElementById('notification-dropdown');
    var profileDropdown = document.getElementById('profile-dropdown');
    
    // ปิด profile dropdown ถ้าเปิดอยู่
    if(profileDropdown) {
        profileDropdown.style.display = 'none';
    }
    
    // สลับการแสดงผล notification dropdown
    if(dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        dropdown.style.display = 'block';
    }
};

// ฟังก์ชันเปิด/ปิดโปรไฟล์
window.toggleProfile = function() {
    var dropdown = document.getElementById('profile-dropdown');
    var notificationDropdown = document.getElementById('notification-dropdown');
    
    // ปิด notification dropdown ถ้าเปิดอยู่
    if(notificationDropdown) {
        notificationDropdown.style.display = 'none';
    }
    
    // สลับการแสดงผล profile dropdown
    if(dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        dropdown.style.display = 'block';
    }
};

// ฟังก์ชันเปิด/ปิด sidebar บนมือถือ
window.toggleSidebar = function() {
    var sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('hidden');
    sidebar.classList.toggle('md:block');
};

// ฟังก์ชันไฮไลต์เมนูที่กำลังใช้งาน
function highlightActiveMenu() {
    const currentPath = window.location.pathname;
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (currentPath.includes(href) || 
            (currentPath.endsWith('/admin/') && href === 'admin_dashboard.html')) {
            item.classList.add('active-nav-item');
        }
    });
}

// ฟังก์ชันดึงข้อมูลการแจ้งเตือน
async function fetchNotifications() {
    try {
        // ดึงข้อมูลจาก API
        const response = await fetch('get_admin_notifications.php');
        const data = await response.json();
        
        // อัปเดตการแสดงผล
        updateNotifications(data);
        
        // อัปเดตทุก 3 นาที
        setTimeout(fetchNotifications, 3 * 60 * 1000);
    } catch (error) {
        console.error('Error fetching notifications:', error);
    }
}

// ฟังก์ชันอัปเดตการแจ้งเตือน
function updateNotifications(data) {
    // อ้างอิงองค์ประกอบ
    const notificationList = document.getElementById('notification-list');
    const notificationCount = document.getElementById('notification-count');
    const sidebarPendingPosts = document.getElementById('sidebar-pending-posts');
    const sidebarPendingComments = document.getElementById('sidebar-pending-comments');
    
    // ดึงข้อมูลโพสต์และคอมเมนต์
    const postNotification = data.find(item => item.type === 'โพสต์');
    const commentNotification = data.find(item => item.type === 'คอมเมนต์');
    
    // ดึงจำนวนรายการ
    let pendingPosts = 0;
    let pendingComments = 0;
    
    if (postNotification) {
        const match = postNotification.message.match(/\d+/);
        if (match) pendingPosts = parseInt(match[0]);
    }
    
    if (commentNotification) {
        const match = commentNotification.message.match(/\d+/);
        if (match) pendingComments = parseInt(match[0]);
    }
    
    // รวมจำนวนรายการทั้งหมด
    const totalPending = pendingPosts + pendingComments;
    
    // อัปเดตการแสดงผล
    if (notificationCount) {
        notificationCount.textContent = totalPending;
        notificationCount.style.display = totalPending > 0 ? 'flex' : 'none';
    }
    
    if (sidebarPendingPosts) {
        sidebarPendingPosts.textContent = pendingPosts;
        sidebarPendingPosts.style.display = pendingPosts > 0 ? 'inline-flex' : 'none';
    }
    
    if (sidebarPendingComments) {
        sidebarPendingComments.textContent = pendingComments;
        sidebarPendingComments.style.display = pendingComments > 0 ? 'inline-flex' : 'none';
    }
    
    // อัปเดตรายการแจ้งเตือน
    if (notificationList) {
        notificationList.innerHTML = '';
        
        // กรองเฉพาะรายการที่ต้องดำเนินการ (pendingPosts และ pendingComments > 0)
        const pendingItems = data.filter(item => {
            if (item.type === 'โพสต์') {
                const match = item.message.match(/\d+/);
                return match && parseInt(match[0]) > 0;
            } else if (item.type === 'คอมเมนต์') {
                const match = item.message.match(/\d+/);
                return match && parseInt(match[0]) > 0;
            }
            return false;
        });
        
        if (pendingItems.length > 0) {
            pendingItems.forEach(item => {
                const element = document.createElement('a');
                
                if (item.type === 'โพสต์') {
                    element.href = 'manage_posts.html?status=pending';
                    element.innerHTML = `
                        <div class="p-3 hover:bg-gray-50">
                            <div class="flex items-center">
                                <div class="bg-yellow-100 p-2 rounded-full mr-3">
                                    <i class="fas fa-clipboard-check text-yellow-600"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-800">${item.message}</p>
                                    <p class="text-xs text-gray-500">${formatTimeAgo(item.created_at)}</p>
                                </div>
                            </div>
                        </div>
                    `;
                } else if (item.type === 'คอมเมนต์') {
                    element.href = 'manage_comment.html?status=pending';
                    element.innerHTML = `
                        <div class="p-3 hover:bg-gray-50">
                            <div class="flex items-center">
                                <div class="bg-red-100 p-2 rounded-full mr-3">
                                    <i class="fas fa-flag text-red-600"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-800">${item.message}</p>
                                    <p class="text-xs text-gray-500">${formatTimeAgo(item.created_at)}</p>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                notificationList.appendChild(element);
            });
        } else {
            notificationList.innerHTML = `
                <div class="p-4 text-center text-gray-500">
                    <i class="fas fa-check-circle text-green-500 text-xl mb-2"></i>
                    <p class="text-sm">ไม่มีรายการที่รอดำเนินการ</p>
                </div>
            `;
        }
        
        // ซ่อน/แสดงไอคอนการแจ้งเตือนตามความเหมาะสม
        const notificationBtn = document.getElementById('notification-btn');
        if (notificationBtn) {
            // ถ้าไม่มีรายการที่ต้องดำเนินการ ให้ซ่อนจุดแดงที่แสดงจำนวนการแจ้งเตือน
            const notificationIndicator = notificationBtn.querySelector('#notification-count');
            if (notificationIndicator) {
                notificationIndicator.style.display = totalPending > 0 ? 'flex' : 'none';
            }
        }
    }
}
// ฟังก์ชันเปิด/ปิด sidebar บนมือถือ และสลับไอคอนลูกศร
window.toggleSidebar = function() {
    var sidebar = document.getElementById('sidebar');
    var arrowIcon = document.getElementById('toggle-sidebar-arrow').querySelector('i');
    sidebar.classList.toggle('hidden');
    sidebar.classList.toggle('md:block');
    // สลับไอคอนลูกศร
    if (sidebar.classList.contains('hidden')) {
        arrowIcon.classList.remove('fa-chevron-left');
        arrowIcon.classList.add('fa-chevron-right');
    } else {
        arrowIcon.classList.remove('fa-chevron-right');
        arrowIcon.classList.add('fa-chevron-left');
    }
};

// ฟังก์ชัน toggleSidebarArrow (เรียกใช้ toggleSidebar)
window.toggleSidebarArrow = function() {
    toggleSidebar();
};
// ฟังก์ชันแปลงวันที่เป็นข้อความ "เมื่อ X นาทีที่แล้ว"
function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMinutes = Math.floor((now - date) / (1000 * 60));
    
    if (diffMinutes < 1) return 'เมื่อสักครู่';
    if (diffMinutes < 60) return `${diffMinutes} นาทีที่แล้ว`;
    if (diffMinutes < 1440) return `${Math.floor(diffMinutes / 60)} ชั่วโมงที่แล้ว`;
    return `${Math.floor(diffMinutes / 1440)} วันที่แล้ว`;
}

// เพิ่ม event listener เพื่อปิด dropdown เมื่อคลิกที่อื่น
document.addEventListener('click', function(event) {
    var notificationBtn = document.getElementById('notification-btn');
    var profileBtn = document.getElementById('profile-btn');
    var notificationDropdown = document.getElementById('notification-dropdown');
    var profileDropdown = document.getElementById('profile-dropdown');
    
    // ถ้าคลิกไม่ใช่ปุ่มแจ้งเตือนและไม่ใช่ dropdown
    if (notificationBtn && notificationDropdown && 
        event.target !== notificationBtn && 
        !notificationBtn.contains(event.target) && 
        !notificationDropdown.contains(event.target)) {
        notificationDropdown.style.display = 'none';
    }
    
    // ถ้าคลิกไม่ใช่ปุ่มโปรไฟล์และไม่ใช่ dropdown
    if (profileBtn && profileDropdown && 
        event.target !== profileBtn && 
        !profileBtn.contains(event.target) && 
        !profileDropdown.contains(event.target)) {
        profileDropdown.style.display = 'none';
    }
});

// เรียกใช้ฟังก์ชันโหลด navbar เมื่อโหลดเอกสาร
document.addEventListener('DOMContentLoaded', loadNavbar);