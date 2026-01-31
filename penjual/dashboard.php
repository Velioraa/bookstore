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

// Cek apakah role user adalah penjual
if ($_SESSION['role'] !== 'penjual') {
    // Redirect ke halaman sesuai role
    switch ($_SESSION['role']) {
        case 'pembeli':
            header("Location: ../pembeli/dashboard.php");
            break;
        case 'penjual':
            // Tetap di dashboard penjual
            break;
        default:
            header("Location: ../login.php");
            break;
    }
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
    
    // Ambil data user
    $username = $user['username'];
    $role = $user['role'];
    $gambar = $user['gambar'];
    $email = $user['email'];
    
    // Fungsi untuk mendapatkan inisial dari username
    function getInitials($name) {
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        return substr($initials, 0, 2);
    }
    
    // Ambil inisial untuk avatar jika tidak ada gambar
    $initials = getInitials($username);
    
    // Format role untuk ditampilkan
    $role_display = 'Penjual';
    
    // Path gambar default jika tidak ada
    $gambar_path = !empty($gambar) ? '../asset/' . $gambar : '';
    $has_image = !empty($gambar) && file_exists($gambar_path);
    
} else {
    // Jika user tidak ditemukan, logout
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Hitung notifikasi belum dibaca untuk penjual
$sql_notifikasi = "SELECT COUNT(*) as total_notif FROM notifikasi 
                  WHERE id_user = ? AND is_read = 0";
$stmt_notifikasi = $conn->prepare($sql_notifikasi);
$stmt_notifikasi->bind_param("i", $user_id);
$stmt_notifikasi->execute();
$result_notifikasi = $stmt_notifikasi->get_result();
$notif_data = $result_notifikasi->fetch_assoc();
$total_notif = $notif_data['total_notif'] ?? 0;

// Hitung pesanan baru (pending) untuk penjual ini
$sql_orders = "SELECT COUNT(*) as pending_orders FROM transaksi 
              WHERE id_user = ? AND status = 'pending' AND approve = 'pending'";
$stmt_orders = $conn->prepare($sql_orders);
$stmt_orders->bind_param("i", $user_id);
$stmt_orders->execute();
$result_orders = $stmt_orders->get_result();
$orders_data = $result_orders->fetch_assoc();
$pending_orders = $orders_data['pending_orders'] ?? 0;

// Hitung pesan chat belum dibaca untuk penjual
$sql_chat_unread = "SELECT COUNT(*) as unread_chat FROM chat 
                   WHERE id_penerima = ? AND status_baca = 'terkirim'";
$stmt_chat_unread = $conn->prepare($sql_chat_unread);
$stmt_chat_unread->bind_param("i", $user_id);
$stmt_chat_unread->execute();
$result_chat_unread = $stmt_chat_unread->get_result();
$chat_data = $result_chat_unread->fetch_assoc();
$unread_chat = $chat_data['unread_chat'] ?? 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Penjual Dashboard</title>
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
    --sidebar-width: 280px;
}

/* Hapus scrollbar untuk semua browser */
::-webkit-scrollbar {
    display: none;
}

* {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background-color: #f5f7fa;
    color: var(--dark);
    overflow-x: hidden;
}

/* Top Navigation */
.top-nav {
    background: white;
    height: 70px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 30px;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 20px;
}

.menu-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: #f0f4f8;
    color: var(--primary);
    cursor: pointer;
    transition: all 0.3s ease;
}

.menu-toggle:hover {
    background: #e3e9f1;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
}

.logo-img {
    height: 40px;
    width: 40px;
    border-radius: 8px;
    object-fit: contain;
    padding: 8px;
}

.logo-text {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--secondary);
    letter-spacing: 0.5px;
}

.search-bar {
    position: relative;
    width: 400px;
}

.search-bar input {
    width: 100%;
    padding: 12px 45px 12px 20px;
    border: 2px solid #e1e5eb;
    border-radius: 25px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.search-bar input:focus {
    outline: none;
    border-color: var(--secondary);
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
}

.search-icon {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray);
    cursor: pointer;
}

.nav-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.nav-icons {
    display: flex;
    align-items: center;
    gap: 15px;
}

.nav-icon {
    position: relative;
    cursor: pointer;
    color: var(--gray);
    font-size: 1.2rem;
    transition: all 0.3s ease;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.nav-icon:hover {
    color: var(--secondary);
    background: #f0f4f8;
}

.notification-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: var(--danger);
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.user-profile {
    position: relative;
    cursor: pointer;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    transition: all 0.3s ease;
}

.user-info:hover {
    background: #f0f4f8;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 1.1rem;
    overflow: hidden;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-details {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 600;
    color: var(--dark);
    font-size: 0.9rem;
    max-width: 150px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-role {
    font-size: 0.8rem;
    color: var(--gray);
    text-transform: capitalize;
}

.dropdown {
    display: none;
    position: absolute;
    top: 50px;
    right: 0;
    background: white;
    min-width: 200px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-radius: 10px;
    overflow: hidden;
    z-index: 1000;
}

.dropdown.show {
    display: block;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.dropdown-item {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    text-decoration: none;
    color: var(--dark);
    transition: background 0.2s ease;
}

.dropdown-item:hover {
    background: #f8f9fa;
}

.dropdown-item i {
    margin-right: 10px;
    color: var(--gray);
    width: 20px;
}

.dropdown-divider {
    height: 1px;
    background: #e9ecef;
    margin: 5px 0;
}

/* Notifications Dropdown */
.notifications-dropdown {
    display: none;
    position: absolute;
    top: 50px;
    right: 0;
    background: white;
    width: 320px;
    max-height: 400px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    border-radius: 10px;
    overflow: hidden;
    z-index: 1001;
}

.notifications-dropdown.show {
    display: block;
    animation: fadeIn 0.2s ease;
}

.notifications-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notifications-title {
    font-weight: 600;
    color: var(--dark);
}

.mark-all-read {
    background: none;
    border: none;
    color: var(--secondary);
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
}

.mark-all-read:hover {
    text-decoration: underline;
}

.notifications-list {
    max-height: 300px;
    overflow-y: auto;
}

.notification-item {
    padding: 12px 20px;
    border-bottom: 1px solid #f8f9fa;
    cursor: pointer;
    transition: background 0.2s ease;
}

.notification-item:hover {
    background: #f8f9fa;
}

.notification-item.unread {
    background: #f0f8ff;
    border-left: 3px solid var(--secondary);
}

.notification-content {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.notification-icon {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.9rem;
}

.notification-icon.info {
    background: #e3f2fd;
    color: var(--secondary);
}

.notification-icon.success {
    background: #e8f5e9;
    color: var(--success);
}

.notification-icon.warning {
    background: #fff8e1;
    color: var(--warning);
}

.notification-icon.danger {
    background: #ffebee;
    color: var(--danger);
}

.notification-text {
    flex: 1;
}

.notification-message {
    font-size: 0.9rem;
    color: var(--dark);
    margin-bottom: 5px;
    line-height: 1.4;
}

.notification-time {
    font-size: 0.75rem;
    color: var(--gray);
}

.no-notifications, .error-notifications, .loading-notifications {
    padding: 40px 20px;
    text-align: center;
    color: var(--gray);
}

.no-notifications i, .error-notifications i {
    font-size: 2rem;
    margin-bottom: 10px;
    opacity: 0.5;
}

.loading-notifications i {
    font-size: 1.5rem;
}

.notifications-footer {
    padding: 12px 20px;
    border-top: 1px solid #e9ecef;
    text-align: center;
}

.view-all-notifications {
    color: var(--secondary);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
}

.view-all-notifications:hover {
    text-decoration: underline;
}

/* Sidebar */
.sidebar {
    position: fixed;
    left: 0;
    top: 70px;
    bottom: 0;
    width: var(--sidebar-width);
    background: white;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
    padding: 30px 0;
    overflow-y: auto;
    transform: translateX(0);
    transition: transform 0.3s ease;
    z-index: 999;
}

.sidebar.collapsed {
    transform: translateX(-100%);
}

.sidebar-nav {
    padding: 0 20px;
}

.nav-section {
    margin-bottom: 30px;
}

.nav-title {
    font-size: 0.85rem;
    color: var(--gray);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 15px;
    padding: 0 20px;
}

.sidebar-item {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    margin-bottom: 5px;
    border-radius: 10px;
    color: var(--dark);
    text-decoration: none;
    transition: all 0.3s ease;
}

.sidebar-item:hover {
    background: #f0f4f8;
    color: var(--secondary);
}

.sidebar-item.active {
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    color: white;
}

.sidebar-item i {
    margin-right: 15px;
    font-size: 1.2rem;
    width: 25px;
}

.badge {
    margin-left: auto;
    background: var(--danger);
    color: white;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    min-width: 20px;
    text-align: center;
}

.badge-success {
    background: var(--success);
}

.badge-info {
    background: var(--secondary);
}

.badge-warning {
    background: var(--warning);
}

.badge-chat {
    background: var(--accent);
}

/* Main Content */
.main-content {
    margin-left: var(--sidebar-width);
    margin-top: 70px;
    padding: 30px;
    min-height: calc(100vh - 70px);
    transition: margin-left 0.3s ease;
}

.main-content.expanded {
    margin-left: 0;
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-title {
    font-size: 1.8rem;
    font-weight: 600;
    color: var(--primary);
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--gray);
}

.breadcrumb a {
    color: var(--gray);
    text-decoration: none;
}

.breadcrumb a:hover {
    color: var(--secondary);
}

/* Penjual Header */
.penjual-header {
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
}

.penjual-info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.penjual-profile {
    display: flex;
    align-items: center;
    gap: 20px;
}

.penjual-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--secondary);
    font-size: 2rem;
    font-weight: 700;
    overflow: hidden;
}

.penjual-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.penjual-details h2 {
    font-size: 1.8rem;
    margin-bottom: 5px;
}

.penjual-details p {
    opacity: 0.9;
    margin-bottom: 10px;
}

.penjual-status {
    display: flex;
    align-items: center;
    gap: 10px;
}

.status-badge {
    padding: 5px 15px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.penjual-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.penjual-stat {
    text-align: center;
    padding: 15px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}

.penjual-stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.penjual-stat-label {
    font-size: 0.9rem;
    opacity: 0.8;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    gap: 20px;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stat-content {
    flex-grow: 1;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--gray);
}

.stat-change {
    font-size: 0.85rem;
    padding: 3px 8px;
    border-radius: 20px;
    font-weight: 600;
}

.stat-change.positive {
    background: rgba(40, 167, 69, 0.1);
    color: var(--success);
}

.stat-change.negative {
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger);
}

/* Add Product Button */
.add-product-btn {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
    color: white;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
    z-index: 100;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.add-product-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 8px 20px rgba(40, 167, 69, 0.4);
}

/* Notification Toast */
.notification-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    background: #4CAF50;
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    animation: slideIn 0.3s ease;
    max-width: 300px;
}

.notification-toast .toast-content {
    display: flex;
    align-items: center;
    gap: 10px;
}

.notification-toast button {
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    margin-left: auto;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Responsive */
@media (max-width: 1024px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .main-content.expanded {
        margin-left: 0;
    }
    
    .search-bar {
        width: 300px;
    }
    
    .notifications-dropdown {
        right: -50px;
    }
}

@media (max-width: 768px) {
    .top-nav {
        padding: 0 15px;
    }
    
    .search-bar {
        width: 200px;
    }
    
    .user-details {
        display: none;
    }
    
    .penjual-info {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .penjual-profile {
        flex-direction: column;
        text-align: center;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .notifications-dropdown {
        position: fixed;
        top: 70px;
        left: 0;
        right: 0;
        width: 100%;
        max-height: 50vh;
    }
}

@media (max-width: 576px) {
    .search-bar {
        display: none;
    }
    
    .main-content {
        padding: 20px 15px;
    }
    
    .logo-text {
        font-size: 1.5rem;
    }
    
    .notifications-dropdown {
        max-height: 60vh;
    }
}
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="nav-left">
            <div class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </div>
            <div class="logo">
                <img src="../asset/wesley.png" alt="Wesley Bookstore Logo" class="logo-img">
                <span class="logo-text">WESLEY BOOKSTORE</span>
            </div>
        </div>
        <div class="nav-right">
            <div class="nav-icons">
                <!-- Notification Icon -->
                <div class="nav-icon notification-icon" id="notificationIcon">
                    <i class="far fa-bell"></i>
                    <?php if ($total_notif > 0): ?>
                        <span class="notification-badge" id="notificationBadge"><?php echo $total_notif; ?></span>
                    <?php else: ?>
                        <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="user-profile" id="userProfile">
                <div class="user-info">
                    <div class="avatar">
                        <?php if ($has_image): ?>
                            <img src="<?php echo $gambar_path; ?>" alt="<?php echo htmlspecialchars($username); ?>">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                        <span class="user-role"><?php echo $role_display; ?></span>
                    </div>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown" id="userDropdown">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user"></i>
                        <span>Profile</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item" id="logoutLink">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Log Out</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Notifications Dropdown -->
        <div class="notifications-dropdown" id="notificationsDropdown">
            <div class="notifications-header">
                <h4 class="notifications-title">Notifikasi Penjual</h4>
                <button class="mark-all-read" id="markAllReadBtn">
                    Tandai semua dibaca
                </button>
            </div>
            <div class="notifications-list" id="notificationsList">
                <div class="loading-notifications">
                    <i class="fas fa-spinner fa-spin"></i> Memuat notifikasi...
                </div>
            </div>
            <div class="notifications-footer">
                <a href="notifikasi.php" class="view-all-notifications">Lihat semua notifikasi</a>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Main</div>
                <a href="dashboard.php" class="sidebar-item active">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="penjual.php" class="sidebar-item">
                    <i class="fas fa-user-cog"></i>
                    <span>Admin</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Produk Management</div>
                <a href="produk.php" class="sidebar-item">
                    <i class="fas fa-box"></i>
                    <span>Produk</span>
                </a>
                <a href="approve.php" class="sidebar-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Approve</span>
                    <?php if ($pending_orders > 0): ?>
                        <span class="badge badge-warning" id="sidebarOrderBadge"><?php echo $pending_orders; ?></span>
                    <?php else: ?>
                        <span class="badge badge-warning" id="sidebarOrderBadge" style="display: none;">0</span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Laporan & Analisis</div>
                <a href="laporan.php" class="sidebar-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>Laporan</span>
                </a>
                <a href="chat.php" class="sidebar-item">
                    <i class="far fa-comment-dots"></i>
                    <span>Chat</span>
                    <?php if ($unread_chat > 0): ?>
                        <span class="badge badge-chat" id="sidebarChatBadge"><?php echo $unread_chat; ?></span>
                    <?php else: ?>
                        <span class="badge badge-chat" id="sidebarChatBadge" style="display: none;">0</span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Support</div>
                <a href="help.php" class="sidebar-item">
                    <i class="fas fa-question-circle"></i>
                    <span>Help</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="content-header">
            <h1 class="page-title">Penjual Dashboard</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Dashboard</span>
            </div>
        </div>

        <!-- Penjual Header -->
        <div class="penjual-header">
            <div class="penjual-info">
                <div class="penjual-profile">
                    <div class="penjual-avatar">
                        <?php if ($has_image): ?>
                            <img src="<?php echo $gambar_path; ?>" alt="<?php echo htmlspecialchars($username); ?>">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="penjual-details">
                        <h2><?php echo htmlspecialchars($username); ?></h2>
                        <p><?php echo htmlspecialchars($email); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript untuk Notifikasi Realtime -->
    <script>
        class PenjualNotificationSystem {
            constructor() {
                this.apiUrl = 'api_notifications.php';
                this.pollingInterval = 30000; // 30 detik
                this.pollingTimer = null;
                this.lastNotificationCount = <?php echo $total_notif; ?>;
                this.lastOrderCount = <?php echo $pending_orders; ?>;
                this.lastChatCount = <?php echo $unread_chat; ?>;
                
                this.initialize();
            }
            
            initialize() {
                this.startPolling();
                this.setupEventListeners();
                this.loadInitialCounts();
                this.loadSellerStats();
            }
            
            async loadInitialCounts() {
                try {
                    const data = await this.fetchCounts();
                    this.updateBadges(data);
                } catch (error) {
                    console.error('Gagal load initial counts:', error);
                }
            }
            
            async fetchCounts() {
                const response = await fetch(`${this.apiUrl}?action=get_counts&user_id=<?php echo $user_id; ?>`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                return data.data;
            }
            
            async loadSellerStats() {
                try {
                    const response = await fetch(`${this.apiUrl}?action=get_seller_stats&user_id=<?php echo $user_id; ?>`);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.updateStats(data.data);
                    }
                } catch (error) {
                    console.error('Gagal load seller stats:', error);
                }
            }
            
            updateBadges(data) {
                // Update notification badges
                const unreadCount = data.notifications?.unread || 0;
                
                // Update navbar badge
                const navBadge = document.getElementById('notificationBadge');
                if (navBadge) {
                    navBadge.textContent = unreadCount;
                    if (unreadCount > 0) {
                        navBadge.style.display = 'flex';
                        navBadge.classList.add('badge-pulse');
                    } else {
                        navBadge.style.display = 'none';
                        navBadge.classList.remove('badge-pulse');
                    }
                }
                
                // Update dashboard stat
                const dashboardStat = document.getElementById('totalNotif');
                if (dashboardStat) {
                    dashboardStat.textContent = unreadCount;
                }
                
                // Update order badges
                const newOrders = data.orders?.new || 0;
                
                // Update sidebar order badge
                const sidebarOrderBadge = document.getElementById('sidebarOrderBadge');
                if (sidebarOrderBadge) {
                    sidebarOrderBadge.textContent = newOrders;
                    if (newOrders > 0) {
                        sidebarOrderBadge.style.display = 'flex';
                        sidebarOrderBadge.classList.add('badge-warning');
                    } else {
                        sidebarOrderBadge.style.display = 'none';
                    }
                }
                
                // Update penjual header order stat
                const penjualOrderStat = document.querySelectorAll('.penjual-stat-value')[0];
                if (penjualOrderStat) {
                    penjualOrderStat.textContent = newOrders;
                }
                
                // Update chat badges
                const unreadChat = data.chat?.unread || 0;
                
                // Update sidebar chat badge
                const sidebarChatBadge = document.getElementById('sidebarChatBadge');
                if (sidebarChatBadge) {
                    sidebarChatBadge.textContent = unreadChat;
                    if (unreadChat > 0) {
                        sidebarChatBadge.style.display = 'flex';
                        sidebarChatBadge.classList.add('badge-chat');
                    } else {
                        sidebarChatBadge.style.display = 'none';
                    }
                }
                
                // Update penjual header chat stat
                const penjualChatStat = document.getElementById('totalChat');
                if (penjualChatStat) {
                    penjualChatStat.textContent = unreadChat;
                }
                
                // Show notification toast if new notifications
                if (unreadCount > this.lastNotificationCount) {
                    const newCount = unreadCount - this.lastNotificationCount;
                    this.showNotificationAlert(newCount, 'notifikasi');
                }
                
                // Show notification toast if new orders
                if (newOrders > this.lastOrderCount) {
                    const newCount = newOrders - this.lastOrderCount;
                    this.showNotificationAlert(newCount, 'pesanan');
                }
                
                // Show notification toast if new chat messages
                if (unreadChat > this.lastChatCount) {
                    const newCount = unreadChat - this.lastChatCount;
                    this.showNotificationAlert(newCount, 'chat');
                }
                
                // Save counts for comparison
                this.lastNotificationCount = unreadCount;
                this.lastOrderCount = newOrders;
                this.lastChatCount = unreadChat;
            }
            
            updateStats(stats) {
                if (stats.products) {
                    const productCount = document.getElementById('productCount');
                    if (productCount) productCount.textContent = stats.products;
                }
                
                if (stats.total_orders) {
                    const totalOrders = document.getElementById('totalOrders');
                    if (totalOrders) totalOrders.textContent = stats.total_orders;
                }
                
                if (stats.total_revenue) {
                    const totalRevenue = document.getElementById('totalRevenue');
                    if (totalRevenue) {
                        const revenue = parseInt(stats.total_revenue);
                        if (revenue >= 1000000) {
                            totalRevenue.textContent = 'Rp ' + (revenue / 1000000).toFixed(1) + ' JT';
                        } else if (revenue >= 1000) {
                            totalRevenue.textContent = 'Rp ' + (revenue / 1000).toFixed(0) + ' RB';
                        } else {
                            totalRevenue.textContent = 'Rp ' + revenue;
                        }
                    }
                }
            }
            
            showNotificationAlert(newCount, type = 'notifikasi') {
                let message = '';
                let icon = 'fa-bell';
                let bgColor = '#4CAF50';
                
                switch(type) {
                    case 'pesanan':
                        message = `Anda memiliki ${newCount} pesanan baru yang perlu disetujui`;
                        icon = 'fa-shopping-cart';
                        bgColor = '#FF9800';
                        break;
                    case 'chat':
                        message = `Anda memiliki ${newCount} pesan chat baru`;
                        icon = 'fa-comment-dots';
                        bgColor = '#9b59b6';
                        break;
                    default:
                        message = `Anda memiliki ${newCount} notifikasi baru`;
                        icon = 'fa-bell';
                        bgColor = '#4CAF50';
                }
                
                const toast = document.createElement('div');
                toast.className = 'notification-toast';
                toast.style.background = bgColor;
                toast.innerHTML = `
                    <div class="toast-content">
                        <i class="fas ${icon}"></i>
                        <span>${message}</span>
                        <button onclick="this.parentElement.parentElement.remove()">&times;</button>
                    </div>
                `;
                
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 5000);
            }
            
            startPolling() {
                if (this.pollingTimer) return;
                
                this.pollingTimer = setInterval(async () => {
                    try {
                        const data = await this.fetchCounts();
                        this.updateBadges(data);
                        this.loadSellerStats();
                    } catch (error) {
                        console.error('Polling error:', error);
                    }
                }, this.pollingInterval);
            }
            
            stopPolling() {
                if (this.pollingTimer) {
                    clearInterval(this.pollingTimer);
                    this.pollingTimer = null;
                }
            }
            
            setupEventListeners() {
                // Mark all as read button
                const markAllReadBtn = document.getElementById('markAllReadBtn');
                if (markAllReadBtn) {
                    markAllReadBtn.addEventListener('click', () => this.markAllNotificationsAsRead());
                }
                
                // Listen untuk custom events
                document.addEventListener('newOrder', () => {
                    this.loadInitialCounts();
                });
                
                document.addEventListener('notificationRead', () => {
                    this.loadInitialCounts();
                });
                
                document.addEventListener('newChatMessage', () => {
                    this.loadInitialCounts();
                });
            }
            
            async markAllNotificationsAsRead() {
                try {
                    const response = await fetch(`${this.apiUrl}?action=mark_all_read`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `user_id=<?php echo $user_id; ?>`
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update badges
                        this.updateBadges({
                            notifications: { unread: 0 },
                            orders: { new: this.lastOrderCount },
                            chat: { unread: this.lastChatCount }
                        });
                        
                        // Update notifications list jika dropdown terbuka
                        const dropdown = document.getElementById('notificationsDropdown');
                        if (dropdown && dropdown.classList.contains('show')) {
                            this.loadNotifications();
                        }
                        
                        alert('Semua notifikasi telah ditandai sebagai dibaca');
                    }
                } catch (error) {
                    console.error('Gagal menandai notifikasi:', error);
                    alert('Gagal menandai notifikasi sebagai dibaca');
                }
            }
            
            async loadNotifications() {
                const list = document.getElementById('notificationsList');
                if (!list) return;
                
                try {
                    const response = await fetch(`${this.apiUrl}?action=get_notifications&user_id=<?php echo $user_id; ?>`);
                    const data = await response.json();
                    
                    if (data.success) {
                        let html = '';
                        
                        if (data.data.notifications.length > 0) {
                            data.data.notifications.forEach(notif => {
                                const timeAgo = this.getTimeAgo(notif.created_at);
                                const unreadClass = notif.is_read === '0' ? 'unread' : '';
                                const iconClass = this.getNotificationIconClass(notif.type);
                                const icon = this.getNotificationIcon(notif.type);
                                
                                html += `
                                    <div class="notification-item ${unreadClass}" data-id="${notif.id_notifikasi}">
                                        <div class="notification-content">
                                            <div class="notification-icon ${iconClass}">
                                                <i class="${icon}"></i>
                                            </div>
                                            <div class="notification-text">
                                                <p class="notification-message">${notif.pesan}</p>
                                                <span class="notification-time">${timeAgo}</span>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html = `
                                <div class="no-notifications">
                                    <i class="far fa-bell-slash"></i>
                                    <p>Tidak ada notifikasi</p>
                                </div>
                            `;
                        }
                        
                        list.innerHTML = html;
                        
                        // Add click events untuk notifikasi
                        document.querySelectorAll('.notification-item').forEach(item => {
                            item.addEventListener('click', () => {
                                const notifId = item.dataset.id;
                                this.markSingleNotificationAsRead(notifId);
                            });
                        });
                    }
                } catch (error) {
                    console.error('Error loading notifications:', error);
                    list.innerHTML = `
                        <div class="error-notifications">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Gagal memuat notifikasi</p>
                        </div>
                    `;
                }
            }
            
            async markSingleNotificationAsRead(notifId) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_single_read');
                    formData.append('notif_id', notifId);
                    formData.append('user_id', '<?php echo $user_id; ?>');
                    
                    await fetch(this.apiUrl, {
                        method: 'POST',
                        body: formData
                    });
                    
                    // Remove unread class
                    const item = document.querySelector(`.notification-item[data-id="${notifId}"]`);
                    if (item) {
                        item.classList.remove('unread');
                    }
                    
                    // Refresh counts
                    this.loadInitialCounts();
                    
                    // Trigger custom event
                    document.dispatchEvent(new CustomEvent('notificationRead'));
                } catch (error) {
                    console.error('Error marking notification:', error);
                }
            }
            
            getNotificationIcon(type) {
                switch(type) {
                    case 'new_order': return 'fas fa-shopping-cart';
                    case 'order_approved': return 'fas fa-check-circle';
                    case 'order_rejected': return 'fas fa-times-circle';
                    case 'payment': return 'fas fa-money-check-alt';
                    case 'shipped': return 'fas fa-truck';
                    case 'delivered': return 'fas fa-box-open';
                    case 'message': return 'fas fa-comment';
                    default: return 'fas fa-shopping-cart';
                }
            }
            
            getNotificationIconClass(type) {
                switch(type) {
                    case 'new_order': return 'warning';
                    case 'order_approved': return 'success';
                    case 'order_rejected': return 'danger';
                    case 'payment': return 'warning';
                    case 'shipped': return 'info';
                    case 'delivered': return 'success';
                    case 'message': return 'info';
                    default: return 'info';
                }
            }
            
            getTimeAgo(dateTime) {
                const date = new Date(dateTime);
                const now = new Date();
                const diffMs = now - date;
                const diffMins = Math.floor(diffMs / 60000);
                const diffHours = Math.floor(diffMs / 3600000);
                const diffDays = Math.floor(diffMs / 86400000);
                
                if (diffMins < 1) return 'Baru saja';
                if (diffMins < 60) return `${diffMins} menit lalu`;
                if (diffHours < 24) return `${diffHours} jam lalu`;
                if (diffDays === 1) return 'Kemarin';
                if (diffDays < 7) return `${diffDays} hari lalu`;
                
                return date.toLocaleDateString('id-ID');
            }
            
            refresh() {
                this.loadInitialCounts();
                this.loadSellerStats();
            }
            
            destroy() {
                this.stopPolling();
            }
        }
        
        // Initialize system
        document.addEventListener('DOMContentLoaded', () => {
            window.penjualNotificationSystem = new PenjualNotificationSystem();
            
            // Setup notification dropdown toggle
            const notificationIcon = document.getElementById('notificationIcon');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            
            if (notificationIcon && notificationsDropdown) {
                notificationIcon.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notificationsDropdown.classList.toggle('show');
                    
                    // Load notifications saat dropdown dibuka
                    if (notificationsDropdown.classList.contains('show')) {
                        window.penjualNotificationSystem.loadNotifications();
                    }
                    
                    // Tutup user dropdown jika terbuka
                    userDropdown.classList.remove('show');
                });
            }
            
            // Toggle Sidebar
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (menuToggle) {
                menuToggle.addEventListener('click', () => {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                    
                    const menuIcon = menuToggle.querySelector('i');
                    if (sidebar.classList.contains('collapsed')) {
                        menuIcon.className = 'fas fa-bars';
                    } else {
                        menuIcon.className = 'fas fa-times';
                    }
                });
            }
            
            // Toggle User Dropdown
            const userProfile = document.getElementById('userProfile');
            const userDropdown = document.getElementById('userDropdown');
            
            if (userProfile && userDropdown) {
                userProfile.addEventListener('click', (e) => {
                    e.stopPropagation();
                    userDropdown.classList.toggle('show');
                    
                    // Tutup notifikasi dropdown jika terbuka
                    if (notificationsDropdown) {
                        notificationsDropdown.classList.remove('show');
                    }
                });
            }
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', (e) => {
                if (notificationsDropdown && !notificationIcon.contains(e.target) && !notificationsDropdown.contains(e.target)) {
                    notificationsDropdown.classList.remove('show');
                }
                
                if (userDropdown && !userProfile.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.classList.remove('show');
                }
            });
            
            // Logout confirmation
            const logoutLink = document.getElementById('logoutLink');
            if (logoutLink) {
                logoutLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Apakah Anda yakin ingin keluar?')) {
                        // Stop polling sebelum logout
                        if (window.penjualNotificationSystem) {
                            window.penjualNotificationSystem.destroy();
                        }
                        window.location.href = this.href;
                    }
                });
            }
            
            // Approve menu click - Redirect to approve.php
            const approveMenu = document.querySelector('a[href="approve.php"]');
            if (approveMenu) {
                approveMenu.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = 'approve.php';
                });
            }
            
            // Notifikasi menu click - Redirect to notifikasi.php
            const notifMenu = document.querySelector('a[href="notifikasi.php"]');
            if (notifMenu) {
                notifMenu.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = 'notifikasi.php';
                });
            }
            
            // Chat menu click - Redirect to chat.php
            const chatMenu = document.querySelector('a[href="chat.php"]');
            if (chatMenu) {
                chatMenu.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = 'chat.php';
                });
            }
        });
        
        // Global function untuk digunakan di halaman lain
        function refreshPenjualNotifications() {
            if (window.penjualNotificationSystem) {
                window.penjualNotificationSystem.refresh();
            }
        }
        
        // Function untuk menandai notifikasi sebagai dibaca
        function markPenjualNotificationAsRead(notifId) {
            if (!window.penjualNotificationSystem) return Promise.resolve(false);
            
            return new Promise(async (resolve) => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_single_read');
                    formData.append('notif_id', notifId);
                    formData.append('user_id', '<?php echo $user_id; ?>');
                    
                    const response = await fetch('api_notifications.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update notification system
                        window.penjualNotificationSystem.loadInitialCounts();
                        
                        // Trigger custom event
                        document.dispatchEvent(new CustomEvent('notificationRead'));
                        
                        resolve(true);
                    } else {
                        resolve(false);
                    }
                } catch (error) {
                    console.error('Gagal menandai notifikasi sebagai dibaca:', error);
                    resolve(false);
                }
            });
        }
        
        // Function untuk menandai semua notifikasi sebagai dibaca
        function markAllPenjualNotificationsAsRead() {
            if (!window.penjualNotificationSystem) return Promise.resolve(false);
            
            return new Promise(async (resolve) => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_all_read');
                    formData.append('user_id', '<?php echo $user_id; ?>');
                    
                    const response = await fetch('api_notifications.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update notification system
                        window.penjualNotificationSystem.loadInitialCounts();
                        
                        // Trigger custom event
                        document.dispatchEvent(new CustomEvent('notificationRead'));
                        
                        resolve(true);
                    } else {
                        resolve(false);
                    }
                } catch (error) {
                    console.error('Gagal menandai semua notifikasi sebagai dibaca:', error);
                    resolve(false);
                }
            });
        }
        
        // Function untuk mark chat messages as read
        function markChatMessagesAsRead(otherUserId) {
            if (!window.penjualNotificationSystem) return Promise.resolve(false);
            
            return new Promise(async (resolve) => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_as_read');
                    formData.append('other_user_id', otherUserId);
                    formData.append('user_id', '<?php echo $user_id; ?>');
                    
                    const response = await fetch('chat_api.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update notification system
                        window.penjualNotificationSystem.loadInitialCounts();
                        
                        // Trigger custom event
                        document.dispatchEvent(new CustomEvent('newChatMessage'));
                        
                        resolve(true);
                    } else {
                        resolve(false);
                    }
                } catch (error) {
                    console.error('Gagal menandai pesan chat sebagai dibaca:', error);
                    resolve(false);
                }
            });
        }
    </script>
</body>
</html>