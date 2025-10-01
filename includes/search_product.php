<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
try {
    if ($q === '') { echo json_encode([]); exit; }

    $like = "%$q%";
    $stmt = $pdo->prepare("
        SELECT p.id,
               p.product_code,
               p.barcode,
               p.product_name,
               p.description,
               p.brand,
               p.tags,
               p.sale_price,
               p.quantity,
               c.category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'active' AND (
              p.product_code LIKE :like
           OR p.barcode LIKE :like
           OR p.product_name LIKE :like
           OR p.description LIKE :like
           OR p.brand LIKE :like
           OR p.tags LIKE :like
           OR c.category_name LIKE :like
        )
        ORDER BY p.product_name
        LIMIT 20
    ");
    $stmt->execute([':like' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows ?: [] , JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
