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

// ฟังก์ชันจัดการลูกค้า
function getCustomers($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM customers ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getCustomer($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function addCustomer($pdo, $data) {
    // ตรวจสอบว่าเบอร์โทรซ้ำหรือไม่
    if (!empty($data['phone'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM customers WHERE phone = ?");
        $stmt->execute([$data['phone']]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return false; // มีเบอร์โทรนี้อยู่แล้ว
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
    return $stmt->execute([
        $data['name'],
        $data['phone'],
        $data['email'],
        $data['address']
    ]);
}

function updateCustomer($pdo, $id, $data) {
    // ตรวจสอบว่าเบอร์โทรซ้ำหรือไม่ (ยกเว้นลูกค้าปัจจุบัน)
    if (!empty($data['phone'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM customers WHERE phone = ? AND id != ?");
        $stmt->execute([$data['phone'], $id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return false; // มีเบอร์โทรนี้อยู่แล้ว
        }
    }
    
    $stmt = $pdo->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
    return $stmt->execute([
        $data['name'],
        $data['phone'],
        $data['email'],
        $data['address'],
        $id
    ]);
}

function deleteCustomer($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
    return $stmt->execute([$id]);
}

// จัดการฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    $form_action = $_POST['action'] ?? 'add';
    $form_id = $_POST['id'] ?? 0;
    
    if (!empty($name)) {
        $customer_data = [
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'address' => $address
        ];
        
        if ($form_action === 'add') {
            if (addCustomer($pdo, $customer_data)) {
                $_SESSION['success_message'] = 'เพิ่มลูกค้าสำเร็จ';
            } else {
                $_SESSION['error_message'] = 'ไม่สามารถเพิ่มลูกค้าได้ (อาจมีเบอร์โทรศัพท์นี้อยู่แล้ว)';
            }
        } elseif ($form_action === 'edit' && $form_id > 0) {
            if (updateCustomer($pdo, $form_id, $customer_data)) {
                $_SESSION['success_message'] = 'แก้ไขลูกค้าสำเร็จ';
            } else {
                $_SESSION['error_message'] = 'ไม่สามารถแก้ไขลูกค้าได้ (อาจมีเบอร์โทรศัพท์นี้อยู่แล้ว)';
            }
        }
        header('Location: customers.php');
        exit;
    } else {
        $_SESSION['error_message'] = 'กรุณากรอกชื่อลูกค้า';
        header('Location: customers.php');
        exit;
    }
}

// ลบลุกค้า
if ($action === 'delete' && $id > 0) {
    if (deleteCustomer($pdo, $id)) {
        $_SESSION['success_message'] = 'ลบลุกค้าสำเร็จ';
    } else {
        $_SESSION['error_message'] = 'ไม่สามารถลบลุกค้าได้';
    }
    header('Location: customers.php');
    exit;
}

// ข้อมูลสำหรับหน้าแก้ไข
$customer = [];
if ($action === 'edit' && $id > 0) {
    $customer = getCustomer($pdo, $id);
    if (!$customer) {
        $_SESSION['error_message'] = 'ไม่พบลูกค้านี้';
        header('Location: customers.php');
        exit;
    }
}

$customers = getCustomers($pdo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการลูกค้า - ระบบสต็อกสินค้า</title>
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
        
        .customer-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }
        
        .customer-card:hover {
            transform: translateY(-5px);
        }
        
        .customer-icon {
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
        
        #customersTable th {
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
        
        .contact-info {
            font-size: 0.9rem;
        }
        
        .address-info {
            font-size: 0.85rem;
            color: #6c757d;
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
                                <h1 class="h2 mb-0"><i class="bi bi-people me-2"></i> จัดการลูกค้า</h1>
                                <p class="mb-0">เพิ่ม แก้ไข และลบข้อมูลลูกค้า</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#customerModal" data-action="add">
                                    <i class="bi bi-plus-circle me-1"></i> เพิ่มลูกค้า
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
                            <div class="card customer-card text-center p-4">
                                <div class="customer-icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <h3><?php echo count($customers); ?></h3>
                                <p class="text-muted mb-0">ลูกค้าทั้งหมด</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card customer-card text-center p-4">
                                <div class="customer-icon text-success">
                                    <i class="bi bi-telephone"></i>
                                </div>
                                <h3><?php 
                                    $with_phone = array_filter($customers, function($c) { 
                                        return !empty($c['phone']); 
                                    });
                                    echo count($with_phone);
                                ?></h3>
                                <p class="text-muted mb-0">มีเบอร์โทรศัพท์</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card customer-card text-center p-4">
                                <div class="customer-icon text-primary">
                                    <i class="bi bi-envelope"></i>
                                </div>
                                <h3><?php 
                                    $with_email = array_filter($customers, function($c) { 
                                        return !empty($c['email']); 
                                    });
                                    echo count($with_email);
                                ?></h3>
                                <p class="text-muted mb-0">มีอีเมล</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card customer-card text-center p-4">
                                <div class="customer-icon text-warning">
                                    <i class="bi bi-geo-alt"></i>
                                </div>
                                <h3><?php 
                                    $with_address = array_filter($customers, function($c) { 
                                        return !empty($c['address']); 
                                    });
                                    echo count($with_address);
                                ?></h3>
                                <p class="text-muted mb-0">มีที่อยู่</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ตารางลูกค้า -->
                    <div class="card">
                        <div class="card-header bg-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">รายการลูกค้า</h5>
                                <div class="d-flex">
                                    <input type="text" id="searchInput" class="form-control form-control-sm me-2" placeholder="ค้นหาลูกค้า..." style="width: 200px;">
                                    <button class="btn btn-sm btn-outline-secondary" id="btnClearSearch">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="customersTable" class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="20%">ชื่อลูกค้า</th>
                                            <th width="15%">โทรศัพท์</th>
                                            <th width="20%">อีเมล</th>
                                            <th width="25%">ที่อยู่</th>
                                            <th width="15%">วันที่เพิ่ม</th>
                                            <th width="15%">การดำเนินการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($customers) > 0): ?>
                                            <?php foreach ($customers as $index => $cust): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                                <i class="bi bi-person text-white"></i>
                                                            </div>
                                                            <strong><?php echo htmlspecialchars($cust['name']); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($cust['phone'])): ?>
                                                            <span class="contact-info">
                                                                <i class="bi bi-telephone me-1"></i>
                                                                <?php echo htmlspecialchars($cust['phone']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($cust['email'])): ?>
                                                            <span class="contact-info">
                                                                <i class="bi bi-envelope me-1"></i>
                                                                <?php echo htmlspecialchars($cust['email']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($cust['address'])): ?>
                                                            <span class="address-info" title="<?php echo htmlspecialchars($cust['address']); ?>">
                                                                <?php 
                                                                if (strlen($cust['address']) > 50) {
                                                                    echo htmlspecialchars(substr($cust['address'], 0, 50)) . '...';
                                                                } else {
                                                                    echo htmlspecialchars($cust['address']);
                                                                }
                                                                ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($cust['created_at'])); ?></td>
                                                    <td class="action-buttons">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#customerModal"
                                                                data-action="edit"
                                                                data-id="<?php echo $cust['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($cust['name']); ?>"
                                                                data-phone="<?php echo htmlspecialchars($cust['phone']); ?>"
                                                                data-email="<?php echo htmlspecialchars($cust['email']); ?>"
                                                                data-address="<?php echo htmlspecialchars($cust['address']); ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger btn-delete" 
                                                                data-id="<?php echo $cust['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($cust['name']); ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="text-center py-4">
                                                    <i class="bi bi-people display-4 d-block text-muted mb-2"></i>
                                                    <p class="text-muted">ยังไม่มีข้อมูลลูกค้า</p>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal" data-action="add">
                                                        <i class="bi bi-plus-circle me-1"></i> เพิ่มลูกค้าแรก
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

    <!-- Modal สำหรับเพิ่ม/แก้ไขลูกค้า -->
    <div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalLabel">เพิ่มลูกค้าใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="customers.php">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">ชื่อลูกค้า <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">โทรศัพท์</label>
                            <input type="tel" class="form-control" id="phone" name="phone">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">อีเมล</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">ที่อยู่</label>
                            <textarea class="form-control" id="address" name="address" rows="3"></textarea>
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
            const table = $('#customersTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                },
                responsive: true,
                columnDefs: [
                    { orderable: false, targets: [6] } // ปิดการเรียงลำดับคอลัมน์การดำเนินการ
                ]
            });
            
            // การค้นหา
            $('#searchInput').on('keyup', function() {
                table.search(this.value).draw();
            });
            
            $('#btnClearSearch').on('click', function() {
                $('#searchInput').val('');
                table.search('').draw();
            });
            
            // จัดการ Modal สำหรับแก้ไข
            const customerModal = document.getElementById('customerModal');
            const formAction = document.getElementById('formAction');
            const formId = document.getElementById('formId');
            const name = document.getElementById('name');
            const phone = document.getElementById('phone');
            const email = document.getElementById('email');
            const address = document.getElementById('address');
            const modalTitle = document.getElementById('customerModalLabel');
            
            customerModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const action = button.getAttribute('data-action');
                
                if (action === 'edit') {
                    // โหมดแก้ไข
                    modalTitle.textContent = 'แก้ไขลูกค้า';
                    formAction.value = 'edit';
                    formId.value = button.getAttribute('data-id');
                    name.value = button.getAttribute('data-name') || '';
                    phone.value = button.getAttribute('data-phone') || '';
                    email.value = button.getAttribute('data-email') || '';
                    address.value = button.getAttribute('data-address') || '';
                } else {
                    // โหมดเพิ่ม
                    modalTitle.textContent = 'เพิ่มลูกค้าใหม่';
                    formAction.value = 'add';
                    formId.value = '';
                    name.value = '';
                    phone.value = '';
                    email.value = '';
                    address.value = '';
                }
            });
            
            // รีเซ็ต Modal เมื่อปิด
            customerModal.addEventListener('hidden.bs.modal', function() {
                modalTitle.textContent = 'เพิ่มลูกค้าใหม่';
                formAction.value = 'add';
                formId.value = '';
                name.value = '';
                phone.value = '';
                email.value = '';
                address.value = '';
            });
            
            // การจัดการการลบด้วย SweetAlert2
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const customerId = this.getAttribute('data-id');
                    const customerName = this.getAttribute('data-name');
                    
                    Swal.fire({
                        title: 'ยืนยันการลบ',
                        html: `<div class="text-center">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">คุณแน่ใจว่าต้องการลบลูกค้านี้?</h4>
                            <p class="text-danger">"${customerName}"</p>
                            <p class="text-muted">การลบลูกค้าจะไม่สามารถกู้คืนได้</p>
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
                            window.location.href = `customers.php?action=delete&id=${customerId}`;
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>