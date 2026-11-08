<?php
require_once __DIR__.'/api_client.php';
session_start();

// ตั้งค่าโซนเวลา
date_default_timezone_set('Asia/Bangkok');
// เชื่อมต่อฐานข้อมูล
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "waste_system";

// สร้างการเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);

// Helper: fetch rows from prepared statements without requiring mysqlnd (get_result)
if (!function_exists('stmt_all_assoc')) {
    function stmt_all_assoc(mysqli_stmt $stmt): array {
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                $rows = [];
                while ($row = $res->fetch_assoc()) { $rows[] = $row; }
                return $rows;
            }
            return [];
        }
        $rows = [];
        if (!$stmt->store_result()) { return []; }
        $meta = $stmt->result_metadata();
        if (!$meta) { return []; }
        $fields = $meta->fetch_fields();
        $row = [];
        $bindArgs = [];
        foreach ($fields as $f) {
            $row[$f->name] = null;
            $bindArgs[] = &$row[$f->name]; // pass by reference
        }
        call_user_func_array([$stmt, 'bind_result'], $bindArgs);
        while ($stmt->fetch()) {
            // detach references by creating a copy
            $rows[] = array_map(function($v){ return $v; }, $row);
        }
        return $rows;
    }
}

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        // ตอบกลับ 200 เพื่อไม่ให้หน้าเว็บโชว์ 500 ใน Console แต่ส่ง success:false
        echo json_encode([
            'success' => false,
            'detail'  => 'Database connection error',
            'error'   => $conn->connect_error,
        ]);
        exit;
    }
}

// Lightweight helper to fetch management contents and posts by waste name (no auto-create)
if (!function_exists('fetch_content_for_waste')) {
    function fetch_content_for_waste(mysqli $conn, string $waste_name): array {
        $waste_name = trim($waste_name);
        $trash_content = [];
        $recent_posts = [];

        if ($waste_name === '') {
            return ['trash_content' => [], 'recent_posts' => []];
        }

        $trashId = null;
        $stmt = $conn->prepare("SELECT trashId FROM trash WHERE name = ?");
        if ($stmt) {
            $stmt->bind_param("s", $waste_name);
            if ($stmt->execute()) {
                $rows = stmt_all_assoc($stmt);
                if ($rows && count($rows) > 0) {
                    $trashId = (int)($rows[0]['trashId'] ?? 0);
                }
            }
        }

        if (!empty($trashId)) {
            // Management contents
            $stmtc = $conn->prepare("SELECT * FROM trash_content WHERE trashId = ?");
            if ($stmtc) {
                $stmtc->bind_param("i", $trashId);
                if ($stmtc->execute()) {
                    $rows = stmt_all_assoc($stmtc);
                    foreach ($rows as $row) { $trash_content[] = $row; }
                }
            }
            // Recent posts
            $stmtp = $conn->prepare("SELECT p.*, u.name, u.profile_img 
                                     FROM post p 
                                     JOIN users u ON p.userId = u.userID 
                                     WHERE p.trashId = ? AND p.status = 'approved'
                                     ORDER BY p.date DESC 
                                     LIMIT 3");
            if ($stmtp) {
                $stmtp->bind_param("i", $trashId);
                if ($stmtp->execute()) {
                    $rows = stmt_all_assoc($stmtp);
                    foreach ($rows as $row) { $recent_posts[] = $row; }
                }
            }
        }

        return [
            'trash_content' => $trash_content,
            'recent_posts'  => $recent_posts,
        ];
    }
}

// Ajax: GET ?action=content&waste=<name> returns management/posts for a specific waste type
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['action']) && $_GET['action'] === 'content') {
    $name = trim($_GET['waste'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if ($name === '') {
        echo json_encode(['success' => false, 'detail' => 'missing waste name']);
        exit;
    }
    $data = fetch_content_for_waste($conn, $name);
    echo json_encode([
        'success'       => true,
        'waste_name'    => $name,
        'trash_content' => $data['trash_content'],
        'recent_posts'  => $data['recent_posts'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // ปิดการแสดง error บน output สำหรับ API เพื่อตอบกลับ JSON ล้วน ๆ
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    @error_reporting(E_ALL);
    if (!isset($_FILES["image"]) || !is_uploaded_file($_FILES['image']['tmp_name'] ?? '')) {
        header('Content-Type: application/json; charset=utf-8');
        // ใช้ 200 + success:false เพื่อไม่ให้ Console แสดง 400
        echo json_encode([
            'success' => false,
            'detail'  => 'ไม่พบไฟล์ภาพในคำขอ'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $file = $_FILES["image"]["tmp_name"];
    $lat = isset($_POST["lat"]) ? floatval($_POST["lat"]) : null;
    $lng = isset($_POST["lng"]) ? floatval($_POST["lng"]) : null;

    // เตรียมข้อมูลสำหรับส่งไป FastAPI
    // >>> ใช้ตัวช่วยคอล FastAPI ผ่าน api_client.php (สั้น/เสถียร/มี timeout)
    $chosen_model = strtolower(trim($_POST['model'] ?? 'system'));
    $preds = null;
    $api_error = null;

    if ($chosen_model === 'gemini') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = '127.0.0.1';
        $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $router = $scheme . '://' . $host . $base . '/API/analyze_router.php';

        if (!function_exists('curl_init')) {
            $api_error = 'PHP cURL extension is not enabled. Please enable php_curl.';
        } else {
            
            $ch = curl_init($router);
            $post = [
                'model' => 'gemini',
                'image' => new CURLFile(
                    $_FILES['image']['tmp_name'],
                    $_FILES['image']['type'] ?? 'application/octet-stream',
                    $_FILES['image']['name'] ?? 'image.jpg'
                ),
            ];
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 60,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($resp === false) {
                error_log('[scan] Gemini router cURL error: ' . $cerr);
                $api_error = 'ขณะนี้บริการวิเคราะห์ภาพด้วย Gemini ไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง';
            } elseif ($http !== 200) {
                error_log('[scan] Gemini router HTTP ' . $http . ': ' . substr((string)$resp, 0, 200));
                $api_error = 'ขณะนี้บริการวิเคราะห์ภาพด้วย Gemini ไม่พร้อมใช้งาน กรุณาลองใหม่ภายหลัง';
            } else {
                $data = json_decode($resp, true);
                if (is_array($data) && ($data['success'] ?? false)) {
                    $preds = $data['predictions'] ?? [];
                } else {
                    $api_error = $data['error'] ?? 'Invalid response from Gemini router';
                }
            }
        }
    } else {
        list($preds, $api_error) = api_predict_from_upload($_FILES['image'], 3, 0.65, 0.15);
    }

    // รูปแบบผลลัพธ์ให้เหมือนเดิม (success + predictions + detail ถ้ามี)
    $result = [
        'success'      => $api_error === null,
        'predictions'  => $preds ?? [],
        'detail'       => $api_error,
    ];
    
    // ดึงข้อมูลจากฐานข้อมูลเมื่อมีการสแกน
    $trash_content = [];
    $recent_posts = [];
    $predicted_waste_name = null;
    
    if ($result['success'] && !empty($result['predictions'])) {
        // ใช้ชื่อขยะจากอันดับแรก (สูงสุด)
        $predicted_waste_name = $result['predictions'][0]['waste_type'] ?? null;
        
        if ($predicted_waste_name) {
            // ดึง trashId จากชื่อขยะ
            $trash_rows = [];
            $trash_result = null; // keep legacy variable falsey to avoid warnings in old branch
            $trash_query = "SELECT trashId FROM trash WHERE name = ?";
            $stmt = $conn->prepare($trash_query);
            if ($stmt) {
                $stmt->bind_param("s", $predicted_waste_name);
                if ($stmt->execute()) {
                    $trash_rows = stmt_all_assoc($stmt);
                }
            }
            
            // If not found, auto-create it
            $created_new = false;
            $trashId = null;
            
            if (!(is_array($trash_rows) && count($trash_rows) > 0)) {
                // ไม่พบใน database - สร้างใหม่
                if ($predicted_waste_name === "ไม่ใช่ขยะ") {
                    $trashId = null;
                    $created_new = false;
                } else {
                    $pred_category = map_category($predicted_waste_name);
                    $insert_sql = "INSERT INTO trash (name, Waste_type) VALUES (?, ?)";
                    if ($stmt2 = $conn->prepare($insert_sql)) {
                        $stmt2->bind_param("ss", $predicted_waste_name, $pred_category);
                        if ($stmt2->execute()) {
                            $trashId = (int)$conn->insert_id;
                            $created_new = true;
                        }
                    }
                }
            } else {
                // พบใน database แล้ว - ใช้ ID ที่มีอยู่
                $trashId = (int)($trash_rows[0]['trashId'] ?? 0);
            }
            
            // Fetch related content/posts by trashId (both for existing/new)
            if (!empty($trashId)) {
                // content
                $content_query = "SELECT * FROM trash_content WHERE trashId = ?";
                $stmtc = $conn->prepare($content_query);
                if ($stmtc) {
                    $stmtc->bind_param("i", $trashId);
                    if ($stmtc->execute()) {
                        $rows = stmt_all_assoc($stmtc);
                        foreach ($rows as $row) { $trash_content[] = $row; }
                    }
                }

                // recent posts
                $posts_query = "SELECT p.*, u.name, u.profile_img 
                                FROM post p 
                                JOIN users u ON p.userId = u.userID 
                                WHERE p.trashId = ? AND p.status = 'approved'
                                ORDER BY p.date DESC 
                                LIMIT 3";
                $stmtp = $conn->prepare($posts_query);
                if ($stmtp) {
                    $stmtp->bind_param("i", $trashId);
                    if ($stmtp->execute()) {
                        $rows = stmt_all_assoc($stmtp);
                        foreach ($rows as $row) { $recent_posts[] = $row; }
                    }
                }
            }
            
            if (false && $trash_result && $trash_result->num_rows > 0) {
                $trash_row = $trash_result->fetch_assoc();
                $trashId = $trash_row['trashId'];
                
                // ดึงเนื้อหาการจัดการขยะที่เกี่ยวข้อง
                $content_query = "SELECT * FROM trash_content WHERE trashId = ?";
                $stmt = $conn->prepare($content_query);
                if ($stmt) {
                    $stmt->bind_param("i", $trashId);
                    if ($stmt->execute()) {
                        $content_result = $stmt->get_result();
                    } else {
                        $content_result = false;
                    }
                } else {
                    $content_result = false;
                }
                
                if ($content_result && $content_result->num_rows > 0) {
                    while($row = $content_result->fetch_assoc()) {
                        $trash_content[] = $row;
                    }
                }
                
                // ดึงโพสต์ที่เกี่ยวข้อง
                $posts_query = "SELECT p.*, u.name, u.profile_img 
                                FROM post p 
                                JOIN users u ON p.userId = u.userID 
                                WHERE p.trashId = ? AND p.status = 'approved'
                                ORDER BY p.date DESC 
                                LIMIT 3";
                $stmt = $conn->prepare($posts_query);
                if ($stmt) {
                    $stmt->bind_param("i", $trashId);
                    if ($stmt->execute()) {
                        $posts_result = $stmt->get_result();
                    } else {
                        $posts_result = false;
                    }
                } else {
                    $posts_result = false;
                }
                
                if ($posts_result && $posts_result->num_rows > 0) {
                    while($row = $posts_result->fetch_assoc()) {
                        $recent_posts[] = $row;
                    }
                }
            }
        }
    }
    
    // ปิดการเชื่อมต่อฐานข้อมูล
    $conn->close();
    
    // เพิ่มข้อมูลจากฐานข้อมูลลงในผลลัพธ์
    $result['database_content'] = [
        'trash_content' => $trash_content,
        'recent_posts' => $recent_posts,
        'predicted_waste_name' => $predicted_waste_name ?? 'ไม่ระบุ'
    ];
    // Augment with status/note for frontend UX
    $result['database_content']['status'] = (!empty($trash_content) || !empty($recent_posts)) ? 'has_content' : 'no_content';
    $result['database_content']['note'] = (!empty($trash_content) || !empty($recent_posts)) ? null : 'ยังไม่มีข้อมูลในฐานข้อมูลสำหรับรายการนี้';
    
    // ส่งผลลัพธ์เป็น JSON สำหรับ JavaScript
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
} else {
    // หน้า HTML สำหรับอัปโหลด
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สแกนขยะอัจฉริยะ</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/share.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        /* Custom styles from the original file */
    body { font-family: 'Kanit', sans-serif; background-color: #f4f7f6; }
    .upload-container { max-width: 600px; margin: 2rem auto; padding: 2rem; background: #fff; border-radius: 1rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
    #imagePreview { max-width: 100%; height: auto; margin-top: 1.5rem; border-radius: 0.5rem; border: 2px dashed #ddd; display: none; }
    .prediction-item { background: #f8f9fa; padding: 1rem; margin-top: 1rem; border-radius: 0.5rem; text-align: left; border-left: 5px solid; }
    .rank-1 { border-color: #28a745; }
    .rank-2 { border-color: #ffc107; }
    .rank-3 { border-color: #dc3545; }
    #loading { font-size: 1.2rem; }

    /* Modal Styles */
    .modal {
        display: none; position: fixed; z-index: 1050; left: 0; top: 0;
        width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
    }
    .modal.show { display: flex; align-items: center; justify-content: center; }
    .modal-content {
        background-color: #fefefe; margin: auto;  border: 1px solid #888;
        width: 90%; max-width: 800px; border-radius: 0.5rem;
    }
    .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dee2e6; padding-bottom: 1rem; }
    .modal-title { margin-bottom: 0; font-size: 1.5rem; }
    .close {
        color: #aaa; float: right; font-size: 28px; font-weight: bold;
        border: none; background: none; cursor: pointer;
    }
    .close:hover, .close:focus { color: black; text-decoration: none; }
    /* Map UI removed */
    #map { display:none; height: 0; width: 0; }
    #placesList .list-group-item { margin-bottom: 0.5rem; border-radius: 0.5rem; }
</style>

    <style>
        
        * {
            font-family: 'Kanit', sans-serif;
        }
        
        body {
            min-height: 100vh;
        }


        .main-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            max-width: 689px;
            margin: 65px auto;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .header {
            background-color: rgba(16, 185, 129, 1);
            color: white;
            padding: 32px 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { transform: translateX(-100px) translateY(-100px); }
            50% { transform: translateX(100px) translateY(100px); }
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            position: relative;
            z-index: 1;
        }

        .header p {
            margin: 8px 0 0;
            opacity: 0.9;
            font-size: 1rem;
            position: relative;
            z-index: 1;
        }

        .content {
            padding: 32px 24px;
        }

        .upload-zone {
            border: 3px dashed #e0e7ff;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .upload-zone:hover, .upload-zone.dragover {
            border-color: #4CAF50;
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            transform: translateY(-2px);
        }

        .upload-zone.has-file {
            border-color: #4CAF50;
            background: #f0fdf4;
        }

        .upload-icon {
            font-size: 3rem;
            color: #94a3b8;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }

        .upload-zone:hover .upload-icon {
            color: #4CAF50;
            transform: scale(1.1);
        }

        .preview-image {
            max-width: 100%;
            max-height: 200px;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            margin-top: 16px;
            display: none;
            justify-self: center;
        }

        .btn {
            padding: 16px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
           background-color: rgba(16, 185, 129, 1);
            color: white;
            box-shadow: 0 4px 16px rgba(76, 175, 80, 0.3);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #45a049, #3d8b40);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(76, 175, 80, 0.4);
        }

        .btn-primary:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            transform: translateY(-1px);
        }

        /* Ghost button: no background, link-like */
        .btn-ghost {
            background: transparent !important;
            color: #79be48ff; /* gray-900 */
            padding: 8px 0;
            box-shadow: none;
        }
        .btn-ghost:hover {
            background: transparent !important;
            color: #298215ff; /* slightly darker */
            transform: none;
            text-decoration: none;
        }

        .result-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-top: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            display: none;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .result-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .result-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 16px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .result-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .result-category {
            font-size: 1rem;
            color: #4CAF50;
            font-weight: 600;
        }

        .confidence-display {
            margin: 20px 0;
        }

        .confidence-bar {
            background: #f1f5f9;
            border-radius: 50px;
            height: 12px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .confidence-fill {
            background: linear-gradient(90deg, #4CAF50, #45a049);
            height: 100%;
            border-radius: 50px;
            transition: width 1s ease-out;
            width: 0%;
        }
        /* Confidence thresholds */
        .confidence-fill.low { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .confidence-fill.very-low { background: linear-gradient(90deg, #ef4444, #b91c1c); }

        .confidence-text {
            text-align: center;
            font-weight: 600;
            color: #4CAF50;
            font-size: 1.125rem;
        }
        .confidence-text.low { color: #f59e0b; }
        .confidence-text.very-low { color: #ef4444; }

        .disposal-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin: 16px 0;
            border-left: 4px solid #4CAF50;
        }

        .disposal-info h4 {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .disposal-info p {
            color: #64748b;
            margin: 4px 0;
        }

        .action-buttons {
            display: grid;
            gap: 12px;
            margin-top: 20px;
        }

        .error-card {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        .error-card .result-icon {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .loading-card {
            text-align: center;
            padding: 40px 20px;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4CAF50;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 9998;
        }
     

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 90vw;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideInModal 0.3s ease;
            position: relative;
        }

        @keyframes slideInModal {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
           background-color: rgba(16, 185, 129, 1);
            color: white;
            padding: 24px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: background 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

       .modal-body {
            padding: 24px;
          
        }

        .management-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .management-item h4 {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 12px;
            font-size: 1.125rem;
        }

       .management-item img {
            width: 100%;
            max-width: 300px;
            border-radius: 8px;
            margin-top: 12px;
        }

       .post-item {
    background: white;
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    border: 1px solid #f1f5f9;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    position: relative;
    overflow: hidden;
}
.post-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    border-color: #e2e8f0;
}
post-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 4px;
    height: 0;
    background: linear-gradient(to bottom, #10b981, #059669);
    transition: height 0.3s ease;
}

.post-item:hover::before {
    height: 100%;
}

        .post-header {
    display: flex;
    align-items: center;
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f8fafc;
}


        .post-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 14px;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
}

        .post-header:hover .post-avatar {
    border-color: #10b981;
    transform: scale(1.05);
}

.post-user-info h5 {
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 4px 0;
    font-size: 1.1rem;
}

.post-date {
    color: #64748b;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

        .post-title {
    font-weight: 700;
    font-size: 1.3rem;
    color: #1e293b;
    margin-bottom: 14px;
    line-height: 1.4;
}

.post-content {
    color: #475569;
    line-height: 1.7;
    margin-bottom: 18px;
    font-size: 1rem;
}

.post-images {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.post-image {
    width: 50%;
    height: auto;
    object-fit: cover;
    border-radius: 10px;
    transition: all 0.3s ease;
    cursor: pointer;
    place-self: center;
}

.post-image:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.post-tag {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: #166534;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 6px rgba(22, 101, 52, 0.1);
}

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }

        .no-data i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        /* สไตล์สำหรับระบบกล้อง */
        #cameraFeed, #cameraCanvas {
            width: 100%;
            border-radius: 12px;
            display: none;
        }

        .camera-container {
            position: relative;
            margin-bottom: 16px;
        }

        .camera-controls {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        /* สไตล์สำหรับผลลัพธ์หลายอันดับ */
        .prediction-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border-left: 4px solid #e2e8f0;
        }

        .prediction-item.rank-1 {
            border-left-color: #10b981;
        }

        .prediction-item.rank-2 {
            border-left-color: #f59e0b;
        }

        .prediction-item.rank-3 {
            border-left-color: #3b82f6;
        }

        .rank-badge {
            display: inline-block;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #10b981;
            color: white;
            text-align: center;
            line-height: 24px;
            margin-right: 8px;
        }

        .rank-2 .rank-badge {
            background: #f59e0b;
        }

        .rank-3 .rank-badge {
            background: #3b82f6;
        }

        .category-badge {
            font-size: 0.875rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .category-recycle {
            background-color: #dcfce7;
            color: #166534;
        }

        .category-hazard {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .category-general {
            background-color: #e2e8f0;
            color: #475569;
        }

        @media (max-width: 768px) {
            .main-container {
                margin: 10px;
                border-radius: 16px;
            }
            
            .header {
                padding: 24px 20px;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
            
            .content {
                padding: 24px 20px;
            }
            
            .modal-content {
                max-width: 95vw;
            }
            
            .modal-body {
    padding: 16px 24px 24px;
    max-height: calc(90vh - 140px);
    overflow-y: auto;
    background: #fafbfc;
}

.modal-body::-webkit-scrollbar {
    width: 6px;
}

.modal-body::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #10b981;
    border-radius: 3px;
}
.post-item {
    opacity: 0;
    transform: translateY(20px);
    animation: postSlideIn 0.5s ease forwards;
}

.post-item:nth-child(1) { animation-delay: 0.1s; }
.post-item:nth-child(2) { animation-delay: 0.2s; }
.post-item:nth-child(3) { animation-delay: 0.3s; }

@keyframes postSlideIn {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
        }
    </style>
    <style>
/* เพิ่ม/แก้ไข CSS สำหรับกล้อง */
#cameraFeed {
    width: 100%;
    height: auto;
    border-radius: 12px;
    display: none;
    background: #000;
}

.camera-container {
    position: relative;
    margin-bottom: 16px;
    min-height: 300px; /* กำหนดความสูงขั้นต่ำ */
    background: #f8fafc;
    border-radius: 12px;
    display: none; /* ซ่อนไว้ตอนแรก */
}

.camera-container.active {
    display: block;
}

/* แก้ไขสำหรับการแสดงรูปเต็มพื้นที่ */
.upload-zone.has-image {
    padding: 0;
    border: none;
    background: transparent;
}

.upload-zone.has-image .upload-content {
    display: none;
}

.preview-image {
    width: 100%;
    height: auto;
    max-height: 400px;
    object-fit: contain;
    border-radius: 12px;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
    margin: 0;
    display: none;
}

.preview-image.show {
    display: block;
}
</style>
<style>
/* สไตล์สำหรับการแสดงโพสต์ในหน้าหลัก */
.posts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.post-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    cursor: pointer;
    transition: all 0.3s ease;
}

.post-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
}

.post-card-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
}

.post-card-content {
    padding: 16px;
}

.post-card-header {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}

.post-card-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 8px;
}

.post-card-info h5 {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.post-card-date {
    font-size: 0.75rem;
    color: #64748b;
}

.post-card-title {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.post-card-excerpt {
    font-size: 0.875rem;
    color: #475569;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: 8px;
}

.post-card-tag {
    display: inline-block;
    background: #dcfce7;
    color: #166534;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

/* Modal สำหรับรายละเอียดเต็ม */
.post-detail-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(5px);
}

.post-detail-modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.post-detail-content {
    background: white;
    border-radius: 20px;
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.post-detail-header {
    position: sticky;
    top: 0;
    background: white;
    padding: 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 10;
}

.post-detail-close {
    background: #f1f5f9;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.3s ease;
}

.post-detail-close:hover {
    background: #e2e8f0;
}

.post-detail-body {
    padding: 20px;
}

.post-detail-user {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
}

.post-detail-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
}

.post-detail-user-info h4 {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
    color: #1e293b;
}

.post-detail-date {
    font-size: 0.875rem;
    color: #64748b;
}

.post-detail-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 16px;
}

.post-detail-text {
    color: #475569;
    line-height: 1.8;
    margin-bottom: 20px;
}

.post-detail-images {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.post-detail-image {
    width: 100%;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.3s ease;
}

.post-detail-image:hover {
    transform: scale(1.05);
}

.post-detail-tag {
    background: #dcfce7;
    color: #166534;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
    display: inline-block;
}

/* Image viewer modal */
.image-viewer-modal {
    display: none;
    position: fixed;
    z-index: 3000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.9);
    cursor: pointer;
}

.image-viewer-modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.image-viewer-img {
    max-width: 90%;
    max-height: 90vh;
    object-fit: contain;
}

/* Responsive */
@media (max-width: 768px) {
    .posts-grid {
        grid-template-columns: 1fr;
    }
    
    .post-detail-content {
        max-width: 100%;
        border-radius: 12px;
    }
}
/* เพิ่มใน style tag */
.cropper-container-wrapper {
    padding: 20px;
    background: #f9fafb;
    max-height: 60vh;
    overflow: auto;
}

.cropper-container {
    max-height: 500px;
}

/* สำคัญ - ต้องกำหนดให้รูปแสดงผลถูกต้อง */
#imageToCrop {
    max-width: 100%;
    display: block;
}
.cropper-container {
    height: 60vh; /* กำหนดความสูงของพื้นที่แสดงภาพ */
    max-height: 600px;
    background-color: #2c3e50; /* พื้นหลังสีเข้มเพื่อให้เห็นรูปง่าย */
}

/* สำคัญ: ต้องกำหนดให้รูปภาพแสดงผลเป็น block */
#imageToCrop {
    display: block;
    max-width: 100%;
}

/* จัดการ Layout ของ Footer ให้ปุ่มอยู่ชิดขวา */
.modal-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 12px;
}

/* ป้องกันปัญหา Scrollbar ของหน้าเว็บเมื่อ Modal เปิดอยู่ */
body.modal-open {
    overflow: hidden;
}

/* เพิ่มการตกแต่งให้ปุ่มใน Modal ดูสอดคล้องกับดีไซน์ */
.modal-footer .btn {
    padding: 12px 20px;
    font-size: 0.95rem;
}
.modal-content .modal-header,
.modal-content .modal-header *,
.modal-content .modal-body,
.modal-content .modal-body *,
.modal-content .modal-footer,
.modal-content .modal-footer * {
    pointer-events: auto; /* ตั้งค่ากลับเป็นปกติ */
}
.map-modal-content {
    background: white;
    border-radius: 20px;
    max-width: 90vw;
    width: 800px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

        .map-modal-header {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 5; /* make header above any map overlays */
        }

        .map-modal-search {
            flex: 1;
            max-width: 520px;
            margin: 0 12px;
            display: flex;
            gap: 8px;
        }

        .map-modal-search input {
            flex: 1;
            border-radius: 9999px;
            border: none;
            padding: 10px 16px;
            outline: none;
        }

        .map-modal-search button {
            background: rgba(255,255,255,0.15);
            color: white;
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 9999px;
            padding: 10px 16px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .map-modal-search button:hover {
            background: rgba(255,255,255,0.25);
        }

        /* Ensure all elements in the custom map modal can receive pointer events */
    
        /* Keep the search controls on top of potential map overlays */
        .map-modal-search { position: relative; z-index: 6; }
        .map-modal-search input { position: relative; z-index: 7; color: black;}
        .map-modal-search button { position: relative; z-index: 7; }

        /* Make Google Places Autocomplete dropdown visible above modal */
        .pac-container { z-index: 100000 !important; }

.map-modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
}

.map-modal-close {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    transition: background 0.3s ease;
}

.map-modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
}

.map-modal-body {
    padding: 0;
    display: grid;
    grid-template-columns: 1fr; /* single column since map panel is hidden */
    height: 70vh;
}

.map-container {
    height: 100%;
    border-right: 1px solid #e2e8f0;
}

.places-container {
    height: 100%;
    overflow-y: auto;
    padding: 20px;
}

.place-item {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    cursor: pointer;
}

.place-item:hover {
    background: #f1f5f9;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.place-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.place-name {
    font-weight: 600;
    color: #1e293b;
    font-size: 1.1rem;
    margin: 0;
}

.place-distance {
    background: #10b981;
    color: white;
    padding: 4px 8px;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 500;
    min-width: 60px;
    text-align: center;
}

.place-address {
    color: #64748b;
    font-size: 0.9rem;
    margin-bottom: 8px;
    line-height: 1.4;
}

.place-rating {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #64748b;
    font-size: 0.875rem;
}

.star-icon {
    color: #f59e0b;
}

.loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 200px;
    color: #64748b;
}

.no-places {
    text-align: center;
    padding: 40px 20px;
    color: #64748b;
}

.no-places-icon {
    font-size: 3rem;
    color: #cbd5e1;
    margin-bottom: 16px;
}

/* Responsive */
@media (max-width: 768px) {
    .map-modal-content {
        max-width: 95vw;
        width: 95vw;
    }
    
    .map-modal-body {
        grid-template-columns: 1fr;
        height: 70vh;
    }
    
    .map-container {
        height: 300px;
        border-right: none;
        border-bottom: 1px solid #e2e8f0;
    }
}
.category-not-waste {
    background-color: #dbeafe;
    color: #1e40af;
}
</style>
</head>
    
<body>
    <?php include 'nav.php'; ?>
    <!-- Analyze progress + overlay -->
    <div id="topProgress" style="position:fixed;left:0;top:0;height:3px;width:0;background:#10b981;box-shadow:0 0 10px rgba(16,185,129,.6);z-index:11000;transition:width .35s ease;"></div>
    <div id="analyzingOverlay" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(10,17,14,.35);backdrop-filter:blur(2px);z-index:1500;">
      <div class="bb-spinner" style="width:66px;height:66px;border-radius:50%;border:5px solid rgba(255,255,255,.3);border-top-color:#fff;box-shadow:0 0 18px rgba(255,255,255,.35);"></div>
    </div>
    <div class="main-container">
        <!-- Header -->
        <div class="header">
            <h1>สแกนขยะ</h1>
            <p>ระบบ AI จำแนกขยะอัตโนมัติ</p>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Upload Zone -->
            <div class="upload-zone" id="uploadZone">
    <div class="upload-content">
        <div class="upload-icon">
            <i class="fas fa-cloud-upload-alt"></i>
        </div>
        <p class="text-lg font-medium text-gray-700 mb-2">ลากและวางไฟล์ หรือคลิกเพื่อเลือก</p>
        <p class="text-sm text-gray-500">รองรับไฟล์ JPG, PNG, GIF</p>
    </div>
    <input type="file" id="imageInput" accept="image/*" class="hidden">
    <img id="previewImage" class="preview-image" alt="Preview">
</div>

            <!-- Camera Feed -->
            <div class="camera-container" id="cameraContainer">
    <video id="cameraFeed" autoplay playsinline></video>
    <canvas id="cameraCanvas" style="display: none;"></canvas>
</div>

            <!-- Model Selector -->
            <div class="mt-4" style="display:flex;align-items:center;gap:10px;">
                <label for="modelSelect" class="text-sm text-gray-600">โมเดล:</label>
                <select id="modelSelect" class="btn btn-secondary" style="padding:6px 10px;">
                    <option value="system">System</option>
                    <option value="gemini">Gemini</option>
                </select>
            </div>

            <!-- Action Buttons -->
            <div class="grid grid-cols-2 gap-3 mt-6">
                <button id="cameraButton" class="btn btn-secondary">
                    <i class="fas fa-camera"></i>
                    เปิดกล้อง
                </button>
                <button id="scanButton" class="btn btn-primary" disabled>
                    <i class="fas fa-search"></i>
                    เริ่มสแกน
                </button>
                <button id="captureButton" class="btn btn-primary hidden">
                    <i class="fas fa-camera"></i>
                    ถ่ายภาพ
                </button>
                <button id="clearButton" class="btn btn-secondary hidden">
                    <i class="fas fa-times"></i>
                    ลบรูป
                </button>
            </div>

            <!-- Result Area -->
            <div id="resultArea"></div>
        </div>
    </div>

    <!-- Management Modal -->
    <div id="managementModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-recycle mr-2"></i>
                    วิธีการจัดการขยะ
                </h3>
                <button class="modal-close" onclick="closeModal('managementModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="managementContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Posts Modal -->
    <div id="postsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-users mr-2"></i>
                    วิธีการคัดแยกจากผู้ใช้
                </h3>
                <button class="modal-close" onclick="closeModal('postsModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="postsContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Profile Modal -->
    <div id="profileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user mr-2"></i>
                    โปรไฟล์ผู้ใช้
                </h3>
                <button class="modal-close" onclick="closeModal('profileModal')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="profileContent"></div>
        </div>
    </div>
    <div id="cropModal" class="modal">
        <div class="modal-content" style="max-width: 700px; padding: 0; overflow: hidden;">
            <div class="modal-header">
                <h3 class="modal-title">ครอบตัดรูปภาพ</h3>
                <button id="cancelCropButton" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" style="padding: 0; background-color: #2c3e50;">
                <div class="cropper-container">
                    <img id="imageToCrop" src="">
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px; text-align: right; background-color: #f1f5f9; border-top: 1px solid #e2e8f0;">
                <button id="resetCropButton" class="btn btn-secondary" style="margin-right: 8px;">
                    <i class="fas fa-sync-alt"></i> รีเซ็ต
                </button>
                <button id="confirmCropButton" class="btn btn-primary">
                    <i class="fas fa-check"></i> ยืนยัน
                </button>
            </div>
        </div>
    </div>
</div>
        </div>
</div>
</div>

<script>
        // Analyze animation helpers
        (function(){
            const style = document.createElement('style');
            style.textContent = '@keyframes bbspin{to{transform:rotate(360deg)}} .bb-spinner{animation:bbspin 1s linear infinite} #scanButton{position:relative;overflow:hidden} #scanButton:active::after{content:"";position:absolute;left:50%;top:50%;width:0;height:0;border-radius:999px;background:rgba(255,255,255,.35);transform:translate(-50%,-50%);animation:bbripple .5s ease}@keyframes bbripple{to{width:360px;height:360px;opacity:0}}';
            document.head.appendChild(style);
        })();
        // Inject enhanced styles for modals/cards
        (function(){
            const css = `
#managementModal .modal-content, #postsModal .modal-content { border-radius:16px; overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
#managementModal .modal-header, #postsModal .modal-header { background:linear-gradient(135deg,#10b981,#059669); color:#fff; }
#managementModal .modal-title, #postsModal .modal-title { display:flex; align-items:center; gap:8px; margin:0; }
#managementModal .modal-body, #postsModal .modal-body { overflow-y:auto; max-height:calc(90vh - 72px); }
.mg-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:16px; margin-bottom:14px; box-shadow:0 6px 18px rgba(0,0,0,.06); }
.mg-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; font-weight:700; color:#134e4a; }
.mg-type-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:9999px; font-size:.85rem; font-weight:600; background:#dcfce7; color:#166534; margin-left:auto; }
.mg-content img { max-width: 50%;border-radius: 8px;margin-top: 8px; place-self: center;}
.user-link { cursor:pointer; }
.user-link:hover { text-decoration:underline; }
`;
            const el = document.createElement('style'); el.textContent = css; document.head.appendChild(el);
        })();
        // Global variables
let currentData = null;
let cameraStream = null;
let cropper = null; 
let map;
let markers = [];
let originMarker = null;
let modalPlaceAutocomplete = null;
let modalState = { keyword: null, category: null, lat: null, lng: null };
const uploadZone = document.getElementById('uploadZone');
const imageInput = document.getElementById('imageInput');
const previewImage = document.getElementById('previewImage');
const scanButton = document.getElementById('scanButton');
const clearButton = document.getElementById('clearButton');
const resultArea = document.getElementById('resultArea');
const cameraButton = document.getElementById('cameraButton');
const captureButton = document.getElementById('captureButton');
const cameraFeed = document.getElementById('cameraFeed');
const cameraCanvas = document.getElementById('cameraCanvas');
const cameraContainer = document.getElementById('cameraContainer');
const cropModal = document.getElementById('cropModal');
const imageToCrop = document.getElementById('imageToCrop');
const confirmCropButton = document.getElementById('confirmCropButton');
const cancelCropButton = document.getElementById('cancelCropButton');
const resetCropButton = document.getElementById('resetCropButton');


// UI helper: show/hide overlay and animate top progress
function setUIAnalyzing(on){
    const overlay = document.getElementById('analyzingOverlay');
    const topBar = document.getElementById('topProgress');
    const btn = scanButton;
    if(on){
        if(overlay) overlay.style.display = 'flex';
        if(topBar){
            topBar.style.width = '20%';
            setTimeout(()=>{ topBar.style.width = '55%'; }, 350);
            setTimeout(()=>{ topBar.style.width = '85%'; }, 1200);
        }
        if(btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i>กำลังวิเคราะห์...'; }
    }else{
        if(overlay) overlay.style.display = 'none';
        if(topBar){ topBar.style.width = '100%'; setTimeout(()=>{ topBar.style.width = '0'; }, 600); }
        if(btn){ btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> วิเคราะห์'; }
    }
}

function autoScrollToResult(){
    if(resultArea){ resultArea.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}


        // Upload zone events
        
        

        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        confirmCropButton.addEventListener('click', applyCrop);
cancelCropButton.addEventListener('click', () => {
    closeModal('cropModal');
    // ไม่ต้อง resetUpload() ทันที ให้ผู้ใช้เลือกรูปใหม่เอง
});
resetCropButton.addEventListener('click', () => {
    if (cropper) {
        cropper.reset(); // ใช้ฟังก์ชัน reset ของ cropper
    }
});

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        // Helpers to support dropping images directly from websites (files, items, and URLs)
        function extractImageUrlFromHtml(html){
            try{
                const m = String(html||'').match(/<img[^>]+src=["']([^"']+)["']/i);
                return m ? m[1] : null;
            }catch(_){ return null; }
        }
        function filenameFromUrl(url){
            try{
                const u = new URL(url, window.location.href);
                let name = (u.pathname || '').split('/').pop() || 'image';
                if(!/\.(png|jpe?g|webp|gif|bmp)$/i.test(name)){
                    const qFmt = (u.searchParams.get('format')||'');
                    const ext = (qFmt.match(/(png|jpe?g|jpg|webp|gif|bmp)/i)||[])[0] || 'jpg';
                    name += '.'+ext.replace('jpeg','jpg');
                }
                return name;
            }catch(_){ return 'dropped.jpg'; }
        }
        function getUrlFromDataTransfer(dt){
            return new Promise((resolve)=>{
                let resolved = false;
                const tryResolve = (val)=>{ if(!resolved && val){ resolved=true; resolve(val); } };
                const items = Array.from(dt.items||[]);
                items.forEach(item=>{
                    if(item.kind==='string' && item.type==='text/uri-list'){
                        item.getAsString(s=>{ tryResolve((s||'').split('\n')[0].trim()); });
                    } else if(item.kind==='string' && item.type==='text/html'){
                        item.getAsString(s=>{ tryResolve(extractImageUrlFromHtml(s)); });
                    } else if(item.kind==='string' && item.type==='text/plain'){
                        item.getAsString(s=>{ const m = (s||'').match(/https?:[^\s]+/i); tryResolve(m?m[0]:null); });
                    }
                });
                setTimeout(()=>{ if(!resolved) resolve(null); }, 60);
            });
        }
        async function handleDroppedUrl(url){
            if(!url) return;
            try{
                if(url.startsWith('data:')){
                    const r = await fetch(url);
                    const blob = await r.blob();
                    const file = new File([blob], filenameFromUrl('image.png'), { type: blob.type||'image/png' });
                    handleFileSelect(file);
                    return;
                }
                if(!/^https?:/i.test(url)) return;
                const resp = await fetch(url, { mode: 'cors' });
                if(!resp.ok) throw new Error('fetch-failed');
                const blob = await resp.blob();
                if(blob && blob.size>0){
                    const name = filenameFromUrl(url);
                    const type = blob.type || (name.toLowerCase().endsWith('.png')?'image/png':'image/jpeg');
                    const file = new File([blob], name, { type });
                    handleFileSelect(file);
                    return;
                }
                throw new Error('empty-blob');
            }catch(err){
                console.warn('Drop URL fetch failed:', err);
                alert('ไม่สามารถดึงรูปจากลิงก์นี้ได้โดยตรง (CORS) กรุณาดาวน์โหลดรูปก่อนแล้วลากมาวาง');
            }
        }

        uploadZone.addEventListener('drop', async (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            const dt = e.dataTransfer;
            // 1) Direct files (from desktop or some sites)
            if (dt.files && dt.files.length > 0) {
                handleFileSelect(dt.files[0]);
                return;
            }
            // 2) DataTransferItem file entries
            if (dt.items && dt.items.length > 0) {
                for (const item of dt.items) {
                    if (item.kind === 'file') {
                        const f = item.getAsFile();
                        if (f) { handleFileSelect(f); return; }
                    }
                }
                // 3) URL or HTML content
                const url = await getUrlFromDataTransfer(dt);
                if (url) { await handleDroppedUrl(url); }
            }
        });

        imageInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files[0]);
            }
        });

        // Camera functions
        cameraButton.addEventListener('click', async () => {
    resetUpload(); // เพิ่มบรรทัดนี้เพื่อล้างค่าเก่า

    try {
        // แสดง container กล้อง
        cameraContainer.classList.add('active');
        cameraFeed.style.display = 'block';
        
        // ซ่อน upload zone
        uploadZone.style.display = 'none';
        
        // จัดการปุ่ม
        scanButton.style.display = 'none';
        captureButton.classList.remove('hidden');
        clearButton.classList.add('hidden');
        cameraButton.style.display = 'none';
        
        // ขอสิทธิ์ใช้กล้อง
        const constraints = {
            video: {
                facingMode: 'environment',
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        };
        
        cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
        cameraFeed.srcObject = cameraStream;
        
        // รอให้ video พร้อม
        cameraFeed.onloadedmetadata = () => {
            cameraFeed.play();
        };
        
    } catch (error) {
        console.error('Camera error:', error);
        alert(`ไม่สามารถเปิดกล้องได้: ${error.message}`);
        resetCamera();
    }
});


        captureButton.addEventListener('click', () => {
    // วาดภาพจาก video ไปยัง canvas
    const context = cameraCanvas.getContext('2d');
    cameraCanvas.width = cameraFeed.videoWidth;
    cameraCanvas.height = cameraFeed.videoHeight;
    context.drawImage(cameraFeed, 0, 0, cameraCanvas.width, cameraCanvas.height);
    
    // แสดงภาพตัวอย่าง
    const dataUrl = cameraCanvas.toDataURL('image/jpeg');
    openCropModal(dataUrl);
    // previewImage.src = dataUrl;
    // previewImage.classList.add('show');
    // uploadZone.classList.add('has-image');
    
    // ปิดสตรีมกล้อง
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    
    // ซ่อนกล้อง แสดง upload zone
    cameraContainer.classList.remove('active');
    cameraFeed.style.display = 'none';
    uploadZone.style.display = 'block';
    
    // เตรียมไฟล์สำหรับอัปโหลด
    cameraCanvas.toBlob(blob => {
        const file = new File([blob], 'camera_capture.jpg', { type: 'image/jpeg' });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        imageInput.files = dataTransfer.files;
        
        // จัดการปุ่ม
        scanButton.disabled = false;
        scanButton.style.display = 'inline-flex';
        captureButton.classList.add('hidden');
        cameraButton.style.display = 'inline-flex';
        clearButton.classList.remove('hidden');
    }, 'image/jpeg');
});
    


       function resetCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    cameraContainer.classList.remove('active');
    cameraFeed.style.display = 'none';
    captureButton.classList.add('hidden');
    uploadZone.style.display = 'block';
    scanButton.style.display = 'inline-flex';
    cameraButton.style.display = 'inline-flex';
}

        // Handle file selection
       function handleFileSelect(file) {
    if (file && ( (file.type && file.type.startsWith('image/')) || /\.(png|jpe?g|webp|gif|bmp)$/i.test((file.name||'')) )) {
        const reader = new FileReader();
        reader.onload = (e) => {
            // จากเดิมที่แสดงภาพเลย เปลี่ยนเป็นเปิด modal
            openCropModal(e.target.result);
        };
        reader.readAsDataURL(file);
    } else {
        alert('กรุณาอัปโหลดไฟล์ภาพ (JPG, PNG) เท่านั้น!');
    }
}
function openCropModal(imageData) {
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }

    imageToCrop.src = imageData;
    showModal('cropModal'); // เปิด Modal ก่อน

    // ใช้ setTimeout เล็กน้อยเพื่อให้ Modal แสดงผลเสร็จสมบูรณ์ก่อนเริ่ม Cropper
setTimeout(() => {
    cropper = new Cropper(imageToCrop, {
        // --- ส่วนที่แก้ไข ---
        aspectRatio: NaN,  // << แก้ไขตรงนี้เป็น NaN เพื่อปลดล็อกอัตราส่วน

        // --- ออปชันอื่นๆ ที่เราปรับไว้ยังคงเดิม ---
        viewMode: 0,
        autoCropArea: 0.5,
        minCropBoxWidth: 50,
        minCropBoxHeight: 50,
        dragMode: 'move',
        restore: false,
        guides: true,
        center: true,
        highlight: true,
        cropBoxMovable: true,
        cropBoxResizable: true,
        toggleDragModeOnDblclick: false,
        responsive: true,
        background: false,
        modal: true
    });
}, 150);
}

function applyCrop() {
    if (!cropper) return;
    
    // ดึงภาพที่ครอบตัดแล้วจาก canvas
    const canvas = cropper.getCroppedCanvas({
        width: 512, // กำหนดขนาดภาพสุดท้าย
        height: 512,
        imageSmoothingQuality: 'high',
    });

    // แสดงภาพที่ครอบแล้วใน preview
    previewImage.src = canvas.toDataURL('image/jpeg');
    previewImage.classList.add('show');
    uploadZone.classList.add('has-image');
    

    // แปลง canvas เป็นไฟล์เพื่อเตรียมส่งไปวิเคราะห์
    canvas.toBlob((blob) => {
        const file = new File([blob], 'cropped_image.jpg', { type: 'image/jpeg' });
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        imageInput.files = dataTransfer.files; // <-- สำคัญมาก: เปลี่ยนไฟล์ใน input เป็นไฟล์ที่ครอบแล้ว

        scanButton.disabled = false;
        clearButton.classList.remove('hidden');
    }, 'image/jpeg');

    closeModal('cropModal');
    captureButton.disabled = true;
}
        function handleImageUpload(imageData) {
  const uploadZone = document.getElementById('upload-zone');
  
  // ล้างเนื้อหาเดิม
  uploadZone.innerHTML = '';
  
  // สร้าง element รูปภาพใหม่
  const imgElement = document.createElement('img');
  imgElement.src = imageData;
  imgElement.style.maxWidth = '100%';
  
  // เพิ่มรูปภาพลงใน upload-zone
  uploadZone.appendChild(imgElement);
  
  // ซ่อนข้อความหรือคำแนะนำเดิม
  const dragDropText = document.querySelector('.drag-drop-text');
  if (dragDropText) {
    dragDropText.style.display = 'none';
  }
}

        // Clear button
        clearButton.addEventListener('click', resetUpload);

function resetUpload() {
        imageInput.value = '';
        previewImage.classList.remove('show');
        previewImage.src = '';
        uploadZone.classList.remove('has-image');
        uploadZone.classList.remove('has-file');
        scanButton.disabled = true;
        clearButton.classList.add('hidden');
        resultArea.innerHTML = '';
        currentData = null;
        
        // vvv เพิ่มโค้ดบรรทัดนี้ vvv
        captureButton.disabled = false;
        
        resetCamera();
}
uploadZone.addEventListener('click', (e) => {
    // ถ้าคลิกที่รูปภาพ ไม่ต้องเปิด file dialog
    if (e.target === previewImage && previewImage.classList.contains('show')) {
        return;
    }
    imageInput.click();
});
        // Scan button
        scanButton.addEventListener('click', () => {
            // ตรวจสอบว่ามีรูปภาพหรือยัง
            if (!imageInput.files || !imageInput.files[0]) {
                displayError('กรุณาเลือกรูปภาพก่อนทำการวิเคราะห์');
                setUIAnalyzing(false);
                return;
            }
            setUIAnalyzing(true);
            const formData = new FormData();
            formData.append('image', imageInput.files[0]);
            const modelSelect = document.getElementById('modelSelect');
            formData.append('model', modelSelect ? modelSelect.value : 'system');

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        formData.append('lat', position.coords.latitude);
                        formData.append('lng', position.coords.longitude);
                        performScan(formData);
                    },
                    (error) => {
                        performScan(formData);
                    }
                );
            } else {
                performScan(formData);
            }
        });

        function performScan(formData) {
            // Show loading
            resultArea.innerHTML = `
                <div class="result-card loading-card" style="display: block;">
                    <div class="loading-spinner"></div>
                    <p class="text-lg font-medium text-gray-700">กำลังวิเคราะห์ภาพ...</p>
                    <p class="text-sm text-gray-500 mt-2">กรุณารอสักครู่</p>
                </div>
            `;

            fetch('scan.php', {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' }
            })
            .then(async response => {
                const contentType = response.headers.get('content-type') || '';
                const raw = await response.text();
                if (!response.ok) {
                    // แสดงข้อความตัดทอน เพื่อป้องกัน HTML เต็ม ๆ
                    const snippet = raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 200);
                    return { success: false, detail: 'ไม่สามารถวิเคราะห์ภาพได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง' };
                }
                // ถ้าเป็น JSON ตรง ๆ
                if (contentType.includes('application/json')) {
                    try { return JSON.parse(raw); } catch (_) {}
                }
                // พยายามกู้ JSON จาก text (กรณีมี warning ปะปน)
                try {
                    const start = raw.indexOf('{');
                    const end = raw.lastIndexOf('}');
                    if (start !== -1 && end !== -1 && end > start) {
                        return JSON.parse(raw.substring(start, end + 1));
                    }
                } catch (_) {}
                // ไม่ใช่ JSON แน่ ๆ
                const snippet = raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 120);
                return { success: false, detail: 'ไม่สามารถวิเคราะห์ภาพได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง' };
            })
            .then(result => {
                currentData = result;
                displayResult(result);
                autoScrollToResult();
                setUIAnalyzing(false);
            })
            .catch(error => {
                displayError(error && error.message ? error.message : 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');
                autoScrollToResult();
                setUIAnalyzing(false);
            });
        }

        function displayResult(result) {
    if (result.success && result.predictions && result.predictions.length > 0) {
        let html = `
            <div class="result-card" style="display: block;">
                <div class="result-header">
                    <div class="result-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h3 class="result-title">ผลการวิเคราะห์</h3>
                </div>
        `;
        // ถ้า DB ยังไม่มีข้อมูลของชนิดที่ทำนายได้ ให้แสดงโน้ตสุภาพใต้ผลลัพธ์
        try {
            const db = result.database_content || {};
            const hasContent = Array.isArray(db.trash_content) && db.trash_content.length > 0;
            const hasPosts   = Array.isArray(db.recent_posts) && db.recent_posts.length > 0;
            if (!hasContent && !hasPosts) {
                const label = db.predicted_waste_name ? `สำหรับ "${db.predicted_waste_name}"` : '';
                html += `
                    <div class="disposal-info" style="margin-top:12px;">
                        <h4><i class="fas fa-info-circle mr-2"></i>ยังไม่มีข้อมูลจากฐานข้อมูล</h4>
                        <p>ระบบวิเคราะห์ภาพได้แล้ว แต่ยังไม่มีรายละเอียด/โพสต์ในฐานข้อมูล ${label}</p>
                    </div>
                `;
            }
        } catch(e) {}

        // แสดงผล 3 อันดับแรก
        result.predictions.forEach((prediction, index) => {
            const absProb = Number(prediction.confidence || 0);
            const pct = absProb * 100;
            // แถบความคืบหน้าดูง่ายขึ้น (ไม่แสดงเป็น 0 กว้าง 0 พิกเซล)
            const confidencePercent = Math.max(2, Math.round(pct));
            const isLow = pct < 50;
            const isVeryLow = pct < 30;
            const confLevelClass = isVeryLow ? 'very-low' : (isLow ? 'low' : '');
            // ป้ายตัวเลขแสดงค่าจริง (คงความถูกต้อง)
            const confidenceLabel = (pct >= 1 ? pct.toFixed(0) : pct.toFixed(1)) + '% ความมั่นใจ';
            const finalConfidenceLabel = isLow ? ((pct >= 1 ? pct.toFixed(0) : pct.toFixed(1)) + '% ไม่มั่นใจ') : confidenceLabel;
            const rankClass = `prediction-item rank-${index + 1}`;
            
            // กำหนดคลาสสำหรับประเภทขยะ
            let categoryClass = '';
            if (prediction.category === 'ขยะรีไซเคิล') {
                categoryClass = 'category-recycle';
            } else if (prediction.category === 'ขยะอันตราย') {
                categoryClass = 'category-hazard';
            } else if (prediction.category === 'ไม่ใช่ขยะ') {
                categoryClass = 'category-not-waste'; // เพิ่มคลาสสำหรับ "ไม่ใช่ขยะ"
            } else {
                categoryClass = 'category-general';
            }
            
            html += `
                <div class="${rankClass}">
                    <div class="flex justify-between items-center">
                        <h4 class="text-lg font-bold">
                            <span class="rank-badge">${index + 1}</span>
                            ${prediction.waste_type}
                        </h4>
                        <span class="category-badge ${categoryClass}">
                            ${prediction.category}
                        </span>
                    </div>
                    
                    <div class="confidence-display mt-2">
                        <div class="confidence-bar">
                            <div class="confidence-fill ${confLevelClass}" 
                                 style="width: ${confidencePercent}%"></div>
                        </div>
                        <p class="confidence-text ${confLevelClass}">${finalConfidenceLabel}</p>
                    </div>

                    ${prediction.category !== 'ไม่ใช่ขยะ' ? `
                    <div class="mt-3">
                        <button class="btn btn-secondary find-places-btn" 
                                data-keyword="${prediction.waste_type}" 
                                data-category="${prediction.category}">
                            <i class="fas fa-map-marked-alt mr-2"></i>ค้นหาศูนย์จัดการ
                        </button>
                    </div>
                    ` : ''}
                </div>
            `;
        });

        // ปุ่มดำเนินการ (Action Buttons)
        // ตรวจสอบว่ามีขยะที่สามารถจัดการได้หรือไม่
        const hasWaste = result.predictions.some(pred => pred.category !== 'ไม่ใช่ขยะ');
        
        html += `
                <div class="action-buttons mt-4">

                    <button class="btn btn-secondary" onclick="resetUpload()">
                        <i class="fas fa-redo"></i>
                        สแกนใหม่
                    </button>
                </div>
        `;

        html += `</div>`; // ปิด result-card
        resultArea.innerHTML = html;
        try {
            const postsBtn = resultArea.querySelector('button[onclick="showPostsModal()"]');
            if (postsBtn) postsBtn.innerHTML = '<i class="fas fa-users"></i> วิธีการจัดการจากผู้ใช้';
            const mgBtn = resultArea.querySelector('button[onclick="showManagementModal()"]');
            if (mgBtn) mgBtn.innerHTML = '<i class="fas fa-info-circle"></i> วิธีการจัดการขยะ';
        } catch(_) {}

        // เพิ่ม Event Listener ให้กับปุ่มค้นหาศูนย์จัดการ
       document.querySelectorAll('.find-places-btn').forEach(btn => {
  btn.addEventListener('click', function () {
    const wasteType = this.dataset.keyword;   // เช่น "หลอดไฟ"
    const category  = this.dataset.category;  // เช่น "ขยะอันตราย"

    // ใช้คีย์เวิร์ดกว้างตามหมวด เพื่อให้ API หาเจอได้มากขึ้น
    const searchKey =
      category === 'ขยะอันตราย' ? 'ขยะอันตราย' :
      category === 'ขยะรีไซเคิล' ? 'ขยะรีไซเคิล' : 'ขยะทั่วไป';

    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(pos => {
        openMapModal(searchKey, category, pos.coords.latitude, pos.coords.longitude);
      }, () => alert('กรุณาอนุญาตการเข้าถึงตำแหน่งเพื่อค้นหาสถานที่'));
    } else {
      alert('เบราว์เซอร์ของคุณไม่รองรับการระบุตำแหน่ง');
    }
  });
});

        // Add per-card action buttons (methods + user posts) to the right of the finder
        document.querySelectorAll('.find-places-btn').forEach(btn => {
            try{
                const parent = btn.parentElement; // the mt-3 container
                if (!parent) return;
                parent.classList.add('flex','items-start','justify-between','gap-2','flex-wrap');
                // Skip if buttons already added
                if (parent.querySelector('.open-management-btn')) return;
                const right = document.createElement('div');
                right.className = 'flex flex-col items-start gap-2';
                const wname = btn.getAttribute('data-keyword') || '';

                const mg = document.createElement('button');
                mg.className = 'btn btn-ghost open-management-btn';
                mg.setAttribute('data-waste', wname);
                mg.innerHTML = '<i class="fas fa-info-circle"></i> วิธีการจัดการขยะ';

                const posts = document.createElement('button');
                posts.className = 'btn btn-ghost open-posts-btn';
                posts.setAttribute('data-waste', wname);
                posts.innerHTML = '<i class="fas fa-users"></i> วิธีการคัดแยกจากผู้ใช้';

                right.appendChild(mg);
                right.appendChild(posts);
                parent.appendChild(right);

                // Ensure labels and style as link-like (no background)
                try {
                    mg.innerHTML = '<i class="fas fa-info-circle"></i> วิธีการจัดการขยะ >';
                } catch(_) {}
                try {
                    posts.innerHTML = '<i class="fas fa-users"></i> วิธีการจัดการจากผู้ใช้ >';
                } catch(_) {}

                mg.addEventListener('click', () => openManagementFor(wname));
                posts.addEventListener('click', () => openPostsFor(wname));
            }catch(_){ /* noop */ }
        });

        // Also bind any static buttons created in HTML template (if any)
        document.querySelectorAll('.open-management-btn').forEach(b=> b.addEventListener('click', ()=> openManagementFor(b.dataset.waste)));
        document.querySelectorAll('.open-posts-btn').forEach(b=> b.addEventListener('click', ()=> openPostsFor(b.dataset.waste)));

        // Animate confidence bars
        document.querySelectorAll('.confidence-fill').forEach((bar, index) => {
            setTimeout(() => {
                bar.style.width = bar.style.width;
            }, index * 300);
        });
    } else {
        displayError(result.detail || 'ไม่สามารถประมวลผลได้');
    }
}
function initMap(lat, lng) {
    const userLocation = { lat: lat, lng: lng };
    // map UI removed; init no-op
    
    // หมุดตำแหน่งค้นหา (ลากได้)
    originMarker = new google.maps.Marker({
        position: userLocation,
        map: map,
        title: "ตำแหน่งค้นหา",
        draggable: true,
        zIndex: 1000
    });

    originMarker.addListener('dragend', () => {
        const p = originMarker.getPosition();
        modalState.lat = p.lat();
        modalState.lng = p.lng();
        fetchNearbyAndRenderV3();
    });

    // คลิกบนแผนที่เพื่อย้ายหมุดค้นหา
    map.addListener('click', (e) => {
        originMarker.setPosition(e.latLng);
        modalState.lat = e.latLng.lat();
        modalState.lng = e.latLng.lng();
        fetchNearbyAndRenderV3();
    });
}



  async function openMapModal(keyword, category, lat, lng) {
  const placesListDiv = document.getElementById('placesList');
  document.getElementById('modalKeyword').textContent = keyword;

  // Show modal first
  showModal('mapModal');

  // Create/focus map
  // แสดงผลเป็นรายการเท่านั้น ไม่สร้าง/โฟกัสแผนที่

  // อัปเดตสถานะและหมุดค้นหา
  modalState.keyword = keyword;
  modalState.category = category;
  modalState.lat = lat;
  modalState.lng = lng;
  if (originMarker) originMarker.setPosition({ lat, lng });

  // ตั้งค่า Autocomplete สำหรับช่องค้นหา (ครั้งเดียว)
  const input = document.getElementById('modalPlaceInput');
  const btn = document.getElementById('modalPlaceGo');
  // Autocomplete removed; geocode via backend handled by button/Enter

  const triggerModalTextGeocode = () => {
    const q = (input?.value || '').trim();
    if (!q) return;
    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(arr => {
        if (!Array.isArray(arr) || !arr.length) return;
        const la = parseFloat(arr[0].lat);
        const ln = parseFloat(arr[0].lon);
        if (!Number.isFinite(la) || !Number.isFinite(ln)) return;
        if (originMarker) originMarker.setPosition({ lat: la, lng: ln });
        modalState.lat = la;
        modalState.lng = ln;
        fetchNearbyAndRenderV3();
      })
      .catch(() => {});
  };
    btn?.addEventListener('click', (e) => { e.preventDefault(); triggerModalTextGeocode(); });
  input?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); triggerModalTextGeocode(); }});

  await fetchNearbyAndRenderV3();
 }

async function fetchNearbyAndRender() {
  const placesListDiv = document.getElementById('placesList');
  // Clear old markers
  markers.forEach(m => m.setMap(null));
  markers = [];

  // Loading state
  placesListDiv.innerHTML = `
    <div class=\"loading-container\">\n      <div class=\"loading-spinner\" style=\"width:40px;height:40px;\"></div>\n      <p class=\"mt-3 text-gray-600\">กำลังค้นหาสถานที่...</p>\n    </div>`;

  try {
    const searchRadiusKm = 30;
    const url = `http://localhost:8000/nearby_center` +
      `?lat=${modalState.lat}&lng=${modalState.lng}` +
      `&radius=${searchRadiusKm}` +
      `&keyword=${encodeURIComponent(modalState.keyword || '')}` +
      `&category=${encodeURIComponent(modalState.category || '')}`;

    const res = await fetch(url);
    const data = await res.json();
    const srcArr = Array.isArray(data.centers)
      ? data.centers
      : (Array.isArray(data.places) ? data.places : []);
    const items = srcArr.map(p => ({
      center_name: p.center_name || p.name || 'ศูนย์จัดการขยะ',
      lat: Number(p.lat),
      lng: Number(p.lng),
      address: p.address || p.vicinity || '',
      rating: p.rating || null,
      distance: p.distance || null,
      maps_link: p.maps_link || ((Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lng)))
        ? `https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lng}#map=16/${p.lat}/${p.lng}`
        : '#'),
      type: p.type || 'general',
    }));

    let html = '';
    if (items.length === 0) {
      html = `
        <div class=\"no-places\">\n          <i class=\"fas fa-map-marker-alt no-places-icon\"></i>\n          <p>ไม่พบศูนย์จัดการขยะในรัศมี ${searchRadiusKm} กม.</p>\n        </div>`;
    } else {
      items.forEach((place) => {
        const name = place.center_name || place.name || 'ไม่ระบุชื่อ';
        const la = parseFloat(place.lat);
        const ln = parseFloat(place.lng);
        const addr = place.address || place.vicinity || '';
        const rating = place.rating ? `${place.rating} ⭐` : 'ไม่มีคะแนน';
        const distance = place.distance ? `${place.distance} กม.` : 'ไม่ทราบระยะทาง';
        const link = place.maps_link || `https://www.google.com/maps/search/?api=1&query=${la},${ln}`;

        html += `
          <div class=\"place-item\" data-lat=\"${la}\" data-lng=\"${ln}\">\n            <div class=\"place-header\">\n              <h4 class=\"place-name\">${name}</h4>\n              <span class=\"place-distance\">${distance}</span>\n            </div>\n            <p class=\"place-address\">${addr}</p>\n            <div class=\"place-rating\">\n              <i class=\"fas fa-star star-icon\"></i>\n              ${rating}\n            </div>\n            <a href=\"${link}\" target=\"_blank\" class=\"text-blue-500 text-sm mt-2 inline-block\">\n              ดูใน Google Maps\n            </a>\n          </div>`;

        const tag = (place.type || '').toLowerCase();
        const iconUrl =
          tag === 'hazardous' ? 'https://maps.google.com/mapfiles/ms/icons/orange-dot.png' :
          tag === 'recycle'   ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png'  :
                                'https://maps.google.com/mapfiles/ms/icons/blue-dot.png';

        if (Number.isFinite(la) && Number.isFinite(ln)) {
          const marker = new google.maps.Marker({
            position: { lat: la, lng: ln },
            map,
            title: name,
            icon: { url: iconUrl }
          });
          const info = new google.maps.InfoWindow({
            content: `
              <div style=\"max-width:260px\">\n                <strong>${name}</strong><br/>\n                <small>${addr}</small><br/>\n                <a href=\"${link}\" target=\"_blank\">เปิดใน Google Maps</a>\n              </div>`
          });
          marker.addListener('click', () => info.open({ anchor: marker, map }));
          markers.push(marker);
        }
      });
    }

    placesListDiv.innerHTML = html;

    document.querySelectorAll('.place-item').forEach(el => {
      el.addEventListener('click', () => {
        const linkEl = el.querySelector('a[target="_blank"]');
        if (linkEl && linkEl.href) { window.open(linkEl.href, '_blank'); }
      });
    });
  } catch (err) {
    console.error(err);
    placesListDiv.innerHTML = `
      <div class=\"text-center text-red-500 p-4\">\n        <i class=\"fas fa-exclamation-triangle text-2xl mb-2\"></i>\n        <p>เกิดข้อผิดพลาดในการค้นหาสถานที่</p>\n        <p class=\"text-sm\">${String(err)}</p>\n      </div>`;
  }
}

        function displayError(message) {
            resultArea.innerHTML = `
                <div class="result-card error-card" style="display: block;">
                    <div class="result-header">
                        <div class="result-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h3 class="result-title">เกิดข้อผิดพลาด</h3>
                        <p class="result-category">${message}</p>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="resetUpload()">
                            <i class="fas fa-redo"></i>
                            ลองใหม่อีกครั้ง
                        </button>
                    </div>
                </div>
            `;
        }

        function startCamera() {
  const video = document.getElementById('camera-preview');
  
  navigator.mediaDevices.getUserMedia({ video: true })
    .then(stream => {
      video.srcObject = stream;
      video.style.display = 'block'; // แสดงพรีวิวทันที
    })
    .catch(err => {
      console.error("Error accessing camera: ", err);
    });
}
        // Helpers to fetch and open per-waste modals (top-3)
        async function fetchWasteContentByName(name){
            const url = 'scan.php?action=content&waste=' + encodeURIComponent(name||'');
            const res = await fetch(url, { headers: { 'Accept':'application/json' } });
            try { return await res.json(); } catch(_) { return { success:false }; }
        }

        async function openManagementFor(name){
            const containerId = 'managementContent';
            const el = document.getElementById(containerId);
            if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
            showModal('managementModal');
            try{
                const data = await fetchWasteContentByName(name);
                if (data && data.success && Array.isArray(data.trash_content) && data.trash_content.length){
                    renderManagementCards(data.trash_content);
                } else {
                    showNoDataMessage(containerId, 'ไม่พบข้อมูลวิธีการจัดการ');
                }
            }catch(e){ showNoDataMessage(containerId, 'เกิดข้อผิดพลาดในการดึงข้อมูล'); }
        }

        async function openPostsFor(name){
            const containerId = 'postsContent';
            const el = document.getElementById(containerId);
            if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
            showModal('postsModal');
            try{
                const data = await fetchWasteContentByName(name);
                if (data && data.success && Array.isArray(data.recent_posts) && data.recent_posts.length){
                    renderUserPostsClean(data.recent_posts);
                } else {
                    showNoDataMessage(containerId, 'ยังไม่มีวิธีคัดแยกจากผู้ใช้');
                }
            }catch(e){ showNoDataMessage(containerId, 'เกิดข้อผิดพลาดในการดึงข้อมูล'); }
        }

        // Modal functions
        function showManagementModal() {
            if (!currentData?.database_content?.trash_content || currentData.database_content.trash_content.length === 0) {
                showNoDataMessage('managementContent', 'ไม่มีข้อมูลวิธีการจัดการขยะ');
            } else {
                renderManagementCards(currentData.database_content.trash_content);
            }
            showModal('managementModal');
        }

        function showPostsModal() {
            if (!currentData?.database_content?.recent_posts || currentData.database_content.recent_posts.length === 0) {
                showNoDataMessage('postsContent', 'ไม่มีโพสต์จากผู้ใช้');
            } else {
                renderUserPostsClean(currentData.database_content.recent_posts);
            }
            showModal('postsModal');
        }

        function showModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.add('show');
    document.body.classList.add('modal-open'); // เพิ่ม class เพื่อล็อค scroll
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.body.classList.remove('modal-open'); // เอา class ออก
    
    if (modalId === 'cropModal' && cropper) {
        cropper.destroy();
        cropper = null;
    }
}

        function showNoDataMessage(containerId, message) {
            document.getElementById(containerId).innerHTML = `
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>${message}</p>
                    <p class="text-sm mt-2">ขออภัย ยังไม่มีข้อมูลสำหรับขยะประเภทนี้ในฐานข้อมูล</p>
                </div>
            `;
        }

        function displayManagementContent(contents) {
            const path = "admin/";
            let html = '';
            
            contents.forEach(item => {
                // แก้ path รูปภาพใน item.content
                const fixedContent = item.content.replace(/<img\s+src="img\//g, '<img src="admin/img/');
                
                html += `
                    <div class="management-item">
                        <h4><i class="fas fa-leaf mr-2"></i>${item.Management_type}</h4>
                        <div class="prose">${fixedContent}</div>
                        ${item.image ? `<img src="${path}${item.image}" alt="วิธีจัดการขยะ">` : ''}
                    </div>
                `;
            });
            
            document.getElementById('managementContent').innerHTML = html;
        }

        function displayPostsContent(posts) {
            let html = '';
            
            posts.forEach(post => {
                let images = [];
                try {
                    images = JSON.parse(post.image || '[]');
                } catch (e) {
                    images = post.image ? [post.image] : [];
                }
                
                html += `
                    <div class="post-item">
                        <div class="post-header">
                            ${post.profile_img ? 
                                `<img src="img/profile_uploads/${post.profile_img}" alt="Avatar" class="post-avatar">` : 
                                '<div class="post-avatar" style="background: #e2e8f0; display: flex; align-items: center; justify-content: center;"><i class="fas fa-user text-gray-400"></i></div>'
                            }
                            <div class="post-user-info">
                                <h5>${post.name}</h5>
                                <p class="post-date">${new Date(post.date).toLocaleDateString('th-TH', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric'
                                })}</p>
                            </div>
                        </div>
                        
                        <h4 class="post-title">${post.title}</h4>
                        <p class="post-content">${post.details ? 
                            (post.details.length > 200 ? 
                                post.details.substring(0, 200) + '...' : 
                                post.details
                            ) : ''
                        }</p>
                        
                        ${images.length > 0 ? `
                            <div class="post-images">
                                ${images.map(img => 
                                    `<img src="img/post_img/${img}" alt="Post Image" class="post-image">`
                                ).join('')}
                            </div>
                        ` : ''}
                        
                        <div class="post-tag">
                            <i class="fas fa-tag mr-1"></i>
                            ${post.Management_type || 'ทั่วไป'}
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('postsContent').innerHTML = html;
        }

        // New enhanced renderers
        function renderManagementCards(contents){
            const path = 'admin/';
            let html = '';
            (contents||[]).forEach(item=>{
                const fixed = String(item.content||'').replace(/<img\s+src="img\//g, '<img src="admin/img/');
                const type = item.Management_type || '';
                const icon = (String(type).toLowerCase().includes('recycl')||String(type).includes('รีไซ')) ? 'fa-recycle' : (String(type).toLowerCase().includes('reuse') ? 'fa-redo' : 'fa-trash');
                html += `<div class="mg-card">
                    <div class="mg-head"><i class="fas ${icon}"></i><span>${type||'วิธีจัดการ'}</span><span class="mg-type-chip"><i class="fas fa-tag"></i> ${type||'-'}</span></div>
                    <div class="mg-content">${fixed}</div>
                    ${item.image ? `<img src="${path}${item.image}" alt="management-image">` : ''}
                </div>`;
            });
            const el = document.getElementById('managementContent'); if (el) el.innerHTML = html || '<div class="no-data"><i class="fas fa-info-circle"></i><p>ไม่มีข้อมูลวิธีจัดการ</p></div>';
        }

        function renderUserPosts(posts){
            const fmt = (d)=> new Date(d).toLocaleDateString('th-TH',{year:'numeric',month:'long',day:'numeric'});
            let html='';
            (posts||[]).forEach(post=>{
                const uid = post.userId || post.userID || '';
                let imgs=[]; try{ imgs = JSON.parse(post.image||'[]'); }catch{ imgs = post.image ? [post.image] : []; }
                html += `<div class="post-item">
                    <div class="post-header">
                        ${post.profile_img ? `<img src="img/profile_uploads/${post.profile_img}" alt="Avatar" class="post-avatar user-link" data-uid="${uid}">` : `<div class=\"post-avatar user-link\" data-uid=\"${uid}\" style=\"background:#e2e8f0;display:flex;align-items:center;justify-content:center;\"><i class=\"fas fa-user text-gray-400\"></i></div>`}
                        <div class="post-user-info">
                            <h5 class="user-link" data-uid="${uid}">${post.name||''}</h5>
                            <p class="post-date">${fmt(post.date)}</p>
                        </div>
                    </div>
                    <h4 class="post-title">${post.title||''}</h4>
                    <p class="post-content">${post.details ? (post.details.length>200 ? post.details.slice(0,200)+'...' : post.details) : ''}</p>
                    ${imgs.length? `<div class=\"post-images\">${imgs.map(img=>`<img src=\\\"img/post_img/${img}\\\" class=\\\"post-image\\\" alt=\\\"Post Image\\\">`).join('')}</div>`:''}
                    <div class="post-tag"><i class="fas fa-tag mr-1"></i>${post.Management_type||'-'}</div>
                </div>`;
            });
            const el = document.getElementById('postsContent'); if (el) el.innerHTML = html || '<div class="no-data"><i class="fas fa-info-circle"></i><p>ยังไม่มีโพสต์เกี่ยวข้อง</p></div>';
        }

        // Delegate click to open profile modal (from share1.php)
        (function(){ const root=document.getElementById('postsContent'); if(!root) return; root.addEventListener('click', (e)=>{ const t=e.target.closest('.user-link'); if(!t) return; const uid=t.getAttribute('data-uid'); if(uid && window.wasteManager && typeof window.wasteManager.openProfileModal==='function'){ window.wasteManager.openProfileModal(uid); } }); })();

        // Clean renderer to ensure correct image paths and markup
        function renderUserPostsClean(posts){
            const fmt = (d)=> new Date(d).toLocaleDateString('th-TH',{year:'numeric',month:'long',day:'numeric'});
            let html='';
            (posts||[]).forEach(post=>{
                const uid = post.userId || post.userID || '';
                let imgs=[]; try{ imgs = JSON.parse(post.image||'[]'); }catch{ imgs = post.image ? [post.image] : []; }
                const imagesHtml = imgs.map(im => '<img src="img/post_img/'+im+'" class="post-image" alt="Post Image">').join('');
                html += `
                <div class="post-item">
                    <div class="post-header">
                        ${post.profile_img ? `<img src="img/profile_uploads/${post.profile_img}" alt="Avatar" class="post-avatar user-link" data-uid="${uid}">` : `<div class="post-avatar user-link" data-uid="${uid}" style="background:#e2e8f0;display:flex;align-items:center;justify-content:center;"><i class="fas fa-user text-gray-400"></i></div>`}
                        <div class="post-user-info">
                            <h5 class="user-link" data-uid="${uid}">${post.name||''}</h5>
                            <p class="post-date">${fmt(post.date)}</p>
                        </div>
                    </div>
                    <h4 class="post-title">${post.title||''}</h4>
                    <p class="post-content">${post.details ? (post.details.length>200 ? post.details.slice(0,200)+'...' : post.details) : ''}</p>
                    ${imgs.length ? `<div class="post-images">${imagesHtml}</div>` : ''}
                    <div class="post-tag"><i class="fas fa-tag mr-1"></i>${post.Management_type||'-'}</div>
                </div>`;
            });
            const el = document.getElementById('postsContent');
            if (el) el.innerHTML = html || '<div class="no-data"><i class="fas fa-info-circle"></i><p>ยังไม่มีโพสต์เกี่ยวข้อง</p></div>';
        }
        // === Progressive search helpers + fallback (Google Places) ===
        function kmToMeters(km){ return Math.max(0, Math.floor(km * 1000)); }
        function haversineKm(lat1, lon1, lat2, lon2){
            const toRad = d => d * Math.PI/180;
            const R = 6371; // km
            const dLat = toRad(lat2-lat1);
            const dLon = toRad(lon2-lon1);
            const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon/2)**2;
            return 2 * R * Math.asin(Math.sqrt(a));
        }
        function getAltKeywords(category, baseKeyword){
            const c = String(category||'').toLowerCase();
            const set = new Set();
            if (baseKeyword) set.add(baseKeyword);
            if (c.includes('hazard') || c.includes('อันตราย')){
                ['กำจัดของเสียอันตราย','ศูนย์ของเสียอันตราย','โรงงานกำจัดของเสีย','hazardous waste'].forEach(s=>set.add(s));
            } else if (c.includes('recycle') || c.includes('รีไซเคิล')){
                ['ศูนย์รีไซเคิล','จุดรีไซเคิล','รับซื้อของเก่า','recycling center','ที่รับขยะรีไซเคิล'].forEach(s=>set.add(s));
            } else {
                ['สถานีขนถ่ายขยะ','ศูนย์กำจัดขยะ','จุดทิ้งขยะ','เทศบาล','municipal waste'].forEach(s=>set.add(s));
            }
            return Array.from(set);
        }
        function googlePlacesSearch(location, query, radiusM){
            // Map UI removed -> disable Google Places fallback
            return Promise.resolve([]);
        }

        // Overpass-based renderer (no Google/Backend required)
        async function fetchNearbyAndRenderV3(){
            const placesListDiv = document.getElementById('placesList');
            markers = [];
            placesListDiv.innerHTML = `
                <div class=\"loading-container\">\n      <div class=\"loading-spinner\" style=\"width:40px;height:40px;\"></div>\n      <p class=\"mt-3 text-gray-600\">กำลังค้นหาสถานที่...</p>\n    </div>`;
            try {
                const radiiKm = [30, 50, 75, 100, 120];
                let items = [];
                let usedRadius = radiiKm[0];
                for (const r of radiiKm){
                    items = await overpassFetchCenters(modalState.lat, modalState.lng, r, modalState.category||'');
                    if (items && items.length){ usedRadius = r; break; }
                }
                let html = '';
                if (!items || !items.length){
                    html = `
                        <div class=\"no-places\">\n          <i class=\"fas fa-map-marker-alt no-places-icon\"></i>\n          <p>ไม่พบศูนย์จัดการขยะในระยะ 30–120 กม. ลองค้นหาด้วยคำอื่น</p>\n        </div>`;
                } else {
                    items.forEach(place => {
                        const name = place.center_name || 'ศูนย์จัดการขยะ';
                        const la = parseFloat(place.lat);
                        const ln = parseFloat(place.lng);
                        const addr = place.address || '';
                        const distTxt = place.distance ? `${place.distance} กม.` : '';
                        const link = place.maps_link || (Number.isFinite(la) && Number.isFinite(ln) ? `https://www.openstreetmap.org/?mlat=${la}&mlon=${ln}#map=16/${la}/${ln}` : '#');
                        html += `
                            <div class=\"place-item\" data-lat=\"${la}\" data-lng=\"${ln}\">\n            <div class=\"place-header\">\n              <h4 class=\"place-name\">${name}</h4>\n              <span class=\"place-distance\">${distTxt}</span>\n            </div>\n            <p class=\"place-address\">${addr}</p>\n            <a href=\"${link}\" target=\"_blank\" class=\"text-blue-500 text-sm mt-2 inline-block\">\n              เปิด OpenStreetMap\n            </a>\n          </div>`;
                    });
                    html = `<div class=\"text-xs text-gray-500 mb-2\">ระยะค้นหาที่ใช้ ~${usedRadius} กม.</div>` + html;
                }
                placesListDiv.innerHTML = html;
                document.querySelectorAll('.place-item').forEach(el => {
                    el.addEventListener('click', () => {
                        const a = el.querySelector('a[target=\"_blank\"]');
                        if (a && a.href) window.open(a.href, '_blank');
                    });
                });
            } catch (err) {
                console.error(err);
                placesListDiv.innerHTML = `<div class=\"text-center text-red-500 p-4\">เกิดข้อผิดพลาดในการค้นหา</div>`;
            }
        }
        async function overpassFetchCenters(lat, lng, radiusKm, categoryLabel){
            const radiusM = Math.max(100, Math.round(radiusKm * 1000));
            const overpassUrl = 'https://overpass-api.de/api/interpreter';
            const q = `
                [out:json][timeout:25];
                (
                  node["amenity"="recycling"](around:${radiusM},${lat},${lng});
                  way["amenity"="recycling"](around:${radiusM},${lat},${lng});
                  relation["amenity"="recycling"](around:${radiusM},${lat},${lng});
                  node["amenity"="waste_disposal"](around:${radiusM},${lat},${lng});
                  way["amenity"="waste_disposal"](around:${radiusM},${lat},${lng});
                  relation["amenity"="waste_disposal"](around:${radiusM},${lat},${lng});
                );
                out center tags;`;
            const res = await fetch(overpassUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: 'data=' + encodeURIComponent(q)
            });
            const data = await res.json();
            const elements = Array.isArray(data.elements) ? data.elements : [];
            const isHazardous = (tags) => {
                if (!tags) return false;
                const keys = [
                  'recycling:hazardous_waste','recycling:batteries','recycling:electronics',
                  'recycling:oil','recycling:paint','recycling:chemicals','recycling:toxic'
                ];
                return keys.some(k => String(tags[k]||'').toLowerCase() === 'yes');
            };
            const normalize = (el) => {
                const tags = el.tags || {};
                const la = (typeof el.lat === 'number') ? el.lat : (el.center ? el.center.lat : null);
                const ln = (typeof el.lon === 'number') ? el.lon : (el.center ? el.center.lon : null);
                const amenity = (tags.amenity || '').toLowerCase();
                let type = (amenity === 'recycling') ? 'recycle' : 'general';
                if (amenity === 'recycling' && isHazardous(tags)) type = 'hazardous';
                const distKm = (Number.isFinite(la) && Number.isFinite(ln)) ? haversineKm(lat, lng, la, ln) : null;
                // Prefer Thai → English → operator/brand → default
                const displayName = tags['name:th'] || tags['name:en'] || tags['int_name'] ||
                                    tags['operator:th'] || tags['operator:en'] || tags['operator'] ||
                                    tags['brand:th'] || tags['brand:en'] || tags['brand'] ||
                                    tags.name || 'ศูนย์จัดการขยะ';
                const addrParts = [];
                const addIf = (v) => { if (v) addrParts.push(v); };
                addIf(tags['addr:housename']);
                addIf(tags['addr:housenumber']);
                addIf(tags['addr:street']);
                addIf(tags['addr:subdistrict'] || tags['addr:suburb']);
                addIf(tags['addr:district'] || tags['addr:county']);
                addIf(tags['addr:city'] || tags['addr:town'] || tags['addr:village']);
                addIf(tags['addr:province'] || tags['addr:state']);
                addIf(tags['addr:postcode']);
                const address = tags['addr:full'] || addrParts.join(', ');
                return {
                    center_name: displayName,
                    lat: la,
                    lng: ln,
                    address: address || '',
                    rating: null,
                    distance: distKm !== null ? distKm.toFixed(1) : null,
                    maps_link: (Number.isFinite(la) && Number.isFinite(ln)) ? `https://www.openstreetmap.org/?mlat=${la}&mlon=${ln}#map=16/${la}/${ln}` : '#',
                    type
                };
            };
            let items = elements.map(normalize).filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lng));
            const kw = String(categoryLabel||'').trim();
          if (kw.includes('อันตราย')) {              // อันตราย
            items = items.filter(p => p.type === 'hazardous');
            } else if (kw.includes('รีไซเคิล')) {      // รีไซเคิล
            items = items.filter(p => p.type === 'recycle' || p.type === 'hazardous');
            } else if (kw.includes('ทั่วไป')) {        // ทั่วไป
            items = items.filter(p => p.type === 'general');
            }
            items.sort((a,b) => (parseFloat(a.distance||'999') - parseFloat(b.distance||'999')));
            return items;
        }
        async function fetchNearbyAndRenderV2(){
            const placesListDiv = document.getElementById('placesList');
            markers.forEach(m => m.setMap(null));
            markers = [];
            placesListDiv.innerHTML = `
                <div class=\"loading-container\">\n      <div class=\"loading-spinner\" style=\"width:40px;height:40px;\"></div>\n      <p class=\"mt-3 text-gray-600\">กำลังค้นหาสถานที่...</p>\n    </div>`;
            try {
                const radiiKm = [30, 50, 75, 100, 120];
                let items = [];
                let usedRadius = radiiKm[0];
                let source = 'backend';
                for (const r of radiiKm){
                    const url = `http://localhost:8000/nearby_center` +
                        `?lat=${modalState.lat}&lng=${modalState.lng}` +
                        `&radius=${r}` +
                        `&keyword=${encodeURIComponent(modalState.keyword || '')}` +
                        `&category=${encodeURIComponent(modalState.category || '')}`;
                    try{
                        const res = await fetch(url);
                        const data = await res.json();
                        const arr = Array.isArray(data.centers) ? data.centers : (Array.isArray(data.places) ? data.places : []);
                        items = arr.map(p => ({
                          center_name: p.center_name || p.name || 'ศูนย์จัดการขยะ',
                          lat: Number(p.lat),
                          lng: Number(p.lng),
                          address: p.address || p.vicinity || '',
                          rating: p.rating || null,
                          distance: p.distance || null,
                          maps_link: p.maps_link || ((Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lng)))
                            ? `https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lng}#map=16/${p.lat}/${p.lng}`
                            : '#'),
                          type: p.type || 'general',
                        }));
                    }catch(_){ items = []; }
                    if (items && items.length){ usedRadius = r; break; }
                }
                // Do not fallback to Google Places (map removed)
                let html = '';
                if (!items || !items.length){
                    const q = encodeURIComponent(modalState.keyword || 'ศูนย์กำจัดขยะ');
                    const gmapsLink = `https://www.google.com/maps/search/?api=1&query=${q}`;
                    html = `
                        <div class=\"no-places\">\n          <i class=\"fas fa-map-marker-alt no-places-icon\"></i>\n          <p>ไม่พบศูนย์จัดการขยะในระยะ 30–120 กม. รอบตำแหน่งของคุณ</p>\n          <p class=\"text-sm text-gray-500\">ลองเปิดใน Google Maps</p>\n          <a class=\"btn btn-primary mt-3\" href=\"${gmapsLink}\" target=\"_blank\"><i class=\"fas fa-external-link-alt mr-1\"></i> เปิดใน Google Maps</a>\n        </div>`;
                } else {
                    items.forEach(place => {
                        const name = place.center_name || place.name || 'ไม่ทราบชื่อ';
                        const la = parseFloat(place.lat);
                        const ln = parseFloat(place.lng);
                        const addr = place.address || place.vicinity || '';
                        const rating = place.rating ? `${place.rating} ★` : 'ไม่มีคะแนน';
                        const distTxt = place.distance ? `${place.distance} กม.` : '';
                        const link = place.maps_link || (Number.isFinite(la) && Number.isFinite(ln) ? `https://www.google.com/maps/search/?api=1&query=${la},${ln}` : '#');
                        html += `
                            <div class=\"place-item\" data-lat=\"${la}\" data-lng=\"${ln}\">\n            <div class=\"place-header\">\n              <h4 class=\"place-name\">${name}</h4>\n              <span class=\"place-distance\">${distTxt}</span>\n            </div>\n            <p class=\"place-address\">${addr}</p>\n            <div class=\"place-rating\">\n              <i class=\"fas fa-star star-icon\"></i>\n              ${rating}\n            </div>\n            <a href=\"${link}\" target=\"_blank\" class=\"text-blue-500 text-sm mt-2 inline-block\">\n              เปิด Google Maps\n            </a>\n          </div>`;
                        const tag = (place.type || '').toLowerCase();
                        const iconUrl = tag === 'hazardous' ? 'https://maps.google.com/mapfiles/ms/icons/orange-dot.png' : tag === 'recycle' ? 'https://maps.google.com/mapfiles/ms/icons/green-dot.png' : 'https://maps.google.com/mapfiles/ms/icons/blue-dot.png';
                        if (Number.isFinite(la) && Number.isFinite(ln)){
                            const marker = new google.maps.Marker({ position: { lat: la, lng: ln }, map, title: name, icon: { url: iconUrl } });
                            const info = new google.maps.InfoWindow({ content: `
                                <div style=\"max-width:260px\">\n                <strong>${name}</strong><br/>\n                <small>${addr}</small><br/>\n                <em>${distTxt ? `ระยะทาง ~ ${distTxt}` : ''}</em><br/>\n                <a href=\"${link}\" target=\"_blank\">เปิด Google Maps</a>\n              </div>` });
                            marker.addListener('click', () => info.open({ anchor: marker, map }));
                            markers.push(marker);
                        }
                    });
                    html = `<div class=\"text-xs text-gray-500 mb-2\">ค้นหาด้วยรัศมี ~${usedRadius} กม. (${source === 'backend' ? 'ฐานข้อมูลระบบ' : 'Google Places'})</div>` + html;
                }
                placesListDiv.innerHTML = html;
                document.querySelectorAll('.place-item').forEach(el => {
                    el.addEventListener('click', () => {
                        const a = el.querySelector('a[target="_blank"]');
                        if (a && a.href) window.open(a.href, '_blank');
                    });
                });
            } catch (err) {
                console.error(err);
                placesListDiv.innerHTML = `<div class=\"text-center text-red-500 p-4\">เกิดข้อผิดพลาดในการค้นหา</div>`;
            }
        }

        // Close modal when clicking outside
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                    document.body.style.overflow = '';
                });
            }
        });
    </script>
    <div id="mapModal" class="modal">
    <div class="map-modal-content">
        <div class="map-modal-header">
            <h5 class="map-modal-title">📍 ศูนย์จัดการขยะใกล้ฉัน</h5>
            
            <button type="button" class="map-modal-close" onclick="closeModal('mapModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
            <div class="map-modal-body">
            <div class="places-container" style="width:100%">
                <h4 class="text-lg font-semibold mb-4 text-gray-700">
                    <i class="fas fa-search mr-2"></i>
                    ผลการค้นหาสำหรับ: <span id="modalKeyword" class="text-green-600"></span>
                </h4>
                <div id="placesList"></div>
            </div>
        </div>
    </div>
</div>
    
<script>
// ----- โมเดลที่ใช้ในการสแกน: ตัวเลือกแบบฝังหน้า -----
(function(){
  const FASTAPI_BASE = 'http://127.0.0.1:8000';
  const ADMIN_TOKEN  = (window.FASTAPI_ADMIN_TOKEN || 'changeme');
  function el(tag, attrs, children){
    const e = document.createElement(tag);
    if (attrs) Object.entries(attrs).forEach(([k,v])=>{ if(k==='style'&&typeof v==='object'){ Object.assign(e.style,v); } else if(k==='class') { e.className=v; } else { e.setAttribute(k,v); } });
    (children||[]).forEach(c=> e.appendChild(typeof c==='string' ? document.createTextNode(c) : c));
    return e;
  }
  async function refreshModelSelector(sel, info){
    try{
      const r = await fetch(`${FASTAPI_BASE}/models`);
      const d = await r.json();
      if(!d || !Array.isArray(d.items)) return;
      sel.innerHTML = d.items.map(it=>`<option value="${it.id}" ${String(d.active||'').includes(it.weights)?'selected':''}>${it.id}</option>`).join('');
      info.textContent = d.active ? `ใช้งาน: ${d.active.split('/').slice(-2).join('/')}` : '';
    }catch(e){}
  }
  async function switchModel(id){
    const fd = new FormData(); fd.append('id', id); fd.append('token', ADMIN_TOKEN);
    await fetch(`${FASTAPI_BASE}/models/load`, { method:'POST', body: fd });
  }
  function mount(){
    const header = document.querySelector('.header');
    if(!header) return;
    const wrap = el('div', {style:{marginTop:'10px',display:'flex',gap:'8px',alignItems:'center',justifyContent:'center'}});
    const label = el('span',null,['โมเดล:']); label.style.fontWeight='700';
    const select = el('select', {class:'btn btn-secondary'});
    select.style.background = '#0ea5a0'; select.style.color='#fff'; select.style.border='none';
    const info = el('small', {style:{opacity:'.9'}});
    wrap.appendChild(label); wrap.appendChild(select); wrap.appendChild(info);
    header.appendChild(wrap);
    select.addEventListener('change', (e)=>{ switchModel(e.target.value).then(()=> refreshModelSelector(select, info)); });
    refreshModelSelector(select, info);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
</script>

<script>
// Enhance names in places list: synthesize from address when generic
(function(){
  function enhance() {
    const root = document.getElementById('placesList');
    if (!root) return;
    root.querySelectorAll('.place-item').forEach(item => {
      const nameEl = item.querySelector('.place-name');
      const addrEl = item.querySelector('.place-address');
      if (!nameEl) return;
      const current = (nameEl.textContent||'').trim();
      if (!current || /^ศูนย์จัดการ(ขยะ)?$/.test(current)) {
        const addr = (addrEl?.textContent||'').trim();
        const locality = addr ? addr.split(',')[0].trim() : '';
        const base = 'จุดจัดการขยะ';
        nameEl.textContent = locality ? `${base} – ${locality}` : base;
      }
    });
  }
  const list = document.getElementById('placesList');
  if (list) {
    enhance();
    const mo = new MutationObserver(() => enhance());
    mo.observe(list, { childList: true, subtree: true });
  }
})();
</script>
<script>
// Remove model selector UI (admin controls model in admin panel)
(function(){
  function removeUI(){
    try{
      const header = document.querySelector('.header');
      if(!header) return;
      const selects = header.querySelectorAll('select.btn.btn-secondary');
      selects.forEach(sel=>{ const wrap = sel.parentElement; if(wrap && wrap.parentElement===header) wrap.remove(); else sel.remove(); });
    }catch(e){}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ()=> setTimeout(removeUI, 60));
  else setTimeout(removeUI, 60);
})();
// expose profile modal helper from share page module if available
</script>
<script type="module">
import wasteManager from './waste-management.js';
window.wasteManager = wasteManager;
</script>

<script>
// Enhance empty-state messages to include waste name with emphasis
(function(){
  function htmlesc(s){
    return String(s==null?'':s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/\"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }
  if (typeof window.showNoDataMessage === 'function'){
    const orig = window.showNoDataMessage;
    window.showNoDataMessage = function(containerId, message, wasteName){
      const name = (wasteName || (window.currentData && window.currentData.database_content && window.currentData.database_content.predicted_waste_name) || '').trim();
      const detail = name
        ? `ขออภัย ยังไม่มีข้อมูลสำหรับ "<strong>${htmlesc(name)}</strong>"`
        : 'ขออภัย ยังไม่มีข้อมูลที่เกี่ยวข้องในฐานข้อมูล';
      const el = document.getElementById(containerId);
      if (el) {
        el.innerHTML = `
          <div class="no-data">
            <i class="fas fa-info-circle"></i>
            <p>${message}</p>
            <p class="text-sm mt-2">${detail}</p>
          </div>`;
      }
    };
  }
  if (typeof window.openPostsFor === 'function'){
    const __origOpenPostsFor = window.openPostsFor;
    window.openPostsFor = function(name){
      window.__lastRequestedWasteName = name;
      return __origOpenPostsFor.apply(this, arguments);
    };
  }
})();
</script>

<script>
// Final override to ensure readable Thai messages and bold waste name
(function(){
  function esc(s){
    return String(s==null?'':s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/\"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }
  window.showNoDataMessage = function(containerId, message, wasteName){
    const name = (wasteName || window.__lastRequestedWasteName || (window.currentData && window.currentData.database_content && window.currentData.database_content.predicted_waste_name) || '').trim();
    const msg = (containerId === 'postsContent') ? 'ยังไม่มีวิธีคัดแยกจากผู้ใช้' : (message || 'ยังไม่มีข้อมูล');
    const detail = name ? `ขออภัย ยังไม่มีข้อมูลสำหรับ "<strong>${esc(name)}</strong>"` : 'ขออภัย ยังไม่มีข้อมูลที่เกี่ยวข้องในฐานข้อมูล';
    const el = document.getElementById(containerId);
    if (el) {
      el.innerHTML = `<div class="no-data"><i class="fas fa-info-circle"></i><p>${msg}</p><p class="text-sm mt-2">${detail}</p></div>`;
    }
  };
  // Tweak static modal titles to desired wording
  try{
    const mTitle = document.querySelector('#managementModal .modal-title');
    if (mTitle) mTitle.innerHTML = '<i class="fas fa-recycle mr-2"></i> วิธีการจัดการขยะ';
    const pTitle = document.querySelector('#postsModal .modal-title');
    if (pTitle) pTitle.innerHTML = '<i class="fas fa-users mr-2"></i> วิธีการจัดการจากผู้ใช้';
  }catch(_){}
})();
</script>

<script>
// Video-aware post renderer for posts modal
(function(){
  function getEmbedUrl(platform, url){
    try{ platform = String(platform||'').toLowerCase(); }catch{ platform=''; }
    if (!url) return null;
    if (platform === 'youtube'){
      const m = String(url).match(/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{6,})/);
      return m ? `https://www.youtube.com/embed/${m[1]}` : null;
    }
    if (platform === 'instagram'){
      const m = String(url).match(/instagram\.com\/(p|reel|tv)\/([A-Za-z0-9_-]+)/);
      return m ? `https://www.instagram.com/${m[1]}/${m[2]}/embed` : null;
    }
    if (platform === 'facebook'){
      return `https://www.facebook.com/plugins/video.php?href=${encodeURIComponent(url)}&show_text=0`;
    }
    return null;
  }

  function buildVideoHtml(post){
    const vs = String(post?.video_source||'none').toLowerCase();
    if (vs === 'url' && post?.video_url){
      const plat = String(post?.video_platform||'').toLowerCase();
      if (plat === 'tiktok'){
        const m = String(post.video_url||'').match(/\/video\/(\d+)/);
        if (m && m[1]){
          return `<div class="video-embed"><iframe src="https://www.tiktok.com/embed/v2/video/${m[1]}" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>`;
        }
        return '';
      }
      const src = getEmbedUrl(plat, post.video_url);
      if (src){
        return `<div class="video-embed"><iframe src="${src}" allowfullscreen loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe></div>`;
      }
      return '';
    }
    if (vs === 'file' && post?.video_path){
      const path = `uploads/videos/${post.video_path}`;
      return `<div class="video-embed"><video controls preload="metadata" src="${path}"><source src="${path}" type="video/mp4">ไม่สามารถเล่นวิดีโอได้</video></div>`;
    }
    return '';
  }

  // Profile modal opener
  window.openUserProfile = async function(userId){
    try{
      const modal = document.getElementById('profileModal');
      const body  = document.getElementById('profileContent');
      if (body) body.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
      showModal('profileModal');

      const r = await fetch('get_user_profile.php?userId=' + encodeURIComponent(userId));
      const data = await r.json();
      const u = data && data.user ? data.user : null;
      const posts = (data && Array.isArray(data.posts)) ? data.posts : [];

      const avatar = (u && u.profile_img) ? ('img/profile_uploads/' + u.profile_img) : 'img/profile_uploads/default.jpg';
      const head = `
        <div class="flex items-center mb-4">
          <img src="${avatar}" onerror="this.src='img/profile_uploads/default.jpg'" class="w-12 h-12 rounded-full object-cover mr-3" alt="avatar" />
          <div>
            <div class="font-semibold">${u ? (u.name || '-') : '-'}</div>
            <div class="text-xs text-gray-500">โพสต์ทั้งหมด: ${u ? (u.post_count || 0) : 0}</div>
          </div>
        </div>`;

      const cards = posts.map(p => {
        const images = Array.isArray(p.images) ? p.images : [];
        const hasVideo = (String(p.video_source||'') === 'url' && p.video_url) || (String(p.video_source||'') === 'file' && p.video_path);
        let mediaHtml = '';
        if (hasVideo){ mediaHtml = buildVideoHtml(p) || ''; }
        else if (images.length){ mediaHtml = `<img src="img/post_img/${images[0]}" class="w-full h-48 object-cover rounded" style="width: 100%; height: 100%;" alt="">`; }
        else { mediaHtml = `<div class="w-full h-48 bg-gray-100 flex items-center justify-center text-gray-400 rounded"><i class="fas fa-image text-3xl"></i></div>`; }

        let badge = '';
        if (hasVideo && images.length>0){ badge = `<div class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1">+${images.length} รูป</div>`; }
        else if (!hasVideo && images.length>1){ badge = `<div class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1">+${images.length-1} รูป</div>`; }
        const mediaWrap = (hasVideo || images.length) ? `<div class="relative">${mediaHtml}${badge}</div>` : mediaHtml;
        const title = (p.title||'').toString();
        return `<div class="share-card mb-4 p-3 rounded border bg-white">${mediaWrap}<div class="mt-2 font-semibold">${title}</div></div>`;
      });

      if (body) body.innerHTML = head + (cards.length ? cards.join('') : '<div class="no-data"><i class="fas fa-info-circle"></i><p>ไม่มีโพสต์</p></div>');
    }catch(e){
      try{ document.getElementById('profileContent').innerHTML = '<div class="no-data"><i class="fas fa-info-circle"></i><p>ไม่สามารถโหลดโปรไฟล์ได้</p></div>'; }catch(_){ }
      console.warn('openUserProfile failed', e);
    }
  }

  function parseImages(raw){
    if (!raw) return [];
    try{ const arr = JSON.parse(raw); return Array.isArray(arr) ? arr.filter(Boolean) : []; }catch{ return []; }
  }

  window.renderUserPostsClean = function(posts){
    const el = document.getElementById('postsContent');
    if (!el) return;
    const cards = [];
    try{
      (posts||[]).forEach(p => {
        const images = parseImages(p.image);
        const hasVideo = (String(p.video_source||'') === 'url' && p.video_url) || (String(p.video_source||'') === 'file' && p.video_path);
        let mediaHtml = '';
        if (hasVideo){
          mediaHtml = buildVideoHtml(p) || '';
        } else if (images.length){
          mediaHtml = `<img src="img/post_img/${images[0]}" class="w-full h-48 object-cover rounded" style="width: 100%; height: 100%;" alt="">`;
        } else {
          mediaHtml = `<div class="w-full h-48 bg-gray-100 flex items-center justify-center text-gray-400 rounded"><i class="fas fa-image text-3xl"></i></div>`;
        }
        let badge = '';
        if (hasVideo && images.length>0){ badge = `<div class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1">+${images.length} รูป</div>`; }
        else if (!hasVideo && images.length>1){ badge = `<div class="absolute bottom-2 right-2 bg-black bg-opacity-50 text-white text-xs rounded-full px-2 py-1">+${images.length-1} รูป</div>`; }

        const mediaWrap = (hasVideo || images.length)
          ? `<div class="relative">${mediaHtml}${badge}</div>`
          : mediaHtml;

        const title = (p.title||'').toString();
        const username = (p.name||p.username||'').toString();
        const uid = p.userId || p.userID || '';
        const avatar = (p.profile_img||p.avatar_path||'').trim();
        const avatarSrc = avatar ? `img/profile_uploads/${avatar}` : 'img/profile_uploads/default.jpg';
        const profileOpen  = uid ? `<a href="admin/user_detail.php?id=${uid}" target="_blank" class="flex items-center">` : `<div class="flex items-center">`;
        const profileClose = uid ? `</a>` : `</div>`;
        const allPostsLink = uid ? `<a href="admin/user_posts.php?id=${uid}" target="_blank" class="text-green-600 hover:text-green-800 text-sm"><i class="fas fa-list mr-1"></i>ดูโพสต์ทั้งหมด</a>` : '';
        const card = `
          <div class="share-card mb-4 p-3 rounded border bg-white">
            ${mediaWrap}
            <div class="mt-3 flex items-center justify-between">
              ${profileOpen}
                <img src="${avatarSrc}" onerror="this.src='img/profile_uploads/default.jpg'" class="w-9 h-9 rounded-full object-cover mr-3" alt="avatar" />
                <div class="text-sm font-medium text-gray-800">${username||'-'}</div>
              ${profileClose}
              ${allPostsLink}
            </div>
            <div class="mt-2">
              <div class="font-semibold">${title}</div>
            </div>
          </div>`;
        cards.push(card);
      });
    } catch(e){ console.warn('renderUserPostsClean failed', e); }
    el.innerHTML = (cards.length ? cards.join('') : '<div class="no-data"><i class="fas fa-info-circle"></i><p>ไม่พบโพสต์ล่าสุด</p></div>');

    try {
      el.querySelectorAll('a[href^="admin/user_detail.php"]').forEach(a => {
        const uid = new URL(a.getAttribute('href'), location.href).searchParams.get('id');
        a.addEventListener('click', (e)=>{ e.preventDefault(); if (uid) openUserProfile(uid); });
      });
      el.querySelectorAll('a[href^="admin/user_posts.php"]').forEach(a => {
        const uid = new URL(a.getAttribute('href'), location.href).searchParams.get('id');
        a.addEventListener('click', (e)=>{ e.preventDefault(); if (uid) openUserProfile(uid); });
      });
    } catch(_){}
  };
})();
</script>

<script>
// Override handlers so modal always reflects the last clicked waste name
(function(){
  const esc = (s)=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  async function fetchBy(name){
    try {
      const r = await fetch('scan.php?action=content&waste=' + encodeURIComponent(name||''), { headers:{'Accept':'application/json'} });
      return await r.json();
    } catch { return {success:false}; }
  }

  window.openManagementFor = async function(name){
    window.__lastRequestedWasteName = name;
    const el = document.getElementById('managementContent');
    if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
    try {
      const t = document.querySelector('#managementModal .modal-title');
      if (t) t.innerHTML = '<i class="fas fa-recycle mr-2"></i> วิธีการจัดการขยะ: <span class="font-semibold">'+esc(name)+'</span>';
    } catch {}
    showModal('managementModal');
    const data = await fetchBy(name);
    if (data && data.success && Array.isArray(data.trash_content) && data.trash_content.length){
      renderManagementCards(data.trash_content);
    } else {
      showNoDataMessage('managementContent', 'ยังไม่มีข้อมูลวิธีการจัดการขยะ', name);
    }
  };

  const originalOpenPostsFor = window.openPostsFor;
  window.openPostsFor = async function(name){
    window.__lastRequestedWasteName = name;
    const el = document.getElementById('postsContent');
    if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
    try {
      const t = document.querySelector('#postsModal .modal-title');
      if (t) t.innerHTML = '<i class="fas fa-users mr-2"></i> วิธีการจัดการจากผู้ใช้: <span class="font-semibold">'+esc(name)+'</span>';
    } catch {}
    showModal('postsModal');
    const data = await fetchBy(name);
    if (data && data.success && Array.isArray(data.recent_posts) && data.recent_posts.length){
      renderUserPostsClean(data.recent_posts);
    } else {
      showNoDataMessage('postsContent', 'ยังไม่มีวิธีคัดแยกจากผู้ใช้', name);
    }
  };
  // Rebind newly created buttons to the overridden handlers
  try{
    document.querySelectorAll('.open-management-btn').forEach(btn=>{
      btn.addEventListener('click', (e)=>{ e.preventDefault(); window.openManagementFor(btn.dataset.waste || ''); });
    });
    document.querySelectorAll('.open-posts-btn').forEach(btn=>{
      btn.addEventListener('click', (e)=>{ e.preventDefault(); window.openPostsFor(btn.dataset.waste || ''); });
    });
  }catch{}
})();
</script>

</body>
</html>
<?php
}
?>
<?php /* trimmed stray duplicated scripts below by agent */ ?>


<script>
// Override handlers so modal always reflects the last clicked waste name
(function(){
  const esc = (s)=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  async function fetchBy(name){
    try {
      const r = await fetch('scan.php?action=content&waste=' + encodeURIComponent(name||''), { headers:{'Accept':'application/json'} });
      return await r.json();
    } catch { return {success:false}; }
  }

  window.openManagementFor = async function(name){
    window.__lastRequestedWasteName = name;
    const el = document.getElementById('managementContent');
    if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
    try {
      const t = document.querySelector('#managementModal .modal-title');
      if (t) t.innerHTML = '<i class="fas fa-recycle mr-2"></i> วิธีการจัดการขยะ: <span class="font-semibold">'+esc(name)+'</span>';
    } catch {}
    showModal('managementModal');
    const data = await fetchBy(name);
    if (data && data.success && Array.isArray(data.trash_content) && data.trash_content.length){
      renderManagementCards(data.trash_content);
    } else {
      showNoDataMessage('managementContent', 'ยังไม่มีข้อมูลวิธีการจัดการขยะ', name);
    }
  };

  const originalOpenPostsFor = window.openPostsFor;
  window.openPostsFor = async function(name){
    window.__lastRequestedWasteName = name;
    const el = document.getElementById('postsContent');
    if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
    try {
      const t = document.querySelector('#postsModal .modal-title');
      if (t) t.innerHTML = '<i class="fas fa-users mr-2"></i> วิธีการจัดการจากผู้ใช้: <span class="font-semibold">'+esc(name)+'</span>';
    } catch {}
    showModal('postsModal');
    const data = await fetchBy(name);
    if (data && data.success && Array.isArray(data.recent_posts) && data.recent_posts.length){
      renderUserPostsClean(data.recent_posts);
    } else {
      showNoDataMessage('postsContent', 'ยังไม่มีวิธีคัดแยกจากผู้ใช้', name);
    }
  };
  // Rebind newly created buttons to the overridden handlers
  try{
    document.querySelectorAll('.open-management-btn').forEach(btn=>{
      btn.addEventListener('click', (e)=>{ e.preventDefault(); window.openManagementFor(btn.dataset.waste || ''); });
    });
    document.querySelectorAll('.open-posts-btn').forEach(btn=>{
      btn.addEventListener('click', (e)=>{ e.preventDefault(); window.openPostsFor(btn.dataset.waste || ''); });
    });
  }catch{}
})();
</script>

</body>
</html>


<script>
// Override handlers so modal always reflects the last clicked waste name
(function(){
  const esc = (s)=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  async function fetchBy(name){
    try {
      const r = await fetch('scan.php?action=content&waste=' + encodeURIComponent(name||''), { headers:{'Accept':'application/json'} });
      return await r.json();
    } catch { return {success:false}; }
  }

  window.openManagementFor = async function(name){
    window.__lastRequestedWasteName = name;
    const el = document.getElementById('managementContent');
    if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
    try {
      const t = document.querySelector('#managementModal .modal-title');
      if (t) t.innerHTML = '<i class="fas fa-recycle mr-2"></i> วิธีการจัดการขยะ: <span class="font-semibold">'+esc(name)+'</span>';
    } catch {}
    showModal('managementModal');
    const data = await fetchBy(name);
    if (data && data.success && Array.isArray(data.trash_content) && data.trash_content.length){
      renderManagementCards(data.trash_content);
    } else {
      showNoDataMessage('managementContent', 'ยังไม่มีข้อมูลวิธีการจัดการขยะ', name);
    }
  };

  const originalOpenPostsFor = window.openPostsFor;
  window.openPostsFor = async function(name){
    window.__lastRequestedWasteName = name;
    const el = document.getElementById('postsContent');
    if (el) el.innerHTML = '<div class="loading-container"><div class="loading-spinner" style="width:40px;height:40px;"></div><p class="mt-3 text-gray-600">กำลังโหลด...</p></div>';
    try {
      const t = document.querySelector('#postsModal .modal-title');
      if (t) t.innerHTML = '<i class="fas fa-users mr-2"></i> วิธีการจัดการจากผู้ใช้: <span class="font-semibold">'+esc(name)+'</span>';
    } catch {}
    showModal('postsModal');
    const data = await fetchBy(name);
    if (data && data.success && Array.isArray(data.recent_posts) && data.recent_posts.length){
      renderUserPostsClean(data.recent_posts);
    } else {
      showNoDataMessage('postsContent', 'ยังไม่มีวิธีคัดแยกจากผู้ใช้', name);
    }
  };
  // Rebind newly created buttons to the overridden handlers
  try{
    document.querySelectorAll('.open-management-btn').forEach(btn=>{
      btn.addEventListener('click', (e)=>{ e.preventDefault(); window.openManagementFor(btn.dataset.waste || ''); });
    });
    document.querySelectorAll('.open-posts-btn').forEach(btn=>{
      btn.addEventListener('click', (e)=>{ e.preventDefault(); window.openPostsFor(btn.dataset.waste || ''); });
    });
  }catch{}
})();
</script>

</body>
</html>

