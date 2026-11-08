<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แชร์วิธีจัดการขยะ</title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body, input, button, select, textarea {
            font-family: 'Kanit', sans-serif;
        }
        .modal-content {
            max-height: 90vh;
            overflow-y: auto;
        }
        .form-step {
            display: none;
        }
        .form-step.active {
            display: block;
        }
        .step-indicator {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #E5E7EB;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .step-indicator.active {
            background-color: #059669;
            color: white;
        }
        .step-indicator.completed {
            background-color: #10B981;
            color: white;
        }
        .custom-waste-input {
            display: none;
        }
    </style>
</head>

<body class="bg-gray-50">
    <?php include 'nav.php'; ?>
    <div class="max-w-3xl mx-auto p-4">
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-green-600 to-green-500 p-6 text-white">
                <h1 class="text-2xl font-semibold">เพิ่มวิธีจัดการขยะ</h1>
            </div>
            <div class="p-6 text-center">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#wasteManagementModal">
                    <i class="fas fa-plus"></i> เพิ่มโพสต์ใหม่
                </button>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="wasteManagementModal" tabindex="-1" aria-labelledby="wasteManagementModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-green-500 text-white">
                    <h5 class="modal-title" id="wasteManagementModalLabel">เพิ่มวิธีจัดการขยะ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="wasteManagementForm">
                        <div class="space-y-6">
                            <!-- Step 1: Waste Item Selection -->
                            <div class="form-step active" id="step1">
                                <div class="space-y-6">
                                    <div class="space-y-2">
                                        <label class="block text-gray-700 font-medium">ชิ้นขยะ <span class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <select id="wasteItemSelect" name="wasteItem" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required onchange="toggleCustomWaste(this)">
                                                <option value="" disabled selected>เลือกชิ้นขยะ</option>
                                                <option value="other">อื่นๆ (ระบุเอง)</option>
                                            </select>
                                            <input type="text" id="customWasteInput" class="w-full mt-2 p-3 border rounded-lg focus:ring-2 focus:ring-green-500 custom-waste-input" placeholder="ระบุชิ้นขยะที่ไม่มีในระบบ" maxlength="50">
                                        </div>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="block text-gray-700 font-medium">ประเภทขยะ <span class="text-red-500">*</span></label>
                                        <input type="text" id="wasteType" name="wasteType" class="w-full p-3 border rounded-lg bg-gray-100" readonly>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="block text-gray-700 font-medium">วิธีจัดการ <span class="text-red-500">*</span></label>
                                        <select id="managementType" name="managementType" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required>
                                            <option value="" disabled selected>เลือกวิธีจัดการ</option>
                                            <option value="reuse">นำกลับมาใช้ใหม่</option>
                                            <option value="recycle">รีไซเคิล</option>
                                            <option value="dispose">ฝังกลบ</option>
                                        </select>
                                    </div>

                                    <div class="pt-6">
                                        <button type="button" id="nextStep" class="w-full py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                                            ถัดไป
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2: Details -->
                            <div class="form-step" id="step2">
                                <div class="space-y-6">
                                    <div class="space-y-2">
                                        <label class="block text-gray-700 font-medium">หัวข้อ <span class="text-red-500">*</span></label>
                                        <input type="text" id="title" name="title" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required maxlength="50">
                                        <p class="text-sm text-gray-500 text-right" id="titleCharCount">0/50</p>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="block text-gray-700 font-medium">รายละเอียด <span class="text-red-500">*</span></label>
                                        <textarea id="description" name="description" rows="4" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required maxlength="150"></textarea>
                                        <p class="text-sm text-gray-500 text-right" id="descCharCount">0/150</p>
                                    </div>

                                    <div class="space-y-2">
                                        <label class="block text-gray-700 font-medium">รูปภาพขั้นตอน</label>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="imageUploads">
                                            <!-- Image upload slots will be generated here -->
                                        </div>
                                    </div>

                                    <div class="flex gap-4 pt-6">
                                        <button type="button" id="prevStep" class="flex-1 py-3 border-2 border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                                            ย้อนกลับ
                                        </button>
                                        <button type="submit" class="flex-1 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                                            ส่งข้อมูล
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('wasteManagementForm');
            const step1 = document.getElementById('step1');
            const step2 = document.getElementById('step2');
            const nextButton = document.getElementById('nextStep');
            const prevButton = document.getElementById('prevStep');
            const step1Indicator = document.querySelector('#step1-indicator');
            const step2Indicator = document.querySelector('#step2-indicator');

            initializeImageUploads();
            loadWasteItems();

            nextButton.addEventListener('click', validateAndProceed);
            prevButton.addEventListener('click', goToPreviousStep);
            form.addEventListener('submit', handleSubmit);

            document.getElementById('title').addEventListener('input', function() {
                updateCharCount(this, 'titleCharCount', 50);
            });
            document.getElementById('description').addEventListener('input', function() {
                updateCharCount(this, 'descCharCount', 150);
            });

            function initializeImageUploads() {
                const container = document.getElementById('imageUploads');
                for (let i = 1; i <= 3; i++) {
                    const uploadDiv = createImageUploadSlot(i);
                    container.appendChild(uploadDiv);
                }
            }

            function createImageUploadSlot(index) {
                const div = document.createElement('div');
                div.className = 'border-2 border-dashed border-gray-300 rounded-lg p-4 text-center';
                div.innerHTML = `
                    <input type="file" id="image${index}" name="image${index}" accept="image/*" class="hidden">
                    <label for="image${index}" class="cursor-pointer">
                        <div class="preview-container hidden">
                            <img id="preview${index}" class="mx-auto mb-2 max-h-32 rounded">
                        </div>
                        <div class="upload-prompt">
                            <i class="fas fa-camera text-gray-400 text-xl mb-2"></i>
                            <p class="text-sm text-gray-500">คลิกเพื่ออัพโหลดรูปภาพ ${index}</p>
                        </div>
                    </label>
                `;

                const input = div.querySelector(`#image${index}`);
                input.addEventListener('change', function(e) {
                    handleImageUpload(e, index);
                });

                return div;
            }

            function handleImageUpload(event, index) {
                const file = event.target.files[0];
                if (!file) return;

                const preview = document.getElementById(`preview${index}`);
                const previewContainer = preview.parentElement;
                const uploadPrompt = event.target.parentElement.querySelector('.upload-prompt');

                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                    uploadPrompt.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }

            function validateAndProceed() {
                const wasteItem = document.getElementById('wasteItemSelect').value;
                const customWaste = document.getElementById('customWasteInput').value;
                const managementType = document.getElementById('managementType').value;

                if ((wasteItem === 'other' && !customWaste.trim()) || (wasteItem !== 'other' && !wasteItem) || !managementType) {
                    Swal.fire({
                        icon: 'error',
                        title: 'กรุณากรอกข้อมูลให้ครบ',
                        text: 'กรุณาเลือกชิ้นขยะ ระบุชิ้นขยะหากเลือกอื่นๆ และเลือกวิธีจัดการ',
                        confirmButtonColor: '#059669'
                    });
                    return;
                }

                goToNextStep();
            }

            function goToNextStep() {
                step1.classList.remove('active');
                step2.classList.add('active');
                step1Indicator.classList.add('completed');
                step2Indicator.classList.add('active');
            }

            function goToPreviousStep() {
                step2.classList.remove('active');
                step1.classList.add('active');
                step2Indicator.classList.remove('active');
            }

            async function loadWasteItems() {
                try {
                    const response = await fetch('get_waste_items.php');
                    const data = await response.json();
                    if (data.success) {
                        const select = document.getElementById('wasteItemSelect');
                        data.items.forEach(item => {
                            const option = document.createElement('option');
                            option.value = item.trashId;
                            option.textContent = item.name;
                            select.insertBefore(option, select.querySelector('option[value="other"]'));
                        });
                    }
                } catch (error) {
                    console.error('Error loading waste items:', error);
                }
            }

            function toggleCustomWaste(select) {
                const customInput = document.getElementById('customWasteInput');
                if (select.value === 'other') {
                    customInput.classList.remove('custom-waste-input');
                    customInput.required = true;
                    updateWasteType();
                } else {
                    customInput.classList.add('custom-waste-input');
                    customInput.required = false;
                    customInput.value = '';
                    updateWasteType(select.value);
                }
            }

            function updateWasteType(wasteId = null) {
                const wasteTypeInput = document.getElementById('wasteType');
                if (wasteId) {
                    fetch(`get_waste_type.php?trashId=${wasteId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                wasteTypeInput.value = data.type || '';
                            }
                        })
                        .catch(error => console.error('Error fetching waste type:', error));
                } else {
                    wasteTypeInput.value = '';
                }
            }

            function updateCharCount(element, counterId, maxLength) {
                const counter = document.getElementById(counterId);
                const currentLength = element.value.length;
                counter.textContent = `${currentLength}/${maxLength}`;
            }

            async function handleSubmit(event) {
                event.preventDefault();

                const wasteItem = document.getElementById('wasteItemSelect').value;
                const customWaste = document.getElementById('customWasteInput').value;
                const wasteType = document.getElementById('wasteType').value;
                const managementType = document.getElementById('managementType').value;
                const title = document.getElementById('title').value;
                const description = document.getElementById('description').value;

                if (!wasteItem || (wasteItem === 'other' && !customWaste.trim()) || !wasteType || !managementType || !title || !description) {
                    Swal.fire({
                        icon: 'error',
                        title: 'กรุณากรอกข้อมูลให้ครบ',
                        text: 'กรุณากรอกข้อมูลทุกช่องที่มีเครื่องหมาย *',
                        confirmButtonColor: '#059669'
                    });
                    return;
                }

                const formData = new FormData();
                formData.append('wasteItem', wasteItem === 'other' ? customWaste : wasteItem);
                formData.append('wasteType', wasteType);
                formData.append('managementType', managementType);
                formData.append('title', title);
                formData.append('description', description);

                for (let i = 1; i <= 3; i++) {
                    const imageInput = document.getElementById(`image${i}`);
                    if (imageInput && imageInput.files[0]) {
                        formData.append(`image${i}`, imageInput.files[0]);
                    }
                }

                try {
                    const response = await fetch('add_post.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'ส่งข้อมูลสำเร็จ',
                            text: 'โพสต์ของคุณถูกส่งไปยังผู้ดูแลระบบเพื่อรออนุมัติ',
                            confirmButtonColor: '#059669'
                        });
                        $('#wasteManagementModal').modal('hide');
                        window.location.reload();
                    } else {
                        throw new Error(result.error || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล');
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: error.message,
                        confirmButtonColor: '#DC2626'
                    });
                }
            }

            // ตรวจสอบการระงับการโพสต์
            document.addEventListener('DOMContentLoaded', async function() {
                try {
                    const response = await fetch('check_post_restriction.php');
                    const data = await response.json();

                    if (!data.canPost) {
                        const restrictionAlert = document.createElement('div');
                        restrictionAlert.className = 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-center';
                        restrictionAlert.innerHTML = `
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <span class="font-medium">${data.message}</span>
                        `;

                        const formContainer = document.querySelector('.container');
                        formContainer.insertBefore(restrictionAlert, formContainer.firstChild);

                        const modal = document.getElementById('wasteManagementModal');
                        const modalBody = modal.querySelector('.modal-body');
                        modalBody.innerHTML = `
                            <div class="text-center p-6">
                                <i class="fas fa-lock text-4xl text-red-500 mb-4"></i>
                                <h3 class="text-xl font-bold mb-2">การโพสต์ถูกระงับ</h3>
                                <p class="mb-4">${data.message}</p>
                                <a href="index.php" class="inline-block px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                                    กลับไปหน้าหลัก
                                </a>
                            </div>
                        `;
                        modal.querySelectorAll('input, select, button').forEach(el => el.disabled = true);
                    }
                } catch (error) {
                    console.error('เกิดข้อผิดพลาดในการตรวจสอบการระงับการโพสต์:', error);
                }
            });
        });
    </script>
</body>