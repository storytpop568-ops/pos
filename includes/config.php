<?php
// ตรวจสอบว่า session เริ่มแล้วหรือยัง
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตั้งค่า error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ตรวจสอบว่าค่าคงที่ถูกกำหนดแล้วหรือยังก่อนกำหนด
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'clothing_stock');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}

// พยายามเชื่อมต่อฐานข้อมูล
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว: " . $e->getMessage());
}
?>