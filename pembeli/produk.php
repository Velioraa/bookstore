<?php
// Koneksi ke database
require_once '../koneksi.php';

// Mulai session
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

// Ambil jumlah notifikasi dan keranjang dari database secara real-time
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

// Pesan baru (unread messages) - Hitung pesan yang belum dibaca oleh pembeli ini
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

// Fungsi untuk mendapatkan path gambar produk
function getProductImagePath($gambar_filename) {
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

// PROSES TAMBAH KE KERANJANG
if (isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity'] ?? 1);
    
    // Validasi input
    if ($product_id > 0 && $quantity > 0) {
        // Cek apakah produk sudah ada di keranjang
        $sql_check = "SELECT id_keranjang, qty FROM keranjang 
                      WHERE id_user = ? AND id_produk = ? AND status = 'active'";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $user_id, $product_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            // Update quantity jika produk sudah ada
            $cart_item = $result_check->fetch_assoc();
            $new_qty = $cart_item['qty'] + $quantity;
            
            $sql_update = "UPDATE keranjang SET qty = ? WHERE id_keranjang = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ii", $new_qty, $cart_item['id_keranjang']);
            $stmt_update->execute();
        } else {
            // Tambah baru ke keranjang
            $sql_insert = "INSERT INTO keranjang (id_user, id_produk, qty, status, created_at) 
                           VALUES (?, ?, ?, 'active', NOW())";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("iii", $user_id, $product_id, $quantity);
            $stmt_insert->execute();
        }
        
        // Redirect untuk refresh halaman
        header("Location: produk.php?added=true&product_id=" . $product_id);
        exit();
    }
}

// Handle AJAX request untuk detail produk
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_product_detail') {
        $product_id = $_POST['id'] ?? '';
        
        $sql = "SELECT p.*, k.nama_kategori, u.id_user as penjual_id, u.username as penjual_username
                FROM produk p 
                LEFT JOIN katagori k ON p.id_kategori = k.id_kategori
                LEFT JOIN users u ON p.id_penjual = u.id_user
                WHERE p.id_produk = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $product = $result->fetch_assoc();
            
            // Cek path gambar
            $image_path = getProductImagePath($product['gambar']);
            $product['gambar_path'] = $image_path;
            
            // Format harga
            $product['harga_formatted'] = 'Rp ' . number_format($product['harga_jual'], 0, ',', '.');
            
            // Format modal dan keuntungan jika ada
            if (isset($product['modal'])) {
                $product['modal_formatted'] = 'Rp ' . number_format($product['modal'], 0, ',', '.');
            }
            if (isset($product['keuntungan'])) {
                $product['keuntungan_formatted'] = 'Rp ' . number_format($product['keuntungan'], 0, ',', '.');
            }
            
            // Tentukan status stok
            $stock = $product['stok'] ?? 0;
            if ($stock <= 0) {
                $product['stock_status'] = 'Habis';
                $product['stock_class'] = 'out-stock';
            } elseif ($stock <= 10) {
                $product['stock_status'] = 'Stok Sedikit';
                $product['stock_class'] = 'low-stock';
            } else {
                $product['stock_status'] = 'Tersedia';
                $product['stock_class'] = 'in-stock';
            }
            
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
    }
}

// Ambil SEMUA produk dari database untuk pembeli
$sql_produk = "SELECT p.*, k.nama_kategori, u.id_user as penjual_id, u.username as penjual_username
              FROM produk p 
              LEFT JOIN katagori k ON p.id_kategori = k.id_kategori
              LEFT JOIN users u ON p.id_penjual = u.id_user
              WHERE p.stok > 0
              ORDER BY p.id_produk DESC";
$stmt_produk = $conn->prepare($sql_produk);
$stmt_produk->execute();
$result_produk = $stmt_produk->get_result();

// Ambil kategori untuk filter
$sql_kategori = "SELECT * FROM katagori ORDER BY nama_kategori";
$result_kategori = $conn->query($sql_kategori);

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

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Produk</title>
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
            --sidebar-width: 280px;
            --card-border: #e0e0e0;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
            --badge-bestseller: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            --badge-new: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
            --badge-limited: linear-gradient(135deg, #FF5722 0%, #D84315 100%);
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
            color: #000;
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

        .total-products {
            font-size: 1rem;
            color: var(--gray);
            background: #f0f4f8;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
        }

        /* Filter Bar */
        .filter-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            width: 100%;
            box-sizing: border-box;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            width: 100%;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .filter-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            box-sizing: border-box;
        }

        /* Products Grid - Mobile Card Design */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
            width: 100%;
        }

        /* Product Card */
        .product-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            position: relative;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--card-border);
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        /* Product Badge */
        .product-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
            z-index: 2;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .badge-bestseller {
            background: var(--badge-bestseller);
            color: #000;
        }

        .badge-new {
            background: var(--badge-new);
        }

        .badge-terbatas {
            background: var(--badge-limited);
        }

        .badge-habis {
            background: linear-gradient(135deg, var(--danger) 0%, #c82333 100%);
        }

        /* Stock Warning */
        .stock-warning {
            position: absolute;
            top: 15px;
            right: 15px;
            background: linear-gradient(135deg, var(--warning) 0%, #e0a800 100%);
            color: #000;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
            z-index: 2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.7; }
            50% { opacity: 1; }
            100% { opacity: 0.7; }
        }

        /* Product Image */
        .product-image-container {
            height: 200px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            width: 100%;
            cursor: pointer;
        }

        .card-set-display {
            position: relative;
            width: 150px;
            height: 150px;
        }

        .card-box {
            position: absolute;
            width: 120px;
            height: 90px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            z-index: 1;
            top: 30px;
            left: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            color: var(--secondary);
        }

        .card-1, .card-2, .card-3 {
            position: absolute;
            width: 85px;
            height: 60px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--dark);
        }

        .card-1 {
            top: 10px;
            right: 15px;
            transform: rotate(8deg);
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        }

        .card-2 {
            top: 40px;
            right: 5px;
            transform: rotate(-5deg);
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
        }

        .card-3 {
            top: 70px;
            right: 20px;
            transform: rotate(3deg);
            background: linear-gradient(135deg, #fff8e1 0%, #ffe082 100%);
        }

        .product-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .product-card:hover .product-image-container img {
            transform: scale(1.05);
        }

        /* Product Overlay untuk Quick View */
        .product-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .product-image-container:hover .product-overlay {
            opacity: 1;
        }

        .quick-view-btn {
            padding: 10px 20px;
            background: white;
            border: none;
            border-radius: 8px;
            color: var(--secondary);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        /* Product Content */
        .product-content {
            padding: 20px;
            width: 100%;
            box-sizing: border-box;
        }

        .product-category {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .product-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--primary);
            height: 3em;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.5em;
            cursor: pointer;
        }

        .product-title:hover {
            color: var(--secondary);
        }

        .product-price {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--secondary);
            margin-bottom: 15px;
            white-space: nowrap;
        }

        .product-price span {
            font-size: 1rem;
            font-weight: normal;
            color: var(--gray);
            text-decoration: line-through;
            margin-left: 8px;
        }

        /* Stock Info */
        .stock-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 8px 0;
            border-top: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
        }

        .stock-label {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 500;
        }

        .stock-value {
            font-size: 0.9rem;
            font-weight: 600;
        }

        .stock-low {
            color: var(--warning);
        }

        .stock-available {
            color: var(--success);
        }

        .stock-out {
            color: var(--danger);
        }

        /* Action Buttons Container */
        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
        }

        /* Add to Cart Button - Hanya Icon */
        .add-to-cart-btn {
            width: 50px;
            height: 50px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .add-to-cart-btn:hover:not(:disabled) {
            transform: translateY(-2px) scale(1.1);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
            background: #218838;
        }

        .add-to-cart-btn:active:not(:disabled) {
            transform: translateY(0) scale(1);
        }

        .add-to-cart-btn:disabled {
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Pesan Button - Hanya Icon */
        .pesan-btn {
            width: 50px;
            height: 50px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--info) 0%, #138496 100%);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 1.2rem;
            flex-shrink: 0;
            text-decoration: none;
        }

        .pesan-btn:hover {
            transform: translateY(-2px) scale(1.1);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.4);
            background: #138496;
        }

        .pesan-btn:active {
            transform: translateY(0) scale(1);
        }

        /* Out of Stock Text */
        .out-of-stock {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 10px auto;
            font-size: 1.2rem;
        }

        /* Form untuk keranjang */
        .add-to-cart-form {
            margin: 0;
            width: auto;
            display: flex;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e1e5eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #f0f4f8;
            color: var(--danger);
        }

        .modal-body {
            padding: 20px;
        }

        /* Quick View Grid */
        .quick-view-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        @media (min-width: 768px) {
            .quick-view-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .quick-view-image {
            width: 100%;
            height: 300px;
            border-radius: 10px;
            overflow: hidden;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quick-view-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            max-height: 300px;
        }

        .quick-view-details h4 {
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: var(--primary);
            line-height: 1.4;
        }

        .quick-view-price .price {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--secondary);
            margin-bottom: 20px;
            display: block;
        }

        .product-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--secondary);
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--gray);
            margin-bottom: 5px;
            font-weight: 500;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .info-value.stock-low {
            color: var(--warning);
        }

        .info-value.stock-available {
            color: var(--success);
        }

        .info-value.stock-out {
            color: var(--danger);
        }

        .quick-view-description h5 {
            margin: 20px 0 10px;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .quick-view-description p {
            line-height: 1.6;
            color: var(--gray);
            margin-bottom: 25px;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn-modal {
            flex: 1;
            min-width: 150px;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.95rem;
        }

        .btn-modal-cart {
            background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
            color: white;
        }

        .btn-modal-cart:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            background: #218838;
        }

        .btn-modal-cart:disabled {
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-modal-chat {
            background: linear-gradient(135deg, var(--info) 0%, #138496 100%);
            color: white;
        }

        .btn-modal-chat:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            background: #138496;
        }

        .btn-modal-close {
            background: #6c757d;
            color: white;
        }

        .btn-modal-close:hover {
            background: #5a6268;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .pagination-btn {
            width: 40px;
            height: 40px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            background: white;
            color: var(--dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .pagination-btn:hover {
            border-color: var(--secondary);
            color: var(--secondary);
        }

        .pagination-btn.active {
            background: var(--secondary);
            color: white;
            border-color: var(--secondary);
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 10000;
            max-width: 350px;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            border-left: 4px solid var(--success);
        }

        .notification.error {
            border-left: 4px solid var(--danger);
        }

        .notification.info {
            border-left: 4px solid var(--secondary);
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #343a40;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }

        .empty-state p {
            color: #6c757d;
            font-size: 1rem;
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

        /* Loading Animation untuk Modal */
        .modal-loading {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 200px;
        }

        .modal-loading i {
            font-size: 2rem;
            color: var(--secondary);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
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
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 15px;
            }
            
            .main-content {
                padding: 20px 15px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                gap: 10px;
            }

            .add-to-cart-btn,
            .pesan-btn {
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }

            .notifications-dropdown {
                position: fixed;
                top: 70px;
                right: 0;
                left: 0;
                width: 100%;
                border-radius: 0 0 10px 10px;
            }

            .modal-content {
                width: 95%;
                margin: 10px;
                max-height: 85vh;
            }

            .quick-view-grid {
                grid-template-columns: 1fr;
            }

            .quick-view-image {
                height: 250px;
            }

            .modal-actions {
                flex-direction: column;
            }

            .btn-modal {
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
            
            .products-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            
            .product-image-container {
                height: 150px;
            }
            
            .card-set-display {
                width: 120px;
                height: 120px;
            }
            
            .card-box {
                width: 90px;
                height: 70px;
                font-size: 2rem;
                top: 25px;
                left: 10px;
            }
            
            .card-1, .card-2, .card-3 {
                width: 65px;
                height: 45px;
                font-size: 1.2rem;
            }
            
            .card-1 {
                top: 5px;
                right: 10px;
            }
            
            .card-2 {
                top: 30px;
                right: 5px;
            }
            
            .card-3 {
                top: 55px;
                right: 15px;
            }
            
            .product-content {
                padding: 15px;
            }
            
            .product-title {
                font-size: 0.95rem;
                height: 2.8em;
            }
            
            .product-price {
                font-size: 1.2rem;
            }
            
            .action-buttons {
                gap: 8px;
            }

            .add-to-cart-btn,
            .pesan-btn {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .out-of-stock {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .user-details {
                display: none;
            }

            .product-info-grid {
                grid-template-columns: 1fr;
            }

            .quick-view-image {
                height: 200px;
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
            <div class="search-bar">
                <input type="text" placeholder="Cari produk..." id="globalSearch">
                <i class="fas fa-search search-icon"></i>
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
                <a href="produk.php" class="sidebar-item active">
                    <i class="fas fa-shopping-bag"></i>
                    <span>Produk</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Account</div>
                <a href="status.php" class="sidebar-item">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Status</span>
                </a>
                <a href="chat.php" class="sidebar-item">
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
                <a href="help.php" class="sidebar-item">
                    <i class="fas fa-question-circle"></i>
                    <span>Help Center</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="content-header">
            <h1 class="page-title">Semua Produk</h1>
            <div class="total-products" id="productCounter">0 produk tersedia</div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-container">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Kategori</label>
                    <select class="filter-select" id="categoryFilter">
                        <option value="">Semua Kategori</option>
                        <?php 
                        $result_kategori->data_seek(0);
                        while ($kategori = $result_kategori->fetch_assoc()): ?>
                            <option value="<?php echo $kategori['id_kategori']; ?>">
                                <?php echo htmlspecialchars($kategori['nama_kategori']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Urutkan</label>
                    <select class="filter-select" id="sortFilter">
                        <option value="newest">Terbaru</option>
                        <option value="price-low">Harga Terendah</option>
                        <option value="price-high">Harga Tertinggi</option>
                        <option value="name-asc">Nama A-Z</option>
                        <option value="name-desc">Nama Z-A</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="products-grid" id="productsGrid">
            <?php if ($result_produk->num_rows > 0): ?>
                <?php 
                $result_produk->data_seek(0);
                $product_counter = 0;
                while ($produk = $result_produk->fetch_assoc()): 
                    $product_counter++;
                    
                    // Cek kondisi stok
                    $stok = $produk['stok'];
                    $is_out_of_stock = $stok <= 0;
                    $is_low_stock = $stok > 0 && $stok <= 10;
                    $is_available = $stok > 10;
                    
                    // Tentukan badge berdasarkan kondisi
                    $badge_class = '';
                    $badge_text = '';
                    
                    if ($is_out_of_stock) {
                        $badge_class = 'badge-habis';
                        $badge_text = 'HABIS';
                    } elseif ($is_low_stock) {
                        $badge_class = 'badge-terbatas';
                        $badge_text = 'TERBATAS';
                    } elseif ($product_counter <= 5) {
                        $badge_class = 'badge-new';
                        $badge_text = 'BARU';
                    } elseif ($produk['keuntungan'] > 50000 || $stok > 50) {
                        $badge_class = 'badge-bestseller';
                        $badge_text = 'BESTSELLER';
                    }
                    
                    // Format harga
                    $harga_jual = number_format($produk['harga_jual'], 0, ',', '.');
                    
                    // Dapatkan path gambar produk
                    $image_path = getProductImagePath($produk['gambar']);
                    
                    // Tentukan warna stok
                    $stock_class = $is_out_of_stock ? 'stock-out' : ($is_low_stock ? 'stock-low' : 'stock-available');
                    $stock_text = $is_out_of_stock ? 'Habis' : ($is_low_stock ? 'Menipis' : 'Tersedia');
                    
                    // Data penjual untuk tombol pesan
                    $penjual_id = $produk['penjual_id'] ?? 0;
                    $penjual_username = $produk['penjual_username'] ?? 'Penjual';
                ?>
                    <div class="product-card" 
                         data-id="<?php echo $produk['id_produk']; ?>"
                         data-category="<?php echo $produk['id_kategori']; ?>"
                         data-price="<?php echo $produk['harga_jual']; ?>"
                         data-name="<?php echo htmlspecialchars($produk['nama_produk']); ?>"
                         data-stock="<?php echo $stok; ?>"
                         data-penjual-id="<?php echo $penjual_id; ?>"
                         data-penjual-name="<?php echo htmlspecialchars($penjual_username); ?>">
                        
                        <?php if ($badge_text): ?>
                            <div class="product-badge <?php echo $badge_class; ?>">
                                <?php echo $badge_text; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($is_low_stock && !$is_out_of_stock): ?>
                            <div class="stock-warning">Stok Menipis!</div>
                        <?php endif; ?>
                        
                        <div class="product-image-container" onclick="showProductDetail(<?php echo $produk['id_produk']; ?>)">
                            <?php if ($image_path): ?>
                                <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($produk['nama_produk']); ?>">
                                <div class="product-overlay">
                                    <button class="quick-view-btn">
                                        <i class="fas fa-eye"></i> Lihat Detail
                                    </button>
                                </div>
                            <?php else: ?>
                                <!-- Collectible Card Set Display -->
                                <div class="card-set-display">
                                    <div class="card-box">
                                        <i class="fas fa-box-open"></i>
                                    </div>
                                    <div class="card-1">
                                        <i class="fas fa-heart"></i>
                                    </div>
                                    <div class="card-2">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="card-3">
                                        <i class="fas fa-gem"></i>
                                    </div>
                                </div>
                                <div class="product-overlay">
                                    <button class="quick-view-btn">
                                        <i class="fas fa-eye"></i> Lihat Detail
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-content">
                            <div class="product-category">
                                <?php echo htmlspecialchars($produk['nama_kategori'] ?: 'Koleksi'); ?>
                            </div>
                            <h3 class="product-title" title="<?php echo htmlspecialchars($produk['nama_produk']); ?>" 
                                onclick="showProductDetail(<?php echo $produk['id_produk']; ?>)">
                                <?php echo htmlspecialchars($produk['nama_produk']); ?>
                            </h3>
                            
                            <div class="stock-info">
                                <span class="stock-label">Stok:</span>
                                <span class="stock-value <?php echo $stock_class; ?>">
                                    <?php echo $stok; ?> <?php echo $stock_text; ?>
                                </span>
                            </div>
                            
                            <div class="action-buttons">
                                <?php if ($is_out_of_stock): ?>
                                    <!-- Tampilkan icon disabled jika stok habis -->
                                    <div class="out-of-stock">
                                        <i class="fas fa-ban"></i>
                                    </div>
                                <?php else: ?>
                                    <!-- Form untuk tambah ke keranjang jika stok masih ada -->
                                    <form method="POST" class="add-to-cart-form" onsubmit="return addToCart(this, <?php echo $produk['id_produk']; ?>, '<?php echo htmlspecialchars(addslashes($produk['nama_produk'])); ?>', <?php echo $stok; ?>)">
                                        <input type="hidden" name="add_to_cart" value="1">
                                        <input type="hidden" name="product_id" value="<?php echo $produk['id_produk']; ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="add-to-cart-btn" <?php echo $is_out_of_stock ? 'disabled' : ''; ?>>
                                            <i class="fas fa-cart-plus"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <!-- Tombol Pesan -->
                                <?php if ($penjual_id > 0): ?>
                                    <a href="chat.php?penjual_id=<?php echo $penjual_id; ?>&produk_id=<?php echo $produk['id_produk']; ?>" 
                                       class="pesan-btn" 
                                       title="Tanya Penjual (<?php echo htmlspecialchars($penjual_username); ?>)">
                                        <i class="fas fa-comment-dots"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="chat.php" class="pesan-btn" title="Chat Penjual">
                                        <i class="fas fa-comment-dots"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h3>Tidak ada produk tersedia</h3>
                    <p>Maaf, saat ini belum ada produk yang dapat ditampilkan.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <div class="pagination" id="pagination">
            <!-- Pagination akan di-generate oleh JavaScript -->
        </div>
    </main>

    <!-- Modal Detail Produk -->
    <div class="modal" id="productDetailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Detail Produk</h3>
                <button class="modal-close" onclick="closeModal('productDetailModal')">&times;</button>
            </div>
            <div class="modal-body" id="productDetailContent">
                <!-- Content akan diisi secara dinamis -->
            </div>
        </div>
    </div>

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
                
                // Update sidebar badge
                const sidebarBadge = document.getElementById('sidebarNotifBadge');
                if (sidebarBadge) {
                    sidebarBadge.textContent = unreadCount;
                    if (unreadCount > 0) {
                        sidebarBadge.style.display = 'flex';
                    } else {
                        sidebarBadge.style.display = 'none';
                    }
                }
                
                // Update dashboard stat
                const dashboardStat = document.getElementById('dashboardNotifCount');
                if (dashboardStat) {
                    dashboardStat.textContent = unreadCount;
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
                
                // Update sidebar cart badge
                const sidebarCartBadge = document.getElementById('sidebarCartBadge');
                if (sidebarCartBadge) {
                    sidebarCartBadge.textContent = cartCount;
                    if (cartCount > 0) {
                        sidebarCartBadge.style.display = 'flex';
                    } else {
                        sidebarCartBadge.style.display = 'none';
                    }
                }
                
                // Update dashboard cart stat
                const dashboardCartStat = document.getElementById('dashboardCartCount');
                if (dashboardCartStat) {
                    dashboardCartStat.textContent = cartCount;
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
                
                // Update navbar chat badge (jika ada)
                const navChatBadge = document.getElementById('chatBadge');
                if (navChatBadge) {
                    navChatBadge.textContent = unreadMessagesCount;
                    if (unreadMessagesCount > 0) {
                        navChatBadge.style.display = 'flex';
                    } else {
                        navChatBadge.style.display = 'none';
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
        
        // Global function untuk menampilkan detail produk
        async function showProductDetail(productId) {
            try {
                // Tampilkan modal loading
                const modalContent = document.getElementById('productDetailContent');
                modalContent.innerHTML = `
                    <div class="modal-loading">
                        <i class="fas fa-spinner fa-spin"></i> Memuat detail produk...
                    </div>
                `;
                
                // Tampilkan modal
                openModal('productDetailModal');
                
                // Ambil data produk dari server
                const response = await fetch('produk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_product_detail&id=${productId}`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const product = data.product;
                    const isOutOfStock = product.stok <= 0;
                    const isLowStock = product.stok > 0 && product.stok <= 10;
                    
                    // Buat konten modal
                    modalContent.innerHTML = `
                        <div class="quick-view-grid">
                            <div class="quick-view-image">
                                ${product.gambar_path ? 
                                    `<img src="${product.gambar_path}" alt="${product.nama_produk}">` : 
                                    `<i class="fas fa-box-open" style="font-size: 4rem; color: #adb5bd;"></i>`
                                }
                            </div>
                            <div class="quick-view-details">
                                <h4>${product.nama_produk}</h4>
                                <div class="price">${product.harga_formatted}</div>
                                
                                <div class="product-info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Kategori</div>
                                        <div class="info-value">${product.nama_kategori || 'Tidak dikategorikan'}</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Stok</div>
                                        <div class="info-value ${product.stock_class}">${product.stok} ${product.stock_status}</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Penjual</div>
                                        <div class="info-value">${product.penjual_username || 'Tidak diketahui'}</div>
                                    </div>
                                    ${product.modal ? `
                                    <div class="info-item">
                                        <div class="info-label">Harga Modal</div>
                                        <div class="info-value">${product.modal_formatted}</div>
                                    </div>
                                    ` : ''}
                                    ${product.keuntungan ? `
                                    <div class="info-item">
                                        <div class="info-label">Keuntungan</div>
                                        <div class="info-value">${product.keuntungan_formatted}</div>
                                    </div>
                                    ` : ''}
                                </div>
                                
                                ${product.deskripsi ? `
                                <div class="quick-view-description">
                                    <h5>Deskripsi Produk</h5>
                                    <p>${product.deskripsi}</p>
                                </div>
                                ` : ''}
                                
                                ${isLowStock ? `
                                <div style="background: rgba(255, 193, 7, 0.1); padding: 10px; border-radius: 8px; border-left: 4px solid var(--warning); margin-bottom: 20px;">
                                    <i class="fas fa-exclamation-triangle" style="color: var(--warning); margin-right: 8px;"></i>
                                    <span style="color: var(--dark); font-weight: 500;">Stok produk ini menipis! Hanya tersisa ${product.stok} buah.</span>
                                </div>
                                ` : ''}
                                
                                ${isOutOfStock ? `
                                <div style="background: rgba(220, 53, 69, 0.1); padding: 10px; border-radius: 8px; border-left: 4px solid var(--danger); margin-bottom: 20px;">
                                    <i class="fas fa-exclamation-circle" style="color: var(--danger); margin-right: 8px;"></i>
                                    <span style="color: var(--dark); font-weight: 500;">Maaf, stok produk ini sudah habis.</span>
                                </div>
                                ` : ''}
                                
                                <div class="modal-actions">
                                    ${!isOutOfStock ? `
                                    <form method="POST" class="add-to-cart-form" onsubmit="return addToCartModal(this, ${product.id_produk}, '${product.nama_produk.replace(/'/g, "\\'")}', ${product.stok})">
                                        <input type="hidden" name="add_to_cart" value="1">
                                        <input type="hidden" name="product_id" value="${product.id_produk}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="btn-modal btn-modal-cart" ${isOutOfStock ? 'disabled' : ''}>
                                            <i class="fas fa-cart-plus"></i>
                                            Tambah ke Keranjang
                                        </button>
                                    </form>
                                    ` : `
                                    <button class="btn-modal btn-modal-cart" disabled>
                                        <i class="fas fa-ban"></i>
                                        Stok Habis
                                    </button>
                                    `}
                                    
                                    ${product.penjual_id ? `
                                    <a href="chat.php?penjual_id=${product.penjual_id}&produk_id=${product.id_produk}" 
                                       class="btn-modal btn-modal-chat">
                                        <i class="fas fa-comment-dots"></i>
                                        Tanya Penjual
                                    </a>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    modalContent.innerHTML = `
                        <div style="text-align: center; padding: 40px 20px;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: var(--danger); margin-bottom: 20px;"></i>
                            <h3 style="color: var(--dark); margin-bottom: 10px;">Produk Tidak Ditemukan</h3>
                            <p style="color: var(--gray); margin-bottom: 25px;">Maaf, produk yang Anda cari tidak dapat ditemukan.</p>
                            <button class="btn-modal btn-modal-close" onclick="closeModal('productDetailModal')">
                                <i class="fas fa-times"></i>
                                Tutup
                            </button>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error:', error);
                const modalContent = document.getElementById('productDetailContent');
                modalContent.innerHTML = `
                    <div style="text-align: center; padding: 40px 20px;">
                        <i class="fas fa-exclamation-circle" style="font-size: 3rem; color: var(--danger); margin-bottom: 20px;"></i>
                        <h3 style="color: var(--dark); margin-bottom: 10px;">Terjadi Kesalahan</h3>
                        <p style="color: var(--gray); margin-bottom: 25px;">Gagal memuat detail produk. Silakan coba lagi.</p>
                        <button class="btn-modal btn-modal-close" onclick="closeModal('productDetailModal')">
                            <i class="fas fa-times"></i>
                            Tutup
                        </button>
                    </div>
                `;
            }
        }

        // Function untuk menambahkan ke keranjang dari modal
        function addToCartModal(form, productId, productName, stock) {
            return addToCart(form, productId, productName, stock);
        }

        // Function untuk menampilkan modal
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        // Function untuk menutup modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal saat klik di luar
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                closeModal(e.target.id);
            }
        });

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
            
            // Setup pagination
            setupPagination();
            updateProductCounter();
            
            // Check if product was added from URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('added') === 'true') {
                const productId = urlParams.get('product_id');
                if (productId) {
                    // Refresh cart count
                    if (window.realtimeBadgeSystem) {
                        window.realtimeBadgeSystem.refresh();
                    }
                    
                    // Remove parameter from URL
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            }
        });
        
        // Global function untuk digunakan di halaman lain
        function addToCart(form, productId, productName, stock) {
            // Cek stok sebelum menambahkan
            if (stock <= 0) {
                showNotification('Maaf, stok produk ini sudah habis', 'error');
                return false;
            }
            
            if (stock <= 10) {
                // Tampilkan konfirmasi untuk stok menipis
                if (!confirm('⚠️ Stok produk ini menipis! Hanya tersisa ' + stock + ' buah.\n\nTetap tambahkan ke keranjang?')) {
                    return false;
                }
            }
            
            // Kirim ke API
            const formData = new FormData();
            formData.append('action', 'add_to_cart');
            formData.append('product_id', productId);
            formData.append('quantity', 1);
            
            fetch('api_notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh cart count
                    if (window.realtimeBadgeSystem) {
                        window.realtimeBadgeSystem.refresh();
                    }
                    
                    // Tampilkan notifikasi
                    showNotification(`${productName} ditambahkan ke keranjang!`, 'success');
                    
                    // Update stok di tampilan
                    updateProductStockDisplay(productId, stock - 1);
                    
                    // Tutup modal jika terbuka
                    closeModal('productDetailModal');
                    
                    // Trigger custom event
                    document.dispatchEvent(new CustomEvent('productAddedToCart', {
                        detail: { cartCount: data.data?.cart_count || 0 }
                    }));
                } else {
                    showNotification('Gagal menambahkan ke keranjang: ' + (data.message || 'Terjadi kesalahan'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Terjadi kesalahan saat menambahkan ke keranjang', 'error');
            });
            
            return false; // Prevent default form submission
        }

        // Fungsi untuk update tampilan stok setelah ditambahkan ke keranjang
        function updateProductStockDisplay(productId, newStock) {
            const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
            if (!productCard) return;
            
            // Update data attribute
            productCard.setAttribute('data-stock', newStock);
            
            // Update display stok
            const stockValue = productCard.querySelector('.stock-value');
            const addToCartBtn = productCard.querySelector('.add-to-cart-btn');
            const stockWarning = productCard.querySelector('.stock-warning');
            const badge = productCard.querySelector('.product-badge');
            
            if (newStock <= 0) {
                // Stok habis
                stockValue.textContent = '0 Habis';
                stockValue.className = 'stock-value stock-out';
                
                // Disable tombol dan ganti dengan icon disabled
                if (addToCartBtn) {
                    const form = addToCartBtn.closest('form');
                    const actionButtons = form.closest('.action-buttons');
                    
                    // Ganti form dengan icon disabled
                    form.remove();
                    
                    // Tambahkan icon disabled
                    const outOfStockDiv = document.createElement('div');
                    outOfStockDiv.className = 'out-of-stock';
                    outOfStockDiv.innerHTML = '<i class="fas fa-ban"></i>';
                    actionButtons.prepend(outOfStockDiv);
                }
                
                // Update badge
                if (badge) {
                    badge.className = 'product-badge badge-habis';
                    badge.textContent = 'HABIS';
                } else {
                    // Buat badge baru
                    const newBadge = document.createElement('div');
                    newBadge.className = 'product-badge badge-habis';
                    newBadge.textContent = 'HABIS';
                    productCard.querySelector('.product-image-container').prepend(newBadge);
                }
                
                // Hapus warning stok menipis jika ada
                if (stockWarning) {
                    stockWarning.remove();
                }
                
            } else if (newStock <= 10) {
                // Stok menipis
                stockValue.textContent = newStock + ' Menipis';
                stockValue.className = 'stock-value stock-low';
                
                // Update badge
                if (badge) {
                    badge.className = 'product-badge badge-terbatas';
                    badge.textContent = 'TERBATAS';
                } else {
                    const newBadge = document.createElement('div');
                    newBadge.className = 'product-badge badge-terbatas';
                    newBadge.textContent = 'TERBATAS';
                    productCard.querySelector('.product-image-container').prepend(newBadge);
                }
                
                // Tambah/tampilkan warning stok menipis
                if (!stockWarning) {
                    const warning = document.createElement('div');
                    warning.className = 'stock-warning';
                    warning.textContent = 'Stok Menipis!';
                    productCard.querySelector('.product-image-container').appendChild(warning);
                }
                
            } else {
                // Stok normal
                stockValue.textContent = newStock + ' Tersedia';
                stockValue.className = 'stock-value stock-available';
                
                // Hapus warning stok menipis jika ada
                if (stockWarning) {
                    stockWarning.remove();
                }
                
                // Update badge jika masih ada badge stok menipis
                if (badge && badge.classList.contains('badge-terbatas')) {
                    badge.remove();
                }
            }
        }
        
        function refreshBadges() {
            if (window.realtimeBadgeSystem) {
                window.realtimeBadgeSystem.refresh();
            }
        }

        // Cart icon click event
        document.getElementById('cartIcon').addEventListener('click', function() {
            window.location.href = 'keranjang.php';
        });

        // Search functionality
        document.getElementById('globalSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const products = document.querySelectorAll('.product-card');
            
            products.forEach(product => {
                const title = product.querySelector('.product-title').textContent.toLowerCase();
                const category = product.querySelector('.product-category').textContent.toLowerCase();
                const isVisible = title.includes(searchTerm) || category.includes(searchTerm);
                product.style.display = isVisible ? 'block' : 'none';
            });
            
            // Update product counter
            updateProductCounter();
        });

        // Filter functionality
        document.getElementById('categoryFilter').addEventListener('change', applyFilters);
        document.getElementById('sortFilter').addEventListener('change', applyFilters);

        function applyFilters() {
            const category = document.getElementById('categoryFilter').value;
            const sort = document.getElementById('sortFilter').value;
            
            const products = Array.from(document.querySelectorAll('.product-card'));
            
            // Filter produk
            products.forEach(product => {
                const productCategory = product.getAttribute('data-category');
                
                let isVisible = true;
                
                // Filter kategori
                if (category && productCategory !== category) {
                    isVisible = false;
                }
                
                product.style.display = isVisible ? 'block' : 'none';
            });
            
            // Sort produk yang visible
            const visibleProducts = products.filter(p => p.style.display !== 'none');
            
            visibleProducts.sort((a, b) => {
                const aPrice = parseFloat(a.getAttribute('data-price')) || 0;
                const bPrice = parseFloat(b.getAttribute('data-price')) || 0;
                const aName = a.getAttribute('data-name').toLowerCase();
                const bName = b.getAttribute('data-name').toLowerCase();
                
                switch(sort) {
                    case 'newest':
                        return 0; // Default order
                    case 'price-low':
                        return aPrice - bPrice;
                    case 'price-high':
                        return bPrice - aPrice;
                    case 'name-asc':
                        return aName.localeCompare(bName);
                    case 'name-desc':
                        return bName.localeCompare(aName);
                    default:
                        return 0;
                }
            });
            
            // Reorder products in DOM
            const productsGrid = document.getElementById('productsGrid');
            visibleProducts.forEach(product => {
                productsGrid.appendChild(product);
            });
            
            // Update product counter
            updateProductCounter();
        }
        
        function updateProductCounter() {
            const visibleProducts = document.querySelectorAll('.product-card[style="display: block"]').length || 
                                   document.querySelectorAll('.product-card').length;
            document.getElementById('productCounter').textContent = `${visibleProducts} produk tersedia`;
        }

        // Function untuk menampilkan notifikasi
        function showNotification(message, type = 'info') {
            // Buat elemen notifikasi
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            // Tambahkan ke body
            document.body.appendChild(notification);
            
            // Animasi masuk
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Hapus setelah 3 detik
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        // Simple pagination
        function setupPagination() {
            const productsPerPage = 12;
            const products = document.querySelectorAll('.product-card');
            const totalPages = Math.ceil(products.length / productsPerPage);
            const paginationContainer = document.getElementById('pagination');
            
            if (totalPages <= 1) {
                paginationContainer.style.display = 'none';
                return;
            }
            
            let currentPage = 1;
            
            function showPage(page) {
                const start = (page - 1) * productsPerPage;
                const end = start + productsPerPage;
                
                products.forEach((product, index) => {
                    if (index >= start && index < end) {
                        product.style.display = 'block';
                    } else {
                        product.style.display = 'none';
                    }
                });
                
                updatePaginationButtons(page);
            }
            
            function updatePaginationButtons(page) {
                paginationContainer.innerHTML = '';
                
                // Previous button
                if (page > 1) {
                    const prevBtn = document.createElement('button');
                    prevBtn.className = 'pagination-btn';
                    prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
                    prevBtn.onclick = () => {
                        currentPage--;
                        showPage(currentPage);
                    };
                    paginationContainer.appendChild(prevBtn);
                }
                
                // Page numbers
                for (let i = 1; i <= totalPages; i++) {
                    const pageBtn = document.createElement('button');
                    pageBtn.className = `pagination-btn ${i === page ? 'active' : ''}`;
                    pageBtn.textContent = i;
                    pageBtn.onclick = () => {
                        currentPage = i;
                        showPage(currentPage);
                    };
                    paginationContainer.appendChild(pageBtn);
                }
                
                // Next button
                if (page < totalPages) {
                    const nextBtn = document.createElement('button');
                    nextBtn.className = 'pagination-btn';
                    nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
                    nextBtn.onclick = () => {
                        currentPage++;
                        showPage(currentPage);
                    };
                    paginationContainer.appendChild(nextBtn);
                }
            }
            
            // Initialize
            showPage(1);
        }
    </script>
</body>
</html>