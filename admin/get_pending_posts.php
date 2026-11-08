<?php
// get_pending_posts.php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $status = isset($_GET['status']) ? $_GET['status'] : 'pending';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 8;
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    // Query ข้อมูล
    $sql = "SELECT p.*, t.name as trash_name, t.Waste_type, u.name as user_name, u.profile_img 
            FROM post p 
            JOIN users u ON p.userId = u.userID 
            JOIN trash t ON p.trashId = t.trashId 
            WHERE 1=1";
    
    if ($status !== 'all') {
        $sql .= " AND p.status = :status";
    }
    if ($search) {
        $sql .= " AND (p.title LIKE :search OR t.name LIKE :search OR u.name LIKE :search)";
    }
    
    $sql .= " ORDER BY p.date DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    
    if ($status !== 'all') {
        $stmt->bindValue(':status', $status);
    }
    if ($search) {
        $stmt->bindValue(':search', "%$search%");
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Query จำนวนทั้งหมด
    $sql = "SELECT COUNT(*) as total FROM post p 
            JOIN users u ON p.userId = u.userID 
            JOIN trash t ON p.trashId = t.trashId 
            WHERE 1=1";
            
    if ($status !== 'all') {
        $sql .= " AND p.status = :status";
    }
    if ($search) {
        $sql .= " AND (p.title LIKE :search OR t.name LIKE :search OR u.name LIKE :search)";
    }

    $stmt = $pdo->prepare($sql);
    
    if ($status !== 'all') {
        $stmt->bindValue(':status', $status);
    }
    if ($search) {
        $stmt->bindValue(':search', "%$search%");
    }
    $stmt->execute();
    
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'data' => [
            'posts' => $posts,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อกับฐานข้อมูล: ' . $e->getMessage()
    ]);
}
?>