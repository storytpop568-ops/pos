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

// ฟังก์ชันจัดการการขาย
function getSales($pdo) {
    try {
        // ตรวจสอบว่าตาราง sales มีอยู่หรือไม่
        $stmt = $pdo->query("SELECT 1 FROM sales LIMIT 1");
    } catch (PDOException $e) {
        // หากตารางไม่มีอยู่ ให้สร้างตาราง
        createSalesTable($pdo);
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, c.name as customer_name, u.username as cashier_name
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            LEFT JOIN users u ON s.cashier_id = u.id 
            ORDER BY s.sale_date DESC, s.created_at DESC
        ");
        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting sales: " . $e->getMessage());
        return [];
    }
}

function getSale($pdo, $id) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, c.name as customer_name, u.username as cashier_name
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            LEFT JOIN users u ON s.cashier_id = u.id 
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting sale: " . $e->getMessage());
        return false;
    }
}

function getSaleItems($pdo, $sale_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT si.*, p.product_name, p.product_code
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

function getCustomers($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers ORDER BY name");
        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting customers: " . $e->getMessage());
        return [];
    }
}

function searchCustomers($pdo, $search) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM customers 
            WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?
            ORDER BY name
            LIMIT 10
        ");
        $searchTerm = "%$search%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error searching customers: " . $e->getMessage());
        return [];
    }
}

function getCustomerByPhone($pdo, $phone) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
        $stmt->execute([$phone]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting customer by phone: " . $e->getMessage());
        return false;
    }
}

function addCustomer($pdo, $data) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO customers (name, phone, email, address)
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([
            $data['name'],
            $data['phone'],
            $data['email'],
            $data['address']
        ]);
        return $result ? $pdo->lastInsertId() : false;
    } catch (PDOException $e) {
        error_log("Error adding customer: " . $e->getMessage());
        return false;
    }
}

function getProducts($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE quantity > 0 ORDER BY product_name");
        $stmt->execute();
        $result = $stmt->fetchAll();
        return $result ?: [];
    } catch (PDOException $e) {
        error_log("Error getting products: " . $e->getMessage());
        return [];
    }
}

function getProductByCode($pdo, $product_code) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE product_code = ?");
        $stmt->execute([$product_code]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting product by code: " . $e->getMessage());
        return false;
    }
}

function addSale($pdo, $data) {
    $pdo->beginTransaction();
    
    try {
        // คำนวณส่วนลดจากเปอร์เซ็นต์
        $discount_amount = ($data['total_amount'] * $data['discount_percent']) / 100;
        $net_amount = $data['total_amount'] - $discount_amount;
        
        // บันทึกการขาย
        $stmt = $pdo->prepare("
            INSERT INTO sales 
            (sale_code, customer_id, total_amount, discount_percent, discount_amount, net_amount, payment_method, cashier_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['sale_code'],
            $data['customer_id'],
            $data['total_amount'],
            $data['discount_percent'],
            $discount_amount,
            $net_amount,
            $data['payment_method'],
            $_SESSION['user_id']
        ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // บันทึกรายการขาย
        foreach ($data['items'] as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO sale_items 
                (sale_id, product_id, quantity, unit_price, subtotal) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['subtotal']
            ]);
            
            // ลดจำนวนสินค้าในสต็อก
            $stmt = $pdo->prepare("
                UPDATE products 
                SET quantity = quantity - ? 
                WHERE id = ? AND quantity >= ?
            ");
            $stmt->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        }
        
        $pdo->commit();
        return $sale_id;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error adding sale: " . $e->getMessage());
        return false;
    }
}

function deleteSale($pdo, $id) {
    $pdo->beginTransaction();
    
    try {
        // ดึงข้อมูลรายการขายเพื่อคืนสต็อก
        $items = getSaleItems($pdo, $id);
        
        foreach ($items as $item) {
            // คืนจำนวนสินค้าในสต็อก
            $stmt = $pdo->prepare("
                UPDATE products 
                SET quantity = quantity + ? 
                WHERE id = ?
            ");
            $stmt->execute([$item['quantity'], $item['product_id']]);
        }
        
        // ลบรายการขาย
        $stmt = $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?");
        $stmt->execute([$id]);
        
        // ลบการขาย
        $stmt = $pdo->prepare("DELETE FROM sales WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting sale: " . $e->getMessage());
        return false;
    }
}

function generateSaleCode($pdo) {
    $prefix = 'S';
    $year = date('Y');
    $month = date('m');
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM sales 
            WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?
        ");
        $stmt->execute([$year, $month]);
        $result = $stmt->fetch();
        
        $number = str_pad($result['count'] + 1, 4, '0', STR_PAD_LEFT);
        return $prefix . $year . $month . $number;
    } catch (PDOException $e) {
        error_log("Error generating sale code: " . $e->getMessage());
        return $prefix . $year . $month . '0001';
    }
}

// ฟังก์ชันสร้างตาราง sales หากไม่มี
function createSalesTable($pdo) {
    try {
        $sql = "
        CREATE TABLE IF NOT EXISTS sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_code VARCHAR(20) NOT NULL UNIQUE,
            customer_id INT,
            total_amount DECIMAL(10,2) DEFAULT 0,
            discount_percent DECIMAL(5,2) DEFAULT 0,
            discount_amount DECIMAL(10,2) DEFAULT 0,
            net_amount DECIMAL(10,2) DEFAULT 0,
            payment_method ENUM('cash', 'transfer', 'credit', 'qr') DEFAULT 'cash',
            cashier_id INT NOT NULL,
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $pdo->exec($sql);
        
        // สร้างตาราง sale_items
        $sql = "
        CREATE TABLE IF NOT EXISTS sale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $pdo->exec($sql);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating sales table: " . $e->getMessage());
        return false;
    }
}

// จัดการฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sale_code = trim($_POST['sale_code'] ?? '');
    $customer_id = $_POST['customer_id'] ?? null;
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $discount_percent = $_POST['discount_percent'] ?? 0;
    
    $form_action = $_POST['action'] ?? 'add';
    
    // ดึงข้อมูลรายการจากฟอร์ม
    $items = [];
    $total_amount = 0;
    
    if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
        foreach ($_POST['product_id'] as $index => $product_id) {
            if (!empty($product_id) && !empty($_POST['quantity'][$index])) {
                $quantity = (int)$_POST['quantity'][$index];
                $unit_price = (float)$_POST['unit_price'][$index];
                $subtotal = $quantity * $unit_price;
                
                $items[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'subtotal' => $subtotal
                ];
                
                $total_amount += $subtotal;
            }
        }
    }
    
    if (!empty($items)) {
        $sale_data = [
            'sale_code' => $sale_code,
            'customer_id' => $customer_id,
            'total_amount' => $total_amount,
            'discount_percent' => $discount_percent,
            'payment_method' => $payment_method,
            'items' => $items
        ];
        
        if ($form_action === 'add') {
            $sale_id = addSale($pdo, $sale_data);
            if ($sale_id) {
                $_SESSION['success_message'] = 'บันทึกการขายสำเร็จ';
                header('Location: sale_receipt.php?id=' . $sale_id);
                exit;
            } else {
                $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการบันทึกการขาย';
            }
        }
    } else {
        $_SESSION['error_message'] = 'กรุณาเพิ่มรายการสินค้า';
    }
    
    header('Location: sales.php');
    exit;
}

// ลบการขาย
if ($action === 'delete' && $id > 0) {
    if (deleteSale($pdo, $id)) {
        $_SESSION['success_message'] = 'ลบการขายสำเร็จ';
    } else {
        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการลบการขาย';
    }
    header('Location: sales.php');
    exit;
}

// ดูรายละเอียดการขาย
if ($action === 'view' && $id > 0) {
    $sale = getSale($pdo, $id);
    $sale_items = getSaleItems($pdo, $id);
    
    if (!$sale) {
        $_SESSION['error_message'] = 'ไม่พบการขายนี้';
        header('Location: sales.php');
        exit;
    }
}

// ค้นหาลูกค้า
$customer_search = $_GET['customer_search'] ?? '';
if (!empty($customer_search)) {
    $customers = searchCustomers($pdo, $customer_search);
} else {
    $customers = getCustomers($pdo);
}

$products = getProducts($pdo);
$new_sale_code = generateSaleCode($pdo);

// เรียกใช้งานฟังก์ชัน getSales() และตรวจสอบผลลัพธ์
$sales = getSales($pdo);
if ($sales === null) {
    $sales = []; // กำหนดค่าเป็น array ว่างหากเป็น null
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการการขาย - ระบบสต็อกสินค้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Phetsarath+OT:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-header {
            background: linear-gradient(120deg, #4361ee, #3f37c9);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        
        .sale-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }
        
        .sale-card:hover {
            transform: translateY(-5px);
        }
        
        .sale-icon {
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
        
        #salesTable th {
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
        
        .sale-items-table th {
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
        
        .payment-badge {
            font-size: 0.8rem;
        }
        
        .customer-search-box {
            position: relative;
        }
        
        .customer-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        
        .customer-search-result {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        
        .customer-search-result:hover {
            background-color: #f8f9fa;
        }
        
        .customer-search-result:last-child {
            border-bottom: none;
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
                                <h1 class="h2 mb-0"><i class="bi bi-cart-check me-2"></i> จัดการการขาย</h1>
                                <p class="mb-0">จัดการรายการขายและออกใบเสร็จ</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#saleModal" data-action="add">
                                    <i class="bi bi-plus-circle me-1"></i> ขายสินค้า
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
                    
                    <?php if ($action === 'view' && isset($sale)): ?>
                        <!-- แสดงรายละเอียดการขาย -->
                        <div class="card mb-4">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-receipt me-2"></i>รายละเอียดการขาย #<?php echo $sale['sale_code']; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <p><strong>ลูกค้า:</strong> <?php echo $sale['customer_name'] ?? 'ลูกค้าทั่วไป'; ?></p>
                                        <p><strong>พนักงานขาย:</strong> <?php echo $sale['cashier_name']; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>วันที่ขาย:</strong> <?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></p>
                                        <p>
                                            <strong>ชำระโดย:</strong> 
                                            <span class="badge bg-info payment-badge">
                                                <?php 
                                                $payment_methods = [
                                                    'cash' => 'เงินสด',
                                                    'transfer' => 'โอนเงิน',
                                                    'credit' => 'บัตรเครดิต',
                                                    'qr' => 'QR Code'
                                                ];
                                                echo $payment_methods[$sale['payment_method']] ?? $sale['payment_method']; 
                                                ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered sale-items-table">
                                        <thead>
                                            <tr>
                                                <th width="5%">#</th>
                                                <th width="45%">สินค้า</th>
                                                <th width="15%">จำนวน</th>
                                                <th width="15%">ราคาต่อหน่วย</th>
                                                <th width="20%">รวม</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $total = 0; ?>
                                            <?php foreach ($sale_items as $index => $item): ?>
                                                <tr class="item-row">
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                    </td>
                                                    <td><?php echo number_format($item['quantity']); ?></td>
                                                    <td>฿<?php echo number_format($item['unit_price'], 2); ?></td>
                                                    <td>฿<?php echo number_format($item['subtotal'], 2); ?></td>
                                                </tr>
                                                <?php $total += $item['subtotal']; ?>
                                            <?php endforeach; ?>
                                            <tr class="total-row">
                                                <td colspan="4" class="text-end"><strong>รวมทั้งสิ้น:</strong></td>
                                                <td><strong>฿<?php echo number_format($total, 2); ?></strong></td>
                                            </tr>
                                            <?php if ($sale['discount_percent'] > 0): ?>
                                                <tr class="total-row">
                                                    <td colspan="4" class="text-end"><strong>ส่วนลด (<?php echo $sale['discount_percent']; ?>%):</strong></td>
                                                    <td><strong>-฿<?php echo number_format($sale['discount_amount'], 2); ?></strong></td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr class="total-row">
                                                <td colspan="4" class="text-end"><strong>ยอดสุทธิ:</strong></td>
                                                <td><strong>฿<?php echo number_format($sale['net_amount'], 2); ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <a href="sales.php" class="btn btn-secondary me-2">
                                        <i class="bi bi-arrow-left me-1"></i> กลับ
                                    </a>
                                    <a href="sale_receipt.php?id=<?php echo $sale['id']; ?>" class="btn btn-primary" target="_blank">
                                        <i class="bi bi-printer me-1"></i> พิมพ์ใบเสร็จ
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- การ์ดแสดงสถิติ -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card sale-card text-center p-4">
                                    <div class="sale-icon">
                                        <i class="bi bi-cart-check"></i>
                                    </div>
                                    <h3><?php echo count($sales); ?></h3>
                                    <p class="text-muted mb-0">การขายทั้งหมด</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card sale-card text-center p-4">
                                    <div class="sale-icon text-success">
                                        <i class="bi bi-currency-exchange"></i>
                                    </div>
                                    <h3>฿<?php 
                                        $total_sales = array_sum(array_column($sales, 'net_amount'));
                                        echo number_format($total_sales); 
                                    ?></h3>
                                    <p class="text-muted mb-0">ยอดขายรวม</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card sale-card text-center p-4">
                                    <div class="sale-icon text-info">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <h3><?php 
                                        $customer_count = count($customers);
                                        echo $customer_count > 0 ? $customer_count : 0;
                                    ?></h3>
                                    <p class="text-muted mb-0">ลูกค้าทั้งหมด</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card sale-card text-center p-4">
                                    <div class="sale-icon text-warning">
                                        <i class="bi bi-graph-up"></i>
                                    </div>
                                    <h3>฿<?php 
                                        $avg_sales = count($sales) > 0 ? $total_sales / count($sales) : 0;
                                        echo number_format($avg_sales, 2);
                                    ?></h3>
                                    <p class="text-muted mb-0">ยอดขายเฉลี่ย</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ตารางการขาย -->
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">รายการขาย</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table id="salesTable" class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="5%">#</th>
                                                <th width="15%">เลขที่ขาย</th>
                                                <th width="20%">ลูกค้า</th>
                                                <th width="15%">วันที่ขาย</th>
                                                <th width="15%">ยอดสุทธิ</th>
                                                <th width="15%">ชำระโดย</th>
                                                <th width="15%">การดำเนินการ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($sales) > 0): ?>
                                                <?php foreach ($sales as $index => $sale): ?>
                                                    <tr>
                                                        <td><?php echo $index + 1; ?></td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo htmlspecialchars($sale['sale_code']); ?></span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'ลูกค้าทั่วไป'); ?></td>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?></td>
                                                        <td>฿<?php echo number_format($sale['net_amount'], 2); ?></td>
                                                        <td>
                                                            <?php 
                                                            $payment_methods = [
                                                                'cash' => 'เงินสด',
                                                                'transfer' => 'โอนเงิน',
                                                                'credit' => 'บัตรเครดิต',
                                                                'qr' => 'QR Code'
                                                            ];
                                                            ?>
                                                            <span class="badge bg-info"><?php echo $payment_methods[$sale['payment_method']] ?? $sale['payment_method']; ?></span>
                                                        </td>
                                                        <td class="action-buttons">
                                                            <a href="sales.php?action=view&id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-outline-info">
                                                                <i class="bi bi-eye"></i> ดู
                                                            </a>
                                                            <button class="btn btn-sm btn-outline-danger btn-delete" 
                                                                    data-id="<?php echo $sale['id']; ?>"
                                                                    data-code="<?php echo htmlspecialchars($sale['sale_code']); ?>">
                                                                <i class="bi bi-trash"></i> ลบ
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="text-center py-4">
                                                        <i class="bi bi-cart-x display-4 d-block text-muted mb-2"></i>
                                                        <p class="text-muted">ยังไม่มีรายการขาย</p>
                                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#saleModal" data-action="add">
                                                            <i class="bi bi-plus-circle me-1"></i> สร้างการขายแรก
                                                        </button>
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

    <!-- Modal สำหรับเพิ่มการขาย -->
    <div class="modal fade" id="saleModal" tabindex="-1" aria-labelledby="saleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="saleModalLabel">ขายสินค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="sales.php" id="saleForm">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="sale_code" value="<?php echo $new_sale_code; ?>">
                    <input type="hidden" name="customer_id" id="customerId" value="">
                    
                    <div class="modal-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">เลขที่ขาย</label>
                                    <input type="text" class="form-control" value="<?php echo $new_sale_code; ?>" disabled>
                                    <small class="text-muted">รหัสการขายจะถูกสร้างอัตโนมัติ</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">ลูกค้า</label>
                                    <div class="customer-search-box">
                                        <input type="text" class="form-control" id="customerSearch" placeholder="ค้นหาลูกค้าด้วยชื่อ, โทรศัพท์, อีเมล..." 
                                               value="<?php echo $customer_search; ?>">
                                        <div class="customer-search-results" id="customerSearchResults"></div>
                                    </div>
                                    <small class="text-muted">หรือ <a href="#" data-bs-toggle="modal" data-bs-target="#customerModal">สมัครสมาชิกใหม่</a></small>
                                    <div id="selectedCustomer" class="mt-2 p-2 bg-light rounded" style="display: none;">
                                        <strong>ลูกค้า:</strong> <span id="selectedCustomerName"></span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="changeCustomer">
                                            <i class="bi bi-x"></i> เปลี่ยน
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="payment_method" class="form-label">วิธีการชำระ</label>
                                    <select class="form-select" id="payment_method" name="payment_method" required>
                                        <option value="cash">เงินสด</option>
                                        <option value="transfer">โอนเงิน</option>
                                        <option value="credit">บัตรเครดิต</option>
                                        <option value="qr">QR Code</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="discount_percent" class="form-label">ส่วนลด (%)</label>
                                    <input type="number" class="form-control" id="discount_percent" name="discount_percent" min="0" max="100" value="0" step="0.01">
                                    <small class="text-muted">ส่วนลดเป็นเปอร์เซ็นต์</small>
                                </div>
                            </div>
                        </div>
                        
                        <h6 class="mb-3">รายการสินค้า</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40%">สินค้า</th>
                                        <th width="15%">จำนวน</th>
                                        <th width="15%">ราคาต่อหน่วย</th>
                                        <th width="20%">รวม</th>
                                        <th width="10%">การดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr id="noItemsRow">
                                        <td colspan="5" class="text-center text-muted py-3">
                                            ยังไม่มีรายการสินค้า
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>รวมทั้งสิ้น:</strong></td>
                                        <td><strong id="totalAmount">฿0.00</strong></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-end"><strong>ส่วนลด (<span id="discountPercent">0</span>%):</strong></td>
                                        <td><strong id="discountAmount">-฿0.00</strong></td>
                                        <td></td>
                                    </tr>
                                    <tr class="table-primary">
                                        <td colspan="3" class="text-end"><strong>ยอดสุทธิ:</strong></td>
                                        <td><strong id="netAmount">฿0.00</strong></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-5">
                                <input type="text" class="form-control" id="productCodeInput" placeholder="ป้อนรหัสสินค้า...">
                            </div>
                            <div class="col-md-3">
                                <input type="number" class="form-control" id="itemQuantity" min="1" value="1" placeholder="จำนวน">
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-primary w-100" id="addItemBtn">
                                    <i class="bi bi-plus"></i> เพิ่มสินค้า
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-primary">บันทึกการขาย</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal สำหรับสมัครสมาชิก -->
    <div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalLabel">สมัครสมาชิกใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="customerForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="customer_name" class="form-label">ชื่อลูกค้า <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="customer_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="customer_phone" class="form-label">โทรศัพท์ <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="customer_phone" name="phone" required>
                        </div>
                        <div class="mb-3">
                            <label for="customer_email" class="form-label">อีเมล</label>
                            <input type="email" class="form-control" id="customer_email" name="email">
                        </div>
                        <div class="mb-3">
                            <label for="customer_address" class="form-label">ที่อยู่</label>
                            <textarea class="form-control" id="customer_address" name="address" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                        <button type="submit" class="btn btn-primary">สมัครสมาชิก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
    // ตั้งค่า DataTable
    $('#salesTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
        },
        responsive: true,
        order: [[3, 'desc']], // เรียงตามวันที่ล่าสุด
        columnDefs: [
            { orderable: false, targets: [6] } // ปิดการเรียงลำดับคอลัมน์การดำเนินการ
        ]
    });
    
    // การจัดการการลบด้วย SweetAlert2
    document.querySelectorAll('.btn-delete').forEach(button => {
        button.addEventListener('click', function() {
            const saleId = this.getAttribute('data-id');
            const saleCode = this.getAttribute('data-code');
            
            Swal.fire({
                title: 'ยืนยันการลบ',
                html: `<div class="text-center">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                    <h4 class="mt-3">คุณแน่ใจว่าต้องการลบการขายนี้?</h4>
                    <p class="text-danger">"${saleCode}"</p>
                    <p class="text-muted">การลบการขายจะคืนสต็อกสินค้าและไม่สามารถกู้คืนได้</p>
                </div>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn btn-danger mx-2',
                    cancelButton: 'btn btn-secondary mx-2'
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `sales.php?action=delete&id=${saleId}`;
                }
            });
        });
    });
    
    // การจัดการฟอร์มขายสินค้า
    const customerSearch = document.getElementById('customerSearch');
    const customerSearchResults = document.getElementById('customerSearchResults');
    const customerId = document.getElementById('customerId');
    const selectedCustomer = document.getElementById('selectedCustomer');
    const selectedCustomerName = document.getElementById('selectedCustomerName');
    const changeCustomer = document.getElementById('changeCustomer');
    const productCodeInput = document.getElementById('productCodeInput');
    const itemQuantity = document.getElementById('itemQuantity');
    const addItemBtn = document.getElementById('addItemBtn');
    const itemsTable = document.getElementById('itemsTable');
    const noItemsRow = document.getElementById('noItemsRow');
    const totalAmount = document.getElementById('totalAmount');
    const discountPercent = document.getElementById('discountPercent');
    const discountAmount = document.getElementById('discountAmount');
    const netAmount = document.getElementById('netAmount');
    const discountInput = document.getElementById('discount_percent');
    const saleForm = document.getElementById('saleForm');
    const customerForm = document.getElementById('customerForm');
    
    let items = [];
    let itemCounter = 0;
    
    // ค้นหาลูกค้า
    if (customerSearch) {
        customerSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                customerSearchResults.style.display = 'none';
                return;
            }
            
            fetch(`../includes/search_customers.php?search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(customers => {
                    customerSearchResults.innerHTML = '';
                    
                    if (customers && customers.length > 0) {
                        customers.forEach(customer => {
                            const div = document.createElement('div');
                            div.className = 'customer-search-result';
                            div.innerHTML = `
                                <strong>${customer.name}</strong>
                                <br>
                                <small class="text-muted">${customer.phone} ${customer.email ? '| ' + customer.email : ''}</small>
                            `;
                            div.addEventListener('click', function() {
                                selectCustomer(customer);
                            });
                            customerSearchResults.appendChild(div);
                        });
                        customerSearchResults.style.display = 'block';
                    } else {
                        const div = document.createElement('div');
                        div.className = 'customer-search-result';
                        div.innerHTML = '<em>ไม่พบลูกค้า</em>';
                        customerSearchResults.appendChild(div);
                        customerSearchResults.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error searching customers:', error);
                });
        });
    }
    
    // เลือกลูกค้า
    function selectCustomer(customer) {
        if (customerId && selectedCustomerName && selectedCustomer && customerSearch && customerSearchResults) {
            customerId.value = customer.id;
            selectedCustomerName.textContent = `${customer.name} (${customer.phone})`;
            selectedCustomer.style.display = 'block';
            customerSearch.value = '';
            customerSearchResults.style.display = 'none';
        }
    }
    
    // เปลี่ยนลูกค้า
    if (changeCustomer) {
        changeCustomer.addEventListener('click', function() {
            if (customerId && selectedCustomer && customerSearch) {
                customerId.value = '';
                selectedCustomer.style.display = 'none';
                customerSearch.value = '';
            }
        });
    }
    
    // อัปเดตยอดรวม
    function updateTotals() {
        let total = 0;
        items.forEach(item => {
            total += item.subtotal;
        });
        
        const discount = parseFloat(discountInput.value) || 0;
        const discountValue = (total * discount) / 100;
        const net = total - discountValue;
        
        if (totalAmount) totalAmount.textContent = '฿' + total.toFixed(2);
        if (discountPercent) discountPercent.textContent = discount.toFixed(2);
        if (discountAmount) discountAmount.textContent = '-฿' + discountValue.toFixed(2);
        if (netAmount) netAmount.textContent = '฿' + net.toFixed(2);
    }
    
    // เพิ่มรายการสินค้า
    function addItem(productId, productName, productCode, quantity, unitPrice) {
        const subtotal = quantity * unitPrice;
        const item = {
            id: itemCounter++,
            product_id: productId,
            product_name: productName,
            product_code: productCode,
            quantity: quantity,
            unit_price: unitPrice,
            subtotal: subtotal
        };
        
        items.push(item);
        
        // ซ่อนแถว "ยังไม่มีรายการสินค้า"
        if (noItemsRow) {
            noItemsRow.style.display = 'none';
        }
        
        // เพิ่มแถวในตาราง
        const tbody = itemsTable.querySelector('tbody');
        const newRow = document.createElement('tr');
        newRow.innerHTML = `
            <td>
                ${productName}
                <br>
                <small class="text-muted">${productCode}</small>
                <input type="hidden" name="product_id[]" value="${productId}">
                <input type="hidden" name="quantity[]" value="${quantity}">
                <input type="hidden" name="unit_price[]" value="${unitPrice}">
            </td>
            <td>${quantity}</td>
            <td>฿${unitPrice.toFixed(2)}</td>
            <td>฿${subtotal.toFixed(2)}</td>
            <td>
                <button type="button" class="btn btn-sm btn-danger remove-item" data-id="${item.id}">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
        
        tbody.appendChild(newRow);
        
        // อัปเดตยอดรวม
        updateTotals();
        
        // ล้างฟอร์ม
        if (productCodeInput) productCodeInput.value = '';
        if (itemQuantity) itemQuantity.value = '1';
        
        // เพิ่ม event listener สำหรับปุ่มลบ
        newRow.querySelector('.remove-item').addEventListener('click', function() {
            const itemId = parseInt(this.getAttribute('data-id'));
            items = items.filter(item => item.id !== itemId);
            newRow.remove();
            
            // แสดงแถว "ยังไม่มีรายการสินค้า" ถ้าไม่มีรายการ
            if (items.length === 0 && noItemsRow) {
                noItemsRow.style.display = '';
            }
            
            updateTotals();
        });
    }
    
    // การเพิ่มรายการสินค้า
    if (addItemBtn && productCodeInput && itemQuantity) {
        addItemBtn.addEventListener('click', function() {
            const productCode = productCodeInput.value.trim();
            
            if (!productCode) {
                Swal.fire({
                    title: 'แจ้งเตือน',
                    text: 'กรุณาป้อนรหัสสินค้า',
                    icon: 'warning',
                    confirmButtonText: 'ตกลง'
                });
                return;
            }
            
            // ตรวจสอบว่าป้อนรหัสครบถ้วน
            if (productCode.length < 3) {
                Swal.fire({
                    title: 'แจ้งเตือน',
                    text: 'กรุณาป้อนรหัสสินค้าให้ครบถ้วน',
                    icon: 'warning',
                    confirmButtonText: 'ตกลง'
                });
                return;
            }
            
            const quantity = parseInt(itemQuantity.value) || 1;
            
            if (quantity <= 0) {
                Swal.fire({
                    title: 'แจ้งเตือน',
                    text: 'กรุณากรอกจำนวนที่ถูกต้อง',
                    icon: 'warning',
                    confirmButtonText: 'ตกลง'
                });
                return;
            }
            
            // แสดงการโหลด
            Swal.fire({
                title: 'กำลังค้นหา...',
                text: 'กรุณารอสักครู่',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // ค้นหาสินค้าด้วยรหัส
            fetch(`../includes/search_product.php?code=${encodeURIComponent(productCode)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(product => {
                    Swal.close(); // ปิด loading
                    
                    if (product.error) {
                        Swal.fire({
                            title: 'ไม่พบสินค้า',
                            text: product.error,
                            icon: 'warning',
                            confirmButtonText: 'ตกลง'
                        });
                        return;
                    }
                    
                    // ตรวจสอบจำนวนสินค้าในสต็อก
                    if (quantity > product.quantity) {
                        Swal.fire({
                            title: 'จำนวนไม่เพียงพอ',
                            text: `จำนวนสินค้าในสต็อกมีเพียง ${product.quantity} ชิ้น`,
                            icon: 'warning',
                            confirmButtonText: 'ตกลง'
                        });
                        return;
                    }
                    
                    // ตรวจสอบว่าสินค้าถูกเพิ่มไปแล้วหรือไม่
                    const existingItem = items.find(item => item.product_id == product.id);
                    if (existingItem) {
                        Swal.fire({
                            title: 'แจ้งเตือน',
                            text: 'สินค้านี้ถูกเพิ่มไปแล้ว',
                            icon: 'warning',
                            confirmButtonText: 'ตกลง'
                        });
                        return;
                    }
                    
                    // เพิ่มสินค้า
                    addItem(
                        product.id, 
                        product.product_name, 
                        product.product_code, 
                        quantity, 
                        parseFloat(product.sale_price)
                    );
                    
                    // แสดงข้อความสำเร็จ
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 2000,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer)
                            toast.addEventListener('mouseleave', Swal.resumeTimer)
                        }
                    });
                    
                    Toast.fire({
                        icon: 'success',
                        title: 'เพิ่มสินค้าสำเร็จ'
                    });
                })
                .catch(error => {
                    console.error('Error searching product:', error);
                    Swal.close();
                    Swal.fire({
                        title: 'ข้อผิดพลาด',
                        text: 'ไม่สามารถค้นหาสินค้าได้ กรุณาลองใหม่อีกครั้ง',
                        icon: 'error',
                        confirmButtonText: 'ตกลง'
                    });
                });
        });
    }
    
    // อัปเดตเมื่อส่วนลดเปลี่ยน
    if (discountInput) {
        discountInput.addEventListener('input', updateTotals);
    }
    
    // ตรวจสอบฟอร์มก่อนส่ง
    if (saleForm) {
        saleForm.addEventListener('submit', function(e) {
            if (items.length === 0) {
                e.preventDefault();
                Swal.fire({
                    title: 'แจ้งเตือน',
                    text: 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ',
                    icon: 'warning',
                    confirmButtonText: 'ตกลง'
                });
                return false;
            }
        });
    }
    
    // สมัครสมาชิกใหม่
    if (customerForm) {
        customerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('../includes/add_customer.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'สำเร็จ',
                        text: 'สมัครสมาชิกสำเร็จ',
                        icon: 'success',
                        confirmButtonText: 'ตกลง'
                    }).then(() => {
                        // เลือกลูกค้าที่เพิ่งสมัคร
                        selectCustomer(data.customer);
                        // ปิด modal
                        bootstrap.Modal.getInstance(document.getElementById('customerModal')).hide();
                        // รีเซ็ตฟอร์ม
                        customerForm.reset();
                    });
                } else {
                    Swal.fire({
                        title: 'ข้อผิดพลาด',
                        text: data.message || 'ไม่สามารถสมัครสมาชิกได้',
                        icon: 'error',
                        confirmButtonText: 'ตกลง'
                    });
                }
            })
            .catch(error => {
                console.error('Error adding customer:', error);
                Swal.fire({
                    title: 'ข้อผิดพลาด',
                    text: 'ไม่สามารถสมัครสมาชิกได้ กรุณาลองใหม่อีกครั้ง',
                    icon: 'error',
                    confirmButtonText: 'ตกลง'
                });
            });
        });
    }
    
    // ปิดผลการค้นหาเมื่อคลิกข้างนอก
    document.addEventListener('click', function(e) {
        if (customerSearchResults && !customerSearchResults.contains(e.target) && e.target !== customerSearch) {
            customerSearchResults.style.display = 'none';
        }
    });
    
    // รองรับการกด Enter ในช่องรหัสสินค้า
    if (productCodeInput) {
        productCodeInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (addItemBtn) {
                    addItemBtn.click();
                }
            }
        });
    }
});
    </script>

</body>
</html>