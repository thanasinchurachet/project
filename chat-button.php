<?php
date_default_timezone_set('Asia/Bangkok');
require_once 'db.php';

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['userid'])) {
    $userProfile = ['profile_img' => 'img/profile_uploads/default.jpg', 'name' => 'Guest'];
} else {
    try {
        $stmt = $pdo->prepare("SELECT profile_img, name FROM users WHERE userID = ?");
        $stmt->execute([$_SESSION['userid']]);
        $userProfile = $stmt->fetch();
        if (!$userProfile) {
            $userProfile = ['profile_img' => 'img/profile_uploads/default.jpg', 'name' => 'User'];
        } else {
            $userProfile['profile_img'] = !empty($userProfile['profile_img']) ? 'img/profile_uploads/' . $userProfile['profile_img'] : 'img/profile_uploads/default.jpg';
        }
    } catch (PDOException $e) {
        error_log("Database error in chat-button.php: " . $e->getMessage());
        $userProfile = ['profile_img' => 'img/profile_uploads/default.jpg', 'name' => 'User'];
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>

<!-- Floating Chat Button -->
<button id="chatButton" class="fixed bottom-6 right-6 bg-gradient-to-br from-green-500 to-green-700 text-white rounded-full w-16 h-16 flex items-center justify-center shadow-xl hover:from-green-600 hover:to-green-800 transition-all duration-300 z-50 animate-bounce hover:animate-none hover:scale-110 <?php echo !isset($_SESSION['userid']) ? 'hidden' : ''; ?>">
    <div class="absolute inset-0 rounded-full bg-green-400 animate-ping opacity-20"></div>
    <i class="fas fa-comment-alt text-2xl relative z-10"></i>
</button>

<!-- Chat Window -->
<div id="chatWindow" class="fixed bottom-24 right-6 w-96 h-[28rem] bg-white rounded-2xl shadow-2xl flex flex-col z-50 hidden overflow-hidden border border-gray-100 backdrop-blur-sm">
    <!-- Header -->
    <div class="bg-gradient-to-r from-green-600 via-green-500 to-emerald-600 text-white p-4 rounded-t-2xl flex justify-between items-center relative overflow-hidden">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center backdrop-blur-sm">
                <i class="fas fa-robot text-lg"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold">แชทกับ BinBuddy AI</h3>
                <div class="flex items-center space-x-1 text-sm opacity-90">
                    <div class="w-2 h-2 bg-green-300 rounded-full animate-pulse"></div>
                    <span>ออนไลน์</span>
                </div>
            </div>
        </div>
        <button id="closeChat" class="text-white hover:text-gray-200 hover:bg-white hover:bg-opacity-20 rounded-full w-8 h-8 flex items-center justify-center transition-all duration-300 hover:rotate-90">
            <i class="fas fa-times"></i>
        </button>
        <!-- Decorative elements -->
        <div class="absolute -top-10 -right-10 w-20 h-20 bg-white bg-opacity-10 rounded-full"></div>
        <div class="absolute -bottom-5 -left-5 w-16 h-16 bg-white bg-opacity-5 rounded-full"></div>
    </div>

    <!-- Messages Area -->
    <div id="chatMessages" class="flex-1 p-4 overflow-y-auto bg-gradient-to-b from-gray-50 to-white">
        <!-- Messages will be appended here -->
    </div>

    <!-- Input Area -->
    <div class="p-4 border-t border-gray-200 space-y-3 bg-gradient-to-r from-gray-50 to-white">
        <!-- Image Preview Area -->
        <div id="imagePreviewContainer" class="hidden relative">
            <img id="imagePreview" class="max-w-full max-h-40 rounded-xl border-2 border-green-200 shadow-lg">
            <button id="removeImage" class="absolute -top-2 -right-2 bg-gradient-to-br from-red-500 to-red-600 text-white rounded-full w-7 h-7 flex items-center justify-center shadow-lg hover:from-red-600 hover:to-red-700 transition-all duration-300 hover:scale-110">
                <i class="fas fa-times text-xs"></i>
            </button>
        </div>
         <div class="flex flex-wrap gap-2 justify-center mb-3">
            <button class="quick-command bg-white text-gray-600 px-3 py-1 rounded-full text-xs hover:bg-gray-100 hover:text-gray-800 transition-all duration-300 hover:scale-105 shadow-sm border border-gray-300 hover:border-gray-400" data-message="ขยะมีกี่ประเภท">
                ขยะมีกี่ประเภท
            </button>
            <button class="quick-command bg-white text-gray-600 px-3 py-1 rounded-full text-xs hover:bg-gray-100 hover:text-gray-800 transition-all duration-300 hover:scale-105 shadow-sm border border-gray-300 hover:border-gray-400" data-message="ขยะจัดการยังไง">
                ขยะจัดการยังไง
            </button>
            <button class="quick-command bg-white text-gray-600 px-3 py-1 rounded-full text-xs hover:bg-gray-100 hover:text-gray-800 transition-all duration-300 hover:scale-105 shadow-sm border border-gray-300 hover:border-gray-400" data-message="วิธีการรีไซเคิล">
                วิธีการรีไซเคิล
            </button>
        </div>
        <!-- Chat Input Area -->
        <div class="flex items-end gap-2">
            <!-- Upload Button -->
            <div class="relative">
                <button id="uploadImage" class="bg-gradient-to-br from-blue-500 to-blue-600 text-white p-2 rounded-full h-12 w-12 flex items-center justify-center hover:from-blue-600 hover:to-blue-700 transition-all duration-300 hover:scale-105 active:scale-95 shadow-md hover:shadow-lg group" title="อัปโหลดรูปภาพหรือไฟล์">
                    <i class="fas fa-plus text-xl"></i>
                </button>
                <!-- Tooltip -->
                <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-3 py-1 bg-gray-800 text-white text-xs rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-300 whitespace-nowrap">
                    อัปโหลดรูปภาพหรือไฟล์
                    <div class="absolute top-full left-1/2 transform -translate-x-1/2 w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-gray-800"></div>
                </div>
            </div>
            
            <div class="flex-1 relative">
                <textarea id="chatInput" placeholder="ถามเกี่ยวกับขยะ..." rows="1" 
                    class="flex-1 p-3 rounded-xl border-2 border-gray-200 focus:outline-none focus:border-green-500 resize-none overflow-hidden max-h-32 bg-white shadow-sm transition-all duration-300 hover:shadow-md focus:shadow-lg w-full"></textarea>
                <div class="absolute inset-0 rounded-xl bg-gradient-to-r from-green-500 to-emerald-500 opacity-0 hover:opacity-5 transition-opacity duration-300 pointer-events-none"></div>
            </div>
            
            <button id="sendMessage" class="bg-gradient-to-br from-green-600 to-green-700 text-white p-3 rounded-xl hover:from-green-700 hover:to-green-800 h-12 w-12 flex items-center justify-center shadow-lg hover:shadow-xl transition-all duration-300 hover:scale-105 active:scale-95">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
        
        <!-- Status Indicator -->
        <div class="flex justify-center">
            <div id="typingIndicator" class="hidden text-gray-500 text-sm flex items-center space-x-2">
                <div class="typing-dots">
                    <span></span><span></span><span></span>
                </div>
                <span>BinBuddy AI กำลังพิมพ์</span>
            </div>
        </div>
    </div>
    
    <!-- Hidden File Input -->
    <input id="imageInput" type="file" accept="image/*" class="hidden">
    
    <!-- Upload Options Modal -->
    <div id="uploadModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60]">
        <div class="bg-white rounded-2xl p-6 m-4 max-w-sm w-full shadow-2xl transform transition-all duration-300">
            <h3 class="text-lg font-bold text-gray-800 mb-4 text-center">เลือกประเภทไฟล์</h3>
            <div class="space-y-3">
                <button id="selectImage" class="w-full bg-gradient-to-r from-green-500 to-green-600 text-white p-3 rounded-xl hover:from-green-600 hover:to-green-700 transition-all duration-300 flex items-center justify-center space-x-2 shadow-md hover:shadow-lg">
                    <i class="fas fa-image"></i>
                    <span>อัปโหลดรูปภาพ</span>
                </button>
                <button id="cancelUpload" class="w-full bg-gradient-to-r from-gray-400 to-gray-500 text-white p-3 rounded-xl hover:from-gray-500 hover:to-gray-600 transition-all duration-300 flex items-center justify-center space-x-2 shadow-md hover:shadow-lg">
                    <i class="fas fa-times"></i>
                    <span>ยกเลิก</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const chatButton = document.getElementById("chatButton");
    const chatWindow = document.getElementById("chatWindow");
    const closeChat = document.getElementById("closeChat");
    const sendMessage = document.getElementById("sendMessage");
    const chatInput = document.getElementById("chatInput");
    const chatMessages = document.getElementById("chatMessages");
    const uploadImage = document.getElementById("uploadImage");
    const imageInput = document.getElementById("imageInput");
    const imagePreviewContainer = document.getElementById("imagePreviewContainer");
    const imagePreview = document.getElementById("imagePreview");
    const removeImage = document.getElementById("removeImage");
    const typingIndicator = document.getElementById("typingIndicator");
    const uploadModal = document.getElementById("uploadModal");
    const selectImage = document.getElementById("selectImage");
    const cancelUpload = document.getElementById("cancelUpload");

    // Track if chat history is loaded
    let isHistoryLoaded = false;

    // Auto-resize textarea
    chatInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Handle Shift+Enter for new line, Enter for send
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage.click();
        }
    });
    const quickCommands = document.querySelectorAll('.quick-command');
    quickCommands.forEach(button => {
        button.addEventListener('click', function() {
            const message = this.getAttribute('data-message');
            chatInput.value = message;
            sendMessage.click();
        });
    });

    // Load chat history from localStorage with user-specific key
    function loadChatHistory() {
        const userId = "<?php echo isset($_SESSION['userid']) ? $_SESSION['userid'] : 'guest'; ?>";
        const historyKey = `binbuddy_chat_history_${userId}`;
        const history = localStorage.getItem(historyKey);
        if (history && !isHistoryLoaded) {
            try {
                const messages = JSON.parse(history);
                messages.forEach(msg => {
                    let content = msg.content;
                    // Convert base64 image back to <img> tag if it contains data:image
                    if (content.includes('data:image')) {
                        content = `<div><img src="${content}" class="max-w-full max-h-64 rounded-lg mb-2 shadow-md"></div>`;
                    }
                    appendMessage(msg.sender, content, msg.className);
                });
                isHistoryLoaded = true;
            } catch (e) {
                console.error("Error loading chat history:", e);
            }
        }
    }

    // Save chat history to localStorage with user-specific key
    function saveChatHistory(sender, content, className) {
        const userId = "<?php echo isset($_SESSION['userid']) ? $_SESSION['userid'] : 'guest'; ?>";
        const historyKey = `binbuddy_chat_history_${userId}`;
        const history = localStorage.getItem(historyKey) || '[]';
        try {
            const messages = JSON.parse(history);
            messages.push({ sender, content: escapeHtml(content), className });
            localStorage.setItem(historyKey, JSON.stringify(messages));
        } catch (e) {
            console.error("Error saving chat history:", e);
        }
    }

    // Toggle chat window
    chatButton.addEventListener("click", () => {
        if (chatButton.classList.contains('hidden')) return;
        chatWindow.classList.toggle("hidden");
        if (!chatWindow.classList.contains("hidden")) {
            loadChatHistory();
            chatInput.focus();
        }
    });

    closeChat.addEventListener("click", () => {
        chatWindow.classList.add("hidden");
    });

    // Handle upload modal
    uploadImage.addEventListener("click", () => {
        uploadModal.classList.remove("hidden");
    });

    selectImage.addEventListener("click", () => {
        uploadModal.classList.add("hidden");
        imageInput.click();
    });

    cancelUpload.addEventListener("click", () => {
        uploadModal.classList.add("hidden");
    });

    // Close modal when clicking outside
    uploadModal.addEventListener("click", (e) => {
        if (e.target === uploadModal) {
            uploadModal.classList.add("hidden");
        }
    });

    imageInput.addEventListener("change", () => {
        const file = imageInput.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            imagePreview.src = e.target.result;
            imagePreviewContainer.classList.remove("hidden");
        };
        reader.readAsDataURL(file);
    });

    removeImage.addEventListener("click", () => {
        imagePreview.src = "";
        imagePreviewContainer.classList.add("hidden");
        imageInput.value = "";
    });

    // Append message to chat with improved layout
    function appendMessage(sender, content, className) {
        const messageContainer = document.createElement("div");
        messageContainer.className = "mb-4 flex items-start transition-all duration-300 hover:scale-[1.02]";

        let messageHTML = '';
        if (sender === "คุณ") {
            // User message - profile on the right
            messageContainer.className += " justify-end";
            messageHTML = `
                <div class="max-w-[75%] bg-gradient-to-br from-green-100 to-green-200 rounded-2xl p-3 shadow-md border border-green-200 mr-3">
                    <div class="text-green-700">${content}</div>
                </div>
                <div class="relative flex-shrink-0">
                    <img src="<?php echo htmlspecialchars($userProfile['profile_img']); ?>" alt="User Profile" class="w-10 h-10 rounded-full shadow-lg border-2 border-white">
                    <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-green-500 rounded-full border-2 border-white"></div>
                </div>
            `;
        } else if (sender === "BinBuddy AI") {
            // AI message - icon on the left
            messageContainer.className += " justify-start";
            messageHTML = `
                <div class="relative flex-shrink-0 mr-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-lg border-2 border-white">
                        <i class="fas fa-robot text-white text-sm"></i>
                    </div>
                    <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-blue-500 rounded-full border-2 border-white animate-pulse"></div>
                </div>
                <div class="max-w-[75%] bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl p-3 shadow-md border border-gray-200">
                    <div class="text-gray-700">${content}</div>
                </div>
            `;
        } else if (sender === "typing") {
            // Typing indicator
            messageContainer.className += " justify-start animate-pulse";
            messageHTML = `
                <div class="relative flex-shrink-0 mr-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-full flex items-center justify-center shadow-lg border-2 border-white">
                        <i class="fas fa-robot text-white text-sm"></i>
                    </div>
                    <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-yellow-500 rounded-full border-2 border-white animate-bounce"></div>
                </div>
                <div class="max-w-[75%] bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl p-3 shadow-md border border-gray-200">
                    <div class="typing-animation text-gray-600">กำลังพิมพ์...</div>
                </div>
            `;
        }
        
        messageContainer.innerHTML = messageHTML;
        chatMessages.appendChild(messageContainer);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return messageContainer;
    }

    // Show typing indicator with animation
    function showTypingIndicator() {
        const typingMsg = appendMessage("typing", "", "bg-gray-200");
        return typingMsg;
    }

    // Hide typing indicator
    function hideTypingIndicator(typingMsg) {
        if (typingMsg && typingMsg.parentNode) {
            typingMsg.parentNode.removeChild(typingMsg);
        }
    }
    

    // Escape HTML to prevent XSS
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Convert file to base64
    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error("Failed to read file"));
            reader.readAsDataURL(file);
        });
    }

    // Send data to PHP backend with CSRF token
    async function sendToGemini(data) {
  const typingMsg = showTypingIndicator();
  const transient = s => [429,500,502,503,504].includes(s);
  const maxRetries = 2;
  let attempt = 0, lastErr;

  // ปิดปุ่มส่งชั่วคราว
  sendMessage.disabled = true;

  while (attempt <= maxRetries) {
    try {
      const res = await fetch("API/gemini.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": "<?php echo $csrfToken; ?>"
        },
        body: JSON.stringify({
          message: escapeHtml(data.text || ""),
          image: data.image || "",
          mimeType: data.mimeType || "image/jpeg", // ✅ ส่งชนิดไฟล์ไปด้วย
          prefer_model: "gemini-2.5-pro",
          csrf_token: "<?php echo $csrfToken; ?>"
        })
      });

      if (!res.ok) {
        if (transient(res.status) && attempt < maxRetries) {
          await new Promise(r => setTimeout(r, 500 * Math.pow(2, attempt)));
          attempt++;
          continue;
        }
        // ไม่โชว์เลขสถานะดิบ
        throw new Error("ระบบ AI ไม่พร้อมชั่วคราว กรุณาลองใหม่อีกสักครู่");
      }

      const result = await res.json();
      hideTypingIndicator(typingMsg);
      sendMessage.disabled = false;

      if (result.error) {
        appendMessage("BinBuddy AI", escapeHtml(result.error), "bg-red-200");
        saveChatHistory("BinBuddy AI", escapeHtml(result.error), "bg-red-200");
      } else {
        appendMessage("BinBuddy AI", escapeHtml(result.reply), "bg-gray-200");
        saveChatHistory("BinBuddy AI", escapeHtml(result.reply), "bg-gray-200");
      }
      return;

    } catch (err) {
      lastErr = err;
      if (attempt < maxRetries) {
        await new Promise(r => setTimeout(r, 500 * Math.pow(2, attempt)));
        attempt++;
        continue;
      }
      hideTypingIndicator(typingMsg);
      sendMessage.disabled = false;
      appendMessage("BinBuddy AI", "เกิดข้อผิดพลาดในการติดต่อ AI: " + escapeHtml(err.message), "bg-red-200");
      saveChatHistory("BinBuddy AI", "เกิดข้อผิดพลาดในการติดต่อ AI: " + escapeHtml(err.message), "bg-red-200");
    }
  }
}
imageInput.addEventListener("change", () => {
  const file = imageInput.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    const dataUrl = reader.result;                 // data:image/png;base64,xxxx
    const [meta, b64] = dataUrl.split(",");
    const mimeType = meta.split(":")[1].split(";")[0];
    const content = `<div><img src="${dataUrl}" class="max-w-full max-h-64 rounded-lg mb-2 shadow-md"></div>`;
    appendMessage("คุณ", content, "bg-green-100");
    saveChatHistory("คุณ", dataUrl, "bg-green-100");
    sendToGemini({ image: b64, mimeType, text: chatInput.value.trim() || "ช่วยจำแนกขยะในรูปนี้" });
  };
  reader.readAsDataURL(file);
});

    // Send message or image
    sendMessage.addEventListener("click", async () => {
        const message = chatInput.value.trim();
        const file = imageInput.files[0];

        if (!message && !file) return;

        // Clear input immediately
        chatInput.value = "";
        chatInput.style.height = 'auto';
        imageInput.value = "";
        imagePreview.src = "";
        imagePreviewContainer.classList.add("hidden");

        // Append user message immediately
        if (file) {
            const reader = new FileReader();
            reader.onload = () => {
                const content = `<div>
                    <img src="${reader.result}" class="max-w-full max-h-64 rounded-lg mb-2 shadow-md">
                    ${message ? `<p>${escapeHtml(message)}</p>` : ''}
                </div>`;
                appendMessage("คุณ", content, "bg-green-100");
                saveChatHistory("คุณ", reader.result, "bg-green-100"); // Save base64 directly
                sendToGemini({ image: reader.result.split(",")[1], text: message || "ช่วยจำแนกขยะในรูปนี้" });
            };
            reader.readAsDataURL(file);
        } else {
            appendMessage("คุณ", escapeHtml(message), "bg-green-100");
            saveChatHistory("คุณ", escapeHtml(message), "bg-green-100");
            await sendToGemini({ text: message });
        }
    });
});
</script>

<style>
    #chatWindow {
        resize: vertical;
        min-height: 28rem;
        max-height: 80vh;
        min-width: 24rem;
        max-width: 24rem;
        backdrop-filter: blur(10px);
        z-index: 10000;
    }

    #chatMessages {
        scrollbar-width: thin;
        scrollbar-color: #10b981 #e5e7eb;
    }

    #chatMessages::-webkit-scrollbar {
        width: 8px;
    }

    #chatMessages::-webkit-scrollbar-track {
        background: linear-gradient(180deg, #f8fafc, #e2e8f0);
        border-radius: 4px;
    }

    #chatMessages::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #10b981, #059669);
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }

    #chatMessages::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, #059669, #047857);
    }

    .typing-dots {
        display: inline-flex;
        align-items: center;
        height: 1rem;
        margin-right: 0.5rem;
    }

    .typing-dots span {
        display: inline-block;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: linear-gradient(45deg, #10b981, #059669);
        margin: 0 2px;
        opacity: 0.4;
        animation: typing-dots 1.4s infinite ease-in-out;
        box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
    }

    .typing-dots span:nth-child(1) {
        animation-delay: 0s;
    }

    .typing-dots span:nth-child(2) {
        animation-delay: 0.2s;
    }

    .typing-dots span:nth-child(3) {
        animation-delay: 0.4s;
    }

    @keyframes typing-dots {
        0%, 60%, 100% { 
            transform: translateY(0); 
            opacity: 0.4; 
        }
        30% { 
            transform: translateY(-6px); 
            opacity: 1; 
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.5);
        }
    }

    #imagePreviewContainer {
        transition: all 0.3s ease;
    }

    /* Profile image glow effect */
    img[alt="User Profile"] {
        filter: drop-shadow(0 0 8px rgba(16, 185, 129, 0.3));
        transition: all 0.3s ease;
    }

    img[alt="User Profile"]:hover {
        filter: drop-shadow(0 0 12px rgba(16, 185, 129, 0.5));
        transform: scale(1.1);
    }

    /* Enhanced message containers */
    #chatMessages > div {
        animation: slideIn 0.3s ease-out;
        transform-origin: center;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    /* Button hover effects */
    button {
        position: relative;
        overflow: hidden;
    }

    button::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        background: rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        transition: width 0.6s, height 0.6s;
        transform: translate(-50%, -50%);
    }

    button:active::before {
        width: 300px;
        height: 300px;
    }

    .typing-animation {
        display: inline-flex;
        align-items: center;
        background: linear-gradient(45deg, #10b981, #059669);
        background-size: 200% 200%;
        animation: gradient-shift 2s ease-in-out infinite;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    @keyframes gradient-shift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    .typing-animation::after {
        content: '';
        display: inline-block;
        width: 4px;
        height: 4px;
        background: linear-gradient(45deg, #10b981, #059669);
        border-radius: 50%;
        margin-left: 2px;
        animation: blink 1s infinite;
        box-shadow: 0 0 4px rgba(16, 185, 129, 0.5);
    }

    @keyframes blink {
        0% { opacity: 0; transform: scale(0.8); }
        50% { opacity: 1; transform: scale(1.2); }
        100% { opacity: 0; transform: scale(0.8); }
    }

    /* Tooltip styles */
    .group:hover .group-hover\:opacity-100 {
        opacity: 1;
    }

    /* Modal animation */
    #uploadModal > div {
        animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
</style>
