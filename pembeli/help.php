<?php
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Cek apakah role user adalah pembeli
if ($_SESSION['role'] !== 'pembeli') {
    // Redirect ke halaman sesuai role
    switch ($_SESSION['role']) {
        case 'penjual':
            header("Location: ../penjual/dashboard.php");
            break;
        case 'pembeli':
            header("Location: ../pembeli/dashboard.php");
            break;
        default:
            header("Location: ../login.php");
            break;
    }
    exit();
}

// Koneksi ke database
require_once '../koneksi.php';

// Ambil data user dari database untuk informasi terbaru
$user_id = $_SESSION['user_id'];
$query = "SELECT username, email, nik, gambar FROM users WHERE id_user = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Ambil jumlah notifikasi, keranjang, dan pesan baru dari database secara real-time
// Notifikasi
$sql_notifikasi = "SELECT COUNT(*) as total_notif FROM notifikasi 
                   WHERE id_user = ? AND is_read = 'unread'";
$stmt_notifikasi = $conn->prepare($sql_notifikasi);
$stmt_notifikasi->bind_param("i", $user_id);
$stmt_notifikasi->execute();
$result_notifikasi = $stmt_notifikasi->get_result();
$notif_data = $result_notifikasi->fetch_assoc();
$total_notif = $notif_data['total_notif'] ?? 0;

// Keranjang
$cart_query = "SELECT COUNT(*) as cart_count FROM keranjang WHERE id_user = ? AND status = 'active'";
$cart_stmt = $conn->prepare($cart_query);
$cart_stmt->bind_param("i", $user_id);
$cart_stmt->execute();
$cart_result = $cart_stmt->get_result();
$cart_data = $cart_result->fetch_assoc();
$cart_count = $cart_data['cart_count'] ?? 0;

// Pesan baru (unread messages)
$unread_messages_query = "SELECT COUNT(*) as unread_count 
                          FROM chat 
                          WHERE id_penerima = ? 
                          AND status_baca = 'terkirim'";
$unread_stmt = $conn->prepare($unread_messages_query);
$unread_stmt->bind_param("i", $user_id);
$unread_stmt->execute();
$unread_result = $unread_stmt->get_result();
$unread_data = $unread_result->fetch_assoc();
$unread_messages_count = $unread_data['unread_count'] ?? 0;

// Fungsi untuk mendapatkan path gambar
function getImagePath($gambar_filename, $username) {
    if (empty($gambar_filename)) {
        return null;
    }
    
    $img = trim($gambar_filename);
    $possible_paths = [
        "../asset/" . $img,
        "../asset/uploads/" . $img,
        "../asset/produk/" . $img,
        "../uploads/" . $img,
        "../" . $img
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    return null;
}

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $username = $user['username'];
    $email = $user['email'];
    $nik = $user['nik'];
} else {
    // Jika user tidak ditemukan, logout
    session_destroy();
    header("Location: ../login.php");
    exit();
}

$stmt->close();
$conn->close();

// Fungsi untuk mendapatkan inisial dari username
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

$initials = getInitials($username);

// Format role untuk ditampilkan
$role_display = ($_SESSION['role'] == 'super_admin') ? 'Super Admin' : 
               (($_SESSION['role'] == 'penjual') ? 'Penjual' : 'Pembeli');

// Path gambar user
$gambar_path = getImagePath($user['gambar'], $username);
$has_image = ($gambar_path !== null);

// Ambil total produk untuk badge
$total_produk = 0; // Default value
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Help Center</title>
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
            --card-border: #e0e0e0;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
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
            overflow-x: hidden;
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
            width: 100%;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-shrink: 0;
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
            gap: 15px;
            flex-shrink: 0;
        }

        .nav-icons {
            display: flex;
            align-items: center;
            gap: 10px;
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
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            z-index: 2;
        }

        .cart-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--secondary);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            z-index: 2;
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
            min-width: 0;
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

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: 70px;
            padding: 30px;
            min-height: calc(100vh - 70px);
            transition: margin-left 0.3s ease;
            width: calc(100% - var(--sidebar-width));
            box-sizing: border-box;
            overflow-x: hidden;
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
            width: 100%;
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

        /* Help Header */
        .help-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(74, 144, 226, 0.3);
        }
        
        .help-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.2;
        }
        
        .help-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .search-help {
            margin: 30px auto;
            max-width: 600px;
        }
        
        .search-help input {
            width: 100%;
            padding: 15px 50px 15px 20px;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .search-help input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
        }

        /* Content Section */
        .content-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
        }

        /* FAQ Accordion */
        .faq-item {
            background: white;
            border-radius: 10px;
            margin-bottom: 15px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        .faq-question {
            padding: 20px;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
        }
        
        .faq-question:hover {
            background: #f8f9fa;
        }
        
        .faq-answer {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            color: var(--gray);
            line-height: 1.6;
        }
        
        .faq-item.active .faq-answer {
            padding: 0 20px 20px;
            max-height: 500px;
        }
        
        .faq-toggle {
            color: var(--secondary);
            transition: transform 0.3s ease;
        }
        
        .faq-item.active .faq-toggle {
            transform: rotate(180deg);
        }

        /* Contact Section */
        .contact-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .contact-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary);
        }
        
        .contact-desc {
            color: var(--gray);
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .contact-methods {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .contact-method {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 10px;
            min-width: 200px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .contact-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 1.5rem;
        }
        
        .contact-method-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .contact-method-detail {
            color: var(--gray);
            font-size: 0.95rem;
        }
        
        .contact-btn {
            display: inline-block;
            margin-top: 30px;
            padding: 15px 30px;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .contact-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(74, 144, 226, 0.3);
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

        .badge-pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
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
            
            .help-header {
                padding: 30px;
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
            
            .help-header h1 {
                font-size: 1.8rem;
            }
            
            .help-header p {
                font-size: 1rem;
            }
            
            .contact-methods {
                flex-direction: column;
                align-items: center;
            }
            
            .contact-method {
                width: 100%;
                max-width: 300px;
            }
            
            .logo-text {
                font-size: 1.5rem;
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
                font-size: 1.3rem;
            }
            
            .help-header {
                padding: 20px;
            }
            
            .help-header h1 {
                font-size: 1.5rem;
            }
            
            .contact-section {
                padding: 30px 20px;
            }
            
            .content-section {
                padding: 20px;
            }
            
            .page-title {
                font-size: 1.5rem;
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
                
                <!-- Cart Icon -->
                <div class="nav-icon cart-icon" id="cartIcon">
                    <i class="fas fa-shopping-cart"></i>
                    <?php if ($cart_count > 0): ?>
                        <span class="cart-count" id="cartCount"><?php echo $cart_count; ?></span>
                    <?php else: ?>
                        <span class="cart-count" id="cartCount" style="display: none;">0</span>
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
                <h4 class="notifications-title">Notifikasi</h4>
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
                <div class="nav-title">Main Menu</div>
                <a href="dashboard.php" class="sidebar-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="produk.php" class="sidebar-item">
                    <i class="fas fa-shopping-bag"></i>
                    <span>Produk</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Account</div>
                <a href="status.php" class="sidebar-item" id="statusMenuItem">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Status</span>
                </a>
                <a href="chat.php" class="sidebar-item" id="chatMenuItem">
                    <i class="fas fa-comments"></i>
                    <span>Chat</span>
                    <?php if ($unread_messages_count > 0): ?>
                        <span class="badge badge-info" id="sidebarChatBadge"><?php echo $unread_messages_count; ?></span>
                    <?php else: ?>
                        <span class="badge badge-info" id="sidebarChatBadge" style="display: none;">0</span>
                    <?php endif; ?>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Reports & Support</div>
                <a href="laporan.php" class="sidebar-item">
                    <i class="fas fa-chart-line"></i>
                    <span>Laporan</span>
                </a>
                <a href="help.php" class="sidebar-item active">
                    <i class="fas fa-question-circle"></i>
                    <span>Help Center</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="content-header">
            <h1 class="page-title">Help Center</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Help Center</span>
            </div>
        </div>
        <!-- FAQ Section -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">FAQ</h2>
            </div>

            <!-- Pertanyaan 1 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana cara pembeli mengetahui apakah pesanan mereka sudah diproses oleh penjual?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Pembeli dapat mengecek menu Status yang menampilkan daftar transaksi, baik yang sedang diproses
                        maupun yang sudah selesai. Selain itu, pembeli akan menerima notifikasi khusus yang
                        memberitahukan apakah pesanan mereka di-Approve (disetujui) atau Ditolak oleh penjual.</p>
                </div>
            </div>

            <!-- Pertanyaan 2 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana sistem memastikan bukti pembayaran saya tidak disalahgunakan?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Wesley Bookstore mengenkripsi file bukti pembayaran, membatasi akses hanya untuk tim verifikasi,
                        dan menyamarkan sebagian data sensitif (misalnya nomor rekening). Bukti hanya dipakai untuk
                        validasi transaksi dan tidak dibagikan ke pihak lain.</p>
                </div>
            </div>

            <!-- Pertanyaan 3 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana Penjual mengelola stok dan memantau keuntungan dari buku yang mereka jual?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Penjual dapat melakukan CRUD produk yang mereka input sendiri, dan jika stok buku sudah mencapai
                        0, penjual dapat menghapus data buku tersebut. Dalam menu detail produk, penjual bisa melihat
                        informasi lengkap mulai dari modal, margin, hingga keuntungan yang didapatkan dari setiap buku.
                    </p>
                </div>
            </div>

            <!-- Pertanyaan 4 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Apa saja batasan akses yang dimiliki Super Admin dalam mengelola database pengguna?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Super Admin memiliki wewenang penuh (CRUD) untuk mengelola akun Penjual dan Kategori Buku.
                        Namun, untuk akun Pembeli, Super Admin tidak bisa menambah akun baru secara langsung (registrasi
                        dilakukan oleh pembeli sendiri) dan hanya memiliki akses untuk melakukan update (edit), delete
                        (hapus), serta melihat data saja. Super Admin juga bertanggung jawab menghapus akun penjual atau
                        pembeli yang statusnya sudah tidak aktif.</p>
                </div>
            </div>

            <!-- Pertanyaan 5 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Fitur apa yang dapat digunakan Penjual untuk memantau performa penjualan secara visual?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Penjual dapat mengakses menu Laporan Penjual yang dilengkapi dengan fitur sortir berdasarkan
                        bulan dan tahun. Sistem ini akan secara otomatis menampilkan grafik garis penjualan yang
                        dihitung berdasarkan margin dan keuntungan dari buku-buku yang terjual. Selain itu, laporan ini
                        juga tersedia dalam format yang dapat diunduh (download).</p>
                </div>
            </div>

            <!-- FAQ tambahan dalam bahasa Inggris (dapat dihapus atau diubah) -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana cara saya mengubah informasi profil saya?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Untuk mengubah informasi profil, buka menu profil Anda melalui ikon pengguna di pojok kanan atas,
                        lalu pilih "Profil". Di halaman tersebut, Anda dapat memperbarui informasi pribadi, foto profil,
                        dan preferensi akun Anda.</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>Apa yang harus saya lakukan jika lupa password?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Di halaman login, klik tautan "Lupa Password". Anda akan diminta untuk memasukkan alamat email
                        yang terdaftar. Sistem akan mengirimkan tautan reset password ke email Anda. Ikuti instruksi
                        dalam email untuk membuat password baru.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- JavaScript untuk Badge Realtime -->
    <script>
        class RealtimeBadgeSystem {
            constructor() {
                this.apiUrl = 'api_notifications.php';
                this.pollingInterval = 30000; // 30 detik
                this.pollingTimer = null;
                this.lastNotificationCount = <?php echo $total_notif; ?>;
                this.lastCartCount = <?php echo $cart_count; ?>;
                this.lastUnreadMessagesCount = <?php echo $unread_messages_count; ?>;
                
                this.initialize();
            }
            
            initialize() {
                this.startPolling();
                this.setupEventListeners();
                this.loadInitialCounts();
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
                const response = await fetch(`${this.apiUrl}?action=get_counts`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message);
                }
                
                return data.data;
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
                
                // Update sidebar notification badge
                const sidebarBadge = document.getElementById('sidebarNotifBadge');
                if (sidebarBadge) {
                    sidebarBadge.textContent = unreadCount;
                    if (unreadCount > 0) {
                        sidebarBadge.style.display = 'flex';
                    } else {
                        sidebarBadge.style.display = 'none';
                    }
                }
                
                // Update cart badges
                const cartCount = data.cart?.count || 0;
                
                // Update navbar cart badge
                const navCartBadge = document.getElementById('cartCount');
                if (navCartBadge) {
                    navCartBadge.textContent = cartCount;
                    if (cartCount > 0) {
                        navCartBadge.style.display = 'flex';
                    } else {
                        navCartBadge.style.display = 'none';
                    }
                }
                
                // Update chat badges (pesan baru)
                const unreadMessagesCount = data.messages?.unread || 0;
                
                // Update sidebar chat badge
                const sidebarChatBadge = document.getElementById('sidebarChatBadge');
                if (sidebarChatBadge) {
                    sidebarChatBadge.textContent = unreadMessagesCount;
                    if (unreadMessagesCount > 0) {
                        sidebarChatBadge.style.display = 'flex';
                        sidebarChatBadge.classList.add('badge-info');
                    } else {
                        sidebarChatBadge.style.display = 'none';
                    }
                }
                
                // Show notification toast if new notifications
                if (unreadCount > this.lastNotificationCount) {
                    const newCount = unreadCount - this.lastNotificationCount;
                    this.showNotificationAlert(newCount, 'notifikasi');
                }
                
                // Show notification toast if new messages
                if (unreadMessagesCount > this.lastUnreadMessagesCount) {
                    const newCount = unreadMessagesCount - this.lastUnreadMessagesCount;
                    this.showNotificationAlert(newCount, 'pesan');
                }
                
                // Save counts for comparison
                this.lastNotificationCount = unreadCount;
                this.lastCartCount = cartCount;
                this.lastUnreadMessagesCount = unreadMessagesCount;
            }
            
            showNotificationAlert(newCount, type = 'notifikasi') {
                const message = type === 'pesan' 
                    ? `Anda memiliki ${newCount} pesan baru` 
                    : `Anda memiliki ${newCount} notifikasi baru`;
                
                const toast = document.createElement('div');
                toast.className = 'notification-toast';
                toast.innerHTML = `
                    <div class="toast-content">
                        <i class="fas ${type === 'pesan' ? 'fa-comment' : 'fa-bell'}"></i>
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
                
                // Listen untuk custom events dari halaman lain
                document.addEventListener('productAddedToCart', (e) => {
                    this.updateCartBadge(e.detail?.cartCount);
                });
                
                document.addEventListener('notificationRead', () => {
                    this.loadInitialCounts();
                });
                
                document.addEventListener('messageRead', () => {
                    this.loadInitialCounts();
                });
                
                document.addEventListener('allMessagesRead', () => {
                    this.loadInitialCounts();
                });
            }
            
            async markAllNotificationsAsRead() {
                try {
                    const response = await fetch(`${this.apiUrl}?action=mark_all_read`, {
                        method: 'POST'
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update badges
                        this.updateBadges({
                            notifications: { unread: 0 },
                            cart: { count: this.lastCartCount },
                            messages: { unread: this.lastUnreadMessagesCount }
                        });
                        
                        // Update notifications list jika dropdown terbuka
                        const dropdown = document.getElementById('notificationsDropdown');
                        if (dropdown && dropdown.classList.contains('show')) {
                            this.loadNotifications();
                        }
                        
                        // Dispatch event untuk memberi tahu halaman lain
                        document.dispatchEvent(new CustomEvent('allNotificationsRead'));
                        
                        return true;
                    }
                    return false;
                } catch (error) {
                    console.error('Gagal menandai notifikasi:', error);
                    throw error;
                }
            }
            
            async markAllMessagesAsRead() {
                try {
                    const response = await fetch(`${this.apiUrl}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=mark_all_messages_read'
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update badges
                        this.updateBadges({
                            notifications: { unread: this.lastNotificationCount },
                            cart: { count: this.lastCartCount },
                            messages: { unread: 0 }
                        });
                        
                        // Dispatch event untuk memberi tahu halaman lain
                        document.dispatchEvent(new CustomEvent('allMessagesRead'));
                        
                        return true;
                    }
                    return false;
                } catch (error) {
                    console.error('Gagal menandai pesan:', error);
                    throw error;
                }
            }
            
            async loadNotifications() {
                const list = document.getElementById('notificationsList');
                if (!list) return;
                
                try {
                    const response = await fetch(`${this.apiUrl}?action=get_notifications`);
                    const data = await response.json();
                    
                    if (data.success) {
                        let html = '';
                        
                        if (data.data.notifications.length > 0) {
                            data.data.notifications.forEach(notif => {
                                const timeAgo = this.getTimeAgo(notif.created_at);
                                const unreadClass = notif.is_read === 'unread' ? 'unread' : '';
                                const iconClass = this.getNotificationIconClass(notif.tipe);
                                const icon = this.getNotificationIcon(notif.tipe);
                                
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
            
            updateCartBadge(count) {
                if (count !== undefined) {
                    this.lastCartCount = count;
                    this.updateBadges({
                        notifications: { unread: this.lastNotificationCount },
                        cart: { count: count },
                        messages: { unread: this.lastUnreadMessagesCount }
                    });
                }
            }
            
            getNotificationIcon(type) {
                switch(type) {
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
            }
            
            destroy() {
                this.stopPolling();
            }
        }
        
        // Initialize system
        document.addEventListener('DOMContentLoaded', () => {
            window.realtimeBadgeSystem = new RealtimeBadgeSystem();
            
            // Setup notification dropdown toggle
            const notificationIcon = document.getElementById('notificationIcon');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            
            if (notificationIcon && notificationsDropdown) {
                notificationIcon.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notificationsDropdown.classList.toggle('show');
                    
                    // Load notifications saat dropdown dibuka
                    if (notificationsDropdown.classList.contains('show')) {
                        window.realtimeBadgeSystem.loadNotifications();
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
            
            // Status menu click handler
            const statusMenuItem = document.getElementById('statusMenuItem');
            if (statusMenuItem) {
                statusMenuItem.addEventListener('click', function(e) {
                    // Hanya eksekusi jika ini adalah link status (bukan link lain)
                    if (this.getAttribute('href') === 'status.php') {
                        e.preventDefault();
                        
                        // Mark all notifications as read
                        if (window.realtimeBadgeSystem) {
                            window.realtimeBadgeSystem.markAllNotificationsAsRead().then(() => {
                                // Navigate to status page
                                window.location.href = 'status.php';
                            }).catch(error => {
                                // If error, still navigate to status page
                                console.error('Error marking notifications:', error);
                                window.location.href = 'status.php';
                            });
                        } else {
                            // If badge system not available, navigate directly
                            window.location.href = 'status.php';
                        }
                    }
                });
            }
            
            // Chat menu click handler
            const chatMenuItem = document.getElementById('chatMenuItem');
            if (chatMenuItem) {
                chatMenuItem.addEventListener('click', function(e) {
                    // Hanya eksekusi jika ini adalah link chat (bukan link lain)
                    if (this.getAttribute('href') === 'chat.php') {
                        e.preventDefault();
                        
                        // Mark all messages as read
                        if (window.realtimeBadgeSystem) {
                            window.realtimeBadgeSystem.markAllMessagesAsRead().then(() => {
                                // Navigate to chat page
                                window.location.href = 'chat.php';
                            }).catch(error => {
                                // If error, still navigate to chat page
                                console.error('Error marking messages:', error);
                                window.location.href = 'chat.php';
                            });
                        } else {
                            // If badge system not available, navigate directly
                            window.location.href = 'chat.php';
                        }
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
            
            // Search functionality
            const searchInput = document.querySelector('.search-bar input');
            const searchIcon = document.querySelector('.search-icon');
            
            if (searchIcon && searchInput) {
                searchIcon.addEventListener('click', () => {
                    if (searchInput.value.trim()) {
                        window.location.href = `produk.php?search=${encodeURIComponent(searchInput.value)}`;
                    }
                });
                
                searchInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && searchInput.value.trim()) {
                        window.location.href = `produk.php?search=${encodeURIComponent(searchInput.value)}`;
                    }
                });
            }
            
            // Logout confirmation
            const logoutLink = document.getElementById('logoutLink');
            if (logoutLink) {
                logoutLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Apakah Anda yakin ingin keluar?')) {
                        // Stop polling sebelum logout
                        if (window.realtimeBadgeSystem) {
                            window.realtimeBadgeSystem.destroy();
                        }
                        window.location.href = this.href;
                    }
                });
            }

            // FAQ Accordion
            document.querySelectorAll('.faq-question').forEach(question => {
                question.addEventListener('click', () => {
                    const item = question.parentElement;
                    item.classList.toggle('active');
                });
            });

            // Search functionality untuk FAQ
            const searchHelpInput = document.querySelector('.search-help input');
            if (searchHelpInput) {
                searchHelpInput.addEventListener('input', function () {
                    const searchTerm = this.value.toLowerCase();
                    const faqItems = document.querySelectorAll('.faq-item');

                    faqItems.forEach(item => {
                        const question = item.querySelector('.faq-question span').textContent.toLowerCase();
                        const answer = item.querySelector('.faq-answer').textContent.toLowerCase();

                        if (question.includes(searchTerm) || answer.includes(searchTerm)) {
                            item.style.display = 'block';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
            
            // Cart icon click event
            document.getElementById('cartIcon').addEventListener('click', function() {
                window.location.href = 'keranjang.php';
            });
            
            // Event listener for all notifications read
            document.addEventListener('allNotificationsRead', function() {
                console.log('Semua notifikasi telah ditandai sebagai dibaca');
                
                // Update badge in status menu
                const sidebarBadge = document.getElementById('sidebarNotifBadge');
                if (sidebarBadge) {
                    sidebarBadge.textContent = '0';
                    sidebarBadge.style.display = 'none';
                }
                
                // Update navbar badge
                const navBadge = document.getElementById('notificationBadge');
                if (navBadge) {
                    navBadge.textContent = '0';
                    navBadge.style.display = 'none';
                    navBadge.classList.remove('badge-pulse');
                }
            });
            
            // Event listener for all messages read
            document.addEventListener('allMessagesRead', function() {
                console.log('Semua pesan telah ditandai sebagai dibaca');
                
                // Update badge in chat menu
                const sidebarChatBadge = document.getElementById('sidebarChatBadge');
                if (sidebarChatBadge) {
                    sidebarChatBadge.textContent = '0';
                    sidebarChatBadge.style.display = 'none';
                }
            });
        });
        
        // Global function untuk digunakan di halaman lain
        function addToCart(productId, quantity = 1) {
            if (!window.realtimeBadgeSystem) return Promise.resolve(false);
            
            return new Promise(async (resolve) => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'add_to_cart');
                    formData.append('product_id', productId);
                    formData.append('quantity', quantity);
                    
                    const response = await fetch('api_notifications.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update badge system
                        window.realtimeBadgeSystem.updateCartBadge(data.data.cart_count);
                        
                        // Trigger custom event
                        document.dispatchEvent(new CustomEvent('productAddedToCart', {
                            detail: { cartCount: data.data.cart_count }
                        }));
                        
                        resolve(true);
                    } else {
                        resolve(false);
                    }
                } catch (error) {
                    console.error('Gagal menambahkan ke keranjang:', error);
                    resolve(false);
                }
            });
        }
        
        function refreshBadges() {
            if (window.realtimeBadgeSystem) {
                window.realtimeBadgeSystem.refresh();
            }
        }

        // Function untuk menandai pesan sebagai dibaca
        function markMessageAsRead(messageId) {
            if (!window.realtimeBadgeSystem) return Promise.resolve(false);
            
            return new Promise(async (resolve) => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_message_read');
                    formData.append('message_id', messageId);
                    
                    const response = await fetch('api_notifications.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update badge system
                        window.realtimeBadgeSystem.loadInitialCounts();
                        
                        // Trigger custom event
                        document.dispatchEvent(new CustomEvent('messageRead'));
                        
                        resolve(true);
                    } else {
                        resolve(false);
                    }
                } catch (error) {
                    console.error('Gagal menandai pesan sebagai dibaca:', error);
                    resolve(false);
                }
            });
        }
        
        // Function untuk menandai semua pesan sebagai dibaca
        function markAllMessagesAsRead() {
            if (!window.realtimeBadgeSystem) return Promise.resolve(false);
            
            return new Promise(async (resolve) => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'mark_all_messages_read');
                    
                    const response = await fetch('api_notifications.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update badge system
                        window.realtimeBadgeSystem.loadInitialCounts();
                        
                        // Trigger custom event
                        document.dispatchEvent(new CustomEvent('allMessagesRead'));
                        
                        resolve(true);
                    } else {
                        resolve(false);
                    }
                } catch (error) {
                    console.error('Gagal menandai semua pesan sebagai dibaca:', error);
                    resolve(false);
                }
            });
        }
    </script>
</body>
</html>