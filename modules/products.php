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

// ฟังก์ชันจัดการสินค้า
function getProducts($pdo) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getProduct($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getCategories($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM categories ORDER BY category_name");
    $stmt->execute();
    return $stmt->fetchAll();
}

function addProduct($pdo, $data) {
    $stmt = $pdo->prepare("
        INSERT INTO products 
        (product_code, product_name, category_id, size, quantity, cost_price, sale_price, description) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([
        $data['product_code'],
        $data['product_name'],
        $data['category_id'],
        $data['size'],
        $data['quantity'],
        $data['cost_price'],
        $data['sale_price'],
        $data['description']
    ]);
}

function updateProduct($pdo, $id, $data) {
    $stmt = $pdo->prepare("
        UPDATE products 
        SET product_code = ?, product_name = ?, category_id = ?, 
            size = ?, quantity = ?, cost_price = ?, sale_price = ?, description = ? 
        WHERE id = ?
    ");
    return $stmt->execute([
        $data['product_code'],
        $data['product_name'],
        $data['category_id'],
        $data['size'],
        $data['quantity'],
        $data['cost_price'],
        $data['sale_price'],
        $data['description'],
        $id
    ]);
}

function deleteProduct($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    return $stmt->execute([$id]);
}

function generateProductCode($pdo) {
    $prefix = 'P';
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products");
    $stmt->execute();
    $result = $stmt->fetch();
    $number = str_pad($result['count'] + 1, 4, '0', STR_PAD_LEFT);
    return $prefix . $number;
}

// จัดการฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_code = trim($_POST['product_code'] ?? '');
    $product_name = trim($_POST['product_name'] ?? '');
    $category_id = $_POST['category_id'] ?? 0;
    $size = trim($_POST['size'] ?? '');
    $quantity = $_POST['quantity'] ?? 0;
    $cost_price = $_POST['cost_price'] ?? 0;
    $sale_price = $_POST['sale_price'] ?? 0;
    $description = trim($_POST['description'] ?? '');
    
    $form_action = $_POST['action'] ?? 'add';
    $form_id = $_POST['id'] ?? 0;
    
    if (!empty($product_code) && !empty($product_name)) {
        $product_data = [
            'product_code' => $product_code,
            'product_name' => $product_name,
            'category_id' => $category_id,
            'size' => $size,
            'quantity' => $quantity,
            'cost_price' => $cost_price,
            'sale_price' => $sale_price,
            'description' => $description
        ];
        
        if ($form_action === 'add') {
            if (addProduct($pdo, $product_data)) {
                $_SESSION['success_message'] = 'เพิ่มสินค้าสำเร็จ';
            } else {
                $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการเพิ่มสินค้า';
            }
        } elseif ($form_action === 'edit' && $form_id > 0) {
            if (updateProduct($pdo, $form_id, $product_data)) {
                $_SESSION['success_message'] = 'แก้ไขสินค้าสำเร็จ';
            } else {
                $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการแก้ไขสินค้า';
            }
        }
        header('Location: products.php');
        exit;
    } else {
        $_SESSION['error_message'] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        header('Location: products.php');
        exit;
    }
}

// ลบสินค้า
if ($action === 'delete' && $id > 0) {
    if (deleteProduct($pdo, $id)) {
        $_SESSION['success_message'] = 'ลบสินค้าสำเร็จ';
    } else {
        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการลบสินค้า';
    }
    header('Location: products.php');
    exit;
}

// ข้อมูลสำหรับหน้าแก้ไข
$product = [];
if ($action === 'edit' && $id > 0) {
    $product = getProduct($pdo, $id);
    if (!$product) {
        $_SESSION['error_message'] = 'ไม่พบสินค้านี้';
        header('Location: products.php');
        exit;
    }
}

$products = getProducts($pdo);
$categories = getCategories($pdo);
$new_product_code = generateProductCode($pdo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการสินค้า - ระบบสต็อกสินค้า</title>
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
        
        .product-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }
        
        .product-card:hover {
            transform: translateY(-5px);
        }
        
        .product-icon {
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
        
        #productsTable th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .stock-low {
            background-color: #fff3cd !important;
        }
        
        .stock-out {
            background-color: #f8d7da !important;
        }
        
        .btn-delete {
            transition: all 0.3s;
        }
        
        .btn-delete:hover {
            transform: scale(1.05);
            box-shadow: 0 0 10px rgba(220, 53, 69, 0.3);
        }
        
        .badge-stock {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
        
        .profit-positive {
            color: #198754;
            font-weight: bold;
        }
        
        .profit-negative {
            color: #dc3545;
            font-weight: bold;
        }
        
        .price-container {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .cost-price {
            font-size: 1rem;
            color: #198754;
            font-weight: bold;
        }
        
        .sale-price {
            font-size: 1rem;
            color: #198754;
            font-weight: bold;
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
                                <h1 class="h2 mb-0"><i class="bi bi-box-seam me-2"></i> จัดการสินค้า</h1>
                                <p class="mb-0">เพิ่ม แก้ไข และลบสินค้าในระบบ</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#productModal" data-action="add">
                                    <i class="bi bi-plus-circle me-1"></i> เพิ่มสินค้า
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
                    
                    <!-- การ์ดแสดงสถิติ -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card product-card text-center p-4">
                                <div class="product-icon">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <h3><?php echo count($products); ?></h3>
                                <p class="text-muted mb-0">สินค้าทั้งหมด</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card product-card text-center p-4">
                                <div class="product-icon text-success">
                                    <i class="bi bi-currency-exchange"></i>
                                </div>
                                <h3>฿<?php echo number_format(array_sum(array_column($products, 'sale_price'))); ?></h3>
                                <p class="text-muted mb-0">มูลค่าสินค้าทั้งหมด</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card product-card text-center p-4">
                                <div class="product-icon text-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                                <h3><?php echo count(array_filter($products, function($p) { return $p['quantity'] > 0 && $p['quantity'] <= 2; })); ?></h3>
                                <p class="text-muted mb-0">สินค้าใกล้หมด</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card product-card text-center p-4">
                                <div class="product-icon text-danger">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                                <h3><?php echo count(array_filter($products, function($p) { return $p['quantity'] == 0; })); ?></h3>
                                <p class="text-muted mb-0">สินค้าหมด</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ตารางสินค้า -->
                    <div class="card">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">รายการสินค้า</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="productsTable" class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="10%">รหัสสินค้า</th>
                                            <th width="20%">ชื่อสินค้า</th>
                                            <th width="15%">ประเภท</th>
                                            <th width="10%">ขนาด</th>
                                            <th width="10%">จำนวน</th>
                                            <th width="15%">ราคา</th>
                                            <th width="15%">การดำเนินการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($products) > 0): ?>
                                            <?php foreach ($products as $index => $prod): ?>
                                                <?php
                                                $row_class = '';
                                                $stock_status = '';
                                                if ($prod['quantity'] == 0) {
                                                    $row_class = 'stock-out';
                                                    $stock_status = '<span class="badge bg-danger badge-stock">หมด</span>';
                                                } elseif ($prod['quantity'] <= 2) {
                                                    $row_class = 'stock-low';
                                                    $stock_status = '<span class="badge bg-warning badge-stock">ใกล้หมด</span>';
                                                }
                                                
                                                // คำนวณกำไร
                                                // คำนวณกำไร
                                                $profit = $prod['sale_price'] - $prod['cost_price'];
                                                $profit_percent = $prod['cost_price'] > 0 ? (($profit / $prod['cost_price']) * 100) : 0;
                                                $profit_class = $profit >= 0 ? 'profit-positive' : 'profit-negative';
                                                ?>
                                                <tr class="<?php echo $row_class; ?>">
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars($prod['product_code']); ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-info rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                                <i class="bi bi-box text-white"></i>
                                                            </div>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($prod['product_name']); ?></strong>
                                                                <?php echo $stock_status; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($prod['category_name'] ?? 'ไม่มีหมวดหมู่'); ?></td>
                                                    <td><?php echo !empty($prod['size']) ? htmlspecialchars($prod['size']) : '-'; ?></td>
                                                    <td>
                                                        <span class="fw-bold"><?php echo number_format($prod['quantity']); ?></span>
                                                        <div class="progress mt-1" style="height: 5px;">
                                                            <?php
                                                            $progress_width = min(100, ($prod['quantity'] / 100) * 100);
                                                            $progress_class = $prod['quantity'] > 20 ? 'bg-success' : ($prod['quantity'] > 0 ? 'bg-warning' : 'bg-danger');
                                                            ?>
                                                            <div class="progress-bar <?php echo $progress_class; ?>" style="width: <?php echo $progress_width; ?>%"></div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="price-container">
                                                            <span class="cost-price">ต้นทุน: ฿<?php echo number_format($prod['cost_price'], 2); ?></span>
                                                            <span class="sale-price">ขาย: ฿<?php echo number_format($prod['sale_price'], 2); ?></span>
                                                            <small class="<?php echo $profit_class; ?>">
                                                                กำไร: ฿<?php echo number_format($profit, 2); ?> 
                                                                (<?php echo number_format($profit_percent, 1); ?>%)
                                                            </small>
                                                        </div>
                                                    </td>
                                                    <td class="action-buttons">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#productModal"
                                                                data-action="edit"
                                                                data-id="<?php echo $prod['id']; ?>"
                                                                data-code="<?php echo htmlspecialchars($prod['product_code']); ?>"
                                                                data-name="<?php echo htmlspecialchars($prod['product_name']); ?>"
                                                                data-category="<?php echo $prod['category_id']; ?>"
                                                                data-size="<?php echo htmlspecialchars($prod['size']); ?>"
                                                                data-quantity="<?php echo $prod['quantity']; ?>"
                                                                data-cost_price="<?php echo $prod['cost_price']; ?>"
                                                                data-sale_price="<?php echo $prod['sale_price']; ?>"
                                                                data-description="<?php echo htmlspecialchars($prod['description']); ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger btn-delete" 
                                                                data-id="<?php echo $prod['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($prod['product_name']); ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <i class="bi bi-inbox display-4 d-block text-muted mb-2"></i>
                                                    <p class="text-muted">ยังไม่มีสินค้า</p>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" data-action="add">
                                                        <i class="bi bi-plus-circle me-1"></i> เพิ่มสินค้าแรก
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal สำหรับเพิ่ม/แก้ไขสินค้า -->
    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productModalLabel">เพิ่มสินค้าใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="products.php">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="product_code" class="form-label">รหัสสินค้า <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="product_code" name="product_code" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="product_name" class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="product_name" name="product_name" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="category_id" class="form-label">หมวดหมู่</label>
                                    <select class="form-select" id="category_id" name="category_id">
                                        <option value="">เลือกหมวดหมู่</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="size" class="form-label">ขนาด</label>
                                    <select class="form-select" id="size" name="size">
                                        <option value="">เลือกขนาด</option>
                                        <option value="S">S</option>
                                        <option value="M">M</option>
                                        <option value="L">L</option>
                                        <option value="XL">XL</option>
                                        <option value="XXL">XXL</option>
                                        <option value="FREE">FREE SIZE</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="quantity" class="form-label">จำนวน <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="quantity" name="quantity" min="0" value="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="cost_price" class="form-label">ราคาต้นทุน (บาท) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="cost_price" name="cost_price" min="0" step="0.01" value="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="sale_price" class="form-label">ราคาขาย (บาท) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="sale_price" name="sale_price" min="0" step="0.01" value="0" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">คำอธิบาย</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // ตั้งค่า DataTable
            $('#productsTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                },
                responsive: true,
                columnDefs: [
                    { orderable: false, targets: [7] } // ปิดการเรียงลำดับคอลัมน์การดำเนินการ
                ]
            });
            
            // จัดการ Modal สำหรับแก้ไข
            const productModal = document.getElementById('productModal');
            const formAction = document.getElementById('formAction');
            const formId = document.getElementById('formId');
            const productCode = document.getElementById('product_code');
            const productName = document.getElementById('product_name');
            const categoryId = document.getElementById('category_id');
            const size = document.getElementById('size');
            const quantity = document.getElementById('quantity');
            const costPrice = document.getElementById('cost_price');
            const salePrice = document.getElementById('sale_price');
            const description = document.getElementById('description');
            const modalTitle = document.getElementById('productModalLabel');
            
            productModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const action = button.getAttribute('data-action');
                
                if (action === 'edit') {
                    // โหมดแก้ไข
                    modalTitle.textContent = 'แก้ไขสินค้า';
                    formAction.value = 'edit';
                    formId.value = button.getAttribute('data-id');
                    productCode.value = button.getAttribute('data-code') || '';
                    productName.value = button.getAttribute('data-name') || '';
                    categoryId.value = button.getAttribute('data-category') || '';
                    size.value = button.getAttribute('data-size') || '';
                    quantity.value = button.getAttribute('data-quantity') || '0';
                    costPrice.value = button.getAttribute('data-cost_price') || '0';
                    salePrice.value = button.getAttribute('data-sale_price') || '0';
                    description.value = button.getAttribute('data-description') || '';
                } else {
                    // โหมดเพิ่ม
                    modalTitle.textContent = 'เพิ่มสินค้าใหม่';
                    formAction.value = 'add';
                    formId.value = '';
                    productCode.value = '<?php echo $new_product_code; ?>';
                    productName.value = '';
                    categoryId.value = '';
                    size.value = '';
                    quantity.value = '0';
                    costPrice.value = '0';
                    salePrice.value = '0';
                    description.value = '';
                }
            });
            
            // รีเซ็ต Modal เมื่อปิด
            productModal.addEventListener('hidden.bs.modal', function() {
                modalTitle.textContent = 'เพิ่มสินค้าใหม่';
                formAction.value = 'add';
                formId.value = '';
                productCode.value = '<?php echo $new_product_code; ?>';
                productName.value = '';
                categoryId.value = '';
                size.value = '';
                quantity.value = '0';
                costPrice.value = '0';
                salePrice.value = '0';
                description.value = '';
            });
            
            // การจัดการการลบด้วย SweetAlert2
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.getAttribute('data-id');
                    const productName = this.getAttribute('data-name');
                    
                    Swal.fire({
                        title: 'ยืนยันการลบ',
                        html: `<div class="text-center">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">คุณแน่ใจว่าต้องการลบสินค้านี้?</h4>
                            <p class="text-danger">"${productName}"</p>
                            <p class="text-muted">การลบสินค้าจะไม่สามารถกู้คืนได้</p>
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
                            window.location.href = `products.php?action=delete&id=${productId}`;
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>