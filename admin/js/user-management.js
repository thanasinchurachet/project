// ฟังก์ชันสำหรับจัดการผู้ใช้ในระบบ BinBuddy

// สำหรับการระงับการใช้งานผู้ใช้แบบต่างๆ
async function suspendUserAction(userId, suspensionType, reason, duration) {
    try {
        // เตรียมข้อมูลสำหรับส่งไปที่ API
        const data = {
            userId: userId,
            suspensionType: suspensionType, // 'full', 'post', 'comment', 'both'
            reason: reason,
            duration: duration, // จำนวนวันหรือ 'permanent'
            notify: true
        };

        // ส่งคำขอไปยัง API
        const response = await fetch('api/suspend_user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถระงับผู้ใช้ได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถระงับผู้ใช้ได้');
        }

        // คืนค่าผลลัพธ์
        return result;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error suspending user:', error);
        throw error;
    }
}

// สำหรับยกเลิกการระงับการใช้งานผู้ใช้
async function unsuspendUser(userId) {
    try {
        // เตรียมข้อมูลสำหรับส่งไปที่ API
        const data = {
            userId: userId,
            notify: true
        };

        // ส่งคำขอไปยัง API
        const response = await fetch('api/unsuspend_user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถยกเลิกการระงับผู้ใช้ได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถยกเลิกการระงับผู้ใช้ได้');
        }

        // คืนค่าผลลัพธ์
        return result;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error unsuspending user:', error);
        throw error;
    }
}

// สำหรับจำกัดการใช้งานผู้ใช้ (เฉพาะการโพสต์หรือคอมเมนต์)
async function restrictUserFeatures(userId, featureType, reason, duration) {
    try {
        // เตรียมข้อมูลสำหรับส่งไปที่ API
        const data = {
            userId: userId,
            featureType: featureType, // 'post', 'comment', 'both'
            reason: reason,
            duration: duration, // จำนวนวัน
            notify: true
        };

        // ส่งคำขอไปยัง API
        const response = await fetch('api/restrict_user_features.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถจำกัดการใช้งานผู้ใช้ได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถจำกัดการใช้งานผู้ใช้ได้');
        }

        // คืนค่าผลลัพธ์
        return result;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error restricting user features:', error);
        throw error;
    }
}

// สำหรับดึงข้อมูลผู้ใช้ทั้งหมด
async function getAllUsers(page = 1, limit = 10, searchTerm = '', status = 'all') {
    try {
        // สร้าง URL พร้อมพารามิเตอร์
        const url = new URL('api/get_users.php', window.location.origin);
        url.searchParams.append('page', page);
        url.searchParams.append('limit', limit);
        
        if (searchTerm) {
            url.searchParams.append('search', searchTerm);
        }
        
        if (status !== 'all') {
            url.searchParams.append('status', status);
        }

        // ส่งคำขอไปยัง API
        const response = await fetch(url.toString());

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถดึงข้อมูลผู้ใช้ได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถดึงข้อมูลผู้ใช้ได้');
        }

        // คืนค่าผลลัพธ์
        return result.data;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error fetching users:', error);
        throw error;
    }
}

// สำหรับดึงข้อมูลการถูกระงับของผู้ใช้
async function getUserSuspensionHistory(userId) {
    try {
        // สร้าง URL พร้อมพารามิเตอร์
        const url = new URL('api/get_user_suspension_history.php', window.location.origin);
        url.searchParams.append('userId', userId);

        // ส่งคำขอไปยัง API
        const response = await fetch(url.toString());

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถดึงประวัติการระงับได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถดึงประวัติการระงับได้');
        }

        // คืนค่าผลลัพธ์
        return result.data;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error fetching user suspension history:', error);
        throw error;
    }
}

// สำหรับดึงข้อมูลกิจกรรมของผู้ใช้
async function getUserActivity(userId) {
    try {
        // สร้าง URL พร้อมพารามิเตอร์
        const url = new URL('api/get_user_activity.php', window.location.origin);
        url.searchParams.append('userId', userId);

        // ส่งคำขอไปยัง API
        const response = await fetch(url.toString());

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถดึงข้อมูลกิจกรรมผู้ใช้ได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถดึงข้อมูลกิจกรรมผู้ใช้ได้');
        }

        // คืนค่าผลลัพธ์
        return result.data;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error fetching user activity:', error);
        throw error;
    }
}

// สำหรับส่งการแจ้งเตือนไปยังผู้ใช้
async function sendUserNotification(userId, message, type = 'info') {
    try {
        // เตรียมข้อมูลสำหรับส่งไปที่ API
        const data = {
            userId: userId,
            message: message,
            type: type // 'info', 'warning', 'error', 'success'
        };

        // ส่งคำขอไปยัง API
        const response = await fetch('api/send_notification.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        // ตรวจสอบสถานะการตอบกลับ
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'ไม่สามารถส่งการแจ้งเตือนได้');
        }

        // แปลงการตอบกลับเป็น JSON
        const result = await response.json();

        // ตรวจสอบผลลัพธ์
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถส่งการแจ้งเตือนได้');
        }

        // คืนค่าผลลัพธ์
        return result;
    } catch (error) {
        // จัดการข้อผิดพลาด
        console.error('Error sending notification:', error);
        throw error;
    }
}

// ฟังก์ชันช่วยในการแปลงวันที่เป็นรูปแบบไทย
function formatThaiDate(dateString) {
    if (!dateString || dateString === 'permanent') return dateString === 'permanent' ? 'ถาวร' : '-';
    
    const options = { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    
    try {
        return new Date(dateString).toLocaleDateString('th-TH', options);
    } catch (error) {
        console.error('Error formatting date:', error);
        return dateString;
    }
}

// ฟังก์ชันแสดงระยะเวลาที่ผ่านมา (Time ago)
function getTimeAgo(dateString) {
    if (!dateString) return '-';
    
    const now = new Date();
    const past = new Date(dateString);
    const diffMs = now - past;
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHour = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHour / 24);
    
    if (diffDay > 30) {
        const diffMonth = Math.floor(diffDay / 30);
        return `${diffMonth} เดือนที่แล้ว`;
    } else if (diffDay > 0) {
        return `${diffDay} วันที่แล้ว`;
    } else if (diffHour > 0) {
        return `${diffHour} ชั่วโมงที่แล้ว`;
    } else if (diffMin > 0) {
        return `${diffMin} นาทีที่แล้ว`;
    } else {
        return 'เมื่อสักครู่';
    }
}

// ฟังก์ชัน debounce สำหรับการค้นหา
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            func.apply(this, args);
        }, wait);
    };
}
/**
 * ฟังก์ชั่นสำหรับการจัดการผู้ใช้งาน
 */

// เปิด Modal ยกเลิกการระงับบัญชี
window.openUnsuspendModal = function(userId) {
    console.log('Opening unsuspend modal for user ID:', userId);
    const user = allUsers.find(u => u.userID == userId);
    if (!user) {
        showError('ไม่พบข้อมูลผู้ใช้');
        return;
    }
    
    document.getElementById('unsuspendUserId').value = userId;
    document.getElementById('unsuspendUserName').textContent = user.name;
    
    // แสดง Modal
    document.getElementById('unsuspendModal').classList.remove('hidden');
};

// ยกเลิกการระงับบัญชีผู้ใช้
function unsuspendUser() {
    const userId = document.getElementById('unsuspendUserId').value;
    const notify = document.getElementById('notifyUnsuspendUser').checked;
    
    console.log('Executing unsuspend for user ID:', userId, 'Notify:', notify);
    
    if (!userId) {
        showError('ไม่พบข้อมูลผู้ใช้');
        return;
    }
    
    // แสดง loading
    document.getElementById('loadingSpinner').classList.remove('hidden');
    
    // เรียกใช้ API ยกเลิกการระงับบัญชี
    fetch('api/unsuspend_user.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ userId, notify })
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(result => {
        console.log('API response:', result);
        
        // ปิด loading
        document.getElementById('loadingSpinner').classList.add('hidden');
        
        if (!result.success) {
            throw new Error(result.message || 'ไม่สามารถยกเลิกการระงับบัญชีได้');
        }
        
        // อัปเดตข้อมูลในตาราง
        const userIndex = allUsers.findIndex(u => u.userID == userId);
        if (userIndex !== -1) {
            allUsers[userIndex].status = 'active';
            
            // อัปเดตการแสดงผล
            filteredUsers = [...allUsers];
            filterUsers();
            updateUserStats();
            
            // แสดงข้อความสำเร็จ
            const userName = allUsers[userIndex].name;
            showSuccess(`ยกเลิกการระงับบัญชีของ ${userName} สำเร็จแล้ว`);
        }
        
        // ปิด Modal
        closeAllModals();
    })
    .catch(error => {
        // ปิด loading
        document.getElementById('loadingSpinner').classList.add('hidden');
        console.error('Error unsuspending user:', error);
        showError(error.message || 'ไม่สามารถยกเลิกการระงับบัญชีได้');
    });
}

// Set up unsuspend user modal when document is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Set up unsuspend user modal button
    const confirmUnsuspendBtn = document.getElementById('confirmUnsuspend');
    if (confirmUnsuspendBtn) {
        // Remove any existing event listeners
        const newConfirmBtn = confirmUnsuspendBtn.cloneNode(true);
        confirmUnsuspendBtn.parentNode.replaceChild(newConfirmBtn, confirmUnsuspendBtn);
        
        // Add event listener
        newConfirmBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Unsuspend button clicked');
            unsuspendUser();
        });
    }
});

// ปิด Modal เมื่อคลิกปุ่มปิดหรือคลิกนอก Modal
document.addEventListener('DOMContentLoaded', function() {
    // Close modals when clicking close button or outside
    document.querySelectorAll('.close-modal').forEach(button => {
        button.addEventListener('click', function() {
            closeAllModals();
        });
    });
    
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAllModals();
            }
        });
    });
});