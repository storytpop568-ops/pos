<?php
// ไฟล์: ajax/search_products.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'ไม่ได้รับอนุญาต']);
    exit;
}

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

try {
    // ค้นหาสินค้าจากชื่อ, รหัสสินค้า, บาร์โค้ด, หมวดหมู่
    $sql = "
        SELECT p.*, c.category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.quantity > 0 
        AND (
            p.product_name LIKE :query OR 
            p.product_code LIKE :query OR 
            p.barcode LIKE :query OR 
            c.category_name LIKE :query OR
            p.tags LIKE :query
        )
        ORDER BY 
            CASE 
                WHEN p.product_code = :exact_query THEN 1
                WHEN p.barcode = :exact_query THEN 2
                WHEN p.product_name LIKE :exact_query THEN 3
                ELSE 4
            END,
            p.product_name
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $likeQuery = '%' . $query . '%';
    $stmt->bindParam(':query', $likeQuery);
    $stmt->bindParam(':exact_query', $query);
    $stmt->execute();
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($products);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
?>