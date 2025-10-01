<?php
// ตรวจสอบว่ามีการล็อกอินแล้ว
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
?>
<div class="sidebar col-md-3 col-lg-2 p-0 bg-dark text-white">
    <div class="d-flex flex-column flex-shrink-0 p-3 h-100">
        <a href="dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none">
            <i class="bi bi-grid-1x2 fs-4 me-2"></i>
            <span class="fs-5 fw-bold">เมนูหลัก</span>
        </a>
        <hr>
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="bi bi-speedometer2 me-2"></i>
                    แดชบอร์ด
                </a>
            </li>
            <li>
                <a href="products.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'products.php' ? 'active' : ''; ?>">
                    <i class="bi bi-box-seam me-2"></i>
                    จัดการสินค้า
                </a>
            </li>
            <li>
                <a href="categories.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>">
                    <i class="bi bi-tags me-2"></i>
                    ประเภทสินค้า
                </a>
            </li>
            <li>
                <a href="suppliers.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'suppliers.php' ? 'active' : ''; ?>">
                    <i class="bi bi-truck me-2"></i>
                    ผู้จัดหา
                </a>
            </li>
            <li>
                <a href="orders.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : ''; ?>">
                    <i class="bi bi-cart me-2"></i>
                    การสั่งซื้อ
                </a>
            </li>
            <li>
                <a href="reports.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
                    <i class="bi bi-graph-up me-2"></i>
                    รายงาน
                </a>
            </li>
        </ul>
        <hr>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="dropdownUser2" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                    <i class="bi bi-person text-white"></i>
                </div>
                <strong><?php echo $_SESSION['username']; ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser2">
                <li><a class="dropdown-item" href="profile.php">โปรไฟล์</a></li>
                <li><a class="dropdown-item" href="settings.php">ตั้งค่า</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php">ออกจากระบบ</a></li>
            </ul>
        </div>
    </div>
</div>

<style>
    .sidebar {
        min-height: calc(100vh - 76px);
        box-shadow: inset -1px 0 0 rgba(0, 0, 0, 0.1);
    }
    
    .nav-pills .nav-link.active, .nav-pills .show > .nav-link {
        background-color: #0d6efd;
        color: white;
    }
    
    .nav-link {
        border-radius: 0.375rem;
        margin-bottom: 0.25rem;
        transition: all 0.3s;
    }
    
    .nav-link:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    
    .dropdown-menu-dark {
        background-color: #343a40;
        border: none;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }
    
    .dropdown-item {
        border-radius: 0.375rem;
        margin: 0.1rem;
    }
    
    .dropdown-item:hover {
        background-color: #495057;
    }
    
    @media (max-width: 767.98px) {
        .sidebar {
            position: fixed;
            top: 76px;
            bottom: 0;
            z-index: 1000;
            overflow-y: auto;
        }
    }
</style>