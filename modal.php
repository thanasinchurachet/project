<!-- Modal -->
<div class="modal fade" id="wasteManagementModal" tabindex="-1" aria-labelledby="wasteManagementModalLabel" aria-hidden="true" style="z-index: 10000;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-green-500 text-white">
                <h5 class="modal-title" id="wasteManagementModalLabel">เพิ่มวิธีจัดการขยะ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="wasteManagementForm" enctype="multipart/form-data">
                    <div class="space-y-6">
                        <!-- Step 1: Waste Item Selection -->
                        <div class="form-step active" id="step1">
                            <div class="space-y-6">
                                <div class="space-y-2">
                                    <label class="block text-gray-700 font-medium">ชิ้นขยะ <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <select id="wasteItemSelect" name="wasteItem" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required onchange="toggleCustomWaste(this)">
                                            <option value="" disabled selected>เลือกชิ้นขยะ</option>
                                           
                                        </select>
                                        <input type="text" id="customWasteInput" class="w-full mt-2 p-3 border rounded-lg focus:ring-2 focus:ring-green-500 custom-waste-input hidden" placeholder="ระบุชิ้นขยะที่ไม่มีในระบบ" maxlength="50">
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
                                        <option value="dispose">กำจัด</option>
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
                        <div class="form-step hidden" id="step2">
                            <div class="space-y-6">
                                <div class="space-y-2">
                                    <label class="block text-gray-700 font-medium">หัวข้อ <span class="text-red-500">*</span></label>
                                    <input type="text" id="title" name="title" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required maxlength="50">
                                    <p class="text-sm text-gray-500 text-right" id="titleCharCount">0/50</p>
                                </div>

                                <div class="space-y-2">
                                    <label class="block text-gray-700 font-medium">รายละเอียด <span class="text-red-500">*</span></label>
                                    <textarea id="description" name="description" rows="4" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" required maxlength="500" style="resize: vertical;"></textarea>
                                    <p class="text-sm text-gray-500 text-right" id="descCharCount">0/500</p>
                                </div>

                                <!-- วิดีโอ (ลิงก์หรืออัปโหลด) -->
                                <div class="space-y-2">
                                    <label class="block text-gray-700 font-medium">วิดีโอ (เลือกอย่างใดอย่างหนึ่ง)</label>
                                    <div class="flex items-center gap-6">
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="video_mode" value="none" checked>
                                            <span>ไม่ใส่วิดีโอ</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="video_mode" value="url">
                                            <span>ลิงก์ (YouTube/Instragram/Facebook)</span>
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <input type="radio" name="video_mode" value="file">
                                            <span>อัปโหลดไฟล์</span>
                                        </label>
                                    </div>

                                    <!-- โหมดลิงก์ -->
                                    <div id="videoUrlGroup" class="mt-2 hidden">
                                        <input type="text" id="video_url" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="วางลิงก์วิดีโอ เช่น https://youtu.be/...">
                                        <div class="text-sm text-gray-500 mt-1">
                                            แพลตฟอร์ม: <span id="videoPlatformTag">-</span>
                                        </div>
                                        <div id="videoUrlPreview" class="mt-2 hidden">
                                            <div class="aspect-video w-full">
                                                <iframe id="videoUrlIframe" class="w-full h-full rounded" loading="lazy" allowfullscreen></iframe>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- โหมดอัปโหลดไฟล์ -->
                                    <div id="videoFileGroup" class="mt-2 hidden">
                                        <input type="file" id="video_file" accept="video/mp4,video/webm,video/ogg" class="w-full p-2 border rounded-lg">
                                        <div class="text-sm text-gray-500 mt-1">รองรับ MP4/WebM/Ogg ขนาดไม่เกิน 200MB</div>
                                        <video id="videoFilePreview" class="mt-2 w-full rounded hidden" controls playsinline></video>
                                    </div>
                                </div>

                                <div class="space-y-2">
                                    <label class="block text-gray-700 font-medium">รูปภาพขั้นตอน</label>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="imageUploads">
                                        <!-- Image upload slots will be generated here -->
                                    </div>
                                </div>

                                <div class="flex gap-4 pt-6">
                                    <button type="button" id="prevStep" class="flex-1 py-3 border-2 border-gray-300 rounded-lg hover:bg-gray-50 transition-colors hidden">
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

<style>
    /* Ensure modal and its inputs are clickable */
    #wasteManagementModal {
        z-index: 10000 !important;
    }
    #wasteManagementModal .modal-content,
    #wasteManagementModal input,
    #wasteManagementModal textarea,
    #wasteManagementModal select,
    #wasteManagementModal label {
        pointer-events: auto !important;
        user-select: auto !important;
    }
    /* Override SweetAlert2 z-index to show above modal */
    .swal2-container {
        z-index: 11000 !important;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('wasteManagementForm');
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const nextButton = document.getElementById('nextStep');
        const prevButton = document.getElementById('prevStep');

        initializeImageUploads();
        loadWasteItems();

        nextButton.addEventListener('click', validateAndProceed);
        prevButton.addEventListener('click', goToPreviousStep);
        form.addEventListener('submit', handleSubmit);

        document.getElementById('title').addEventListener('input', function() {
            updateCharCount(this, 'titleCharCount', 50);
        });
        document.getElementById('description').addEventListener('input', function() {
            updateCharCount(this, 'descCharCount', 500);
        });

        function initializeImageUploads() {
            const container = document.getElementById('imageUploads');
            if (!container) {
                console.error('Image uploads container not found');
                return;
            }
            container.innerHTML = '';
            for (let i = 1; i <= 3; i++) {
                const div = document.createElement('div');
                div.className = 'border-2 border-dashed border-gray-300 rounded-lg p-4 text-center';
                div.innerHTML = `
                    <input type="file" id="image${i}" name="image${i}" accept="image/*" class="hidden" onchange="handleImageUpload(event, ${i})">
                    <label for="image${i}" class="cursor-pointer w-full h-full flex flex-col items-center justify-center">
                        <div id="previewContainer${i}" class="preview-container hidden">
                            <img id="preview${i}" class="mx-auto mb-2 max-h-32 rounded">
                            <button type="button" class="text-red-500 text-sm mt-1" onclick="removeImage(${i})">ลบรูป</button>
                        </div>
                        <div id="uploadPrompt${i}" class="upload-prompt">
                            <i class="fas fa-camera text-gray-400 text-xl mb-2"></i>
                            <p class="text-sm text-gray-500">คลิกเพื่ออัพโหลดรูปภาพ ${i}</p>
                        </div>
                    </label>
                `;
                container.appendChild(div);
            }
        }

        window.handleImageUpload = function(event, index) {
            const file = event.target.files[0];
            if (!file || !['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
                Swal.fire({
                    icon: 'error',
                    title: 'ไฟล์ไม่ถูกต้อง',
                    text: 'กรุณาเลือกไฟล์ JPG หรือ PNG เท่านั้น',
                    confirmButtonColor: '#059669'
                });
                event.target.value = '';
                return;
            }

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

            const preview = document.getElementById(`preview${index}`);
            const previewContainer = document.getElementById(`previewContainer${index}`);
            const uploadPrompt = document.getElementById(`uploadPrompt${index}`);

            if (!preview || !previewContainer || !uploadPrompt) {
                console.error('DOM elements not found for image upload:', { index });
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                previewContainer.classList.remove('hidden');
                uploadPrompt.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        };

        window.removeImage = function(index) {
            const input = document.getElementById(`image${index}`);
            const preview = document.getElementById(`preview${index}`);
            const previewContainer = document.getElementById(`previewContainer${index}`);
            const uploadPrompt = document.getElementById(`uploadPrompt${index}`);

            if (input && preview && previewContainer && uploadPrompt) {
                input.value = '';
                preview.src = '';
                previewContainer.classList.add('hidden');
                uploadPrompt.classList.remove('hidden');
            }
        };

        function validateAndProceed() {
            const wasteItem = document.getElementById('wasteItemSelect').value;
            const customWaste = document.getElementById('customWasteInput').value.trim();
            const managementType = document.getElementById('managementType').value;

            if ((wasteItem === 'other' && !customWaste) || !wasteItem || !managementType) {
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
            step1.classList.add('hidden');
            step2.classList.remove('hidden');
            step2.classList.add('active');
            prevButton.classList.remove('hidden');
        }

        function goToPreviousStep() {
            step2.classList.remove('active');
            step2.classList.add('hidden');
            step1.classList.remove('hidden');
            step1.classList.add('active');
            prevButton.classList.add('hidden');
        }

        async function loadWasteItems() {
            try {
                const response = await fetch('get_waste_items.php');
                const data = await response.json();
                if (data.success && Array.isArray(data.items)) {
                    const select = document.getElementById('wasteItemSelect');
                    data.items.forEach(item => {
                        if (item.trashId && item.name && item.Waste_type) {
                            const option = document.createElement('option');
                            option.value = item.trashId;
                            option.textContent = item.name;
                            option.setAttribute('data-waste-type', item.Waste_type);
                            select.insertBefore(option, select.querySelector('option[value="other"]'));
                        } else {
                            console.warn('Invalid item data:', item);
                        }
                    });
                } else {
                    console.error('Invalid data from get_waste_items.php:', data);
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'ไม่สามารถโหลดรายการขยะได้ กรุณาลองใหม่ภายหลัง',
                        confirmButtonColor: '#059669'
                    });
                }
            } catch (error) {
                console.error('Error loading waste items:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'เกิดข้อผิดพลาด',
                    text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ กรุณาลองใหม่ภายหลัง',
                    confirmButtonColor: '#059669'
                });
            }
        }

        function updateCharCount(element, counterId, maxLength) {
            const counter = document.getElementById(counterId);
            if (counter) {
                const currentLength = element.value.length;
                counter.textContent = `${currentLength}/${maxLength}`;
            }
        }

        // --- Video UI handlers ---
        function detectPlatform(url) {
            try {
                const u = (url || '').trim();
                if (!u) return null;
                const host = (new URL(u)).host.replace(/^www\./,'');
                if (host.includes('youtube.com') || host.includes('youtu.be')) return 'youtube';
                if (host.includes('tiktok.com')) return 'tiktok';
                if (host.includes('instagram.com')) return 'instagram';
                if (host.includes('facebook.com') || host.includes('fb.watch')) return 'facebook';
                return null;
            } catch { return null; }
        }
        function buildEmbed(platform, url) {
            switch (platform) {
                case 'youtube': {
                    const m = url.match(/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/);
                    return m ? `https://www.youtube.com/embed/${m[1]}` : null;
                }
                case 'tiktok': {
                    const m = url.match(/\/video\/(\d+)/);
                    return m ? `https://www.tiktok.com/embed/v2/video/${m[1]}` : null;
                }
                case 'instagram': {
                    const m = url.match(/instagram\.com\/(p|reel|tv)\/([A-Za-z0-9_-]+)/);
                    return m ? `https://www.instagram.com/${m[1]}/${m[2]}/embed` : null;
                }
                case 'facebook':
                    return `https://www.facebook.com/plugins/video.php?href=${encodeURIComponent(url)}&show_text=0`;
                default:
                    return null;
            }
        }

        async function handleSubmit(event) {
            event.preventDefault();

            const wasteItem = document.getElementById('wasteItemSelect').value;
            const customWaste = document.getElementById('customWasteInput').value.trim();
            const wasteType = document.getElementById('wasteType').value;
            const managementType = document.getElementById('managementType').value;
            const title = document.getElementById('title').value.trim();
            const description = document.getElementById('description').value.trim();

            if (!wasteItem || (wasteItem === 'other' && !customWaste) || !wasteType || !managementType || !title || !description) {
                Swal.fire({
                    icon: 'error',
                    title: 'กรุณากรอกข้อมูลให้ครบ',
                    text: 'กรุณากรอกข้อมูลทุกช่องที่มีเครื่องหมาย *',
                    confirmButtonColor: '#059669'
                });
                return;
            }

            // Validate total upload size (images + video) against 40MB post limit
            const MAX_TOTAL = 200 * 1024 * 1024; // 40MB
            let totalBytes = 0;
            // images
            for (let i=1;i<=3;i++) {
                const inp = document.getElementById(`image${i}`);
                if (inp && inp.files && inp.files[0]) totalBytes += inp.files[0].size || 0;
            }
            // video
            const videoModeSel = document.querySelector('input[name="video_mode"]:checked')?.value || 'none';
            if (videoModeSel === 'file') {
                const vf = document.getElementById('video_file');
                if (vf && vf.files && vf.files[0]) totalBytes += vf.files[0].size || 0;
            }
            if (totalBytes > MAX_TOTAL) {
                Swal.fire({ icon:'error', title:'ไฟล์รวมเกินขนาด', text:`ขนาดรวมของไฟล์ต้องไม่เกิน ${(MAX_TOTAL/1024/1024)|0}MB`, confirmButtonColor:'#DC2626'});
                return;
            }

            
// --- Helper: parse embed code or plain URL to {url, platform}


function parseEmbedOrUrl(input) {
/*__ENTITY_DECODE__*/
  // Decode basic HTML entities to handle &lt;iframe&gt; pasted
  const decodeEntities = (s) => {
    if (!s) return s;
    return s
      .replace(/&lt;/gi, '<')
      .replace(/&gt;/gi, '>')
      .replace(/&amp;/gi, '&')
      .replace(/&quot;/gi, '"')
      .replace(/&#39;/g, "'");
  };
  input = decodeEntities(input);
    const val = (input || '').trim();
    if (!val) return { url: '', platform: '' };

    const detect = (u) => {
        try {
            const h = (new URL(u)).host.replace(/^www\./,'').toLowerCase();
            if (h.includes('youtube.com') || h.includes('youtu.be')) return 'youtube';
            if (h.includes('tiktok.com')) return 'tiktok';
            if (h.includes('instagram.com')) return 'instagram';
            if (h.includes('facebook.com') || h.includes('fb.watch')) return 'facebook';
            return '';
        } catch { return ''; }
    };

    // If input contains HTML (embed code), extract URL from common attributes
    if (/[<][a-z]/i.test(val)) {
        // 1) TikTok blockquote
        let m = val.match(/cite="([^"]+)"/i);
        if (m) return { url: m[1], platform: detect(m[1]) };

        // 2) Facebook <div class="fb-video" data-href="...">
        m = val.match(/data-href="([^"]+)"/i);
        if (m) return { url: m[1], platform: detect(m[1]) };

        // 3) <iframe src="..."> (Facebook plugin)
        m = val.match(/src="([^"]+)"/i);
        if (m) {
            try {
                const srcUrl = new URL(m[1]);
                const hrefParam = srcUrl.searchParams.get('href');
                if (hrefParam) {
                    const decoded = decodeURIComponent(hrefParam);
                    return { url: decoded, platform: detect(decoded) };
                }
                return { url: srcUrl.toString(), platform: detect(srcUrl.toString()) };
            } catch {}
        }

        // 4) Generic <a href="...">
        m = val.match(/href="([^"]+)"/i);
        if (m) return { url: m[1], platform: detect(m[1]) };

        // 5) Fallback: any URL in text
        const any = val.match(/https?:\/\/[^\s"']+/i);
        if (any) return { url: any[0], platform: detect(any[0]) };

        return { url: '', platform: '' };
    }

    // Plain text (URL)
    return { url: val, platform: detect(val) };
}


            const formData = new FormData();
            formData.append('wasteItem', wasteItem);
            formData.append('customWaste', wasteItem === 'other' ? customWaste : '');
            formData.append('wasteType', wasteType);
            formData.append('managementType', managementType);
            formData.append('title', title);
            formData.append('description', description);

            // Video fields
            const videoMode = (document.querySelector('input[name="video_mode"]:checked')?.value) || 'none';
            formData.append('video_mode', videoMode);
            if (videoMode === 'url') {
                const raw = document.getElementById('video_url').value.trim();
                const parsed = parseEmbedOrUrl(raw);
                if (parsed.url) {
                    formData.append('video_url', parsed.url);
                    if (parsed.platform) formData.append('video_platform', parsed.platform);
                }
            } else if (videoMode === 'file') {
                const vf = document.getElementById('video_file');
                if (vf && vf.files && vf.files[0]) formData.append('video_file', vf.files[0]);
            }

            for (let i = 1; i <= 3; i++) {
                const imageInput = document.getElementById(`image${i}`);
                if (imageInput && imageInput.files[0]) {
                    console.log(`Adding image${i}:`, imageInput.files[0].name);
                    formData.append(`image${i}`, imageInput.files[0]);
                }
            }

            try {
                const response = await fetch('add_post.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Raw response:', errorText);
                    throw new Error(`HTTP error! status: ${response.status}, details: ${errorText}`);
                }

                const result = await response.json();
                console.log('Server response:', result);

                if (result.success) {
                    for (let i = 1; i <= 3; i++) {
                        const imageInput = document.getElementById(`image${i}`);
                        if (imageInput) imageInput.value = '';
                        const preview = document.getElementById(`preview${i}`);
                        const previewContainer = document.getElementById(`previewContainer${i}`);
                        const uploadPrompt = document.getElementById(`uploadPrompt${i}`);
                        if (preview && previewContainer && uploadPrompt) {
                            preview.src = '';
                            previewContainer.classList.add('hidden');
                            uploadPrompt.classList.remove('hidden');
                        }
                    }

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
                console.error('Fetch error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'เกิดข้อผิดพลาด',
                    text: error.message,
                    confirmButtonColor: '#DC2626'
                });
            }
        }
    });

    function toggleCustomWaste(select) {
        const customInput = document.getElementById('customWasteInput');
        const wasteTypeInput = document.getElementById('wasteType');
        if (select.value === 'other') {
            customInput.classList.remove('hidden');
            customInput.required = true;
            wasteTypeInput.value = '';
        } else {
            customInput.classList.add('hidden');
            customInput.required = false;
            customInput.value = '';
            updateWasteType(select.value);
        }
    }

    // Bind video UI interactions
    (function initVideoUI(){
        const radios = document.querySelectorAll('input[name="video_mode"]');
        const urlGroup = document.getElementById('videoUrlGroup');
        const fileGroup = document.getElementById('videoFileGroup');
        const urlInput = document.getElementById('video_url');
        const platformTag = document.getElementById('videoPlatformTag');
        const previewWrap = document.getElementById('videoUrlPreview');
        const previewIframe = document.getElementById('videoUrlIframe');
        const fileInput = document.getElementById('video_file');
        const filePreview = document.getElementById('videoFilePreview');

        function updateMode() {
            const mode = document.querySelector('input[name="video_mode"]:checked')?.value || 'none';
            urlGroup.classList.toggle('hidden', mode !== 'url');
            fileGroup.classList.toggle('hidden', mode !== 'file');
            if (mode !== 'url') {
                previewWrap.classList.add('hidden');
                platformTag.textContent = '-';
                urlInput.value = '';
            }
            if (mode !== 'file') {
                filePreview.classList.add('hidden');
                if (fileInput) fileInput.value = '';
            }
        }
        radios.forEach(r => r.addEventListener('change', updateMode));
        updateMode();

        if (urlInput) {
            urlInput.addEventListener('input', () => {
                const u = urlInput.value.trim();
                const p = detectPlatform(u);
                platformTag.textContent = p || '-';
                const embed = p ? buildEmbed(p, u) : null;
                if (embed) {
                    previewWrap.innerHTML = `<div class="aspect-video w-full"><iframe class="w-full h-full rounded" src="${embed}" allowfullscreen loading="lazy"></iframe></div>`;
                    previewWrap.classList.remove('hidden');
                } else if (p === 'tiktok' && u) {
                    // Fallback to TikTok blockquote embed for short links
                    previewWrap.innerHTML = `<blockquote class="tiktok-embed" cite="${u}" style="max-width:605px;min-width:325px;"><section></section></blockquote>`;
                    previewWrap.classList.remove('hidden');
                    if (!document.querySelector('script[data-tiktok-embed]')) { const s=document.createElement('script'); s.src='https://www.tiktok.com/embed.js'; s.async=true; s.setAttribute('data-tiktok-embed','1'); document.body.appendChild(s);}                
                } else {
                    previewWrap.innerHTML = '';
                    previewWrap.classList.add('hidden');
                }
            });
        }
        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (fileInput.files && fileInput.files[0]) {
                    const f = fileInput.files[0];
                    const typeOk = ['video/mp4','video/webm','video/ogg'].includes(f.type);
                    const ext = (f.name.split('.').pop() || '').toLowerCase();
                    const extOk = ['mp4','webm','ogg'].includes(ext);
                    const sizeOk = f.size <= 80*1024*1024; // 40MB to match server default
                    if (!(sizeOk && (typeOk || (!f.type && extOk) || (f.type==='application/octet-stream' && extOk)))) {
                        Swal.fire({ icon:'error', title:'ไฟล์วิดีโอไม่ถูกต้อง', text:'รองรับ MP4/WebM/Ogg และต้องไม่เกิน 200MB' });
                        fileInput.value='';
                        filePreview.classList.add('hidden');
                        return;
                    }
                    const url = URL.createObjectURL(f);
                    filePreview.src = url;
                    filePreview.classList.remove('hidden');
                } else {
                    filePreview.src = '';
                    filePreview.classList.add('hidden');
                }
            });
        }
    })();

    function updateWasteType(wasteId = null) {
        const wasteTypeInput = document.getElementById('wasteType');
        if (wasteId && wasteId !== 'other') {
            const selectedOption = document.querySelector(`#wasteItemSelect option[value="${wasteId}"]`);
            if (selectedOption) {
                const wasteType = selectedOption.getAttribute('data-waste-type') || '';
                wasteTypeInput.value = wasteType;
            } else {
                console.warn('No option found for wasteId:', wasteId);
                wasteTypeInput.value = '';
            }
        } else {
            wasteTypeInput.value = '';
        }
    }
</script>
<script>
(function(){
  // ให้ form ไม่บังคับ type=url (กัน "Please enter a URL")
  const f = document.querySelector('form'); if (f) f.setAttribute('novalidate','novalidate');

  const input = document.getElementById('video_url');        // ช่องกรอกลิงก์
  const wrap  = document.getElementById('videoUrlPreviewWrap'); // กล่องพรีวิว
  const tag   = document.getElementById('videoPlatformTag'); // ป้ายแพลตฟอร์ม

  if (!input || !wrap || !tag) return;
  try{ if(input.type.toLowerCase()==='url') input.type='text'; }catch{}

  function ensureTikTokScript(){
    if (!document.querySelector('script[data-tiktok-embed]')) {
      const s=document.createElement('script');
      s.src='https://www.tiktok.com/embed.js'; s.async=true; s.setAttribute('data-tiktok-embed','1');
      document.body.appendChild(s);
    } else if (window.tiktokEmbedLoad) {
      try{ window.tiktokEmbedLoad(); }catch(_){}
    }
    setTimeout(()=>{ try{ window.tiktokEmbedLoad && window.tiktokEmbedLoad(); }catch(_){} },0);
  }

  function parseTikTok(inputStr){
    const s = String(inputStr||'').trim();
    // ถ้าวางเป็น blockquote ทั้งก้อน
    if (s.includes('<') && s.includes('>')) {
      const m = s.match(/<blockquote[^>]*class="[^"]*tiktok-embed[^"]*"[^>]*cite="([^"]+)"/i);
      if (m) return { url: m[1], id: (m[1].match(/\/video\/(\d+)/)||[])[1] || '' };
      const id = (s.match(/data-video-id="(\d+)"/i)||[])[1];
      if (id) return { url: '', id };
      const any = (s.match(/https?:\/\/[^\s"']+/i)||[])[0];
      if (any) return { url:any, id:(any.match(/\/video\/(\d+)/)||[])[1]||'' };
      return {url:'', id:''};
    }
    // ลิงก์ปกติ
    return { url:s, id:(s.match(/\/video\/(\d+)/)||[])[1]||'' };
  }

  function render(){
    const v = input.value.trim();
    if (!v) { wrap.innerHTML=''; wrap.classList.add('hidden'); tag.textContent='-'; return; }

    // ตรวจ TikTok ก่อน
    if (/tiktok\.com/i.test(v) || /tiktok-embed/i.test(v)) {
      tag.textContent = 'tiktok';
      const ex = parseTikTok(v);
      const citeUrl = ex.url;
      const id = ex.id;

      // พรีวิวด้วย blockquote เสมอ (เสถียรสุด)
      const bq = citeUrl
        ? `<blockquote class="tiktok-embed" cite="${citeUrl}" style="max-width:605px;min-width:325px;"><section></section></blockquote>`
        : (id ? `<blockquote class="tiktok-embed" cite="https://www.tiktok.com/@_/video/${id}" style="max-width:605px;min-width:325px;"><section></section></blockquote>` : '');

      if (bq) {
        wrap.innerHTML = bq;
        wrap.classList.remove('hidden');
        ensureTikTokScript();
      } else {
        wrap.innerHTML=''; wrap.classList.add('hidden');
      }
      return;
    }

    // แพลตฟอร์มอื่น ๆ – ใช้โค้ดเดิมของคุณต่อได้
    tag.textContent = '-';
    wrap.innerHTML=''; wrap.classList.add('hidden');
  }

  input.addEventListener('input', render);
  input.addEventListener('change', render);
  setTimeout(render, 0);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
