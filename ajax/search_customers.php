<?php
// ไฟล์: ajax/search_customers.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'ไม่ได้รับอนุญาต']);
    exit;
}

$phone = trim($_GET['phone'] ?? '');

if (strlen($phone) < 3) {
    echo json_encode([]);
    exit;
}

try {
    // ค้นหาลูกค้าจากเบอร์โทรศัพท์
    $sql = "
        SELECT id, name, phone, email
        FROM customers 
        WHERE phone LIKE :phone 
        ORDER BY 
            CASE 
                WHEN phone = :exact_phone THEN 1
                WHEN phone LIKE :start_phone THEN 2
                ELSE 3
            END,
            name
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $likePhone = '%' . $phone . '%';
    $startPhone = $phone . '%';
    $stmt->bindParam(':phone', $likePhone);
    $stmt->bindParam(':exact_phone', $phone);
    $stmt->bindParam(':start_phone', $startPhone);
    $stmt->execute();
    
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($customers);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
?>