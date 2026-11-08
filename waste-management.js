// waste-management.js
function ensureTikTokEmbedScriptAndParse(scopeEl) {
  // โหลดสคริปต์ครั้งเดียว
  if (!document.querySelector('script[data-tiktok-embed]')) {
    const s = document.createElement('script');
    s.src = 'https://www.tiktok.com/embed.js';
    s.async = true;
    s.setAttribute('data-tiktok-embed', '1');
    document.body.appendChild(s);
  } else if (window.tiktokEmbedLoad) {
    // ถ้ามีอยู่แล้ว ให้ re-parse เฉพาะ scope (หรือทั้งหน้า)
    try { window.tiktokEmbedLoad(); } catch (_) {}
  }
  // กันพลาด: ลอง parse อีกครั้งหลัง DOM ทันที
  setTimeout(() => { try { window.tiktokEmbedLoad && window.tiktokEmbedLoad(); } catch(_){} }, 0);
}
// --- TikTok robust extractors ---
function _extractTikTok(input) {
  const s = String(input || '');
  try {
    const u = new URL(s);
    if (u.host.includes('tiktok.com')) {
      if (u.host.startsWith('m.')) u.host = 'www.tiktok.com';
      const id = (u.pathname.match(/\/video\/(\d+)/)||[])[1] || '';
      return { url: u.toString(), id };
    }
  } catch(_){}
  let m = s.match(/cite="([^"]+)"/i);
  if (m) {
    try {
      const u = new URL(m[1]);
      if (u.host.startsWith('m.')) u.host = 'www.tiktok.com';
      const id = (u.pathname.match(/\/video\/(\d+)/)||[])[1] || '';
      return { url: u.toString(), id };
    } catch(_){ return { url: m[1], id: '' }; }
  }
  m = s.match(/href="([^"]+)"/i);
  if (m && /tiktok\.com/i.test(m[1])) {
    try {
      const u = new URL(m[1]);
      if (u.host.startsWith('m.')) u.host = 'www.tiktok.com';
      const id = (u.pathname.match(/\/video\/(\d+)/)||[])[1] || '';
      return { url: u.toString(), id };
    } catch(_){ return { url: m[1], id: '' }; }
  }
  m = s.match(/https?:\/\/[^\s"'<>]*tiktok\.com[^\s"'<>]*/i);
  if (m) {
    try {
      const u = new URL(m[0]);
      if (u.host.startsWith('m.')) u.host = 'www.tiktok.com';
      const id = (u.pathname.match(/\/video\/(\d+)/)||[])[1] || '';
      return { url: u.toString(), id };
    } catch(_){ return { url: m[0], id: '' }; }
  }
  m = s.match(/data-video-id="(\d+)"/i);
  if (m) return { url: '', id: m[1] };
  return { url: '', id: '' };
}
function _buildTikTokBlockquote(url){
  return `<blockquote class="tiktok-embed" cite="${url}" style="max-width:605px;min-width:325px;"><section></section></blockquote>`;
}
function _buildTikTokIframeById(id){
  if (!id) return '';
  return `<div class="aspect-video w-full"><iframe src="https://www.tiktok.com/embed/v2/video/${id}" allowfullscreen loading="lazy" class="w-full h-full rounded" referrerpolicy="no-referrer-when-downgrade"></iframe></div>`;
}

window.detectPlatform = window.detectPlatform || function(url) {
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
};

window.buildEmbed = window.buildEmbed || function(platform, url) {
  switch (platform) {
    case 'youtube': {
      const m = url.match(/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/);
      return m ? `https://www.youtube.com/embed/${m[1]}` : null;
    }
    case 'tiktok': {
                // Use blockquote + embed.js instead of iframe
                return null;
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
};

class WasteManagement {
    
    constructor() {
        this.posts = [];
        this.currentFilter = {
            wasteType: 'all',
            managementType: 'all',
            searchQuery: ''
        };
        this.currentPage = 1;
        this.postsPerPage = 8; // เปลี่ยนจาก 6 เป็น 8 โพสต์ต่อหน้า
        this.debug = true;

        // --- Sorting state (default: newest first)
        this.currentSort = { key: 'date', dir: 'desc' };
    
        // Define waste type mappings
        this.wasteTypeMap = {
            'general': 'ขยะทั่วไป',
            'recycle': 'ขยะรีไซเคิล',
            'hazardous': 'ขยะอันตราย',
            'all': 'all'
        };
    
        // แก้ไข managementTypeMap ในส่วน constructor
        this.managementTypeMap = {
            'recycle': 'recycle',  // รีไซเคิล
            'dispose': 'dispose',  // ทิ้ง
            'reuse': 'reuse',     // นำกลับมาใช้ใหม่
            'all': 'all'
        };
    
        // Bind methods
        this.filterWaste = this.filterWaste.bind(this);
        this.filterManagement = this.filterManagement.bind(this);
        this.filterPosts = this.filterPosts.bind(this);
        this.renderPosts = this.renderPosts.bind(this);
        this.searchPosts = this.searchPosts.bind(this);
        this.changePage = this.changePage.bind(this); // ผูกเมธอด changePage

        // Try to bind sort controls after instantiation
        setTimeout(() => { try { this.bindSortControls && this.bindSortControls(); } catch (e) { console.warn('bindSortControls failed', e); } try { this.bindFilterControls && this.bindFilterControls(); } catch (e) { console.warn('bindFilterControls failed', e); } }, 0);
    }

    log(message, data) {
        if (this.debug) {
            console.log(`[WasteManagement] ${message}:`, data);
        }
    }

    // --- Video helpers ---
    hasVideo(post) {
        return (post && (
            (post.video_source === 'url' && post.video_url && post.video_platform) ||
            (post.video_source === 'file' && post.video_path)
        ));
    }
async showCustomAlert({ title = '', message = '', type = 'info', showInput = false, confirmText = 'ตกลง', cancelText = 'ยกเลิก', maxLength = 255 } = {}) {
  if (!window.Swal || !window.Swal.fire) {
    alert(message); // fallback
    return { confirmed: true, value: null };
  }
  const result = await Swal.fire({
    title,
    html: showInput ? `<input id="swal-input" class="swal2-input" maxlength="${maxLength}" placeholder="พิมพ์ที่นี่...">` : message,
    icon: type, // 'success' | 'error' | 'warning' | 'info' | 'question'
    showCancelButton: !!showInput,
    confirmButtonText: confirmText,
    cancelButtonText: cancelText,
    didOpen: () => {
      if (showInput) document.getElementById('swal-input').focus();
    }
  });
  return {
    confirmed: result.isConfirmed,
    value: showInput ? (document.getElementById('swal-input')?.value ?? '') : null
  };
}
getEmbedUrl(platform, url) {
    try {
        if (!platform || !url) return null;
        switch (platform) {
            case 'youtube': {
                const m = url.match(/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/);
                return m ? `https://www.youtube.com/embed/${m[1]}?rel=0&showinfo=0&modestbranding=1` : null;
            }
            case 'tiktok': {
                // Use blockquote + embed.js instead of iframe
                return null;
            }
            case 'instagram': {
                const m = url.match(/instagram\.com\/(p|reel|tv)\/([A-Za-z0-9_-]+)/);
                if (m) {
                    // ใช้ oEmbed API หรือ clean embed URL
                    return `https://www.instagram.com/${m[1]}/${m[2]}/embed/?cr=1&v=14&wp=1080&hp=1350#%7B%22ci%22%3A0%2C%22os%22%3A0%7D`;
                }
                return null;
            }
            case 'facebook': {
                // Use <div class=\"fb-video\"> + Facebook SDK instead of iframe plugin URL
                return null;
            }
            default:
                return null;
        }
    } catch (e) { 
        console.warn('getEmbedUrl error', e); 
        return null; 
    }
}
async getInstagramEmbedHtml(instagramUrl) {
    try {
        const oEmbedUrl = `https://api.instagram.com/oembed?url=${encodeURIComponent(instagramUrl)}&omitscript=true&hidecaption=true`;
        const response = await fetch(oEmbedUrl);
        const data = await response.json();
        
        if (data.html) {
            // ปรับแต่ง HTML ที่ได้จาก oEmbed
            let html = data.html;
            // เอา script tag ออก
            html = html.replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
            // ปรับขนาด
            html = html.replace(/width="\d+"/g, 'width="100%"');
            html = html.replace(/height="\d+"/g, 'height="100%"');
            
            return html;
        }
        return null;
    } catch (error) {
        console.error('Error fetching Instagram oEmbed:', error);
        return null;
    }
}
async renderInstagramPreview(post) {
    const instagramUrl = post.video_url;
    
    // ลองใช้ oEmbed API ก่อน
    try {
        const oEmbedResponse = await fetch(`https://api.instagram.com/oembed/?url=${encodeURIComponent(instagramUrl)}`);
        const oEmbedData = await oEmbedResponse.json();
        
        if (oEmbedData.thumbnail_url) {
            return `
                <div class="instagram-preview relative cursor-pointer" 
                     onclick="window.open('${instagramUrl}', '_blank')"
                     style="background-image: url('${oEmbedData.thumbnail_url}'); background-size: cover; background-position: center;">
                    <div class="absolute inset-0 bg-black bg-opacity-30 flex items-center justify-center">
                        <div class="bg-white rounded-full p-3">
                            <i class="fab fa-instagram text-2xl text-pink-600"></i>
                        </div>
                    </div>
                    <div class="absolute bottom-2 left-2 text-white text-sm bg-black bg-opacity-50 px-2 py-1 rounded">
                        ดูใน Instagram
                    </div>
                </div>
            `;
        }
    } catch (error) {
        console.log('oEmbed failed, using fallback');
    }
    
    // Fallback: แสดงลิงก์ไปยัง Instagram
    return `
        <div class="instagram-fallback relative cursor-pointer bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center text-white"
             onclick="window.open('${instagramUrl}', '_blank')">
            <div class="text-center">
                <i class="fab fa-instagram text-4xl mb-2"></i>
                <p class="text-sm">ดูวิดีโอใน Instagram</p>
            </div>
        </div>
    `;
}
renderVideoHtml(post, forModal = false) {
    if (!this.hasVideo(post)) return '';
    
    // กำหนด container classes ตาม context
    const containerClass = forModal ? 
        'video-embed video-modal' : 
        'video-embed video-feed';
    
    // สร้าง play overlay สำหรับ share card เท่านั้น
    const playOverlay = !forModal ? `
        <div class="video-play-overlay" onclick='window.wasteManager.openPostDetailModal(${JSON.stringify(post).replace(/"/g, "&quot;")}); event.stopPropagation();'>
            <div class="video-play-button"></div>
        </div>
    ` : '';
    
    // สร้าง video type badge
    const getVideoBadge = (platform, source) => {
        let icon = '';
        let text = '';
        
        if (source === 'file') {
            icon = 'fas fa-file-video';
            text = 'วิดีโอ';
        } else if (platform === 'youtube') {
            icon = 'fab fa-youtube';
            text = 'YouTube';
        } else if (platform === 'tiktok') {
            icon = 'fab fa-tiktok';
            text = 'TikTok';
        } else if (platform === 'instagram') {
            icon = 'fab fa-instagram';
            text = 'Instagram';
        } else if (platform === 'facebook') {
            icon = 'fab fa-facebook';
            text = 'Facebook';
        } else {
            icon = 'fas fa-play';
            text = 'วิดีโอ';
        }
        
        return `<div class="video-type-badge">
            <i class="${icon}"></i>
            <span>${text}</span>
        </div>`;
    };
    
    const videoBadge = getVideoBadge(post.video_platform, post.video_source);
    
    const wrapStart = `<div class="${containerClass}" data-platform="${post.video_platform || 'local'}">`;
    const wrapEnd = videoBadge + playOverlay + '</div>';
    
    if (post.video_source === 'url') {
        const src = this.getEmbedUrl(post.video_platform, post.video_url);
        if (src) {
            const iframe = `<iframe 
                src="${src}" 
                ${forModal ? '' : 'style="pointer-events:none;"'} 
                allowfullscreen 
                loading="lazy" 
                class="w-full h-full"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
            </iframe>`;
            return wrapStart + iframe + wrapEnd;
        }
        
        // TikTok specific handling
if (post.video_platform === 'tiktok') {
  // post.video_url ควรเป็นลิงก์รูปแบบ https://www.tiktok.com/@user/video/VIDEOID
  const tiktokUrl = (post.video_url || '').trim();
  if (tiktokUrl) {
    // blockquote แบบเดิม (เสถียรสำหรับเคสของคุณ)
    const bq =
      `<blockquote class="tiktok-embed" cite="${tiktokUrl}" style="max-width:605px;min-width:325px;">
         <section></section>
       </blockquote>`;
    const html = forModal ? bq : `<div style="pointer-events:none">${bq}</div>`;
    // ให้สคริปต์พร้อมเสมอ
    ensureTikTokEmbedScriptAndParse(null);
    return wrapStart + html + wrapEnd;
  }
  // ถ้าไม่พบ url ให้ปล่อยตกไปเคสอื่น ๆ ของคุณต่อไป
}
        
        // Facebook specific handling using SDK
        if (post.video_platform === 'facebook') {
            const fbBlock = `<div class="fb-video" data-href="${post.video_url}" data-width="auto" data-allowfullscreen="true"></div>`;

            // Load Facebook SDK once
            if (!document.getElementById('facebook-jssdk')) {
                const fbRoot = document.getElementById('fb-root') || (function(){ const d=document.createElement('div'); d.id='fb-root'; document.body.appendChild(d); return d; })();
                const s = document.createElement('script');
                s.id = 'facebook-jssdk';
                s.async = true;
                s.src = 'https://connect.facebook.net/th_TH/sdk.js#xfbml=1&version=v19.0';
                document.body.appendChild(s);
            } else if (window.FB && window.FB.XFBML && window.FB.XFBML.parse) {
                setTimeout(() => { try { window.FB.XFBML.parse(); } catch(_){} }, 0);
            }

            return wrapStart + (forModal ? fbBlock : `<div style="pointer-events:none; width:100%; height:100%; display:flex; align-items:center; justify-content:center;">${fbBlock}</div>`) + wrapEnd;
        }
return '';
    }
    
    // Local video file - มีไอคอน play เสมอ
    const path = `uploads/videos/${post.video_path}`;
    const video = `<video 
        class="w-full h-full object-cover" 
        ${forModal ? 'controls' : 'style="pointer-events:none;"'} 
        preload="metadata" 
        poster=""
        src="${path}">
        <source src="${path}" type="video/mp4">
        ไม่สามารถเล่นวิดีโอได้
    </video>`;
    
    return wrapStart + video + wrapEnd;
}


// เพิ่มฟังก์ชันสำหรับจัดการคลิกที่วิดีโอ
handleVideoClick(post, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    // เปิด modal รายละเอียดโพสต์
    this.openPostDetailModal(post);
}

// เพิ่มฟังก์ชันสำหรับปรับขนาดวิดีโอหลังจากโหลด
adjustVideoLayout() {
    // รอให้ DOM โหลดเสร็จ
    setTimeout(() => {
        document.querySelectorAll('.video-embed').forEach(container => {
            const iframe = container.querySelector('iframe');
            const video = container.querySelector('video');
            
            if (iframe) {
                iframe.addEventListener('load', () => {
                    container.classList.add('loaded');
                });
            }
            
            if (video) {
                video.addEventListener('loadedmetadata', () => {
                    container.classList.add('loaded');
                });
            }
        });
    }, 100);
}


    // Filter functions
    filterWaste(wasteType) {
        this.log('Filtering waste type', wasteType);
        this.currentFilter.wasteType = wasteType;
        this.currentPage = 1;

        // Update UI buttons
        document.querySelectorAll('[data-type]').forEach(element => {
            if (element.tagName === 'BUTTON') {
                element.classList.toggle('active', element.dataset.type === wasteType);
            } else if (element.tagName === 'SELECT') {
                element.value = wasteType;
            }
        });

        return this.renderPosts();
    }

    filterManagement(managementType) {
        this.log('Filtering management type', managementType);
        this.currentFilter.managementType = managementType;
        this.currentPage = 1;

        // Update UI buttons
        document.querySelectorAll('[data-management-type]').forEach(element => {
            if (element.tagName === 'BUTTON') {
                element.classList.toggle('active', element.dataset.managementType === managementType);
            } else if (element.tagName === 'SELECT') {
                element.value = managementType;
            }
        });

        return this.renderPosts();
    }
    filterPosts() {
        let filteredPosts = [...this.posts];

        // Filter by waste type
        if (this.currentFilter.wasteType !== 'all') {
            const wasteTypeToMatch = this.wasteTypeMap[this.currentFilter.wasteType];
            filteredPosts = filteredPosts.filter(post => post.Waste_type === wasteTypeToMatch);
        }

        // Filter by management type
        if (this.currentFilter.managementType !== 'all') {
            const managementTypeToMatch = this.managementTypeMap[this.currentFilter.managementType];
            filteredPosts = filteredPosts.filter(post => post.Management_type === managementTypeToMatch);
        }

        // Filter by search query
        if (this.currentFilter.searchQuery) {
            const query = this.currentFilter.searchQuery.toLowerCase();
            filteredPosts = filteredPosts.filter(post => {
                return (
                    post.username?.toLowerCase().includes(query) ||
                    post.title?.toLowerCase().includes(query) ||    // เพิ่มการค้นหาจากชื่อโพสต์
                    post.Waste_type?.toLowerCase().includes(query) ||
                    post.Management_type?.toLowerCase().includes(query) ||
                    post.details?.toLowerCase().includes(query)
                );
            });
        }

        return filteredPosts;
    }
    updateCommentCount(postId, commentCount) {
        console.log(`Updating comment count for post ${postId} to ${commentCount}`);
        
        try {
          // อัปเดตจำนวนคอมเมนต์ในการ์ดหลัก
          document.querySelectorAll(`.share-card[data-post-id="${postId}"] .comments-button span`).forEach(span => {
            span.textContent = span.classList.contains('comment-text') ? 
              `${commentCount} คอมเมนต์` : `${commentCount}`;
          });
          
          // อัปเดตจำนวนคอมเมนต์ในโมดัลรายละเอียดโพสต์
          document.querySelectorAll(`#postDetailRightSide .comments-button span`).forEach(span => {
            span.textContent = span.classList.contains('comment-text') ? 
              `${commentCount} คอมเมนต์` : `${commentCount}`;
          });
          
          // อัปเดตจำนวนคอมเมนต์ในโมดัลโปรไฟล์
          document.querySelectorAll(`.modal-post[data-post-id="${postId}"] .comments-button span`).forEach(span => {
            span.textContent = span.classList.contains('comment-text') ? 
              `${commentCount} คอมเมนต์` : `${commentCount}`;
          });
          
          // อัปเดตในการ์ดโพสต์ของหน้า my_post.php
          document.querySelectorAll(`.post-card[data-post-id="${postId}"] .comments-button span`).forEach(span => {
            span.textContent = commentCount;
          });
          
          // อัปเดตค่าในตัวแปร post ด้วย
          if (this.posts && this.posts.length > 0) {
            const post = this.posts.find(p => p.post_id == postId);
            if (post) {
              post.comment_count = commentCount;
            }
          }
          
          // อัปเดต window.allPosts ถ้ามี (สำหรับหน้า my_post.php)
          if (window.allPosts && Array.isArray(window.allPosts) && window.allPosts.length > 0) {
            const post = window.allPosts.find(p => p.post_id == postId);
            if (post) {
              post.comment_count = commentCount;
              console.log('Updated comment count in window.allPosts:', post);
            }
          }
          
          console.log(`Comment count updated successfully to ${commentCount}`);
        } catch (error) {
          console.error('Error updating comment count:', error);
        }
      }

    // Rendering functions
    renderPost(post) {
    console.log('Rendering post:', post);

    if (!post || typeof post !== 'object') {
        console.error('Invalid post object:', post);
        return '';
    }

    // ตรวจสอบว่าเป็นโพสต์ของผู้ใช้ปัจจุบันหรือไม่
    const isCurrentUserPost = post.userID === this.currentUserId;

    const truncateTitle = (title, maxLength = 40) => {
        if (!title) return 'ไม่มีชื่อ';
        return title.length > maxLength ? title.substring(0, maxLength) + '...' : title;
    };

    // สร้าง HTML สำหรับเมนูโพสต์
    const postMenuHtml = isCurrentUserPost ? `
    <div class="relative mt-2 mr-5">
        <button 
            onclick="event.stopPropagation(); window.wasteManager.togglePostMenu(${post.post_id})"
            class="absolute top-2 right-2 text-gray-600 hover:text-gray-800 focus:outline-none z-10"
        >
            <i class="fas fa-ellipsis-h"></i>
        </button>
        <div 
            id="post-menu-${post.post_id}" 
            class="hidden absolute right-0 top-8 mt-2 w-48 bg-white rounded-lg shadow-lg border z-50"
        >
            <ul class="py-1 pr-2">
    <li>
        <button 
  onclick="event.preventDefault(); event.stopPropagation(); window.editPost(${post.post_id});"
  class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-black"
>
  <i class="fas fa-edit mr-2"></i>แก้ไข
</button>
    </li>
    <li>
        <button 
            onclick="window.wasteManager.deletePost(${post.post_id})"
            class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600"
        >
            <i class="fas fa-trash mr-2"></i>ลบ
        </button>
    </li>
</ul>
        </div>
    </div>
` : '';

    const isLiked = parseInt(post.is_liked) === 1;
    const isRecyclable = post.Waste_type === "ขยะรีไซเคิล" && post.Management_type === "recycle";

    const images = Array.isArray(post.images)
        ? post.images.filter(img => img && typeof img === 'string')
        : [];

    const truncatedDetails = post.details.length > 50
        ? post.details.substring(0, 50) + '...'
        : post.details;

    const imageHtml = images.length > 0 ?
        `<div class="img-container mb-2 relative" onclick='window.wasteManager.openPostDetailModal(${JSON.stringify(post).replace(/"/g, "&quot;")})'>
            <div class="carousel-container" id="carousel-${post.post_id}">
                <div class="carousel-inner absolute inset-0">
                    ${images.map((image, index) => `
                        <div class="carousel-item absolute inset-0 ${index === 0 ? 'active' : ''}" data-index="${index}">
                            <div class="w-full h-full flex items-center justify-center">
                                <img 
                                    src="img/post_img/${image.trim()}" 
                                    class="max-w-full max-h-full object-cover cursor-pointer" 
                                    alt="Post image ${index + 1}"
                                />
                            </div>
                        </div>
                    `).join('')}
                </div>
                ${images.length > 1 ? `
    <button class="carousel-control prev absolute left-2 top-1/2 -translate-y-1/2 bg-black bg-opacity-50 text-white rounded-full p-2 opacity-0 transition-opacity duration-300 group-hover:opacity-100" 
            onclick="event.stopPropagation(); window.wasteManager.prevImage(${post.post_id})">
        <i class="fas fa-chevron-left"></i>
    </button>
    <button class="carousel-control next absolute right-2 top-1/2 -translate-y-1/2 bg-black bg-opacity-50 text-white rounded-full p-2 opacity-0 transition-opacity duration-300 group-hover:opacity-100" 
            onclick="event.stopPropagation(); window.wasteManager.nextImage(${post.post_id})">
        <i class="fas fa-chevron-right"></i>
    </button>
    <div class="absolute bottom-2 left-1/2 transform -translate-x-1/2 opacity-0 transition-opacity duration-300 group-hover:opacity-100"
         style="background-color: rgba(0, 0, 0, 0.5); color: white; padding: 4px 12px; border-radius: 9999px; font-size: 14px; z-index: 2;">
        <span id="counter-${post.post_id}">1/${images.length}</span>
    </div>
` : ''}
            </div>
        </div>`
        : '';

    return `
        <div class="share-card relative group" data-post-id="${post.post_id}">
            ${postMenuHtml}
            <div class="share-card-content">
                <!-- ส่วนหัวการ์ด -->
                <div class="post-header">
                    <div class="flex items-center mb-3">
                        <img src="img/profile_uploads/${post.avatar_path || 'default.jpg'} "
                             alt="${post.username || 'User'}" 
                             class="w-10 h-10 rounded-full cursor-pointer hover:opacity-80 transition-opacity" style="object-fit: cover;"
                             onerror="this.src='img/profile_uploads/default.jpg'"
                             onclick="window.wasteManager.openProfileModal(${post.userID})">
                        <div class="ml-3">
                            <div class="font-bold text-sm cursor-pointer hover:text-green-600 transition-colors"
                                 onclick="window.wasteManager.openProfileModal(${post.userID})">
                                ${post.username || 'Anonymous'}
                            </div>
                            <div class="text-gray-500 text-xs">
                                ${new Date(post.date).toLocaleString('th-TH', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        })}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ส่วนเนื้อหาการ์ด -->
                <div class="post-body">
                    <h3 class="text-lg font-semibold mb-2" title="${post.title || 'ไม่มีชื่อ'}">
                        ${truncateTitle(post.title)}
                    </h3>
                    
                    <div class="waste-info mb-3">
                        <p class="font-bold text-sm mb-1">
                            <span class="text-gray-600">ประเภทขยะ:</span> 
                            <span class="text-green-600">${post.Waste_type || 'ไม่ระบุ'}</span>
                            <span class="mx-4 text-gray-400">|</span>
                            <span class="text-gray-600">วิธีจัดการ:</span> 
                            <span class="text-green-600">${post.Management_type || 'ไม่ระบุ'}</span>
                        </p>
                    </div>
                    
                    
                </div>

                <!-- ส่วนมีเดีย (วิดีโอ/รูปภาพ) -->
                ${ this.hasVideo(post)
                    ? `<div class=\"relative\">${this.renderVideoHtml(post)}${images.length>0?`<div class=\"absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1\">+${images.length} รูป</div>`:''}</div>`
                    : imageHtml }
                    

                <!-- ส่วนท้ายการ์ด -->
                <div class="post-footer">
                    <div class="flex items-center justify-between mt-3 pt-3 border-t">
                        <button class="like-button ${isLiked ? 'active' : ''} 
                                     flex items-center space-x-1 text-gray-500 hover:text-red-500 transition-colors" 
                                onclick="window.wasteManager.toggleLike(${post.post_id})">
                            <i class="fas fa-heart ${isLiked ? 'text-red-500' : ''}"></i>
                            <span class="likes-count">${post.likes || 0}</span>
                        </button>
                        
                        <button class="comments-button text-gray-500 hover:text-green-600 flex items-center space-x-2 transition-colors"
                                onclick="window.wasteManager.openCommentsModal(${post.post_id})">
                            <i class="fas fa-comment"></i>
                            <span class="text-sm comment-text sm:inline hidden">${post.comment_count || 0} คอมเมนต์</span>
                            <span class="text-sm comment-count sm:hidden inline">${post.comment_count || 0}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
}



    async renderPosts() {
    try {
        const filteredPosts = this.filterPosts();
        const postsForSort = this.applySort ? this.applySort(filteredPosts) : filteredPosts;
        console.log(`Total filtered posts: ${filteredPosts.length}`);
        
        const startIndex = (this.currentPage - 1) * this.postsPerPage;
        const endIndex = startIndex + this.postsPerPage;
        const paginatedPosts = postsForSort.slice(startIndex, endIndex);
        
        console.log(`Rendering posts for page ${this.currentPage}: ${startIndex} to ${endIndex-1}`);

        const grid = document.getElementById('wasteGrid');
        if (!grid) {
            console.error('wasteGrid element not found');
            return;
        }

        if (paginatedPosts.length === 0) {
            grid.innerHTML = `
                <div class="col-span-full text-center py-8">
                    <p class="text-gray-500">ไม่พบโพสต์</p>
                    <button onclick="window.wasteManager.resetFilters()" 
                            class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        แสดงข้อมูลทั้งหมด
                    </button>
                </div>`;
        } else {
            const approvedPosts = paginatedPosts.filter(post => post.post_status === 'approved');
            console.log(`Approved posts for this page: ${approvedPosts.length}`);
            
            grid.innerHTML = approvedPosts.map(post => this.renderPost(post)).join('');
            
            // ปรับ layout ของวิดีโอหลังจาก render
            this.adjustVideoLayout();
            
            // Ensure TikTok embed script present
            if (!document.querySelector('script[data-tiktok-embed]')) {
                const s = document.createElement('script');
                s.src = 'https://www.tiktok.com/embed.js';
                s.async = true;
                s.setAttribute('data-tiktok-embed','1');
                document.body.appendChild(s);
            }
        }

        this.updatePaginationControls(postsForSort.length);
    } catch (error) {
        console.error('Error in renderPosts:', error);
        const grid = document.getElementById('wasteGrid');
        if (grid) {
            grid.innerHTML = `
                <div class="col-span-full text-center py-8">
                    <p class="text-red-500">เกิดข้อผิดพลาดในการโหลดข้อมูล: ${error.message}</p>
                </div>`;
        }
    }
}


    // เพิ่มเมธอดสำหรับตรวจสอบสถานะโพสต์
    isPostViewable(post) {
        return post.post_status === 'approved';
    }

    async fetchPosts() {
        try {
            // ดึง userID ของผู้ใช้ปัจจุบัน
            const userResponse = await fetch('get_current_user.php');
            const userData = await userResponse.json();
            this.currentUserId = userData.userId;

            const response = await fetch('get_posts.php');
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();

            // กรองเฉพาะโพสต์ที่มีสถานะ approved
            this.posts = Array.isArray(data) ?
                data.filter(post => post.post_status === 'approved') : [];

            return this.posts;
        } catch (error) {
            console.error('Error fetching posts:', error);
            this.posts = [];
            return [];
        }
    }

    // Initialize and fetch data

    async reportComment(commentId, postId) {
        try {
            const result = await this.showCustomAlert({
                title: 'รายงานความคิดเห็น',
                message: 'กรุณาระบุเหตุผลในการรายงานความคิดเห็นนี้',
                type: 'warning',
                showInput: true,
                confirmText: 'รายงาน',
                cancelText: 'ยกเลิก',
                maxLength: 300
            });

            if (!result.confirmed) return;

            const formData = new FormData();
            formData.append('commentId', commentId);
            formData.append('reason', String(result.value || '').slice(0, 300));
            formData.append('postId', postId);

            const response = await fetch('report_comment.php', {
                method: 'POST',
                body: formData
            });

            const responseData = await response.json();

            if (responseData.success) {
                await this.showCustomAlert({
                    title: 'สำเร็จ',
                    message: 'ขอบคุณสำหรับการรายงาน เราจะดำเนินการตรวจสอบโดยเร็วที่สุด',
                    type: 'success',
                    confirmText: 'ตกลง',
                    showInput: false
                });
            } else {
                throw new Error(responseData.error || 'เกิดข้อผิดพลาดในการรายงาน');
            }
        } catch (error) {
            console.error('Error reporting comment:', error);
            await this.showCustomAlert({
                title: 'เกิดข้อผิดพลาด',
                message: error.message || 'ไม่สามารถรายงานความคิดเห็นได้ในขณะนี้',
                type: 'error',
                confirmText: 'ตกลง',
                showInput: false
            });
        }
    }

    // เพิ่มเมธอดใหม่ใน WasteManagement class

    ensureProfileModalExists() {
        let profileModal = document.getElementById('profileModal');
        if (!profileModal) {
            const modalHtml = `
            <div id="profileModal" class="profile-modal">
                <div class="profile-modal-content">
                    <div class="profile-modal-header">
                        <button class="close-profile" onclick="window.wasteManager.closeProfileModal()">
                            <i class="fas fa-times"></i>
                        </button>
                        
                        <div class="profile-info-container">
                            <img id="profileImage" 
                                src="img/profile_uploads/default.jpg" 
                                alt="Profile" 
                                class="profile-avatar"
                                onerror="this.src='img/profile_uploads/default.jpg'">
                                
                            <div class="profile-user-info">
                                <h2 id="profileName"></h2>
                                <p id="profileEmail"></p>
                                <div class="post-stats mt-3">
                                <h5><span id="postCount">0</span> โพสต์
                                </h5>
                                    
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-posts">
                        <div id="userPostsGrid" class="profile-posts-grid">
                            <!-- โพสต์จะถูกเพิ่มที่นี่ -->
                        </div>
                    </div>
                </div>
            </div>
        `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        }
    }
renderPostForModal(post) {
  try {
    const pid   = post?.post_id ?? post?.postId ?? '';
    const imgs  = Array.isArray(post?.images) ? post.images.filter(Boolean) : [];
    const hasV  = (typeof this.hasVideo === 'function') ? this.hasVideo(post)
               : !!(post?.video_source && (post.video_url || post.video_path));
    const pjson = JSON.stringify(post || {}).replace(/"/g, '&quot;');

    // ตรวจเป็นเจ้าของโพสต์ให้ robust
    const currentUserId = String(
      this?.currentUser?.id ??
      this?.currentUser?.userID ??
      this?.currentUserId ??
      window.CURRENT_USER_ID ?? ''
    );
    const isOwner = (post?.is_owner != null)
      ? (String(post.is_owner) === '1' || post.is_owner === true)
      : (currentUserId &&
         String(post?.userID ?? post?.userId ?? post?.userid ?? '') === currentUserId);

    // -------- สื่อ (overlay ครอบเฉพาะกล่องสื่อเท่านั้น) --------
    let media = '';
    if (hasV) {
      media = `
        <div class="relative w-full h-60 rounded-lg overflow-hidden" data-postid="${pid}">
          ${typeof this.renderVideoHtml === 'function' ? this.renderVideoHtml(post, /*inProfileModal*/ true) : ''}
          <div class="absolute inset-0 z-30 cursor-pointer"
               onclick='window.wasteManager && window.wasteManager.openPostDetailModal(${pjson})'
               title="เปิดรายละเอียดโพสต์"></div>
        </div>
      `;
    } else if (imgs.length) {
      media = `
        <div class="relative w-full h-60 rounded-lg overflow-hidden"
             data-postid="${pid}"
             onclick='window.wasteManager && window.wasteManager.openPostDetailModal(${pjson})'>
          <img src="img/post_img/${(imgs[0]||'').trim()}"
               class="absolute inset-0 w-full h-full object-cover" alt="">
          ${imgs.length > 1 ? `
            <div class="absolute top-2 right-2 z-10">
              <span class="bg-black/60 text-white text-xs px-2 py-1 rounded">+${imgs.length-1}</span>
            </div>` : ``}
        </div>
      `;
    } else {
      media = `<div class="w-full h-60 rounded-lg bg-gray-100 grid place-items-center text-gray-400">ไม่มีสื่อ</div>`;
    }

    // -------- แถวหัวข้อ + ปุ่ม … อยู่ขวาของหัวข้อ --------
    const title   = (typeof post?.title === 'string')   ? post.title   : (post?.title ?? '');
    const details = (typeof post?.details === 'string') ? post.details : (post?.details ?? '');
    const short   = details && details.length > 80 ? details.slice(0, 80) + '…' : details;

    const titleRow = `
      <div class="flex items-start justify-between px-5 default_cursor_land" style="padding-top: 20px;">
  <div class="text-base font-semibold default_cursor_land flex-1 mr-2">${title || ''}</div>
  ${isOwner ? `
    <div class="relative shrink-0">
      <button
        class="z-50 text-gray-600 hover:text-gray-800 px-1"
        onclick="event.stopPropagation(); window.wasteManager.togglePostMenuModal(${pid}, this)"
        aria-label="เมนูโพสต์">
        <i class="fas fa-ellipsis-v"></i>
      </button>

      <!-- ใช้ id คนละ namespace กับฟีด -->
      <div id="profile-post-menu-${pid}"
           class="hidden absolute right-0 top-8 z-50 w-48 bg-white rounded-lg shadow-lg border">
        <ul class="py-1">
          <li>
            <button
              onclick="event.preventDefault(); event.stopPropagation(); window.editPost(${pid});"
              class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm">
              <i class="fas fa-edit mr-2"></i>แก้ไข
            </button>
          </li>
          <li>
            <button
              onclick="event.stopPropagation(); window.wasteManager.confirmDeletePost(${pid})"
              class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600">
              <i class="fas fa-trash mr-2"></i>ลบ
            </button>
          </li>
        </ul>
      </div>
    </div>
  ` : ``}
</div>
    `;

    // -------- ปุ่มไลก์/คอมเมนต์ (เรียกเมธอดเดียวกับฟีด) --------
    const isLiked   = [1,'1',true,'true'].includes(post?.is_liked);
    const likeCount = Number(post?.likes ?? 0);
    const cmtCount  = Number(post?.comment_count ?? post?.comments ?? 0);

    const actions = `
      <div class="post-footer px-5" style="
    padding-bottom: 20px;">
        <div class="flex items-center justify-between mt-3 pt-3 border-t">
          <button class="like-button ${isLiked ? 'active' : ''} flex items-center space-x-1 text-gray-500 hover:text-red-500"
                  onclick="window.wasteManager.toggleLike(${pid})">
            <i class="fas fa-heart ${isLiked ? 'text-red-500' : ''}"></i>
            <span class="likes-count">${likeCount}</span>
          </button>
          <button class="comments-button text-gray-500 hover:text-green-600 flex items-center space-x-2"
                  onclick="window.wasteManager.openCommentsModal(${pid})">
            <i class="fas fa-comment"></i>
            <span class="text-sm comment-text sm:inline hidden">${cmtCount} คอมเมนต์</span>
            <span class="text-sm comment-count sm:hidden inline">${cmtCount}</span>
          </button>
        </div>
      </div>
    `;

    return `
      <article class="modal-post relative space-y-2" data-post-id="${pid}">
        ${media}
        ${titleRow}
        ${short ? `<div class="text-sm text-gray-600 px-5">${short}</div>` : ``}
        ${actions}
      </article>
    `;
  } catch (e) {
    console.error('renderPostForModal error:', e);
    return `<div class="text-red-500 text-sm">เกิดข้อผิดพลาดในการแสดงผลโพสต์</div>`;
  }
}
togglePostMenuModal(postId, btnEl) {
  try {
    // หา root ของโมดัลโปรไฟล์ (กันหลุดไปคว้า element ในฟีด)
    const modalRoot =
      document.getElementById('profileModal') ||
      (btnEl ? btnEl.closest('#profileModal') : null) ||
      document;

    // ปิดเมนูอื่น ๆ ในโมดัลก่อน
    modalRoot.querySelectorAll('[id^="profile-post-menu-"]').forEach(el => {
      if (el.id !== `profile-post-menu-${postId}`) el.classList.add('hidden');
    });

    const menu = modalRoot.querySelector(`#profile-post-menu-${postId}`);
    if (!menu) return;
    menu.classList.toggle('hidden');

    // คลิกนอกเพื่อปิด (จำกัดขอบเขตในโมดัล)
    const close = (e) => {
      // ถ้าคลิกนอกเมนู และคลิกนอกกล่องปุ่มของโพสต์นี้ → ปิด
      const inMenu = menu.contains(e.target);
      const inBtn = btnEl && btnEl.contains(e.target);
      if (!inMenu && !inBtn) {
        menu.classList.add('hidden');
        modalRoot.removeEventListener('click', close);
      }
    };
    // หน่วง 1 tick กัน event เดียวกันไปปิดเอง
    setTimeout(() => modalRoot.addEventListener('click', close), 0);
  } catch (e) {
    console.warn('togglePostMenuModal error', e);
  }
}





enableProfileVideo(postId) {
  try {
    const wrap = document.querySelector(`[data-postid="${postId}"]`);
    if (!wrap) return;
    const cap = wrap.querySelector('.profile-video-click-capture');
    if (cap) cap.remove();
    const ov = wrap.querySelector('.profile-video-overlay');
    if (ov) ov.remove();
    const vid = wrap.querySelector('video, iframe');
    if (vid && vid.tagName.toLowerCase() === 'video') {
      vid.setAttribute('controls', 'controls');
      try { vid.play(); } catch(_) {}
    }
  } catch (e) {
    console.warn('enableProfileVideo error:', e);
  }
}

toggleProfilePostMenu(postId) {
  try {
    const el = document.getElementById(`profile-post-menu-${postId}`);
    if (!el) return;
    el.classList.toggle('hidden');
  } catch (_) {}
}

async deletePostAndCloseProfileModal(postId) {
  try {
    await this.deletePost(postId);
    // รีโหลดโปรไฟล์ของผู้ใช้ปัจจุบัน (เมนูนี้แสดงเฉพาะโพสต์ของตัวเอง)
    if (typeof this.openProfileModal === 'function' && this.currentUserId) {
      try { await this.openProfileModal(this.currentUserId); } catch(_) {}
    }
  } catch (e) {
    console.error('deletePostAndCloseProfileModal error:', e);
  }
}

    // เพิ่มเมธอดใหม่สำหรับการจัดการเมนูในโปรไฟล์โมดัล
    toggleProfilePostMenu(postId) {
        const menu = document.getElementById(`profile-post-menu-${postId}`);

        // ปิดเมนูอื่นทั้งหมด
        document.querySelectorAll('[id^="profile-post-menu-"]').forEach(otherMenu => {
            if (otherMenu.id !== `profile-post-menu-${postId}`) {
                otherMenu.classList.add('hidden');
            }
        });

        // สลับการแสดงผลเมนูปัจจุบัน
        menu.classList.toggle('hidden');

        // เพิ่มการปิดเมนูเมื่อคลิกนอกเมนู
        if (!menu.classList.contains('hidden')) {
            const closeMenuHandler = (event) => {
                if (!menu.contains(event.target)) {
                    menu.classList.add('hidden');
                    document.removeEventListener('click', closeMenuHandler);
                }
            };

            // เพิ่ม timeout เล็กน้อยเพื่อหลีกเลี่ยง event propagation
            setTimeout(() => {
                document.addEventListener('click', closeMenuHandler);
            }, 0);
        }
    }
    deletePostAndCloseProfileModal(postId) {
        // ปิดเมนูก่อน
        const menu = document.getElementById(`profile-post-menu-${postId}`);
        if (menu) {
            menu.classList.add('hidden');
        }

        // ปิดโมดัลโปรไฟล์
        this.closeProfileModal();

        // เรียกใช้เมธอดลบโพสต์
        this.deletePost(postId);
    }


    nextModalImage(postId) {
        const carousel = document.querySelector(`#modal-carousel-${postId}`);
        if (!carousel) return;

        const items = carousel.querySelectorAll('.carousel-item');
        let activeIndex = -1;

        items.forEach((item, index) => {
            if (item.classList.contains('active')) {
                activeIndex = index;
            }
        });

        const nextIndex = (activeIndex + 1) % items.length;

        // อัพเดทรูปภาพ
        items[activeIndex].classList.remove('active');
        items[activeIndex].style.opacity = '0';
        items[nextIndex].classList.add('active');
        items[nextIndex].style.opacity = '1';

        // อัพเดทตัวนับรูปภาพ
        const counter = document.querySelector(`#modal-counter-${postId}`);
        if (counter) {
            counter.textContent = `${nextIndex + 1}/${items.length}`;
        }
    }
    prevModalImage(postId) {
        const carousel = document.querySelector(`#modal-carousel-${postId}`);
        if (!carousel) return;

        const items = carousel.querySelectorAll('.carousel-item');
        let activeIndex = -1;

        items.forEach((item, index) => {
            if (item.classList.contains('active')) {
                activeIndex = index;
            }
        });

        const prevIndex = (activeIndex - 1 + items.length) % items.length;

        // อัพเดทรูปภาพ
        items[activeIndex].classList.remove('active');
        items[activeIndex].style.opacity = '0';
        items[prevIndex].classList.add('active');
        items[prevIndex].style.opacity = '1';

        // อัพเดทตัวนับรูปภาพ
        const counter = document.querySelector(`#modal-counter-${postId}`);
        if (counter) {
            counter.textContent = `${prevIndex + 1}/${items.length}`;
        }
    }


    goToModalImage(postId, index) {
        const carousel = document.querySelector(`#modal-carousel-${postId}`);
        if (!carousel) return;

        const items = carousel.querySelectorAll('.carousel-item');
        let activeIndex = -1;

        items.forEach((item, i) => {
            if (item.classList.contains('active')) {
                activeIndex = i;
            }
        });

        // อัพเดทรูปภาพ
        items[activeIndex].classList.remove('active');
        items[activeIndex].style.opacity = '0';
        items[index].classList.add('active');
        items[index].style.opacity = '1';

        // อัพเดทตัวนับรูปภาพ
        const counter = document.querySelector(`#modal-counter-${postId}`);
        if (counter) {
            counter.textContent = `${index + 1}/${items.length}`;
        }
    }
    async toggleModalLike(postId) {
        try {
            // เรียกใช้ toggleLike และรอรับค่า result
            const result = await this.toggleLike(postId);

            if (result && result.success) {
                // อัพเดตทั้งใน modal และการ์ดหลัก
                const modalLikeButton = document.querySelector(`#profileModal .modal-post[data-post-id="${postId}"] .like-button`);
                const modalLikeIcon = modalLikeButton?.querySelector('i');
                const modalLikesCount = modalLikeButton?.querySelector('.likes-count');

                const mainLikeButton = document.querySelector(`.share-card[data-post-id="${postId}"] .like-button`);
                const mainLikeIcon = mainLikeButton?.querySelector('i');
                const mainLikesCount = mainLikeButton?.querySelector('.likes-count');

                if (modalLikeButton) {
                    modalLikeButton.classList.toggle('active', result.is_liked);
                    if (modalLikeIcon) {
                        modalLikeIcon.classList.toggle('text-red-500', result.is_liked);
                    }
                    if (modalLikesCount) {
                        modalLikesCount.textContent = result.likes;
                    }
                }

                if (mainLikeButton) {
                    mainLikeButton.classList.toggle('active', result.is_liked);
                    if (mainLikeIcon) {
                        mainLikeIcon.classList.toggle('text-red-500', result.is_liked);
                    }
                    if (mainLikesCount) {
                        mainLikesCount.textContent = result.likes;
                    }
                }
            }
        } catch (error) {
            console.error('Error toggling modal like:', error);
        }
    }
    // เพิ่มเมธอดสำหรับสร้าง Modal Alert ในคลาส WasteManagement
    ensureAlertModalExists() {
        let alertModal = document.getElementById('customAlertModal');
        if (!alertModal) {
            const modalHtml = `
                <div id="customAlertModal" 
                    class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center"
                    style="z-index: 99999; display: none;">
                    <div class="relative bg-white rounded-lg max-w-md w-full mx-4 overflow-hidden shadow-xl transform transition-all">
                        <div class="p-6">
                            <div class="flex items-center mb-4">
                                <div id="alertIcon" class="mr-4 flex-shrink-0">
                                    <!-- Icon will be inserted here -->
                                </div>
                                <h3 id="alertTitle" class="text-lg font-semibold text-gray-900">
                                    <!-- Title will be inserted here -->
                                </h3>
                            </div>
                            
                            <div id="alertContent" class="text-gray-600 mb-6">
                                <!-- Content will be inserted here -->
                            </div>

                            <div id="alertInputContainer" class="mb-6 hidden">
                                <textarea 
                                    id="alertInput"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                                    rows="3"
                                    placeholder="กรุณากรอกเหตุผลในการรายงาน..."
                                ></textarea>
                                <div class="flex justify-end mt-1 text-xs text-gray-500">
                                    <span id="alertCharCount">0/300</span>
                                </div>
                            </div>
                            
                            <div class="flex justify-end space-x-3">
                                <button id="alertCancelBtn" 
                                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                    ยกเลิก
                                </button>
                                <button id="alertConfirmBtn" 
                                        class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                                    ยืนยัน
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            // Attach live counter for comment input (500 chars)
            const commentInputInit2 = document.getElementById('newCommentInput');
            const commentCounterInit2 = document.getElementById('commentCharCount');
            if (commentInputInit2 && commentCounterInit2) {
                const update2 = () => { commentCounterInit2.textContent = `${commentInputInit2.value.length}/500`; };
                commentInputInit2.addEventListener('input', update2);
                update2();
            }

            // จัดการกับการปิด modal เมื่อคลิกพื้นหลัง
            const modal = document.getElementById('customAlertModal');
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    const cancelBtn = document.getElementById('alertCancelBtn');
                    if (cancelBtn) {
                        cancelBtn.click();
                    }
                }
            });
        }
    }

    // เพิ่มเมธอดสำหรับสร้าง Modal Alert ในคลาส WasteManagement
    ensureAlertModalExists() {
        let alertModal = document.getElementById('customAlertModal');
        if (!alertModal) {
            const modalHtml = `
            <div id="customAlertModal" 
                class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center"
                style="z-index: 99999; display: none;">
                <div class="relative bg-white rounded-lg max-w-md w-full mx-4 overflow-hidden shadow-xl transform transition-all">
                    <div class="p-6">
                        <div class="flex items-center mb-4">
                            <div id="alertIcon" class="mr-4 flex-shrink-0">
                                <!-- Icon will be inserted here -->
                            </div>
                            <h3 id="alertTitle" class="text-lg font-semibold text-gray-900">
                                <!-- Title will be inserted here -->
                            </h3>
                        </div>
                        
                        <div id="alertContent" class="text-gray-600 mb-6">
                            <!-- Content will be inserted here -->
                        </div>

                        <div id="alertInputContainer" class="mb-6 hidden">
                            <textarea 
                                id="alertInput"
                                class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                                rows="3"
                                placeholder="กรุณากรอกเหตุผลในการรายงาน..."
                            ></textarea>
                            <div class="flex justify-end mt-1 text-xs text-gray-500">
                                <span id="alertCharCount">0/300</span>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button id="alertCancelBtn" 
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                ยกเลิก
                            </button>
                            <button id="alertConfirmBtn" 
                                    class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                                ยืนยัน
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);

            // จัดการกับการปิด modal เมื่อคลิกพื้นหลัง
            const modal = document.getElementById('customAlertModal');
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    const cancelBtn = document.getElementById('alertCancelBtn');
                    if (cancelBtn) {
                        cancelBtn.click();
                    }
                }
            });
        }
    }

    showCustomAlert({
        title = '',
        message = '',
        icon = '',
        showInput = false,
        confirmText = 'ตกลง',
        cancelText = 'ยกเลิก',
        type = 'info', // 'info', 'success', 'warning', 'error'
        maxLength = null
    }) {
        return new Promise((resolve) => {
            this.ensureAlertModalExists();
            const modal = document.getElementById('customAlertModal');
            const titleElement = document.getElementById('alertTitle');
            const contentElement = document.getElementById('alertContent');
            const iconElement = document.getElementById('alertIcon');
            const inputContainer = document.getElementById('alertInputContainer');
            const input = document.getElementById('alertInput');
            const charCount = document.getElementById('alertCharCount');
            const confirmBtn = document.getElementById('alertConfirmBtn');
            const cancelBtn = document.getElementById('alertCancelBtn');

            // Set icon based on type
            const iconMap = {
                info: '<i class="fas fa-info-circle text-blue-500 text-2xl"></i>',
                success: '<i class="fas fa-check-circle text-green-500 text-2xl"></i>',
                warning: '<i class="fas fa-exclamation-triangle text-yellow-500 text-2xl"></i>',
                error: '<i class="fas fa-times-circle text-red-500 text-2xl"></i>'
            };

            // Update modal content
            titleElement.textContent = title;
            contentElement.textContent = message;
            iconElement.innerHTML = iconMap[type] || icon;
            confirmBtn.textContent = confirmText;
            cancelBtn.textContent = cancelText;

            // เพิ่มคลาสตามประเภทของ alert
            confirmBtn.className = `px-4 py-2 rounded-lg text-white transition-colors ${type === 'error' ? 'bg-red-500 hover:bg-red-600' :
                type === 'warning' ? 'bg-yellow-500 hover:bg-yellow-600' :
                    'bg-green-500 hover:bg-green-600'
                }`;

            // Handle input visibility
            if (showInput) {
                inputContainer.classList.remove('hidden');
                input.value = '';
                // Apply maxlength if provided and show counter
                if (maxLength && Number.isFinite(Number(maxLength))) {
                    try { input.setAttribute('maxlength', String(maxLength)); } catch (e) {}
                    if (charCount) charCount.textContent = `0/${maxLength}`;
                    input.oninput = () => {
                        input.classList.remove('ring-2', 'ring-red-500');
                        if (charCount) charCount.textContent = `${input.value.length}/${maxLength}`;
                    };
                } else {
                    try { input.removeAttribute('maxlength'); } catch (e) {}
                    if (charCount) charCount.textContent = '';
                    input.oninput = () => {
                        input.classList.remove('ring-2', 'ring-red-500');
                    };
                }
                input.focus();
            } else {
                inputContainer.classList.add('hidden');
                if (charCount) charCount.textContent = '';
            }

            // Show modal
            modal.style.display = 'flex';
            modal.classList.remove('hidden');

            // เพิ่ม styles เพื่อให้แน่ใจว่า modal จะอยู่ด้านบนสุด
            modal.style.zIndex = '99999';

            // Handle buttons
            const closeModal = (result) => {
                modal.style.display = 'none';
                modal.classList.add('hidden');
                resolve(result);
            };

            confirmBtn.onclick = () => {
                if (showInput) {
                    const inputValue = input.value.trim();
                    if (!inputValue) {
                        input.classList.add('ring-2', 'ring-red-500');
                        input.classList.add('shake-animation');
                        setTimeout(() => {
                            input.classList.remove('shake-animation');
                        }, 500);
                        return;
                    }
                    closeModal({ confirmed: true, value: inputValue });
                } else {
                    closeModal({ confirmed: true });
                }
            };

            cancelBtn.onclick = () => closeModal({ confirmed: false });

            // Handle input validation
            if (showInput) {
                // Handle Enter key
                input.onkeypress = (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        confirmBtn.click();
                    }
                };
            }
        });
    }
    async openProfileModal(userId) {
    console.log('Opening profile modal with userId:', userId, 'Type:', typeof userId);

    try {
        // แปลง userId เป็นสตริงและตรวจสอบอย่างเข้มงวด
        userId = String(userId).trim();
        console.log('Processed userId:', userId);

        if (!userId || userId === 'undefined' || userId === 'null') {
            console.error('Invalid userId:', userId);
            await this.showCustomAlert({
                title: 'เกิดข้อผิดพลาด',
                message: 'ไม่สามารถโหลดโปรไฟล์ได้ กรุณาลองใหม่',
                type: 'error',
                confirmText: 'ตกลง',
                showInput: false
            });
            return;
        }

        // ปิด modal อื่น ๆ
        this.closeCommentsModal();
        this.closePostDetailModal();

        // สร้าง modal ถ้ายังไม่มี
        this.ensureProfileModalExists();

        const modal = document.getElementById('profileModal');
        modal.classList.add('active');
        document.body.classList.add('modal-open');

        // แสดง loading state
        const userPostsGrid = document.getElementById('userPostsGrid');
        if (userPostsGrid) {
            userPostsGrid.innerHTML = `
                <div class="text-center py-8 col-span-full">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-green-500"></div>
                    <p class="mt-2 text-gray-500">กำลังโหลดข้อมูล...</p>
                </div>
            `;
        }

        // ดึงข้อมูลผู้ใช้และโพสต์
        const response = await fetch(`get_user_profile.php?userId=${encodeURIComponent(userId)}`);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        // อัพเดตข้อมูลส่วนหัว
        const profileImage = document.getElementById('profileImage');
        const profileName = document.getElementById('profileName');
        const profileEmail = document.getElementById('profileEmail');
        const postCount = document.getElementById('postCount');

        if (profileImage) profileImage.src = `img/profile_uploads/${data.user.profile_img || 'default.jpg'}`;
        if (profileName) profileName.textContent = data.user.name || 'ไม่ระบุชื่อ';
        if (profileEmail) profileEmail.textContent = data.user.email || 'ไม่ระบุอีเมล';
        if (postCount) postCount.textContent = data.user.post_count || '0';

        // แสดงโพสต์
        if (userPostsGrid) {
            if (data.posts.length === 0) {
                userPostsGrid.innerHTML = `
                    <div class="text-center py-8 col-span-full">
                        <i class="fas fa-camera-retro text-4xl text-gray-300 mb-2"></i>
                        <p class="text-gray-500">ยังไม่มีโพสต์</p>
                    </div>
                `;
            } else {
                userPostsGrid.innerHTML = data.posts.map(post => this.renderPostForModal(post)).join('');
                this.adjustVideoLayout();

// ให้ TikTok re-parse ในพื้นที่โมดัล (เผื่อกรณี TikTok)
try { ensureTikTokEmbedScriptAndParse(null); } catch(e) { console.warn(e); }

// หากมี Facebook embed ให้ FB SDK parse อีกครั้ง
if (window.FB && window.FB.XFBML && window.FB.XFBML.parse) {
  try { window.FB.XFBML.parse(document.getElementById('profileModal')); } catch(_) {}
}
            }
        }

    } catch (error) {
        console.error('Error opening profile modal:', error);
        const userPostsGrid = document.getElementById('userPostsGrid');
        if (userPostsGrid) {
            userPostsGrid.innerHTML = `
                <div class="text-center py-8 col-span-full">
                    <i class="fas fa-exclamation-circle text-4xl text-red-500 mb-2"></i>
                    <p class="text-red-500">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
                    <button onclick="window.wasteManager.closeProfileModal()" 
                            class="mt-4 px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                        ปิด
                    </button>
                </div>
            `;
        }
    }
}

    closeProfileModal() {
        const modal = document.getElementById('profileModal');
        if (modal) {
            modal.classList.remove('active');

            // ตรวจสอบว่า comment modal กำลังเปิดอยู่หรือไม่
            const commentsModal = document.getElementById('commentsModal');
            if (!commentsModal || !commentsModal.classList.contains('active')) {
                // ปิด modal เฉพาะเมื่อไม่มี comment modal เปิดอยู่
                document.body.classList.remove('modal-open');
            }
        }
    }
    closePostDetailModal() {
        const modal = document.getElementById('postDetailModal');
        if (modal) {
            modal.classList.add('hidden');  // ซ่อน modal ด้วยการเพิ่มคลาส hidden
            document.getElementById('postDetailModalImages').innerHTML = '';
            document.getElementById('postDetailRightSide').innerHTML = '';
            document.body.style.overflow = 'auto';  // คืนค่า overflow ของ body
        }
    }


    nextImage(postId) {
        const carousel = document.querySelector(`#carousel-${postId}`);
        if (!carousel) return;

        const items = carousel.querySelectorAll('.carousel-item');
        let activeIndex = -1;

        items.forEach((item, index) => {
            if (item.classList.contains('active')) {
                activeIndex = index;
            }
        });

        const nextIndex = (activeIndex + 1) % items.length;

        items[activeIndex].classList.remove('active');
        items[nextIndex].classList.add('active');

        // อัพเดตตัวนับ
        const counter = document.querySelector(`#counter-${postId}`);
        if (counter) {
            counter.textContent = `${nextIndex + 1}/${items.length}`;
        }
    }

    prevImage(postId) {
        const carousel = document.querySelector(`#carousel-${postId}`);
        if (!carousel) return;

        const items = carousel.querySelectorAll('.carousel-item');
        let activeIndex = -1;

        items.forEach((item, index) => {
            if (item.classList.contains('active')) {
                activeIndex = index;
            }
        });

        const prevIndex = (activeIndex - 1 + items.length) % items.length;

        items[activeIndex].classList.remove('active');
        items[prevIndex].classList.add('active');

        // อัพเดตตัวนับ
        const counter = document.querySelector(`#counter-${postId}`);
        if (counter) {
            counter.textContent = `${prevIndex + 1}/${items.length}`;
        }
    }

    goToImage(postId, index) {
        const carousel = document.querySelector(`#carousel-${postId}`);
        if (!carousel) return;

        const items = carousel.querySelectorAll('.carousel-item');
        let activeIndex = -1;

        items.forEach((item, i) => {
            if (item.classList.contains('active')) {
                activeIndex = i;
            }
        });

        items[activeIndex].classList.remove('active');
        items[index].classList.add('active');

        // อัพเดตตัวนับ
        const counter = document.querySelector(`#counter-${postId}`);
        if (counter) {
            counter.textContent = `${index + 1}/${items.length}`;
        }
    }
    async toggleLike(postId) {
        try {
            const formData = new FormData();
            formData.append('postId', postId);
    
            const response = await fetch('toggle_like.php', {
                method: 'POST',
                body: formData
            });
    
            const data = await response.json();
            console.log('Toggle like response FULL:', data);
    
            if (data.success) {
                const isLiked = [true, 1, "1", "true"].includes(data.is_liked);
    
                console.log('Processed isLiked:', isLiked);
    
                // อัปเดต UI ทันที
                this.updateLikeUI(postId, isLiked, data.likes);
    
                return data;
            }
            return null;
        } catch (error) {
            console.error('Error in toggleLike:', error);
            return null;
        }
    }
    // Search and reset functions
    async searchPosts(query) {
        this.currentFilter.searchQuery = query;
        this.currentPage = 1;
        await this.renderPosts();
    }
    ensureCommentsModalExists() {
        let commentsModal = document.getElementById('commentsModal');

        if (!commentsModal) {
            const modalHtml = `
    <div id="commentsModal" class="modal-backdrop" style="z-index:3000;">
        <div class="modal-container">
            <div class="modal-content bg-white rounded-lg overflow-hidden shadow-xl">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold">ความคิดเห็น</h2>
                        <button onclick="window.wasteManager.closeCommentsModal()" 
                                class="text-gray-600 hover:text-gray-900">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <div class="mb-4 flex justify-end">
                        <select id="commentsSortSelect" 
                                class="border rounded px-2 py-1"
                                onchange="window.wasteManager.openCommentsModal(window.wasteManager.currentOpenPostId)">
                            <option value="newest">ล่าสุด</option>
                            <option value="oldest">เก่าสุด</option>
                        </select>
                    </div>

                    <div id="commentsContainer" class="max-h-96 overflow-y-auto mb-4">
                        <!-- Comments will be loaded here -->
                    </div>

                    <div class="comment-form flex items-center space-x-2">
                        <input 
                            type="text" 
                            id="newCommentInput" 
                            placeholder="เขียนความคิดเห็น..." 
                            class="flex-grow border rounded-lg px-3 py-2"
                            maxlength="500"
                        >
                        <span id="commentCharCount" class="text-xs text-gray-500">0/500</span>
                        <button 
                            onclick="window.wasteManager.submitComment()"
                            class="bg-green-500 text-white rounded-lg px-4 py-2 hover:bg-green-600"
                        >
                            ส่ง
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
`;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const _ci = document.getElementById('newCommentInput');
            const _cc = document.getElementById('commentCharCount');
            if (_ci && _cc) {
                const _upd = () => { _cc.textContent = `${_ci.value.length}/500`; };
                _ci.addEventListener('input', _upd);
                _upd();
            }
        }
    }

    ensurePostDetailModalExists() { 
        let postDetailModal = document.getElementById('postDetailModal'); 
        if (!postDetailModal) { 
            const modalHtml = `
            <div id="postDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-[9999] hidden flex justify-center items-center overflow-hidden">
                <div class="bg-white rounded-lg w-full h-full md:w-4/5 md:h-[90vh] md:max-w-6xl flex flex-col overflow-hidden relative">
                    <!-- Close Modal Button -->
                    <button id="closePostDetailModal" class="absolute top-4 right-4 text-4xl text-gray-400 hover:text-gray-700 z-[10000] p-2 bg-white bg-opacity-70 rounded-full w-14 h-14 flex items-center justify-center shadow-lg">
                        &times;
                    </button>
    
                    <!-- Content Container -->
                    <div class="flex flex-col md:flex-row flex-grow overflow-hidden">
                        <!-- Image Section -->
                        <div class="w-full md:w-2/3 bg-gray-100 flex items-center justify-center relative overflow-hidden">
                            <div id="postDetailModalImages" class="w-full h-full overflow-y-auto flex flex-col items-center justify-start">
                                <!-- Images will be added here -->
                            </div>
                        </div>
    
                        <!-- Post Details Section -->
                        <div id="postDetailRightSide" class="w-full md:w-1/3 flex flex-col bg-white border-t md:border-t-0 md:border-l">
                            <!-- User Info at the top -->
                            <div id="postDetailUserInfo" class="p-4 flex items-center space-x-3 border-b">
                                <img src="" id="postDetailUserProfilePic" alt="Profile" class="w-10 h-10 rounded-full object-cover">
                                <div class="flex flex-col flex-grow">
                                    <span id="postDetailUserName" class="text-sm font-semibold"></span>
                                    <span id="postDetailTimestamp" class="text-xs text-gray-500"></span>
                                </div>
                            </div>
    
                            <!-- Post Text -->
                            <div id="postDetailText" class="p-4 text-gray-700 text-sm flex-grow overflow-y-auto">
                                <!-- Post text will be displayed here -->
                            </div>
    
                            <!-- Interaction Buttons -->
                            <div class="sticky bottom-0 left-0 w-full bg-white border-t p-4 flex justify-between">
                                <button class="flex items-center space-x-2 text-gray-700 hover:text-blue-500">
                                    <i class="fas fa-thumbs-up"></i>
                                    <span>ถูกใจ</span>
                                </button>
                                <button class="flex items-center space-x-2 text-gray-700 hover:text-blue-500">
                                    <i class="fas fa-comment"></i>
                                    <span>คอมเมนต์</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            postDetailModal = document.getElementById('postDetailModal');
    
            // Add Event Listener for closing modal
            postDetailModal.addEventListener('click', (event) => {
                if (event.target.id === 'closePostDetailModal' || event.target === postDetailModal) {
                    this.closePostDetailModal();
                    event.stopPropagation();
                    event.preventDefault();
                }
            });
    
            // Add touch and wheel event listeners for better mobile scrolling
            const imagesContainer = document.getElementById('postDetailModalImages');
            const rightSide = document.getElementById('postDetailRightSide');
            
            // Prevent body scroll when scrolling images or right side
            const preventBodyScroll = (event) => {
                if (event.currentTarget.scrollTop > 0 || event.currentTarget.scrollTop < event.currentTarget.scrollHeight - event.currentTarget.clientHeight) {
                    event.preventDefault();
                }
            };
    
            imagesContainer.addEventListener('touchmove', preventBodyScroll, { passive: false });
            imagesContainer.addEventListener('wheel', preventBodyScroll, { passive: false });
            rightSide.addEventListener('touchmove', preventBodyScroll, { passive: false });
            rightSide.addEventListener('wheel', preventBodyScroll, { passive: false });
        }
    }
    
    // Method to populate modal with images and content
    populatePostDetailModal(post) {
        const imagesContainer = document.getElementById('postDetailModalImages');
        const textContainer = document.getElementById('postDetailText');
        const userPicElement = document.getElementById('postDetailUserProfilePic');
        const userNameElement = document.getElementById('postDetailUserName');
        const timestampElement = document.getElementById('postDetailTimestamp');
        
        // Clear previous content
        imagesContainer.innerHTML = '';
        textContainer.innerHTML = '';
    
        // Add images
        post.images.forEach((imageUrl, index) => {
            const imgElement = document.createElement('img');
            imgElement.src = imageUrl;
            imgElement.alt = `Post image ${index + 1}`;
            imgElement.classList.add('w-full', 'object-contain', 'mb-4');
            
            // Ensure images are fully viewable
            imgElement.style.maxHeight = '100%';
            imgElement.style.width = 'auto';
            
            imagesContainer.appendChild(imgElement);
        });
    
        // Add post details
        textContainer.textContent = post.text;
        userPicElement.src = post.userProfilePicture;
        userNameElement.textContent = post.userName;
        timestampElement.textContent = post.timestamp;
    
        // Show the modal
        const postDetailModal = document.getElementById('postDetailModal');
        postDetailModal.classList.remove('hidden');
        
        // Scroll to top of images
        imagesContainer.scrollTop = 0;
    
        // Disable body scroll when modal is open
        document.body.style.overflow = 'hidden';
    }
    
   
    
    // แก้ไขฟังก์ชัน openPostDetailModal ให้ใช้การลบคลาส "hidden" และตั้งค่า overflow
    async openPostDetailModal(post) {
        try {
            // Check if the post belongs to the current user
            const isCurrentUserPost = post.userID === this.currentUserId;
            console.log('Current user post check:', post.userID, this.currentUserId, isCurrentUserPost);
    
            // ดึงสถานะไลค์ล่าสุดก่อนเปิดโมดัล
            const likeStatus = await this.getPostLikeStatus(post.post_id);
            
            // ใช้ค่าล่าสุดที่ได้จากเซิร์ฟเวอร์แทนค่าเดิมในตัวแปร post
            if (likeStatus && likeStatus.success) {
                post.is_liked = likeStatus.is_liked;
                post.likes = likeStatus.likes;
                console.log('Updated post like status from server:', post.is_liked, post.likes);
            }
    
            // Debug logs
            console.log('Opening post detail, post data:', post);
            const isLiked = post.is_liked === true || post.is_liked === 1 || post.is_liked === "1" || post.is_liked === "true";
            console.log('Updated is_liked value:', post.is_liked, 'Computed isLiked:', isLiked);
    
            this.ensurePostDetailModalExists();
    
            const modal = document.getElementById('postDetailModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
    
            const imagesContainer = document.getElementById('postDetailModalImages');
            imagesContainer.innerHTML = '';

            // Center image vertically when there is only one image
            try {
                imagesContainer.classList.remove('justify-start', 'justify-center');
                if (Array.isArray(post.images) && post.images.length === 1) {
                    imagesContainer.classList.add('justify-center');
                } else {
                    imagesContainer.classList.add('justify-start');
                }
            } catch (e) {
                console.warn('Could not toggle image container alignment:', e);
            }
    
            // Insert video (if any) above images
            try {
                const videoHtml = this.renderVideoHtml(post, true);
                if (videoHtml) {
                    imagesContainer.insertAdjacentHTML('beforeend', videoHtml);
                }
            } catch (e) { console.warn('render video in modal failed', e); }

            const imageWrapper = document.createElement('div');
            imageWrapper.className = 'flex flex-col items-center space-y-2 p-2 overflow-y-auto max-h-full';
    
            post.images.forEach((img, index) => {
                const imgElement = document.createElement('div');
                imgElement.className = 'w-full max-w-md mb-2 relative';
                imgElement.innerHTML = `
                    <div class="absolute top-2 left-2 bg-black bg-opacity-50 text-white rounded-full w-8 h-8 flex items-center justify-center">
                        ${index + 1}
                    </div>
                    <img 
                        src="img/post_img/${img}" 
                        class="w-full object-contain rounded-lg shadow-md"
                    >
                `;
                imageWrapper.appendChild(imgElement);
            });
    
            imagesContainer.appendChild(imageWrapper);

            const detailsContainer = document.getElementById('postDetailRightSide');
    
            // ปรับปรุงโครงสร้าง HTML ให้ข้อมูลผู้ใช้อยู่ด้านบนสุด
            detailsContainer.innerHTML = `
                <div class="flex flex-col h-full">
                    <!-- ส่วนข้อมูลผู้ใช้ (อยู่ด้านบนสุด) -->
                    <div class="flex items-center p-4 border-b">
                        <img 
                            src="img/profile_uploads/${post.avatar_path || 'default.jpg'}" 
                            class="w-10 h-10 rounded-full mr-3 cursor-pointer hover:opacity-80 transition-opacity" 
                            alt="User Avatar"
                            onerror="this.src='img/profile_uploads/default.jpg'"
                            onclick="window.wasteManager.openProfileModal('${post.userID}')"
                        >
                        <div class="flex-grow">
                            <h3 class="font-bold text-sm cursor-pointer hover:text-green-600 transition-colors"
                                onclick="window.wasteManager.openProfileModal('${post.userID}')"
                            >${post.username}</h3>
                            <p class="text-xs text-gray-500">
                                ${new Date(post.date).toLocaleString('th-TH', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                })}
                            </p>
                        </div>
                        
                        ${isCurrentUserPost ? `
                            <div class="relative">
                                <button 
                                    onclick="event.stopPropagation(); window.wasteManager.togglePostDetailMenu(${post.post_id})"
                                    class="text-gray-600 hover:text-gray-800 focus:outline-none"
                                >
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div 
                                    id="post-detail-menu-${post.post_id}" 
                                    class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border z-50"
                                    style="min-width: 160px;"
                                >
                                    <ul class="py-1">
                                        <li>
                                           <button 
  onclick="event.preventDefault(); event.stopPropagation(); window.editPost(${post.post_id}); window.wasteManager.closePostDetailModal();"
  class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-black"
>
  <i class="fas fa-edit mr-2"></i>แก้ไข
</button>
                                        </li>
                                        <li>
                                            <button 
                                                onclick="window.wasteManager.deletePostAndCloseModal(${post.post_id})"
                                                class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600"
                                            >
                                                <i class="fas fa-trash mr-2"></i>ลบ
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                    
                    <!-- ส่วนเนื้อหาโพสต์ (ตรงกลาง) -->
                    <div class="flex-grow overflow-y-auto p-4">
                        <h2 class="text-xl font-bold mb-2">${post.title}</h2>
                        
                        <div class="waste-info mb-3">
                            <p class="font-bold text-sm mb-1">
                                <span class="text-gray-600">ประเภทขยะ:</span> 
                                <span class="text-green-600">${post.Waste_type || 'ไม่ระบุ'}</span>
                                <span class="mx-4 text-gray-400">|</span>
                                <span class="text-gray-600">วิธีจัดการ:</span> 
                                <span class="text-green-600">${post.Management_type || 'ไม่ระบุ'}</span>
                            </p>
                        </div>
                        
                        <p class="text-gray-700">${post.details}</p>
                    </div>
                    
                    <!-- ส่วนปุ่มไลค์และคอมเมนต์ (ด้านล่าง) -->
                    <div class="border-t p-4">
                        <div class="flex justify-between">
                            <button 
                                id="modalLikeButton-${post.post_id}"
                                class="like-button ${isLiked ? 'active' : ''} flex items-center space-x-1 text-gray-500 hover:text-red-500 transition-colors"
                                onclick="window.wasteManager.togglePostDetailLike(${post.post_id})"
                            >
                                <i class="fas fa-heart ${isLiked ? 'text-red-500' : ''}"></i>
                                <span class="likes-count">${post.likes || 0}</span>
                            </button>
                            
                            <button 
                                class="comments-button text-gray-500 hover:text-green-600 flex items-center space-x-2 transition-colors"
                                onclick="window.wasteManager.openCommentsModal(${post.post_id})"
                            >
                                <i class="fas fa-comment"></i>
                                <span class="text-sm comment-text md:inline hidden">${post.comment_count || 0} คอมเมนต์</span>
                                <span class="text-sm comment-count md:hidden inline">${post.comment_count || 0}</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        } catch (error) {
            console.error('Error opening post detail modal:', error);
        }
    }
     // เมธอดสำหรับอัพเดท UI ทั้งหมด
     updateLikeUI(postId, isLiked, likesCount) {
        console.log(`Updating UI for post ${postId} - Liked: ${isLiked}, Count: ${likesCount}`);
        
        // ตรวจสอบว่า likesCount เป็น null, undefined หรือไม่
        const safeCount = likesCount !== null && likesCount !== undefined 
            ? likesCount 
            : 0;
        
        // อัปเดตการ์ดหลัก
        document.querySelectorAll(`.share-card[data-post-id="${postId}"] .like-button, .post-card[data-post-id="${postId}"] .like-button`).forEach(button => {
            button.classList.toggle('active', isLiked);
            const icon = button.querySelector('i.fa-heart');
            if (icon) {
                icon.classList.toggle('text-red-500', isLiked);
            }
            const counter = button.querySelector('.likes-count');
            if (counter) {
                counter.textContent = safeCount;
            }
        });
        
        // อัปเดตโมดัลรายละเอียด
        const modalLikeButton = document.getElementById(`modalLikeButton-${postId}`);
        if (modalLikeButton) {
            modalLikeButton.classList.toggle('active', isLiked);
            const icon = modalLikeButton.querySelector('i.fa-heart');
            if (icon) {
                icon.classList.toggle('text-red-500', isLiked);
            }
            const counter = modalLikeButton.querySelector('.likes-count');
            if (counter) {
                counter.textContent = safeCount;
            }
        }
        
        // อัปเดตโมดัลโปรไฟล์
        document.querySelectorAll(`.modal-post[data-post-id="${postId}"] .like-button`).forEach(button => {
            button.classList.toggle('active', isLiked);
            const icon = button.querySelector('i.fa-heart');
            if (icon) {
                icon.classList.toggle('text-red-500', isLiked);
            }
            const counter = button.querySelector('.likes-count');
            if (counter) {
                counter.textContent = safeCount;
            }
        });
    }
    togglePostDetailMenu(postId) {
        const menu = document.getElementById(`post-detail-menu-${postId}`);

        // Close all other menus first
        document.querySelectorAll('[id^="post-detail-menu-"]').forEach(otherMenu => {
            if (otherMenu.id !== `post-detail-menu-${postId}`) {
                otherMenu.classList.add('hidden');
            }
        });

        // Toggle current menu
        menu.classList.toggle('hidden');

        // Add click outside listener to close menu
        if (!menu.classList.contains('hidden')) {
            const closeMenuHandler = (event) => {
                if (!menu.contains(event.target)) {
                    menu.classList.add('hidden');
                    document.removeEventListener('click', closeMenuHandler);
                }
            };

            // Add a small timeout to avoid immediate event propagation
            setTimeout(() => {
                document.addEventListener('click', closeMenuHandler);
            }, 0);
        }
    }
    async getPostLikeStatus(postId) {
        try {
            const response = await fetch(`get_post_like_status.php?postId=${postId}`);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            console.log('Got post like status:', data);
            return data;
        } catch (error) {
            console.error('Error fetching post like status:', error);
            return { success: false, is_liked: false, likes: 0 };
        }
    }
    

    // Add method to delete post and close modal
    deletePostAndCloseModal(postId) {
        // First close the post detail modal
        this.closePostDetailModal();

        // Then call the regular delete post method
        this.deletePost(postId);
    }
    async togglePostDetailLike(postId) {
        try {
            console.log('togglePostDetailLike started for postId:', postId);
            
            const formData = new FormData();
            formData.append('postId', postId);
    
            const response = await fetch('toggle_like.php', {
                method: 'POST',
                body: formData
            });
    
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
    
            const data = await response.json();
            console.log('Toggle detail like response FULL:', data);
    
            if (data.success) {
                // แปลงค่า is_liked เป็น boolean อย่างชัดเจน
                const isLiked = data.is_liked === true || 
                              data.is_liked === 1 || 
                              data.is_liked === "1" || 
                              data.is_liked === "true";
                
                console.log('Processed isLiked in detail modal:', isLiked);
                
                // อัปเดต UI ทันที - เริ่มจากปุ่มในโมดัลรายละเอียด
                const modalLikeButton = document.getElementById(`modalLikeButton-${postId}`);
                if (modalLikeButton) {
                    modalLikeButton.classList.toggle('active', isLiked);
                    
                    const icon = modalLikeButton.querySelector('i.fa-heart');
                    if (icon) {
                        if (isLiked) {
                            icon.classList.add('text-red-500');
                            icon.classList.remove('text-gray-500');
                        } else {
                            icon.classList.remove('text-red-500');
                            icon.classList.add('text-gray-500');
                        }
                    }
                    
                    const counter = modalLikeButton.querySelector('.likes-count');
                    if (counter) {
                        counter.textContent = data.likes;
                    }
                }
                
                // อัปเดตการ์ดหลัก
                document.querySelectorAll(`.share-card[data-post-id="${postId}"] .like-button`).forEach(button => {
                    button.classList.toggle('active', isLiked);
                    
                    const icon = button.querySelector('i.fa-heart');
                    if (icon) {
                        if (isLiked) {
                            icon.classList.add('text-red-500');
                            icon.classList.remove('text-gray-500');
                        } else {
                            icon.classList.remove('text-red-500');
                            icon.classList.add('text-gray-500');
                        }
                    }
                    
                    const counter = button.querySelector('.likes-count');
                    if (counter) {
                        counter.textContent = data.likes;
                    }
                });
                
                // Update in my_post cards (uses .post-card)
                document.querySelectorAll(`.post-card[data-post-id="${postId}"] .like-button`).forEach(button => {
                    button.classList.toggle('active', isLiked);
                    const icon = button.querySelector('i.fa-heart');
                    if (icon) {
                        if (isLiked) {
                            icon.classList.add('text-red-500');
                            icon.classList.remove('text-gray-500');
                        } else {
                            icon.classList.remove('text-red-500');
                            icon.classList.add('text-gray-500');
                        }
                    }
                    const counter = button.querySelector('.likes-count');
                    if (counter) {
                        counter.textContent = data.likes;
                    }
                });
                
                // อัปเดตโมดัลโปรไฟล์
                document.querySelectorAll(`.modal-post[data-post-id="${postId}"] .like-button`).forEach(button => {
                    button.classList.toggle('active', isLiked);
                    
                    const icon = button.querySelector('i.fa-heart');
                    if (icon) {
                        if (isLiked) {
                            icon.classList.add('text-red-500');
                            icon.classList.remove('text-gray-500');
                        } else {
                            icon.classList.remove('text-red-500');
                            icon.classList.add('text-gray-500');
                        }
                    }
                    
                    const counter = button.querySelector('.likes-count');
                    if (counter) {
                        counter.textContent = data.likes;
                    }
                });
                
                // Sync global cache used by my_post.php if available
                try {
                    if (Array.isArray(window.allPosts)) {
                        const idx = window.allPosts.findIndex(p => String(p.post_id) === String(postId));
                        if (idx !== -1) {
                            window.allPosts[idx].is_liked = (data.is_liked === true || data.is_liked === 1 || data.is_liked === "1" || data.is_liked === "true");
                            window.allPosts[idx].likes = data.likes;
                        }
                    }
                } catch (_) {}
                
                return data;
            }
            
            throw new Error('Toggle like response indicates failure');
        } catch (error) {
            console.error('Error in togglePostDetailLike:', error);
            return null;
        }
    }
    

   
    renderComment(comment, postId) {
        console.log('Full Comment Data:', comment);

        const isCurrentUserComment = comment.is_current_user_comment;

        // เพิ่มการตรวจสอบ userID อย่างระมัดระวัง
        const safeUserId = comment.userID ? String(comment.userID).trim() : '';
        console.log('Processed Safe UserID:', safeUserId);

        return `
            <div class="bg-gray-100 rounded-lg p-4 mb-3 relative">
                <div class="flex items-center mb-2">
                    <img 
                        src="img/profile_uploads/${comment.avatar_path || 'default.jpg'}" 
                        alt="${comment.username}" 
                        class="w-10 h-10 rounded-full mr-3 cursor-pointer hover:opacity-80 transition-opacity"
                        onerror="this.src='img/profile_uploads/default.jpg'"
                        ${safeUserId ? `onclick="window.wasteManager.openProfileModal('${safeUserId}')"` : ''}
                    >
                    <div class="flex-grow">
                        <div class="font-bold text-sm cursor-pointer hover:text-green-600 transition-colors"
                             ${safeUserId ? `onclick="window.wasteManager.openProfileModal('${safeUserId}')"` : ''}>
                            ${comment.username}
                        </div>
                        <div class="text-xs text-gray-500">
                            ${new Date(comment.date_created).toLocaleString('th-TH', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        })}
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-3">
                        ${!isCurrentUserComment ? `
                            <button 
                                onclick="window.wasteManager.reportComment(${comment.commentID}, ${postId})"
                                class="text-red-600 hover:text-red-700 flex items-center gap-1 text-sm"
                            >
                                <i class="fas fa-flag"></i>
                                <span>รายงาน</span>
                            </button>
                        ` : ''}
                        
                        ${isCurrentUserComment ? `
                            <div class="relative">
                                <button 
                                    onclick="window.wasteManager.toggleCommentMenu(${comment.commentID})"
                                    class="text-gray-600 hover:text-gray-800 focus:outline-none"
                                >
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div 
                                    id="comment-menu-${comment.commentID}" 
                                    class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border"
                                >
                                    <ul class="py-1">
                                        <li>
                                            <button 
                                                onclick="window.wasteManager.editComment(${comment.commentID})"
                                                class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm"
                                            >
                                                <i class="fas fa-edit mr-2"></i>แก้ไข
                                            </button>
                                        </li>
                                        <li>
                                            <button 
                                                onclick="window.wasteManager.deleteComment(${comment.commentID})"
                                               class="w-full text-left px-4 py-2 hover:bg-gray-100 text-sm text-red-600"
                                            >
                                               <i class="fas fa-trash mr-2"></i>ลบ
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <p class="text-sm" id="comment-content-${comment.commentID}">
                    ${comment.content}
                </p>
            </div>
        `;
    }
    toggleCommentMenu(commentId) {
        const menu = document.getElementById(`comment-menu-${commentId}`);

        // ปิดเมนูอื่นทั้งหมด
        document.querySelectorAll('[id^="comment-menu-"]').forEach(otherMenu => {
            if (otherMenu.id !== `comment-menu-${commentId}`) {
                otherMenu.classList.add('hidden');
            }
        });

        // สลับการแสดงผลเมนูปัจจุบัน
        menu.classList.toggle('hidden');

        // เพิ่มการปิดเมนูเมื่อคลิกนอกเมนู
        if (!menu.classList.contains('hidden')) {
            const closeMenuHandler = (event) => {
                if (!menu.contains(event.target)) {
                    menu.classList.add('hidden');
                    document.removeEventListener('click', closeMenuHandler);
                }
            };

            // เพิ่ม timeout เล็กน้อยเพื่อหลีกเลี่ยง event propagation
            setTimeout(() => {
                document.addEventListener('click', closeMenuHandler);
            }, 0);
        }
    }
    // เพิ่มเมธอดสำหรับแก้ไขและลบคอมเมนต์
    // ปรับแก้เมธอด submitComment ให้ใช้ showCustomAlert แทน alert แบบเดิม


// ปรับแก้เมธอด editComment ให้ใช้ showCustomAlert แทน alert แบบเดิม
async editComment(commentId) {
    try {
        // Toggle the comment menu closed
        const menu = document.getElementById(`comment-menu-${commentId}`);
        if (menu) menu.classList.add('hidden');

        // ดึงข้อความคอมเมนต์ปัจจุบัน
        const commentContentElem = document.getElementById(`comment-content-${commentId}`);
        if (!commentContentElem) {
            throw new Error('ไม่พบองค์ประกอบของความคิดเห็น');
        }

        const currentContent = commentContentElem.textContent.trim();

        // สร้าง container สำหรับการแก้ไข
        const editContainer = document.createElement('div');
        editContainer.className = 'flex items-center space-x-2';

        // สร้าง input สำหรับแก้ไข + จำกัด 500 ตัวอักษร และตัวนับ
        const editInput = document.createElement('input');
        editInput.type = 'text';
        editInput.value = currentContent;
        try { if (editInput.value.length > 500) { editInput.value = editInput.value.substring(0, 500); } } catch (e) {}
        try { editInput.setAttribute('maxlength', '500'); } catch (e) {}
        editInput.className = 'flex-grow border rounded px-2 py-1';

        // ตัวนับจำนวนอักษร
        const editCounter = document.createElement('span');
        editCounter.className = 'text-xs text-gray-500 ml-2';
        try { editCounter.textContent = `${editInput.value.length}/500`; } catch (e) { editCounter.textContent = '0/500'; }

        const _updateEditCounter = () => { try { editCounter.textContent = `${editInput.value.length}/500`; } catch (e) {} };
        try { editInput.addEventListener('input', _updateEditCounter); } catch (e) {}

        // สร้างปุ่มบันทึก
        const saveButton = document.createElement('button');
        saveButton.innerHTML = '<i class="fas fa-check text-green-500"></i>';
        saveButton.className = 'bg-transparent hover:bg-green-100 rounded-full p-2';

        // สร้างปุ่มยกเลิก
        const cancelButton = document.createElement('button');
        cancelButton.innerHTML = '<i class="fas fa-times text-red-500"></i>';
        cancelButton.className = 'bg-transparent hover:bg-red-100 rounded-full p-2';

        // เพิ่มองค์ประกอบลงใน container
        editContainer.appendChild(editInput);
        editContainer.appendChild(editCounter);
        editContainer.appendChild(saveButton);
        editContainer.appendChild(cancelButton);

        // เตรียมการบันทึก
        saveButton.onclick = async () => {
            const newContent = editInput.value.trim();
            if (!newContent) {
                await this.showCustomAlert({
                    title: 'แจ้งเตือน',
                    message: 'กรุณากรอกความคิดเห็น',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }

            if (newContent.length > 500) {
                await this.showCustomAlert({
                    title: 'แจ้งเตือน',
                    message: 'ความคิดเห็นต้องไม่เกิน 500 ตัวอักษร',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }

            const formData = new FormData();
            formData.append('commentId', commentId);
            formData.append('comment', newContent);

            try {
                const response = await fetch('edit_comment.php', {
                    method: 'POST',
                    body: formData
                });

                // ตรวจสอบการตอบกลับจากเซิร์ฟเวอร์
                const result = await response.json();

                if (result.success) {
                    // สร้าง paragraph เพื่อแทนที่ input
                    const newCommentContent = document.createElement('p');
                    newCommentContent.id = `comment-content-${commentId}`;
                    newCommentContent.className = 'text-sm';
                    newCommentContent.textContent = newContent;

                    // แทนที่ container การแก้ไขด้วย paragraph
                    editContainer.parentNode.replaceChild(newCommentContent, editContainer);

                    await this.showCustomAlert({
                        title: 'สำเร็จ',
                        message: result.message || 'แก้ไขความคิดเห็นเรียบร้อยแล้ว',
                        type: 'success',
                        confirmText: 'ตกลง',
                        showInput: false
                    });
                } else {
                    throw new Error(result.error || 'เกิดข้อผิดพลาดในการแก้ไขความคิดเห็น');
                }
            } catch (error) {
                console.error('Error editing comment:', error);
                await this.showCustomAlert({
                    title: 'เกิดข้อผิดพลาด',
                    message: error.message || 'เกิดข้อผิดพลาดในการแก้ไขความคิดเห็น',
                    type: 'error',
                    confirmText: 'ตกลง',
                    showInput: false
                });
            }
        };

        // ยกเลิกการแก้ไข
        cancelButton.onclick = () => {
            editContainer.parentNode.replaceChild(commentContentElem, editContainer);
        };

        // แทนที่เนื้อหาคอมเมนต์ด้วย container แก้ไข
        commentContentElem.parentNode.replaceChild(editContainer, commentContentElem);

        // โฟกัสที่ input
        editInput.focus();

    } catch (error) {
        console.error('Unexpected error in editComment:', error);
        await this.showCustomAlert({
            title: 'เกิดข้อผิดพลาด',
            message: 'เกิดข้อผิดพลาดในการเตรียมแก้ไขความคิดเห็น',
            type: 'error',
            confirmText: 'ตกลง',
            showInput: false
        });
    }
}

async deleteComment(commentId) {
    try {
      const confirmDelete = await this.showCustomAlert({
        title: 'ยืนยันการลบ',
        message: 'คุณแน่ใจหรือไม่ที่ต้องการลบความคิดเห็นนี้?',
        type: 'warning',
        confirmText: 'ลบ',
        cancelText: 'ยกเลิก',
        showInput: false
      });
  
      if (!confirmDelete.confirmed) {
        return;
      }
  
      const formData = new FormData();
      formData.append('commentId', commentId);
  
      const response = await fetch('delete_comment.php', {
        method: 'POST',
        body: formData
      });
  
      const result = await response.json();
      console.log('Delete comment response:', result);
  
      if (result.success) {
        // อัปเดตจำนวนคอมเมนต์ทุกที่ที่แสดงโพสต์นี้
        if (result.comment_count !== undefined) {
          const postId = this.currentOpenPostId || result.postId;
          // เรียกใช้เมธอด updateCommentCount ที่สร้างขึ้น
          this.updateCommentCount(postId, result.comment_count);
        } else if (result.postId) {
          // หากไม่มีข้อมูล comment_count ในการตอบกลับ ให้หาข้อมูลที่ถูกต้อง
          const getCommentsResponse = await fetch(`get_comments.php?postId=${result.postId}`);
          const comments = await getCommentsResponse.json();
          if (Array.isArray(comments)) {
            this.updateCommentCount(result.postId, comments.length);
          }
        }
        
        // โหลดคอมเมนต์ใหม่
        if (this.currentOpenPostId) {
          await this.openCommentsModal(this.currentOpenPostId);
        }
        
        await this.showCustomAlert({
          title: 'สำเร็จ',
          message: result.message || 'ลบความคิดเห็นเรียบร้อยแล้ว',
          type: 'success',
          confirmText: 'ตกลง',
          showInput: false
        });
      } else {
        await this.showCustomAlert({
          title: 'เกิดข้อผิดพลาด',
          message: result.error || 'เกิดข้อผิดพลาดในการลบความคิดเห็น',
          type: 'error',
          confirmText: 'ตกลง',
          showInput: false
        });
      }
    } catch (error) {
      console.error('Error deleting comment:', error);
      await this.showCustomAlert({
        title: 'เกิดข้อผิดพลาด',
        message: 'เกิดข้อผิดพลาดในการลบความคิดเห็น',
        type: 'error',
        confirmText: 'ตกลง',
        showInput: false
      });
    }
  }


    ensureCommentsModalExists() {
        let commentsModal = document.getElementById('commentsModal');

        if (!commentsModal) {
            const modalHtml = `
    <div id="commentsModal" class="modal-backdrop" style="z-index:3000;">
        <div class="modal-container">
            <div class="modal-content bg-white rounded-lg overflow-hidden shadow-xl">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold">ความคิดเห็น</h2>
                        <button onclick="window.wasteManager.closeCommentsModal()" 
                                class="text-gray-600 hover:text-gray-900">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <div class="mb-4 flex justify-end">
                        <select id="commentsSortSelect" 
                                class="border rounded px-2 py-1"
                                onchange="window.wasteManager.openCommentsModal(window.wasteManager.currentOpenPostId)">
                            <option value="newest">ล่าสุด</option>
                            <option value="oldest">เก่าสุด</option>
                        </select>
                    </div>

                    <div id="commentsContainer" class="max-h-96 overflow-y-auto mb-4">
                        <!-- Comments will be loaded here -->
                    </div>

                    <div class="comment-form flex items-center space-x-2">
                        <input 
                            type="text" 
                            id="newCommentInput" 
                            placeholder="เขียนความคิดเห็น..." 
                            class="flex-grow border rounded-lg px-3 py-2"
                        >
                        <button 
                            onclick="window.wasteManager.submitComment()"
                            class="bg-green-500 text-white rounded-lg px-4 py-2 hover:bg-green-600"
                        >
                            ส่ง
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
`;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
        }
    }
    async submitComment() {
        try {
            // ตรวจสอบว่ามี postId ที่จะแสดงความคิดเห็น
            if (!this.currentOpenPostId) {
                await this.showCustomAlert({
                    title: 'แจ้งเตือน',
                    message: 'ไม่พบโพสต์ที่ต้องการแสดงความคิดเห็น',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }
    
            // ตรวจสอบการระงับก่อนดำเนินการต่อ
            const restrictionResponse = await fetch('check_comment_restriction.php');
            const restrictionData = await restrictionResponse.json();
    
            if (!restrictionData.canComment) {
                await this.showCustomAlert({
                    title: 'ไม่สามารถแสดงความคิดเห็นได้',
                    message: restrictionData.message || 'คุณถูกระงับการแสดงความคิดเห็น',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }
    
            // ดึงข้อความความคิดเห็น
            const commentInput = document.getElementById('newCommentInput');
            const commentContent = commentInput.value.trim();
            
            // ตรวจสอบว่ามีข้อความที่จะส่งหรือไม่
            if (!commentContent) {
                await this.showCustomAlert({
                    title: 'แจ้งเตือน',
                    message: 'กรุณากรอกความคิดเห็น',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }
            
            // ตรวจสอบความยาวความคิดเห็น (สูงสุด 500 อักขระ)
            if (commentContent.length > 500) {
                await this.showCustomAlert({
                    title: 'เกินจำนวนอักขระ',
                    message: 'ความคิดเห็นต้องไม่เกิน 500 อักขระ',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }

            // เตรียมข้อมูลที่จะส่ง
            const formData = new FormData();
            formData.append('postId', this.currentOpenPostId);
            formData.append('comment', commentContent);
            
            // ส่งความคิดเห็น
            const response = await fetch('add_comment.php', {
                method: 'POST',
                body: formData
            });
          
            const result = await response.json();
            console.log('Add comment response:', result);
          
            if (result.success) {
                // ล้างช่องใส่ความคิดเห็น
                commentInput.value = '';
                
                // อัปเดตจำนวนคอมเมนต์ทุกที่ที่แสดงโพสต์นี้
                if (result.comment_count !== undefined) {
                    this.updateCommentCount(this.currentOpenPostId, result.comment_count);
                } else {
                    const getCommentsResponse = await fetch(`get_comments.php?postId=${this.currentOpenPostId}`);
                    const comments = await getCommentsResponse.json();
                    if (Array.isArray(comments)) {
                        this.updateCommentCount(this.currentOpenPostId, comments.length);
                    }
                }
                
                // โหลดความคิดเห็นใหม่
                await this.openCommentsModal(this.currentOpenPostId);
                
                // แสดงข้อความแจ้งเตือน
                await this.showCustomAlert({
                    title: 'สำเร็จ',
                    message: result.message || 'เพิ่มความคิดเห็นเรียบร้อยแล้ว',
                    type: 'success',
                    confirmText: 'ตกลง',
                    showInput: false
                });
            } else {
                await this.showCustomAlert({
                    title: 'เกิดข้อผิดพลาด',
                    message: result.error || 'เกิดข้อผิดพลาดในการส่งความคิดเห็น',
                    type: 'error',
                    confirmText: 'ตกลง',
                    showInput: false
                });
            }
        } catch (error) {
            console.error('Error submitting comment:', error);
            await this.showCustomAlert({
                title: 'เกิดข้อผิดพลาด',
                message: 'เกิดข้อผิดพลาดในการส่งความคิดเห็น',
                type: 'error',
                confirmText: 'ตกลง',
                showInput: false
            });
        }
    }
      
    closeCommentsModal() {
        const commentsModal = document.getElementById('commentsModal');
        if (commentsModal) {
            commentsModal.style.display = 'none';  // ซ่อน Modal
            commentsModal.classList.remove('active');  // ลบ class active

            // เคลียร์ข้อมูลภายใน
            const commentsContainer = document.getElementById('commentsContainer');
            const commentInput = document.getElementById('newCommentInput');

            if (commentsContainer) commentsContainer.innerHTML = '';
            if (commentInput) {
                commentInput.value = '';
                const _ccounter = document.getElementById('commentCharCount');
                if (_ccounter) _ccounter.textContent = '0/500';
            }
        }
        this.currentOpenPostId = null;  // รีเซ็ตโพสต์ที่เปิดอยู่
    } // Comment-related methods
    async openCommentsModal(postId) {
        try {
            // Validate postId
            if (!postId) {
                console.error('No post ID provided');
                await this.showCustomAlert({
                    title: 'แจ้งเตือน',
                    message: 'ไม่พบโพสต์',
                    type: 'warning',
                    confirmText: 'ตกลง',
                    showInput: false
                });
                return;
            }
    
            console.log('Opening comments for postId:', postId);
    
            // Set current open post ID
            this.currentOpenPostId = postId;
    
            // Ensure comments modal exists
            this.ensureCommentsModalExists();
    
            // Reset modal state
            const commentsModal = document.getElementById('commentsModal');
            const commentsContainer = document.getElementById('commentsContainer');
            const commentInput = document.getElementById('newCommentInput');
            const commentCounter = document.getElementById('commentCharCount');
    
            if (commentsContainer) commentsContainer.innerHTML = '';
            if (commentInput) {
                commentInput.value = '';
                try { commentInput.setAttribute('maxlength', '500'); } catch (e) {}
                if (commentCounter) commentCounter.textContent = '0/500';
                // Ensure listener is attached even if modal exists already
                const updateCounter = () => { if (commentCounter) commentCounter.textContent = `${commentInput.value.length}/500`; };
                try { commentInput.removeEventListener('input', updateCounter); } catch (e) {}
                commentInput.addEventListener('input', updateCounter);
            }
    
            // แสดง loading state
            if (commentsContainer) {
                commentsContainer.innerHTML = `
                    <div class="text-center py-8">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-green-500"></div>
                        <p class="mt-2 text-gray-500">กำลังโหลดความคิดเห็น...</p>
                    </div>
                `;
            }
    
            // Fetch comments
            const commentsSort = document.getElementById('commentsSortSelect');
            const sortOrder = commentsSort ? commentsSort.value : 'newest';
    
            console.log('Fetching comments with params:', { postId, sortOrder });
    
            const response = await fetch(`get_comments.php?postId=${postId}&sortOrder=${sortOrder}`);
    
            // Check for HTTP errors
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Server error:', errorText);
                throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
            }
    
            const comments = await response.json();
    
            console.log('Fetched comments:', comments);
    
            // Check for error in JSON response
            if (comments.error) {
                console.error('Comments fetch error:', comments.error);
                throw new Error(comments.error);
            }
    
            // Render comments
            if (!Array.isArray(comments) || comments.length === 0) {
                commentsContainer.innerHTML = `
                    <div class="text-center text-gray-500 py-4">
                        ยังไม่มีความคิดเห็น
                    </div>
                `;
            } else {
                // เพิ่ม postId เป็นอาร์กิวเมนต์ที่สอง
                commentsContainer.innerHTML = comments.map(comment => this.renderComment(comment, postId)).join('');
            }
    
            // Show modal
            if (commentsModal) {
                commentsModal.style.display = 'flex';  // Ensure modal is visible
                commentsModal.classList.add('active');
            }
        } catch (error) {
            console.error('Error fetching comments:', error);
            await this.showCustomAlert({
                title: 'เกิดข้อผิดพลาด',
                message: `ไม่สามารถโหลดความคิดเห็นได้: ${error.message}`,
                type: 'error',
                confirmText: 'ตกลง',
                showInput: false
            });
        }
    } ensureCommentsModalExists() {
        // Check if comments modal already exists
        let commentsModal = document.getElementById('commentsModal');

        if (!commentsModal) {
            const modalHtml = `
                <div id="commentsModal" class="modal-backdrop" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: flex; justify-content: center; align-items: flex-start; padding-top: 10vh; z-index: 999;">
                    <div class="modal-container" style="width: 90%; max-width: 700px; margin-top: 5rem;">
                        <div class="modal-content bg-white rounded-lg overflow-hidden shadow-xl" style="width: 100%; max-height: 80vh; overflow-y: auto;">
                            <div class="p-6">
                                <div class="flex justify-between items-center mb-4">
                                    <h2 class="text-xl font-bold">ความคิดเห็น</h2>
                                    <button onclick="window.wasteManager.closeCommentsModal()" 
                                            class="text-gray-600 hover:text-gray-900">
                                        <i class="fas fa-times text-xl"></i>
                                    </button>
                                </div>
                                
                                <div class="mb-4 flex justify-end">
                                    <select id="commentsSortSelect" 
                                            class="border rounded px-2 py-1"
                                            onchange="window.wasteManager.openCommentsModal(window.wasteManager.currentOpenPostId)">
                                        <option value="newest">ล่าสุด</option>
                                        <option value="oldest">เก่าสุด</option>
                                    </select>
                                </div>

                                <div id="commentsContainer" class="max-h-96 overflow-y-auto mb-4">
                                    <!-- Comments will be loaded here -->
                                </div>

                                <!-- เพิ่มส่วนของการส่งความคิดเห็น -->
                                <div class="comment-form flex items-center space-x-2">
                                    <input 
                                        type="text" 
                                        id="newCommentInput" 
                                        placeholder="เขียนความคิดเห็น..." 
                                        class="flex-grow border rounded-lg px-3 py-2"
                                        maxlength="500"
                                    >
                                    <span id="commentCharCount" class="text-xs text-gray-500">0/500</span>
                                    <button 
                                        onclick="window.wasteManager.submitComment()"
                                        class="bg-green-500 text-white rounded-lg px-4 py-2 hover:bg-green-600"
                                    >
                                        ส่ง
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);
            console.log('Comments modal created with inline positioning styles');

            // Attach comment input live counter (500 chars)
            try {
                const commentInput = document.getElementById('newCommentInput');
                const commentCounter = document.getElementById('commentCharCount');
                if (commentInput && commentCounter) {
                    const apply = () => { commentCounter.textContent = `${commentInput.value.length}/500`; };
                    commentInput.setAttribute('maxlength', '500');
                    commentInput.addEventListener('input', apply);
                    apply();
                }
            } catch (e) {
                console.warn('Failed to init comment counter:', e);
            }
        }
    }


    async resetFilters() {
        this.currentFilter = {
            wasteType: 'all',
            managementType: 'all',
            searchQuery: ''
        };
        this.currentPage = 1;

        // Reset UI
        document.querySelectorAll('.filter-chip').forEach(button => {
            if (button.dataset.type === 'all') {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        });

        const searchInput = document.querySelector('.search-input');
        if (searchInput) searchInput.value = '';

        await this.renderPosts();
    }

    // Pagination functions
    updatePaginationControls(totalPosts) {
        const totalPages = Math.ceil(totalPosts / this.postsPerPage);
        
        console.log(`Pagination: Page ${this.currentPage} of ${totalPages} (Total Posts: ${totalPosts})`);
        
        // อัพเดตข้อความหน้าปัจจุบัน
        const currentPageElement = document.getElementById('currentPage');
        if (currentPageElement) {
            currentPageElement.textContent = `หน้า ${this.currentPage} จาก ${totalPages || 1}`;
        }
        
        // อัพเดตปุ่มเปลี่ยนหน้า
        const prevPageBtn = document.getElementById('prevPage');
        const nextPageBtn = document.getElementById('nextPage');
        
        if (prevPageBtn) {
            prevPageBtn.disabled = this.currentPage <= 1;
            // ถ้าปุ่มถูก disabled ให้เพิ่มคลาส opacity-50 ถ้าไม่ให้ลบคลาส
            prevPageBtn.classList.toggle('opacity-50', this.currentPage <= 1);
        }
        
        if (nextPageBtn) {
            nextPageBtn.disabled = this.currentPage >= totalPages || totalPosts === 0;
            // ถ้าปุ่มถูก disabled ให้เพิ่มคลาส opacity-50 ถ้าไม่ให้ลบคลาส
            nextPageBtn.classList.toggle('opacity-50', this.currentPage >= totalPages || totalPosts === 0);
        }
    }
    
async confirmDeletePost(postId) {
  try {
    if (window.Swal && Swal.fire) {
      const r = await Swal.fire({
        title: 'ยืนยันการลบโพสต์?',
        text: 'เมื่อลบแล้วไม่สามารถกู้คืนได้',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก'
      });
      if (!r.isConfirmed) return;
    } else {
      if (!confirm('ยืนยันการลบโพสต์นี้หรือไม่?')) return;
    }

    await this.deletePost(postId);

    // เอาการ์ดใน profile-modal ออก (ถ้ามี)
    const pmCard = document.querySelector(`article.modal-post[data-post-id="${postId}"]`);
    if (pmCard) pmCard.remove();

    // เอาการ์ดในฟีดออก (กรณีเปิดฟีดอยู่)
    const feedCard = document.querySelector(`.share-card[data-post-id="${postId}"]`);
    if (feedCard) feedCard.remove();

    // ลดตัวนับในหัวโมดัล (ถ้ามี element นับ)
    const countEl = document.querySelector('#postCount');
    if (countEl) {
      const num = parseInt(countEl.textContent, 10);
      if (!Number.isNaN(num)) countEl.textContent = Math.max(0, num - 1);
    }

    await this.showCustomAlert({
      title: 'สำเร็จ',
      message: 'ลบโพสต์เรียบร้อย',
      type: 'success'
    });
  } catch (error) {
    console.error('confirmDeletePost error:', error);
    await this.showCustomAlert({
      title: 'เกิดข้อผิดพลาด',
      message: error?.message || 'ไม่สามารถลบโพสต์ได้',
      type: 'error'
    });
  }
}
    async changePage(direction) {
        try {
            console.log(`[DEBUG] changePage called with direction: ${direction}`);
            
            // แปลงให้เป็นตัวเลขเสมอ
            direction = parseInt(direction);
            
            if (isNaN(direction)) {
                console.error(`[ERROR] Invalid direction value: ${direction}`);
                return;
            }
            
            // ดึงโพสต์ที่ผ่านการกรองแล้ว
            const filteredPosts = this.filterPosts();
            console.log(`[DEBUG] Total filtered posts: ${filteredPosts.length}`);
            
            // คำนวณหน้าทั้งหมด
            const totalPages = Math.ceil(filteredPosts.length / this.postsPerPage);
            console.log(`[DEBUG] Posts per page: ${this.postsPerPage}, Total pages: ${totalPages}`);
            
            // คำนวณหน้าใหม่
            const newPage = this.currentPage + direction;
            console.log(`[DEBUG] Current page: ${this.currentPage}, New page: ${newPage}`);
            
            // ตรวจสอบว่าหน้าใหม่ถูกต้องหรือไม่
            if (newPage >= 1 && newPage <= totalPages) {
                console.log(`[INFO] Changing to page ${newPage}`);
                this.currentPage = newPage;
                
                // แสดงโพสต์หน้าใหม่
                await this.renderPosts();
                
                // เลื่อนไปด้านบนของหน้า
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
                
                console.log(`[INFO] Page changed successfully to ${this.currentPage}`);
            } else {
                console.warn(`[WARN] Cannot change to page ${newPage} (valid range: 1-${totalPages})`);
                // อัปเดตสถานะปุ่มแม้ไม่เปลี่ยนหน้า
                this.updatePaginationControls(postsForSort.length);
            }
        } catch (error) {
            console.error('[ERROR] Error in changePage:', error);
        }
    }
    initPaginationControls() {
        console.log("Initializing pagination controls...");
        
        const prevPageBtn = document.getElementById('prevPage');
        const nextPageBtn = document.getElementById('nextPage');
        
        if (prevPageBtn) {
            prevPageBtn.onclick = null; // ล้าง event listeners เดิม
            prevPageBtn.addEventListener('click', () => this.changePage(-1));
        }
        
        if (nextPageBtn) {
            nextPageBtn.onclick = null; // ล้าง event listeners เดิม
            nextPageBtn.addEventListener('click', () => this.changePage(1));
        }
    }
    
    // เพิ่มเมธอด init เพื่อเริ่มต้นการทำงาน
    async init() {
        try {
            await this.fetchPosts();
            await this.renderPosts();
            this.initPaginationControls();
            this.bindSortControls(); // เพิ่มบรรทัดนี้
        } catch (error) {
            console.error('Error initializing WasteManagement:', error);
        }
    }
    togglePostMenu(postId) {
  try {
    // ปิดเมนูอื่นก่อน
    document.querySelectorAll('[id^="post-menu-"]').forEach(el => {
      if (el.id !== `post-menu-${postId}`) el.classList.add('hidden');
    });

    const menu = document.getElementById(`post-menu-${postId}`);
    if (!menu) return;

    menu.classList.toggle('hidden');

    // คลิกนอกแล้วปิด
    const close = (e) => {
      if (!menu.contains(e.target)) {
        menu.classList.add('hidden');
        document.removeEventListener('click', close);
      }
    };
    setTimeout(() => document.addEventListener('click', close), 0);
  } catch (e) {
    console.warn('togglePostMenu error', e);
  }
}

    async editPost(postId) {
        try {
            const response = await fetch(`get_post_detail.php?postId=${postId}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'ไม่สามารถโหลดข้อมูลโพสต์ได้');
            }

            // เปิด modal แก้ไขโพสต์
            this.openEditPostModal(data.data);
        } catch (error) {
            console.error('Error fetching post details:', error);
            alert(error.message);
        }
    }
    async saveEditedPost(formEl) {
  try {
    const fd = (formEl instanceof FormData) ? formEl : new FormData(formEl);

    // ดึงค่าปัจจุบันจากฟอร์ม
    const nowObj = this._normalizeEditObject({
      title: fd.get('title'),
      details: fd.get('details'),
      Waste_type: fd.get('Waste_type'),
      Management_type: fd.get('Management_type'),
      images: fd.getAll('images[]'),                  // ชื่อ field อาจเป็น images[] / keep ตามฟอร์มจริง
      video_source: fd.get('video_source'),
      video_platform: fd.get('video_platform'),
      video_url: fd.get('video_url'),
      video_path: fd.get('video_path')
    });

    // กรณีมีรายการ "ลบรูป" ด้วย ให้ถือว่าเป็นการเปลี่ยนแน่ ๆ
    const removedImages = fd.getAll('removed_images[]') || [];
    const hasRemoval = removedImages.length > 0;

    // ถ้ามีไฟล์ใหม่อัปโหลดก็ถือว่าเปลี่ยน
    let hasNewFiles = false;
    for (const [k, v] of fd.entries()) {
      if (k === 'new_images[]' && v && v.name) { hasNewFiles = true; break; }
      if (k === 'video_file' && v && v.name) { hasNewFiles = true; break; }
    }

    // เทียบกับ snapshot เดิม
    const A = JSON.stringify(this._editOriginal || {});
    const B = JSON.stringify(nowObj || {});

    if (!hasNewFiles && !hasRemoval && A === B) {
      await Swal.fire({
        icon: 'warning',
        title: 'ไม่มีการเปลี่ยนแปลง',
        text: 'คุณยังไม่ได้แก้ไขข้อมูลใด ๆ',
        confirmButtonText: 'ตกลง'
      });
      return; // ยุติ ไม่ยิง API
    }

    // ---- เรียก API ปรับปรุงตามเดิม ----
    const res = await fetch('update_post.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data?.success) {
      // ฝั่ง server guard NO_CHANGES
      if (data?.code === 'NO_CHANGES') {
        await Swal.fire({ icon: 'warning', title: 'ไม่มีการเปลี่ยนแปลง', text: data.message || '' });
        return;
      }
      throw new Error(data?.message || 'บันทึกไม่สำเร็จ');
    }

    await Swal.fire({ icon: 'success', title: 'สำเร็จ', text: 'บันทึกการแก้ไขแล้ว' });

    // รีเฟรช UI ตามโค้ดเดิมของคุณ
    if (typeof this.fetchPosts === 'function') await this.fetchPosts();
    if (typeof this.renderPosts === 'function') await this.renderPosts();
    this.closeEditPostModal && this.closeEditPostModal();
    this._editOriginal = null;
    this._editingPostId = null;
  } catch (err) {
    console.error('saveEditedPost error', err);
    await Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: err.message || 'บันทึกไม่สำเร็จ' });
  }
}
_shallowNormalize(o) {
  const x = {...o};
  Object.keys(x).forEach(k => {
    const v = x[k];
    if (v == null) x[k] = '';
    else if (typeof v === 'string') x[k] = v.trim();
    else if (Array.isArray(v)) x[k] = v.filter(Boolean).map(s => String(s).trim());
    else x[k] = v;
  });
  return x;
}
_normalizeEditObject(obj) {
  const x = {...obj};
  const normStr = (v) => (v == null ? '' : String(v).trim());
  x.title            = normStr(x.title);
  x.details          = normStr(x.details);
  x.Waste_type       = normStr(x.Waste_type ?? x.waste_type);
  x.Management_type  = normStr(x.Management_type ?? x.management_type);
  x.video_source     = normStr(x.video_source);
  x.video_platform   = normStr(x.video_platform);
  x.video_url        = normStr(x.video_url);
  x.video_path       = normStr(x.video_path);
  // images: ให้เป็นอาเรย์สตริงที่ไม่ว่าง
  let imgs = Array.isArray(x.images) ? x.images : (x.images ? [x.images] : []);
  x.images = imgs.filter(Boolean).map(normStr);
  return x;
}
    openEditPostModal(postId) {
  // ... โค้ดเดิมที่โหลดโพสต์และเปิด modal + ใส่ค่าให้ฟอร์ม ...

  const post = this.posts?.find(p => String(p.post_id ?? p.postId) === String(postId)) || {};
  this._editOriginal = this._normalizeEditObject({
    title: post.title,
    details: post.details,
    Waste_type: post.Waste_type,
    Management_type: post.Management_type,
    images: post.images,                 // อาเรย์ไฟล์รูปเดิม
    video_source: post.video_source,
    video_platform: post.video_platform,
    video_url: post.video_url,
    video_path: post.video_path
  });

  // (แนะนำ) เก็บ id ที่กำลังแก้
  this._editingPostId = postId;
}

    async deletePost(postId) {
  const res = await fetch('delete_post.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
    body: new URLSearchParams({ postId })
  });
  const result = await res.json();
  if (!result || !result.success) {
    throw new Error(result?.message || 'ลบโพสต์ไม่สำเร็จ');
  }

  // อัปเดต state ภายใน
  if (Array.isArray(this.posts)) {
    this.posts = this.posts.filter(p => String(p.post_id) !== String(postId));
  }
  // รีเฟรช pagination/กริด ถ้าจำเป็น
  if (typeof this.renderPosts === 'function') {
    try { await this.renderPosts(); } catch(_) {}
  }
}


    // Bind filter dropdowns (desktop & mobile)
    bindFilterControls() {
        try {
            const wasteSel = document.getElementById('wasteTypeSelect') || document.querySelector('select[data-type="select"]');
            const mgmtSel  = document.getElementById('managementTypeSelect') || document.querySelector('select[data-management-type="select"]');

            if (wasteSel && !wasteSel._wmBound) {
                wasteSel._wmBound = true;
                wasteSel.value = this.currentFilter?.wasteType || 'all';
                wasteSel.addEventListener('change', (e) => this.filterWaste(e.target.value));
            }
            if (mgmtSel && !mgmtSel._wmBound) {
                mgmtSel._wmBound = true;
                mgmtSel.value = this.currentFilter?.managementType || 'all';
                mgmtSel.addEventListener('change', (e) => this.filterManagement(e.target.value));
            }
        } catch (e) {
            console.warn('bindFilterControls warn:', e);
        }
    }
}
window.wasteManager = new WasteManagement();


// Create instance
const wasteManager = new WasteManagement();

// Make it globally available
window.wasteManager = wasteManager;
// Expose constructor for helper bindings (when imported as module)
try { window.WasteManagement = window.WasteManagement || WasteManagement; } catch (e) {}

// Export for module usage
export default wasteManager;



/* =========================
 * Sorting helpers & bindings
 * ========================= */
(function() {
    // Resolve constructor from global or current instance
    const WM = window.WasteManagement || (window.wasteManager && window.wasteManager.constructor);
    if (!WM) return;

    // Compare helper (string) with Thai locale support
    function _strCmp(a, b) {
        try {
            return String(a || '').localeCompare(String(b || ''), 'th-TH', {sensitivity: 'base'});
        } catch (e) {
            // Fallback
            return (a || '').toString().localeCompare((b || '').toString());
        }
    }

    // Parse date safely
    function _dateVal(x) {
        if (!x) return 0;
        let s = String(x).trim();
        // Support MySQL 'YYYY-MM-DD HH:MM:SS' by converting to ISO-like
        if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/.test(s)) {
            s = s.replace(' ', 'T');
        }
        const t = Date.parse(s);
        return isNaN(t) ? 0 : t;
    }

    // Numeric safe
    function _num(x) {
        const n = Number(x);
        return isFinite(n) ? n : 0;
    }

    WM.prototype.applySort = function(posts) {
        try {
            const cfg = (this.currentSort || {key: 'date', dir: 'desc'});
            const dir = cfg.dir === 'asc' ? 1 : -1;
            const key = cfg.key || 'date';

            const arr = Array.isArray(posts) ? posts.slice() : [];

            arr.sort((a, b) => {
                let cmp = 0;
                switch (key) {
                    case 'title':
                        cmp = _strCmp(a?.title, b?.title);
                        break;
                    case 'likes':
                        cmp = _num(a?.likes) - _num(b?.likes);
                        break;
                    case 'comments':
                        cmp = _num(a?.comment_count) - _num(b?.comment_count);
                        break;
                    case 'date':
                    default:
                        cmp = _dateVal(a?.date) - _dateVal(b?.date);
                        break;
                }
                // Apply direction
                cmp = cmp * dir;

                // Tie-breaker by date desc to keep stable ordering
                if (cmp === 0) {
                    return _dateVal(b?.date) - _dateVal(a?.date);
                }
                return cmp;
            });

            return arr;
        } catch (e) {
            console.error('applySort error:', e);
            return posts || [];
        }
    };

    WM.prototype.setSort = function(key, dir) {
        try {
            this.currentSort = { key, dir };
            this.currentPage = 1;
            this.updateSortUI && this.updateSortUI();
            return this.renderPosts();
        } catch (e) {
            console.error('setSort error:', e);
        }
    };

    WM.prototype.updateSortUI = function() {
    try {
        const cfg = (this.currentSort || {});
        
        // อัปเดต label ใน dropdown
        const sortLabelEl = document.getElementById('sortLabel');
        if (sortLabelEl) {
            const labelMap = {
                'date:desc': 'ล่าสุด',
                'date:asc': 'เก่าสุด',
                'title:asc': 'ชื่อ ก-ฮ',
                'title:desc': 'ชื่อ ฮ-ก',
                'likes:desc': 'ยอดถูกใจ',
                'likes:asc': 'ยอดถูกใจ (น้อยสุด)',
                'comments:desc': 'ยอดความคิดเห็น',
                'comments:asc': 'ยอดความคิดเห็น (น้อยสุด)'
            };
            sortLabelEl.textContent = labelMap[`${cfg.key}:${cfg.dir}`] || 'ล่าสุด';
        }
        
        // อัปเดต select box (mobile)
        const sel = document.getElementById('sortSelect');
        if (sel) {
            const map = {
                'date:desc': 'newest',
                'date:asc': 'oldest',
                'title:asc': 'title_asc',
                'title:desc': 'title_desc',
                'likes:desc': 'likes_desc',
                'likes:asc': 'likes_asc',
                'comments:desc': 'comments_desc',
                'comments:asc': 'comments_asc'
            };
            const val = map[`${cfg.key}:${cfg.dir}`] || 'newest';
            sel.value = val;
        }
    } catch (e) {
        console.warn('updateSortUI warn:', e);
    }
};

    WM.prototype.bindSortControls = function() {
    try {
        // Buttons in dropdown
        document.querySelectorAll('.sort-option').forEach(btn => {
            if (btn._sortBound) return;
            btn._sortBound = true;
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const key = btn.getAttribute('data-sort') || 'date';
                const dir = btn.getAttribute('data-dir') || 'desc';
                this.setSort(key, dir);
                
                // อัปเดตปุ่ม dropdown ตามการเลือก
                document.getElementById('sortLabel').textContent = btn.textContent;
            });
        });

        // Select fallback สำหรับ mobile
        const sel = document.getElementById('sortSelect');
        if (sel && !sel._sortBound) {
            sel._sortBound = true;
            sel.addEventListener('change', (e) => {
                const v = sel.value;
                const map = {
                    'newest': ['date', 'desc'],
                    'oldest': ['date', 'asc'],
                    'title_asc': ['title', 'asc'],
                    'title_desc': ['title', 'desc'],
                    'likes_desc': ['likes', 'desc'],
                    'likes_asc': ['likes', 'asc'],
                    'comments_desc': ['comments', 'desc'],
                    'comments_asc': ['comments', 'asc'],
                };
                const [key, dir] = map[v] || ['date', 'desc'];
                this.setSort(key, dir);
            });
        }

        // Initial UI sync
        this.updateSortUI && this.updateSortUI();
    } catch (e) {
        console.warn('bindSortControls warn:', e);
    }
};


    // In case instance already exists when this file loads
    document.addEventListener('DOMContentLoaded', () => {
        try {
            if (window.wasteManager && typeof window.wasteManager.bindSortControls === 'function') {
                window.wasteManager.bindSortControls();
            }
            if (window.wasteManager && typeof window.wasteManager.bindFilterControls === 'function') {
                window.wasteManager.bindFilterControls();
            }
        } catch (e) {
            console.warn('DOMContentLoaded bind controls failed:', e);
        }
    });
})();




