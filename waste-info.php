<?php
require_once __DIR__ . '/db.php';

$METHOD_LABEL = [
  'recycle' => 'รีไซเคิล',
  'reuse'   => 'นำกลับมาใช้ใหม่',
  'dispose' => 'กำจัด',
];

// ---- ตั้งค่าสีสำหรับแต่ละประเภทขยะ ----
$WASTE_TYPE_COLORS = [
  'ขยะรีไซเคิล' => [
    'bg' => 'bg-green-100',
    'text' => 'text-green-800',
    'border' => 'border-green-300'
  ],
  'ขยะอันตราย' => [
    'bg' => 'bg-red-100',
    'text' => 'text-red-800',
    'border' => 'border-red-300'
  ],
  'ขยะทั่วไป' => [
    'bg' => 'bg-blue-100',
    'text' => 'text-blue-800',
    'border' => 'border-blue-300'
  ],
  'ขยะอินทรีย์' => [
    'bg' => 'bg-yellow-100',
    'text' => 'text-yellow-800',
    'border' => 'border-yellow-300'
  ]
];

// ฟังก์ชันดึงสีตามประเภทขยะ
function getWasteTypeColor($wasteType, $property = 'bg') {
  global $WASTE_TYPE_COLORS;
  $default = [
    'bg' => 'bg-gray-100',
    'text' => 'text-gray-800',
    'border' => 'border-gray-300'
  ];
  
  if (isset($WASTE_TYPE_COLORS[$wasteType]) && isset($WASTE_TYPE_COLORS[$wasteType][$property])) {
    return $WASTE_TYPE_COLORS[$wasteType][$property];
  }
  
  return $default[$property];
}

$IMG_BASE = 'img/trash_images/';
$CONTENT_IMG_BASE = 'img/trash_content/';



// รับพารามิเตอร์
$contentId = isset($_GET['contentId']) ? (int)$_GET['contentId'] : 0;
$trashId   = isset($_GET['trashId']) ? (int)$_GET['trashId'] : 0;

// เก็บ URL หน้าที่แล้วเพื่อใช้กับปุ่ม back
$previousPage = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'waste-details.php';

try {
  $trash = null;
  $content = null;
  $methods = []; // รายการวิธีทั้งหมดของขยะชิ้นนี้: [ ['contentId'=>..,'key'=>..,'label'=>..], ... ]

  if ($contentId > 0) {
    // ดึงเนื้อหาที่เลือก + ข้อมูลขยะ
    $sql = "
      SELECT
        t.trashId, t.name, t.Waste_type, t.image AS trash_image,
        tc.contentId, LOWER(tc.Management_type) AS management_key, tc.content
      FROM trash_content tc
      JOIN trash t ON t.trashId = tc.trashId
      WHERE tc.contentId = :cid
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $contentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $trash = [
        'trashId'    => (int)$row['trashId'],
        'name'       => $row['name'],
        'Waste_type' => $row['Waste_type'],
        'image'      => $row['trash_image'],
      ];
      $content = [
        'contentId'      => (int)$row['contentId'],
        'management_key' => strtolower($row['management_key'] ?? ''),
        'html'           => (string)$row['content'],
      ];
    }
  }

  // ถ้าไม่มี contentId แต่มี trashId ให้พาไปวิธีแรก (ถ้ามี)
  if (!$content && $trashId > 0) {
    $stmt = $pdo->prepare("
      SELECT
        t.trashId, t.name, t.Waste_type, t.image AS trash_image,
        tc.contentId, LOWER(tc.Management_type) AS management_key, tc.content
      FROM trash t
      LEFT JOIN trash_content tc ON tc.trashId = t.trashId
      WHERE t.trashId = :tid
      ORDER BY FIELD(LOWER(tc.Management_type),'recycle','reuse','dispose'), tc.contentId
      LIMIT 1
    ");
    $stmt->execute([':tid' => $trashId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      if (!empty($row['contentId'])) {
        // redirect ไป contentId แรก
        header('Location: waste-info.php?contentId='.(int)$row['contentId']);
        exit;
      } else {
        // มีแต่ข้อมูลขยะ แต่ไม่มีเนื้อหา
        $trash = [
          'trashId'    => (int)$row['trashId'],
          'name'       => $row['name'],
          'Waste_type' => $row['Waste_type'],
          'image'      => $row['trash_image'],
        ];
      }
    }
  }

  if (!$trash && !$content) {
    http_response_code(404);
    die('ไม่พบข้อมูลที่ต้องการ');
  }

  // ดึงรายการวิธีทั้งหมดของขยะชิ้นนี้ เพื่อทำชิปให้สลับวิธีได้
  $loadMethodsForTrashId = $trash ? $trash['trashId'] : ($trashId ?: 0);
  if ($loadMethodsForTrashId) {
    $stmt = $pdo->prepare("
      SELECT contentId, LOWER(Management_type) AS management_key
      FROM trash_content
      WHERE trashId = :tid
      ORDER BY FIELD(LOWER(Management_type),'recycle','reuse','dispose'), contentId
    ");
    $stmt->execute([':tid' => $loadMethodsForTrashId]);
    $methodsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($methodsRows as $mr) {
      $key = strtolower($mr['management_key'] ?? '');
      $methods[] = [
        'contentId' => (int)$mr['contentId'],
        'key'       => $key,
        'label'     => $METHOD_LABEL[$key] ?? $key,
      ];
    }
  }

  // เตรียมข้อมูลสำหรับแสดงผล
  $wasteName  = htmlspecialchars($trash['name'] ?? 'ไม่ระบุชื่อ');
  $wasteType  = htmlspecialchars($trash['Waste_type'] ?? 'ไม่ระบุประเภท');
  $wasteImage = !empty($trash['image']) ? $IMG_BASE . htmlspecialchars($trash['image']) : $IMG_BASE . 'default.jpg';

  $activeKey = $content['management_key'] ?? '';
  $contentHtml = $content['html'] ?? '';
  
  // กำหนดคลาสสีตามประเภทขยะ
  $wasteTypeBg = getWasteTypeColor($wasteType, 'bg');
  $wasteTypeText = getWasteTypeColor($wasteType, 'text');
  $wasteTypeBorder = getWasteTypeColor($wasteType, 'border');

  // ปรับ path ของรูปภาพภายในเนื้อหาให้ชี้ไปยังตำแหน่งไฟล์จริง
  // ฐานข้อมูลเก็บเป็น 'img/trash_content/...', แต่ไฟล์จริงอยู่ที่ 'admin/img/trash_content/...'
  if ($contentHtml) {
    // ถ้าไม่มีคำว่า admin/ อยู่ข้างหน้า ให้เติมให้ครบ และป้องกันการซ้ำ 'admin/admin/'
    $fix = $contentHtml;
    // 1) ล้างกรณีซ้ำ
    $fix = preg_replace('#admin\/admin\/img\/trash_content\/#i', 'admin/img/trash_content/', $fix);
    // 2) เติม admin/ ให้กรณีที่ยังเป็นเส้นทางสั้น
    $fix = preg_replace('#(?<!admin\/)img\/trash_content\/#i', 'admin/img/trash_content/', $fix);
    $contentHtml = $fix;
  }

} catch (Throwable $e) {
  http_response_code(500);
  die('Database error: ' . htmlspecialchars($e->getMessage()));
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>รายละเอียดขยะ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
      <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/share.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body {font-family: 'Kanit', sans-serif;}
  </style>
</head>
<body>
        <?php include 'nav.php'; ?>
  <div class="max-w-4xl mx-auto px-4 py-8">
    <!-- ปุ่ม Back -->
    <div class="mb-4">
      <a href="waste-details.php" class="inline-flex items-center text-blue-600 hover:text-blue-800">
        <i class="fas fa-arrow-left mr-2"></i> กลับไปหน้ารายการ
      </a>
    </div>
    
    <div class="bg-white rounded-xl shadow p-6">
      <div class="flex items-center space-x-4 mb-6">
        <img src="<?= $wasteImage ?>" alt="<?= $wasteName ?>" class="w-24 h-24 object-contain border rounded bg-white">
        <div>
          <h1 class="text-2xl font-bold"><?= $wasteName ?></h1>
          <p class="text-gray-600">
            ประเภทขยะ:
            <span class="<?= $wasteTypeBg ?> <?= $wasteTypeText ?> px-2 py-0.5 rounded text-xs border <?= $wasteTypeBorder ?>"><?= $wasteType ?></span>
          </p>
        </div>
      </div>

      <?php if (!empty($methods)): ?>
        <div class="mb-4">
          <p class="text-sm font-medium mb-2">เลือกวิธีการจัดการ:</p>
          <div class="flex flex-wrap gap-2">
            <?php foreach ($methods as $m): ?>
              <?php
                $isActive = ($m['key'] === $activeKey);
                $href = 'waste-info.php?contentId=' . (int)$m['contentId'];
              ?>
              <a href="<?= htmlspecialchars($href) ?>"
                 class="method-pill px-3 py-1 rounded-full text-xs border border-gray-300 hover:bg-gray-100 <?= $isActive ? 'is-active bg-green-100 border-green-300' : '' ?>">
                <?= htmlspecialchars($m['label']) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <hr class="my-4">
      <?php endif; ?>

      <?php if ($contentHtml): ?>
        <div class="prose max-w-none">
          <?= $contentHtml /* HTML content ที่ผ่านการปรับ path แล้ว */ ?>
        </div>
      <?php else: ?>
        <p class="text-gray-500">ยังไม่มีเนื้อหาสำหรับวิธีนี้</p>
      <?php endif; ?>
    </div>
  </div>
      <?php include 'chat-button.php'; ?>
</body>
</html>
