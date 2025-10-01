// การจัดการการแจ้งเตือน
function markAllAsRead() {
    fetch('includes/notifications.php?action=mark_all_read', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // อัพเดต badge
            document.querySelectorAll('.notification-badge').forEach(badge => {
                badge.remove();
            });
            
            // ลบสถานะใหม่จากการแจ้งเตือน
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            
            document.querySelectorAll('.notification-item .badge').forEach(badge => {
                badge.remove();
            });
            
            // แสดงการแจ้งเตือน
            Swal.fire({
                icon: 'success',
                title: 'ทำเครื่องหมายว่าอ่านแล้ว',
                text: 'การแจ้งเตือนทั้งหมดถูกทำเครื่องหมายว่าอ่านแล้ว',
                timer: 2000,
                showConfirmButton: false
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'เกิดข้อผิดพลาด',
            text: 'ไม่สามารถอัพเดตการแจ้งเตือนได้'
        });
    });
}

// ตรวจสอบการแจ้งเตือนใหม่ทุก 30 วินาที
function checkNewNotifications() {
    fetch('includes/notifications.php?action=get_count')
        .then(response => response.json())
        .then(data => {
            if (data.count > 0) {
                updateNotificationBadge(data.count);
            }
        })
        .catch(error => console.error('Error checking notifications:', error));
}

function updateNotificationBadge(count) {
    let badge = document.querySelector('.notification-badge');
    if (count > 0) {
        if (!badge) {
            const notificationIcon = document.querySelector('[data-bs-target="#notificationDropdown"]');
            badge = document.createElement('span');
            badge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger pulse notification-badge';
            notificationIcon.appendChild(badge);
        }
        badge.textContent = count > 9 ? '9+' : count;
    } else if (badge) {
        badge.remove();
    }
}

// ตรวจสอบการแจ้งเตือนทุก 30 วินาที
setInterval(checkNewNotifications, 30000);

// การแจ้งเตือนแบบ real-time (ถ้า supported)
if (typeof(EventSource) !== "undefined") {
    const eventSource = new EventSource("includes/notifications_sse.php");
    eventSource.onmessage = function(event) {
        const data = JSON.parse(event.data);
        if (data.type === 'notification') {
            checkNewNotifications();
        }
    };
}