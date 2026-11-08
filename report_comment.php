<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

try {
    $commentId = $_POST['commentId'];
    $reason = $_POST['reason'];
    $userId = $_SESSION['userid'] ?? null;
    $postId = $_POST['postId'] ?? null;

    // ตรวจสอบการล็อกอิน
    if (!$userId) {
        throw new Exception("กรุณาล็อกอินก่อนรายงาน");
    }

    // ตรวจสอบข้อมูลครบถ้วน
    if (!$commentId || !$reason || !$postId) {
        throw new Exception("ข้อมูลไม่ครบถ้วน");
    }

    // ตรวจสอบว่าไม่ใช่คอมเมนต์ของตัวเอง
    $checkOwnerQuery = "SELECT userID FROM comment WHERE commentID = ?";
    $checkOwnerStmt = $pdo->prepare($checkOwnerQuery);
    $checkOwnerStmt->execute([$commentId]);
    $commentOwner = $checkOwnerStmt->fetch();

    if ($commentOwner && $commentOwner['userID'] === $userId) {
        throw new Exception("ไม่สามารถรายงานความคิดเห็นของตัวเองได้");
    }

    // ตรวจสอบโพสต์มีอยู่จริง
    $checkPostQuery = "SELECT postId FROM post WHERE postId = ?";
    $checkPostStmt = $pdo->prepare($checkPostQuery);
    $checkPostStmt->execute([$postId]);

    if ($checkPostStmt->rowCount() === 0) {
        throw new Exception("ไม่พบโพสต์ที่ระบุ");
    }

    // ตรวจสอบการรายงานซ้ำ
    $checkDuplicateQuery = "SELECT * FROM comment_report 
                           WHERE commentID = ? AND userID = ? AND postID = ?";
    $checkStmt = $pdo->prepare($checkDuplicateQuery);
    $checkStmt->execute([$commentId, $userId, $postId]);

    if ($checkStmt->rowCount() > 0) {
        throw new Exception("คุณได้รายงานคอมเมนต์นี้ไปแล้ว");
    }

    // บันทึกรายงานใหม่
    $query = "INSERT INTO comment_report (commentID, userID, postID, reason, Report_date) 
             VALUES (?, ?, ?, ?, NOW())";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$commentId, $userId, $postId, $reason]);

    echo json_encode(['success' => true, 'message' => 'รายงานความคิดเห็นสำเร็จ']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>