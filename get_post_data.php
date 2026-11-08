<?php
require_once 'db.php';

$postID = $_GET['id'] ?? 0;

// ดึงโพสต์
$stmt = $pdo->prepare("SELECT * FROM post WHERE postID = ?");
$stmt->execute([$postID]);
$post = $stmt->fetch();


?>
