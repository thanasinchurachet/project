<!-- Edit Post Modal -->
<div class="modal fade" id="editPostModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-green-500 text-white">
        <h5 class="modal-title">แก้ไขโพสต์</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="editPostForm" enctype="multipart/form-data">
        <div class="modal-body p-4 space-y-6">
          <!-- hidden fields -->
          <input type="hidden" name="post_id" id="edit_post_id">
          <input type="hidden" name="trashId" id="edit_trash_id">
          <input type="hidden" id="existingImagesField" name="existing_images" value="[]">
          <input type="hidden" id="removedImagesField" name="removed_images" value="[]">

          <!-- ชิ้นขยะ (readonly) -->
          <div>
            <label class="block text-gray-700 font-medium mb-1">ชิ้นขยะ</label>
            <input type="text" id="edit_item" name="item" class="w-full border rounded px-3 py-2"
                   placeholder="เช่น กระดาษลัง" readonly>
          </div>

          <!-- ประเภทขยะ (readonly) -->
          <div>
            <label class="block text-gray-700 font-medium mb-1">ประเภทขยะ</label>
            <input type="text" id="edit_category" name="category" class="w-full border rounded px-3 py-2"
                   placeholder="เช่น รีไซเคิล" readonly>
          </div>

          <!-- วิธีจัดการ (readonly) -->
          <div>
            <label class="block text-gray-700 font-medium mb-1">วิธีจัดการ</label>
            <input type="text" id="edit_method" name="method" class="w-full border rounded px-3 py-2"
                   placeholder="เช่น พับ/มัด ส่งศูนย์รีไซเคิล" readonly>
          </div>

          <!-- หัวข้อ + เคาน์เตอร์ -->
          <div>
            <label class="block text-gray-700 font-medium mb-1">
              หัวข้อ <span class="text-red-500">*</span>
              <span id="titleCounter" class="float-right text-sm text-gray-500">0/50</span>
            </label>
            <input type="text" id="edit_title" name="title" maxlength="50"
                   class="w-full border rounded px-3 py-2" placeholder="หัวข้อ" required>
          </div>

          <!-- รายละเอียด + เคาน์เตอร์ -->
          <div>
            <label class="block text-gray-700 font-medium mb-1">
              รายละเอียด <span class="text-red-500">*</span>
              <span id="detailCounter" class="float-right text-sm text-gray-500">0/500</span>
            </label>
            <textarea id="edit_detail" name="detail" rows="5" maxlength="500"
                      class="w-full border rounded px-3 py-2" placeholder="รายละเอียด" required></textarea>
          </div>

          <!-- วิดีโอ (ลิงก์/อัปโหลด/คงไว้/ลบ) -->
          <div class="mt-2">
            <label class="block text-gray-700 font-medium mb-1">วิดีโอ</label>
            <div class="flex flex-wrap gap-4 mb-2">
              <label class="flex items-center gap-2"><input type="radio" name="edit_video_mode" value="keep" checked> คงไว้</label>
              <label class="flex items-center gap-2"><input type="radio" name="edit_video_mode" value="none"> ไม่ใส่วิดีโอ</label>
              <label class="flex items-center gap-2"><input type="radio" name="edit_video_mode" value="url"> เปลี่ยนเป็นลิงก์</label>
              <label class="flex items-center gap-2"><input type="radio" name="edit_video_mode" value="file"> อัปโหลดใหม่</label>
            </div>

            <!-- แสดงวิดีโอปัจจุบัน -->
            <div id="currentVideoGroup" class="mb-2 hidden">
              <div class="text-sm text-gray-500 mb-1">วิดีโอปัจจุบัน</div>
              <div id="currentVideoPreview"></div>
            </div>

            <!-- กรอกลิงก์ใหม่ -->
            <div id="editVideoUrlGroup" class="hidden">
              <input type="url" id="edit_video_url" class="w-full border rounded px-3 py-2" placeholder="วางลิงก์วิดีโอ">
              <div class="text-sm text-gray-500 mt-1">แพลตฟอร์ม: <span id="editVideoPlatform">-</span></div>
              <div class="mt-2 hidden" id="editVideoUrlPreview"><div class="aspect-video w-full"><iframe id="editVideoUrlIframe" class="w-full h-full rounded" loading="lazy" allowfullscreen></iframe></div></div>
            </div>

            <!-- อัปโหลดไฟล์ใหม่ -->
            <div id="editVideoFileGroup" class="hidden">
              <input type="file" id="edit_video_file" accept="video/mp4,video/webm,video/ogg" class="w-full border rounded px-3 py-2">
              <div class="text-sm text-gray-500 mt-1">รองรับ MP4/WebM/Ogg ขนาดไม่เกิน 50MB</div>
              <video id="editVideoFilePreview" class="mt-2 w-full rounded hidden" controls playsinline></video>
            </div>
          </div>

          <!-- รูปภาพ (สูงสุด 3 รูป) -->
          <div class="mt-2">
            <label class="block text-gray-700 font-medium mb-1">รูปภาพ (สูงสุด 3 รูป)</label>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <!-- Slot 1 -->
              <div class="image-slot" data-slot="1">
                <div class="slot-empty">
                  <i class="fas fa-camera fa-2x mb-3"></i>
                  <div>คลิกเพื่ออัปโหลด<br>รูปภาพ 1</div>
                </div>
                <img class="slot-preview" alt="Preview 1">
                <button type="button" class="slot-remove" data-action="remove-image">
                  ลบรูป
                </button>
                <input type="file" id="image1" name="image1" accept="image/*" class="hidden">
              </div>

              <!-- Slot 2 -->
              <div class="image-slot" data-slot="2">
                <div class="slot-empty">
                  <i class="fas fa-camera fa-2x mb-3"></i>
                  <div>คลิกเพื่ออัปโหลด<br>รูปภาพ 2</div>
                </div>
                <img class="slot-preview" alt="Preview 2">
                <button type="button" class="slot-remove" data-action="remove-image">
                  ลบรูป
                </button>
                <input type="file" id="image2" name="image2" accept="image/*" class="hidden">
              </div>

              <!-- Slot 3 -->
              <div class="image-slot" data-slot="3">
                <div class="slot-empty">
                  <i class="fas fa-camera fa-2x mb-3"></i>
                  <div>คลิกเพื่ออัปโหลด<br>รูปภาพ 3</div>
                </div>
                <img class="slot-preview" alt="Preview 3">
                <button type="button" class="slot-remove" data-action="remove-image">
                  ลบรูป
                </button>
                <input type="file" id="image3" name="image3" accept="image/*" class="hidden">
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
/* Image slot styles */
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

.image-slot:hover {
  border-color: #10b981;
  background: #f0fdf4;
}

.slot-empty {
  position: absolute;
  inset: 0;
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
  cursor: zoom-in;
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
  box-shadow: 0 2px 4px rgba(0,0,0,.1);
  display: none;
  z-index: 10;
}

/* When slot has image */
.image-slot.has-image .slot-preview {
  display: block !important;
}

.image-slot.has-image .slot-empty {
  display: none !important;
}

.image-slot.has-image .slot-remove {
  display: inline-flex !important;
}

.image-slot.has-image {
  border-color: #10b981;
  background: #f0fdf4;
}
</style>

<script>
// ==========================================
// Edit Post Modal - Image Management System
// ==========================================

(function() {
  'use strict';
  
  // Global variables
  let existingImages = [];
  let removedImages = [];
  let currentEditModal = null;
  
  // Utility functions
  function getSlotElements(slot) {
    const container = document.querySelector(`.image-slot[data-slot="${slot}"]`);
    if (!container) return null;
    
    return {
      container,
      img: container.querySelector('.slot-preview'),
      empty: container.querySelector('.slot-empty'),
      btn: container.querySelector('.slot-remove'),
      input: document.getElementById(`image${slot}`)
    };
  }
  
  // Clear a single slot
  function clearSlot(slot) {
    const elements = getSlotElements(slot);
    if (!elements) return;
    
    const { container, img, input } = elements;
    
    // Revoke object URL if exists
    if (img.dataset.objectUrl) {
      URL.revokeObjectURL(img.dataset.objectUrl);
      delete img.dataset.objectUrl;
    }
    
    // Clear input
    if (input) {
      input.value = '';
      delete input.dataset.newfile;
    }
    
    // Reset preview
    img.src = '';
    container.classList.remove('has-image');
  }
  
  // Handle file selection
  function handleFileSelect(slot) {
    const elements = getSlotElements(slot);
    if (!elements || !elements.input) return;
    
    const file = elements.input.files[0];
    if (!file) return;
    
    // Validate file type
    if (!file.type.match(/^image\/(jpeg|jpg|png|gif|webp)$/i)) {
      alert('กรุณาเลือกไฟล์รูปภาพเท่านั้น (JPG, PNG, GIF, WebP)');
      elements.input.value = '';
      return;
    }
    
    // Create preview
    const objectUrl = URL.createObjectURL(file);
    
    // Clear old object URL if exists
    if (elements.img.dataset.objectUrl) {
      URL.revokeObjectURL(elements.img.dataset.objectUrl);
    }
    
    // Set new preview
    elements.img.src = objectUrl;
    elements.img.dataset.objectUrl = objectUrl;
    elements.input.dataset.newfile = '1';
    elements.container.classList.add('has-image');
    
    // Reset button state for new upload
    elements.btn.dataset.isExisting = '0';
    elements.btn.dataset.filename = '';
  }
  
  // Remove image from slot
  function removeImage(slot) {
    const elements = getSlotElements(slot);
    if (!elements) return;
    
    // Check if this is an existing image
    const isExisting = elements.btn.dataset.isExisting === '1';
    const filename = elements.btn.dataset.filename;
    
    if (isExisting && filename) {
      // Add to removed list
      if (!removedImages.includes(filename)) {
        removedImages.push(filename);
      }
      
      // Remove from existing list
      existingImages = existingImages.filter(f => f !== filename);
      
      // Update hidden fields
      updateHiddenFields();
    }
    
    // Clear the slot
    clearSlot(slot);
  }
  
  // Set preview for existing image
  function setExistingImage(slot, url, filename) {
    const elements = getSlotElements(slot);
    if (!elements) return;
    
    elements.img.src = url;
    elements.container.classList.add('has-image');
    elements.btn.dataset.isExisting = '1';
    elements.btn.dataset.filename = filename;
  }
  
  // Update hidden fields
  function updateHiddenFields() {
    const existingField = document.getElementById('existingImagesField');
    const removedField = document.getElementById('removedImagesField');
    
    if (existingField) {
      existingField.value = JSON.stringify(existingImages);
    }
    
    if (removedField) {
      removedField.value = JSON.stringify(removedImages);
    }
  }
  
  // Clear all image slots
  function clearAllSlots() {
    [1, 2, 3].forEach(slot => clearSlot(slot));
    existingImages = [];
    removedImages = [];
    updateHiddenFields();
  }
  
  // Initialize counters
  function initCounters() {
    const titleInput = document.getElementById('edit_title');
    const detailInput = document.getElementById('edit_detail');
    const titleCounter = document.getElementById('titleCounter');
    const detailCounter = document.getElementById('detailCounter');
    
    function updateCounter(input, counter, max) {
      if (input && counter) {
        counter.textContent = `${input.value.length}/${max}`;
      }
    }
    
    if (titleInput && titleCounter) {
      updateCounter(titleInput, titleCounter, 50);
      titleInput.addEventListener('input', () => updateCounter(titleInput, titleCounter, 50));
    }
    
    if (detailInput && detailCounter) {
      updateCounter(detailInput, detailCounter, 500);
      detailInput.addEventListener('input', () => updateCounter(detailInput, detailCounter, 500));
    }
  }
  
  // Initialize event listeners
  function initEventListeners() {
    // File input change handlers
    [1, 2, 3].forEach(slot => {
      const input = document.getElementById(`image${slot}`);
      if (input) {
        input.addEventListener('change', () => handleFileSelect(slot));
      }
    });
    
    // Click handler for image slots
    document.addEventListener('click', function(e) {
      // Handle slot click (open file dialog)
      const slot = e.target.closest('.image-slot');
      if (slot && !slot.classList.contains('has-image')) {
        const slotNum = slot.dataset.slot;
        const input = document.getElementById(`image${slotNum}`);
        if (input) {
          e.preventDefault();
          input.click();
        }
      }
      
      // Handle remove button click
      const removeBtn = e.target.closest('[data-action="remove-image"]');
      if (removeBtn) {
        e.preventDefault();
        e.stopPropagation();
        const slotContainer = removeBtn.closest('.image-slot');
        if (slotContainer) {
          const slotNum = slotContainer.dataset.slot;
          removeImage(slotNum);
        }
      }
      
      // Handle preview image click (zoom)
      const previewImg = e.target.closest('.slot-preview');
      if (previewImg && previewImg.src && e.target.closest('.has-image')) {
        e.preventDefault();
        e.stopPropagation();
        // You can implement zoom functionality here
        console.log('Image clicked for zoom:', previewImg.src);
      }
    });
    
    // Form submit handler
    const form = document.getElementById('editPostForm');
    if (form) {
      form.addEventListener('submit', async function(e) {
        e.preventDefault();

        // Ensure hidden fields reflect current state before collecting form data
        updateHiddenFields();

        // Build multipart form data explicitly to be safe
        // Validate total upload size (new images + new video) <= 40MB
        const MAX_TOTAL = 40 * 1024 * 1024;
        let totalBytes = 0;
        [1,2,3].forEach(n=>{ const inp = document.getElementById(`image${n}`); if (inp && inp.files && inp.files[0]) totalBytes += inp.files[0].size||0; });
        const vmSel = document.querySelector('input[name="edit_video_mode"]:checked')?.value || 'keep';
        if (vmSel === 'file') {
          const vf = document.getElementById('edit_video_file');
          if (vf && vf.files && vf.files[0]) totalBytes += vf.files[0].size||0;
        }
        if (totalBytes > MAX_TOTAL) { alert(`ขนาดรวมของไฟล์ต้องไม่เกิน ${(MAX_TOTAL/1024/1024)|0}MB`); return; }

        const formData = new FormData();
        // Basic fields
        formData.append('post_id', document.getElementById('edit_post_id')?.value || '');
        formData.append('title', document.getElementById('edit_title')?.value || '');
        formData.append('detail', document.getElementById('edit_detail')?.value || '');
        // Video fields
        const vm = document.querySelector('input[name="edit_video_mode"]:checked')?.value || 'keep';
        formData.append('video_mode', vm);
        if (vm === 'url') {
          const vu = document.getElementById('edit_video_url')?.value?.trim() || '';
          if (vu) formData.append('video_url', vu);
        } else if (vm === 'file') {
          const vf = document.getElementById('edit_video_file');
          if (vf && vf.files && vf.files[0]) formData.append('video_file', vf.files[0]);
        }
        // Hidden image tracking
        formData.append('existing_images', document.getElementById('existingImagesField')?.value || '[]');
        formData.append('removed_images', document.getElementById('removedImagesField')?.value || '[]');
        // File inputs (image1..3) — append only if present
        [1,2,3].forEach(n => {
          const inp = document.getElementById(`image${n}`);
          if (inp && inp.files && inp.files[0]) {
            formData.append(`image${n}`, inp.files[0]);
          }
        });
        
        try {
          const response = await fetch('update_post.php', {
            credentials: 'same-origin',
            method: 'POST',
            body: formData
          });
          
          const result = await response.json();
          
          if (result && result.success) {
            alert('บันทึกการแก้ไขสำเร็จ');
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('editPostModal'));
            if (modal) modal.hide();
            
            // Reload page or update UI
            location.reload();
          } else {
            alert('เกิดข้อผิดพลาด: ' + (result.message || 'ไม่สามารถบันทึกได้'));
          }
      
}catch (error) {
          console.error('Error:', error);
          alert('เกิดข้อผิดพลาดในการบันทึก');
        }
      });
    }
  }
  
  // Global function to open edit modal
  window.editPost = async function(postId) {
    currentEditModal = document.getElementById('editPostModal');
    const modal = bootstrap.Modal.getOrCreateInstance(currentEditModal);
    
    // Reset form
    document.getElementById('editPostForm').reset();
    document.getElementById('edit_post_id').value = postId;
    
    // Clear all slots
    clearAllSlots();
    
    // Initialize counters
    initCounters();
    
    // Show modal
    modal.show();
    
    try {
      // Fetch post data
      const response = await fetch(`get_post_detail.php?postId=${postId}`);
      const data = await response.json();
      
      if (data.success && data.data) {
        const post = data.data;
        
        // Fill form fields
        document.getElementById('edit_item').value = post.name || '';
        document.getElementById('edit_category').value = post.Waste_type || '';
        document.getElementById('edit_method').value = post.Management_type || '';
        document.getElementById('edit_title').value = post.title || '';
        document.getElementById('edit_detail').value = post.details || '';
        
        // Setup current video UI
        try {
          const modeKeep = document.querySelector('input[name="edit_video_mode"][value="keep"]');
          const modeNone = document.querySelector('input[name="edit_video_mode"][value="none"]');
          const modeUrl  = document.querySelector('input[name="edit_video_mode"][value="url"]');
          const modeFile = document.querySelector('input[name="edit_video_mode"][value="file"]');
          const curGrp = document.getElementById('currentVideoGroup');
          const curPrev = document.getElementById('currentVideoPreview');
          const urlGrp = document.getElementById('editVideoUrlGroup');
          const fileGrp = document.getElementById('editVideoFileGroup');
          const platSpan = document.getElementById('editVideoPlatform');
          const urlInp = document.getElementById('edit_video_url');
          const urlPrevWrap = document.getElementById('editVideoUrlPreview');
          const urlIframe = document.getElementById('editVideoUrlIframe');
          const fileInput = document.getElementById('edit_video_file');
          const filePrev = document.getElementById('editVideoFilePreview');

          function detectP(u){ try{ const host=(new URL(u)).host.replace(/^www\./,''); if(host.includes('youtube.com')||host.includes('youtu.be'))return'youtube'; if(host.includes('tiktok.com'))return'tiktok'; if(host.includes('instagram.com'))return'instagram'; if(host.includes('facebook.com')||host.includes('fb.watch'))return'facebook'; return null;}catch{return null;} }
          function buildEmbed(p,u){ if(p==='youtube'){ const m=u.match(/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/); return m?`https://www.youtube.com/embed/${m[1]}`:null;} if(p==='tiktok'){ const m=u.match(/\/video\/(\d+)/); return m?`https://www.tiktok.com/embed/v2/video/${m[1]}`:null;} if(p==='instagram'){ const m=u.match(/instagram\.com\/(p|reel|tv)\/([A-Za-z0-9_-]+)/); return m?`https://www.instagram.com/${m[1]}/${m[2]}/embed`:null;} if(p==='facebook'){ return `https://www.facebook.com/plugins/video.php?href=${encodeURIComponent(u)}&show_text=0`; } return null; }

          function setMode(m){
            urlGrp.classList.toggle('hidden', m!=='url');
            fileGrp.classList.toggle('hidden', m!=='file');
            curGrp.classList.toggle('hidden', !(post.video_source && post.video_source !== 'none' && m==='keep'));
            if(m!=='url'){ urlPrevWrap.classList.add('hidden'); urlIframe.src=''; urlInp.value=''; platSpan.textContent='-'; }
            if(m!=='file'){ filePrev.classList.add('hidden'); if(fileInput) fileInput.value=''; }
          }

          // Default selection based on existing
          if (post.video_source && post.video_source !== 'none') {
            if (modeKeep) modeKeep.checked = true;
            curGrp.classList.remove('hidden');
            if (post.video_source === 'url') {
  const embed = buildEmbed(post.video_platform, post.video_url);
  if (embed) {
   curPrev.innerHTML = `<div class='aspect-video w-full'><iframe src='${embed}' class='w-full h-full rounded' loading='lazy' allowfullscreen></iframe></div>`;
  } else if (post.video_platform === 'tiktok' && post.video_url) {
    curPrev.innerHTML = `<blockquote class='tiktok-embed' cite='${post.video_url}' style='max-width:605px;min-width:325px;'><section></section></blockquote>`;
    if(!document.querySelector('script[data-tiktok-embed]')){ const s=document.createElement('script'); s.src='https://www.tiktok.com/embed.js'; s.async=true; s.setAttribute('data-tiktok-embed','1'); document.body.appendChild(s);} 
  } else {
    curPrev.innerHTML = '';
  }
            } else if (post.video_source === 'file') {
              curPrev.innerHTML = `<video class='w-full rounded' controls preload='metadata' src='uploads/videos/${post.video_path}'></video>`;
            } else { curPrev.innerHTML = ''; }
          } else {
            if (modeNone) modeNone.checked = true;
            curGrp.classList.add('hidden');
          }
          setMode(document.querySelector('input[name="edit_video_mode"]:checked')?.value || 'keep');

          // Bind listeners
          [modeKeep, modeNone, modeUrl, modeFile].forEach(el => el && el.addEventListener('change', (e)=> setMode(e.target.value)) );
          if (urlInp) urlInp.addEventListener('input', ()=>{ const u=urlInp.value.trim(); const p=detectP(u); platSpan.textContent=p||'-'; const emb=p?buildEmbed(p,u):null; if(emb){ urlPrevWrap.innerHTML = `<div class="aspect-video w-full"><iframe class="w-full h-full rounded" src="${emb}" allowfullscreen loading="lazy"></iframe></div>`; urlPrevWrap.classList.remove('hidden'); } else if (p==='tiktok' && u){ urlPrevWrap.innerHTML = `<blockquote class="tiktok-embed" cite="${u}" style="max-width:605px;min-width:325px;"><section></section></blockquote>`; if(!document.querySelector('script[data-tiktok-embed]')){ const s=document.createElement('script'); s.src='https://www.tiktok.com/embed.js'; s.async=true; s.setAttribute('data-tiktok-embed','1'); document.body.appendChild(s);} urlPrevWrap.classList.remove('hidden'); } else { urlPrevWrap.innerHTML=''; urlPrevWrap.classList.add('hidden'); }});
          if (fileInput) fileInput.addEventListener('change', ()=>{ if(fileInput.files&&fileInput.files[0]){ const f=fileInput.files[0]; const ok=['video/mp4','video/webm','video/ogg'].includes(f.type)&&f.size<=40*1024*1024; if(!ok){ alert('รองรับ MP4/WebM/Ogg และไม่เกิน 50MB'); fileInput.value=''; filePrev.classList.add('hidden'); return; } const url=URL.createObjectURL(f); filePrev.src=url; filePrev.classList.remove('hidden'); } else { filePrev.src=''; filePrev.classList.add('hidden'); } });
        } catch(e) { console.warn('init video UI failed', e); }

        // Update counters
        const titleCounter = document.getElementById('titleCounter');
        const detailCounter = document.getElementById('detailCounter');
        if (titleCounter) titleCounter.textContent = `${post.title?.length || 0}/50`;
        if (detailCounter) detailCounter.textContent = `${post.details?.length || 0}/500`;
        
        // Load existing images
        let images = post.images || post.image || [];
        if (typeof images === 'string') {
          try {
            images = JSON.parse(images);
          } catch {
            images = [];
          }
        }
        
        if (!Array.isArray(images)) images = [];
        
        existingImages = images.slice(0, 3);
        
        // Display existing images
        existingImages.forEach((filename, index) => {
          setExistingImage(index + 1, `img/post_img/${filename}`, filename);
        });
        
        updateHiddenFields();
      }
    } catch (error) {
      console.error('Error loading post:', error);
      alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
  };
  
  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEventListeners);
  } else {
    initEventListeners();
  }
})();
</script>



