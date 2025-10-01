<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$id = $_GET['id'] ?? 0;

if (!$id) {
    die('ไม่พบการขายนี้');
}

// ฟังก์ชันดึงข้อมูลการขาย
function getSale($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as customer_name, c.phone as customer_phone,
               u.username as cashier_name
        FROM sales s 
        LEFT JOIN customers c ON s.customer_id = c.id 
        LEFT JOIN users u ON s.cashier_id = u.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getSaleItems($pdo, $sale_id) {
    $stmt = $pdo->prepare("
        SELECT si.*, p.product_name, p.product_code
        FROM sale_items si 
        LEFT JOIN products p ON si.product_id = p.id 
        WHERE si.sale_id = ?
    ");
    $stmt->execute([$sale_id]);
    return $stmt->fetchAll();
}

$sale = getSale($pdo, $id);
$sale_items = getSaleItems($pdo, $id);

if (!$sale) {
    die('ไม่พบการขายนี้');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบเสร็จ #<?php echo $sale['sale_code']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #fff;
        }
        .receipt-container {
            max-width: 400px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ddd;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px dashed #ddd;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .receipt-footer {
            text-align: center;
            border-top: 2px dashed #ddd;
            padding-top: 15px;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        @media print {
            body * {
                visibility: hidden;
            }
            .receipt-container, .receipt-container * {
                visibility: visible;
            }
            .receipt-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                border: none;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-4 no-print">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>ใบเสร็จรับเงิน</h2>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="bi bi-printer"></i> พิมพ์ใบเสร็จ
            </button>
        </div>
    </div>
    
    <div class="receipt-container">
        <div class="receipt-header">
            <h3>ร้านขายเสื้อผ้า FashionStock</h3>
            <p class="mb-1">ที่อยู่: 123 ถนนแฟชั่น กรุงเทพมหานคร</p>
            <p class="mb-1">โทร: 02-345-6789</p>
            <p>ใบเสร็จรับเงิน</p>
        </div>
        
        <div class="receipt-body">
            <div class="row mb-3">
                <div class="col-6">
                    <strong>เลขที่:</strong> <?php echo $sale['sale_code']; ?>
                </div>
                <div class="col-6 text-end">
                    <strong>วันที่:</strong> <?php echo date('d/m/Y H:i', strtotime($sale['sale_date'])); ?>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-12">
                    <strong>ลูกค้า:</strong> <?php echo $sale['customer_name'] ?? 'ลูกค้าทั่วไป'; ?>
                    <?php if (!empty($sale['customer_phone'])): ?>
                        <br><small>โทร: <?php echo $sale['customer_phone']; ?></small>
                    <?php endif; ?>
                </div>
            </div>
            
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>สินค้า</th>
                        <th class="text-end">จำนวน</th>
                        <th class="text-end">ราคา</th>
                        <th class="text-end">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sale_items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td class="text-end"><?php echo $item['quantity']; ?></td>
                            <td class="text-end">฿<?php echo number_format($item['unit_price'], 2); ?></td>
                            <td class="text-end">฿<?php echo number_format($item['subtotal'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end"><strong>รวม:</strong></td>
                        <td class="text-end"><strong>฿<?php echo number_format($sale['total_amount'], 2); ?></strong></td>
                    </tr>
                    <?php if ($sale['discount'] > 0): ?>
                        <tr>
                            <td colspan="3" class="text-end"><strong>ส่วนลด:</strong></td>
                            <td class="text-end"><strong>-฿<?php echo number_format($sale['discount'], 2); ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td colspan="3" class="text-end"><strong>ยอดสุทธิ:</strong></td>
                        <td class="text-end"><strong>฿<?php echo number_format($sale['net_amount'], 2); ?></strong></td>
                    </tr>
                    <tr>
                        <td colspan="3" class="text-end"><strong>ชำระโดย:</strong></td>
                        <td class="text-end">
                            <?php
                            $payment_methods = [
                                'cash' => 'เงินสด',
                                'transfer' => 'โอนเงิน',
                                'credit' => 'บัตรเครดิต',
                                'qr' => 'QR Code'
                            ];
                            echo $payment_methods[$sale['payment_method']] ?? $sale['payment_method'];
                            ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <div class="receipt-footer">
            <p>ขอบคุณที่ใช้บริการ</p>
            <p>พนักงาน: <?php echo $sale['cashier_name']; ?></p>
            <small>***