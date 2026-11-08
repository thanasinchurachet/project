<?php
// Reusable SweetAlert2 integration and alert helpers
// Include this file once per page (ideally before </body>)
// Optional auto-message via query: ?status=success&message=...

// Capture optional status/message from query string
$__alert_status = $_GET['status'] ?? null;
$__alert_message = $_GET['message'] ?? null;
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var nativeAlert = window.alert ? window.alert.bind(window) : function(msg){ console.log('ALERT:', msg); };

  function iconFor(text) {
    try {
      if (/error|ผิดพลาด|ล้มเหลว/i.test(text)) return 'error';
      if (/success|สำเร็จ|เรียบร้อย|ทำเสร็จ/i.test(text)) return 'success';
      if (/warn|เตือน|ระวัง/i.test(text)) return 'warning';
      return 'info';
    } catch (e) { return 'info'; }
  }

  function showModal(text, icon) {
    if (window.Swal && Swal.fire) {
      Swal.fire({
        text: String(text),
        icon: icon || iconFor(text),
        confirmButtonText: 'ตกลง'
      });
    } else {
      nativeAlert(String(text));
    }
  }

  function showToast(text, icon) {
    if (window.Swal && Swal.fire) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 2500,
        timerProgressBar: true,
        icon: icon || iconFor(text),
        title: String(text)
      });
    } else {
      nativeAlert(String(text));
    }
  }

  // Override default alert to pretty modal
  window.alert = function (msg) {
    var text = (typeof msg === 'string') ? msg : (msg && msg.message) || String(msg);
    showModal(text);
  };

  // Convenience helpers for explicit usage
  window.showSuccess = function (text) { showToast(text, 'success'); };
  window.showError   = function (text) { showToast(text, 'error'); };
  window.showWarning = function (text) { showToast(text, 'warning'); };
  window.showInfo    = function (text) { showToast(text, 'info'); };

  // Optional: auto show message from query string (?status=success&message=...)
  <?php if ($__alert_message !== null) { ?>
  try {
    var __msg = <?php echo json_encode($__alert_message, JSON_UNESCAPED_UNICODE); ?>;
    var __status = (<?php echo json_encode($__alert_status ?? ''); ?> || '').toLowerCase();
    var __icon = ['success','error','warning','info','question'].includes(__status) ? __status : undefined;
    // Prefer toast for success, modal for others
    if (__icon === 'success') {
      showToast(__msg, __icon);
    } else {
      showModal(__msg, __icon);
    }
  } catch (e) {}
  <?php } ?>
})();
</script>

