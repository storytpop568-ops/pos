<?php
// ../ajax/get_sale_items.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// โหมดค้นหา "ใบขาย" สำหรับ Select2 (พิมพ์ค้นหาเลขที่ขาย/ชื่อลูกค้า)
if (isset($_GET['q'])) {
    $q = trim($_GET['q']);
    try {
        // ตารางอ้างอิงเดิมในระบบพี่: sales (id, sale_code, customer_id, created_at, ...)
        // join customers เพื่อดึงชื่อลูกค้ามาด้วย
        $sql = "
            SELECT s.id,
                   s.sale_code,
                   s.customer_id,
                   COALESCE(c.name, '') AS customer_name,
                   DATE_FORMAT(s.created_at, '%Y-%m-%d %H:%i') AS date
            FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            WHERE (s.sale_code LIKE :kw OR c.name LIKE :kw)
            ORDER BY s.created_at DESC
            LIMIT 20
        ";
        $stmt = $pdo->prepare($sql);
        $kw = "%{$q}%";
        $stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// โหมดเดิม: ดึง "รายการสินค้าในใบขาย" ด้วย sale_id
$sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;

if ($sale_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT si.*, p.product_name, p.product_code 
        FROM sale_items si 
        LEFT JOIN products p ON si.product_id = p.id 
        WHERE si.sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($items, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error'], JSON_UNESCAPED_UNICODE);
}
