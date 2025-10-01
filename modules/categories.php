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

// ฟังก์ชันจัดการหมวดหมู่
function getCategories($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM categories ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function getCategory($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function addCategory($pdo, $data) {
    $stmt = $pdo->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
    return $stmt->execute([$data['category_name'], $data['description']]);
}

function updateCategory($pdo, $id, $data) {
    $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, description = ? WHERE id = ?");
    return $stmt->execute([$data['category_name'], $data['description'], $id]);
}

function deleteCategory($pdo, $id) {
    // ตรวจสอบว่ามีสินค้าใช้หมวดหมู่นี้อยู่หรือไม่
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        return false; // ไม่สามารถลบได้因为有商品使用此分类
    }
    
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    return $stmt->execute([$id]);
}

// จัดการฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $form_action = $_POST['action'] ?? 'add';
    $form_id = $_POST['id'] ?? 0;
    
    if (!empty($category_name)) {
        if ($form_action === 'add') {
            if (addCategory($pdo, ['category_name' => $category_name, 'description' => $description])) {
                $_SESSION['success_message'] = 'เพิ่มหมวดหมู่สำเร็จ';
            } else {
                $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการเพิ่มหมวดหมู่';
            }
        } elseif ($form_action === 'edit' && $form_id > 0) {
            if (updateCategory($pdo, $form_id, ['category_name' => $category_name, 'description' => $description])) {
                $_SESSION['success_message'] = 'แก้ไขหมวดหมู่สำเร็จ';
            } else {
                $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการแก้ไขหมวดหมู่';
            }
        }
        header('Location: categories.php');
        exit;
    } else {
        $_SESSION['error_message'] = 'กรุณากรอกชื่อหมวดหมู่';
        header('Location: categories.php');
        exit;
    }
}

// ลบหมวดหมู่
if ($action === 'delete' && $id > 0) {
    if (deleteCategory($pdo, $id)) {
        $_SESSION['success_message'] = 'ลบหมวดหมู่สำเร็จ';
    } else {
        $_SESSION['error_message'] = 'ไม่สามารถลบหมวดหมู่ได้ เนื่องจากมีสินค้าใช้งานอยู่';
    }
    header('Location: categories.php');
    exit;
}

// ข้อมูลสำหรับหน้าแก้ไข
$category = [];
if ($action === 'edit' && $id > 0) {
    $category = getCategory($pdo, $id);
    if (!$category) {
        $_SESSION['error_message'] = 'ไม่พบหมวดหมู่นี้';
        header('Location: categories.php');
        exit;
    }
}

$categories = getCategories($pdo);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการหมวดหมู่ - ระบบสต็อกสินค้า</title>
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
        
        .category-card {
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
        }
        
        .category-icon {
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
        
        #categoriesTable th {
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
                                <h1 class="h2 mb-0"><i class="bi bi-tags me-2"></i> จัดการหมวดหมู่</h1>
                                <p class="mb-0">เพิ่ม แก้ไข และลบหมวดหมู่สินค้า</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#categoryModal" data-action="add">
                                    <i class="bi bi-plus-circle me-1"></i> เพิ่มหมวดหมู่
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
                        <div class="col-md-4">
                            <div class="card category-card text-center p-4">
                                <div class="category-icon">
                                    <i class="bi bi-tags"></i>
                                </div>
                                <h3><?php echo count($categories); ?></h3>
                                <p class="text-muted mb-0">หมวดหมู่ทั้งหมด</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card category-card text-center p-4">
                                <div class="category-icon">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <h3>150</h3>
                                <p class="text-muted mb-0">สินค้าในระบบ</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card category-card text-center p-4">
                                <div class="category-icon">
                                    <i class="bi bi-collection"></i>
                                </div>
                                <h3>12</h3>
                                <p class="text-muted mb-0">หมวดหมู่ย่อย</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ตารางหมวดหมู่ -->
                    <div class="card">
                        <div class="card-header bg-white">
                            <h5 class="card-title mb-0">รายการหมวดหมู่</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="categoriesTable" class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">#</th>
                                            <th width="25%">ชื่อหมวดหมู่</th>
                                            <th width="35%">คำอธิบาย</th>
                                            <th width="20%">วันที่สร้าง</th>
                                            <th width="15%">การดำเนินการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($categories) > 0): ?>
                                            <?php foreach ($categories as $index => $cat): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                                                                <i class="bi bi-tag text-white"></i>
                                                            </div>
                                                            <strong><?php echo htmlspecialchars($cat['category_name']); ?></strong>
                                                        </div>
                                                    </td>
                                                    <td><?php echo !empty($cat['description']) ? htmlspecialchars($cat['description']) : '<span class="text-muted">ไม่มีคำอธิบาย</span>'; ?></td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($cat['created_at'])); ?></td>
                                                    <td class="action-buttons">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#categoryModal"
                                                                data-action="edit"
                                                                data-id="<?php echo $cat['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($cat['category_name']); ?>"
                                                                data-description="<?php echo htmlspecialchars($cat['description']); ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger btn-delete" 
                                                                data-id="<?php echo $cat['id']; ?>"
                                                                data-name="<?php echo htmlspecialchars($cat['category_name']); ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4">
                                                    <i class="bi bi-inbox display-4 d-block text-muted mb-2"></i>
                                                    <p class="text-muted">ยังไม่มีหมวดหมู่</p>
                                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" data-action="add">
                                                        <i class="bi bi-plus-circle me-1"></i> เพิ่มหมวดหมู่แรก
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

    <!-- Modal สำหรับเพิ่ม/แก้ไขหมวดหมู่ -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalLabel">เพิ่มหมวดหมู่ใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="categories.php">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category_name" class="form-label">ชื่อหมวดหมู่ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="category_name" name="category_name" required>
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
            $('#categoriesTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                },
                responsive: true,
                columnDefs: [
                    { orderable: false, targets: [4] } // ปิดการเรียงลำดับคอลัมน์การดำเนินการ
                ]
            });
            
            // จัดการ Modal สำหรับแก้ไข
            const categoryModal = document.getElementById('categoryModal');
            const formAction = document.getElementById('formAction');
            const formId = document.getElementById('formId');
            const categoryName = document.getElementById('category_name');
            const description = document.getElementById('description');
            const modalTitle = document.getElementById('categoryModalLabel');
            
            categoryModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const action = button.getAttribute('data-action');
                
                if (action === 'edit') {
                    // โหมดแก้ไข
                    modalTitle.textContent = 'แก้ไขหมวดหมู่';
                    formAction.value = 'edit';
                    formId.value = button.getAttribute('data-id');
                    categoryName.value = button.getAttribute('data-name') || '';
                    description.value = button.getAttribute('data-description') || '';
                } else {
                    // โหมดเพิ่ม
                    modalTitle.textContent = 'เพิ่มหมวดหมู่ใหม่';
                    formAction.value = 'add';
                    formId.value = '';
                    categoryName.value = '';
                    description.value = '';
                }
            });
            
            // รีเซ็ต Modal เมื่อปิด
            categoryModal.addEventListener('hidden.bs.modal', function() {
                modalTitle.textContent = 'เพิ่มหมวดหมู่ใหม่';
                formAction.value = 'add';
                formId.value = '';
                categoryName.value = '';
                description.value = '';
            });
            
            // การจัดการการลบด้วย SweetAlert2
            document.querySelectorAll('.btn-delete').forEach(button => {
                button.addEventListener('click', function() {
                    const categoryId = this.getAttribute('data-id');
                    const categoryName = this.getAttribute('data-name');
                    
                    Swal.fire({
                        title: 'ยืนยันการลบ',
                        html: `<div class="text-center">
                            <i class="bi bi-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                            <h4 class="mt-3">คุณแน่ใจว่าต้องการลบหมวดหมู่นี้?</h4>
                            <p class="text-danger">"${categoryName}"</p>
                            <p class="text-muted">การลบจะส่งผลต่อสินค้าที่เกี่ยวข้องกับหมวดหมู่นี้</p>
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
                            // ไปยังหน้ารายการหมวดหมู่พร้อมพารามิเตอร์การลบ
                            window.location.href = `categories.php?action=delete&id=${categoryId}`;
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>