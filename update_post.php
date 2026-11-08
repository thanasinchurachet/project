<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'video_utils.php';

try {
  // Require login
  if (!isset($_SESSION['userid'])) {
    throw new Exception('กรุณาเข้าสู่ระบบ');
  }

  // Inputs
  $postId = (int)($_POST['post_id'] ?? ($_POST['postId'] ?? 0));
  $title  = trim($_POST['title']  ?? '');
  $detail = trim($_POST['detail'] ?? '');
  $removedImages = json_decode($_POST['removed_images'] ?? '[]', true);
  if (!is_array($removedImages)) { $removedImages = []; }

  if ($postId <= 0)  throw new Exception('โพสต์ไม่ถูกต้อง');
  if ($title  === '') throw new Exception('กรุณากรอกหัวข้อ');

  // Fetch post + verify owner
  $stmt = $pdo->prepare("SELECT userID AS user_id, image, video_source, video_url, video_platform, video_path, video_mime, video_size FROM post WHERE postID = ?");
  $stmt->execute([$postId]);
  $post = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$post) throw new Exception('ไม่พบโพสต์');
  if ((int)$post['user_id'] !== (int)$_SESSION['userid']) {
    throw new Exception('คุณไม่มีสิทธิ์แก้ไขโพสต์นี้');
  }

  // Existing images
  $currentImages = [];
  if (!empty($post['image'])) {
    $tmp = json_decode($post['image'], true);
    if (is_array($tmp)) $currentImages = $tmp;
  }

  // Prepare upload dir
  $uploadDir = __DIR__ . '/img/post_img/';
  if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
      throw new Exception('ไม่สามารถสร้างโฟลเดอร์สำหรับอัปโหลดได้');
    }
  }

  // Filter remaining images and delete removed files
  $remainImages = array_values(array_filter(
    $currentImages,
    fn($n) => !in_array($n, $removedImages, true)
  ));
  foreach ($removedImages as $fn) {
    $path = $uploadDir . basename($fn);
    if (is_file($path)) { @unlink($path); }
  }

  // Collect new uploads (image1..image3)
  $fields  = ['image1','image2','image3'];
  $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
  $newImages = [];

  foreach ($fields as $f) {
    if (!isset($_FILES[$f])) continue;
    $err = (int)($_FILES[$f]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) continue;
    if ($err !== UPLOAD_ERR_OK) {
      throw new Exception('อัปโหลดไฟล์ผิดพลาด (code ' . $err . ')');
    }

    $size = (int)($_FILES[$f]['size'] ?? 0);
    if ($size <= 0) continue;

    $tmp  = $_FILES[$f]['tmp_name'];
    $name = $_FILES[$f]['name'] ?? '';
    $mime = @mime_content_type($tmp) ?: ($_FILES[$f]['type'] ?? '');

    $mimeL = strtolower($mime);
    if (!in_array($mimeL, $allowed, true) && $mimeL !== 'application/octet-stream') {
      throw new Exception('รองรับเฉพาะ JPG, PNG, GIF, WEBP');
    }
    if ($size > 5*1024*1024) {
      throw new Exception('ขนาดไฟล์ต้องไม่เกิน 5MB ต่อรูป');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '') {
      if (str_contains($mimeL, 'png')) $ext = 'png';
      elseif (str_contains($mimeL, 'webp')) $ext = 'webp';
      elseif (str_contains($mimeL, 'gif')) $ext = 'gif';
      else $ext = 'jpg';
    }
    $safe = bin2hex(random_bytes(8)) . '.' . $ext;

    if (!move_uploaded_file($tmp, $uploadDir . $safe)) {
      throw new Exception('อัปโหลดรูปภาพไม่สำเร็จ');
    }
    $newImages[] = $safe;
  }

  // Merge remain + new (max 3)
  $finalImages = array_slice(array_values(array_unique(array_merge($remainImages, $newImages))), 0, 3);

  // Handle video update
  $videoMode = $_POST['video_mode'] ?? 'keep';
  $videoMode = in_array($videoMode, ['keep','none','url','file'], true) ? $videoMode : 'keep';

  $currVideoSource = $post['video_source'] ?? 'none';
  $currVideoPath = $post['video_path'] ?? null;

  $video_source = $currVideoSource;
  $video_url = $post['video_url'] ?? null;
  $video_platform = $post['video_platform'] ?? null;
  $video_path = $currVideoPath;
  $video_mime = $post['video_mime'] ?? null;
  $video_size = $post['video_size'] ?? null;

  $videoDir = __DIR__ . '/uploads/videos/';
  if (!is_dir($videoDir)) {
    @mkdir($videoDir, 0775, true);
  }

  $deleteOldVideoFile = function($path) use ($videoDir) {
    if ($path) {
      $file = $videoDir . basename($path);
      if (is_file($file)) { @unlink($file); }
    }
  };

  if ($videoMode === 'none') {
    if ($currVideoSource === 'file') {
      $deleteOldVideoFile($currVideoPath);
    }
    $video_source = 'none';
    $video_url = $video_platform = $video_path = $video_mime = null;
    $video_size = null;
  } elseif ($videoMode === 'url') {
    $rawUrl = trim($_POST['video_url'] ?? '');
    if ($rawUrl === '') {
      throw new Exception('กรุณาระบุลิงก์วิดีโอ');
    }
    $platform = detect_video_platform($rawUrl);
    if (!$platform) {
      throw new Exception('ลิงก์วิดีโอไม่รองรับ');
    }
    if ($currVideoSource === 'file') {
      $deleteOldVideoFile($currVideoPath);
    }
    $video_source = 'url';
    $video_url = $rawUrl;
    $video_platform = $platform;
    $video_path = $video_mime = null;
    $video_size = null;
  } elseif ($videoMode === 'file') {
    if (!isset($_FILES['video_file'])) {
      throw new Exception('กรุณาเลือกไฟล์วิดีโอ');
    }
    $vf = $_FILES['video_file'];
    if ((int)$vf['error'] !== UPLOAD_ERR_OK) {
      throw new Exception('อัปโหลดไฟล์วิดีโอผิดพลาด (code ' . $vf['error'] . ')');
    }
    $maxSize = 40 * 1024 * 1024; // align with server default unless php.ini increased
    if ((int)$vf['size'] > $maxSize) {
      throw new Exception('ขนาดไฟล์วิดีโอต้องไม่เกิน 50MB');
    }
    $tmp = $vf['tmp_name'];
    $mime = @mime_content_type($tmp) ?: ($vf['type'] ?? '');
    $allowed = ['video/mp4','video/webm','video/ogg'];
    $ext = strtolower(pathinfo($vf['name'] ?? 'mp4', PATHINFO_EXTENSION));
    if (!in_array(strtolower($mime), $allowed, true)) {
      if (!in_array($ext, ['mp4','webm','ogg'], true)) {
        throw new Exception('รองรับเฉพาะ MP4, WebM, Ogg');
      }
    }
    if ($ext === '') {
      $ext = str_contains($mime, 'webm') ? 'webm' : (str_contains($mime, 'ogg') ? 'ogg' : 'mp4');
    }
    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $videoDir . $safe)) {
      throw new Exception('อัปโหลดวิดีโอไม่สำเร็จ');
    }
    if ($currVideoSource === 'file') {
      $deleteOldVideoFile($currVideoPath);
    }
    $video_source = 'file';
    $video_url = null;
    $video_platform = null;
    $video_path = $safe;
    $video_mime = $mime;
    $video_size = (int)$vf['size'];
  }

  // Update
  $stmt = $pdo->prepare(
    "UPDATE post
        SET title = :title,
            details = :details,
            image = :image,
            video_source = :video_source,
            video_url = :video_url,
            video_platform = :video_platform,
            video_path = :video_path,
            video_mime = :video_mime,
            video_size = :video_size,
            status = 'pending',
            date_modified = NOW()
      WHERE postID = :pid AND userId = :uid"
  );
  $ok = $stmt->execute([
    ':title' => $title,
    ':details' => $detail,
    ':image' => json_encode($finalImages, JSON_UNESCAPED_UNICODE),
    ':video_source' => $video_source,
    ':video_url' => $video_url,
    ':video_platform' => $video_platform,
    ':video_path' => $video_path,
    ':video_mime' => $video_mime,
    ':video_size' => $video_size,
    ':pid' => $postId,
    ':uid' => $_SESSION['userid'],
  ]);
  if (!$ok) throw new Exception('อัปเดตไม่สำเร็จ');

  echo json_encode([
    'success' => true,
    'images' => $finalImages,
    'video' => [
      'source' => $video_source,
      'url' => $video_url,
      'platform' => $video_platform,
      'path' => $video_path,
      'mime' => $video_mime,
      'size' => $video_size,
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
