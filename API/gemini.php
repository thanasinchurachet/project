<?php
session_start();
header('Content-Type: application/json');

// The key comes from .env (GEMINI_API_KEY) — see config.php / .env.example
require_once dirname(__DIR__) . '/config.php';
$apiKey = env('GEMINI_API_KEY', env('GOOGLE_API_KEY', ''));

if (empty($apiKey)) {
  echo json_encode(['error' => 'API Key ไม่ถูกต้องหรือว่างเปล่า']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
  echo json_encode(['error' => 'ข้อมูล JSON ไม่ถูกต้อง: ' . json_last_error_msg()]); exit;
}

$userId       = $_SESSION['userid'] ?? '';
$message      = $input['message'] ?? '';
$imageB64     = $input['image']   ?? '';
$mimeType     = $input['mimeType'] ?? 'image/jpeg'; // ✅ รับชนิดไฟล์มาจาก client
$responseMime = $input['response_mime'] ?? null;     // บังคับ MIME ตอบกลับ (เช่น application/json)
$preferModel  = isset($input['model']) ? trim((string)$input['model']) : (isset($input['prefer_model']) ? trim((string)$input['prefer_model']) : '');

// อนุญาตให้เรียกภายในจาก router โดยไม่ต้องล็อกอิน (ต้องมี internal token ตรงกัน)
$internalKey = getenv('GEMINI_INTERNAL_KEY') ?: 'local-router';
$isInternal  = isset($input['internal_token']) && hash_equals($internalKey, (string)$input['internal_token']);

if (!$userId && !$isInternal) { echo json_encode(['error' => 'กรุณาล็อกอินก่อนใช้งาน']); exit; }
if ($isInternal && !$userId) { $userId = 'router'; }

// ตรวจภาษาเหมือนเดิม...
function detectLanguage($t){ if(preg_match('/[\p{Thai}]/u',$t))return'th'; if(preg_match('/[\p{Latin}]/u',$t))return'en'; return'en';}
$lang = detectLanguage($message);
$needVision = !empty($imageB64);

// payload เหมือนเดิม ยกเว้นตั้ง mime ตามจริง และรองรับการบังคับ MIME ตอบกลับ
// Choose candidate models based on modality (image vs text)
// Put most-available models first; prefer 2.5-pro (works in your test)
if ($needVision) {
  $models = [
    'gemini-2.5-pro',               // multimodal, stable
    'gemini-2.5-flash',             // fast multimodal
    'gemini-1.5-pro',               // fallback multimodal
    'gemini-1.5-flash',             // fallback fast
    'gemini-pro-vision',            // broad compatibility for vision
    'gemini-1.0-pro-vision',        // older alias
  ];
} else {
  $models = [
    'gemini-2.5-pro',               // preferred first
    'gemini-1.5-pro',               // fallback
    'gemini-1.5-flash',             // fast
    'gemini-pro',                   // common text model
  ];
}
// Restrict to v1beta which consistently supports generateContent for these names
$versions = ['v1beta'];
$urls = [];
// Allow environment to set preferred models (global override)
$envPreferred = getenv('GEMINI_PREFERRED_MODEL') ?: '';
$envPreferredVision = getenv('GEMINI_PREFERRED_MODEL_VISION') ?: '';
if ($needVision && $envPreferredVision !== '') { $preferModel = $envPreferredVision; }
elseif (!$needVision && $envPreferred !== '') { $preferModel = $envPreferred; }

// If client asked for a specific model, move it to the front
if ($preferModel !== '') {
  array_unshift($models, $preferModel);
  // de-duplicate while keeping order
  $seen = [];
  $models = array_values(array_filter($models, function($m) use (&$seen){ if(isset($seen[$m])) return false; $seen[$m]=1; return true; }));
}
foreach ($models as $m) {
  foreach ($versions as $ver) {
  $urls[] = "https://generativelanguage.googleapis.com/$ver/models/$m:generateContent?key=$apiKey";
  }
}
// Start with the first URL by default
$url = $urls[0];
// Build payload using camelCase keys (works for v1beta and v1 of Generative Language API)

// === Retry/Backoff สำหรับ 429/5xx ===
// Keep retries low to avoid long waits in UI
$maxRetries = 2;
$delayMs = 250;
$retryOn = [429,500,502,503,504];

// Rotate through URL variants on 404; no single-switch limitation

// Flags to allow graceful degradation if model rejects certain fields
$dropSystemInstruction = false;
$dropGenerationConfig  = false;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
  $sysText = ($responseMime === 'application/json')
    ? "You are BinBuddy AI. Respond with JSON only. Do not include explanations."
    : "You are BinBuddy AI. Answer briefly about waste management in ".($lang==='th'?'Thai':'English').".";

  $payload = [ 'contents' => [[ 'role' => 'user', 'parts' => [] ]] ];
  if (!$dropSystemInstruction) {
    $payload['systemInstruction'] = ['parts' => [[ 'text' => $sysText ]]];
  }
  if ($responseMime && !$dropGenerationConfig) {
    $payload['generationConfig'] = [ 'responseMimeType' => $responseMime ];
  }
  if ($message) { $payload['contents'][0]['parts'][] = ['text' => $message]; }
  if ($imageB64) {
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $imageB64)) { echo json_encode(['error' => 'ข้อมูลภาพไม่ถูกต้อง']); exit; }
    $payload['contents'][0]['parts'][] = [ 'inlineData' => [ 'mimeType' => $mimeType, 'data' => $imageB64 ] ];
  }
  if (empty($payload['contents'][0]['parts'])) { echo json_encode(['error' => 'ไม่มีข้อมูลข้อความหรือภาพที่ส่งมา']); exit; }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'Accept: application/json',
      'Connection: keep-alive'
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 18,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FAILONERROR    => false
  ]);

  $response  = curl_exec($ch);
  $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($curlError) {
    error_log("[Gemini] CURL error: $curlError (attempt $attempt/$maxRetries)");
  }

  if ($httpCode === 200 && $response) {
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      error_log("[Gemini] JSON decode error: ".json_last_error_msg());
      // ให้ลองใหม่รอบถัดไป
    } else {
      $reply = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
      if ($reply === '' && isset($result['candidates'][0]['content']['parts'][0]['inline_data'])) {
        // unlikely, but keep fallback
        $reply = '';
      }
      // passthrough reply (could be JSON text if forced)
      echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } elseif ($httpCode === 404) {
    // Rotate to next URL variant (model/version). If we cycle through all, stop.
    error_log("[Gemini] HTTP 404 response: ".substr((string)$response,0,500));
    $currentIndex = array_search($url, $urls, true);
    if ($currentIndex === false) { $currentIndex = 0; }
    $nextIndex = ($currentIndex + 1) % count($urls);
    if ($nextIndex === 0) {
      error_log("[Gemini] Exhausted all model endpoints with 404.");
      echo json_encode(['error' => 'คำขอไม่ถูกต้องหรือไม่ได้รับอนุญาต (HTTP 404)']);
      exit;
    }
    $url = $urls[$nextIndex];
      // quick rotate without heavy backoff to keep UI responsive
      usleep(150 * 1000);
      continue;
    } elseif ($httpCode === 400) {
      // Inspect error to decide a compatible fallback
      $msg = '';
      $errObj = json_decode((string)$response, true);
      if (is_array($errObj)) {
        $msg = (string)($errObj['error']['message'] ?? '');
      }
      $lmsg = strtolower($msg);
      // If model complains about systemInstruction, drop it and retry
      if (!$dropSystemInstruction && ($lmsg && (strpos($lmsg,'systeminstruction')!==false || strpos($lmsg,'system_instruction')!==false))) {
        $dropSystemInstruction = true;
        error_log('[Gemini] Dropping systemInstruction due to 400: '.substr($msg,0,200));
        usleep($delayMs * 1000);
        $delayMs *= 2;
        continue;
      }
      // If model complains about responseMimeType/generationConfig, drop it and retry
      if (!$dropGenerationConfig && ($lmsg && (strpos($lmsg,'responsemimetype')!==false || strpos($lmsg,'response_mime_type')!==false || strpos($lmsg,'generationconfig')!==false || strpos($lmsg,'generation_config')!==false))) {
        $dropGenerationConfig = true;
        error_log('[Gemini] Dropping generationConfig due to 400: '.substr($msg,0,200));
        usleep($delayMs * 1000);
        $delayMs *= 2;
        continue;
      }
      // non-retriable 400
      error_log("[Gemini] HTTP 400 response: ".substr((string)$response,0,500));
      echo json_encode(['error' => 'คำขอไม่ถูกต้องหรือไม่ได้รับอนุญาต (HTTP 400)']);
      exit;
    } elseif (!in_array($httpCode, $retryOn, true)) {
      // ถ้าเป็น 4xx (ยกเว้น 429) ไม่ต้อง retry
      error_log("[Gemini] HTTP $httpCode response: ".substr((string)$response,0,500));
      echo json_encode(['error' => 'คำขอไม่ถูกต้องหรือไม่ได้รับอนุญาต (HTTP '.$httpCode.')']);
      exit;
    }

  // รอแล้วลองใหม่ (exponential backoff)
  usleep($delayMs * 1000);
  $delayMs *= 2;
}

// หมดสิทธิ์แล้ว — แจ้งข้อความที่เป็นมิตร ไม่โชว์เลขสถานะดิบ
echo json_encode(['error' => 'ระบบ AI ไม่เสถียรชั่วคราว กรุณาลองใหม่อีกครั้งใน 30–60 วินาที']);
