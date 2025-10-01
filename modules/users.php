<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ตรวจสอบสิทธิ์การเข้าถึง
if (!hasRole('admin')) {
    $_SESSION['error_message'] = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
    header('Location: dashboard.php');
    exit;
}

// ตรวจสอบการกระทำ
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// ฟังก์ชันจัดการผู้ใช้
function getUsers($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getUser($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function addUser($pdo, $data) {
    // ตรวจสอบว่ามี username นี้อยู่แล้วหรือไม่
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
    $stmt->execute([$data['username']]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        return false; // มี username นี้อยู่แล้ว
    }
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    return $stmt->execute([
        $data['username'],
        password_hash($data['password'], PASSWORD_DEFAULT),
        $data['full_name'],
        $data['email'],
        $data['role'],
        $data['status']
    ]);
}

function updateUser($pdo, $id, $data) {
    // ตรวจสอบว่ามี username นี้อยู่แล้วหรือไม่ (ยกเว้นผู้ใช้ปัจจุบัน)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$data['username'], $id]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        return false; // มี username นี้อยู่แล้ว
    }
    
    // หากมีการกรอกรหัสผ่านใหม่
    if (!empty($data['password'])) {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
        return $stmt->execute([
            $data['username'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['full_name'],
            $data['email'],
            $data['role'],
            $data['status'],
            $id
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
        return $stmt->execute([
            $data['username'],
            $data['full_name'],
            $data['email'],
            $data['role'],
            $data['status'],
            $id
        ]);
    }
}

function deleteUser($pdo, $id) {
    // ตรวจสอบว่าไม่สามารถลบผู้ใช้ตัวเองได้
    if ($id == $_SESSION['user_id']) {
        return false;
    }
    
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    return $stmt->execute([$id]);
}

// จัดการฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'staff';
    $status = $_POST['status'] ?? 'active';
    
    $form_action = $_POST['action'] ?? 'add';
    $form_id = $_POST['id'] ?? 0;
    
    if (!empty($username) && !empty($full_name)) {
        $user_data = [
            'username' => $username,
            'password' => $password,
            'full_name' => $full_name,
            'email' => $email,
            'role' => $role,
            'status' => $status
        ];
        
        if ($form_action === 'add') {
            if (empty($password)) {
                $_SESSION['error_message'] = 'กรุณากรอกรหัสผ่าน';
                header('Location: users.php');
                exit;
            }
            
            if (addUser($pdo, $user_data)) {
                $_SESSION['success_message'] = 'เพิ่มผู้ใช้สำเร็จ';
            } else {
                $_SESSION['error_message'] = 'ไม่สามารถเพิ่มผู้ใช้ได้ (อาจมีชื่อผู้ใช้นี้อยู่แล้ว)';
            }
        } elseif ($form_action === 'edit' && $form_id > 0) {
            if (updateUser($pdo, $form_id, $user_data)) {
                $_SESSION['success_message'] = 'แก้ไขผู้ใช้สำเร็จ';
            } else {
                $_SESSION['error_message'] = 'ไม่สามารถแก้ไขผู้ใช้ได้ (อาจมีชื่อผู้ใช้นี้อยู่แล้ว)';
            }
        }
        header('Location: users.php');
        exit;
    } else {
        $_SESSION['error_message'] = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        header('Location: users.php');
        exit;
    }
}

// ลบผู้ใช้
if ($action === 'delete' && $id > 0) {
    if (deleteUser($pdo, $id)) {
        $_SESSION['success_message'] = 'ลบผู้ใช้สำเร็จ';
    } else {
        $_SESSION['error_message'] = 'ไม่สามารถลบผู้ใช้ได้ (ไม่สามารถลบบัญชีตัวเองได้)';
    }
    header('Location: users.php');
    exit;
}

// ข้อมูลสำหรับหน้าแก้ไข
$user = [];
if ($action === 'edit' && $id > 0) {
    $user = getUser($pdo, $id);
    if (!$user) {
        $_SESSION['error_message'] = 'ไม่พบผู้ใช้นี้';
        header('Location: users.php');
        exit;
    }
}

$users = getUsers($pdo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้ - ระบบสต็อกสินค้า</title>
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
        
        .user-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }
        
        .user-card:hover {
            transform: translateY(-5px);
        }
        
        .user-icon {
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
        
        #usersTable th {
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
        
        .status-active {
            background-color: #198754;
        }
        
        .status-inactive {
            background-color: #6c757d;
        }
        
        .role-admin {
            background-color: #dc3545;
        }
        
        .role-staff {
            background-color: #0d6efd;
        }
        
        .role-manager {
            background-color: #fd7e14;
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
                                <h1 class="h2 mb-0"><i class="bi bi-people me-2"></i> จัดการผู้ใช้</h1>
                                <p class="mb-0">เพิ่ม แก้ไข และลบผู้ใช้ระบบ</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#userModal" data-action="add">
                                    <i class="bi bi-plus-circle me-1"></i> เพิ่มผู้ใช้
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
                            <div class="card user-card text-center p-4">
                                <div class="user-icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <h3><?php echo count($users); ?></h3>
                                <p class="text-muted mb-0">ผู้ใช้ทั้งหมด</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card user-card text-center p-4">
                                <div class="user-icon text-success">
                                    <i class="bi bi-person-check"></i>
                                </div>
                                <h3><?php 
                                    $active_count = array_filter($users, function($u) { 
                                        return $u['status'] === 'active'; 
                                    });
                                    echo count($active_count);
                                ?></h3>
                                <p class="text-muted mb-0">ผู้ใช้ที่ใช้งานอยู่</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card user-card text-center p-4">
                                <div class="user-icon text-primary">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                                <h3><?php 
                                    $admin_count = array_filter($users, function($u) { 
                                        return $u['role'] === 'admin'; 
                                    });
                                    echo count($admin_count);
                                ?></h3>
                                <p class="text-muted mb-0">ผู้ดูแลระบบ</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card user-card text-center p-4">
                                <div class="user-icon text-warning">
                                    <i class="bi bi-person"></i>
                                </div>
                                <h3><?php 
                                    $user_count = array_filter($users, function($u) { 
                                        return $u['role'] === 'staff'; 
                                    });
                                    echo count($user_count);
                                ?></h3>
                                <p class="text-muted mb-0">ผู้ใช้ทั่วไป</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ตารางผู้ใช้ -->
                    <div class="card">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">รายการผู้ใช้</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="usersTable" class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="15%">ชื่อผู้ใช้</th>
                                            <th width="20%">ชื่อ-สกุล</th>
                                            <th width="20%">อีเมล</th>
                                            <th width="15%">บทบาท</th>
                                            <th width="15%">สถานะ</th>
                                            <th width="10%">วันที่สร้าง</th>
                                            <th width="15%">การดำเนินการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($users) > 0): ?>
                                            <?php foreach ($users as $index => $usr): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                                <i class="bi bi-person text-white"></i>
                                                            </div>
                                                            <strong><?php echo htmlspecialchars($usr['username']); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($usr['full_name']); ?></td>
                                                    <td><?php echo !empty($usr['email']) ? htmlspecialchars($usr['email']) : '<span class="text-muted">ไม่มีอีเมล</span>'; ?></td>
                                                    <td>
                                                        <span class="badge badge-status role-<?php echo $usr['role']; ?>">
                                                            <?php 
                                                            $role_labels = [
                                                                'admin' => 'ผู้ดูแลระบบ',
                                                                'manager' => 'ผู้จัดการ',
                                                                'staff' => 'ผู้ใช้ทั่วไป'
                                                            ];
                                                            echo $role_labels[$usr['role']] ?? $usr['role'];
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-status status-<?php echo $usr['status']; ?>">
                                                            <?php 
                                                            $status_labels = [
                                                                'active' => 'ใช้งาน',
                                                                'inactive' => 'ไม่ใช้งาน'
                                                            ];
                                                            echo $status_labels[$usr['status']] ?? $usr['status'];
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($usr['created_at'])); ?></td>
                                                    <td class="action-buttons">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#userModal"
                                                                data-action="edit"
                                                                data-id="<?php echo $usr['id']; ?>"
                                                                data-username="<?php echo htmlspecialchars($usr['username']); ?>"
                                                                data-full_name="<?php echo htmlspecialchars($usr['full_name']); ?>"
                                                                data-email="<?php echo htmlspecialchars($usr['email']); ?>"
                                                                data-role="<?php echo $usr['role']; ?>"
                                                                data-status="<?php echo $usr['status']; ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <?php if ($usr['id'] != $_SESSION['user_id']): ?>
                                                            <button class="btn btn-sm btn-outline-danger btn-delete" 
                                                                    data-id="<?php echo $usr['id']; ?>"
                                                                    data-username="<?php echo htmlspecialchars($usr['username']); ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-sm btn-outline-secondary" disabled>
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <i class="bi bi-people display-4 d-block text-muted mb-2"></i>
                                                    <p class="text-muted">ยังไม่มีผู้ใช้</p>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" data-action="add">
                                                        <i class="bi bi-plus-circle me-1"></i> เพิ่มผู้ใช้แรก
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

    <!-- Modal สำหรับเพิ่ม/แก้ไขผู้ใช้ -->
    <div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">เพิ่มผู้ใช้ใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="users.php">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label" id="passwordLabel">รหัสผ่าน <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password">
                            <div class="form-text" id="passwordHelp">หากไม่ต้องการเปลี่ยนรหัสผ่าน ให้เว้นว่างไว้</div>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">ชื่อ-สกุล <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">อีเมล</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="role" class="form-label">บทบาท</label>
                                    <select class="form-select" id="role" name="role">
                                        <option value="staff">ผู้ใช้ทั่วไป</option>
                                        <option value="manager">ผู้จัดการ</option>
                                        <option value="admin">ผู้ดูแลระบบ</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">สถานะ</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active">ใช้งาน</option>
                                        <option value="inactive">ไม่ใช้งาน</option>
                                    </select>
                                </div>
                            </div>
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
            $('#usersTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                },
                responsive: true,
                columnDefs: [
                    { orderable: false, targets: [7] } // ปิดการเรียงลำดับคอลัมน์การดำเนินการ
                ]
            });
            
            // จัดการ Modal สำหรับแก้ไข
            const userModal = document.getElementById('userModal');
            const formAction = document.getElementById('formAction');
            const formId = document.getElementById('formId');
            const username = document.getElementById('username');
            const password = document.getElementById('password');
            const passwordLabel = document.getElementById('passwordLabel');
            const passwordHelp = document.getElementById('passwordHelp');
            const fullName = document.getElementById('full_name');
            const email = document.getElementById('email');
            const role = document.getElementById('role');
            const status = document.getElementById('status');
            const modalTitle = document.getElementById('userModalLabel');
            
            userModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const action = button.getAttribute('data-action');
                
                if (action === 'edit') {
                    // โหมดแก้ไข
                    modalTitle.textContent = 'แก้ไขผู้ใช้';
                    formAction.value = 'edit';
                    formId.value = button.getAttribute('data-id');
                    username.value = button.getAttribute('data-username') || '';
                    password.required = false;
                    passwordLabel.innerHTML = 'รหัสผ่าน <span class="text-muted">(หากไม่ต้องการเปลี่ยนรหัสผ่าน ให้เว้นว่างไว้)</span>';
                    passwordHelp.style.display = 'block';
                    fullName.value = button.getAttribute('data-full_name') || '';
                    email.value = button.getAttribute('data-email') || '';
                    role.value = button.getAttribute('data-role') || 'staff';
                    status.value = button.getAttribute('data-status') || 'active';
                } else {
                    // โหมดเพิ่ม
                    modalTitle.textContent = 'เพิ่มผู้ใช้ใหม่';
                    formAction.value = 'add';
                    formId.value = '';
                    username.value = '';
                    password.required = true;
                    passwordLabel.innerHTML = 'รหัสผ่าน <span class="text-danger">*</span>';
                    passwordHelp.style.display = 'none';
                    password.value = '';
                    fullName.value = '';
                    email.value = '';
                    role.value = 'staff';
                    status.value = 'active';
                }
            });
            
            // รีเซ็ต Modal เมื่อปิด
            userModal.addEventListener('hidden.bs.modal', function() {
                modalTitle.textContent = 'เพิ่มผู้ใช้ใหม่';
                formAction.value = 'add';
                formId.value = '';
                username.value = '';
                password.required = true;
                passwordLabel.innerHTML = 'รหัสผ่าน <span class="text-danger">*</span>';
                passwordHelp.style.display = 'none';
                password.value = '';
                fullName.value = '';
                email.value = '';
                role.value = 'staff';
                status.value = 'active';
            });
            
            // การจัดการการลบด้วย SweetAlert2
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const userId = this.getAttribute('data-id');
                    const username = this.getAttribute('data-username');
                    
                    Swal.fire({
                        title: 'ยืนยันการลบ',
                        html: `<div class="text-center">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">คุณแน่ใจว่าต้องการลบผู้ใช้นี้?</h4>
                            <p class="text-danger">"${username}"</p>
                            <p class="text-muted">การลบผู้ใช้จะไม่สามารถกู้คืนได้</p>
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
                            window.location.href = `users.php?action=delete&id=${userId}`;
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>