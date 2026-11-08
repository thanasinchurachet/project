<?php
require_once 'db1.php';
header('Content-Type: application/json');

$page      = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit     = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$wasteType = isset($_GET['wasteType']) ? $_GET['wasteType'] : 'all'; // เดิมยังใช้ได้
$trashId   = isset($_GET['trashId']) ? intval($_GET['trashId']) : null;
$managementType = isset($_GET['managementType']) ? $_GET['managementType'] : 'all'; // << เพิ่มบรรทัดนี้

$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types  = '';

if ($wasteType !== 'all') {
  $where[] = 't.Waste_type = ?';
  $params[] = $wasteType;
  $types   .= 's';
}
if (!empty($trashId)) {
  $where[] = 'tc.trashId = ?';
  $params[] = $trashId;
  $types   .= 'i';
}
if (!empty($managementType) && $managementType !== 'all') {     // << กรองตามวิธีจัดการ
  $where[] = 'tc.Management_type = ?';
  $params[] = $managementType; // reuse / recycle / dispose
  $types   .= 's';
}

$whereSQL = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// --- count ---
$countSQL = "SELECT COUNT(*) AS total
             FROM trash_content tc
             JOIN trash t ON t.trashId = tc.trashId
             $whereSQL";
$countStmt = $conn->prepare($countSQL);
if ($types !== '') $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

// --- data ---
$dataSQL = "SELECT
              tc.contentId, tc.trashId, tc.Management_type, tc.content,
              t.name AS waste_name, t.Waste_type, t.image
            FROM trash_content tc
            JOIN trash t ON t.trashId = tc.trashId
            $whereSQL
            ORDER BY tc.contentId DESC
            LIMIT ?, ?";
$dataStmt = $conn->prepare($dataSQL);
if ($types !== '') {
  $bindTypes = $types . 'ii';
  $bindParams = array_merge($params, [$offset, $limit]);
  $dataStmt->bind_param($bindTypes, ...$bindParams);
} else {
  $dataStmt->bind_param('ii', $offset, $limit);
}
$dataStmt->execute();
$res = $dataStmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$dataStmt->close();

echo json_encode(['success'=>true,'data'=>[
  'contents'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit
]], JSON_UNESCAPED_UNICODE);
