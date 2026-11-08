<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userid'])) { echo json_encode(['success'=>false,'message'=>'unauthorized']); exit; }

$postId   = intval($_POST['post_id'] ?? 0);
$filename = basename($_POST['filename'] ?? '');

if ($postId<=0 || $filename==='') { echo json_encode(['success'=>false,'message'=>'invalid params']); exit; }

// (ออปชัน) ตรวจสอบว่าไฟล์นี้เป็นของโพสต์นี้จริง
$stmt = $pdo->prepare("SELECT images FROM post WHERE postID=? AND userId=?");
$stmt->execute([$postId, $_SESSION['userid']]);
$row = $stmt->fetch();

if (!$row) { echo json_encode(['success'=>false,'message'=>'post not found']); exit; }

// ถ้า images เก็บเป็น JSON
$imgs = [];
if (!empty($row['images'])) {
  $tmp = json_decode($row['images'], true);
  if (is_array($tmp)) $imgs = $tmp;
}

if (!in_array($filename, $imgs)) {
  echo json_encode(['success'=>false,'message'=>'file not in post']); exit;
}

$path = __DIR__ . '/img/post_img/' . $filename;
if (is_file($path)) {
  @unlink($path);
}

// อัปเดตฐานข้อมูลเอาชื่อรูปออก
$imgs = array_values(array_filter($imgs, fn($n) => $n !== $filename));
$stmt = $pdo->prepare("UPDATE post SET images=? WHERE postID=? AND userId=?");
$stmt->execute([json_encode($imgs), $postId, $_SESSION['userid']]);

echo json_encode(['success'=>true]);
