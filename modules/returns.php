<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ฟังก์ชันบันทึกประวัติการเคลื่อนไหวสต็อก
function logStockMovement($pdo, $product_id, $movement_type, $quantity, $note, $user_id) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements 
            (product_id, movement_type, quantity, note, user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$product_id, $movement_type, $quantity, $note, $user_id]);
    } catch (PDOException $e) {
        error_log("Error logging stock movement: " . $e->getMessage());
        return false;
    }
}

// ฟังก์ชันตรวจสอบและสร้างตาราง stock_movements หากไม่มี
function ensureStockMovementsTable($pdo) {
    try {
        $sql = "CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            movement_type ENUM('in', 'out', 'return', 'exchange_in', 'exchange_out') NOT NULL,
            quantity INT NOT NULL,
            note TEXT,
            user_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )";
        $pdo->exec($sql);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating stock_movements table: " . $e->getMessage());
        return false;
    }
}

// เรียกใช้ฟังก์ชันตรวจสอบตาราง
ensureStockMovementsTable($pdo);

// ตรวจสอบการกระทำ
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// ฟังก์ชันจัดการการคืนสินค้า
function getReturns($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, c.name as customer_name, s.sale_code, u.username as created_by_name
            FROM returns r 
            LEFT JOIN customers c ON r.customer_id = c.id 
            LEFT JOIN sales s ON r.sale_id = s.id 
            LEFT JOIN users u ON r.created_by = u.id 
            ORDER BY r.return_date DESC, r.created_at DESC
        ");
        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting returns: " . $e->getMessage());
        return [];
    }
}

function getReturn($pdo, $id) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, c.name as customer_name, c.phone as customer_phone, 
                   s.sale_code, u.username as created_by_name
            FROM returns r 
            LEFT JOIN customers c ON r.customer_id = c.id 
            LEFT JOIN sales s ON r.sale_id = s.id 
            LEFT JOIN users u ON r.created_by = u.id 
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting return: " . $e->getMessage());
        return false;
    }
}

function getReturnItems($pdo, $return_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT ri.*, p.product_name, p.product_code,
                   ep.product_name as exchange_product_name, 
                   ep.product_code as exchange_product_code
            FROM return_items ri 
            LEFT JOIN products p ON ri.product_id = p.id 
            LEFT JOIN products ep ON ri.exchange_product_id = ep.id 
            WHERE ri.return_id = ?
        ");
        $stmt->execute([$return_id]);
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting return items: " . $e->getMessage());
        return [];
    }
}

function getSales($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, c.name as customer_name
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            ORDER BY s.sale_date DESC
            LIMIT 100
        ");
        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting sales: " . $e->getMessage());
        return [];
    }
}

function getSaleItems($pdo, $sale_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT si.*, p.product_name, p.product_code, p.quantity as stock_quantity
            FROM sale_items si 
            LEFT JOIN products p ON si.product_id = p.id 
            WHERE si.sale_id = ?
        ");
        $stmt->execute([$sale_id]);
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting sale items: " . $e->getMessage());
        return [];
    }
}

function addReturn($pdo, $data) {
    $pdo->beginTransaction();
    
    try {
        // ตรวจสอบข้อมูลที่จำเป็น
        if (empty($data['return_code']) || empty($data['items']) || count($data['items']) == 0) {
            throw new Exception('ข้อมูลไม่ครบถ้วน: เลขที่คืนสินค้าและรายการสินค้าจำเป็น');
        }

        // ตรวจสอบว่า return_code ซ้ำหรือไม่
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM returns WHERE return_code = ?");
        $stmt->execute([$data['return_code']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('เลขที่คืนสินค้านี้มีอยู่แล้วในระบบ');
        }

        // บันทึกการคืนสินค้า
        $stmt = $pdo->prepare("
            INSERT INTO returns 
            (return_code, sale_id, customer_id, return_type, reason, status, 
             total_amount, refund_amount, refund_method, created_by, return_date, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['return_code'],
            !empty($data['sale_id']) ? $data['sale_id'] : null,
            !empty($data['customer_id']) ? $data['customer_id'] : null,
            $data['return_type'] ?? 'return',
            $data['reason'] ?? '',
            $data['status'] ?? 'pending',
            $data['total_amount'] ?? 0,
            $data['refund_amount'] ?? 0,
            $data['refund_method'] ?? 'cash',
            $_SESSION['user_id']
        ]);
        
        $return_id = $pdo->lastInsertId();
        
        // บันทึกรายการคืนสินค้าและจัดการสต็อก
        foreach ($data['items'] as $item) {
            if (empty($item['product_id']) || $item['quantity'] <= 0) {
                continue;
            }

            // บันทึกรายการคืน
            $stmt = $pdo->prepare("
                INSERT INTO return_items 
                (return_id, product_id, quantity, unit_price, subtotal, reason, action, exchange_product_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $return_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'] ?? 0,
                $item['subtotal'] ?? 0,
                $item['reason'] ?? '',
                $item['action'] ?? 'refund',
                !empty($item['exchange_product_id']) ? $item['exchange_product_id'] : null
            ]);
            
            $action = $item['action'] ?? 'refund';
            
            // จัดการสต็อกตามประเภทการดำเนินการ
            if ($action === 'refund' || $action === 'store_credit') {
                // คืนสินค้า - เพิ่มสต็อก
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
                
                // บันทึกประวัติ
                logStockMovement(
                    $pdo, 
                    $item['product_id'], 
                    'return', 
                    $item['quantity'], 
                    "คืนสินค้า: {$data['return_code']} - " . ($item['reason'] ?? 'ไม่ระบุเหตุผล'),
                    $_SESSION['user_id']
                );
                
            } elseif ($action === 'exchange' && !empty($item['exchange_product_id'])) {
                // เปลี่ยนสินค้า
                
                // 1. เพิ่มสต็อกสินค้าที่คืน (สินค้าเก่า)
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
                
                // บันทึกประวัติ - สินค้าเข้า
                $stmt = $pdo->prepare("SELECT product_name FROM products WHERE id = ?");
                $stmt->execute([$item['product_id']]);
                $old_product = $stmt->fetch();
                
                logStockMovement(
                    $pdo, 
                    $item['product_id'], 
                    'exchange_in', 
                    $item['quantity'], 
                    "เปลี่ยนสินค้าเข้า: {$data['return_code']} - {$old_product['product_name']}",
                    $_SESSION['user_id']
                );
                
                // 2. ตรวจสอบสต็อกสินค้าที่จะเปลี่ยนให้ (สินค้าใหม่)
                $stmt = $pdo->prepare("SELECT product_name, quantity FROM products WHERE id = ?");
                $stmt->execute([$item['exchange_product_id']]);
                $new_product = $stmt->fetch();
                
                if (!$new_product) {
                    throw new Exception('ไม่พบสินค้าที่ต้องการเปลี่ยน');
                }
                
                if ($new_product['quantity'] < $item['quantity']) {
                    throw new Exception("สินค้า {$new_product['product_name']} มีในสต็อกเพียง {$new_product['quantity']} ชิ้น ไม่เพียงพอสำหรับการเปลี่ยน");
                }
                
                // 3. ลดสต็อกสินค้าที่เปลี่ยนให้ (สินค้าใหม่)
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['exchange_product_id']]);
                
                // บันทึกประวัติ - สินค้าออก
                logStockMovement(
                    $pdo, 
                    $item['exchange_product_id'], 
                    'exchange_out', 
                    $item['quantity'], 
                    "เปลี่ยนสินค้าออก: {$data['return_code']} - แลกจาก {$old_product['product_name']}",
                    $_SESSION['user_id']
                );
            }
        }
        
        $pdo->commit();
        return $return_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error adding return: " . $e->getMessage());
        throw $e;
    }
}

function updateReturnStatus($pdo, $id, $status) {
    try {
        $stmt = $pdo->prepare("
            UPDATE returns 
            SET status = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        return $stmt->execute([$status, $id]);
    } catch (PDOException $e) {
        error_log("Error updating return status: " . $e->getMessage());
        return false;
    }
}

function deleteReturn($pdo, $id) {
    $pdo->beginTransaction();
    
    try {
        // ดึงข้อมูลรายการคืนสินค้าเพื่อคืนสต็อก
        $items = getReturnItems($pdo, $id);
        $return = getReturn($pdo, $id);
        
        foreach ($items as $item) {
            // ย้อนกลับการดำเนินการสต็อก
            if ($item['action'] === 'refund' || $item['action'] === 'store_credit') {
                // ลดสต็อกที่เพิ่มไป
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET quantity = GREATEST(quantity - ?, 0)
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
                
                logStockMovement(
                    $pdo, 
                    $item['product_id'], 
                    'out', 
                    $item['quantity'], 
                    "ยกเลิกการคืนสินค้า: {$return['return_code']}",
                    $_SESSION['user_id']
                );
                
            } elseif ($item['action'] === 'exchange' && $item['exchange_product_id']) {
                // ย้อนกลับการเปลี่ยนสินค้า
                
                // 1. ลดสต็อกสินค้าเก่าที่เพิ่มไป
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET quantity = GREATEST(quantity - ?, 0)
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
                
                // 2. เพิ่มสต็อกสินค้าใหม่ที่ลดไป
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['exchange_product_id']]);
                
                logStockMovement(
                    $pdo, 
                    $item['exchange_product_id'], 
                    'in', 
                    $item['quantity'], 
                    "ยกเลิกการเปลี่ยนสินค้า: {$return['return_code']}",
                    $_SESSION['user_id']
                );
            }
        }
        
        // ลบรายการคืนสินค้า
        $stmt = $pdo->prepare("DELETE FROM return_items WHERE return_id = ?");
        $stmt->execute([$id]);
        
        // ลบการคืนสินค้า
        $stmt = $pdo->prepare("DELETE FROM returns WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting return: " . $e->getMessage());
        return false;
    }
}

function generateReturnCode(PDO $pdo): string {
    $prefix = 'RET' . date('Ym');
    $start  = strlen($prefix) + 1;

    $sql = "
        SELECT MAX(CAST(SUBSTRING(return_code, :start) AS UNSIGNED)) AS max_seq
        FROM returns
        WHERE return_code LIKE CONCAT(:prefix, '%')
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start'  => $start,
        ':prefix' => $prefix
    ]);
    $max = (int)$stmt->fetchColumn();

    $next = $max + 1;
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// จัดการฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $return_code = trim($_POST['return_code'] ?? '');
        $sale_id = !empty($_POST['sale_id']) ? (int)$_POST['sale_id'] : null;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $return_type = $_POST['return_type'] ?? 'return';
        $reason = trim($_POST['reason'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $refund_method = $_POST['refund_method'] ?? 'cash';
        
        $form_action = $_POST['action'] ?? 'add';
        $form_id = $_POST['id'] ?? 0;
        
        if (empty($return_code)) {
            throw new Exception('กรุณาระบุเลขที่คืนสินค้า');
        }
        
        // ดึงข้อมูลรายการจากฟอร์ม
        $items = [];
        $total_amount = 0;
        
        if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
            foreach ($_POST['product_id'] as $index => $product_id) {
                if (!empty($product_id) && !empty($_POST['quantity'][$index]) && $_POST['quantity'][$index] > 0) {
                    $quantity = (int)$_POST['quantity'][$index];
                    $unit_price = (float)($_POST['unit_price'][$index] ?? 0);
                    $subtotal = $quantity * $unit_price;
                    $item_reason = trim($_POST['item_reason'][$index] ?? '');
                    $action = $_POST['action_type'][$index] ?? 'refund';
                    $exchange_product_id = !empty($_POST['exchange_product_id'][$index]) ? (int)$_POST['exchange_product_id'][$index] : null;
                    
                    $items[] = [
                        'product_id' => (int)$product_id,
                        'quantity' => $quantity,
                        'unit_price' => $unit_price,
                        'subtotal' => $subtotal,
                        'reason' => $item_reason,
                        'action' => $action,
                        'exchange_product_id' => $exchange_product_id
                    ];
                    
                    $total_amount += $subtotal;
                }
            }
        }
        
        if (empty($items)) {
            throw new Exception('กรุณาเพิ่มรายการสินค้าที่ต้องการคืน');
        }
        
        $return_data = [
            'return_code' => $return_code,
            'sale_id' => $sale_id,
            'customer_id' => $customer_id,
            'return_type' => $return_type,
            'reason' => $reason,
            'status' => $status,
            'total_amount' => $total_amount,
            'refund_amount' => $total_amount,
            'refund_method' => $refund_method,
            'items' => $items
        ];
        
        if ($form_action === 'add') {
            $return_id = addReturn($pdo, $return_data);
            if ($return_id) {
                $_SESSION['success_message'] = 'บันทึกการคืนสินค้าสำเร็จ และอัพเดทสต็อกเรียบร้อยแล้ว';
                header('Location: returns.php?action=view&id=' . $return_id);
                exit;
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        error_log("Return processing error: " . $e->getMessage());
    }
    
    header('Location: returns.php');
    exit;
}

// อัปเดตสถานะ
if ($action === 'update_status' && $id > 0) {
    $status = $_GET['status'] ?? '';
    $valid_statuses = ['pending', 'approved', 'rejected', 'completed'];
    
    if (in_array($status, $valid_statuses)) {
        if (updateReturnStatus($pdo, $id, $status)) {
            $_SESSION['success_message'] = 'อัปเดตสถานะสำเร็จ';
        } else {
            $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการอัปเดตสถานะ';
        }
    } else {
        $_SESSION['error_message'] = 'สถานะไม่ถูกต้อง';
    }
    
    header('Location: returns.php?action=view&id=' . $id);
    exit;
}

// ลบการคืนสินค้า
if ($action === 'delete' && $id > 0) {
    if (deleteReturn($pdo, $id)) {
        $_SESSION['success_message'] = 'ลบการคืนสินค้าสำเร็จ และคืนสต็อกเรียบร้อยแล้ว';
    } else {
        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการลบการคืนสินค้า';
    }
    header('Location: returns.php');
    exit;
}

// ดูรายละเอียดการคืนสินค้า
if ($action === 'view' && $id > 0) {
    $return = getReturn($pdo, $id);
    $return_items = getReturnItems($pdo, $id);
    
    if (!$return) {
        $_SESSION['error_message'] = 'ไม่พบการคืนสินค้านี้';
        header('Location: returns.php');
        exit;
    }
}

$returns = getReturns($pdo);
$sales = getSales($pdo);
$new_return_code = generateReturnCode($pdo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการการคืนสินค้า - ระบบสต็อกสินค้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Phetsarath+OT:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page-header {
            background: linear-gradient(120deg, #4361ee, #3f37c9);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        .select2-container { width: 100% !important; }
        
        .exchange-product-info {
            border-left: 3px solid #0d6efd;
            background-color: #f8f9fa;
            font-size: 0.85rem;
            padding: 10px;
            margin-top: 10px;
            border-radius: 5px;
        }
        
        .exchange-product-info .info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .exchange-product-info .info-value {
            color: #0d6efd;
            font-weight: 500;
        }
        
        .stock-alert {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            padding: 8px;
            border-radius: 5px;
            margin-top: 5px;
        }
        
        .stock-ok {
            background-color: #d1e7dd;
            border: 1px solid #198754;
        }
        
        .action-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .return-summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        
        .stock-movement-badge {
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <?php if ($action === 'view' && isset($return)): ?>
            <!-- แสดงรายละเอียดการคืนสินค้า -->
            <div class="page-header">
                <div class="container">
                    <h1 class="h2 mb-0">
                        <i class="bi bi-arrow-return-left me-2"></i>
                        รายละเอียดการคืนสินค้า #<?php echo $return['return_code']; ?>
                    </h1>
                </div>
            </div>
            
            <div class="container">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <div class="return-summary-card">
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="bi bi-person me-2"></i><?php echo $return['customer_name']; ?></h5>
                            <p class="mb-1"><i class="bi bi-telephone me-2"></i><?php echo $return['customer_phone'] ?? '-'; ?></p>
                            <p class="mb-0"><i class="bi bi-receipt me-2"></i>ใบขาย: <?php echo $return['sale_code']; ?></p>
                        </div>
                        <div class="col-md-6 text-end">
                            <h5><i class="bi bi-calendar me-2"></i><?php echo date('d/m/Y H:i', strtotime($return['return_date'])); ?></h5>
                            <p class="mb-1"><i class="bi bi-person-badge me-2"></i><?php echo $return['created_by_name']; ?></p>
                            <p class="mb-0">
                                <span class="badge bg-light text-dark">
                                    <?php echo $return['return_type'] === 'return' ? 'คืนสินค้า' : 'เปลี่ยนสินค้า'; ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($return['reason'])): ?>
                    <div class="alert alert-info">
                        <strong><i class="bi bi-info-circle me-2"></i>เหตุผล:</strong> <?php echo htmlspecialchars($return['reason']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th width="15%">ราคา</th>
                                    <th width="15%">การดำเนินการ</th>
                                    <th width="25%">สินค้าที่เปลี่ยน</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $total = 0; ?>
                                <?php foreach ($return_items as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            <br><small class="text-muted">รหัส: <?php echo htmlspecialchars($item['product_code']); ?></small>
                                            <?php if (!empty($item['reason'])): ?>
                                                <br><small class="text-info">เหตุผล: <?php echo htmlspecialchars($item['reason']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo number_format($item['quantity']); ?></strong></td>
                                        <td>฿<?php echo number_format($item['subtotal'], 2); ?></td>
                                        <td>
                                            <?php
                                            $action_labels = [
                                                'refund' => ['คืนเงิน', 'bg-success'],
                                                'exchange' => ['เปลี่ยนสินค้า', 'bg-primary'],
                                                'store_credit' => ['เครดิตร้าน', 'bg-info']
                                            ];
                                            $action_info = $action_labels[$item['action']] ?? [$item['action'], 'bg-secondary'];
                                            ?>
                                            <span class="badge <?php echo $action_info[1]; ?> action-badge">
                                                <?php echo $action_info[0]; ?>
                                            </span>
                                            <br>
                                            <small class="stock-movement-badge bg-success text-white mt-1 d-inline-block">
                                                <i class="bi bi-arrow-up"></i> สต็อก +<?php echo $item['quantity']; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($item['action'] === 'exchange' && $item['exchange_product_id']): ?>
                                                <div class="exchange-product-info">
                                                    <div><span class="info-label">สินค้า:</span> <span class="info-value"><?php echo htmlspecialchars($item['exchange_product_name']); ?></span></div>
                                                    <div><span class="info-label">รหัส:</span> <span class="info-value"><?php echo htmlspecialchars($item['exchange_product_code']); ?></span></div>
                                                    <small class="stock-movement-badge bg-danger text-white mt-1 d-inline-block">
                                                        <i class="bi bi-arrow-down"></i> สต็อก -<?php echo $item['quantity']; ?>
                                                    </small>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php $total += $item['subtotal']; ?>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="3" class="text-end"><strong>รวม:</strong></td>
                                    <td colspan="3"><strong>฿<?php echo number_format($total, 2); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <a href="returns.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i> กลับ
                            </a>
                            <?php if ($return['status'] === 'pending'): ?>
                                <div>
                                    <button class="btn btn-success me-2" onclick="updateStatus(<?php echo $return['id']; ?>, 'approved')">
                                        <i class="bi bi-check-circle me-1"></i> อนุมัติ
                                    </button>
                                    <button class="btn btn-danger me-2" onclick="updateStatus(<?php echo $return['id']; ?>, 'rejected')">
                                        <i class="bi bi-x-circle me-1"></i> ปฏิเสธ
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="confirmDelete(<?php echo $return['id']; ?>, '<?php echo htmlspecialchars($return['return_code']); ?>')">
                                        <i class="bi bi-trash me-1"></i> ลบ
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- หน้ารายการคืนสินค้า -->
            <div class="page-header">
                <div class="container">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h1 class="h2 mb-0"><i class="bi bi-arrow-return-left me-2"></i>จัดการการคืนสินค้า</h1>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#returnModal">
                                <i class="bi bi-plus-circle me-1"></i> คืนสินค้า
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="container">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo $_SESSION['error_message']; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <table id="returnsTable" class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>เลขที่คืน</th>
                                    <th>ลูกค้า</th>
                                    <th>วันที่</th>
                                    <th>ประเภท</th>
                                    <th>ยอดเงิน</th>
                                    <th>สถานะ</th>
                                    <th>การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returns as $index => $ret): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><span class="badge bg-primary"><?php echo $ret['return_code']; ?></span></td>
                                        <td><?php echo htmlspecialchars($ret['customer_name']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($ret['return_date'])); ?></td>
                                        <td><?php echo $ret['return_type'] === 'return' ? 'คืนสินค้า' : 'เปลี่ยนสินค้า'; ?></td>
                                        <td>฿<?php echo number_format($ret['refund_amount'], 2); ?></td>
                                        <td>
                                            <?php
                                            $status_colors = [
                                                'pending' => 'warning',
                                                'approved' => 'primary',
                                                'completed' => 'success',
                                                'rejected' => 'danger'
                                            ];
                                            $status_labels = [
                                                'pending' => 'รอดำเนินการ',
                                                'approved' => 'อนุมัติแล้ว',
                                                'completed' => 'เสร็จสิ้น',
                                                'rejected' => 'ปฏิเสธ'
                                            ];
                                            ?>
                                            <span class="badge bg-<?php echo $status_colors[$ret['status']] ?? 'secondary'; ?>">
                                                <?php echo $status_labels[$ret['status']] ?? $ret['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="returns.php?action=view&id=<?php echo $ret['id']; ?>" class="btn btn-sm btn-outline-info">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($ret['status'] === 'pending'): ?>
                                                <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $ret['id']; ?>, '<?php echo htmlspecialchars($ret['return_code']); ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal เพิ่มการคืนสินค้า -->
    <div class="modal fade" id="returnModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">คืนสินค้า / เปลี่ยนสินค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="return_code" value="<?php echo $new_return_code; ?>">
                    
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>เลขที่คืนสินค้า:</strong> <?php echo $new_return_code; ?>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">การขายที่เกี่ยวข้อง</label>
                                <select name="sale_id" id="saleSelect" class="form-select">
                                    <option value="">เลือกใบขาย</option>
                                    <?php foreach ($sales as $sale): ?>
                                        <option value="<?php echo $sale['id']; ?>" 
                                                data-customer="<?php echo $sale['customer_id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($sale['customer_name']); ?>">
                                            <?php echo $sale['sale_code']; ?> - <?php echo htmlspecialchars($sale['customer_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">วิธีคืนเงิน</label>
                                <select name="refund_method" class="form-select">
                                    <option value="cash">เงินสด</option>
                                    <option value="transfer">โอนเงิน</option>
                                    <option value="credit">เครดิต</option>
                                </select>
                                <input type="hidden" name="customer_id" id="customerId">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">เหตุผล</label>
                            <textarea name="reason" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <h6 class="mb-3">รายการสินค้า</h6>
                        <table class="table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th width="25%">สินค้าที่คืน</th>
                                    <th width="10%">จำนวน</th>
                                    <th width="12%">ราคา</th>
                                    <th width="15%">การดำเนินการ</th>
                                    <th width="25%">สินค้าที่เปลี่ยน (ระบุรหัส)</th>
                                    <th width="8%">เหตุผล</th>
                                    <th width="5%"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                        
                        <button type="button" class="btn btn-sm btn-primary" onclick="addItemRow()">
                            <i class="bi bi-plus"></i> เพิ่มรายการ
                        </button>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-primary">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            $('#returnsTable').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json' }
            });
            
            $('#saleSelect').select2({
                dropdownParent: $('#returnModal')
            }).on('change', function() {
                const option = $(this).find(':selected');
                $('#customerId').val(option.data('customer'));
                loadSaleItems($(this).val());
            });
        });
        
        let itemCounter = 0;
        
        function loadSaleItems(saleId) {
            if (!saleId) return;
            
            $.get('../ajax/get_sale_items.php', { sale_id: saleId }, function(items) {
                $('#itemsBody').empty();
                items.forEach(item => {
                    addItemRow(item.product_id, item.product_name, item.product_code, item.quantity, item.unit_price);
                });
            });
        }
        
        function addItemRow(productId = '', productName = '', productCode = '', qty = 1, price = 0) {
            const row = `
                <tr id="row-${itemCounter}">
                    <td>
                        <input type="hidden" name="product_id[]" value="${productId}">
                        <div><strong>${productName}</strong></div>
                        <small class="text-muted">รหัส: ${productCode}</small>
                    </td>
                    <td><input type="number" name="quantity[]" class="form-control form-control-sm" value="${qty}" min="1"></td>
                    <td><input type="number" name="unit_price[]" class="form-control form-control-sm" value="${price}" step="0.01" readonly></td>
                    <td>
                        <select name="action_type[]" class="form-select form-select-sm action-select" data-row="${itemCounter}">
                            <option value="refund">คืนเงิน</option>
                            <option value="exchange">เปลี่ยนสินค้า</option>
                            <option value="store_credit">เครดิตร้าน</option>
                        </select>
                    </td>
                    <td>
                        <input type="hidden" name="exchange_product_id[]" id="exchange-id-${itemCounter}">
                        <input type="text" class="form-control form-control-sm exchange-code" 
                               id="exchange-code-${itemCounter}" 
                               placeholder="ระบุรหัสสินค้า" disabled>
                        <div id="exchange-info-${itemCounter}" class="exchange-product-info" style="display:none;">
                            <div class="info-label">ชื่อ: <span class="info-value" id="exchange-name-${itemCounter}"></span></div>
                            <div class="info-label">ราคา: <span class="info-value" id="exchange-price-${itemCounter}"></span></div>
                            <div class="info-label">สต็อก: <span class="info-value" id="exchange-stock-${itemCounter}"></span></div>
                        </div>
                    </td>
                    <td><input type="text" name="item_reason[]" class="form-control form-control-sm" placeholder="เหตุผล"></td>
                    <td><button type="button" class="btn btn-sm btn-danger" onclick="removeRow(${itemCounter})"><i class="bi bi-trash"></i></button></td>
                </tr>
            `;
            
            $('#itemsBody').append(row);
            
            // Event listener for action change
            $(`.action-select[data-row="${itemCounter}"]`).on('change', function() {
                const rowId = $(this).data('row');
                const isExchange = $(this).val() === 'exchange';
                $(`#exchange-code-${rowId}`).prop('disabled', !isExchange);
                if (!isExchange) {
                    $(`#exchange-id-${rowId}`).val('');
                    $(`#exchange-info-${rowId}`).hide();
                }
            });
            
            // Event listener for exchange product code
            $(`#exchange-code-${itemCounter}`).on('blur', function() {
                const code = $(this).val().trim();
                const rowId = $(this).attr('id').split('-')[2];
                
                if (code) {
                    $.get('../ajax/get_product_by_code.php', { code: code }, function(product) {
                        if (product && !product.error) {
                            $(`#exchange-id-${rowId}`).val(product.id);
                            $(`#exchange-name-${rowId}`).text(product.product_name);
                            $(`#exchange-price-${rowId}`).text('฿' + parseFloat(product.sale_price).toFixed(2));
                            $(`#exchange-stock-${rowId}`).text(product.quantity + ' ชิ้น');
                            
                            const qty = $(`#row-${rowId} input[name="quantity[]"]`).val();
                            if (parseInt(product.quantity) < parseInt(qty)) {
                                $(`#exchange-stock-${rowId}`).parent().addClass('text-danger');
                                Swal.fire('แจ้งเตือน', 'สต็อกสินค้าไม่เพียงพอ!', 'warning');
                            } else {
                                $(`#exchange-stock-${rowId}`).parent().removeClass('text-danger');
                            }
                            
                            $(`#exchange-info-${rowId}`).show();
                        } else {
                            Swal.fire('ไม่พบสินค้า', 'ไม่พบสินค้ารหัส: ' + code, 'error');
                            $(`#exchange-id-${rowId}`).val('');
                            $(`#exchange-info-${rowId}`).hide();
                        }
                    }).fail(function() {
                        Swal.fire('ข้อผิดพลาด', 'ไม่สามารถค้นหาสินค้าได้', 'error');
                    });
                }
            });
            
            itemCounter++;
        }
        
        function removeRow(id) {
            $(`#row-${id}`).remove();
        }
        
        function updateStatus(id, status) {
            Swal.fire({
                title: 'ยืนยันการอัพเดทสถานะ?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `returns.php?action=update_status&id=${id}&status=${status}`;
                }
            });
        }
        
        function confirmDelete(id, code) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                html: `คุณต้องการลบการคืนสินค้า <strong>${code}</strong>?<br><small class="text-danger">สต็อกจะถูกคืนกลับสู่สถานะเดิม</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                confirmButtonColor: '#dc3545',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `returns.php?action=delete&id=${id}`;
                }
            });
        }
    </script>
</body>
</html>