<?php
// เริ่ม session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ลบ session variables
$_SESSION = array();

// ลบ session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// ทำลาย session
session_destroy();

// redirect ไปยังหน้าล็อกอิน
header("Location: ../login.php");
exit;
?>