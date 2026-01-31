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

// Cek apakah role user adalah penjual atau super_admin
if ($_SESSION['role'] !== 'penjual' && $_SESSION['role'] !== 'super_admin') {
    // Redirect ke halaman sesuai role
    switch ($_SESSION['role']) {
        case 'pembeli':
            header("Location: ../pembeli/dashboard.php");
            break;
        default:
            header("Location: ../login.php");
            break;
    }
    exit();
}

// HITUNG NOTIFIKASI UNTUK SIDEBAR (sama seperti dashboard.php)
$user_id = $_SESSION['user_id'];

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

// Ambil data user dari database berdasarkan session
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
    $role_display = ($role == 'super_admin') ? 'Super Admin' : 
                   (($role == 'penjual') ? 'Penjual' : 'Pembeli');
    
    // Path gambar default jika tidak ada
    $gambar_path = !empty($gambar) ? '../asset/' . $gambar : '';
    $has_image = !empty($gambar) && file_exists($gambar_path);
    
    // ========== SEARCH DAN FILTER PARAMETERS ==========
    
    // Ambil parameter search dan filter dari GET
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
    
    // ========== PAGINATION SETUP ==========
    
    // Jumlah data per halaman
    $records_per_page = 10;
    
    // Tentukan halaman saat ini dari query string
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($current_page < 1) $current_page = 1;
    
    // Hitung offset untuk query
    $offset = ($current_page - 1) * $records_per_page;
    
    // ========== QUERY UNTUK DATA PENJUAL DENGAN FILTER ==========
    
    // Query dasar untuk data penjual
    $base_sql = "SELECT * FROM users WHERE role = 'penjual' AND id_user != ?";
    $count_sql = "SELECT COUNT(*) as total FROM users WHERE role = 'penjual' AND id_user != ?";
    
    // Parameter untuk prepared statement
    $params = array($user_id);
    $count_params = array($user_id);
    $types = "i";
    $count_types = "i";
    
    // Tambahkan kondisi search jika ada
    if (!empty($search)) {
        $base_sql .= " AND (username LIKE ? OR email LIKE ?)";
        $count_sql .= " AND (username LIKE ? OR email LIKE ?)";
        
        $search_param = "%" . $search . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        
        $types .= "ss";
        $count_types .= "ss";
    }
    
    // Tambahkan filter status jika dipilih
    if ($status_filter != 'all') {
        if ($status_filter == 'active') {
            $base_sql .= " AND (status = 'active' OR status = 'aktif')";
            $count_sql .= " AND (status = 'active' OR status = 'aktif')";
        } elseif ($status_filter == 'inactive') {
            $base_sql .= " AND (status = 'inactive' OR status = 'nonaktif' OR status = 'suspended')";
            $count_sql .= " AND (status = 'inactive' OR status = 'nonaktif' OR status = 'suspended')";
        }
    }
    
    // Tambahkan sorting dan pagination
    $base_sql .= " ORDER BY username ASC LIMIT ? OFFSET ?";
    
    // Tambahkan parameter pagination
    $params[] = $records_per_page;
    $params[] = $offset;
    $types .= "ii";
    
    // Query untuk menghitung total data
    $stmt_count = $conn->prepare($count_sql);
    
    // Bind parameter untuk count query
    if (count($count_params) > 0) {
        $stmt_count->bind_param($count_types, ...$count_params);
    }
    
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $row_count = $result_count->fetch_assoc();
    $total_records = $row_count['total'];
    
    // Hitung total halaman
    $total_pages = ceil($total_records / $records_per_page);
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $records_per_page;
    }
    
    // Query untuk mengambil data penjual dengan pagination
    $stmt_penjual = $conn->prepare($base_sql);
    
    // Bind parameter untuk main query
    if (count($params) > 0) {
        $stmt_penjual->bind_param($types, ...$params);
    }
    
    $stmt_penjual->execute();
    $result_penjual = $stmt_penjual->get_result();
    
    // ========== HITUNG STATISTIK DENGAN FILTER YANG SAMA ==========
    
    $sql_stats = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' OR status = 'aktif' THEN 1 ELSE 0 END) as aktif,
                    SUM(CASE WHEN status = 'suspended' OR status = 'nonaktif' OR status = 'inactive' THEN 1 ELSE 0 END) as nonaktif
                  FROM users WHERE role = 'penjual' AND id_user != ?";
    
    // Tambahkan kondisi yang sama untuk stats
    if (!empty($search)) {
        $sql_stats .= " AND (username LIKE ? OR email LIKE ?)";
    }
    
    if ($status_filter != 'all') {
        if ($status_filter == 'active') {
            $sql_stats .= " AND (status = 'active' OR status = 'aktif')";
        } elseif ($status_filter == 'inactive') {
            $sql_stats .= " AND (status = 'inactive' OR status = 'nonaktif' OR status = 'suspended')";
        }
    }
    
    $stmt_stats = $conn->prepare($sql_stats);
    
    // Parameter untuk stats
    $stats_params = array($user_id);
    $stats_types = "i";
    
    if (!empty($search)) {
        $search_param = "%" . $search . "%";
        $stats_params[] = $search_param;
        $stats_params[] = $search_param;
        $stats_types .= "ss";
    }
    
    // Bind parameter stats
    if (count($stats_params) > 0) {
        $stmt_stats->bind_param($stats_types, ...$stats_params);
    }
    
    $stmt_stats->execute();
    $result_stats = $stmt_stats->get_result();
    $stats = $result_stats->fetch_assoc();
    
    $total_penjual = $stats['total'] ?? 0;
    $aktif_count = $stats['aktif'] ?? 0;
    $nonaktif_count = $stats['nonaktif'] ?? 0;
    
    // Inisialisasi array untuk menyimpan data penjual
    $penjual_data = array();
    
    if ($result_penjual->num_rows > 0) {
        while ($row = $result_penjual->fetch_assoc()) {
            $penjual_data[] = $row;
        }
    }
    
} else {
    // Jika user tidak ditemukan, logout
    session_destroy();
    header("Location: ../login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Daftar Penjual</title>
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

html, body {
    overflow-x: hidden; /* MENCEGAH SCROLL HORIZONTAL */
    width: 100%;
    background-color: #f5f7fa;
    color: var(--dark);
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
    width: 100%; /* PASTIKAN LEBAR 100% */
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-shrink: 0; /* MENCEGAH SHRINK */
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
    flex-shrink: 0;
}

.menu-toggle:hover {
    background: #e3e9f1;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.logo-img {
    height: 40px;
    width: 40px;
    border-radius: 8px;
    object-fit: contain;
    padding: 8px;
    flex-shrink: 0;
}

.logo-text {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--secondary);
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.search-bar {
    position: relative;
    width: 400px;
    max-width: 100%;
    flex-shrink: 1;
}

.search-bar input {
    width: 100%;
    padding: 12px 45px 12px 20px;
    border: 2px solid #e1e5eb;
    border-radius: 25px;
    font-size: 14px;
    transition: all 0.3s ease;
    box-sizing: border-box;
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
    flex-shrink: 0;
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
    flex-shrink: 0;
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
}

.user-profile {
    position: relative;
    cursor: pointer;
    flex-shrink: 0;
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
    flex-shrink: 0;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.user-details {
    display: flex;
    flex-direction: column;
    min-width: 0; /* MENCEGAH OVERFLOW */
}

.user-name {
    font-weight: 600;
    color: var(--dark);
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 150px;
}

.user-role {
    font-size: 0.8rem;
    color: var(--gray);
    text-transform: capitalize;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
    white-space: nowrap;
}

.dropdown-item:hover {
    background: #f8f9fa;
}

.dropdown-item i {
    margin-right: 10px;
    color: var(--gray);
    width: 20px;
    flex-shrink: 0;
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
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.sidebar::-webkit-scrollbar {
    display: none;
}

.sidebar.collapsed {
    transform: translateX(-100%);
}

.sidebar-nav {
    padding: 0 20px;
    width: 100%;
}

.nav-section {
    margin-bottom: 30px;
    width: 100%;
}

.nav-title {
    font-size: 0.85rem;
    color: var(--gray);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 15px;
    padding: 0 20px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
    width: 100%;
    box-sizing: border-box;
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
    flex-shrink: 0;
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
    flex-shrink: 0;
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
    width: calc(100% - var(--sidebar-width));
    box-sizing: border-box;
    overflow-x: hidden; /* MENCEGAH SCROLL HORIZONTAL */
}

.main-content.expanded {
    margin-left: 0;
    width: 100%;
}

.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-title {
    font-size: 1.8rem;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--gray);
    flex-wrap: wrap;
}

.breadcrumb a {
    color: var(--gray);
    text-decoration: none;
    white-space: nowrap;
}

.breadcrumb a:hover {
    color: var(--secondary);
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
    width: 100%;
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
    width: 100%;
    box-sizing: border-box;
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
    flex-shrink: 0;
}

.stat-content {
    flex-grow: 1;
    min-width: 0; /* MENCEGAH OVERFLOW */
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--gray);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Content Section */
.content-section {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    width: 100%;
    box-sizing: border-box;
    overflow: hidden; /* MENCEGAH OVERFLOW */
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
    width: 100%;
}

.section-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.btn-create {
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    white-space: nowrap;
    flex-shrink: 0;
}

.btn-create:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
}

/* Table Styles - PERBAIKAN UTAMA UNTUK MENCEGAH SCROLL */
.table-container {
    overflow-x: auto;
    width: 100%;
    -webkit-overflow-scrolling: touch;
    margin: 0;
    padding: 0;
}

.admin-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    min-width: 1000px; /* MINIMUM WIDTH FOR TABLE */
}

.admin-table th {
    background-color: #f8f9fa;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: var(--dark);
    border-bottom: 2px solid #e9ecef;
    white-space: nowrap;
}

.admin-table td {
    padding: 15px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
    white-space: nowrap;
}

.admin-table tbody tr:hover {
    background-color: #f8f9fa;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-block;
    white-space: nowrap;
}

.status-active {
    background-color: rgba(40, 167, 69, 0.1);
    color: var(--success);
}

.status-inactive {
    background-color: rgba(220, 53, 69, 0.1);
    color: var(--danger);
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: nowrap;
}

.btn-action {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.btn-edit {
    background-color: rgba(74, 144, 226, 0.1);
    color: var(--secondary);
}

.btn-edit:hover {
    background-color: rgba(74, 144, 226, 0.2);
}

.btn-detail {
    background-color: rgba(155, 89, 182, 0.1);
    color: var(--accent);
}

.btn-detail:hover {
    background-color: rgba(155, 89, 182, 0.2);
}

.btn-delete {
    background-color: rgba(220, 53, 69, 0.1);
    color: var(--danger);
}

.btn-delete:hover {
    background-color: rgba(220, 53, 69, 0.2);
}

/* Filter Section */
.filter-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 15px;
    flex-wrap: wrap;
}

.search-filter {
    flex-grow: 1;
    min-width: 200px;
    position: relative;
}

.search-filter input {
    width: 100%;
    padding: 10px 15px 10px 40px;
    border: 2px solid #e1e5eb;
    border-radius: 8px;
    font-size: 14px;
    box-sizing: border-box;
}

.search-filter i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray);
}

.status-filter select {
    padding: 10px 15px;
    border: 2px solid #e1e5eb;
    border-radius: 8px;
    background: white;
    font-size: 14px;
    min-width: 150px;
    box-sizing: border-box;
    cursor: pointer;
}

.btn-reset {
    padding: 10px 15px;
    border: 2px solid #e1e5eb;
    border-radius: 8px;
    background: white;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 5px;
}

.btn-reset:hover {
    background: #f8f9fa;
    border-color: var(--danger);
    color: var(--danger);
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
    margin-top: 30px;
    flex-wrap: wrap;
}

.pagination a, .pagination span {
    padding: 8px 12px;
    border: 1px solid #e1e5eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    flex-shrink: 0;
    text-decoration: none;
    color: var(--dark);
    font-size: 0.9rem;
    min-width: 40px;
    text-align: center;
}

.pagination a:hover {
    background: #f8f9fa;
}

.pagination .active {
    background: var(--secondary);
    color: white;
    border-color: var(--secondary);
}

.pagination .disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f8f9fa;
}

.pagination-info {
    margin-top: 10px;
    text-align: center;
    color: var(--gray);
    font-size: 0.9rem;
}

/* Password Display */
.password-field {
    font-family: monospace;
    letter-spacing: 1px;
    white-space: nowrap;
}

/* Avatar untuk tabel */
.table-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.9rem;
    overflow: hidden;
    flex-shrink: 0;
}

.table-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
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
        width: 100%;
        padding: 20px;
    }
    
    .main-content.expanded {
        margin-left: 0;
    }
    
    .search-bar {
        width: 300px;
    }
    
    .table-container {
        margin: 0;
        padding: 0;
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
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .btn-create {
        width: 100%;
        justify-content: center;
    }
    
    .main-content {
        padding: 15px;
    }
    
    .content-section {
        padding: 20px 15px;
    }
    
    .table-container {
        margin: 0;
        padding: 0;
    }
    
    .filter-section {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-filter, .status-filter, .btn-reset {
        width: 100%;
    }
}

@media (max-width: 576px) {
    .search-bar {
        display: none;
    }
    
    .main-content {
        padding: 15px 10px;
    }
    
    .logo-text {
        font-size: 1.5rem;
    }
    
    .admin-table {
        font-size: 0.9rem;
        min-width: 800px; /* MIN WIDTH FOR SMALL SCREENS */
    }
    
    .admin-table th,
    .admin-table td {
        padding: 10px 8px;
    }
    
    .action-buttons {
        flex-wrap: wrap;
    }
    
    .table-container {
        margin: 0;
        padding: 0;
    }
    
    .content-section {
        padding: 15px 10px;
    }
    
    .pagination a, .pagination span {
        padding: 6px 10px;
        min-width: 35px;
        font-size: 0.8rem;
    }
}
    </style>
</head>
<body>
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
                <!-- Notification Icon - SAMA DENGAN DASHBOARD -->
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
                <a href="dashboard.php" class="sidebar-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="penjual.php" class="sidebar-item active">
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
                <?php if ($role == 'penjual'): ?>
                <a href="approve.php" class="sidebar-item">
                    <i class="fas fa-check-circle"></i>
                    <span>Approve</span>
                    <?php if ($pending_orders > 0): ?>
                        <span class="badge badge-warning" id="sidebarOrderBadge"><?php echo $pending_orders; ?></span>
                    <?php else: ?>
                        <span class="badge badge-warning" id="sidebarOrderBadge" style="display: none;">0</span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
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
            <h1 class="page-title">Data Admin/Penjual</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Admin</span>
            </div>
        </div>

        <!-- Admin Table Section -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Daftar Penjual</h2>
                <button class="btn-create" onclick="window.location.href='create.php'">
                    <i class="fas fa-plus"></i>
                    Tambah Penjual Baru
                </button>
            </div>
            
            <!-- Filter Section (BACKEND) -->
            <div class="filter-section">
                <form method="GET" action="" class="search-filter">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari berdasarkan username atau email..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </form>
                <form method="GET" action="" class="status-filter">
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>Semua Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Aktif</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Tidak Aktif</option>
                    </select>
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                </form>
                <?php if (!empty($search) || $status_filter != 'all'): ?>
                <button class="btn-reset" onclick="window.location.href='penjual.php'">
                    <i class="fas fa-redo"></i> Reset Filter
                </button>
                <?php endif; ?>
            </div>
            
            <div class="table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Foto</th>
                            <th>Username</th>
                            <th>Nama Lengkap</th>
                            <th>Email</th>
                            <th>Password</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="penjualTableBody">
                        <?php if (!empty($penjual_data)): ?>
                            <?php 
                            // Hitung nomor urut berdasarkan halaman
                            $start_number = (($current_page - 1) * $records_per_page) + 1;
                            $counter = $start_number; 
                            ?>
                            <?php foreach ($penjual_data as $penjual): ?>
                                <?php 
                                    // Tentukan status penjual (asumsi ada kolom 'status' atau gunakan default)
                                    $status = isset($penjual['status']) ? $penjual['status'] : 'active';
                                    
                                    // Tentukan kelas dan teks status
                                    if ($status == 'active' || $status == 'aktif') {
                                        $status_class = 'status-active';
                                        $status_text = 'Aktif';
                                    } else {
                                        $status_class = 'status-inactive';
                                        $status_text = 'Tidak Aktif';
                                    }
                                    
                                    // Gunakan username untuk nama tampilan (karena tidak ada kolom nama_lengkap)
                                    $nama_tampilan = $penjual['username'];
                                    
                                    // Ambil gambar profil dari database
                                    $gambar_penjual = isset($penjual['gambar']) ? $penjual['gambar'] : '';
                                    $gambar_path_penjual = !empty($gambar_penjual) ? '../asset/' . $gambar_penjual : '';
                                    $has_image_penjual = !empty($gambar_penjual) && file_exists($gambar_path_penjual);
                                    
                                    // Buat inisial untuk avatar jika tidak ada gambar
                                    $initials_penjual = getInitials($nama_tampilan);
                                    
                                    // Tentukan warna baris berdasarkan status
                                    $row_bg = '';
                                    if ($status == 'suspended' || $status == 'nonaktif' || $status == 'inactive') {
                                        $row_bg = 'style="background-color: #fff5f5;"';
                                    }
                                ?>
                                <tr <?php echo $row_bg; ?>>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <div class="table-avatar">
                                            <?php if ($has_image_penjual): ?>
                                                <img src="<?php echo $gambar_path_penjual; ?>" alt="<?php echo htmlspecialchars($nama_tampilan); ?>">
                                            <?php else: ?>
                                                <?php echo $initials_penjual; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($penjual['username']); ?></td>
                                    <td><?php echo htmlspecialchars($nama_tampilan); ?></td>
                                    <td><?php echo htmlspecialchars($penjual['email']); ?></td>
                                    <td class="password-field">••••••••</td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: var(--gray);">
                                    <?php if (!empty($search) || $status_filter != 'all'): ?>
                                        Tidak ada penjual yang sesuai dengan filter yang Anda pilih.
                                    <?php elseif ($current_page > 1): ?>
                                        Tidak ada data penjual di halaman ini.
                                    <?php else: ?>
                                        Tidak ada data penjual ditemukan.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div class="pagination">
                <?php if ($total_pages > 0): ?>
                    <!-- Tombol Previous -->
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo buildPaginationUrl($current_page - 1, $search, $status_filter); ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>
                    
                    <!-- Tampilkan halaman 1 -->
                    <a href="?<?php echo buildPaginationUrl(1, $search, $status_filter); ?>" class="<?php echo ($current_page == 1) ? 'active' : ''; ?>">1</a>
                    
                    <!-- Tampilkan halaman sekitar current page -->
                    <?php
                    $start_page = max(2, $current_page - 2);
                    $end_page = min($total_pages - 1, $current_page + 2);
                    
                    // Tampilkan "..." jika ada halaman yang terlewati di awal
                    if ($start_page > 2): ?>
                        <span class="disabled">...</span>
                    <?php endif;
                    
                    // Tampilkan halaman-halaman di tengah
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?<?php echo buildPaginationUrl($i, $search, $status_filter); ?>" class="<?php echo ($current_page == $i) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor;
                    
                    // Tampilkan "..." jika ada halaman yang terlewati di akhir
                    if ($end_page < $total_pages - 1): ?>
                        <span class="disabled">...</span>
                    <?php endif;
                    
                    // Tampilkan halaman terakhir (jika lebih dari 1 halaman)
                    if ($total_pages > 1): ?>
                        <a href="?<?php echo buildPaginationUrl($total_pages, $search, $status_filter); ?>" class="<?php echo ($current_page == $total_pages) ? 'active' : ''; ?>">
                            <?php echo $total_pages; ?>
                        </a>
                    <?php endif; ?>
                    
                    <!-- Tombol Next -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo buildPaginationUrl($current_page + 1, $search, $status_filter); ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Fungsi untuk membangun URL pagination dengan filter -->
    <?php 
    function buildPaginationUrl($page, $search, $status) {
        $params = array();
        
        if ($page > 1) {
            $params['page'] = $page;
        }
        
        if (!empty($search)) {
            $params['search'] = urlencode($search);
        }
        
        if ($status != 'all') {
            $params['status'] = $status;
        }
        
        return http_build_query($params);
    }
    ?>

    <script>
        // Toggle Sidebar - SAMA DENGAN DASHBOARD
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
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

        // Toggle User Dropdown - SAMA DENGAN DASHBOARD
        const userProfile = document.getElementById('userProfile');
        const userDropdown = document.getElementById('userDropdown');

        userProfile.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('show');
            
            // Tutup notifikasi dropdown jika terbuka
            if (notificationsDropdown) {
                notificationsDropdown.classList.remove('show');
            }
        });

        // Toggle Notifications Dropdown
        const notificationIcon = document.getElementById('notificationIcon');
        const notificationsDropdown = document.getElementById('notificationsDropdown');

        if (notificationIcon && notificationsDropdown) {
            notificationIcon.addEventListener('click', (e) => {
                e.stopPropagation();
                notificationsDropdown.classList.toggle('show');
                
                // Tutup user dropdown jika terbuka
                userDropdown.classList.remove('show');
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

        // Logout confirmation - SAMA DENGAN DASHBOARD
        const logoutLink = document.getElementById('logoutLink');
        if (logoutLink) {
            logoutLink.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Apakah Anda yakin ingin keluar?')) {
                    window.location.href = this.href;
                }
            });
        }

        // Submit form search dengan Enter
        document.querySelector('input[name="search"]').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
    </script>
</body>
</html>