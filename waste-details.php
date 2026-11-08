<?php
// ใช้ไฟล์เชื่อมต่อฐานข้อมูลแบบเดิมของโปรเจกต์คุณ
// ให้แก้ชื่อไฟล์ตามจริง ถ้าไม่ใช่ db.php
require_once __DIR__ . '/db.php';

// ---- ตั้งค่าชื่อ method ภาษาไทย ----
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

try {
  // ดึงทุกแถว (เริ่มจาก trash เพื่อให้มีการ์ดแม้ไม่มีเนื้อหา)
  $sql = "
    SELECT
      t.trashId,
      t.name,
      t.Waste_type,
      t.image  AS trash_image,
      tc.contentId,
      LOWER(tc.Management_type) AS management_key
    FROM trash t
    LEFT JOIN trash_content tc ON tc.trashId = t.trashId
    ORDER BY t.trashId, FIELD(LOWER(tc.Management_type),'recycle','reuse','dispose'), tc.contentId
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // จัดกลุ่ม "การ์ดละ 1 ชิ้นขยะ"
  $grouped = []; // trashId => [name, Waste_type, image, methods[ key => ['label','contentId'] ]]
  foreach ($rows as $r) {
    $trashId = (int)($r['trashId'] ?? 0);
    if ($trashId <= 0) continue;

    if (!isset($grouped[$trashId])) {
      $grouped[$trashId] = [
        'trashId'    => $trashId,
        'name'       => $r['name'] ?? 'ไม่มีชื่อ',
        'waste_type' => $r['Waste_type'] ?? '',
        'image'      => $r['trash_image'] ?? null,
        'methods'    => [], // key => ['label','contentId']
      ];
    }

    $mKey = trim(strtolower($r['management_key'] ?? ''));
    if ($mKey !== '' && !isset($grouped[$trashId]['methods'][$mKey])) {
      $grouped[$trashId]['methods'][$mKey] = [
        'label'     => $METHOD_LABEL[$mKey] ?? $mKey,
        'contentId' => (int)($r['contentId'] ?? 0),
      ];
    }
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
  <title>รายการขยะ</title>
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
    body { font-family: 'Kanit', sans-serif;}
    .sort-btn.active {
      background-color: rgba(5, 150, 105, var(--tw-bg-opacity));
      color: white;
    }
  </style>
</head>
<body >
  <?php include 'nav.php'; ?>
  <div class="">
    <!-- ฟิลเตอร์และช่องค้นหา -->
    <div class="p-4 rounded-lg ">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- ช่องค้นหา -->
        <div>
          <label for="searchInput" class="block text-sm font-medium text-gray-700 mb-1">ค้นหาขยะ</label>
          <div class="relative">
            <input type="text" id="searchInput" placeholder="พิมพ์ชื่อขยะ..." 
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <i class="fas fa-search absolute right-3 top-3 text-gray-400"></i>
          </div>
        </div>
        
        <!-- ตัวกรองประเภทขยะ -->
        <div>
          <label for="wasteTypeFilter" class="block text-sm font-medium text-gray-700 mb-1">กรองตามประเภท</label>
          <select id="wasteTypeFilter" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
            <option value="all">ทั้งหมด</option>
            <option value="ขยะรีไซเคิล">ขยะรีไซเคิล</option>
            <option value="ขยะอันตราย">ขยะอันตราย</option>
            <option value="ขยะทั่วไป">ขยะทั่วไป</option>
            <option value="ขยะอินทรีย์">ขยะอินทรีย์</option>
          </select>
        </div>
        
        <!-- ตัวเลือกการเรียงลำดับ -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">เรียงลำดับ</label>
          <div class="flex space-x-2">
            <button id="sortAZ" class="sort-btn px-3 py-2 bg-gray-200 rounded-lg text-sm active">ก-ฮ</button>
            <button id="sortZA" class="sort-btn px-3 py-2 bg-gray-200 rounded-lg text-sm">ฮ-ก</button>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6" id="wasteGrid">
      <?php if (!empty($grouped)): ?>
        <?php foreach ($grouped as $g): ?>
          <?php
            $name      = htmlspecialchars($g['name']);
            $wasteType = htmlspecialchars($g['waste_type'] ?: 'ไม่ระบุ');
            $img       = !empty($g['image']) ? $IMG_BASE . htmlspecialchars($g['image']) : $IMG_BASE . 'default.jpg';

            // ค่าเริ่มต้นของปุ่ม "ดูข้อมูลเพิ่มเติม" = contentId ของวิธีแรก (ถ้ามี)
            $defaultContentId = 0;
            if (!empty($g['methods'])) {
              $first = reset($g['methods']);
              $defaultContentId = (int)($first['contentId'] ?? 0);
            }

            // data-management-type: เก็บรายการ label วิธี เช่น "รีไซเคิล, นำกลับมาใช้ใหม่"
            $labels = [];
            foreach ($g['methods'] as $info) $labels[] = $info['label'];
            $dataManagement = htmlspecialchars(implode(', ', $labels));
            
            // กำหนดคลาสสีตามประเภทขยะ
            $wasteTypeBg = getWasteTypeColor($wasteType, 'bg');
            $wasteTypeText = getWasteTypeColor($wasteType, 'text');
            $wasteTypeBorder = getWasteTypeColor($wasteType, 'border');
          ?>
          <div class="waste-card rounded-xl shadow bg-white p-5 flex flex-col" data-type="<?= $wasteType ?>" data-management-type="<?= $dataManagement ?>" data-name="<?= $name ?>">
            <h2 class="text-xl font-semibold text-gray-800 mb-2"><?= $name ?></h2>
            <img src="<?= $img ?>" alt="<?= $name ?>" class="mb-3 rounded-lg w-full h-48 object-contain bg-white">

            <div class="flex items-center mb-2">
              <span class="text-sm font-medium text-gray-700">ประเภทขยะ:</span>
              <span class="ml-2 px-2 py-1 <?= $wasteTypeBg ?> <?= $wasteTypeText ?> <?= $wasteTypeBorder ?> rounded-full text-xs border"><?= $wasteType ?></span>
            </div>

            <div class="mb-3">
              <span class="text-sm font-medium text-gray-700">วิธีการจัดการ:</span>
              <div class="mt-2 flex flex-wrap gap-2">
                <?php if (!empty($g['methods'])): ?>
                  <?php $first = true; foreach ($g['methods'] as $mk => $info): ?>
                    <?php
                      $label     = htmlspecialchars($info['label']);
                      $contentId = (int)$info['contentId'];
                      $activeCls = $first ? ' is-active bg-green-100 border-green-300' : '';
                    ?>
                    <button
                      type="button"
                      class="method-pill px-3 py-1 rounded-full text-xs border border-gray-300 hover:bg-gray-100<?= $activeCls ?>"
                      data-content-id="<?= $contentId ?>"
                      aria-pressed="<?= $first ? 'true' : 'false' ?>">
                      <?= $label ?>
                    </button>
                    <?php $first = false; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="text-sm text-gray-500 ml-2">ยังไม่มีวิธีการจัดการ</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="mt-auto">
              <a
                class="btn-more block text-center bg-green-600 text-white rounded-lg py-2 hover:bg-green-700"
                href="<?= $defaultContentId ? 'waste-info.php?contentId=' . $defaultContentId : 'javascript:void(0)' ?>"
                data-base="waste-info.php?contentId=">
                ดูข้อมูลเพิ่มเติม
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="col-span-full text-center text-gray-500">ไม่พบข้อมูลขยะ</p>
      <?php endif; ?>
    </div>
    
    <!-- ข้อความเมื่อไม่พบผลลัพธ์ -->
    <div id="noResults" class="hidden text-center py-8 bg-white rounded-lg shadow mt-6">
      <i class="fas fa-search fa-3x text-gray-300 mb-4"></i>
      <h3 class="text-xl font-semibold text-gray-700">ไม่พบขยะที่ค้นหา</h3>
      <p class="text-gray-500">ลองเปลี่ยนคำค้นหาหรือตัวกรองดูนะ</p>
    </div>
  </div>
    <?php include 'chat-button.php'; ?>
  <script>
    // เมื่อคลิกชิป "วิธีการจัดการ" ให้ทำ active และอัปเดต href ปุ่ม "ดูข้อมูลเพิ่มเติม"
    document.addEventListener('click', function (e) {
      const pill = e.target.closest('.method-pill');
      if (!pill) return;

      const card = pill.closest('.waste-card');
      if (!card) return;

      // toggle active
      card.querySelectorAll('.method-pill').forEach(b => {
        b.classList.remove('is-active', 'bg-green-100', 'border-green-300');
        b.setAttribute('aria-pressed', 'false');
      });
      pill.classList.add('is-active', 'bg-green-100', 'border-green-300');
      pill.setAttribute('aria-pressed', 'true');

      // อัปเดตลิงก์
      const contentId = pill.getAttribute('data-content-id');
      const moreBtn = card.querySelector('.btn-more');
      const base = moreBtn.getAttribute('data-base'); // "waste-info.php?contentId="
      if (contentId) moreBtn.href = base + encodeURIComponent(contentId);
    });

    // ฟังก์ชันการค้นหาและกรอง
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('searchInput');
      const wasteTypeFilter = document.getElementById('wasteTypeFilter');
      const sortAZ = document.getElementById('sortAZ');
      const sortZA = document.getElementById('sortZA');
      const wasteGrid = document.getElementById('wasteGrid');
      const noResults = document.getElementById('noResults');
      const wasteCards = Array.from(document.querySelectorAll('.waste-card'));
      
      let currentSort = 'az';
      
      // ฟังก์ชันกรองการ์ด
      function filterCards() {
        const searchText = searchInput.value.toLowerCase();
        const selectedType = wasteTypeFilter.value;
        
        let visibleCards = 0;
        
        wasteCards.forEach(card => {
          const name = card.getAttribute('data-name').toLowerCase();
          const type = card.getAttribute('data-type');
          const matchesSearch = name.includes(searchText);
          const matchesType = selectedType === 'all' || type === selectedType;
          
          if (matchesSearch && matchesType) {
            card.style.display = 'flex';
            visibleCards++;
          } else {
            card.style.display = 'none';
          }
        });
        
        // แสดงข้อความเมื่อไม่พบผลลัพธ์
        if (visibleCards === 0) {
          noResults.classList.remove('hidden');
          wasteGrid.classList.add('hidden');
        } else {
          noResults.classList.add('hidden');
          wasteGrid.classList.remove('hidden');
        }
        
        // เรียงลำดับการ์ด
        sortCards();
      }
      
      // ฟังก์ชันเรียงลำดับการ์ด
      function sortCards() {
        const container = wasteGrid;
        const cards = Array.from(container.querySelectorAll('.waste-card'));
        
        // กรองเฉพาะการ์ดที่แสดงอยู่
        const visibleCards = cards.filter(card => card.style.display !== 'none');
        
        visibleCards.sort((a, b) => {
          const nameA = a.getAttribute('data-name');
          const nameB = b.getAttribute('data-name');
          
          // ใช้ Intl.Collator สำหรับการเรียงลำดับภาษาไทย
          const collator = new Intl.Collator('th');
          
          if (currentSort === 'az') {
            return collator.compare(nameA, nameB);
          } else {
            return collator.compare(nameB, nameA);
          }
        });
        
        // ลบการ์ดทั้งหมดออกแล้วเพิ่มกลับตามลำดับใหม่
        // ต้องลบและเพิ่มเฉพาะการ์ดที่มองเห็นได้เท่านั้น
        visibleCards.forEach(card => {
          container.appendChild(card);
        });
      }
      
      // ตั้งค่าการเรียงลำดับ
      function setSortOrder(order) {
        currentSort = order;
        sortAZ.classList.toggle('active', order === 'az');
        sortZA.classList.toggle('active', order === 'za');
        sortCards();
      }
      
      // อีเวนต์ลิสเตอร์
      searchInput.addEventListener('input', filterCards);
      wasteTypeFilter.addEventListener('change', filterCards);
      sortAZ.addEventListener('click', () => setSortOrder('az'));
      sortZA.addEventListener('click', () => setSortOrder('za'));
      
      // เรียกฟังก์ชันกรองครั้งแรก
      filterCards();
    });
  </script>
</body>
</html>