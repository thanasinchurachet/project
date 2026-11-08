<?php
// api_client.php
// Helper เรียก FastAPI ให้สั้น/เสถียร และหลีกเลี่ยง 500 จากการตั้ง header multipart เอง

require_once __DIR__ . '/config.php';

function api_base_url() {
    // FastAPI address — set API_BASE_URL in .env if it is not 127.0.0.1:8000
    return env('API_BASE_URL', 'http://127.0.0.1:8000');
}

/**
 * อัปโหลดรูปไป predict แล้วคืน (predictions, error)
 * @param array $fileArray  = $_FILES['image']
 * @param int   $top_k      = จำนวนอันดับที่ต้องการ (default 3)
 * @param float $unsure_th  = เกณฑ์ความมั่นใจขั้นต่ำของอันดับ 1 เพื่อตีความว่า “ไม่แน่ใจ”
 * @param float $min_gap    = เกณฑ์ระยะห่างระหว่างอันดับ 1 กับ 2 (ถ้าห่างน้อยกว่า => ไม่แน่ใจ)
 * @return array [$predictions|null, $error|null]
 */
function api_predict_from_upload($fileArray, $top_k = 3, $unsure_th = 0.65, $min_gap = 0.15) {
    if (!function_exists('curl_init')) {
        return [null, 'PHP cURL extension is not enabled. กรุณาเปิด php_curl ใน php.ini แล้วรีสตาร์ท Apache'];
    }
    if (!isset($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        return [null, "ไม่พบไฟล์ภาพสำหรับอัปโหลด"];
    }

    $url = rtrim(api_base_url(), "/") . "/predict/";
    $ch  = curl_init();

    // multipart form-data: ห้ามตั้ง Content-Type เอง ให้ cURL ใส่ boundary ให้
    $post = [
        'image'     => new CURLFile($fileArray['tmp_name'], $fileArray['type'] ?? 'application/octet-stream', $fileArray['name'] ?? 'image.jpg'),
        // ถ้าจะส่ง lat/lng เพิ่มเติมจาก PHP ก็ใส่ได้ เช่น:
        // 'lat' => $lat, 'lng' => $lng,
        'threshold' => $unsure_th, // ส่งไปให้ API ใช้เป็นโหมด “ไม่แน่ใจ”
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [null, "ติดต่อ API ไม่สำเร็จ: $curl_err"];
    }
    if ($http_code !== 200) {
        return [null, "API ตอบกลับสถานะ $http_code: " . substr($response, 0, 500)];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return [null, "ผลลัพธ์จาก API ไม่ใช่ JSON: " . substr($response, 0, 200)];
    }

    // ดึง predictions (API จะจัดอันดับมาแล้ว แต่กันเหนียวเรียงอีกครั้ง)
    $preds = $data['predictions'] ?? [];
    usort($preds, function($a, $b) {
        return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
    });
    $preds = array_slice($preds, 0, max(1, (int)$top_k));

    // เติม category ให้ (เผื่อ API ไม่ส่งมา)
    foreach ($preds as &$p) {
        if (empty($p['category']) && !empty($p['waste_type'])) {
            $p['category'] = map_category($p['waste_type']);
        }
    }
    unset($p);

    // โหมด “ไม่แน่ใจ” ฝั่ง client (ใช้เป็นข้อมูลประกอบ UI ถ้าต้องการ)
    $unsure_client = false;
    if (count($preds) >= 1) {
        $top1 = $preds[0]['confidence'] ?? 0.0;
        $top2 = $preds[1]['confidence'] ?? 0.0;
        if ($top1 < (float)$unsure_th || ($top1 - $top2) < (float)$min_gap) {
            $unsure_client = true;
        }
    }

    // ถ้าต้องใช้ธงนี้ในหน้า JS ก็แนบไปได้ (ตอนนี้ scan.php ไม่ได้อ่านค่านี้ แต่แนบไว้ไม่เสียหาย)
    // $preds_meta = ['unsure' => $unsure_client, 'threshold' => $unsure_th, 'min_gap' => $min_gap];

    return [$preds, null];
}

function map_category($waste_name) {
    // จับคู่หมวดหมู่แบบเดียวกับฝั่ง API
    $recycle = ["กระดาษ", "กระดาษลัง", "ขวดแก้วใส", "ขวดพลาสติก", "โลหะกระป๋อง", "พลาสติก"];
    $hazard  = ["แบตเตอรี่", "หลอดไฟ"];
    $notwaste= ["ไม่ใช่ขยะ"];
    if (in_array($waste_name, $hazard, true))   return "ขยะอันตราย";
    if (in_array($waste_name, $recycle, true))  return "ขยะรีไซเคิล";
    if (in_array($waste_name, $notwaste, true)) return "ไม่ใช่ขยะ";
    return "ขยะทั่วไป";
}
