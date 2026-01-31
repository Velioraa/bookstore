<?php
// Koneksi ke database
require_once '../koneksi.php';

// Mulai session
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    // Jika belum login, redirect ke halaman login
    header("Location: ../login.php");
    exit();
}

// Ambil data user dari database berdasarkan session
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id_user = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $username = $user['username'];
    $role = $user['role'];
} else {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Fungsi untuk mendapatkan notifikasi berdasarkan database yang diberikan
function getNotifications($conn, $user_id) {
    $sql = "SELECT 
                n.id_notifikasi,
                n.id_user,
                n.id_order,
                n.id_produk,
                n.judul,
                n.pesan,
                n.is_read,
                n.type,
                n.created_at,
                t.invoice_number,
                t.status as status_transaksi,
                p.nama_produk,
                p.gambar as produk_gambar
            FROM notifikasi n
            LEFT JOIN transaksi t 
                ON n.id_order = t.id_transaksi
            LEFT JOIN produk p 
                ON n.id_produk = p.id_produk
            WHERE n.id_user = ?
            ORDER BY n.created_at DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    return $stmt->get_result();
}

// Ambil notifikasi
$notif_result = getNotifications($conn, $user_id);
$notifications = [];
$unread_count = 0;
$approval_count = 0;
$rejection_count = 0;
$shipping_count = 0;
$today_count = 0;
$yesterday_count = 0;
$older_count = 0;

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

while ($notif = $notif_result->fetch_assoc()) {
    $notifications[] = $notif;
    
    // Hitung notifikasi belum dibaca
    if ($notif['is_read'] == 0) {
        $unread_count++;
    }
    
    // Hitung notifikasi berdasarkan status transaksi
    if ($notif['status_transaksi'] == 'approve' || 
        $notif['judul'] == 'Pesanan Disetujui' || 
        (strpos($notif['judul'] ?? '', 'Disetujui') !== false)) {
        $approval_count++;
    }
    
    if ($notif['status_transaksi'] == 'tidak' || 
        $notif['status_transaksi'] == 'ditolak' || 
        $notif['judul'] == 'Pesanan Ditolak' || 
        (strpos($notif['judul'] ?? '', 'Ditolak') !== false)) {
        $rejection_count++;
    }
    
    if ($notif['status_transaksi'] == 'dikirim' || 
        $notif['status_transaksi'] == 'processing' || 
        (strpos($notif['judul'] ?? '', 'Dikirim') !== false)) {
        $shipping_count++;
    }
    
    // Group by date
    $notif_date = date('Y-m-d', strtotime($notif['created_at']));
    if ($notif_date == $today) {
        $today_count++;
    } elseif ($notif_date == $yesterday) {
        $yesterday_count++;
    } else {
        $older_count++;
    }
}

// Update semua notifikasi menjadi telah dibaca
$update_sql = "UPDATE notifikasi 
               SET is_read = 1 
               WHERE id_user = ? AND is_read = 0";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("i", $user_id);
$update_stmt->execute();

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi - Wesley Bookstore</title>
    <link rel="website icon" type="png" href="../asset/wesley.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #4a90e2;
            --accent: #9b59b6;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --sky-blue: #87ceeb;
            --light-blue: #e3f2fd;
            --border: #e0e0e0;
            --shadow: 0 2px 8px rgba(0,0,0,0.08);
            --hover-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header dengan tombol back */
        .notif-header {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .back-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: var(--light-blue);
            color: var(--secondary);
            border: none;
            cursor: pointer;
            font-size: 1.3rem;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: #d4e4f7;
            transform: translateX(-3px);
        }

        .header-title {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-title i {
            color: var(--secondary);
            font-size: 1.5rem;
        }

        .header-title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .action-btn {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: var(--light-blue);
            border: none;
            color: var(--secondary);
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            background: #d4e4f7;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Notifications Container */
        .notifications-container {
            flex: 1;
            display: flex;
            gap: 25px;
            padding: 25px;
            overflow: hidden;
            max-width: 100%;
        }

        /* Notifications Sidebar */
        .notif-sidebar {
            width: 300px;
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 25px;
            border-bottom: 1px solid var(--border);
            background: var(--light-blue);
        }

        .sidebar-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 20px;
        }

        .filter-btn {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            background: white;
            border: 2px solid transparent;
            border-radius: 10px;
            font-size: 1rem;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            background: var(--light-blue);
        }

        .filter-btn.active {
            background: var(--light-blue);
            border-color: var(--secondary);
            color: var(--secondary);
            font-weight: 600;
        }

        .filter-btn i {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }

        .filter-badge {
            margin-left: auto;
            background: var(--secondary);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            min-width: 30px;
            text-align: center;
        }

        .badge-danger {
            background: var(--danger);
        }

        .badge-success {
            background: var(--success);
        }

        .badge-warning {
            background: var(--warning);
        }

        /* Notifications List */
        .notif-list-section {
            flex: 1;
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .list-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border);
            background: var(--light-blue);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .list-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary);
        }

        .mark-all-btn {
            background: none;
            border: none;
            color: var(--secondary);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .mark-all-btn:hover {
            background: rgba(74, 144, 226, 0.1);
        }

        .notif-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        /* Date Group */
        .date-group {
            padding: 15px 25px;
            background: #f9f9f9;
            border-bottom: 1px solid var(--border);
        }

        .date-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Notification Item */
        .notif-item {
            padding: 20px 25px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.3s ease;
            position: relative;
        }

        .notif-item:hover {
            background: #f9f9f9;
        }

        .notif-item.unread {
            background: rgba(74, 144, 226, 0.05);
        }

        .notif-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .icon-order {
            background: rgba(74, 144, 226, 0.1);
            color: var(--secondary);
        }

        .icon-approval {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }

        .icon-rejection {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .icon-payment {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }

        .icon-shipping {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info);
        }

        .notif-content {
            flex: 1;
            min-width: 0;
        }

        .notif-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 5px;
            line-height: 1.4;
        }

        .notif-message {
            font-size: 0.95rem;
            color: var(--gray);
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .notif-product {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 8px;
        }

        .product-image {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border);
            background: white;
            padding: 3px;
        }

        .product-name {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark);
            flex: 1;
        }

        .notif-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
        }

        .notif-time {
            font-size: 0.85rem;
            color: var(--gray);
        }

        .notif-status {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 12px;
        }

        .status-approved {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }

        .status-rejected {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }

        .status-shipped {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info);
        }

        .status-completed {
            background: rgba(155, 89, 182, 0.1);
            color: var(--accent);
        }

        .status-ditolak {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .status-processing {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info);
        }

        .status-dikirim {
            background: rgba(74, 144, 226, 0.1);
            color: var(--secondary);
        }

        .unread-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--secondary);
            flex-shrink: 0;
            margin-left: auto;
        }

        /* Empty State */
        .empty-notif {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px;
            text-align: center;
        }

        .empty-notif i {
            font-size: 5rem;
            color: var(--sky-blue);
            margin-bottom: 25px;
            opacity: 0.7;
        }

        .empty-notif h3 {
            font-size: 1.8rem;
            color: var(--primary);
            margin-bottom: 15px;
            font-weight: 700;
        }

        .empty-notif p {
            color: var(--gray);
            margin-bottom: 30px;
            max-width: 400px;
            font-size: 1.1rem;
            line-height: 1.6;
        }

        .shop-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 30px;
            background: var(--secondary);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .shop-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 144, 226, 0.3);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .notifications-container {
                flex-direction: column;
                gap: 20px;
            }
            
            .notif-sidebar {
                width: 100%;
            }
            
            .filter-buttons {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .filter-btn {
                flex: 1;
                min-width: 150px;
            }
        }

        @media (max-width: 768px) {
            .notif-header {
                padding: 15px;
            }
            
            .header-title h1 {
                font-size: 1.5rem;
            }
            
            .back-button {
                width: 45px;
                height: 45px;
            }
            
            .action-btn {
                width: 45px;
                height: 45px;
            }
            
            .notifications-container {
                padding: 15px;
            }
            
            .notif-item {
                padding: 15px;
                flex-direction: column;
                gap: 15px;
            }
            
            .notif-icon {
                width: 45px;
                height: 45px;
            }
            
            .notif-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            
            .unread-dot {
                position: absolute;
                top: 20px;
                right: 20px;
            }
        }

        @media (max-width: 576px) {
            .notif-header {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .header-actions {
                order: 3;
                width: 100%;
                justify-content: center;
            }
            
            .filter-buttons {
                flex-direction: column;
            }
            
            .filter-btn {
                width: 100%;
            }
            
            .empty-notif {
                padding: 30px;
            }
            
            .empty-notif i {
                font-size: 4rem;
            }
            
            .empty-notif h3 {
                font-size: 1.5rem;
            }
        }

        /* Scrollbar Styling */
        .notif-list::-webkit-scrollbar {
            width: 8px;
        }

        .notif-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .notif-list::-webkit-scrollbar-thumb {
            background: var(--secondary);
            border-radius: 4px;
        }

        .notif-list::-webkit-scrollbar-thumb:hover {
            background: #2c7be5;
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .notif-item {
            animation: fadeIn 0.3s ease;
        }

        .badge-pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Header dengan tombol back -->
    <div class="notif-header">
        <button class="back-button" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i>
        </button>
        
        <div class="header-title">
            <i class="fas fa-bell"></i>
            <h1>Notifikasi</h1>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="notifications-container">
            <!-- Sidebar Filter -->
            <div class="notif-sidebar">   
                <div class="filter-buttons">
                    <button class="filter-btn active" data-filter="all" onclick="filterNotifications('all')">
                        <i class="fas fa-layer-group"></i>
                        Semua
                        <span class="filter-badge"><?php echo count($notifications); ?></span>
                    </button>
                    
                    <button class="filter-btn" data-filter="unread" onclick="filterNotifications('unread')">
                        <i class="fas fa-envelope"></i>
                        Belum Dibaca
                        <span class="filter-badge badge-pulse"><?php echo $unread_count; ?></span>
                    </button>
                    
                    <button class="filter-btn" data-filter="approval" onclick="filterNotifications('approval')">
                        <i class="fas fa-check-circle"></i>
                        Persetujuan
                        <span class="filter-badge badge-success"><?php echo $approval_count; ?></span>
                    </button>
                    
                    <button class="filter-btn" data-filter="rejection" onclick="filterNotifications('rejection')">
                        <i class="fas fa-times-circle"></i>
                        Penolakan
                        <span class="filter-badge badge-danger"><?php echo $rejection_count; ?></span>
                    </button>
                    
                    <button class="filter-btn" data-filter="shipping" onclick="filterNotifications('shipping')">
                        <i class="fas fa-truck"></i>
                        Pengiriman
                        <span class="filter-badge badge-warning"><?php echo $shipping_count; ?></span>
                    </button>
                </div>
            </div>

            <!-- Notifications List -->
            <div class="notif-list-section">
                <div class="list-header">
                    <h3 class="list-title">Semua Notifikasi</h3>
                    <button class="mark-all-btn" onclick="markAllAsRead()">
                        <i class="fas fa-check-double"></i>
                        Tandai Semua Sudah Dibaca
                    </button>
                </div>
                
                <?php if (empty($notifications)): ?>
                    <!-- Empty State -->
                    <div class="empty-notif">
                        <i class="far fa-bell-slash"></i>
                        <h3>Tidak Ada Notifikasi</h3>
                        <p>Belum ada notifikasi yang tersedia. Pesanan baru atau pembaruan status akan muncul di sini.</p>
                    </div>
                <?php else: ?>
                    <!-- Notifications List -->
                    <div class="notif-list" id="notifList">
                        <!-- Today's Notifications -->
                        <?php if ($today_count > 0): ?>
                            <div class="date-group">
                                <div class="date-label">
                                    <i class="far fa-calendar-check"></i>
                                    Hari Ini
                                    <span class="filter-badge"><?php echo $today_count; ?></span>
                                </div>
                            </div>
                            
                            <?php foreach ($notifications as $notif): ?>
                                <?php if (date('Y-m-d', strtotime($notif['created_at'])) == $today): ?>
                                    <?php include 'notification_item.php'; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Yesterday's Notifications -->
                        <?php if ($yesterday_count > 0): ?>
                            <div class="date-group">
                                <div class="date-label">
                                    <i class="far fa-calendar"></i>
                                    Kemarin
                                    <span class="filter-badge"><?php echo $yesterday_count; ?></span>
                                </div>
                            </div>
                            
                            <?php foreach ($notifications as $notif): ?>
                                <?php if (date('Y-m-d', strtotime($notif['created_at'])) == $yesterday): ?>
                                    <?php include 'notification_item.php'; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Older Notifications -->
                        <?php if ($older_count > 0): ?>
                            <div class="date-group">
                                <div class="date-label">
                                    <i class="far fa-calendar-alt"></i>
                                    Lebih Lama
                                    <span class="filter-badge"><?php echo $older_count; ?></span>
                                </div>
                            </div>
                            
                            <?php foreach ($notifications as $notif): ?>
                                <?php 
                                $notif_date = date('Y-m-d', strtotime($notif['created_at']));
                                if ($notif_date != $today && $notif_date != $yesterday): 
                                ?>
                                    <?php include 'notification_item.php'; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Data notifications
        let notifications = <?php echo json_encode($notifications); ?>;
        let currentFilter = 'all';

        // Filter notifications
        function filterNotifications(filter) {
            currentFilter = filter;
            
            // Update active button
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.filter === filter) {
                    btn.classList.add('active');
                }
            });
            
            // Filter notifications
            const filteredNotifs = notifications.filter(notif => {
                switch(filter) {
                    case 'all':
                        return true;
                    case 'unread':
                        return notif.is_read == 0;
                    case 'approval':
                        return notif.status_transaksi === 'approve' || 
                               notif.judul === 'Pesanan Disetujui' ||
                               (notif.judul && notif.judul.includes('Disetujui'));
                    case 'rejection':
                        return notif.status_transaksi === 'tidak' || 
                               notif.status_transaksi === 'ditolak' ||
                               notif.judul === 'Pesanan Ditolak' ||
                               (notif.judul && notif.judul.includes('Ditolak'));
                    case 'shipping':
                        return notif.status_transaksi === 'dikirim' ||
                               notif.status_transaksi === 'processing' ||
                               (notif.judul && notif.judul.includes('Dikirim'));
                    default:
                        return true;
                }
            });
            
            // Update list title
            const listTitle = document.querySelector('.list-title');
            switch(filter) {
                case 'all':
                    listTitle.textContent = 'Semua Notifikasi';
                    break;
                case 'unread':
                    listTitle.textContent = 'Belum Dibaca';
                    break;
                case 'approval':
                    listTitle.textContent = 'Persetujuan Pesanan';
                    break;
                case 'rejection':
                    listTitle.textContent = 'Penolakan Pesanan';
                    break;
                case 'shipping':
                    listTitle.textContent = 'Pengiriman';
                    break;
            }
            
            // Re-render notifications
            renderNotifications(filteredNotifs);
        }

        // Render notifications by date
        function renderNotifications(notifs) {
            const notifList = document.getElementById('notifList');
            const today = new Date().toISOString().split('T')[0];
            const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
            
            // Group by date
            const todayNotifs = [];
            const yesterdayNotifs = [];
            const olderNotifs = [];
            
            notifs.forEach(notif => {
                const notifDate = notif.created_at.split(' ')[0];
                if (notifDate === today) {
                    todayNotifs.push(notif);
                } else if (notifDate === yesterday) {
                    yesterdayNotifs.push(notif);
                } else {
                    olderNotifs.push(notif);
                }
            });
            
            // Build HTML
            let html = '';
            
            // Today's notifications
            if (todayNotifs.length > 0) {
                html += `
                    <div class="date-group">
                        <div class="date-label">
                            <i class="far fa-calendar-check"></i>
                            Hari Ini
                            <span class="filter-badge">${todayNotifs.length}</span>
                        </div>
                    </div>
                `;
                
                todayNotifs.forEach(notif => {
                    html += generateNotificationHTML(notif);
                });
            }
            
            // Yesterday's notifications
            if (yesterdayNotifs.length > 0) {
                html += `
                    <div class="date-group">
                        <div class="date-label">
                            <i class="far fa-calendar"></i>
                            Kemarin
                            <span class="filter-badge">${yesterdayNotifs.length}</span>
                        </div>
                    </div>
                `;
                
                yesterdayNotifs.forEach(notif => {
                    html += generateNotificationHTML(notif);
                });
            }
            
            // Older notifications
            if (olderNotifs.length > 0) {
                html += `
                    <div class="date-group">
                        <div class="date-label">
                            <i class="far fa-calendar-alt"></i>
                            Lebih Lama
                            <span class="filter-badge">${olderNotifs.length}</span>
                        </div>
                    </div>
                `;
                
                olderNotifs.forEach(notif => {
                    html += generateNotificationHTML(notif);
                });
            }
            
            // If no notifications match filter
            if (notifs.length === 0) {
                html = `
                    <div class="empty-notif" style="padding: 40px;">
                        <i class="far fa-bell-slash"></i>
                        <h3>Tidak Ada Notifikasi</h3>
                        <p>Tidak ada notifikasi yang sesuai dengan filter yang dipilih.</p>
                    </div>
                `;
            }
            
            notifList.innerHTML = html;
        }

        // Generate notification HTML
        function generateNotificationHTML(notif) {
            // Determine icon and status based on type
            let iconClass = 'icon-order';
            let statusClass = 'status-pending';
            let statusText = 'Pending';
            let title = notif.judul || 'Notifikasi';
            let message = notif.pesan || '';
            
            // Determine icon and status based on transaction status or title
            if (notif.status_transaksi === 'approve' || 
                notif.judul === 'Pesanan Disetujui' || 
                (notif.judul && notif.judul.includes('Disetujui'))) {
                iconClass = 'icon-approval';
                statusClass = 'status-approved';
                statusText = 'Disetujui';
            } else if (notif.status_transaksi === 'tidak' || 
                      notif.status_transaksi === 'ditolak' || 
                      notif.judul === 'Pesanan Ditolak' ||
                      (notif.judul && notif.judul.includes('Ditolak'))) {
                iconClass = 'icon-rejection';
                statusClass = 'status-ditolak';
                statusText = 'Ditolak';
            } else if (notif.status_transaksi === 'dikirim') {
                iconClass = 'icon-shipping';
                statusClass = 'status-dikirim';
                statusText = 'Dikirim';
            } else if (notif.status_transaksi === 'processing') {
                iconClass = 'icon-payment';
                statusClass = 'status-processing';
                statusText = 'Diproses';
            } else if (notif.status_transaksi === 'pending') {
                iconClass = 'icon-payment';
                statusClass = 'status-pending';
                statusText = 'Pending';
            }
            
            // Default product image
            const productImage = notif.produk_gambar ? '../asset/' + notif.produk_gambar : '../asset/wesley.png';
            
            // Format time
            const notifTime = new Date(notif.created_at);
            const timeString = formatTime(notifTime);
            
            // Check if unread
            const unreadClass = notif.is_read == 0 ? 'unread' : '';
            
            return `
                <div class="notif-item ${unreadClass}" onclick="viewNotification(${notif.id_notifikasi})">
                    <div class="notif-icon ${iconClass}">
                        ${getIconByStatus(notif.status_transaksi, notif.judul)}
                    </div>
                    <div class="notif-content">
                        <h4 class="notif-title">${title}</h4>
                        <p class="notif-message">${message}</p>
                        
                        ${notif.nama_produk ? `
                            <div class="notif-product">
                                <img src="${productImage}" 
                                     alt="${notif.nama_produk}" 
                                     class="product-image"
                                     onerror="this.src='../asset/wesley.png'">
                                <span class="product-name">${notif.nama_produk}</span>
                            </div>
                        ` : ''}
                        
                        ${notif.invoice_number ? `
                            <div class="notif-meta">
                                <span class="notif-time">${timeString}</span>
                                <span class="notif-status ${statusClass}">${statusText} • ${notif.invoice_number}</span>
                            </div>
                        ` : `
                            <div class="notif-meta">
                                <span class="notif-time">${timeString}</span>
                                <span class="notif-status ${statusClass}">${statusText}</span>
                            </div>
                        `}
                    </div>
                    ${notif.is_read == 0 ? '<div class="unread-dot"></div>' : ''}
                </div>
            `;
        }

        // Get icon by status
        function getIconByStatus(status, title) {
            if (status === 'approve' || title === 'Pesanan Disetujui' || (title && title.includes('Disetujui'))) {
                return '<i class="fas fa-check-circle"></i>';
            } else if (status === 'tidak' || status === 'ditolak' || title === 'Pesanan Ditolak' || (title && title.includes('Ditolak'))) {
                return '<i class="fas fa-times-circle"></i>';
            } else if (status === 'dikirim') {
                return '<i class="fas fa-truck"></i>';
            } else if (status === 'processing') {
                return '<i class="fas fa-cog"></i>';
            } else if (status === 'pending') {
                return '<i class="fas fa-clock"></i>';
            } else {
                return '<i class="fas fa-shopping-cart"></i>';
            }
        }

        // Format time
        function formatTime(date) {
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffMins < 1) {
                return 'Baru saja';
            } else if (diffMins < 60) {
                return `${diffMins} menit lalu`;
            } else if (diffHours < 24) {
                return `${diffHours} jam lalu`;
            } else if (diffDays === 1) {
                return 'Kemarin';
            } else if (diffDays < 7) {
                return `${diffDays} hari lalu`;
            } else {
                return date.toLocaleDateString('id-ID', {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                });
            }
        }

        // View notification
        function viewNotification(notifId) {
            // Mark as read
            const notifItem = document.querySelector(`.notif-item[onclick="viewNotification(${notifId})"]`);
            if (notifItem) {
                notifItem.classList.remove('unread');
                const dot = notifItem.querySelector('.unread-dot');
                if (dot) dot.remove();
                
                // Update local data
                const notifIndex = notifications.findIndex(n => n.id_notifikasi == notifId);
                if (notifIndex !== -1) {
                    notifications[notifIndex].is_read = 1;
                }
                
                // Send AJAX to mark as read
                markAsRead(notifId);
            }
            
            // In real implementation, you would redirect to appropriate page
            // For now, just show an alert
            const notif = notifications.find(n => n.id_notifikasi == notifId);
            if (notif) {
                if (notif.id_order) {
                    // Redirect to transaction detail
                    window.location.href = `transaksi_detail.php?id=${notif.id_order}`;
                } else if (notif.id_produk) {
                    // Redirect to product detail
                    window.location.href = `produk_detail.php?id=${notif.id_produk}`;
                }
            }
        }

        // Mark all as read
        function markAllAsRead() {
            // Update all items
            document.querySelectorAll('.notif-item.unread').forEach(item => {
                item.classList.remove('unread');
                const dot = item.querySelector('.unread-dot');
                if (dot) dot.remove();
            });
            
            // Update local data
            notifications.forEach(notif => {
                notif.is_read = 1;
            });
            
            // Update badge counts
            document.querySelectorAll('.filter-badge').forEach(badge => {
                if (badge.textContent.includes('unread')) {
                    badge.textContent = '0';
                }
            });
            
            // Send AJAX to mark all as read
            fetch('mark_all_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ user_id: <?php echo $user_id; ?> })
            });
            
            alert('Semua notifikasi telah ditandai sebagai sudah dibaca');
        }

        // AJAX to mark as read
        function markAsRead(notifId) {
            fetch('mark_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    notif_id: notifId,
                    user_id: <?php echo $user_id; ?> 
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    console.error('Error marking as read:', data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Initial render
            filterNotifications('all');
        });
    </script>
</body>
</html>