<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $postId = $_POST['postId'] ?? null;
    $status = $_POST['status'] ?? null;
    $reason = $_POST['reason'] ?? null;

    if (!$postId || !$status) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    // Start transaction
    $pdo->beginTransaction();

    // ดึงข้อมูลโพสต์และผู้ใช้
    $stmt = $pdo->prepare("
        SELECT p.userId, p.title, u.name 
        FROM post p 
        JOIN users u ON p.userId = u.userID 
        WHERE p.postId = ?
    ");
    $stmt->execute([$postId]);
    $postInfo = $stmt->fetch();

    if (!$postInfo) {
        throw new Exception('ไม่พบข้อมูลโพสต์');
    }

    // Update post status
    $stmt = $pdo->prepare("UPDATE post SET status = ?, Suspended_date = NOW() WHERE postId = ?");
    $stmt->execute([$status, $postId]);

    // สร้างข้อความแจ้งเตือน
    $message = '';
    if ($status === 'approved') {
        $message = "โพสต์ \"{$postInfo['title']}\" ของคุณได้รับการอนุมัติแล้ว";
    } else if ($status === 'suspended') {
        $message = "โพสต์ \"{$postInfo['title']}\" ของคุณถูกปฏิเสธ" . 
                  ($reason ? " เนื่องจาก: $reason" : "");
    }

    // บันทึกการแจ้งเตือน
    if ($message) {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (userID, message, created_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$postInfo['userId'], $message]);
    }

    // Log admin action
    $stmt = $pdo->prepare("
        INSERT INTO admin_log (action, targetID, target_type, reason) 
        VALUES (?, ?, 'post', ?)
    ");
    $action = $status === 'approved' ? 'approve_post' : 'delete_post';
    $stmt->execute([$action, $postId, $reason]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $status === 'approved' ? 'อนุมัติโพสต์เรียบร้อยแล้ว' : 'ปฏิเสธโพสต์เรียบร้อยแล้ว'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>