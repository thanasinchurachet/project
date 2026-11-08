<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Check if user is logged in
    $currentUserId = $_SESSION['userid'] ?? null;
    if (!$currentUserId) {
        throw new Exception('กรุณาเข้าสู่ระบบ');
    }

    // Check if POST ID is set and is a valid number
    $postId = filter_input(INPUT_GET, 'postId', FILTER_VALIDATE_INT);
    $sortOrder = $_GET['sortOrder'] ?? 'newest';

    if ($postId === false || $postId === null) {
        throw new Exception('Invalid or missing Post ID');
    }

    // Validate sort order
    $sortOrder = in_array($sortOrder, ['newest', 'oldest']) ? $sortOrder : 'newest';

    // Prepare SQL query with current user check
    $query = "
        SELECT 
            c.commentID,
            c.content,
            c.date_created,
            u.userID, 
            u.name AS username,
            u.profile_img AS avatar_path,
            CASE WHEN c.userID = :currentUserId THEN 1 ELSE 0 END AS is_current_user_comment
        FROM comment c
        JOIN users u ON c.userID = u.userID
        WHERE c.postID = :postId AND c.status = 'visible'
    ";

    // Add ORDER BY clause based on sort order
    if ($sortOrder === 'newest') {
        $query .= " ORDER BY c.date_created DESC";
    } else {
        $query .= " ORDER BY c.date_created ASC";
    }

    // Use prepared statement to prevent SQL injection
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':postId', $postId, PDO::PARAM_INT);
    $stmt->bindParam(':currentUserId', $currentUserId, PDO::PARAM_INT);
    
    // Execute and fetch
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Validate comments exist
    if (empty($comments)) {
        echo json_encode([]);
        exit;
    }

    // Format dates
    foreach ($comments as &$comment) {
        // Convert to Thai format
        $commentDate = new DateTime($comment['date_created']);
        $comment['date_created'] = $commentDate->format('Y-m-d H:i:s');
        
        // Convert is_current_user_comment to boolean
        $comment['is_current_user_comment'] = (bool)$comment['is_current_user_comment'];
    }

    // Return JSON response
    echo json_encode($comments);
    exit;

} catch (PDOException $e) {
    // Log PDO specific errors
    error_log('PDO Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'details' => 'An error occurred while fetching comments'
    ]);
    exit;
} catch (Exception $e) {
    // Log general errors
    error_log('Error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'details' => 'An unexpected error occurred'
    ]);
    exit;
}
?>