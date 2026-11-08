<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../api_client.php';

// ไม่เช็คสถานะแอดมินที่หน้านี้ (ให้สิทธิ์เมื่อเข้าถึงได้แล้ว)

// ตั้งค่า FastAPI และโทเคนแอดมิน (ควรเปลี่ยนให้ตรงกับฝั่ง backend MAIN)
$FASTAPI_BASE = rtrim(api_base_url(), '/');
// หมายเหตุ: ให้ตั้งค่า ADMIN_TOKEN เดียวกับฝั่ง FastAPI (ENV ADMIN_TOKEN)
$FASTAPI_ADMIN_TOKEN = getenv('FASTAPI_ADMIN_TOKEN') ?: 'changeme';

?>
<?php
// --- AJAX endpoint: save label -> waste_type mapping ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_label_type') {
    header('Content-Type: application/json; charset=utf-8');
    // match the same admin token used for FastAPI admin
    $token = $_POST['token'] ?? '';
    $admin_tok = getenv('FASTAPI_ADMIN_TOKEN') ?: 'changeme';
    if (!hash_equals($admin_tok, $token)) {
        echo json_encode(['success'=>false, 'detail'=>'invalid token'], JSON_UNESCAPED_UNICODE); exit;
    }
    $label = trim($_POST['label'] ?? '');
    $waste_type = trim($_POST['waste_type'] ?? '');
    if ($label === '' || $waste_type === '') { echo json_encode(['success'=>false,'detail'=>'missing fields'], JSON_UNESCAPED_UNICODE); exit; }

    // connect DB (same as scan.php)
    $servername = env('DB_HOST', 'localhost'); $username = env('DB_USER', 'root'); $password = env('DB_PASSWORD', ''); $dbname = env('DB_NAME', 'waste_system');
    $conn = @new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        echo json_encode(['success'=>false, 'detail'=>'DB error', 'error'=>$conn->connect_error], JSON_UNESCAPED_UNICODE); exit;
    }
    // create table if not exists
    $create_sql = "CREATE TABLE IF NOT EXISTS `waste_labels` (
        `label` VARCHAR(100) NOT NULL,
        `waste_type` ENUM('ขยะทั่วไป','ขยะอันตราย','ขยะรีไซเคิล') NOT NULL,
        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`label`)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
    if (!$conn->query($create_sql)) {
        echo json_encode(['success'=>false, 'detail'=>'create table failed', 'error'=>$conn->error], JSON_UNESCAPED_UNICODE); exit;
    }
    // upsert mapping
    $stmt = $conn->prepare("INSERT INTO waste_labels (label, waste_type) VALUES (?,?) ON DUPLICATE KEY UPDATE waste_type=VALUES(waste_type)");
    if ($stmt) {
        $stmt->bind_param("ss", $label, $waste_type);
        if ($stmt->execute()) {
            echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE); exit;
        } else {
            echo json_encode(['success'=>false, 'detail'=>'insert failed', 'error'=>$stmt->error], JSON_UNESCAPED_UNICODE); exit;
        }
    } else {
        echo json_encode(['success'=>false, 'detail'=>'prepare failed', 'error'=>$conn->error], JSON_UNESCAPED_UNICODE); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>จัดการการเทรนโมเดล</title>
  <!-- Tailwind CSS for admin navbar/layout -->
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body { font-family: 'Inter', 'Google Sans', 'Kanit', system-ui, -apple-system, Segoe UI, Roboto, 'Helvetica Neue', Arial, 'Noto Sans', 'Liberation Sans', sans-serif; }
    .card { border-radius: 16px; }
    .section-title { font-weight: 700; }
    .muted { color:#64748b }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; }
    /* layout spacing to accommodate admin header (64px) and sidebar (w-64) */
    body { padding-top: 64px; }
    @media (min-width: 768px) { .admin-main { margin-left: 16rem; } }

    /* TeachableMachine‑like visuals (green theme) */
    .tm-card { border-radius: 18px; border:1px solid #e5e7eb; box-shadow: 0 8px 24px rgba(0,0,0,0.06); }
    .tm-title { font-weight:700; letter-spacing:.3px }
    .tm-chip { display:inline-block;padding:6px 12px;border-radius:9999px;background:#dcfce7;color:#166534;font-weight:600;margin:4px 6px 0 0; }
    .tm-dnd { border:2px dashed #cbd5e1; border-radius:14px; background:linear-gradient(135deg,#f8fafc,#eef2f7); padding:18px; text-align:center; cursor:pointer }
    .tm-dnd.hover { border-color:#10b981; background:linear-gradient(135deg,#f0fdf4,#dcfce7) }
    .tm-badge { display:inline-flex;align-items:center;gap:6px;background:#10b981;color:white;border-radius:9999px;padding:4px 10px;font-size:.85rem }
    .tm-section { background:white;border-radius:16px;border:1px solid #e5e7eb;padding:16px }
    .tm-muted { color:#64748b }

    /* Class list panel (inspired by train_model1 layout) */
    .tm-class-list { max-height: 52vh; overflow-y: auto; padding-right: 6px; }
    .tm-class-card { background:#fff; border:2px solid #e9ecef; border-radius:14px; padding:12px; margin-bottom:12px; transition:all .2s ease; }
    .tm-class-card:hover { border-color:#10b981; box-shadow: 0 6px 20px rgba(16,185,129,0.15); }
    .tm-class-header { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .tm-class-name { font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px; }
    .tm-class-count { background:#f1f5f9; color:#334155; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; }
    .tm-sample-grid { display:grid; grid-template-columns: repeat(6, 1fr); gap:4px; margin-top:8px; }
    .tm-thumb { width:100%; aspect-ratio:1; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; }
    .tm-actions { display:flex; gap:6px; }
    .tm-btn { border:1px solid #e5e7eb; background:#fff; color:#334155; padding:6px 10px; border-radius:8px; font-size:12px; font-weight:600; }
    .tm-btn:hover { border-color:#10b981; color:#064e3b }
    .tm-btn-danger { border-color:#fecaca; color:#991b1b; }
    .tm-btn-danger:hover { border-color:#ef4444; color:#7f1d1d; }

    /* Teachable add-class + upload buttons */
    :root { --gm-blue:#1a73e8; --gm-blue-50:#e8f0fe; }
    .tm-add-class { width:100%; padding:12px; border:2px dashed #e5e7eb; border-radius:12px; background:#fff; color:#64748b; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; transition:all .2s ease; }
    .tm-add-class:hover { border-color:var(--gm-blue); color:#1a73e8; background:var(--gm-blue-50); }
    .tm-upload-buttons { display:flex; gap:8px; margin-top:10px; }
    .tm-upload-btn { flex:1; border:2px solid #e5e7eb; background:#fff; color:#334155; padding:8px 10px; border-radius:10px; font-size:12px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; transition:all .2s ease; }
    .tm-upload-btn:hover { border-color:var(--gm-blue); color:#1a73e8; background:var(--gm-blue-50); }
    /* Models list readability */
    .tm-model-item { border:1px solid #e5e7eb; border-radius:10px; padding:10px; }
    .tm-model-path { overflow-wrap:anywhere; word-break:break-all; color:#64748b; }
    .tm-model-actions .btn { padding:4px 10px; }

    /* Visual link from classes to training */
    /* Prepare flow overlay */
    .admin-main { position: relative; }
/* เปลี่ยนสีและสไตล์ให้เหมือน Teachable Machine */
.tm-flow-svg { 
    position: absolute; 
    inset: 0; 
    width: 100%; 
    height: 100%; 
    pointer-events: none; 
    z-index: 3; 
}

/* เส้นพื้นหลัง (shadow) */
.tm-flow-path-bg { 
    stroke: #e8eaed;           /* เทาอ่อนแบบ Google */
    stroke-width: 4; 
    opacity: 0.8; 
    fill: none; 
    stroke-linecap: round;     /* ปลายเส้นโค้ง */
}

/* เส้นหลัก */
.tm-flow-path { 
    stroke: #6a6a6aff;           /* สีฟ้า Google เป๊ะ */
    stroke-width: 2.5; 
    opacity: 1; 
    fill: none; 
    stroke-linecap: round;     /* ปลายเส้นโค้ง */
    stroke-dasharray: none;    /* เส้นทึบ ไม่ประ */
}

/* เส้นแบบ Animated (ถ้าต้องการ) */
.tm-flow-path.animated {
    stroke-dasharray: 8, 4;
    animation: tm-flow 2s linear infinite;
}

@keyframes tm-flow {
    0% { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: 12; }
}

/* Card styles ให้เหมือน Teachable */
.tm-class-card { 
    position: relative; 
    background: #ffffff;
    border: 1px solid #dadce0;  /* เทาแบบ Google */
    border-radius: 8px;
    box-shadow: 0 1px 2px 0 rgba(60,64,67,.3), 0 1px 3px 1px rgba(60,64,67,.15);
}

.tm-train-card { 
    position: relative; 
    background: #ffffff;
    border: 1px solid #dadce0;
    border-radius: 8px;
    box-shadow: 0 1px 2px 0 rgba(60,64,67,.3), 0 1px 3px 1px rgba(60,64,67,.15);
}

/* เพิ่มเอฟเฟค hover */
.tm-class-card:hover,
.tm-train-card:hover {
    box-shadow: 0 1px 3px 0 rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15);
}
    /* disable older pseudo-connector styles */
    .tm-class-card::after,.tm-class-card::before,.tm-train-card::before,.tm-dataset-card::after{ content:none !important; }

    /* Make dataset title background transparent to improve line visibility */
    .tm-title { background: transparent !important; }
    .mg-row-3{
          margin-top: 200px;
    }
    .mg-row-2{
          margin-top: 200px;
    }

  </style>
</head>
<body>
<div id="navbar-container"></div>
<script src="navbar.js"></script>

<div class="container my-4 admin-main" style="max-width: 1080px;">
  <!-- Flow overlay (SVG curves between classes and training) -->
  <svg id="tmFlow" class="tm-flow-svg" aria-hidden="true"></svg>
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h2 class="section-title mb-0"><i class="fa-solid fa-brain me-2"></i>จัดการการเทรนโมเดล</h2>
    <div class="small text-muted">FASTAPI: <span class="mono" id="fastapiBase"></span></div>
  </div>

  <div class="alert alert-warning" role="alert" id="adminTokenWarn" style="display:none">
    คำแนะนำ: กรุณาเพิ่มภาพขั้นต่ำ 1600 ภาพต่อ 1 คลาส
  </div>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card shadow-sm tm-card tm-dataset-card">
        <div class="card-body">
          <h5 class="card-title tm-title"><i class="fa-solid fa-list-ul me-2"></i>ชุดข้อมูล (Dataset)</h5>
          <p class="tm-muted mb-2">อ่านจาก <span class="mono">data/prepared/train, val</span></p>
          <div id="classesList" class="mb-3 tm-class-list">-</div>
<!-- Add Class Modal -->
<div class="modal fade" id="addClassModal" tabindex="-1" aria-labelledby="addClassLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header border-0">
        <h5 class="modal-title" id="addClassLabel"><i class="fa-solid fa-plus me-2"></i>เพิ่มคลาสใหม่</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="addClassForm" class="needs-validation" novalidate>
        <div class="modal-body pt-0">
          <div class="mb-3">
            <label class="form-label">ชื่อคลาส</label>
            <input type="text" class="form-control" id="newClassNameModal" placeholder="เช่น พลาสติกใส, กล่องโฟม" required>
            <div class="invalid-feedback">กรุณากรอกชื่อคลาส</div>
          </div>
          <div class="mb-2">
            <label class="form-label">ประเภทขยะ</label>
            <select class="form-select" id="newClassTypeModal" required>
              <option value="" selected disabled>เลือกประเภท</option>
              <option value="ขยะทั่วไป">ขยะทั่วไป</option>
              <option value="ขยะรีไซเคิล">ขยะรีไซเคิล</option>
              <option value="ขยะอันตราย">ขยะอันตราย</option>
            </select>
            <div class="invalid-feedback">กรุณาเลือกประเภท</div>
          </div>
          <p class="small text-muted mb-0">ระบบจะสร้างโฟลเดอร์คลาสในทั้ง <span class="mono">train/</span> และ <span class="mono">val/</span> และบันทึกประเภทลงฐานข้อมูล</p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
          <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-1"></i>บันทึก</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1080">
  <div id="tmToast" class="toast align-items-center border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="tmToastBody">บันทึกสำเร็จ</div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

          <button type="button" id="tmAddClassBtn" class="tm-add-class mb-2">
            <i class="fa-solid fa-plus"></i>
            Add a class
          </button>
          <div class="input-group mb-2" id="addClassRow" style="display:none!important">
            <input type="text" class="form-control" id="newClassName" placeholder="ชื่อคลาสใหม่ เช่น พลาสติก">
            <button class="btn btn-success" id="btnAddClass"><i class="fa-solid fa-plus"></i></button>
          </div>
          <small class="tm-muted">จะสร้างในทั้ง train/ และ val/</small>
          <!-- Hidden ZIP input for per-class upload -->
          <input type="file" id="uploadZip" accept=".zip" class="d-none">
        </div>
      </div>
    </div>

    <div class="col-lg-4 mg-row-2">
      <div class="card shadow-sm mb-4 tm-card tm-train-card">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h5 class="card-title mb-0 tm-title"><i class="fa-solid fa-rocket me-2"></i>เริ่มเทรนต่อยอด</h5>
            <button class="btn btn-light btn-sm" id="btnToggleAdvanced"><i class="fa-solid fa-sliders"></i> Advanced</button>
          </div>
          <div class="row g-3 align-items-end" id="advancedRow">
            <div class="col-md-4">
              <label class="form-label">ตั้งชื่อเวอร์ชันโมเดล</label>
              <input type="text" class="form-control" id="versionInput" placeholder="เช่น v3">
            </div>
            <div class="col-md-5">
              <label class="form-label">ใช้โมเดลเดิมเป็นฐาน</label>
              <select class="form-select" id="baseModelSelect"></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Epochs</label>
              <input type="number" class="form-control" id="epochsInput" value="5" min="1" max="200">
            </div>
          </div>
          <div class="mt-3 d-flex gap-2 align-items-center">
            <button class="btn btn-success" id="btnStartTrain" disabled>
              <i class="fa-solid fa-circle-play me-1"></i><span>เริ่ม</span>
            </button>
            <button class="btn btn-outline-danger" id="btnStopTrain" disabled>
              <i class="fa-solid fa-stop me-1"></i>หยุด
            </button>
            <button class="btn btn-outline-secondary" id="btnRefreshModels">รีเฟรช</button>
            <span id="datasetHint" class="text-muted small">อัปโหลดภาพอย่างน้อย 1500 ไฟล์ก่อนจึงจะเริ่มเทรนได้</span>
          </div>
          <div class="mt-3">
            <div class="progress" style="height: 20px;">
              <div id="trainProgress" class="progress-bar bg-success" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
            <div class="d-flex justify-content-between small text-muted mt-1">
              <div id="trainEta">พร้อมเริ่มเทรน</div>
              <div id="trainState">0/100</div>
            </div>
          </div>
          <pre id="trainStatus" class="mt-3 mono small text-muted" style="display:none"></pre>
        </div>
      </div>

      <!-- Preview card moved to right column -->

      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-3"><i class="fa-solid fa-layer-group me-2"></i>โมเดลที่มี</h5>
          <div id="modelsList">-</div>
        </div>
      </div>
    </div>

    <!-- Right column: Preview / Export -->
    <div class="col-lg-4 mg-row-3">
      <div class="card shadow-sm mb-4 tm-card tm-preview-card">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h5 class="card-title mb-0 tm-title"><i class="fa-solid fa-vial me-2"></i>ทดสอบโมเดลอย่างรวดเร็ว</h5>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="border rounded p-2 mb-2">
                <video id="cam" autoplay playsinline style="width:100%;display:none;border-radius:8px"></video>
                <canvas id="camCanvas" style="display:none"></canvas>
                <div class="d-flex gap-2 mt-2">
                  <button id="btnOpenCam" class="btn btn-outline-secondary"><i class="fa-solid fa-camera"></i> เปิดกล้อง</button>
                  <button id="btnSnap" class="btn btn-secondary" style="display:none"><i class="fa-solid fa-bolt"></i> ถ่ายภาพ</button>
                  <button id="btnCloseCam" class="btn btn-outline-danger" style="display:none"><i class="fa-solid fa-circle-xmark"></i> ปิดกล้อง</button>
                </div>
              </div>
              <div class="input-group">
                <input id="testFile" type="file" accept="image/*" class="form-control">
                <button id="btnPredict" class="btn btn-primary">ทำนาย</button>
              </div>
              <div class="mt-2">
                <img id="testPreview" alt="Preview" style="max-width:100%;max-height:200px;display:none;border-radius:8px" />
              </div>
            </div>
            <div class="col-md-6">
              <canvas id="predictChart" height="220"></canvas>
              <div id="predictResult" class="mt-2"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const FASTAPI_BASE = <?php echo json_encode($FASTAPI_BASE); ?>;
const ADMIN_TOKEN  = <?php echo json_encode($FASTAPI_ADMIN_TOKEN); ?>;
document.getElementById('fastapiBase').textContent = FASTAPI_BASE;
if (ADMIN_TOKEN === 'changeme') document.getElementById('adminTokenWarn').style.display = 'block';

async function apiGet(u){ const r = await fetch(u); return await r.json(); }
async function apiPost(u, fd){ const r = await fetch(u, { method:'POST', body: fd }); return await r.json(); }
// safer post that surfaces server-side error body
async function apiPostSafe(u, fd){
  const r = await fetch(u, { method:'POST', body: fd });
  const txt = await r.text();
  try { if (!r.ok) throw new Error(txt); return JSON.parse(txt); } catch(e){ throw new Error((e && e.message) ? e.message : txt); }
}

let _classCounts = {};
let datasetReady = (localStorage.getItem('datasetReady') === '1');
let currentClass = null; // used for per-class uploads (ZIP)
async function loadClasses(){
  const list = document.getElementById('classesList');
  const sel  = document.getElementById('uploadClassSelect');
  if (sel) sel.innerHTML = '';
  let classes = [];
  try {
    const cc = await apiGet(`${FASTAPI_BASE}/dataset/class_counts`);
    if (cc && Array.isArray(cc.classes)){
      _classCounts = cc.counts || {};
      classes = cc.classes;
      // Render class cards
      list.innerHTML = classes.map(c=>{
        const ct = _classCounts[c] || {train:0,val:0,total:0};
        const cardId = `card_${btoa(unescape(encodeURIComponent(c))).replace(/=+$/,'')}`;
        const gridId = `grid_${btoa(unescape(encodeURIComponent(c))).replace(/=+$/,'')}`;
        return `
          <div class="tm-class-card" id="${cardId}">
            <div class="tm-class-header">
              <div class="tm-class-name">
                <i class="fa-regular fa-folder"></i>
                <span>${c}</span>
                <span class="tm-class-count">${ct.total} รูป</span>
              </div>
              <div class="dropdown">
                <button class="btn btn-sm btn-light" data-bs-toggle="dropdown" aria-expanded="false" title="เมนู">
                  <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><button class="dropdown-item" data-action="rename" data-class="${c.replace(/&/g,'&amp;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;')}"><i class="fa-regular fa-pen-to-square me-2"></i>เปลี่ยนชื่อ</button></li>
                  <li><button class="dropdown-item" data-action="clear" data-class="${c.replace(/&/g,'&amp;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;')}"><i class="fa-regular fa-trash-can me-2"></i>ลบรูปทั้งหมด</button></li>
                  <li><button class="dropdown-item" data-action="delete" data-class="${c.replace(/&/g,'&amp;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;')}"><i class="fa-solid fa-trash me-2"></i>ลบคลาส</button></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><button class="dropdown-item disabled" tabindex="-1"><i class="fa-regular fa-circle-xmark me-2"></i>Disable Class</button></li>
                  <li><button class="dropdown-item disabled" tabindex="-1"><i class="fa-solid fa-download me-2"></i>Download Samples</button></li>
                  <li><button class="dropdown-item disabled" tabindex="-1"><i class="fa-brands fa-google-drive me-2"></i>Save to Drive</button></li>
                </ul>
              </div>
            </div>
            <div class="tm-sample-grid" id="${gridId}">
              <div class="small tm-muted" style="grid-column: 1 / -1;">กำลังโหลดตัวอย่าง...</div>
            </div>
            <div class="tm-upload-buttons">
              <button type="button" class="tm-upload-btn tm-zip-upload-btn" data-class="${c.replace(/&/g,'&amp;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;')}" title="อัปโหลด ZIP ของรูปตัวอย่าง">
                <i class="fa-solid fa-upload"></i>
                <span>Upload ZIP</span>
              </button>
            </div>
          </div>`;
      }).join('');
      if (sel) {
        sel.innerHTML = classes.map(c=>{
          const ct = _classCounts[c] || {total:0};
          return `<option value=\"${c}\">${c} (${ct.total})</option>`;
        }).join('');
      }
      // recompute readiness from counts
      try {
        const totals = Object.values(_classCounts).map(ct=> (ct && ct.total) ? Number(ct.total) : 0);
        const hasAny = totals.some(n => (n||0) > 0);
        datasetReady = hasAny;
        localStorage.setItem('datasetReady', datasetReady ? '1' : '0');
        updateStartBtn();
      } catch(_){}

      // Load thumbnails per class asynchronously
      for (const c of classes){
        try {
          const gridId = `grid_${btoa(unescape(encodeURIComponent(c))).replace(/=+$/,'')}`;
          const el = document.getElementById(gridId);
          const d = await apiGet(`${FASTAPI_BASE}/dataset/class_samples?class_name=${encodeURIComponent(c)}&limit=12&thumb_size=72`);
          const samples = (d && d.samples) ? d.samples : [];
          if (el){
            el.innerHTML = samples.length
              ? samples.map(s=>`<img class=\"tm-thumb\" src=\"${s.data_url}\" alt=\"${c}\">`).join('')
              : '<span class="small tm-muted" style="grid-column:1/-1">ไม่มีรูปในคลาสนี้</span>';
          }
        } catch(e) {
          const gridId = `grid_${btoa(unescape(encodeURIComponent(c))).replace(/=+$/,'')}`;
          const el = document.getElementById(gridId);
          if (el) el.innerHTML = '<span class="small text-danger" style="grid-column:1/-1">โหลดตัวอย่างไม่สำเร็จ</span>';
        }
      }

      // Bind per-class actions (menu + upload)
      try {
        list.querySelectorAll('.tm-zip-upload-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            currentClass = btn.getAttribute('data-class');
            const selEl = document.getElementById('uploadClassSelect');
            if (selEl && currentClass) selEl.value = currentClass;
            const up = document.getElementById('uploadZip');
            if (up) up.click();
          });
        });
        list.querySelectorAll('.dropdown-item[data-action]').forEach(item => {
          item.addEventListener('click', () => {
            const action = item.getAttribute('data-action');
            const cls = item.getAttribute('data-class');
            if (action === 'rename') return promptRenameClass(cls);
            if (action === 'delete') return confirmDeleteClass(cls);
            if (action === 'clear') return removeAllSamples(cls);
          });
        });
      } catch(_){ /* ignore binding errors */ }

      // redraw flow after classes rendered
      requestAnimationFrame(drawTMFlow);
      // also re-draw on internal list scrolls
      document.querySelectorAll('.tm-class-list').forEach(el => {
        el.removeEventListener?.('scroll', scheduleDrawFlow);
        el.addEventListener('scroll', scheduleDrawFlow, { passive: true });
      });
    }
  } catch(e){
    const d = await apiGet(`${FASTAPI_BASE}/dataset/classes`);
    if (!d || !d.classes || d.classes.length===0){
      list.textContent = 'ยังไม่มีคลาส';
      return;
    }
    classes = d.classes;
    list.innerHTML = classes.map(c=>`<span class=\"badge text-bg-success me-1 mb-1\">${c}</span>`).join('');
    if (sel) sel.innerHTML = classes.map(c=>`<option>${c}</option>`).join('');
  }
  if (classes && classes.length){
    const chosen = (sel && sel.value) ? sel.value : classes[0];
    renderClassInfo(chosen);
  }
}

// ---- Rename/Delete class helpers ----
async function promptRenameClass(oldName){
  const nn = prompt('เปลี่ยนชื่อคลาส', oldName);
  if (!nn) return;
  if (nn === oldName) return;
  const fd = new FormData();
  fd.append('old_name', oldName);
  fd.append('new_name', nn);
  fd.append('token', ADMIN_TOKEN);
  try {
    const r = await fetch(`${FASTAPI_BASE}/dataset/rename_class`, { method:'POST', body: fd });
    const d = await r.json();
    if (!r.ok) throw new Error((d && d.detail) ? d.detail : 'rename failed');
    await loadClasses();
    // keep selection if possible
    const sel = document.getElementById('uploadClassSelect');
    if (sel) { sel.value = nn; renderClassInfo(nn); }
  } catch(e){ alert('เปลี่ยนชื่อไม่สำเร็จ: ' + String(e)); }
}

async function confirmDeleteClass(name){
  if (!confirm(`ยืนยันลบคลาส “${name}” และรูปทั้งหมดในคลาสนี้หรือไม่?`)) return;
  const fd = new FormData();
  fd.append('class_name', name);
  fd.append('token', ADMIN_TOKEN);
  try {
    const r = await fetch(`${FASTAPI_BASE}/dataset/delete_class`, { method:'POST', body: fd });
    const d = await r.json();
    if (!r.ok) throw new Error((d && d.detail) ? d.detail : 'delete failed');
    await loadClasses();
  } catch(e){ alert('ลบคลาสไม่สำเร็จ: ' + String(e)); }
}

async function renderClassInfo(className){
  const info = document.getElementById('classInfo');
  const thumbs = document.getElementById('classThumbs');
  const ct = _classCounts[className] || {train:0,val:0,total:0};
  if (info) info.textContent = `รูปทั้งหมด: ${ct.total} (train ${ct.train} · val ${ct.val})`;
  if (thumbs) thumbs.innerHTML = '<div class="small text-muted">กำลังโหลดตัวอย่าง...</div>';
  try{
    const limit = 20;
    const d = await apiGet(`${FASTAPI_BASE}/dataset/class_samples?class_name=${encodeURIComponent(className)}&limit=${limit}&thumb_size=96`);
    const samples = (d && d.samples) ? d.samples : [];
    if (thumbs) thumbs.innerHTML = samples.length
      ? samples.map(s=>`<img src=\"${s.data_url}\" title=\"${s.name}\" style=\"width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb\">`).join('')
      : '<span class="small text-muted">ไม่มีรูปในคลาสนี้</span>';
  }catch(e){
    if (thumbs) thumbs.innerHTML = '<span class="small text-danger">โหลดตัวอย่างไม่สำเร็จ</span>';
  }
}

async function addClass(){
  const name = document.getElementById('newClassName').value.trim();
  if(!name) return;
  const fd = new FormData(); fd.append('name', name); fd.append('token', ADMIN_TOKEN);
  const d = await apiPost(`${FASTAPI_BASE}/dataset/classes`, fd);
  await loadClasses();
}

async function uploadImages(){
  const cls = document.getElementById('uploadClassSelect').value;
  const files = document.getElementById('uploadFile').files;
  if(!cls || !files.length) return;
  const info = document.getElementById('uploadInfo');
  const MAX_PER_REQ = 200; // ส่งเป็นชุดๆ เพื่อลดโอกาสชนเพดาน 1000 ไฟล์ของเซิร์ฟเวอร์
  let savedTotal = 0;
  try {
    for (let i = 0; i < files.length; i += MAX_PER_REQ) {
      const chunk = Array.from(files).slice(i, i + MAX_PER_REQ);
      const fd = new FormData(); fd.append('class_name', cls); fd.append('token', ADMIN_TOKEN);
      // ส่งเฉพาะ field name 'files' (ฝั่ง API รองรับอยู่แล้ว)
      for (const f of chunk) fd.append('files', f);
      info.textContent = `กำลังอัปโหลด... (${Math.min(i+MAX_PER_REQ, files.length)}/${files.length})`;
      const d = await apiPostSafe(`${FASTAPI_BASE}/dataset/upload_images`, fd);
      savedTotal += (d.saved || 0);
    }
    info.textContent = `อัปโหลดแล้ว ${savedTotal} ไฟล์`;
    if (savedTotal > 0){
      datasetReady = true; localStorage.setItem('datasetReady','1'); updateStartBtn();
      const selEl = document.getElementById('uploadClassSelect');
      const prev = selEl && selEl.value; await loadClasses();
      if (selEl && prev) { selEl.value = prev; renderClassInfo(prev); }
    }
  } catch(err){
    info.textContent = 'อัปโหลดไม่สำเร็จ: ' + String(err).slice(0,200);
    alert('อัปโหลดไม่สำเร็จ\n' + String(err));
  }
}

async function uploadZip(){
  const sel = document.getElementById('uploadClassSelect');
  const cls = (sel && sel.value) ? sel.value : (currentClass || '');
  const upEl = document.getElementById('uploadZip');
  const f   = upEl && upEl.files ? upEl.files[0] : null;
  if(!cls || !f) return;
  // Confirm before upload
  if (!confirm(`อัปโหลดไฟล์ ZIP ไปยังคลาส "${cls}" หรือไม่?`)) { if (upEl) upEl.value=''; return; }
  showUploadOverlay('กำลังอัปโหลดและแตกไฟล์ ZIP...');
  const fd = new FormData(); fd.append('class_name', cls); fd.append('file', f); fd.append('token', ADMIN_TOKEN);
  try {
    const d = await apiPostSafe(`${FASTAPI_BASE}/dataset/upload_zip`, fd);
    const info = document.getElementById('uploadInfo');
    if (info) info.textContent = `แตกไฟล์แล้ว ${d.saved || 0} ไฟล์`;
    if ((d.saved||0) > 0){
      datasetReady = true; localStorage.setItem('datasetReady','1'); updateStartBtn();
      // refresh counts and thumbnails immediately
      const sel2 = document.getElementById('uploadClassSelect');
      const prev = sel2 && sel2.value;
      await loadClasses();
      if (sel2 && prev){ sel2.value = prev; await renderClassInfo(prev); }
    }
  } catch(err){
    const info = document.getElementById('uploadInfo');
    if (info) info.textContent = 'อัป ZIP ไม่สำเร็จ: ' + String(err).slice(0,200);
    alert('อัป ZIP ไม่สำเร็จ\n' + String(err));
  }
  hideUploadOverlay();
  if (upEl) upEl.value='';
}

function renderModels(d){
  const c = document.getElementById('modelsList');
  const baseSel = document.getElementById('baseModelSelect');
  baseSel.innerHTML = '';
  if(!d || !Array.isArray(d.items)) { c.textContent = 'ไม่พบโมเดล'; return; }
  baseSel.innerHTML = d.items.map(it=>`<option value="${it.id}">${it.id}</option>`).join('');
  const act = String(d.active||'');
  c.innerHTML = d.items.map(it=>{
    const isActive = act.includes(it.weights);
    return `
      <div class="tm-model-item d-flex align-items-center justify-content-between mb-2">
        <div class="pe-2" style="min-width:0;">
          <div><strong>${it.id}</strong> ${isActive?'<span class="badge text-bg-success">active</span>':''}</div>
          <div class="tm-model-path mono small">${it.weights}</div>
        </div>
        <div class="tm-model-actions btn-group ms-2 flex-shrink-0">
          <button class="btn ${isActive ? 'btn-success' : 'btn-outline-primary'} btn-sm" ${isActive ? 'disabled' : ''} onclick="useModel('${it.id}')">ใช้</button>
          <button class="btn btn-outline-danger btn-sm" title="ลบ" onclick="deleteModel('${it.id}')"><i class="fa-regular fa-trash-can"></i></button>
        </div>
      </div>`;
  }).join('');
}

async function refreshModels(){
  const d = await apiGet(`${FASTAPI_BASE}/models`);
  renderModels(d);
}

async function useModel(id){
  const fd = new FormData(); fd.append('id', id); fd.append('token', ADMIN_TOKEN);
  await apiPost(`${FASTAPI_BASE}/models/load`, fd);
  await refreshModels();
}

async function deleteModel(id){
  if (!confirm(`ยืนยันลบโมเดล "${id}" หรือไม่?`)) return;
  const fd = new FormData(); fd.append('id', id); fd.append('token', ADMIN_TOKEN);
  try{
    const r = await fetch(`${FASTAPI_BASE}/models/delete`, { method:'POST', body: fd });
    const d = await r.json();
    if (!r.ok || d.ok !== true){
      throw new Error((d && (d.detail || d.error)) ? d.detail || d.error : 'delete failed');
    }
    await refreshModels();
  }catch(e){
    alert('ลบโมเดลไม่สำเร็จ: ' + String(e));
  }
}

let currentTrainingVersion = null;
let trainingTick = null;

async function startTrain(){
  const ver = document.getElementById('versionInput').value.trim();
  const base = document.getElementById('baseModelSelect').value;
  const ep   = parseInt(document.getElementById('epochsInput').value || '5', 10);
  if(!ver) { alert('กรอกชื่อเวอร์ชัน'); return; }
  if(!datasetReady){ alert('กรุณาอัปโหลดภาพก่อนเริ่มเทรน'); return; }
  const fd = new FormData();
  fd.append('version', ver); fd.append('base_model_id', base); fd.append('epochs', String(ep)); fd.append('token', ADMIN_TOKEN);
  const d = await apiPost(`${FASTAPI_BASE}/train/start`, fd);
  currentTrainingVersion = ver;
  setTrainingUI(true);
  pollStatus(ver);
}

// Remove all samples in a class by deleting and recreating
async function removeAllSamples(name){
  if (!name) return;
  if (!confirm(`ลบรูปทั้งหมดในคลาส "${name}" และสร้างคลาสใหม่อีกครั้ง?`)) return;
  try {
    const fdDel = new FormData();
    fdDel.append('class_name', name);
    fdDel.append('token', ADMIN_TOKEN);
    await fetch(`${FASTAPI_BASE}/dataset/delete_class`, { method:'POST', body: fdDel });
  } catch(_) {}
  try {
    const fdAdd = new FormData();
    fdAdd.append('name', name);
    fdAdd.append('token', ADMIN_TOKEN);
    await fetch(`${FASTAPI_BASE}/dataset/classes`, { method:'POST', body: fdAdd });
  } catch(_) {}
  await loadClasses();
}

async function pollStatus(ver){
  const box = document.getElementById('trainStatus');
  if (trainingTick) { clearInterval(trainingTick); trainingTick = null; }
  trainingTick = setInterval(async () => {
    try {
      const s = await apiGet(`${FASTAPI_BASE}/train/status?version=${encodeURIComponent(ver)}`);
      box.textContent = (s.done ? 'สำเร็จ ' : 'กำลังเทรน... ') + (s.best || '');
      if (s.log) box.textContent += "\n--- LOG ---\n" + s.log;
      if (s.done) { clearInterval(trainingTick); trainingTick = null; setTrainingUI(false); await refreshModels(); }
    } catch(e){ if (trainingTick) { clearInterval(trainingTick); trainingTick = null; } }
  }, 3000);
}

document.getElementById('btnAddClass').addEventListener('click', addClass);
const _btnUploadImages = document.getElementById('btnUploadImages');
if (_btnUploadImages) _btnUploadImages.addEventListener('click', uploadImages);
const _btnUploadZip = document.getElementById('btnUploadZip');
if (_btnUploadZip) _btnUploadZip.addEventListener('click', uploadZip);
document.getElementById('btnStartTrain').addEventListener('click', startTrain);
document.getElementById('btnStopTrain').addEventListener('click', stopTrain);
document.getElementById('btnRefreshModels').addEventListener('click', refreshModels);
const _selUploadClass = document.getElementById('uploadClassSelect');
if (_selUploadClass) _selUploadClass.addEventListener('change', (e)=> renderClassInfo(e.target.value));
// TM-style helpers
const tmAddBtn = document.getElementById('tmAddClassBtn');
if (tmAddBtn){
  tmAddBtn.addEventListener('click', ()=>{
    const row = document.getElementById('addClassRow');
    if (!row) return;
    const show = (row.style.display === 'none' || !row.style.display);
    row.style.display = show ? 'flex' : 'none';
    if (show){ const i = document.getElementById('newClassName'); if (i) i.focus(); }
  });
}
const advBtn = document.getElementById('btnToggleAdvanced');
if (advBtn){
  advBtn.addEventListener('click', ()=>{
    const row = document.getElementById('advancedRow');
    if (!row) return;
    row.classList.toggle('d-none');
  });
}
// Trigger upload when a zip file is picked via hidden input
const hiddenZip = document.getElementById('uploadZip');
if (hiddenZip){
  hiddenZip.addEventListener('change', async (e)=>{
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    await uploadZip();
  });
}

(async function init(){
  await loadClasses();
  await refreshModels();
  updateStartBtn();
  drawTMFlow();
  window.addEventListener('resize', scheduleDrawFlow);
  window.addEventListener('scroll', scheduleDrawFlow, { passive: true });
})();

function updateStartBtn(){
  const b = document.getElementById('btnStartTrain');
  const hint = document.getElementById('datasetHint');
  if (datasetReady){ b.removeAttribute('disabled'); hint.style.display='none'; }
  else { b.setAttribute('disabled','disabled'); hint.style.display='inline'; }
}

function setTrainingUI(running){
  const btn = document.getElementById('btnStartTrain');
  const stopBtn = document.getElementById('btnStopTrain');
  const p = document.getElementById('trainProgress');
  const eta = document.getElementById('trainEta');
  const state = document.getElementById('trainState');
  btn.disabled = running || !datasetReady;
  stopBtn.disabled = !running;
  btn.classList.toggle('btn-secondary', running);
  btn.classList.toggle('btn-success', !running);
  btn.querySelector('span').textContent = running ? 'กำลังเทรน...' : 'เริ่มเทรน';
  if (!running){ p.style.width='0%'; p.textContent='0%'; p.setAttribute('aria-valuenow','0'); eta.textContent='พร้อมเริ่มเทรน'; state.textContent='0/100'; }
}

async function pollStatus(ver){
  const p = document.getElementById('trainProgress');
  const eta = document.getElementById('trainEta');
  const state = document.getElementById('trainState');
  const pre = document.getElementById('trainStatus');
  if (trainingTick) { clearInterval(trainingTick); trainingTick = null; }
  const startTime = Date.now();
  trainingTick = setInterval(async () => {
    try{
      const s = await apiGet(`${FASTAPI_BASE}/train/status?version=${encodeURIComponent(ver)}`);
      const prog = Math.max(0, Math.min(100, parseInt(s.progress||0)));
      p.style.width = `${prog}%`; p.textContent = `${prog}%`; p.setAttribute('aria-valuenow', String(prog));
      state.textContent = `${prog}/100`;
      // fallback elapsed timer if API has no started
      const sec = s.started ? Math.floor(Date.now()/1000 - s.started) : Math.floor((Date.now()-startTime)/1000);
      const m=Math.floor(sec/60), r=sec%60; eta.textContent = `ระยะเวลา ${m}:${String(r).padStart(2,'0')}`;
      if (s.log){ pre.style.display='none'; /* ซ่อน log ดิบ */ }
      if (s.done){
        clearInterval(trainingTick); trainingTick = null;
        p.style.width='100%'; p.textContent='100%'; p.setAttribute('aria-valuenow','100'); state.textContent='100/100';
        setTrainingUI(false);
        await refreshModels();
        // แสดงสรุปผลอย่างง่าย
        pre.style.display='block';
        pre.textContent = 'เทรนเสร็จแล้ว: ' + (s.best || '');
      }
    }catch(e){ if (trainingTick) { clearInterval(trainingTick); trainingTick = null; } setTrainingUI(false); }
  }, 2500);
}

// Simple export handler (placeholder)
// export removed by request

// Lightweight overlay animation while uploading ZIP
function showUploadOverlay(text){
  const ex = document.getElementById('uploadOverlay');
  if (ex) ex.remove();
  const el = document.createElement('div');
  el.id = 'uploadOverlay';
  el.setAttribute('style', 'position:fixed;inset:0;background:rgba(255,255,255,.7);backdrop-filter:blur(2px);z-index:2000;display:flex;align-items:center;justify-content:center;');
  el.innerHTML = `
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:12px;box-shadow:0 8px 30px rgba(0,0,0,.12)">
      <div class="spinner-border text-primary" role="status" style="width:1.75rem;height:1.75rem"></div>
      <div style="font-weight:600;color:#374151">${text || 'กำลังอัปโหลด...'}</div>
    </div>`;
  document.body.appendChild(el);
}
function hideUploadOverlay(){
  const el = document.getElementById('uploadOverlay');
  if (el) el.remove();
}

// ---- Flow drawing between class cards and training card ----
function scheduleDrawFlow(){
  if (window._tmFlowRAF) cancelAnimationFrame(window._tmFlowRAF);
  window._tmFlowRAF = requestAnimationFrame(drawTMFlow);
}
function drawTMFlow(){
  const svg = document.getElementById('tmFlow');
  const wrap = document.querySelector('.admin-main');
  const train = document.querySelector('.tm-train-card');
  const preview = document.querySelector('.tm-preview-card');
  const dataset = document.querySelector('.tm-dataset-card');
  const listEl = document.querySelector('.tm-class-list');
  if (!svg || !wrap || !train || !dataset){ return; }
  const classes = Array.from(document.querySelectorAll('.tm-class-card'));
  const wrapRect = wrap.getBoundingClientRect();
  const trainRect = train.getBoundingClientRect();
  const datasetRect = dataset.getBoundingClientRect();
  // size svg to container
  const w = wrap.clientWidth;
  const h = Math.max(wrap.scrollHeight, wrapRect.height);
  svg.setAttribute('width', String(w));
  svg.setAttribute('height', String(h));
  svg.innerHTML = '';

  // Clip visible vertical band to avoid lines leaking out of cards
  const listRect = listEl ? listEl.getBoundingClientRect() : datasetRect;
  const visTop = (listRect.top - wrapRect.top);
  const visBottom = (listEl ? (listRect.top + listEl.clientHeight) : listRect.bottom) - wrapRect.top;
  const previewRect = preview ? preview.getBoundingClientRect() : trainRect;
  const trainTopN = trainRect.top - wrapRect.top, trainBotN = trainRect.bottom - wrapRect.top;
  const prevTopN = previewRect.top - wrapRect.top, prevBotN = previewRect.bottom - wrapRect.top;
  const yTop = Math.min(visTop, trainTopN, prevTopN) - 8;
  const yBot = Math.max(visBottom, trainBotN, prevBotN) + 8;
  const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
  const clip = document.createElementNS('http://www.w3.org/2000/svg', 'clipPath');
  clip.setAttribute('id', 'tmClip');
  const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
  rect.setAttribute('x', '0');
  rect.setAttribute('y', String(Math.max(0, yTop)));
  rect.setAttribute('width', String(w));
  rect.setAttribute('height', String(Math.max(0, yBot - yTop)));
  clip.appendChild(rect); defs.appendChild(clip); svg.appendChild(defs);
  const g = document.createElementNS('http://www.w3.org/2000/svg','g');
  g.setAttribute('clip-path','url(#tmClip)');
  svg.appendChild(g);
  // function to create a path
  const mk = (d, cls) => { const p = document.createElementNS('http://www.w3.org/2000/svg','path'); p.setAttribute('d', d); p.setAttribute('class', cls); return p; };
  // Calculate junction point between dataset and training
  const datasetRight = datasetRect.right - wrapRect.left;
  const trainLeft = trainRect.left - wrapRect.left;
  const gap = Math.max(24, trainLeft - datasetRight);
  const jx = datasetRight + Math.min(gap - 20, gap * 0.35); // junction X between columns
  // choose junction Y as avg of class centers that are visible inside the scroll viewport
  const visibleClasses = classes.filter(card => {
    const r = card.getBoundingClientRect();
    const cy = r.top - wrapRect.top + r.height/2;
    return cy >= visTop && cy <= visBottom;
  });
  const ys = visibleClasses.map(card => {
    const r = card.getBoundingClientRect();
    return r.top - wrapRect.top + r.height/2;
  });
  const fallbackY = (visTop + visBottom) / 2;
  const jy = ys.length ? (ys.reduce((a,b)=>a+b,0) / ys.length) : fallbackY;

  // From each class to the junction (merge)
  visibleClasses.forEach((card) => {
    const r = card.getBoundingClientRect();
    const x1 = r.right - wrapRect.left; // right edge of class
    const y1 = r.top - wrapRect.top + r.height/2;
    const c1x = x1 + Math.max(20, gap * 0.15), c1y = y1;
    const c2x = jx - Math.max(20, gap * 0.15), c2y = jy;
    const d = `M ${x1} ${y1} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${jx} ${jy}`;
    g.appendChild(mk(d,'tm-flow-path-bg'));
    g.appendChild(mk(d,'tm-flow-path'));
  });

  // Single junction -> training connector
  {
    const x1 = jx, y1 = jy;
    const x2 = trainLeft, y2 = Math.min(
      trainRect.bottom - wrapRect.top - 40,
      Math.max(trainRect.top - wrapRect.top + 40, y1)
    );
    const c1x = x1 + Math.max(30, gap * 0.25), c1y = y1;
    const c2x = x2 - Math.max(30, gap * 0.25), c2y = y2;
    const d = `M ${x1} ${y1} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${x2} ${y2}`;
    g.appendChild(mk(d,'tm-flow-path-bg'));
    g.appendChild(mk(d,'tm-flow-path'));
  }
  // train -> preview connector
  if (preview){
    const pr = preview.getBoundingClientRect();
    const x1 = trainRect.right - wrapRect.left;
    const y1 = trainRect.top - wrapRect.top + Math.min(120, Math.max(60, trainRect.height/2));
    const x2 = pr.left - wrapRect.left;
    const y2 = pr.top - wrapRect.top + Math.min(120, Math.max(60, pr.height/2));
    const c1x = x1 + 50, c1y = y1;
    const c2x = x2 - 50, c2y = y2;
    const d = `M ${x1} ${y1} C ${c1x} ${c1y}, ${c2x} ${c2y}, ${x2} ${y2}`;
    g.appendChild(mk(d,'tm-flow-path-bg'));
    g.appendChild(mk(d,'tm-flow-path'));
  }
}

async function stopTrain(){
  if (!currentTrainingVersion){
    const v = document.getElementById('versionInput').value.trim();
    if (!v){ alert('ไม่พบเวอร์ชันที่กำลังเทรน'); return; }
    currentTrainingVersion = v;
  }
  if (!confirm(`หยุดเทรนเวอร์ชัน "${currentTrainingVersion}" หรือไม่?`)) return;
  const fd = new FormData();
  fd.append('version', currentTrainingVersion);
  fd.append('token', ADMIN_TOKEN);
  try{
    await apiPost(`${FASTAPI_BASE}/train/stop`, fd);
  }catch(_){ /* ignore */ }
  if (trainingTick){ clearInterval(trainingTick); trainingTick = null; }
  setTrainingUI(false);
}

// ---- Quick predict (webcam / upload) ----
const videoEl = document.getElementById('cam');
const canvasEl = document.getElementById('camCanvas');
const openBtn = document.getElementById('btnOpenCam');
const snapBtn = document.getElementById('btnSnap');
const fileEl = document.getElementById('testFile');
const predictBtn = document.getElementById('btnPredict');
const closeBtn = document.getElementById('btnCloseCam');
const previewEl = document.getElementById('testPreview');
let stream = null;

openBtn.addEventListener('click', async ()=>{
  try{
    stream = await navigator.mediaDevices.getUserMedia({ video:true });
    videoEl.srcObject = stream; videoEl.style.display='block';
    snapBtn.style.display='inline-block'; closeBtn.style.display='inline-block';
  }catch(e){ alert('ไม่สามารถเปิดกล้องได้'); }
});

closeBtn.addEventListener('click', ()=>{
  try{
    if (stream){ stream.getTracks().forEach(t=>t.stop()); }
  }catch(_){}
  stream = null; videoEl.srcObject = null; videoEl.style.display='none';
  snapBtn.style.display='none'; closeBtn.style.display='none';
});

snapBtn.addEventListener('click', ()=>{
  const v = videoEl; const c = canvasEl; const w = v.videoWidth, h=v.videoHeight; if(!w||!h) return;
  c.width=w; c.height=h; const ctx=c.getContext('2d'); ctx.drawImage(v,0,0,w,h);
  c.toBlob(b=>{ fileEl.files = new DataTransfer().files; const file=new File([b],'cam.jpg',{type:'image/jpeg'}); const dt=new DataTransfer(); dt.items.add(file); fileEl.files=dt.files; if (previewEl){ previewEl.src = URL.createObjectURL(file); previewEl.style.display='block'; } }, 'image/jpeg');
  // auto close camera after snapping
  try{ if (stream){ stream.getTracks().forEach(t=>t.stop()); } }catch(_){ }
  stream = null; videoEl.srcObject = null; videoEl.style.display='none';
  snapBtn.style.display='none'; closeBtn.style.display='none';
});

predictBtn.addEventListener('click', async ()=>{
  const f = fileEl.files && fileEl.files[0]; if(!f){ alert('เลือกภาพก่อน'); return; }
  const fd = new FormData(); fd.append('image', f);
  try{
    const r = await fetch(`${FASTAPI_BASE}/predict/`, { method:'POST', body: fd });
    const d = await r.json();
    renderPredict(d);
  }catch(e){ alert('predict error'); }
});

// preview chosen file
fileEl.addEventListener('change', ()=>{
  const f = fileEl.files && fileEl.files[0];
  if (!f) { if (previewEl){ previewEl.style.display='none'; previewEl.src=''; } return; }
  const url = URL.createObjectURL(f); if (previewEl){ previewEl.src = url; previewEl.style.display='block'; }
});

function renderPredict(d){
  const el = document.getElementById('predictResult');
  const canvas = document.getElementById('predictChart');
  if (!d || !Array.isArray(d.predictions)){ el.textContent='-'; return; }
  const labels = d.predictions.map(x=> x.waste_type || '-');
  const data = d.predictions.map(x=> Math.round((x.confidence||0)*100));
  if (window._predChart){ window._predChart.destroy(); }
  window._predChart = new Chart(canvas.getContext('2d'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'ความมั่นใจ (%)', data, backgroundColor: ['#10b981','#f59e0b','#3b82f6'] }] },
    options: { indexAxis: 'y', responsive: true, plugins:{ legend:{ display:false } }, scales:{ x:{ min:0, max:100, ticks:{ callback:(v)=> v+'%' } } } }
  });
  el.innerHTML = labels.map((name,i)=>`<div class="small">${i+1}. ${name} — ${data[i]}%</div>`).join('');
}

// Drag & Drop zone
(function(){
  const zone = document.getElementById('dndZone');
  const zipInput = document.getElementById('uploadZip');
  if (!zone || !zipInput) return;
  const toggle = (on)=> zone.classList[on?'add':'remove']('hover');
  zone.addEventListener('dragover', (e)=>{ e.preventDefault(); toggle(true); });
  zone.addEventListener('dragleave', ()=> toggle(false));
  zone.addEventListener('drop', async (e)=>{
    e.preventDefault(); toggle(false);
    const files = Array.from(e.dataTransfer.files||[]);
    const zip = files.find(f=> /\.zip$/i.test(f.name));
    if (!zip){ alert('โปรดวางไฟล์ .zip เท่านั้น'); return; }
    const dt = new DataTransfer(); dt.items.add(zip); zipInput.files = dt.files;
    await uploadZip();
  });
  zone.addEventListener('click', ()=> zipInput.click());
})();
</script>

<!-- Floating QR Button + Modal -->
<style>
.qr-fab {
  position: fixed; right: 20px; bottom: 24px; z-index: 1050;
  width: 56px; height: 56px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: #10b981; color: #fff; border: none;
  box-shadow: 0 8px 24px rgba(16,185,129,.35);
}
.qr-fab:hover { background: #0ea5a0; }
.qr-modal { display:none; position: fixed; inset:0; z-index: 2000;
  align-items: center; justify-content: center;
  background: rgba(0,0,0,.45); backdrop-filter: blur(2px);
}
.qr-modal.show { display:flex; }
.qr-card { background:#fff; border-radius:16px; padding:16px; width:min(90vw, 420px);
  box-shadow: 0 16px 40px rgba(0,0,0,.2); text-align:center; position:relative;
}
.qr-card h5 { margin: 0 0 8px 0; font-weight:700; }
.qr-close { position:absolute; top:10px; right:12px; border:none; background:transparent; color:#64748b }
.qr-img { max-width:100%; height:auto; border-radius:8px; border:1px solid #e5e7eb }
.qr-hint { font-size:.9rem; color:#64748b }
</style>

<button id="qrFab" class="qr-fab" title="เพิ่ม LINE บอท">
  <i class="fa-solid fa-bell"></i>
  <!-- ทางเลือก: fa-brands fa-line หรือ fa-comments -->
</button>
<div id="qrModal" class="qr-modal" role="dialog" aria-modal="true" aria-labelledby="qrTitle">
  <div class="qr-card">
    <button class="qr-close" aria-label="ปิด" id="qrClose"><i class="fa-solid fa-xmark"></i></button>
    <h5 id="qrTitle">สแกนเพื่อเพิ่ม LINE บอท</h5>
    <img class="qr-img" src="img/trash_content/LINEBOT/LINEQR.png" alt="LINE QR Code">
    <div class="qr-hint mt-2">สแกนด้วย LINE เพื่อรับการแจ้งเตือนจากระบบ</div>
  </div>
  </div>

<script>
(function(){
  const btn = document.getElementById('qrFab');
  const modal = document.getElementById('qrModal');
  const closeBtn = document.getElementById('qrClose');
  if (!btn || !modal || !closeBtn) return;
  btn.addEventListener('click', ()=> modal.classList.add('show'));
  closeBtn.addEventListener('click', ()=> modal.classList.remove('show'));
  modal.addEventListener('click', (e)=>{ if (e.target === modal) modal.classList.remove('show'); });
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>

// --- Enhanced Add Class Modal ---
(function(){
  const modalEl = document.getElementById('addClassModal');
  const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
  const btnOpen = document.getElementById('tmAddClassBtn');
  const form = document.getElementById('addClassForm');
  const nameInput = document.getElementById('newClassNameModal');
  const typeSelect = document.getElementById('newClassTypeModal');
  const toastEl = document.getElementById('tmToast');
  const toastBody = document.getElementById('tmToastBody');
  const toast = toastEl ? new bootstrap.Toast(toastEl) : null;

  function showToast(msg){
    if (!toast) return alert(msg);
    toastBody.textContent = msg;
    toast.show();
  }

  if (btnOpen && modal) {
    btnOpen.addEventListener('click', ()=>{ 
      form && form.reset();
      nameInput && nameInput.focus();
      modal.show(); 
    });
  }

  if (form) {
    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      // Bootstrap validation
      if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
      const name = nameInput.value.trim();
      const wtype = typeSelect.value;
      try{
        // 1) Create class on FastAPI (dataset side)
        const fd1 = new FormData();
        fd1.append('name', name);
        fd1.append('token', ADMIN_TOKEN);
        await apiPost(`${FASTAPI_BASE}/dataset/classes`, fd1);

        // 2) Save label -> waste_type mapping to our DB through this PHP
        const fd2 = new FormData();
        fd2.append('action', 'save_label_type');
        fd2.append('label', name);
        fd2.append('waste_type', wtype);
        fd2.append('token', ADMIN_TOKEN);
        const r = await fetch(window.location.href, { method: 'POST', body: fd2 });
        const j = await r.json().catch(()=>({success:false, detail:'bad json'}));
        if (!j.success) throw new Error(j.detail || 'save mapping failed');

        // 3) Refresh class list and close modal
        await loadClasses();
        modal.hide();
        showToast('เพิ่มคลาสและบันทึกประเภทสำเร็จ');
      }catch(err){
        console.error(err);
        showToast('เกิดข้อผิดพลาด: ' + String(err.message || err));
      }
    });
  }
})();

</script>
</body>
</html>
