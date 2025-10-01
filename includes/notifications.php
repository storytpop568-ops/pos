<?php
require_once 'config.php';
require_once 'auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'get_count':
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$_SESSION['user_id']]);
            $result = $stmt->fetch();
            echo json_encode(['count' => (int)$result['count']]);
            break;
            
        case 'mark_all_read':
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            echo json_encode(['success' => true, 'message' => 'Marked all as read']);
            break;
            
        case 'get_recent':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . $limit);
            $stmt->execute([$_SESSION['user_id']]);
            $notifications = $stmt->fetchAll();
            echo json_encode($notifications);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action not recognized']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}