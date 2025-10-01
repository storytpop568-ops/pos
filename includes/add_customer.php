<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');

if (empty($name) || empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อและโทรศัพท์']);
    exit;
}

try {
    // ตรวจสอบว่าเบอร์โทรซ้ำหรือไม่
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
    $stmt->execute([$phone]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'เบอร์โทรนี้มีอยู่ในระบบแล้ว']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO customers (name, phone, email, address)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$name, $phone, $email, $address]);
    
    $customer_id = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();
    
    echo json_encode(['success' => true, 'customer' => $customer]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการสมัครสมาชิก']);
}