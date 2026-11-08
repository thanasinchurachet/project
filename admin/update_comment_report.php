<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

function send_json_response($success, $message, $data = null, $http_code = 200) {
    http_response_code($http_code);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, 'Method Not Allowed', null, 405);
}

require_once 'db1.php';
if ($conn->connect_error) {
    send_json_response(false, 'Database connection failed: ' . $conn->connect_error, null, 500);
}

// รับค่าหลัก (ไม่ต้องพึ่ง reportIds แล้ว)
$action        = $_POST['action'] ?? '';
$postID        = intval($_POST['postID'] ?? 0);
$commentID     = intval($_POST['commentID'] ?? 0);
$notifyMessage = trim($_POST['notifyMessage'] ?? '');

if (!$action || !$postID || !$commentID) {
    send_json_response(false, 'กรุณาระบุข้อมูลให้ครบถ้วน', null, 400);
}

$conn->begin_transaction();
try {
    if ($action === 'hide') {
        // 1) ซ่อนคอมเมนต์
        $stmt = $conn->prepare("UPDATE comment SET status = 'hidden' WHERE commentID = ?");
        $stmt->bind_param("i", $commentID);
        $stmt->execute();
        $stmt->close();

        // 2) อัปเดตรายงานทั้งหมดของคอมเมนต์นี้ (ไม่มี reportID)
        $stmt = $conn->prepare("
            UPDATE comment_report 
            SET action_status = 'hidden', date_OPT = NOW()
            WHERE postID = ? AND commentID = ?
        ");
        $stmt->bind_param("ii", $postID, $commentID);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        // 3) บันทึก log — ใช้ค่าใน enum ที่มีอยู่จริง เช่น 'delete_comment'
        $action_type = 'delete_comment';
        $reason = 'ละเมิดกฎของชุมชน';
        $stmt = $conn->prepare("
            INSERT INTO admin_log (action, targetID, target_type, reason, date_action)
            VALUES (?, ?, 'comment', ?, NOW())
        ");
        $stmt->bind_param("sis", $action_type, $commentID, $reason);
        $stmt->execute();
        $stmt->close();

        // 4) แจ้งเตือนเจ้าของคอมเมนต์
        $stmt = $conn->prepare("
            SELECT c.userID, p.title 
            FROM comment c 
            JOIN post p ON c.postID = p.postId 
            WHERE c.commentID = ?
        ");
        $stmt->bind_param("i", $commentID);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $commenterID = intval($result['userID']);
            $post_title  = $result['title'];
            $commenterNotifyMessage = "คอมเมนต์ของคุณในโพสต์ '{$post_title}' ถูกซ่อนโดยผู้ดูแลระบบ เนื่องจากมีเนื้อหาไม่เหมาะสม";

            $stmt = $conn->prepare("INSERT INTO notifications (userID, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $stmt->bind_param("is", $commenterID, $commenterNotifyMessage);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'ignore') {
        // ตีความว่า "ไม่ดำเนินการ" = มองว่าไม่มีการละเมิด -> อัปเดตรายงานทั้งหมดของคอมเมนต์นี้เป็น ignored
        $stmt = $conn->prepare("
            UPDATE comment_report 
            SET action_status = 'ignored', date_OPT = NOW()
            WHERE postID = ? AND commentID = ?
        ");
        $stmt->bind_param("ii", $postID, $commentID);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } elseif ($action === 'ignore_partial') {
        // ไม่ดำเนินการเฉพาะบางรายการ: ยอมรับ userIDs[] หรือ reportIds[] (postID_commentID_userID)
        $userIDs = [];
        if (isset($_POST['userIDs']) && is_array($_POST['userIDs'])) {
            foreach ($_POST['userIDs'] as $u) {
                $uid = intval($u);
                if ($uid > 0) $userIDs[] = $uid;
            }
        }
        if (empty($userIDs) && isset($_POST['reportIds']) && is_array($_POST['reportIds'])) {
            foreach ($_POST['reportIds'] as $rid) {
                $parts = explode('_', (string)$rid);
                if (count($parts) === 3) {
                    $uid = intval($parts[2]);
                    if ($uid > 0) $userIDs[] = $uid;
                }
            }
        }
        if (empty($userIDs)) {
            throw new Exception('ไม่มีรายการที่เลือกสำหรับไม่ดำเนินการบางส่วน');
        }
        // Dynamic IN clause
        $placeholders = implode(',', array_fill(0, count($userIDs), '?'));
        $sql = "UPDATE comment_report SET action_status='ignored', date_OPT = NOW() WHERE postID=? AND commentID=? AND userID IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', 2 + count($userIDs));
        $params = array_merge([$types, $postID, $commentID], $userIDs);
        $refs = [];
        foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } else {
        throw new Exception('ไม่รู้จักการกระทำ: ' . htmlspecialchars($action));
    }

    // แจ้งเตือนผู้รายงาน (ถ้ามีข้อความ)
    if ($notifyMessage !== '') {
        $stmt = $conn->prepare("
            SELECT DISTINCT userID 
            FROM comment_report 
            WHERE postID = ? AND commentID = ?
        ");
        $stmt->bind_param("ii", $postID, $commentID);
        $stmt->execute();
        $res = $stmt->get_result();
        $reporterIDs = [];
        while ($row = $res->fetch_assoc()) {
            $reporterIDs[] = intval($row['userID']);
        }
        $stmt->close();

        if (!empty($reporterIDs)) {
            $stmt = $conn->prepare("INSERT INTO notifications (userID, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            foreach ($reporterIDs as $uid) {
                $stmt->bind_param("is", $uid, $notifyMessage);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    $conn->commit();
    send_json_response(true, 'อัปเดตสถานะสำเร็จ', [
        'action'   => $action,
        'affected' => $affected ?? 0
    ]);
} catch (Exception $e) {
    $conn->rollback();
    send_json_response(false, 'เกิดข้อผิดพลาด: ' . $e->getMessage(), null, 500);
} finally {
    $conn->close();
}
