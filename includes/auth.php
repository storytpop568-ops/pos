<?php
// ตรวจสอบว่ามีการประกาศฟังก์ชันแล้วหรือยัง
require_once 'config.php';
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
}

if (!function_exists('hasRole')) {
    function hasRole($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
}
// เพิ่มฟังก์ชันตรวจสอบบทบาท
if (!function_exists('hasRole')) {
    function hasRole($requiredRole) {
        if (!isset($_SESSION['role'])) {
            return false;
        }
        
        // กำหนดลำดับความสำคัญของบทบาท
        $roleHierarchy = [
            'staff' => 1,
            'manager' => 2,
            'admin' => 3
        ];
        
        $userRoleLevel = $roleHierarchy[$_SESSION['role']] ?? 0;
        $requiredRoleLevel = $roleHierarchy[$requiredRole] ?? 0;
        
        return $userRoleLevel >= $requiredRoleLevel;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // ตรวจสอบข้อมูลในฐานข้อมูล
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            echo json_encode(['success' => true, 'message' => 'เข้าสู่ระบบสำเร็จ']);
        } else {
            echo json_encode(['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
        }
    }
}
?>