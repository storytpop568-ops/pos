<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ฟังก์ชันจัดการข้อมูล
function getProducts($pdo) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.quantity > 0
        ORDER BY p.product_name
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getCustomers($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM customers ORDER BY name");
    $stmt->execute();
    return $stmt->fetchAll();
}

function generateSaleCode($pdo) {
    $prefix = 'S';
    $date = date('Ymd');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM sales WHERE DATE(sale_date) = CURDATE()");
    $stmt->execute();
    $result = $stmt->fetch();
    $number = str_pad($result['count'] + 1, 3, '0', STR_PAD_LEFT);
    return $prefix . $date . $number;
}

function processSale($pdo, $data) {
    try {
        $pdo->beginTransaction();
        
        // บันทึกข้อมูลการขาย
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
            $data['discount_amount'],
            $data['net_amount'],
            $data['payment_method'],
            $_SESSION['user_id']
        ]);
        
        $sale_id = $pdo->lastInsertId();
        
        // บันทึกรายการสินค้าและอัปเดตสต็อก
        $stmt_item = $pdo->prepare("
            INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt_update = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        
        foreach ($data['items'] as $item) {
            $stmt_item->execute([
                $sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['subtotal']
            ]);
            
            $stmt_update->execute([
                $item['quantity'],
                $item['product_id']
            ]);
        }
        
        $pdo->commit();
        return ['success' => true, 'sale_id' => $sale_id];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_sale') {
    $items = json_decode($_POST['items'], true);
    
    if (empty($items)) {
        $_SESSION['error_message'] = 'กรุณาเพิ่มสินค้าอย่างน้อย 1 รายการ';
    } else {
        $sale_data = [
            'sale_code' => $_POST['sale_code'],
            'customer_id' => $_POST['customer_id'] ?: null,
            'total_amount' => $_POST['total_amount'],
            'discount_percent' => $_POST['discount_percent'],
            'discount_amount' => $_POST['discount_amount'],
            'net_amount' => $_POST['net_amount'],
            'payment_method' => $_POST['payment_method'],
            'items' => $items
        ];
        
        $result = processSale($pdo, $sale_data);
        
        if ($result['success']) {
            $_SESSION['success_message'] = 'บันทึกการขายสำเร็จ รหัสการขาย: ' . $_POST['sale_code'];
            $_SESSION['print_receipt'] = [
                'sale_id' => $result['sale_id'],
                'sale_code' => $_POST['sale_code'],
                'items' => $items,
                'customer_id' => $_POST['customer_id'],
                'total_amount' => $_POST['total_amount'],
                'discount_amount' => $_POST['discount_amount'],
                'net_amount' => $_POST['net_amount'],
                'payment_method' => $_POST['payment_method']
            ];
            header('Location: dashboard.php');
            exit;
        } else {
            $_SESSION['error_message'] = 'เกิดข้อผิดพลาด: ' . $result['message'];
        }
    }
}

$products = getProducts($pdo);
$customers = getCustomers($pdo);
$new_sale_code = generateSaleCode($pdo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ขายสินค้า - ระบบสต็อกสินค้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Phetsarath+OT:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-header {
            background: linear-gradient(120deg, #20c997, #17a2b8);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        
        .pos-container {
            min-height: calc(100vh - 200px);
        }
        
        .product-grid {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .product-card {
            border-radius: 12px;
            border: 2px solid transparent;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }
        
        .product-card:hover {
            border-color: #20c997;
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(32, 201, 151, 0.15);
        }
        
        .product-image {
            width: 100%;
            height: 120px;
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .cart-container {
            background: linear-gradient(135deg, #fff, #f8f9fa);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .cart-header {
            background: linear-gradient(120deg, #20c997, #17a2b8);
            color: white;
            padding: 1rem;
            border-radius: 15px 15px 0 0;
        }
        
        .cart-item {
            border-bottom: 1px solid #dee2e6;
            padding: 0.75rem;
            transition: background-color 0.3s;
        }
        
        .cart-item:hover {
            background-color: #f8f9fa;
        }
        
        .cart-total {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 0 0 15px 15px;
        }
        
        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quantity-control .btn {
            width: 30px;
            height: 30px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .quantity-control input {
            width: 60px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        
        .payment-methods .btn {
            margin: 0.25rem;
        }
        
        .discount-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #fff, #f1f3f4);
            border-radius: 12px;
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .total-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: #28a745;
        }
        
        .stock-badge {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            font-size: 0.75rem;
        }
        
        .search-box {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .customer-search-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        /* Suggestion box styles */
        .suggestion-box {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            z-index: 1050;
        }
        
        .suggestion-item {
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .suggestion-item:hover {
            background-color: #f8f9fa;
        }
        
        .suggestion-item:last-child {
            border-bottom: none;
        }

        /* Receipt Styles */
        .receipt {
            width: 300px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            background: white;
            padding: 20px;
            margin: 0 auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }

        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }

        .receipt-header h2 {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
            font-family: 'Phetsarath OT', sans-serif;
        }

        .receipt-info {
            margin-bottom: 15px;
            font-size: 11px;
        }

        .receipt-items {
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }

        .receipt-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            font-size: 11px;
        }

        .receipt-item-name {
            flex: 1;
            margin-right: 10px;
        }

        .receipt-item-qty {
            width: 30px;
            text-align: center;
        }

        .receipt-item-price {
            width: 60px;
            text-align: right;
        }

        .receipt-total {
            font-size: 12px;
            font-weight: bold;
        }

        .receipt-total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }

        .receipt-footer {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #333;
            font-size: 10px;
        }

        @media print {
            body * {
                visibility: hidden;
            }
            
            .receipt, .receipt * {
                visibility: visible;
            }
            
            .receipt {
                position: absolute;
                left: 0;
                top: 0;
                margin: 0;
                box-shadow: none;
                width: 80mm;
                font-size: 10px;
            }

            @page {
                size: 80mm 200mm;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <!-- ส่วนหัวหน้า -->
        <div class="page-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h1 class="h2 mb-0"><i class="bi bi-cart-plus me-2"></i> ขายสินค้า</h1>
                        <p class="mb-0">ระบบขายสินค้าหน้าร้าน</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span class="badge bg-light text-dark fs-6">รหัสการขาย: <?php echo $new_sale_code; ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="container-fluid">
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
            
            <div class="row pos-container">
                <!-- ส่วนเลือกสินค้า -->
                <div class="col-md-8">
                    <div class="card h-100">
                        <div class="search-box">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <div class="input-group position-relative">
                                        <input type="text" id="searchProduct" class="form-control" 
                                               placeholder="ค้นหาสินค้า (ชื่อ, รหัส, บาร์โค้ด, หมวดหมู่)..." autocomplete="off">
                                        <button class="btn btn-outline-secondary" type="button" id="btnOpenScanner" title="สแกนบาร์โค้ด">
                                            <i class="bi bi-upc-scan"></i> สแกน
                                        </button>
                                        
                                        <!-- กล่องแสดงผลการค้นหา -->
                                        <div id="searchSuggestions" class="list-group position-absolute w-100 shadow suggestion-box" 
                                             style="top: 100%; display: none;"></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <select id="categoryFilter" class="form-select">
                                        <option value="">ทุกหมวดหมู่</option>
                                        <?php
                                        $categories = array_unique(array_column($products, 'category_name'));
                                        foreach ($categories as $cat):
                                            if (!empty($cat)):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                        <?php endif; endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="row product-grid" id="productGrid">
                                <?php foreach ($products as $product): ?>
                                    <div class="col-lg-3 col-md-4 col-sm-6 mb-3 product-item" 
                                         data-name="<?php echo strtolower($product['product_name']); ?>"
                                         data-code="<?php echo strtolower($product['product_code']); ?>"
                                         data-category="<?php echo strtolower($product['category_name'] ?? ''); ?>">
                                        <div class="card product-card h-100" 
                                             onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['product_name']); ?>', <?php echo $product['sale_price']; ?>, <?php echo $product['quantity']; ?>)">
                                            <div class="card-body text-center">
                                                <div class="product-image">
                                                    <i class="bi bi-box-seam display-4 text-muted"></i>
                                                </div>
                                                <h6 class="card-title"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                                <p class="text-muted small mb-1"><?php echo htmlspecialchars($product['category_name'] ?? 'ไม่มีหมวดหมู่'); ?></p>
                                                <p class="text-primary fw-bold mb-2">฿<?php echo number_format($product['sale_price'], 2); ?></p>
                                                <p class="text-muted small mb-2">รหัส: <?php echo htmlspecialchars($product['product_code']); ?></p>
                                                
                                                <span class="badge <?php echo $product['quantity'] <= 5 ? 'bg-warning' : 'bg-success'; ?> stock-badge">
                                                    คงเหลือ: <?php echo $product['quantity']; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($products)): ?>
                                    <div class="col-12 text-center py-5">
                                        <i class="bi bi-inbox display-4 text-muted"></i>
                                        <h5 class="text-muted mt-3">ไม่มีสินค้าในสต็อก</h5>
                                        <p class="text-muted">เพิ่มสินค้าเข้าสู่ระบบก่อนจึงจะสามารถขายได้</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ส่วนตะกร้าสินค้า -->
                <div class="col-md-4">
                    <div class="cart-container sticky-top">
                        <div class="cart-header">
                            <h5 class="mb-0"><i class="bi bi-cart me-2"></i> ตะกร้าสินค้า</h5>
                        </div>
                        
                        <div class="cart-body" style="max-height: 400px; overflow-y: auto;">
                            <div id="cartItems">
                                <div class="text-center py-5" id="emptyCart">
                                    <i class="bi bi-cart-x display-4 text-muted"></i>
                                    <p class="text-muted mt-3">ตะกร้าว่าง</p>
                                    <p class="small text-muted">คลิกที่สินค้าเพื่อเพิ่มลงตะกร้า</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ส่วนลดและสรุป -->
                        <div class="discount-section">
                            <div class="row align-items-center">
                                <div class="col-6">
                                    <label class="form-label small">ส่วนลด (%)</label>
                                    <input type="number" id="discountPercent" class="form-control form-control-sm" 
                                           value="0" min="0" max="100" onchange="calculateTotal()">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">จำนวนเงิน (฿)</label>
                                    <input type="number" id="discountAmount" class="form-control form-control-sm" 
                                           value="0" min="0" step="0.01" onchange="calculateTotal()">
                                </div>
                            </div>
                        </div>
                        
                        <!-- สรุปยอดเงิน -->
                        <div class="p-3">
                            <div class="summary-card p-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>ยอดรวม:</span>
                                    <span class="fw-bold" id="totalAmount">฿0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>ส่วนลด:</span>
                                    <span class="text-danger" id="discountDisplay">-฿0.00</span>
                                </div>
                                <hr>
                                <div class="d-flex justify-content-between">
                                    <span class="h6">ยอดสุทธิ:</span>
                                    <span class="total-display" id="netAmount">฿0.00</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- วิธีการชำระเงิน -->
                        <div class="p-3 border-top">
                            <label class="form-label">วิธีการชำระเงิน</label>
                            <div class="payment-methods">
                                <input type="radio" class="btn-check" name="paymentMethod" id="cash" value="cash" checked>
                                <label class="btn btn-outline-success btn-sm" for="cash">
                                    <i class="bi bi-cash"></i> เงินสด
                                </label>
                                
                                <input type="radio" class="btn-check" name="paymentMethod" id="transfer" value="transfer">
                                <label class="btn btn-outline-info btn-sm" for="transfer">
                                    <i class="bi bi-credit-card"></i> โอน
                                </label>
                                
                                <input type="radio" class="btn-check" name="paymentMethod" id="qr" value="qr">
                                <label class="btn btn-outline-warning btn-sm" for="qr">
                                    <i class="bi bi-qr-code"></i> QR Code
                                </label>
                            </div>
                        </div>
                        
                        <!-- ลูกค้า -->
                        <div class="p-3 border-top">
                            <div class="customer-search-section">
                                <label class="form-label">ลูกค้า</label>
                                <div class="position-relative">
                                    <input type="hidden" id="customerId" name="customer_id">
                                    <input type="text" id="customerPhone" class="form-control" 
                                           placeholder="ค้นหาด้วยเบอร์โทรศัพท์..." autocomplete="off">
                                    
                                    <!-- กล่องแสดงผลการค้นหาลูกค้า -->
                                    <div id="customerSuggestions" class="list-group position-absolute w-100 shadow suggestion-box" 
                                         style="top: 100%; display: none;"></div>
                                </div>
                                
                                <!-- แสดงข้อมูลลูกค้าที่เลือก -->
                                <div id="selectedCustomer" class="mt-2" style="display: none;">
                                    <div class="alert alert-info py-2">
                                        <small><strong>ลูกค้าที่เลือก:</strong> <span id="customerName"></span></small>
                                        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="clearCustomer()">
                                            <i class="bi bi-x"></i> ล้าง
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- ทางเลือกอื่น: เลือกจากรายชื่อ -->
                                <div class="mt-2">
                                    <select id="customerSelect" class="form-select form-select-sm">
                                        <option value="">หรือเลือกจากรายชื่อ</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['id']; ?>">
                                                <?php echo htmlspecialchars($customer['name']); ?> 
                                                <?php if (!empty($customer['phone'])): ?>
                                                    - <?php echo htmlspecialchars($customer['phone']); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ปุ่มดำเนินการ -->
                        <div class="cart-total p-3">
                            <button class="btn btn-light w-100 mb-2" onclick="clearCart()">
                                <i class="bi bi-trash me-2"></i> ล้างตะกร้า
                            </button>
                            <button class="btn btn-warning w-100 text-dark fw-bold" onclick="processSale()" id="checkoutBtn" disabled>
                                <i class="bi bi-check-circle me-2"></i> ชำระเงิน
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="receiptModalLabel">
                        <i class="bi bi-receipt me-2"></i> ใบเสร็จรับเงิน
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="receiptContent" class="receipt"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-primary" onclick="printReceipt()">
                        <i class="bi bi-printer me-2"></i> ปริ้นท์
                    </button>
                    <button type="button" class="btn btn-success" onclick="newSale()">
                        <i class="bi bi-plus-circle me-2"></i> ขายใหม่
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/@ericblade/quagga2/dist/quagga.js"></script>

    <script>
        let cart = [];
        let searchTimeout;
        let customerSearchTimeout;
        
        // ตรวจสอบการพิมพ์ใบเสร็จหลังจากขายสำเร็จ
        <?php if (isset($_SESSION['print_receipt'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const receiptData = <?php echo json_encode($_SESSION['print_receipt']); ?>;
            showReceiptModal(receiptData);
        });
        <?php unset($_SESSION['print_receipt']); ?>
        <?php endif; ?>
        
function updateCartDisplay() {
    const cartItems = document.getElementById('cartItems');
    const emptyCart = document.getElementById('emptyCart');
    const checkoutBtn = document.getElementById('checkoutBtn');
    
    if (cart.length === 0) {
        // ถ้าตะกร้าว่าง แสดงข้อความว่าง
        cartItems.innerHTML = `
            <div class="text-center py-5" id="emptyCart">
                <i class="bi bi-cart-x display-4 text-muted"></i>
                <p class="text-muted mt-3">ตะกร้าว่าง</p>
                <p class="small text-muted">คลิกที่สินค้าเพื่อเพิ่มลงตะกร้า</p>
            </div>
        `;
        checkoutBtn.disabled = true;
    } else {
        // ถ้ามีสินค้าในตะกร้า สร้าง HTML สำหรับแสดงสินค้าทั้งหมด
        let html = '';
        cart.forEach(item => {
            html += `
                <div class="cart-item">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1">
                            <h6 class="mb-1">${item.name}</h6>
                            <p class="text-muted small mb-1">฿${item.price.toFixed(2)} x ${item.quantity}</p>
                            <div class="quantity-control">
                                <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity(${item.id}, -1)">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <input type="number" value="${item.quantity}" min="1" max="${item.stock}" 
                                       onchange="setQuantity(${item.id}, this.value)" class="form-control form-control-sm">
                                <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity(${item.id}, 1)">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-primary">฿${item.subtotal.toFixed(2)}</div>
                            <button class="btn btn-sm btn-outline-danger" onclick="removeFromCart(${item.id})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        // แสดงสินค้าทั้งหมดในตะกร้า
        cartItems.innerHTML = html;
        checkoutBtn.disabled = false;
    }
    
    calculateTotal();

}
// ตรวจสอบและแก้ไขฟังก์ชัน addToCart ด้วย
function addToCart(productId, productName, price, stock) {
    const existingItem = cart.find(item => item.id === productId);
    
    if (existingItem) {
        // ถ้ามีสินค้าอยู่ในตะกร้าแล้ว เพิ่มจำนวน
        if (existingItem.quantity < stock) {
            existingItem.quantity++;
            existingItem.subtotal = existingItem.quantity * existingItem.price;
        } else {
            Swal.fire({
                icon: 'warning',
                title: 'สินค้าไม่เพียงพอ',
                text: `คงเหลือเพียง ${stock} ชิ้น`
            });
            return;
        }
    } else {
        // ถ้ายังไม่มีในตะกร้า เพิ่มสินค้าใหม่
        cart.push({
            id: productId,
            name: productName,
            price: price,
            quantity: 1,
            stock: stock,
            subtotal: price
        });
    }
    
    // อัปเดตการแสดงผล
    updateCartDisplay();
    
    // แสดงการแจ้งเตือนว่าเพิ่มสินค้าสำเร็จ
    const toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1500,
        timerProgressBar: true
    });
    
    toast.fire({
        icon: 'success',
        title: `เพิ่ม ${productName} ลงตะกร้าแล้ว`
    });
}
        
        // อัปเดตจำนวนสินค้า
        function updateQuantity(productId, change) {
            const item = cart.find(item => item.id === productId);
            if (item) {
                const newQuantity = item.quantity + change;
                if (newQuantity > 0 && newQuantity <= item.stock) {
                    item.quantity = newQuantity;
                    item.subtotal = item.quantity * item.price;
                    updateCartDisplay();
                } else if (newQuantity <= 0) {
                    removeFromCart(productId);
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'สินค้าไม่เพียงพอ',
                        text: `คงเหลือเพียง ${item.stock} ชิ้น`
                    });
                }
            }
        }
        
        // ตั้งค่าจำนวนสินค้า
        function setQuantity(productId, quantity) {
            const item = cart.find(item => item.id === productId);
            if (item) {
                const qty = parseInt(quantity);
                if (qty > 0 && qty <= item.stock) {
                    item.quantity = qty;
                    item.subtotal = item.quantity * item.price;
                    updateCartDisplay();
                } else if (qty <= 0) {
                    removeFromCart(productId);
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'สินค้าไม่เพียงพอ',
                        text: `คงเหลือเพียง ${item.stock} ชิ้น`
                    });
                    updateCartDisplay();
                }
            }
        }
        
        // ลบสินค้าออกจากตะกร้า
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            updateCartDisplay();
        }
        
        // ล้างตะกร้า
        function clearCart() {
            if (cart.length > 0) {
                Swal.fire({
                    title: 'ยืนยันการล้างตะกร้า',
                    text: 'คุณต้องการล้างสินค้าทั้งหมดในตะกร้าหรือไม่?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'ยืนยัน',
                    cancelButtonText: 'ยกเลิก'
                }).then((result) => {
                    if (result.isConfirmed) {
                        cart = [];
                        updateCartDisplay();
                    }
                });
            }
        }
        
        // คำนวดยอดรวม
        function calculateTotal() {
            const totalAmount = cart.reduce((sum, item) => sum + item.subtotal, 0);
            const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
            const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
            
            let finalDiscountAmount = discountAmount;
            if (discountPercent > 0) {
                finalDiscountAmount = totalAmount * (discountPercent / 100);
                document.getElementById('discountAmount').value = finalDiscountAmount.toFixed(2);
            }
            
            const netAmount = Math.max(0, totalAmount - finalDiscountAmount);
            
            document.getElementById('totalAmount').textContent = `฿${totalAmount.toFixed(2)}`;
            document.getElementById('discountDisplay').textContent = `-฿${finalDiscountAmount.toFixed(2)}`;
            document.getElementById('netAmount').textContent = `฿${netAmount.toFixed(2)}`;
        }
        
        // ประมวลผลการขาย
        function processSale() {
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'ตะกร้าว่าง',
                    text: 'กรุณาเพิ่มสินค้าลงในตะกร้าก่อน'
                });
                return;
            }
            
            const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked').value;
            const customerId = document.getElementById('customerId').value || document.getElementById('customerSelect').value;
            const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
            const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
            
            const totalAmount = cart.reduce((sum, item) => sum + item.subtotal, 0);
            const netAmount = Math.max(0, totalAmount - discountAmount);
            
            Swal.fire({
                title: 'ยืนยันการขาย',
                html: `
                    <div class="text-start">
                        <p><strong>ยอดรวม:</strong> ฿${totalAmount.toFixed(2)}</p>
                        <p><strong>ส่วนลด:</strong> ฿${discountAmount.toFixed(2)}</p>
                        <p><strong>ยอดสุทธิ:</strong> ฿${netAmount.toFixed(2)}</p>
                        <p><strong>การชำระเงิน:</strong> ${getPaymentMethodText(paymentMethod)}</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันการขาย',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#28a745'
            }).then((result) => {
                if (result.isConfirmed) {
                    // สร้างฟอร์มและส่งข้อมูล
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const formData = {
                        action: 'process_sale',
                        sale_code: '<?php echo $new_sale_code; ?>',
                        customer_id: customerId,
                        total_amount: totalAmount,
                        discount_percent: discountPercent,
                        discount_amount: discountAmount,
                        net_amount: netAmount,
                        payment_method: paymentMethod,
                        items: JSON.stringify(cart.map(item => ({
                            product_id: item.id,
                            quantity: item.quantity,
                            unit_price: item.price,
                            subtotal: item.subtotal
                        })))
                    };
                    
                    Object.keys(formData).forEach(key => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = formData[key];
                        form.appendChild(input);
                    });
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // แปลงวิธีการชำระเงินเป็นข้อความ
        function getPaymentMethodText(method) {
            switch(method) {
                case 'cash': return 'เงินสด';
                case 'transfer': return 'โอนเงิน';
                case 'qr': return 'QR Code';
                default: return method;
            }
        }

        // ฟังก์ชันสร้างและแสดงใบเสร็จ
        function showReceiptModal(receiptData) {
            const receiptHTML = generateReceiptHTML(receiptData);
            document.getElementById('receiptContent').innerHTML = receiptHTML;
            
            const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
            receiptModal.show();
        }

        // ฟังก์ชันสร้าง HTML ใบเสร็จ
        function generateReceiptHTML(data) {
            const currentDate = new Date();
            const dateStr = currentDate.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            });
            const timeStr = currentDate.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit'
            });

            let itemsHTML = '';
            if (data.items && Array.isArray(data.items)) {
                data.items.forEach(item => {
                    itemsHTML += `
                        <div class="receipt-item">
                            <div class="receipt-item-name">${item.name || 'สินค้า'}</div>
                            <div class="receipt-item-qty">${item.quantity}</div>
                            <div class="receipt-item-price">฿${parseFloat(item.subtotal).toFixed(2)}</div>
                        </div>
                    `;
                });
            }

            return `
                <div class="receipt-header">
                    <h2>ร้านค้าของเรา</h2>
                    <div>123 ถนนตัวอย่าง อำเภอเมือง</div>
                    <div>จังหวัดตัวอย่าง 12345</div>
                    <div>โทร: 02-123-4567</div>
                </div>

                <div class="receipt-info">
                    <div style="display: flex; justify-content: space-between;">
                        <span>รหัสการขาย: ${data.sale_code || ''}</span>
                        <span>วันที่: ${dateStr}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>แคชเชียร์: <?php echo $_SESSION['username'] ?? 'ไม่ระบุ'; ?></span>
                        <span>เวลา: ${timeStr}</span>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 10px;">
                    <span>รายการ</span>
                    <span>จำนวน</span>
                    <span>ราคา</span>
                </div>
                <div style="border-bottom: 1px solid #333; margin-bottom: 10px;"></div>

                <div class="receipt-items">
                    ${itemsHTML}
                </div>

                <div class="receipt-total">
                    <div class="receipt-total-row">
                        <span>ยอดรวม:</span>
                        <span>฿${parseFloat(data.total_amount).toFixed(2)}</span>
                    </div>
                    ${parseFloat(data.discount_amount) > 0 ? `
                        <div class="receipt-total-row">
                            <span>ส่วนลด:</span>
                            <span>-฿${parseFloat(data.discount_amount).toFixed(2)}</span>
                        </div>
                    ` : ''}
                    <div style="border-top: 1px solid #333; margin: 5px 0;"></div>
                    <div class="receipt-total-row" style="font-size: 14px;">
                        <span>ยอดสุทธิ:</span>
                        <span>฿${parseFloat(data.net_amount).toFixed(2)}</span>
                    </div>
                    <div class="receipt-total-row">
                        <span>ชำระด้วย:</span>
                        <span>${getPaymentMethodText(data.payment_method)}</span>
                    </div>
                </div>

                <div class="receipt-footer">
                    <div>** ขอบคุณที่ใช้บริการ **</div>
                    <div>เก็บใบเสร็จไว้เป็นหลักฐาน</div>
                    <div>สอบถามข้อมูลเพิ่มเติม โทร 02-123-4567</div>
                </div>
            `;
        }

        // ฟังก์ชันปริ้นท์ใบเสร็จ
        function printReceipt() {
            window.print();
        }

        // ฟังก์ชันเริ่มขายใหม่
        function newSale() {
            cart = [];
            document.getElementById('discountPercent').value = 0;
            document.getElementById('discountAmount').value = 0;
            clearCustomer();
            updateCartDisplay();
            
            const receiptModal = bootstrap.Modal.getInstance(document.getElementById('receiptModal'));
            receiptModal.hide();
            
            // รีเฟรชหน้าเพื่อได้รหัสการขายใหม่
            window.location.reload();
        }
        
        // ค้นหาสินค้าด้วย AJAX
        async function searchProducts(query) {
            if (!query || query.length < 1) {
                hideSuggestions();
                return;
            }
            
            try {
                const response = await fetch(`../ajax/search_products.php?q=${encodeURIComponent(query)}`);
                const products = await response.json();
                
                if (Array.isArray(products) && products.length > 0) {
                    showProductSuggestions(products);
                } else {
                    hideSuggestions();
                }
            } catch (error) {
                console.error('Search error:', error);
                hideSuggestions();
            }
        }
        
        // แสดงผลลัพธ์การค้นหาสินค้า
        function showProductSuggestions(products) {
            const suggestionsBox = document.getElementById('searchSuggestions');
            suggestionsBox.innerHTML = '';
            
            products.forEach(product => {
                const item = document.createElement('div');
                item.className = 'suggestion-item';
                item.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${product.product_name}</strong>
                            <div class="small text-muted">รหัส: ${product.product_code || '-'}</div>
                        </div>
                        <div class="text-end">
                            <div class="text-primary fw-bold">฿${parseFloat(product.sale_price).toFixed(2)}</div>
                            <div class="small text-muted">คงเหลือ: ${product.quantity}</div>
                        </div>
                    </div>
                `;
                
                item.addEventListener('click', () => {
                    addToCart(product.id, product.product_name, parseFloat(product.sale_price), parseInt(product.quantity));
                    document.getElementById('searchProduct').value = '';
                    hideSuggestions();
                });
                
                suggestionsBox.appendChild(item);
            });
            
            suggestionsBox.style.display = 'block';
        }
        
        // ซ่อนผลลัพธ์การค้นหา
        function hideSuggestions() {
            document.getElementById('searchSuggestions').style.display = 'none';
        }
        
        // ค้นหาลูกค้าด้วยเบอร์โทร
        async function searchCustomers(phone) {
            if (!phone || phone.length < 3) {
                hideCustomerSuggestions();
                return;
            }
            
            try {
                const response = await fetch(`../ajax/search_customers.php?phone=${encodeURIComponent(phone)}`);
                const customers = await response.json();
                
                if (Array.isArray(customers) && customers.length > 0) {
                    showCustomerSuggestions(customers);
                } else {
                    hideCustomerSuggestions();
                }
            } catch (error) {
                console.error('Customer search error:', error);
                hideCustomerSuggestions();
            }
        }
        
        // แสดงผลลัพธ์การค้นหาลูกค้า
        function showCustomerSuggestions(customers) {
            const suggestionsBox = document.getElementById('customerSuggestions');
            suggestionsBox.innerHTML = '';
            
            customers.forEach(customer => {
                const item = document.createElement('div');
                item.className = 'suggestion-item';
                item.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${customer.name || 'ไม่มีชื่อ'}</strong>
                            <div class="small text-muted">${customer.phone || 'ไม่มีเบอร์'}</div>
                        </div>
                        <div class="small text-muted">
                            ${customer.email || ''}
                        </div>
                    </div>
                `;
                
                item.addEventListener('click', () => {
                    selectCustomer(customer);
                    hideCustomerSuggestions();
                });
                
                suggestionsBox.appendChild(item);
            });
            
            suggestionsBox.style.display = 'block';
        }
        
        // ซ่อนผลลัพธ์การค้นหาลูกค้า
        function hideCustomerSuggestions() {
            document.getElementById('customerSuggestions').style.display = 'none';
        }
        
        // เลือกลูกค้า
        function selectCustomer(customer) {
            document.getElementById('customerId').value = customer.id;
            document.getElementById('customerPhone').value = customer.phone || '';
            document.getElementById('customerName').textContent = customer.name;
            document.getElementById('selectedCustomer').style.display = 'block';
            document.getElementById('customerSelect').value = '';
        }
        
        // ล้างข้อมูลลูกค้า
        function clearCustomer() {
            document.getElementById('customerId').value = '';
            document.getElementById('customerPhone').value = '';
            document.getElementById('selectedCustomer').style.display = 'none';
            document.getElementById('customerSelect').value = '';
        }
        
        // ฟิลเตอร์สินค้าในหน้า
        function filterProductsOnPage() {
            const searchTerm = document.getElementById('searchProduct').value.toLowerCase();
            const categoryFilter = document.getElementById('categoryFilter').value.toLowerCase();
            const productItems = document.querySelectorAll('.product-item');
            
            productItems.forEach(item => {
                const name = item.getAttribute('data-name') || '';
                const code = item.getAttribute('data-code') || '';
                const category = item.getAttribute('data-category') || '';
                
                const matchesSearch = !searchTerm || name.includes(searchTerm) || code.includes(searchTerm);
                const matchesCategory = !categoryFilter || category.includes(categoryFilter);
                
                if (matchesSearch && matchesCategory) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        // ค้นหาด้วยรหัสแน่นอน (สำหรับบาร์โค้ดสแกนเนอร์)
        async function searchByExactCode(code) {
            try {
                const response = await fetch(`../ajax/get_product_by_code.php?code=${encodeURIComponent(code)}`);
                const product = await response.json();
                
                if (product && !product.error) {
                    addToCart(product.id, product.product_name, parseFloat(product.sale_price), parseInt(product.quantity));
                    document.getElementById('searchProduct').value = '';
                    hideSuggestions();
                    return true;
                }
            } catch (error) {
                console.error('Search by code error:', error);
            }
            return false;
        }
        
        // Event Listeners
        document.getElementById('searchProduct').addEventListener('input', function() {
            const query = this.value.trim();
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (query.length >= 1) {
                    searchProducts(query);
                } else {
                    hideSuggestions();
                }
            }, 300);
            
            // ฟิลเตอร์สินค้าในหน้าด้วย
            filterProductsOnPage();
        });
        
        document.getElementById('searchProduct').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const query = this.value.trim();
                if (query) {
                    // ลองค้นหาด้วยรหัสแน่นอนก่อน
                    searchByExactCode(query);
                }
            }
            
            if (e.key === 'Escape') {
                hideSuggestions();
            }
        });
        
        document.getElementById('customerPhone').addEventListener('input', function() {
            const phone = this.value.trim();
            
            clearTimeout(customerSearchTimeout);
            customerSearchTimeout = setTimeout(() => {
                if (phone.length >= 3) {
                    searchCustomers(phone);
                } else {
                    hideCustomerSuggestions();
                }
            }, 300);
        });
        
        document.getElementById('customerPhone').addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideCustomerSuggestions();
            }
        });
        
        document.getElementById('customerSelect').addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                const customerData = {
                    id: this.value,
                    name: selectedOption.text.split(' - ')[0],
                    phone: ''
                };
                selectCustomer(customerData);
            }
        });
        
        document.getElementById('categoryFilter').addEventListener('change', filterProductsOnPage);
        
        // คลิกนอกกล่องซ่อนผลลัพธ์
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#searchProduct') && !e.target.closest('#searchSuggestions')) {
                hideSuggestions();
            }
            
            if (!e.target.closest('#customerPhone') && !e.target.closest('#customerSuggestions')) {
                hideCustomerSuggestions();
            }
        });
        
        // อัปเดตส่วนลดเมื่อพิมพ์
        document.getElementById('discountPercent').addEventListener('input', function() {
            document.getElementById('discountAmount').value = 0;
            calculateTotal();
        });
        
        document.getElementById('discountAmount').addEventListener('input', function() {
            document.getElementById('discountPercent').value = 0;
            calculateTotal();
        });
        
        // เริ่มต้นการแสดงผล
        updateCartDisplay();
    </script>

    <!-- Barcode Scanner Modal -->
    <div class="modal fade" id="barcodeModal" tabindex="-1" aria-labelledby="barcodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="barcodeModalLabel">สแกนบาร์โค้ด</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="scannerContainer" class="ratio ratio-16x9 bg-dark rounded"></div>
                    <div class="small text-muted mt-2">แนะนำให้ใช้แสงสว่างเพียงพอ และถือกล้องให้นิ่ง</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Barcode Scanner
        let scannerActive = false;
        
        document.getElementById('btnOpenScanner').addEventListener('click', function() {
            const modal = new bootstrap.Modal(document.getElementById('barcodeModal'));
            modal.show();
            
            setTimeout(() => {
                startScanner();
            }, 300);
        });
        
        document.getElementById('barcodeModal').addEventListener('hidden.bs.modal', function() {
            stopScanner();
        });
        
        function startScanner() {
            Quagga.init({
                inputStream: {
                    name: 'Live',
                    type: 'LiveStream',
                    target: document.querySelector('#scannerContainer'),
                    constraints: {
                        width: 640,
                        height: 480,
                        facingMode: 'environment'
                    }
                },
                decoder: {
                    readers: [
                        'ean_reader',
                        'ean_8_reader', 
                        'code_128_reader',
                        'upc_reader',
                        'upc_e_reader'
                    ]
                },
                locate: true
            }, function(err) {
                if (err) {
                    console.error('Scanner initialization error:', err);
                    Swal.fire({
                        icon: 'error',
                        title: 'ไม่สามารถเปิดกล้องได้',
                        text: 'กรุณาตรวจสอบการอนุญาตการใช้กล้อง'
                    });
                    return;
                }
                
                Quagga.start();
                scannerActive = true;
            });
            
            Quagga.onDetected(async function(result) {
                if (!result || !result.codeResult || !result.codeResult.code) {
                    return;
                }
                
                const code = result.codeResult.code;
                
                if (await searchByExactCode(code)) {
                    stopScanner();
                    bootstrap.Modal.getInstance(document.getElementById('barcodeModal')).hide();
                }
            });
        }
        
        function stopScanner() {
            if (scannerActive) {
                Quagga.stop();
                scannerActive = false;
                Quagga.offDetected();
            }
        }
    </script>

</body>
</html>