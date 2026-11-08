<?php
require_once 'nav.php';

// ดึงข้อมูลผู้ใช้เพิ่มเติม
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE userID = ?");
    $stmt->execute([$_SESSION['userid']]);
    $user_profile = $stmt->fetch();
} catch (PDOException $e) {
    die("เกิดข้อผิดพลาด: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bin Buddy - แก้ไขโปรไฟล์</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js"></script>
    <style>
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
        body {
            font-family: 'Kanit', sans-serif;
            background-color: #f0f9f0;
        }

        .profile-header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
            opacity: 0.1;
        }

        .form-section {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .profile-upload {
            position: relative;
            cursor: pointer;
            transition: all 0.3s;
        }

        .profile-upload:hover {
            transform: scale(1.02);
        }

        .upload-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            border-radius: 9999px;
        }

        .profile-upload:hover .upload-overlay {
            opacity: 1;
        }
    </style>
</head>

<body>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <!-- Header Section -->
           

            <form id="profileForm" class="p-8 space-y-6">
                <!-- Profile Image Section -->
                <div class="form-section">
                    <div class="border-b border-gray-200 pb-4 mb-6">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-camera text-green-600 mr-3"></i>รูปโปรไฟล์
                        </h2>
                    </div>
                    <div class="flex flex-col items-center">
                        <div class="profile-upload relative">
    <!-- เปลี่ยนจาก label เป็น div และเพิ่ม onclick -->
    <div onclick="document.getElementById('profileImageUpload').click()" class="cursor-pointer">
        <img
            id="previewImage"
            src="<?php echo htmlspecialchars($avatar_path); ?>"
            alt="Profile"
            class="w-40 h-40 rounded-full object-cover border-4 border-white shadow-lg">
        <div class="upload-overlay">
            <div class="cursor-pointer text-white text-center">
                <i class="fas fa-camera text-2xl mb-2"></i>
                <p class="text-sm">เปลี่ยนรูปภาพ</p>
            </div>
        </div>
    </div>
    <input
        type="file"
        id="profileImageUpload"
        class="hidden"
        accept="image/*">
</div>
                        <p class="text-sm text-gray-500 mt-3">ขนาดไฟล์สูงสุด: 5MB (รองรับ: JPG, PNG, GIF)</p>
                    </div>
                </div>

                <div class="form-section">
                    <div class="border-b border-gray-200 pb-4 mb-6">
                        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-user text-green-600 mr-3"></i>ข้อมูลส่วนตัว
                        </h2>
                    </div>
                    <div class="space-y-4">
                        <div class="flex items-center space-x-4">
                            <div class="flex-grow">
                                <label class="block text-gray-700 mb-2">ชื่อ-นามสกุล</label>
                                <input
                                    type="text"
                                    id="profileName"
                                    value="<?php echo htmlspecialchars($user_profile['name']); ?>"
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-green-500 focus:ring-2 focus:ring-green-100">
                            </div>
                            <div class="flex-grow">
                                <label class="block text-gray-700 mb-2">อีเมล</label>
                                <input
                                    type="email"
                                    value="<?php echo htmlspecialchars($user_profile['email']); ?>"
                                    disabled
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg bg-gray-100 cursor-not-allowed">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end gap-4 pt-6">
                    <button
                        type="button"
                        onclick="window.location.href='my_post.php'"
                        class="px-6 py-3 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 transition-all">
                        <i class="fas fa-times mr-2"></i> ยกเลิก
                    </button>
                    <button
                        type="submit"
                        class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all">
                        <i class="fas fa-save mr-2"></i> บันทึกการเปลี่ยนแปลง
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Preview uploaded image
        document.getElementById('profileImageUpload').addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImage').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });

        // Form submission
        document.getElementById('profileForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData();
            const nameInput = document.getElementById('profileName');
            const imageInput = document.getElementById('profileImageUpload');

            // Add name if changed
            formData.append('name', nameInput.value);

            // Add image if selected
            if (imageInput.files.length > 0) {
                formData.append('profile_image', imageInput.files[0]);
            }

            try {
                const response = await fetch('update_profile.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    alert('อัปเดตโปรไฟล์สำเร็จ');
                    window.location.href = 'my_post.php';
                } else {
                    alert(result.message || 'เกิดข้อผิดพลาดในการอัปเดตโปรไฟล์');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        });
        document.addEventListener('DOMContentLoaded', function() {
    // ทำให้คลิกที่รูปโปรไฟล์เปิดตัวเลือกไฟล์
    const profileImage = document.getElementById('previewImage');
    const fileInput = document.getElementById('profileImageUpload');
    
    if (profileImage && fileInput) {
        profileImage.addEventListener('click', function() {
            fileInput.click();
        });
        
        // ส่วนเดิมสำหรับแสดงตัวอย่างรูปเมื่อเลือกไฟล์
        fileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];
            if (file) {
                // ตรวจสอบขนาดไฟล์
                if (file.size > 5 * 1024 * 1024) {
                    alert('ไฟล์ภาพต้องไม่เกิน 5MB');
                    event.target.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImage').src = e.target.result;
                }
                reader.readAsDataURL(file);
            }
        });
    }
});
    </script>
    
</body>

</html>