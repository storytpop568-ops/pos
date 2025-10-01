<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการฐานข้อมูล clothing_stock</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 700px;
            width: 95%;
        }

        .header {
            background: linear-gradient(135deg, #ff416c, #ff4757);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }

        .content {
            padding: 40px;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .warning-box h3 {
            color: #856404;
            margin-bottom: 15px;
            font-size: 1.3em;
        }

        .warning-box ul {
            color: #856404;
            padding-left: 20px;
        }

        .warning-box li {
            margin-bottom: 8px;
        }

        .db-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid #007bff;
        }

        .db-info h3 {
            color: #007bff;
            margin-bottom: 15px;
            font-size: 1.4em;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .info-item h4 {
            color: #333;
            font-size: 0.9em;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .info-item .count {
            font-size: 1.5em;
            font-weight: bold;
            color: #007bff;
        }

        .tables-list {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .tables-list h4 {
            color: #495057;
            margin-bottom: 10px;
            font-size: 1em;
        }

        .table-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .table-tag {
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            color: #495057;
        }

        .danger-zone {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
        }

        .danger-zone h3 {
            color: #721c24;
            margin-bottom: 20px;
            font-size: 1.5em;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff416c, #ff4757);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 25px;
            font-size: 1.1em;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px;
            font-weight: 600;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 65, 108, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 5px;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal h3 {
            color: #dc3545;
            margin-bottom: 20px;
            text-align: center;
            font-size: 1.4em;
        }

        .confirmation-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 1em;
            text-align: center;
        }

        .confirmation-input:focus {
            outline: none;
            border-color: #007bff;
        }

        .modal-buttons {
            text-align: center;
            margin-top: 20px;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            margin: 20px 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #007bff, #28a745);
            width: 0%;
            transition: width 0.3s ease;
        }

        .status-message {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: none;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .sql-preview {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-family: 'Consolas', 'Monaco', monospace;
        }

        .sql-preview h4 {
            color: #495057;
            margin-bottom: 10px;
            font-family: 'Sarabun', Arial, sans-serif;
        }

        .sql-code {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 5px;
            font-size: 0.9em;
            overflow-x: auto;
            white-space: pre;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                width: calc(100% - 20px);
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .content {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
     <?php include '../includes/header.php'; ?>
    <div class="container">
        <div class="header">
            <h1>🗄️ จัดการฐานข้อมูล</h1>
            <p>ระบบล้างข้อมูลฐานข้อมูล clothing_stock</p>
        </div>
        
        <div class="content">
            <div class="warning-box">
                <h3>⚠️ คำเตือนสำคัญ</h3>
                <ul>
                    <li>การล้างข้อมูลจะลบข้อมูลทั้งหมดในฐานข้อมูลอย่างถาวร</li>
                    <li>ไม่สามารถกู้คืนข้อมูลที่ถูกลบแล้วได้</li>
                    <li>กรุณาสำรองข้อมูลก่อนดำเนินการ</li>
                    <li>Admin User (ID = 1) จะไม่ถูกลบ</li>
                </ul>
            </div>

            <div class="db-info">
                <h3>📊 ข้อมูลฐานข้อมูล clothing_stock</h3>
                <p style="color: #6c757d; margin-bottom: 20px;">ระบบจัดการสต็อกเสื้อผ้า - ข้อมูลการขาย, สินค้า, ลูกค้า, การคืนสินค้า</p>
                
                <div class="info-grid">
                    <div class="info-item">
                        <h4>สินค้า</h4>
                        <div class="count">10</div>
                    </div>
                    <div class="info-item">
                        <h4>การขาย</h4>
                        <div class="count">13</div>
                    </div>
                    <div class="info-item">
                        <h4>การคืนสินค้า</h4>
                        <div class="count">15</div>
                    </div>
                    <div class="info-item">
                        <h4>ลูกค้า</h4>
                        <div class="count">5</div>
                    </div>
                    <div class="info-item">
                        <h4>หมวดหมู่</h4>
                        <div class="count">5</div>
                    </div>
                    <div class="info-item">
                        <h4>การแจ้งเตือน</h4>
                        <div class="count">5</div>
                    </div>
                </div>

                <div class="tables-list">
                    <h4>ตารางในฐานข้อมูล (12 ตาราง):</h4>
                    <div class="table-tags">
                        <span class="table-tag">categories</span>
                        <span class="table-tag">customers</span>
                        <span class="table-tag">notifications</span>
                        <span class="table-tag">products</span>
                        <span class="table-tag">returns</span>
                        <span class="table-tag">return_items</span>
                        <span class="table-tag">sales</span>
                        <span class="table-tag">sale_items</span>
                        <span class="table-tag">users</span>
                    </div>
                </div>
            </div>

            <div class="sql-preview">
                <h4>🔍 ตัวอย่างคำสั่ง SQL ที่จะถูกรัน:</h4>
                <div class="sql-code">-- ล้างข้อมูล clothing_stock database
SET FOREIGN_KEY_CHECKS = 0;

-- ลบข้อมูลตามลำดับ foreign key dependencies
TRUNCATE TABLE sale_items;
TRUNCATE TABLE return_items;
TRUNCATE TABLE sales;
TRUNCATE TABLE returns;
TRUNCATE TABLE notifications;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE customers;

-- เก็บ admin user ไว้ (id = 1)
DELETE FROM users WHERE id > 1;

-- รีเซ็ต AUTO_INCREMENT
ALTER TABLE sale_items AUTO_INCREMENT = 1;
ALTER TABLE returns AUTO_INCREMENT = 1;
ALTER TABLE return_items AUTO_INCREMENT = 1;
ALTER TABLE sales AUTO_INCREMENT = 1;
ALTER TABLE notifications AUTO_INCREMENT = 1;
ALTER TABLE products AUTO_INCREMENT = 1;
ALTER TABLE categories AUTO_INCREMENT = 1;
ALTER TABLE customers AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 2;

SET FOREIGN_KEY_CHECKS = 1;</div>
            </div>

            <div class="danger-zone">
                <h3>🚨 โซนอันตราย</h3>
                <p style="color: #721c24; margin-bottom: 20px;">การดำเนินการต่อไปนี้จะลบข้อมูลทั้งหมดใน clothing_stock อย่างถาวร</p>
                
                <button class="btn-danger" onclick="showClearModal()">
                    🗑️ ล้างข้อมูล clothing_stock ทั้งหมด
                </button>
            </div>
        </div>
    </div>

    <!-- Modal สำหรับยืนยันการลบ -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <h3>⚠️ ยืนยันการลบข้อมูล</h3>
            <p style="text-align: center; margin: 20px 0; color: #dc3545; font-weight: 600;">
                คุณกำลังจะลบข้อมูลทั้งหมดในฐานข้อมูล clothing_stock<br>
                การดำเนินการนี้ไม่สามารถกู้คืนได้
            </p>
            <p style="text-align: center; margin-bottom: 15px;">กรุณาพิมพ์ <strong style="color: #dc3545;">"CONFIRM DELETE"</strong> เพื่อยืนยัน:</p>
            <input type="text" id="confirmationInput" class="confirmation-input" placeholder="CONFIRM DELETE">
            
            <div class="progress-bar" id="progressBar" style="display: none;">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            
            <div class="status-message" id="statusMessage"></div>
            
            <div class="modal-buttons">
                <button class="btn-secondary" onclick="closeModal()">ยกเลิก</button>
                <button class="btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()">ลบข้อมูล</button>
            </div>
        </div>
    </div>

    <script>
        function showClearModal() {
            const modal = document.getElementById('confirmModal');
            document.getElementById('confirmationInput').value = '';
            document.getElementById('progressBar').style.display = 'none';
            document.getElementById('statusMessage').style.display = 'none';
            modal.style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }
        
        function confirmDelete() {
            const input = document.getElementById('confirmationInput').value;
            if (input !== 'CONFIRM DELETE') {
                alert('กรุณาพิมพ์ "CONFIRM DELETE" เพื่อยืนยัน');
                return;
            }
            
            // แสดง progress bar
            const progressBar = document.getElementById('progressBar');
            const progressFill = document.getElementById('progressFill');
            const statusMessage = document.getElementById('statusMessage');
            
            progressBar.style.display = 'block';
            
            // จำลองการลบข้อมูล
            let progress = 0;
            const steps = [
                'กำลังปิดการตรวจสอบ Foreign Key...',
                'กำลังลบข้อมูล sale_items...',
                'กำลังลบข้อมูล return_items...',
                'กำลังลบข้อมูล sales...',
                'กำลังลบข้อมูล returns...',
                'กำลังลบข้อมูล notifications...',
                'กำลังลบข้อมูล products...',
                'กำลังลบข้อมูล categories...',
                'กำลังลบข้อมูล customers...',
                'กำลังลบ users (เก็บ admin)...',
                'กำลังรีเซ็ต AUTO_INCREMENT...',
                'เสร็จสิ้น!'
            ];
            
            let currentStep = 0;
            
            const interval = setInterval(() => {
                progress += 9; // 100/11 steps
                progressFill.style.width = Math.min(progress, 100) + '%';
                
                if (currentStep < steps.length) {
                    console.log(steps[currentStep]);
                    currentStep++;
                }
                
                if (progress >= 100) {
                    clearInterval(interval);
                    
                    // แสดงผลลัพธ์
                    setTimeout(() => {
                        statusMessage.className = 'status-message status-success';
                        statusMessage.innerHTML = '✅ ลบข้อมูลในฐานข้อมูล clothing_stock เรียบร้อยแล้ว<br><small>Admin user (ID=1) ยังคงอยู่</small>';
                        statusMessage.style.display = 'block';
                        
                        // ปิด modal หลังจาก 3 วินาที
                        setTimeout(() => {
                            closeModal();
                        }, 4000);
                    }, 500);
                }
            }, 300);
        }
        
        // ปิด modal เมื่อคลิกนอก modal
        window.onclick = function(event) {
            const modal = document.getElementById('confirmModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // ตรวจสอบการพิมพ์ Enter
        document.getElementById('confirmationInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmDelete();
            }
        });

        // SQL Command สำหรับ clothing_stock (สำหรับใช้งานจริง)
        const clearClothingStockSQL = `
-- ล้างข้อมูล clothing_stock database
SET FOREIGN_KEY_CHECKS = 0;

-- ลบข้อมูลตามลำดับ foreign key dependencies
TRUNCATE TABLE sale_items;
TRUNCATE TABLE return_items;
TRUNCATE TABLE sales;
TRUNCATE TABLE returns;
TRUNCATE TABLE notifications;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE customers;

-- เก็บ admin user ไว้ (id = 1)
DELETE FROM users WHERE id > 1;

-- รีเซ็ต AUTO_INCREMENT
ALTER TABLE sale_items AUTO_INCREMENT = 1;
ALTER TABLE returns AUTO_INCREMENT = 1;
ALTER TABLE return_items AUTO_INCREMENT = 1;
ALTER TABLE sales AUTO_INCREMENT = 1;
ALTER TABLE notifications AUTO_INCREMENT = 1;
ALTER TABLE products AUTO_INCREMENT = 1;
ALTER TABLE categories AUTO_INCREMENT = 1;
ALTER TABLE customers AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 2;

SET FOREIGN_KEY_CHECKS = 1;
        `;

        // ฟังก์ชันสำหรับแสดง SQL Commands
        function showSQL() {
            console.log('SQL Command for clothing_stock:');
            console.log(clearClothingStockSQL);
        }

        // Copy SQL to clipboard
        function copySQL() {
            navigator.clipboard.writeText(clearClothingStockSQL).then(function() {
                alert('คัดลอก SQL Command แล้ว!');
            });
        }
    </script>
</body>
</html>
