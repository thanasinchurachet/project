
/**
 * Patch: Force TikTok to use official iframe v2 everywhere.
 * Drop this AFTER the original waste-management.js
 */
(function(){
  function getTikTokIdFromAny(input){
    const s = String(input || '');
    const m1 = s.match(/data-video-id="(\d+)"/i); if (m1) return m1[1];
    const m2 = s.match(/\/video\/(\d+)/);         if (m2) return m2[1];
    try { const u = new URL(s); const m3 = u.pathname.match(/\/video\/(\d+)/); if (m3) return m3[1]; } catch(_){}
    return '';
  }

  // Upgrade global buildEmbed if present
  if (window.buildEmbed) {
    const _oldBuild = window.buildEmbed;
    window.buildEmbed = function(platform, url){
      if (platform === 'tiktok') {
        const id = getTikTokIdFromAny(url);
        return id ? `https://www.tiktok.com/embed/v2/video/${id}` : null;
      }
      return _oldBuild.apply(this, arguments);
    };
  }

  // Override class instance method getEmbedUrl for TikTok only
  if (window.WasteManagement && window.WasteManagement.prototype) {
    const proto = window.WasteManagement.prototype;
    const _origGet = proto.getEmbedUrl;
    if (typeof _origGet === 'function') {
      proto.getEmbedUrl = function(platform, url){
        if (platform === 'tiktok') {
          const id = getTikTokIdFromAny(url);
          return id ? `https://www.tiktok.com/embed/v2/video/${id}` : null;
        }
        return _origGet.call(this, platform, url);
      };
    }
  }

  // Expose helper globally for other scripts if needed
  window._getTikTokIdFromAny = getTikTokIdFromAny;
})();
