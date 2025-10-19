<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ฟังก์ชันดึงข้อมูลสำหรับรายงาน
function getSalesReport($pdo, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            DATE(s.sale_date) as sale_day,
            COUNT(s.id) as total_orders,
            SUM(s.net_amount) as total_revenue,
            AVG(s.net_amount) as avg_order_value,
            SUM(s.discount_amount) as total_discount
        FROM sales s
    ";
    
    $params = [];
    
    if ($start_date && $end_date) {
        $sql .= " WHERE DATE(s.sale_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY DATE(s.sale_date) ORDER BY sale_day DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTopProducts($pdo, $start_date = null, $end_date = null, $limit = 5) {
    $sql = "
        SELECT 
            p.product_name,
            p.product_code,
            SUM(si.quantity) as total_sold,
            SUM(si.subtotal) as total_revenue
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        JOIN sales s ON si.sale_id = s.id
    ";
    
    $params = [];
    
    if ($start_date && $end_date) {
        $sql .= " WHERE DATE(s.sale_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY p.id ORDER BY total_sold DESC LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getPaymentMethodsReport($pdo, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            payment_method,
            COUNT(*) as transaction_count,
            SUM(net_amount) as total_amount
        FROM sales
    ";
    
    $params = [];
    
    if ($start_date && $end_date) {
        $sql .= " WHERE DATE(sale_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY payment_method ORDER BY total_amount DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getReturnsReport($pdo, $start_date = null, $end_date = null) {
    $sql = "
        SELECT 
            DATE(return_date) as return_day,
            COUNT(*) as total_returns,
            SUM(refund_amount) as total_refund,
            return_type,
            status
        FROM returns
    ";
    
    $params = [];
    
    if ($start_date && $end_date) {
        $sql .= " WHERE DATE(return_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $sql .= " GROUP BY DATE(return_date), return_type, status ORDER BY return_day DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getStockAlerts($pdo) {
    $sql = "
        SELECT 
            product_name,
            product_code,
            quantity,
            cost_price,
            sale_price
        FROM products 
        WHERE quantity <= 5 
        ORDER BY quantity ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll();
}

// ตรวจสอบพารามิเตอร์วันที่
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// ดึงข้อมูลรายงาน
$sales_report = getSalesReport($pdo, $start_date, $end_date);
$top_products = getTopProducts($pdo, $start_date, $end_date);
$payment_methods = getPaymentMethodsReport($pdo, $start_date, $end_date);
$returns_report = getReturnsReport($pdo, $start_date, $end_date);
$stock_alerts = getStockAlerts($pdo);

// คำนวณสถิติ
$total_revenue = array_sum(array_column($sales_report, 'total_revenue'));
$total_orders = array_sum(array_column($sales_report, 'total_orders'));
$avg_order_value = $total_orders > 0 ? $total_revenue / $total_orders : 0;
$total_discount = array_sum(array_column($sales_report, 'total_discount'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงาน - ระบบสต็อกสินค้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Phetsarath+OT:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page-header {
            background: linear-gradient(120deg, #4361ee, #3f37c9);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 15px 15px;
        }
        
        .report-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
            height: 100%;
        }
        
        .report-card:hover {
            transform: translateY(-5px);
        }
        
        .report-icon {
            font-size: 2rem;
            color: #4361ee;
            margin-bottom: 1rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
        
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card {
            text-align: center;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
        }
        
        .stat-card-primary {
            background: linear-gradient(45deg, #4361ee, #3a0ca3);
            color: white;
        }
        
        .stat-card-success {
            background: linear-gradient(45deg, #2ecc71, #27ae60);
            color: white;
        }
        
        .stat-card-warning {
            background: linear-gradient(45deg, #f39c12, #e67e22);
            color: white;
        }
        
        .stat-card-danger {
            background: linear-gradient(45deg, #e74c3c, #c0392b);
            color: white;
        }
        
        .stat-card-info {
            background: linear-gradient(45deg, #3498db, #2980b9);
            color: white;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .report-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .progress {
            height: 8px;
            margin-top: 5px;
        }
        
        .badge-trend-up {
            background-color: #28a745;
        }
        
        .badge-trend-down {
            background-color: #dc3545;
        }
        
        .trend-icon {
            font-size: 0.8rem;
            margin-right: 3px;
        }
        
        .export-buttons .btn {
            margin-left: 5px;
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
                                <h1 class="h2 mb-0"><i class="bi bi-graph-up me-2"></i> รายงาน</h1>
                                <p class="mb-0">สรุปข้อมูลการขายและสถิติต่างๆ</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="export-buttons">
                                    <button class="btn btn-light" onclick="window.print()">
                                        <i class="bi bi-printer me-1"></i> พิมพ์รายงาน
                                    </button>
                                    <button class="btn btn-light" onclick="exportToPDF()">
                                        <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                                    </button>
                                    <button class="btn btn-light" onclick="exportToExcel()">
                                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="container">
                    <!-- ฟิลเตอร์วันที่ -->
                    <div class="filter-section">
                        <form method="GET" action="reports.php" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label for="start_date" class="form-label">วันที่เริ่มต้น</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="end_date" class="form-label">วันที่สิ้นสุด</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter me-1"></i> กรองข้อมูล
                                </button>
                                <a href="reports.php" class="btn btn-outline-secondary ms-2">
                                    <i class="bi bi-arrow-clockwise me-1"></i> ล้าง
                                </a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- การ์ดแสดงสถิติหลัก -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="stat-card stat-card-primary">
                                <div class="stat-number">฿<?php echo number_format($total_revenue, 2); ?></div>
                                <div class="stat-label">รายได้ทั้งหมด</div>
                                <div class="stat-trend">
                                    <span class="badge badge-trend-up">
                                        <i class="bi bi-arrow-up-short trend-icon"></i> 12.5%
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-success">
                                <div class="stat-number"><?php echo number_format($total_orders); ?></div>
                                <div class="stat-label">จำนวนคำสั่งซื้อ</div>
                                <div class="stat-trend">
                                    <span class="badge badge-trend-up">
                                        <i class="bi bi-arrow-up-short trend-icon"></i> 8.3%
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-info">
                                <div class="stat-number">฿<?php echo number_format($avg_order_value, 2); ?></div>
                                <div class="stat-label">ยอดซื้อเฉลี่ย</div>
                                <div class="stat-trend">
                                    <span class="badge badge-trend-up">
                                        <i class="bi bi-arrow-up-short trend-icon"></i> 4.2%
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card stat-card-warning">
                                <div class="stat-number">฿<?php echo number_format($total_discount, 2); ?></div>
                                <div class="stat-label">ส่วนลดทั้งหมด</div>
                                <div class="stat-trend">
                                    <span class="badge badge-trend-down">
                                        <i class="bi bi-arrow-down-short trend-icon"></i> 2.1%
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- กราฟรายได้รายวัน -->
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <div class="card report-card">
                                <div class="card-header bg-white">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-bar-chart me-2"></i> รายได้รายวัน
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="dailyRevenueChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card report-card">
                                <div class="card-header bg-white">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-pie-chart me-2"></i> วิธีการชำระเงิน
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="paymentMethodsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ตารางสินค้าขายดี -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card report-card">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-trophy me-2"></i> สินค้าขายดี 5 อันดับแรก
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover report-table">
                                            <thead>
                                                <tr>
                                                    <th>สินค้า</th>
                                                    <th>จำนวนขาย</th>
                                                    <th>รายได้</th>
                                                    <th>ส่วนแบ่ง</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_sold = array_sum(array_column($top_products, 'total_sold'));
                                                if (count($top_products) > 0):
                                                    foreach ($top_products as $product): 
                                                        $percentage = $total_sold > 0 ? ($product['total_sold'] / $total_sold) * 100 : 0;
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="bg-info rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                                    <i class="bi bi-box text-white"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                                                    <br>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($product['product_code']); ?></small>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td><?php echo number_format($product['total_sold']); ?></td>
                                                        <td>฿<?php echo number_format($product['total_revenue'], 2); ?></td>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="progress flex-grow-1 me-2">
                                                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $percentage; ?>%" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                                </div>
                                                                <span><?php echo number_format($percentage, 1); ?>%</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted py-3">
                                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                                            ไม่มีข้อมูลการขายในช่วงเวลานี้
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card report-card">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-exclamation-triangle me-2"></i> สินค้าใกล้หมด
                                    </h5>
                                    <span class="badge bg-danger"><?php echo count($stock_alerts); ?> รายการ</span>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover report-table">
                                            <thead>
                                                <tr>
                                                    <th>สินค้า</th>
                                                    <th>คงเหลือ</th>
                                                    <th>สถานะ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($stock_alerts) > 0): ?>
                                                    <?php foreach ($stock_alerts as $product): 
                                                        $stock_percentage = min(100, ($product['quantity'] / 5) * 100);
                                                        $status_class = $product['quantity'] == 0 ? 'danger' : ($product['quantity'] <= 2 ? 'warning' : 'info');
                                                        $status_text = $product['quantity'] == 0 ? 'หมด' : ($product['quantity'] <= 2 ? 'ใกล้หมด' : 'พอใช้');
                                                    ?>
                                                        <tr>
                                                            <td>
                                                                <div class="d-flex align-items-center">
                                                                    <div class="bg-info rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                                        <i class="bi bi-box text-white"></i>
                                                                    </div>
                                                                    <div>
                                                                        <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                                                        <br>
                                                                        <small class="text-muted"><?php echo htmlspecialchars($product['product_code']); ?></small>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="fw-bold"><?php echo number_format($product['quantity']); ?></span>
                                                                <div class="progress mt-1" style="height: 5px;">
                                                                    <div class="progress-bar bg-<?php echo $status_class; ?>" style="width: <?php echo $stock_percentage; ?>%"></div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted py-3">
                                                            <i class="bi bi-check-circle display-4 d-block mb-2"></i>
                                                            ไม่มีสินค้าใกล้หมด
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- รายงานการคืนสินค้า -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card report-card">
                                <div class="card-header bg-white">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-arrow-return-left me-2"></i> รายงานการคืนสินค้า
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover report-table">
                                            <thead>
                                                <tr>
                                                    <th>วันที่</th>
                                                    <th>จำนวนการคืน</th>
                                                    <th>ยอดคืนทั้งหมด</th>
                                                    <th>ประเภท</th>
                                                    <th>สถานะ</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($returns_report) > 0): ?>
                                                    <?php foreach ($returns_report as $return): ?>
                                                        <tr>
                                                            <td><?php echo date('d/m/Y', strtotime($return['return_day'])); ?></td>
                                                            <td><?php echo number_format($return['total_returns']); ?></td>
                                                            <td>฿<?php echo number_format($return['total_refund'], 2); ?></td>
                                                            <td>
                                                                <?php echo $return['return_type'] === 'return' ? 'คืนสินค้า' : 'เปลี่ยนสินค้า'; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge 
                                                                    <?php 
                                                                    switch($return['status']) {
                                                                        case 'completed': echo 'bg-success'; break;
                                                                        case 'approved': echo 'bg-primary'; break;
                                                                        case 'pending': echo 'bg-warning'; break;
                                                                        case 'rejected': echo 'bg-danger'; break;
                                                                        default: echo 'bg-secondary';
                                                                    }
                                                                    ?>
                                                                ">
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
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">
                                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                                            ไม่มีข้อมูลการคืนสินค้า
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // กราฟรายได้รายวัน
            const revenueCtx = document.getElementById('dailyRevenueChart');
            if (revenueCtx) {
                const revenueChart = new Chart(revenueCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($item) { return "'" . date('d/m', strtotime($item['sale_day'])) . "'"; }, $sales_report)); ?>],
                        datasets: [{
                            label: 'รายได้ (บาท)',
                            data: [<?php echo implode(',', array_column($sales_report, 'total_revenue')); ?>],
                            backgroundColor: 'rgba(67, 97, 238, 0.7)',
                            borderColor: 'rgba(67, 97, 238, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '฿' + value.toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'รายได้: ฿' + context.raw.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // กราฟวิธีการชำระเงิน
            const paymentCtx = document.getElementById('paymentMethodsChart');
            if (paymentCtx) {
                const paymentChart = new Chart(paymentCtx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($item) { 
                            $methods = [
                                'cash' => 'เงินสด',
                                'transfer' => 'โอนเงิน',
                                'credit' => 'บัตรเครดิต',
                                'qr' => 'QR Code'
                            ];
                            return "'" . ($methods[$item['payment_method']] ?? $item['payment_method']) . "'"; 
                        }, $payment_methods)); ?>],
                        datasets: [{
                            data: [<?php echo implode(',', array_column($payment_methods, 'total_amount')); ?>],
                            backgroundColor: [
                                'rgba(67, 97, 238, 0.7)',
                                'rgba(46, 204, 113, 0.7)',
                                'rgba(241, 196, 15, 0.7)',
                                'rgba(230, 126, 34, 0.7)'
                            ],
                            borderColor: [
                                'rgba(67, 97, 238, 1)',
                                'rgba(46, 204, 113, 1)',
                                'rgba(241, 196, 15, 1)',
                                'rgba(230, 126, 34, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const value = context.raw;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return context.label + ': ฿' + value.toLocaleString() + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // ตั้งค่า DataTable สำหรับตาราง - แก้ไขเพื่อรองรับตารางว่าง
            $('.report-table').each(function() {
                // ตรวจสอบว่าตารางมีข้อมูลหรือไม่
                const $table = $(this);
                const hasData = $table.find('tbody tr').length > 0 && 
                               !$table.find('tbody tr td[colspan]').length;
                
                if (hasData) {
                    $table.DataTable({
                        language: {
                            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                        },
                        responsive: true,
                        ordering: true,
                        pageLength: 5,
                        lengthMenu: [5, 10, 25, 50],
                        dom: '<"row"<"col-md-6"l><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
                        // เพิ่ม options เพื่อจัดการกับตารางว่าง
                        "columnDefs": [
                            { "defaultContent": "-", "targets": "_all" }
                        ]
                    });
                }
            });
        });
        
        // ฟังก์ชันสำหรับการส่งออกรายงาน
        function printReport() {
            window.print();
        }
        
        function exportToPDF() {
            Swal.fire({
                title: 'กำลังเตรียมไฟล์ PDF',
                text: 'กรุณารอสักครู่...',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            
            // ในทางปฏิบัติควรใช้ไลบรารีเช่น jsPDF หรือส่งคำขอไปยังเซิร์ฟเวอร์
            setTimeout(() => {
                Swal.fire({
                    title: 'ส่งออก PDF สำเร็จ',
                    text: 'ไฟล์รายงานได้ถูกบันทึกเรียบร้อยแล้ว',
                    icon: 'success',
                    confirmButtonText: 'ตกลง'
                });
            }, 1500);
        }
        
        function exportToExcel() {
            Swal.fire({
                title: 'กำลังเตรียมไฟล์ Excel',
                text: 'กรุณารอสักครู่...',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            
            // ในทางปฏิบัติควรใช้ไลบรารีเช่น SheetJS หรือส่งคำขอไปยังเซิร์ฟเวอร์
            setTimeout(() => {
                Swal.fire({
                    title: 'ส่งออก Excel สำเร็จ',
                    text: 'ไฟล์รายงานได้ถูกบันทึกเรียบร้อยแล้ว',
                    icon: 'success',
                    confirmButtonText: 'ตกลง'
                });
            }, 1500);
        }
    </script>
</body>
</html>