<?php
// ไฟล์: ajax/get_product_by_code.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'ไม่ได้รับอนุญาต']);
    exit;
}

$code = trim($_GET['code'] ?? '');

if (empty($code)) {
    echo json_encode(['error' => 'กรุณาระบุรหัสสินค้า']);
    exit;
}

try {
    // ค้นหาด้วยรหัสสินค้าหรือบาร์โค้ดที่ตรงกันแน่นอน
    $sql = "
        SELECT p.*, c.category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.quantity > 0 
        AND (p.product_code = :code OR p.barcode = :code)
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':code', $code);
    $stmt->execute();
    
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        echo json_encode($product);
    } else {
        echo json_encode(['error' => 'ไม่พบสินค้า']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
?>