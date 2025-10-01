<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$phone = $_GET['phone'] ?? '';
$phone = trim($phone);
try {
    if ($phone === '') {
        echo json_encode([]);
        exit;
    }
    // Normalize: keep digits and '+' only, and search loosely
    $digits = preg_replace('/[^0-9+]/', '', $phone);
    $like = '%' . $digits . '%';
    $stmt = $pdo->prepare("
        SELECT id, full_name AS name, phone, email 
        FROM customers
        WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE REPLACE(REPLACE(REPLACE(?, ' ', ''), '-', ''), '+', '')
        OR phone LIKE ?
        ORDER BY updated_at DESC, full_name ASC
        LIMIT 15
    ");
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows ?: [] , JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error'], JSON_UNESCAPED_UNICODE);
}
