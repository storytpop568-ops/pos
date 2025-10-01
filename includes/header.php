<?php
// ตรวจสอบว่าไฟล์ config ถูก include แล้วหรือยัง
if (!isset($pdo)) {
    require_once 'config.php';
}

// ตรวจสอบว่าไฟล์ auth ถูก include แล้วหรือยัง
if (!function_exists('isLoggedIn')) {
    require_once 'auth.php';
}

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ฟังก์ชันดึงจำนวนการแจ้งเตือน
function getNotificationCount($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch();
        return $result['count'];
    } catch (PDOException $e) {
        error_log("Error getting notification count: " . $e->getMessage());
        return 0;
    }
}

// ฟังก์ชันดึงการแจ้งเตือนล่าสุด
function getRecentNotifications($pdo, $limit = 5) {
    try {
        // ใช้ CAST เพื่อแปลง LIMIT เป็น integer เพื่อป้องกัน SQL injection
        $stmt = $pdo->prepare("
            SELECT * 
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT " . (int)$limit
        );
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting recent notifications: " . $e->getMessage());
        return [];
    }
}

// ฟังก์ชัน fallback หากตาราง notifications ไม่มี
function createNotificationsTable($pdo) {
    try {
        $sql = "
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
            link VARCHAR(500) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        $pdo->exec($sql);
        
        // เพิ่มข้อมูลตัวอย่าง
        $sampleData = [
            [1, 'สินค้าใกล้หมด', 'เสื้อโปโล สีขาว ขนาด L เหลือเพียง 5 ชิ้น', 'warning', 'products.php?edit=1'],
            [1, 'การสั่งซื้อใหม่', 'มีคำสั่งซื้อใหม่ #ORD-2023-0011', 'success', 'orders.php?id=11'],
            [1, 'การชำระเงิน', 'การชำระเงินสำหรับคำสั่งซื้อ #ORD-2023-0010 เสร็จสมบูรณ์', 'info', 'orders.php?id=10'],
            [1, 'สินค้าหมด', 'กางเกงยีนส์ สีน้ำเงิน ขนาด 32 หมดสต็อก', 'danger', 'products.php?edit=2'],
            [1, 'การจัดส่ง', 'คำสั่งซื้อ #ORD-2023-0009 กำลังจัดส่ง', 'info', 'orders.php?id=9']
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($sampleData as $data) {
            $stmt->execute($data);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating notifications table: " . $e->getMessage());
        return false;
    }
}

// ตรวจสอบว่าตาราง notifications มีอยู่หรือไม่
try {
    $notificationCount = getNotificationCount($pdo);
    $recentNotifications = getRecentNotifications($pdo);
} catch (PDOException $e) {
    // หากตารางไม่มีอยู่ ให้สร้างตาราง
    if (strpos($e->getMessage(), 'Table') !== false && strpos($e->getMessage(), 'doesn\'t exist') !== false) {
        createNotificationsTable($pdo);
        $notificationCount = getNotificationCount($pdo);
        $recentNotifications = getRecentNotifications($pdo);
    } else {
        error_log("Database error: " . $e->getMessage());
        $notificationCount = 0;
        $recentNotifications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการสต็อกสินค้า - เสื้อผ้า</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Phetsarath+OT:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ... CSS styles remain the same ... */
        @import url('https://fonts.googleapis.com/css2?family=Phetsarath&display=swap');

body {
    font-family: 'Phetsarath', sans-serif;
}
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top shadow">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-shop me-2"></i>FashionStock
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> แดชบอร์ด
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>" href="products.php">
                            <i class="bi bi-box-seam me-1"></i> สินค้า
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="categories.php">
                            <i class="bi bi-tags me-1"></i> ประเภท
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'returns.php' ? 'active' : ''; ?>" href="returns.php">
                            <i class="bi bi-truck me-1"></i> คืนสินค้า
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'sales.php' ? 'active' : ''; ?>" href="sales.php">
                            <i class="bi bi-graph-up me-1"></i> รายงาน
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                            <i class="bi bi-graph-up me-1"></i> รายงาน
                        </a>
                    </li>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" href="users.php">
                            <i class="bi bi-people me-2"></i>
                            จัดการผู้ใช้
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
                            <i class="bi bi-people me-2"></i>
                            จัดการลูกค้า
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <!-- การแจ้งเตือน -->
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" href="#" id="notificationDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell fs-5"></i>
                            <?php if ($notificationCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger pulse notification-badge">
                                    <?php echo $notificationCount > 9 ? '9+' : $notificationCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                            <li class="dropdown-header d-flex justify-content-between align-items-center">
                                <span>การแจ้งเตือน</span>
                                <?php if ($notificationCount > 0): ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick="markAllAsRead()">
                                        <i class="bi bi-check-all me-1"></i> อ่านทั้งหมด
                                    </button>
                                <?php endif; ?>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            
                            <?php if (count($recentNotifications) > 0): ?>
                                <?php foreach ($recentNotifications as $notification): ?>
                                    <li>
                                        <a class="dropdown-item notification-item <?php echo $notification['is_read'] == 0 ? 'unread' : ''; ?>" href="<?php echo $notification['link'] ?: 'javascript:void(0);'; ?>">
                                            <div class="d-flex align-items-start">
                                                <div class="notification-icon <?php echo getNotificationIconClass($notification['type']); ?>">
                                                    <i class="bi <?php echo getNotificationIcon($notification['type']); ?>"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-medium"><?php echo htmlspecialchars($notification['title']); ?></div>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($notification['message']); ?></div>
                                                    <div class="notification-time">
                                                        <i class="bi bi-clock me-1"></i>
                                                        <?php echo timeAgo($notification['created_at']); ?>
                                                    </div>
                                                </div>
                                                <?php if ($notification['is_read'] == 0): ?>
                                                    <span class="badge bg-primary ms-2">ใหม่</span>
                                                <?php endif; ?>
                                            </div>
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider m-0"></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="px-3 py-2 text-center text-muted">
                                    <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
                                    ไม่มีการแจ้งเตือน
                                </li>
                            <?php endif; ?>
                            
                            <li class="dropdown-footer text-center p-2">
                                <a href="notifications.php" class="btn btn-outline-primary btn-sm">ดูการแจ้งเตือนทั้งหมด</a>
                            </li>
                        </ul>
                    </li>
                    
                    <!-- ผู้ใช้ -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                <i class="bi bi-person text-white"></i>
                            </div>
                            <?php echo $_SESSION['username']; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>โปรไฟล์</a></li>
                            <li><a class="dropdown-item" href="setting.php"><i class="bi bi-gear me-2"></i>ตั้งค่า</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div style="height: 76px;"></div> <!-- Spacer for fixed navbar -->

    <?php
    // ฟังก์ชัน helper สำหรับการแจ้งเตือน
    function getNotificationIcon($type) {
        switch ($type) {
            case 'success': return 'bi-check-circle';
            case 'warning': return 'bi-exclamation-triangle';
            case 'danger': return 'bi-x-circle';
            case 'info': 
            default: return 'bi-info-circle';
        }
    }
    
    function getNotificationIconClass($type) {
        switch ($type) {
            case 'success': return 'icon-success';
            case 'warning': return 'icon-warning';
            case 'danger': return 'icon-danger';
            case 'info': 
            default: return 'icon-info';
        }
    }
    
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return 'เมื่อสักครู่';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' นาทีที่แล้ว';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
        } elseif ($diff < 2592000) {
            return floor($diff / 86400) . ' วันที่แล้ว';
        } else {
            return date('d/m/Y', $time);
        }
    }
    ?>