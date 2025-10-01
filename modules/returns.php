<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

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
                   ep.product_name as exchange_product_name, ep.product_code as exchange_product_code
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
        
        // บันทึกรายการคืนสินค้า
        foreach ($data['items'] as $item) {
            // ตรวจสอบข้อมูลรายการ
            if (empty($item['product_id']) || $item['quantity'] <= 0) {
                continue; // ข้ามรายการที่ไม่ถูกต้อง
            }

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
            
            // เพิ่มจำนวนสินค้าในสต็อกหากเป็นการคืนสินค้า
            if (($item['action'] ?? 'refund') === 'refund' || ($item['action'] ?? 'refund') === 'store_credit') {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET quantity = quantity + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // ลดจำนวนสินค้าในสต็อกหากเป็นการเปลี่ยนสินค้า
            if (($item['action'] ?? 'refund') === 'exchange' && !empty($item['exchange_product_id'])) {
                // ตรวจสอบสต็อกก่อนลด
                $stmt = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
                $stmt->execute([$item['exchange_product_id']]);
                $current_stock = $stmt->fetchColumn();
                
                if ($current_stock < $item['quantity']) {
                    throw new Exception('สินค้าที่ต้องการเปลี่ยนมีจำนวนในสต็อกไม่เพียงพอ');
                }
                
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET quantity = quantity - ? 
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['exchange_product_id']]);
            }
        }
        
        $pdo->commit();
        return $return_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error adding return: " . $e->getMessage());
        throw $e; // ส่ง exception กลับไปเพื่อแสดงข้อความผิดพลาด
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
        
        foreach ($items as $item) {
            // คืนจำนวนสินค้าในสต็อก (ย้อนกลับการดำเนินการ)
            if ($item['action'] === 'refund' || $item['action'] === 'store_credit') {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET quantity = GREATEST(quantity - ?, 0)
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // เพิ่มจำนวนสินค้าในสต็อกหากเป็นการเปลี่ยนสินค้า
            if ($item['action'] === 'exchange' && $item['exchange_product_id']) {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET quantity = quantity + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['exchange_product_id']]);
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
    // รูปแบบ: RETYYYYMM####  เช่น RET2025090001
    $prefix = 'RET' . date('Ym');            // RET + ปี(4) + เดือน(2)
    $start  = strlen($prefix) + 1;           // ตำแหน่งเริ่มของเลขลำดับ (ฐาน 1)

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
        
        // ตรวจสอบข้อมูลพื้นฐาน
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
            'refund_amount' => $total_amount, // สามารถปรับลดได้ในอนาคต
            'refund_method' => $refund_method,
            'items' => $items
        ];
        
        if ($form_action === 'add') {
            $return_id = addReturn($pdo, $return_data);
            if ($return_id) {
                $_SESSION['success_message'] = 'บันทึกการคืนสินค้าสำเร็จ';
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
        $_SESSION['success_message'] = 'ลบการคืนสินค้าสำเร็จ';
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
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-header {
            background: linear-gradient(120deg, #4361ee, #3f37c9);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        .select2-container { width: 100% !important; }
        .return-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }
        
        .return-card:hover {
            transform: translateY(-5px);
        }
        
        .return-icon {
            font-size: 2rem;
            color: #4361ee;
            margin-bottom: 1rem;
        }
        
        .action-buttons .btn {
            margin: 0 3px;
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        #returnsTable th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .btn-delete {
            transition: all 0.3s;
        }
        
        .btn-delete:hover {
            transform: scale(1.05);
            box-shadow: 0 0 10px rgba(220, 53, 69, 0.3);
        }
        
        .badge-status {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        .return-items-table th {
            background-color: #f8f9fa;
        }
        
        .item-row {
            transition: background-color 0.2s;
        }
        
        .item-row:hover {
            background-color: #f8f9fa;
        }
        
        .total-row {
            background-color: #e9ecef;
            font-weight: bold;
        }
        
        .status-badge {
            font-size: 0.8rem;
        }
        
        .status-pending {
            background-color: #ffc107;
            color: #000;
        }
        
        .status-approved {
            background-color: #198754;
        }
        
        .status-rejected {
            background-color: #dc3545;
        }
        
        .status-completed {
            background-color: #0d6efd;
        }
        
        .action-badge {
            font-size: 0.7rem;
        }
        
        .action-refund {
            background-color: #6f42c1;
        }
        
        .action-exchange {
            background-color: #fd7e14;
        }
        
        .action-store_credit {
            background-color: #20c997;
        }
        
        .exchange-product-info {
            border-left: 3px solid #0d6efd;
            font-size: 0.8rem;
        }
        
        .product-details div {
            margin-bottom: 2px;
        }
        
        .suggestion-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }
        
        .suggestion-item:hover {
            background-color: #f8f9fa;
        }
        
        .product-suggestions, .exchange-product-suggestions {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: 100%;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            
            <main class="">
                <!-- ส่วนหัวหน้า -->
                <div class="page-header">
                    <div class="container">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h1 class="h2 mb-0"><i class="bi bi-arrow-return-left me-2"></i> จัดการการคืนสินค้า</h1>
                                <p class="mb-0">จัดการรายการคืนสินค้าและเปลี่ยนสินค้า</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#returnModal" data-action="add">
                                    <i class="bi bi-plus-circle me-1"></i> คืนสินค้า
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="container">
                    <!-- แสดงข้อความแจ้งเตือน -->
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle me-2"></i> <?php echo $_SESSION['success_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i> <?php echo $_SESSION['error_message']; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>
                    
                    <?php if ($action === 'view' && isset($return)): ?>
                        <!-- แสดงรายละเอียดการคืนสินค้า -->
                        <div class="card mb-4">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-arrow-return-left me-2"></i>รายละเอียดการคืนสินค้า #<?php echo $return['return_code']; ?>
                                </h5>
                                <div>
                                    <span class="badge status-badge status-<?php echo $return['status']; ?>">
                                        <?php 
                                        $status_labels = [
                                            'pending' => 'รอดำเนินการ',
                                            'approved' => 'อนุมัติแล้ว',
                                            'rejected' => 'ปฏิเสธ',
                                            'completed' => 'เสร็จสิ้น'
                                        ];
                                        echo $status_labels[$return['status']] ?? $return['status'];
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <p><strong>ลูกค้า:</strong> <?php echo $return['customer_name']; ?></p>
                                        <p><strong>โทรศัพท์:</strong> <?php echo $return['customer_phone'] ?? '-'; ?></p>
                                        <p><strong>การขายที่เกี่ยวข้อง:</strong> <?php echo $return['sale_code']; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>ประเภท:</strong> 
                                            <?php echo $return['return_type'] === 'return' ? 'คืนสินค้า' : 'เปลี่ยนสินค้า'; ?>
                                        </p>
                                        <p><strong>วันที่คืน:</strong> <?php echo date('d/m/Y H:i', strtotime($return['return_date'])); ?></p>
                                        <p><strong>พนักงาน:</strong> <?php echo $return['created_by_name']; ?></p>
                                    </div>
                                </div>
                                
                                <?php if (!empty($return['reason'])): ?>
                                    <div class="alert alert-info mb-4">
                                        <strong>เหตุผล:</strong> <?php echo htmlspecialchars($return['reason']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="table-responsive mb-4">
                                    <table class="table table-bordered return-items-table">
                                        <thead>
                                            <tr>
                                                <th width="5%">#</th>
                                                <th width="35%">สินค้า</th>
                                                <th width="10%">จำนวน</th>
                                                <th width="15%">ราคาต่อหน่วย</th>
                                                <th width="15%">รวม</th>
                                                <th width="20%">การดำเนินการ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $total = 0; ?>
                                            <?php foreach ($return_items as $index => $item): ?>
                                                <tr class="item-row">
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                        <?php if (!empty($item['reason'])): ?>
                                                            <br>
                                                            <small class="text-muted">เหตุผล: <?php echo htmlspecialchars($item['reason']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo number_format($item['quantity']); ?></td>
                                                    <td>฿<?php echo number_format($item['unit_price'], 2); ?></td>
                                                    <td>฿<?php echo number_format($item['subtotal'], 2); ?></td>
                                                    <td>
                                                        <span class="badge action-badge action-<?php echo $item['action']; ?>">
                                                            <?php 
                                                            $action_labels = [
                                                                'refund' => 'คืนเงิน',
                                                                'exchange' => 'เปลี่ยนสินค้า',
                                                                'store_credit' => 'เครดิตร้าน'
                                                            ];
                                                            echo $action_labels[$item['action']] ?? $item['action'];
                                                            ?>
                                                        </span>
                                                        <?php if ($item['action'] === 'exchange' && $item['exchange_product_id']): ?>
                                                            <br>
                                                            <small class="text-muted">
                                                                เปลี่ยนเป็น: <?php echo htmlspecialchars($item['exchange_product_name']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php $total += $item['subtotal']; ?>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td colspan="4" class="text-end"><strong>รวมทั้งสิ้น:</strong></td>
                                                <td><strong>฿<?php echo number_format($total, 2); ?></strong></td>
                                                <td></td>
                                            </tr>
                                            <?php if ($return['return_type'] === 'return'): ?>
                                                <tr class="total-row">
                                                    <td colspan="4" class="text-end"><strong>ยอดคืน:</strong></td>
                                                    <td><strong>฿<?php echo number_format($return['refund_amount'], 2); ?></strong></td>
                                                    <td>
                                                        <strong>วิธีคืน:</strong> 
                                                        <?php 
                                                        $refund_methods = [
                                                            'cash' => 'เงินสด',
                                                            'transfer' => 'โอนเงิน',
                                                            'credit' => 'บัตรเครดิต'
                                                        ];
                                                        echo $refund_methods[$return['refund_method']] ?? $return['refund_method'];
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="returns.php" class="btn btn-secondary me-2">
                                            <i class="bi bi-arrow-left me-1"></i> กลับ
                                        </a>
                                        <?php if ($return['status'] === 'pending'): ?>
                                            <button class="btn btn-success me-2" onclick="updateStatus(<?php echo $return['id']; ?>, 'approved')">
                                                <i class="bi bi-check-circle me-1"></i> อนุมัติ
                                            </button>
                                            <button class="btn btn-danger me-2" onclick="updateStatus(<?php echo $return['id']; ?>, 'rejected')">
                                                <i class="bi bi-x-circle me-1"></i> ปฏิเสธ
                                            </button>
                                        <?php elseif ($return['status'] === 'approved'): ?>
                                            <button class="btn btn-primary" onclick="updateStatus(<?php echo $return['id']; ?>, 'completed')">
                                                <i class="bi bi-check-all me-1"></i> ทำเครื่องหมายว่าเสร็จสิ้น
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($return['status'] === 'pending'): ?>
                                        <button class="btn btn-outline-danger btn-delete" 
                                                data-id="<?php echo $return['id']; ?>"
                                                data-code="<?php echo htmlspecialchars($return['return_code']); ?>">
                                            <i class="bi bi-trash me-1"></i> ลบ
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- การ์ดแสดงสถิติ -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card return-card text-center p-4">
                                    <div class="return-icon">
                                        <i class="bi bi-arrow-return-left"></i>
                                    </div>
                                    <h3><?php echo count($returns); ?></h3>
                                    <p class="text-muted mb-0">การคืนสินค้าทั้งหมด</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card return-card text-center p-4">
                                    <div class="return-icon text-warning">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <h3><?php 
                                        $pending_count = array_filter($returns, function($r) { 
                                            return $r['status'] === 'pending'; 
                                        });
                                        echo count($pending_count);
                                    ?></h3>
                                    <p class="text-muted mb-0">รอดำเนินการ</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card return-card text-center p-4">
                                    <div class="return-icon text-success">
                                        <i class="bi bi-currency-exchange"></i>
                                    </div>
                                    <h3>฿<?php 
                                        $total_refunds = array_sum(array_column($returns, 'refund_amount'));
                                        echo number_format($total_refunds); 
                                    ?></h3>
                                    <p class="text-muted mb-0">ยอดคืนรวม</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card return-card text-center p-4">
                                    <div class="return-icon text-info">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </div>
                                    <h3><?php 
                                        $exchange_count = array_filter($returns, function($r) { 
                                            return $r['return_type'] === 'exchange'; 
                                        });
                                        echo count($exchange_count);
                                    ?></h3>
                                    <p class="text-muted mb-0">การเปลี่ยนสินค้า</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ตารางการคืนสินค้า -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">รายการคืนสินค้า</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="returnsTable" class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">#</th>
                                                <th width="15%">เลขที่คืน</th>
                                                <th width="20%">ลูกค้า</th>
                                                <th width="15%">วันที่คืน</th>
                                                <th width="15%">ประเภท</th>
                                                <th width="15%">ยอดคืน</th>
                                                <th width="15%">สถานะ</th>
                                                <th width="15%">การดำเนินการ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($returns) > 0): ?>
                                                <?php foreach ($returns as $index => $ret): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo htmlspecialchars($ret['return_code']); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($ret['customer_name']); ?></td>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($ret['return_date'])); ?></td>
                                                        <td>
                                                            <?php echo $ret['return_type'] === 'return' ? 'คืนสินค้า' : 'เปลี่ยนสินค้า'; ?>
                                                        </td>
                                                        <td>฿<?php echo number_format($ret['refund_amount'], 2); ?></td>
                                                        <td>
                                                            <span class="badge status-badge status-<?php echo $ret['status']; ?>">
                                                                <?php 
                                                                $status_labels = [
                                                                    'pending' => 'รอดำเนินการ',
                                                                    'approved' => 'อนุมัติแล้ว',
                                                                    'rejected' => 'ปฏิเสธ',
                                                                    'completed' => 'เสร็จสิ้น'
                                                                ];
                                                                echo $status_labels[$ret['status']] ?? $ret['status'];
                                                                ?>
                                                            </span>
                                                        </td>
                                                        <td class="action-buttons">
                                                            <a href="returns.php?action=view&id=<?php echo $ret['id']; ?>" class="btn btn-sm btn-outline-info">
                                                                <i class="bi bi-eye"></i> ดู
                                                            </a>
                                                            <?php if ($ret['status'] === 'pending'): ?>
                                                                <button class="btn btn-sm btn-outline-danger btn-delete" 
                                                                        data-id="<?php echo $ret['id']; ?>"
                                                                        data-code="<?php echo htmlspecialchars($ret['return_code']); ?>">
                                                                    <i class="bi bi-trash"></i> ลบ
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4 text-muted">
                                                        <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                                        ยังไม่มีรายการคืนสินค้า
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal สำหรับเพิ่ม/แก้ไขการคืนสินค้า -->
    <div class="modal fade" id="returnModal" tabindex="-1" aria-labelledby="returnModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="returnModalLabel">เพิ่มการคืนสินค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="returnForm" method="post" action="returns.php">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="id" value="0">
                    
                    <div class="modal-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="return_code" class="form-label">เลขที่คืนสินค้า</label>
                                    <input type="text" class="form-control" id="return_code" name="return_code" value="<?php echo $new_return_code; ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="return_date" class="form-label">วันที่คืน</label>
                                    <input type="datetime-local" class="form-control" id="return_date" name="return_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sale_id" class="form-label">การขายที่เกี่ยวข้อง</label>
                                    <input type="text" class="form-control" id="sale_id" name="sale_id" required>
                                    <div class="form-text">พิมพ์ค้นหาเลขที่ขายหรือชื่อลูกค้า (อย่างน้อย 2 ตัวอักษร)</div>

                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customer_id" class="form-label">ลูกค้า</label>
                                    <input type="hidden" id="customer_id" name="customer_id">
                                    <input type="text" class="form-control" id="customer_name" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="return_type" class="form-label">ประเภท</label>
                                    <select class="form-select" id="return_type" name="return_type" required>
                                        <option value="return">คืนสินค้า</option>
                                        <option value="exchange">เปลี่ยนสินค้า</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="refund_method" class="form-label">วิธีคืนเงิน</label>
                                    <select class="form-select" id="refund_method" name="refund_method">
                                        <option value="cash">เงินสด</option>
                                        <option value="transfer">โอนเงิน</option>
                                        <option value="credit">บัตรเครดิต</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="reason" class="form-label">เหตุผล</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3"></textarea>
                        </div>
                        
                        <hr>
                        
                        <h5 class="mb-3">รายการสินค้า</h5>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th width="30%">สินค้า</th>
                                        <th width="10%">จำนวน</th>
                                        <th width="15%">ราคาต่อหน่วย</th>
                                        <th width="15%">รวม</th>
                                        <th width="15%">การดำเนินการ</th>
                                        <th width="15%">สินค้าที่ต้องการเปลี่ยน</th>
                                        <th width="10%">เหตุผล</th>
                                        <th width="5%"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <!-- รายการจะถูกเพิ่มโดย JavaScript -->
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="8" class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
                                                <i class="bi bi-plus-circle me-1"></i> เพิ่มสินค้า
                                            </button>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">สถานะ</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="pending">รอดำเนินการ</option>
                                        <option value="approved">อนุมัติแล้ว</option>
                                        <option value="rejected">ปฏิเสธ</option>
                                        <option value="completed">เสร็จสิ้น</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">ยอดรวม</label>
                                    <h3 id="totalAmount">฿0.00</h3>
                                    <input type="hidden" id="total_amount" name="total_amount" value="0">
                                    <input type="hidden" id="refund_amount" name="refund_amount" value="0">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal ยืนยันการลบ -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteConfirmModalLabel">ยืนยันการลบ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>คุณแน่ใจว่าต้องการลบการคืนสินค้า <strong id="deleteReturnCode"></strong> ใช่หรือไม่?</p>
                    <p class="text-danger">การกระทำนี้ไม่สามารถย้อนกลับได้</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">ลบ</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    
    <script>
        $(document).ready(function() {
            // เปิด modal เมื่อคลิกปุ่มเพิ่ม
            $('[data-bs-target="#returnModal"]').click(function() {
                $('#returnModalLabel').text('เพิ่มการคืนสินค้า');
                $('input[name="action"]').val('add');
                $('input[name="id"]').val('0');
                $('#returnForm')[0].reset();
                $('#return_code').val('<?php echo $new_return_code; ?>');
                $('#return_date').val('<?php echo date('Y-m-d\TH:i'); ?>');
                $('#itemsBody').empty();
                updateTotal();
            });
            
            // เปลี่ยนการขาย
            // --- เปิดใช้ Select2 + AJAX ค้นหาใบขาย ---
             // ถ้าอยู่ใน modal ให้อ้างอิง id ของ modal ตรงนี้
             $(function () {
  const $modal = $('#returnModal');           // ← เปลี่ยนให้ตรง id โมดอลจริงของคุณ
  const $sale  = $('#sale_id');

  // เผื่อมีการ set disabled โดยเผลอ
  $sale.prop('disabled', false).removeAttr('readonly');

  $sale.select2({
    placeholder: 'ค้นหาเลขที่ขาย...',
    allowClear: true,
    minimumInputLength: 2,
    width: '100%',
    // สำคัญมาก: ทำให้ dropdown/กล่องค้นหาไปอยู่ใน modal เดียวกัน แก้ปัญหาโฟกัส/พิมพ์ไม่ได้
    dropdownParent: $modal.length ? $modal : $(document.body),
    ajax: {
      url: '../ajax/get_sale_items.php',     // ปรับพาธให้ถูกกับโปรเจกต์จริง
      type: 'GET',
      dataType: 'json',
      delay: 250,
      data: params => ({ q: params.term || '' }),
      processResults: data => ({
        results: data.map(row => ({
          id: row.id,
          text: (row.sale_code || '') + (row.customer_name ? ' - ' + row.customer_name : ''),
          customer_id: row.customer_id || '',
          customer_name: row.customer_name || ''
        }))
      }),
      cache: true
    }
  });

  // เปิดแล้วโฟกัสช่องค้นหาให้เลย (จะได้พิมพ์ได้ทันที)
  $sale.on('select2:open', function () {
    setTimeout(() => {
      const input = document.querySelector('.select2-container .select2-search__field');
      if (input) input.focus();
    }, 0);
  });

  // เลือกแล้วทำงานต่อเดิม
  $sale.on('select2:select', function (e) {
    const d = e.params.data || {};
    if ($('#customer_id').length)   $('#customer_id').val(d.customer_id || '');
    if ($('#customer_name').length) $('#customer_name').val(d.customer_name || '');
    if (typeof loadSaleItems === 'function') loadSaleItems(d.id);
  });
});
            
            // เพิ่มรายการสินค้า
            $('#addItemBtn').click(function() {
                addItemRow();
            });
            
            // เปิด modal ยืนยันการลบ
            $('.btn-delete').click(function() {
                const id = $(this).data('id');
                const code = $(this).data('code');
                
                $('#deleteReturnCode').text(code);
                $('#confirmDeleteBtn').attr('href', 'returns.php?action=delete&id=' + id);
                
                $('#deleteConfirmModal').modal('show');
            });
            
            // กำหนด DataTable
            <?php if ($action !== 'view'): ?>
                $('#returnsTable').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                    },
                    order: [[0, 'desc']],
                    responsive: true
                });
            <?php endif; ?>
        });
        
        // ฟังก์ชันเพิ่มรายการสินค้า
        function addItemRow(productId = '', productName = '', quantity = 1, unitPrice = 0, reason = '') {
            const index = $('#itemsBody tr').length;
            const row = `
                <tr>
                    <td>
                        <input type="hidden" name="product_id[]" value="${productId}">
                        <input type="text" class="form-control product-search" placeholder="ค้นหาสินค้า (รหัสหรือชื่อ)" value="${productName}" data-index="${index}">
                        <div class="product-suggestions" id="suggestions-${index}"></div>
                    </td>
                    <td>
                        <input type="number" class="form-control quantity" name="quantity[]" value="${quantity}" min="1" data-index="${index}">
                    </td>
                    <td>
                        <input type="number" class="form-control unit-price" name="unit_price[]" value="${unitPrice}" step="0.01" min="0" data-index="${index}">
                    </td>
                    <td class="subtotal">฿0.00</td>
                    <td>
                        <select class="form-select action-type" name="action_type[]" data-index="${index}">
                            <option value="refund">คืนเงิน</option>
                            <option value="exchange">เปลี่ยนสินค้า</option>
                            <option value="store_credit">เครดิตร้าน</option>
                        </select>
                    </td>
                    <td>
                        <input type="hidden" class="exchange-product-id" name="exchange_product_id[]" value="">
                        <input type="text" class="form-control exchange-product-search" placeholder="ป้อนรหัสสินค้า" data-index="${index}" disabled>
                        <div class="exchange-product-suggestions" id="exchange-suggestions-${index}"></div>
                        <!-- เพิ่มส่วนแสดงข้อมูลสินค้า -->
                        <div class="exchange-product-info mt-2 p-2 bg-light rounded" id="exchange-info-${index}" style="display: none;">
                            <div class="product-details">
                                <small>
                                    <div><strong>ชื่อ:</strong> <span class="info-name"></span></div>
                                    <div><strong>ราคา:</strong> ฿<span class="info-price"></span></div>
                                    <div><strong>คงเหลือ:</strong> <span class="info-stock"></span></div>
                                </small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <input type="text" class="form-control item-reason" name="item_reason[]" value="${reason}" placeholder="เหตุผล">
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            
            $('#itemsBody').append(row);
            
            // ตั้งค่า event listeners สำหรับแถวใหม่
            const newRow = $('#itemsBody tr:last');
            newRow.find('.quantity, .unit-price').on('input', function() {
                updateRowTotal($(this).data('index'));
            });
            
            newRow.find('.action-type').change(function() {
                const index = $(this).data('index');
                const action = $(this).val();
                
                if (action === 'exchange') {
                    newRow.find('.exchange-product-search').prop('disabled', false);
                } else {
                    newRow.find('.exchange-product-search').prop('disabled', true);
                    newRow.find('.exchange-product-id').val('');
                    newRow.find('.exchange-product-search').val('');
                    $(`#exchange-info-${index}`).hide();
                }
            });
            
            newRow.find('.remove-item').click(function() {
                $(this).closest('tr').remove();
                updateTotal();
            });
            
            // ตั้งค่าการค้นหาสินค้า
            setupProductSearch(newRow.find('.product-search'), index, false);
            setupProductSearch(newRow.find('.exchange-product-search'), index, true);
            
            // ตรวจสอบการป้อนรหัสสินค้าโดยตรง
            newRow.find('.exchange-product-search').on('blur', function() {
                const inputVal = $(this).val();
                if (inputVal && inputVal.length > 0) {
                    // ถ้าเป็นรหัสสินค้า (ขึ้นต้นด้วย P ตามด้วยตัวเลข)
                    if (/^P\d+$/.test(inputVal)) {
                        loadProductByCode(inputVal, index);
                    }
                }
            });
            
            // อัปเดตยอดรวม
            updateRowTotal(index);
        }
        
        // ตั้งค่าการค้นหาสินค้า
        function setupProductSearch(input, index, isExchange) {
            input.on('input', function() {
                const query = $(this).val();
                if (query.length < 2) {
                    $(isExchange ? '#exchange-suggestions-' + index : '#suggestions-' + index).empty();
                    return;
                }
                
                // AJAX ค้นหาสินค้า
                $.ajax({
                    url: '../ajax/search_products.php',
                    method: 'GET',
                    data: { q: query },
                    success: function(response) {
                        let suggestions = '';
                        response.forEach(function(product) {
                            suggestions += `
                                <div class="suggestion-item" data-id="${product.id}" data-name="${product.product_name}" data-price="${product.sale_price}" data-stock="${product.quantity}">
                                    ${product.product_code} - ${product.product_name} (คงเหลือ: ${product.quantity})
                                </div>
                            `;
                        });
                        
                        $(isExchange ? '#exchange-suggestions-' + index : '#suggestions-' + index).html(suggestions);
                        
                        // คลิกเลือกสินค้า
                        $('.suggestion-item').click(function() {
                            const productId = $(this).data('id');
                            const productName = $(this).data('name');
                            const price = $(this).data('price');
                            const stock = $(this).data('stock');
                            
                            if (isExchange) {
                                input.val(productName);
                                input.closest('tr').find('.exchange-product-id').val(productId);
                                
                                // แสดงข้อมูลสินค้า
                                const infoDiv = $(`#exchange-info-${index}`);
                                infoDiv.find('.info-name').text(productName);
                                infoDiv.find('.info-price').text(price.toFixed(2));
                                infoDiv.find('.info-stock').text(stock);
                                infoDiv.show();
                            } else {
                                input.val(productName);
                                input.closest('tr').find('input[name="product_id[]"]').val(productId);
                                input.closest('tr').find('.unit-price').val(price);
                                updateRowTotal(index);
                            }
                            
                            $(isExchange ? '#exchange-suggestions-' + index : '#suggestions-' + index).empty();
                        });
                    }
                });
            });
            
            // ซ่อน suggestions เมื่อคลิก其他地方
            $(document).click(function(e) {
                if (!$(e.target).closest('.product-search, .product-suggestions, .exchange-product-search, .exchange-product-suggestions').length) {
                    $('.product-suggestions, .exchange-product-suggestions').empty();
                }
            });
            
            // ล้างข้อมูลเมื่อลบการค้นหา
            input.on('keyup', function(e) {
                if (e.key === 'Delete' || e.key === 'Backspace') {
                    if ($(this).val().length === 0 && isExchange) {
                        $(`#exchange-info-${index}`).hide();
                        input.closest('tr').find('.exchange-product-id').val('');
                    }
                }
            });
        }
        
        // ฟังก์ชันโหลดข้อมูลสินค้าจากรหัส
        function loadProductByCode(productCode, index) {
            if (!productCode) return;
            
            $.ajax({
                url: '../ajax/get_product_by_code.php',
                method: 'GET',
                data: { code: productCode },
                success: function(product) {
                    if (product) {
                        const row = $(`#itemsBody tr:eq(${index})`);
                        row.find('.exchange-product-id').val(product.id);
                        
                        // แสดงข้อมูลสินค้า
                        const infoDiv = $(`#exchange-info-${index}`);
                        infoDiv.find('.info-name').text(product.product_name);
                        infoDiv.find('.info-price').text(parseFloat(product.sale_price).toFixed(2));
                        infoDiv.find('.info-stock').text(product.quantity);
                        infoDiv.show();
                    }
                }
            });
        }
        
        // อัปเดตยอดรวมของแถว
        function updateRowTotal(index) {
            const row = $(`#itemsBody tr:eq(${index})`);
            const quantity = parseFloat(row.find('.quantity').val()) || 0;
            const unitPrice = parseFloat(row.find('.unit-price').val()) || 0;
            const subtotal = quantity * unitPrice;
            
            row.find('.subtotal').text('฿' + subtotal.toFixed(2));
            updateTotal();
        }
        
        // อัปเดตยอดรวมทั้งหมด
        function updateTotal() {
            let total = 0;
            
            $('#itemsBody tr').each(function() {
                const quantity = parseFloat($(this).find('.quantity').val()) || 0;
                const unitPrice = parseFloat($(this).find('.unit-price').val()) || 0;
                total += quantity * unitPrice;
            });
            
            $('#totalAmount').text('฿' + total.toFixed(2));
            $('#total_amount').val(total);
            $('#refund_amount').val(total);
        }
        
        // โหลดรายการสินค้าจากการขาย
        function loadSaleItems(saleId) {
            if (!saleId) return;
            
            $.ajax({
                url: '../ajax/get_sale_items.php',
                method: 'GET',
                data: { sale_id: saleId },
                success: function(response) {
                    $('#itemsBody').empty();
                    
                    response.forEach(function(item) {
                        addItemRow(
                            item.product_id, 
                            item.product_name, 
                            item.quantity, 
                            item.unit_price,
                            ''
                        );
                    });
                    
                    updateTotal();
                }
            });
        }
        
        // อัปเดตสถานะ
        function updateStatus(id, status) {
            if (confirm('คุณแน่ใจว่าต้องการอัปเดตสถานะใช่หรือไม่?')) {
                window.location.href = 'returns.php?action=update_status&id=' + id + '&status=' + status;
            }
        }
    </script>
</body>
</html>