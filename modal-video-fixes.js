
// modal-video-fixes.js
(function () {
  function isYouTubeSrc(src) {
    return /youtube\.com|youtu\.be/.test(src || "");
  }

  function ensureResponsive(wrapper, iframe) {
    try {
      if (wrapper && !wrapper.style.paddingTop) {
        wrapper.style.position = wrapper.style.position || "relative";
        // 16:9
        wrapper.style.paddingTop = "56.25%";
      }
      if (iframe) {
        iframe.style.position = "absolute";
        iframe.style.top = "0";
        iframe.style.left = "0";
        iframe.style.width = "100%";
        iframe.style.height = "100%";
        // ในโมดัลควรให้คลิกได้
        if (iframe.style.pointerEvents === "none") {
          iframe.style.pointerEvents = "auto";
        }
        // เผื่อ attributes บางอย่างจำเป็นกับ YouTube
        if (!iframe.getAttribute("allow")) {
          iframe.setAttribute("allow", "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share");
        }
        if (!iframe.getAttribute("referrerpolicy")) {
          iframe.setAttribute("referrerpolicy", "strict-origin-when-cross-origin");
        }
      }
    } catch (e) {
      console.warn("[modal-video-fixes] ensureResponsive warn:", e);
    }
  }

  function refreshIfNeeded(modalEl) {
    try {
      const nodes = modalEl.querySelectorAll('.video-embed iframe, iframe[src*="youtube.com"], iframe[src*="youtu.be"]');
      nodes.forEach((ifr) => {
        const src = ifr.getAttribute("src") || "";
        const wrap = ifr.closest(".video-embed") || ifr.parentElement;
        ensureResponsive(wrap, ifr);
        if (isYouTubeSrc(src)) {
          // บางกรณี YouTube ไม่ render เมื่อ element ถูกแสดงจาก hidden -> visible
          requestAnimationFrame(() => {
            try { ifr.src = src; } catch (e) {}
          });
        }
      });
    } catch (e) {
      console.warn("[modal-video-fixes] refreshIfNeeded warn:", e);
    }
  }

  // Bootstrap 5 modal event
  document.addEventListener("shown.bs.modal", function (ev) {
    refreshIfNeeded(ev.target);
  });

  // กันพลาด: ถ้า modal โผล่มาโดยไม่ได้ยิง event (บางธีม custom) ให้ส่อง DOM เปลี่ยนแปลง
  const mo = new MutationObserver((muts) => {
    muts.forEach((m) => {
      if (m.type === "attributes" && m.target.classList && m.target.classList.contains("modal") && m.target.classList.contains("show")) {
        refreshIfNeeded(m.target);
      }
      if (m.addedNodes && m.addedNodes.length) {
        m.addedNodes.forEach((n) => {
          if (n.nodeType === 1 && n.classList && n.classList.contains("modal")) {
            refreshIfNeeded(n);
          }
        });
      }
    });
  });
  mo.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ["class"] });

  // สำหรับ modal ที่แสดงอยู่แล้วหลังโหลด
  document.querySelectorAll(".modal.show").forEach(refreshIfNeeded);
})();
