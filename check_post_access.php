<?php
session_start();
require_once 'db.php';

// ตรวจสอบว่ามีการล็อกอินหรือไม่
if (!isset($_SESSION['userid'])) {
    header('Location: login.html');
    exit;
}

// ตรวจสอบการระงับ
$query = "
    SELECT 
        restriction_id, 
        end_date, 
        is_permanent,
        reason 
    FROM user_restrictions 
    WHERE 
        userID = ? AND 
        restriction_type IN ('post', 'both') AND 
        status = 'active' AND 
        (CAST(is_permanent AS UNSIGNED) = 1 OR (CAST(is_permanent AS UNSIGNED) = 0 AND end_date > NOW()))
    LIMIT 1
";

$stmt = $pdo->prepare($query);
$stmt->execute([$_SESSION['userid']]);
$restriction = $stmt->fetch(PDO::FETCH_ASSOC);

// ถ้ามีการระงับให้ส่งข้อมูลไปที่หน้า post_restricted.php
if ($restriction) {
    $restrictionInfo = json_encode($restriction);
    $_SESSION['restriction_info'] = $restrictionInfo;
    header('Location: post_restricted.php');
    exit;
}

// ถ้าไม่มีการระงับให้เข้าถึงหน้า post.php ได้
header('Location: post.php');
exit;
?>