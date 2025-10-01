<?php
require_once '../includes/config.php';

if (isset($_GET['q'])) {
    $query = $_GET['q'];
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, product_code, product_name, sale_price, quantity 
            FROM products 
            WHERE product_code LIKE ? OR product_name LIKE ?
            ORDER BY product_code
            LIMIT 10
        ");
        $stmt->execute(["%$query%", "%$query%"]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($products);
    } catch (PDOException $e) {
        echo json_encode([]);
    }
}