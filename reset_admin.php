<?php
// ไฟล์ reset_admin.php สำหรับรีเซ็ตรหัสผ่านผู้ดูแลระบบ

// ตั้งค่า error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ข้อมูลการเชื่อมต่อฐานข้อมูล
define('DB_HOST', 'localhost');
define('DB_NAME', 'clothing_stock');
define('DB_USER', 'root');
define('DB_PASS', '');

// เชื่อมต่อฐานข้อมูล
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}

// ฟังก์ชันสร้างรหัสผ่านแบบสุ่ม
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}

// ฟังก์ชันรีเซ็ตรหัสผ่าน
function resetAdminPassword($pdo) {
    // สร้างรหัสผ่านใหม่
    $newPassword = generateRandomPassword();
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // อัปเดตรหัสผ่านในฐานข้อมูล
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $stmt->execute([$hashedPassword]);
    
    return $newPassword;
}

// ตรวจสอบว่ามีการร้องขอรีเซ็ตหรือไม่
$message = '';
$newPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    // ตรวจสอบรหัสยืนยัน (เพื่อป้องกันการรีเซ็ตโดยไม่ได้ตั้งใจ)
    $confirmation = $_POST['confirmation'] ?? '';
    
    if ($confirmation === 'RESET') {
        $newPassword = resetAdminPassword($pdo);
        $message = "รีเซ็ตรหัสผ่านสำเร็จ! รหัสผ่านใหม่คือ: <strong>{$newPassword}</strong>";
    } else {
        $message = "การยืนยันไม่ถูกต้อง กรุณาพิมพ์ 'RESET' ในช่องยืนยัน";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รีเซ็ตรหัสผ่านผู้ดูแลระบบ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Phetsarath+OT:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Phetsarath OT', sans-serif;
            background-color: #f8f9fa;
            padding-top: 50px;
        }
        .reset-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .alert-custom {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .btn-reset {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        .btn-reset:hover {
            background-color: #c82333;
            border-color: #bd2130;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="reset-container">
            <h2 class="text-center mb-4">รีเซ็ตรหัสผ่านผู้ดูแลระบบ</h2>
            
            <?php if (!empty($message)): ?>
                <div class="alert <?php echo strpos($message, 'สำเร็จ') !== false ? 'alert-success' : 'alert-custom'; ?>">
                    <?php echo $message; ?>
                </div>
                
                <?php if (!empty($newPassword)): ?>
                <div class="alert alert-info">
                    <strong>คำแนะนำ:</strong> 
                    <ul>
                        <li>คัดลอกรหัสผ่านใหม่ไปเก็บไว้ในที่ปลอดภัย</li>
                        <li>หลังจากล็อกอินแล้ว ควรเปลี่ยนรหัสผ่านใหม่ทันที</li>
                        <li>ลบไฟล์นี้หลังจากใช้งานเสร็จแล้วเพื่อความปลอดภัย</li>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="alert alert-warning">
                <strong>คำเตือน:</strong> การดำเนินการนี้จะเปลี่ยนรหัสผ่านของผู้ใช้ 'admin' เท่านั้น 
                และจะสร้างรหัสผ่านแบบสุ่มขึ้นมาใหม่ กรุณาพิมพ์คำว่า <strong>RESET</strong> ในช่องด้านล่างเพื่อยืนยันการดำเนินการ
            </div>
            
            <form method="POST">
                <div class="mb-3">
                    <label for="confirmation" class="form-label">พิมพ์ 'RESET' เพื่อยืนยัน</label>
                    <input type="text" class="form-control" id="confirmation" name="confirmation" required 
                           placeholder="RESET" pattern="RESET" title="กรุณาพิมพ์ RESET เพื่อยืนยัน">
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" name="reset" class="btn btn-reset" 
                            onclick="return confirm('คุณแน่ใจว่าต้องการรีเซ็ตรหัสผ่านผู้ดูแลระบบ?')">
                        รีเซ็ตรหัสผ่าน
                    </button>
                    <a href="login.php" class="btn btn-secondary">กลับไปหน้าล็อกอิน</a>
                </div>
            </form>
            
            <div class="mt-4 p-3 bg-light rounded">
                <h5>ข้อมูลการเชื่อมต่อฐานข้อมูล:</h5>
                <ul class="mb-0">
                    <li>โฮสต์: <?php echo DB_HOST; ?></li>
                    <li>ฐานข้อมูล: <?php echo DB_NAME; ?></li>
                    <li>ผู้ใช้: <?php echo DB_USER; ?></li>
                    <li>สถานะ: <?php echo $pdo ? 'เชื่อมต่อสำเร็จ' : 'เชื่อมต่อล้มเหลว'; ?></li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>