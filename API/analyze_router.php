<?php
// API/analyze_router.php
// Router endpoint that analyzes an uploaded image via either the local system model
// or Gemini, and normalizes the response to the same JSON structure used by the system.

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api_client.php';

function respond_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond_error('Invalid method', 405);
    }

    $model = $_POST['model'] ?? 'system';
    $model = in_array($model, ['system','gemini'], true) ? $model : 'system';

    if (!isset($_FILES['image'])) {
        respond_error('Missing file: image');
    }

    if ($model === 'system') {
        list($predictions, $err) = api_predict_from_upload($_FILES['image']);
        if ($err) respond_error($err, 502);
        echo json_encode(['success' => true, 'predictions' => $predictions], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Gemini path ---
    $file = $_FILES['image'];
    if (!is_uploaded_file($file['tmp_name'])) {
        respond_error('Invalid uploaded file');
    }
    $mime = $file['type'] ?: 'image/jpeg';
    $bin  = file_get_contents($file['tmp_name']);
    if ($bin === false) respond_error('Cannot read uploaded file');
    $b64  = base64_encode($bin);
    

    // Build a strict prompt to mimic the system JSON
    $lang = 'th';
    $prompt = (
        "คุณคือ BinBuddy AI ช่วยจัดจำแนกประเภทขยะจากรูปภาพและตอบกลับเป็น JSON ภาษาไทยเท่านั้น ไม่ต้องมีคำอธิบายอื่นๆ นอกเหนือจาก JSON\n" .
        "สคีมาของ JSON ต้องเป็นดังนี้:\n" .
        "{\n  \"predictions\": [\n    {\n      \"waste_type\": \"ชื่อชนิดขยะ (เช่น ขวดพลาสติก, กระดาษ)\",\n      \"category\": \"ขยะทั่วไป|ขยะรีไซเคิล|ขยะอันตราย|ไม่ใช่ขยะ\",\n      \"confidence\": 0.xx (ตัวเลข 0 ถึง 1),\n      \"management\": [\"คำแนะนำการจัดการสั้นๆ แบบเป็นขั้นตอน\"]\n    }\n  ]\n}\n" .
        "ข้อกำหนด:\n- ตอบกลับเป็น JSON เท่านั้น (ห้ามมีข้อความอื่น)\n- ให้คืน predictions 3 รายการเสมอ โดยเรียงจากที่มั่นใจมากไปน้อย\n- ถ้าไม่แน่ใจ ให้ตั้ง confidence ต่ำ แต่ต้องคงรูปแบบ 3 รายการ\n"
    );

    $url  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/gemini.php';
    $full = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $url;

    // Basic .env reader for local setups
    $envVal = function(string $key): ?string {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        $envFile = __DIR__ . '/../backend/.env';
        if (is_readable($envFile)) {
            $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $ln = trim($ln);
                    if ($ln === '' || $ln[0] === '#') continue;
                    $pos = strpos($ln, '=');
                    if ($pos === false) continue;
                    $k = trim(substr($ln, 0, $pos));
                    $val = trim(substr($ln, $pos+1));
                    if (strcasecmp($k, $key) === 0) return $val;
                }
            }
        }
        return null;
    };
    $fallbackFlag = strtolower((string)($envVal('GEMINI_FALLBACK') ?? ''));
    $allowFallback = in_array($fallbackFlag, ['1','true','yes','system'], true);

    $internal_key = getenv('GEMINI_INTERNAL_KEY') ?: 'local-router';
    $payload = [
        'message'        => $prompt,
        'image'          => $b64,
        'mimeType'       => $mime,
        'response_mime'  => 'application/json', // ขอให้ Gemini ตอบกลับเป็น JSON โดยตรง
        // Hint preferred model for compatibility (2.5-pro works per your test)
        'prefer_model'   => 'gemini-2.5-pro',
        'internal_token' => $internal_key,
    ];

    // Call local Gemini proxy
    $ch = curl_init($full);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        // Keep timeout modest so UI doesn't hang if Gemini is unavailable
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // If Gemini proxy fails, return friendly error for UI to alert
    if ($resp === false || $http !== 200) {
        if ($allowFallback) {
            list($predictions, $fallbackErr) = api_predict_from_upload($_FILES['image']);
            if (!$fallbackErr && is_array($predictions)) {
                echo json_encode(['success'=>true,'predictions'=>$predictions,'source'=>'system-fallback'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        respond_error('ขณะนี้การวิเคราะห์ภาพด้วย Gemini ไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง', 503);
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        if ($allowFallback) {
            list($predictions, $fallbackErr) = api_predict_from_upload($_FILES['image']);
            if (!$fallbackErr && is_array($predictions)) {
                echo json_encode(['success'=>true,'predictions'=>$predictions,'source'=>'system-fallback'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        respond_error('ขณะนี้การวิเคราะห์ภาพด้วย Gemini ไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง', 503);
    }
    if (isset($data['error']) && $data['error']) {
        if ($allowFallback) {
            list($predictions, $fallbackErr) = api_predict_from_upload($_FILES['image']);
            if (!$fallbackErr && is_array($predictions)) {
                echo json_encode(['success'=>true,'predictions'=>$predictions,'source'=>'system-fallback'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        respond_error('ขณะนี้การวิเคราะห์ภาพด้วย Gemini ไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง', 503);
    }
    $reply = $data['reply'] ?? '';

    // Gemini reply should be JSON per our prompt. Try to parse.
    $reply = trim($reply);
    // In case it returns in code fences
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $reply, $m)) {
        $reply = $m[1];
    }
    $norm = json_decode($reply, true);
    if (!is_array($norm) || !isset($norm['predictions'])) {
        // Try to rescue with lenient array brackets
        if (preg_match('/\{\s*"predictions"\s*:\s*\[[\s\S]*?\]\s*\}/', $reply, $mm)) {
            $norm = json_decode($mm[0], true);
        }
    }
    if (!is_array($norm) || !isset($norm['predictions'])) {
        // Treat as Gemini error to let frontend alert the user
        respond_error('Gemini returned invalid or non-JSON response', 502);
    }

    // Normalize items and ensure categories, confidences
    $preds = [];
    foreach (($norm['predictions'] ?? []) as $p) {
        $wt = trim((string)($p['waste_type'] ?? ''));
        if ($wt === '') continue;
        $conf = (float)($p['confidence'] ?? 0);
        $cat  = (string)($p['category'] ?? '');
        if ($cat === '' && function_exists('map_category')) { $cat = map_category($wt); }
        $preds[] = [
            'waste_type' => $wt,
            'category'   => $cat ?: 'ขยะทั่วไป',
            'confidence' => $conf,
            'management' => is_array($p['management'] ?? null) ? $p['management'] : [],
        ];
    }
    usort($preds, function($a,$b){ return ($b['confidence']??0) <=> ($a['confidence']??0); });

    // If less than 3 items, complement with system model top-k (ของจริงจากโมเดลระบบ)
    if (count($preds) < 3 && isset($_FILES['image'])) {
        list($sys, $err) = api_predict_from_upload($_FILES['image'], 5, 0.65, 0.15);
        if (!$err && is_array($sys)) {
            $have = [];
            foreach ($preds as $pp) { $have[$pp['waste_type']] = true; }
            foreach ($sys as $sp) {
                $name = (string)($sp['waste_type'] ?? '');
                if ($name === '' || isset($have[$name])) continue;
                $preds[] = [
                    'waste_type' => $name,
                    'category'   => (string)($sp['category'] ?? 'ขยะทั่วไป'),
                    'confidence' => (float)($sp['confidence'] ?? 0),
                    'management' => [],
                ];
                if (count($preds) >= 3) break;
            }
        }
    }
    $preds = array_slice($preds, 0, 3);

    echo json_encode(['success' => true, 'predictions' => $preds], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    respond_error('Server error: ' . $e->getMessage(), 500);
}
?>
