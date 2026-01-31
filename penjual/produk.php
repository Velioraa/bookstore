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

// Handle AJAX requests first
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_stock') {
        $product_id = $_POST['id'] ?? '';
        $new_stock = $_POST['stok'] ?? '';
        
        if (empty($product_id) || !is_numeric($new_stock)) {
            echo json_encode(['success' => false, 'message' => 'Invalid data']);
            exit();
        }
        
        // Update stok
        $sql_update = "UPDATE produk SET stok = ? WHERE id_produk = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ii", $new_stock, $product_id);
        
        if ($stmt_update->execute()) {
            // Get updated product data
            $sql_get = "SELECT p.*, k.nama_kategori FROM produk p 
                       LEFT JOIN katagori k ON p.id_kategori = k.id_kategori 
                       WHERE p.id_produk = ?";
            $stmt_get = $conn->prepare($sql_get);
            $stmt_get->bind_param("i", $product_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();
            $product = $result_get->fetch_assoc();
            
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        
        $stmt_update->close();
        exit();
        
    } elseif ($action === 'get_product') {
        $product_id = $_POST['id'] ?? '';
        
        $sql = "SELECT p.*, k.nama_kategori FROM produk p 
                LEFT JOIN katagori k ON p.id_kategori = k.id_kategori 
                WHERE p.id_produk = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $product = $result->fetch_assoc();
            $product['gambar_path'] = getProductImagePath($product['gambar']);
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
        
    } elseif ($action === 'update_product_with_image') {
        $id = $_POST['id'] ?? '';
        $nama = $_POST['nama'] ?? '';
        $harga = $_POST['harga'] ?? '';
        $stok = $_POST['stok'] ?? '';
        $kategori = $_POST['kategori'] ?? '';
        $deskripsi = $_POST['deskripsi'] ?? '';
        
        // Handle file upload jika ada
        $gambar_filename = null;
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../asset/uploads/';
            
            // Buat direktori jika belum ada
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION);
            $gambar_filename = uniqid('produk_') . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $gambar_filename;
            
            // Validasi file
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $file_type = $_FILES['gambar']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                if (move_uploaded_file($_FILES['gambar']['tmp_name'], $upload_path)) {
                    // Hapus file lama jika ada
                    $sql_get_old = "SELECT gambar FROM produk WHERE id_produk = ?";
                    $stmt_get_old = $conn->prepare($sql_get_old);
                    $stmt_get_old->bind_param("i", $id);
                    $stmt_get_old->execute();
                    $result_old = $stmt_get_old->get_result();
                    if ($row_old = $result_old->fetch_assoc()) {
                        if (!empty($row_old['gambar'])) {
                            $old_path = getProductImagePath($row_old['gambar']);
                            if ($old_path && file_exists($old_path)) {
                                unlink($old_path);
                            }
                        }
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gagal mengupload gambar']);
                    exit();
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Format file tidak didukung']);
                exit();
            }
        }
        
        // Update query berdasarkan apakah ada file baru
        if ($gambar_filename) {
            $sql = "UPDATE produk SET 
                    nama_produk = ?, 
                    harga_jual = ?, 
                    stok = ?, 
                    id_kategori = ?, 
                    deskripsi = ?,
                    gambar = ?
                    WHERE id_produk = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("siiissi", $nama, $harga, $stok, $kategori, $deskripsi, $gambar_filename, $id);
        } else {
            $sql = "UPDATE produk SET 
                    nama_produk = ?, 
                    harga_jual = ?, 
                    stok = ?, 
                    id_kategori = ?, 
                    deskripsi = ?
                    WHERE id_produk = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("siiisi", $nama, $harga, $stok, $kategori, $deskripsi, $id);
        }
        
        if ($stmt->execute()) {
            // Ambil data terbaru untuk dikembalikan
            $sql = "SELECT p.*, k.nama_kategori FROM produk p 
                    LEFT JOIN katagori k ON p.id_kategori = k.id_kategori 
                    WHERE p.id_produk = ?";
            $stmt2 = $conn->prepare($sql);
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $result = $stmt2->get_result();
            $product = $result->fetch_assoc();
            
            // Tambahkan path gambar untuk response
            $product['gambar_path'] = getProductImagePath($product['gambar']);
            
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit();
        
    } elseif ($action === 'quick_view') {
        $product_id = $_POST['id'] ?? '';
        
        $sql = "SELECT p.*, k.nama_kategori FROM produk p 
                LEFT JOIN katagori k ON p.id_kategori = k.id_kategori 
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
            
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
        
    } elseif ($action === 'search_products') {
        $search = $_POST['search'] ?? '';
        
        // Build query
        $sql = "SELECT p.*, k.nama_kategori FROM produk p 
                LEFT JOIN katagori k ON p.id_kategori = k.id_kategori 
                WHERE 1=1";
        
        // Filter berdasarkan penjual (kecuali super admin)
        if ($role != 'super_admin') {
            $sql .= " AND p.id_penjual = $id_penjual";
        }
        
        // Search
        if (!empty($search)) {
            $sql .= " AND (p.nama_produk LIKE '%$search%' 
                     OR p.deskripsi LIKE '%$search%' 
                     OR k.nama_kategori LIKE '%$search%')";
        }
        
        $sql .= " ORDER BY p.id_produk DESC";
        
        $result = $conn->query($sql);
        $products = [];
        
        while ($row = $result->fetch_assoc()) {
            // Cek path gambar untuk setiap produk
            $row['gambar_path'] = getProductImagePath($row['gambar']);
            $products[] = $row;
        }
        
        echo json_encode(['success' => true, 'products' => $products]);
        exit();
        
    } elseif ($action === 'delete_product') {
        $product_id = $_POST['id'] ?? '';
        
        if (empty($product_id)) {
            echo json_encode(['success' => false, 'message' => 'ID produk tidak valid']);
            exit();
        }
        
        // Ambil data produk untuk menghapus gambar
        $sql_get = "SELECT gambar FROM produk WHERE id_produk = ?";
        $stmt_get = $conn->prepare($sql_get);
        $stmt_get->bind_param("i", $product_id);
        $stmt_get->execute();
        $result_get = $stmt_get->get_result();
        
        if ($result_get->num_rows > 0) {
            $product = $result_get->fetch_assoc();
            
            // Hapus gambar produk jika ada
            if (!empty($product['gambar'])) {
                $image_path = getProductImagePath($product['gambar']);
                if ($image_path && file_exists($image_path)) {
                    unlink($image_path);
                }
            }
        }
        
        // Hapus produk dari database
        $sql_delete = "DELETE FROM produk WHERE id_produk = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $product_id);
        
        if ($stmt_delete->execute()) {
            echo json_encode(['success' => true, 'message' => 'Produk berhasil dihapus']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus produk dari database']);
        }
        exit();
    }
}

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Ambil data user
    $username = $user['username'];
    $role = $user['role'];
    $gambar = $user['gambar'];
    $email = $user['email'];
    $id_penjual = $user['id_user']; // ID penjual untuk filter produk
    
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
    if (!$has_image && !empty($gambar)) {
        // Coba path lain
        $gambar_path = getProductImagePath($gambar);
        $has_image = ($gambar_path !== null);
    }
    
    // Ambil data produk dari database berdasarkan penjual
    // Jika super admin, tampilkan semua produk
    // Jika penjual, hanya tampilkan produk miliknya saja
    if ($role == 'super_admin') {
        $sql_produk = "SELECT p.*, k.nama_kategori 
                      FROM produk p 
                      LEFT JOIN katagori k ON p.id_kategori = k.id_kategori
                      ORDER BY p.id_produk DESC";
        $stmt_produk = $conn->prepare($sql_produk);
    } else {
        $sql_produk = "SELECT p.*, k.nama_kategori 
                      FROM produk p 
                      LEFT JOIN katagori k ON p.id_kategori = k.id_kategori
                      WHERE p.id_penjual = ?
                      ORDER BY p.id_produk DESC";
        $stmt_produk = $conn->prepare($sql_produk);
        $stmt_produk->bind_param("s", $id_penjual);
    }
    
    $stmt_produk->execute();
    $result_produk = $stmt_produk->get_result();
    
    // Hitung statistik produk
    $total_produk = 0;
    $total_stok = 0;
    $produk_habis = 0;
    $produk_modal = 0;
    $produk_untung = 0;
    
    while ($row = $result_produk->fetch_assoc()) {
        $total_produk++;
        $total_stok += $row['stok'];
        $produk_modal += $row['modal'];
        $produk_untung += $row['keuntungan'];
        if ($row['stok'] <= 0) {
            $produk_habis++;
        }
    }
    
    // Reset pointer result untuk digunakan lagi
    if ($role == 'super_admin') {
        $sql_produk = "SELECT p.*, k.nama_kategori 
                      FROM produk p 
                      LEFT JOIN katagori k ON p.id_kategori = k.id_kategori
                      ORDER BY p.id_produk DESC";
        $stmt_produk = $conn->prepare($sql_produk);
        $stmt_produk->execute();
    } else {
        $sql_produk = "SELECT p.*, k.nama_kategori 
                      FROM produk p 
                      LEFT JOIN katagori k ON p.id_kategori = k.id_kategori
                      WHERE p.id_penjual = ?
                      ORDER BY p.id_produk DESC";
        $stmt_produk = $conn->prepare($sql_produk);
        $stmt_produk->bind_param("s", $id_penjual);
        $stmt_produk->execute();
    }
    
    $result_produk = $stmt_produk->get_result();
    
    // Ambil kategori untuk filter
    $sql_kategori = "SELECT * FROM katagori ORDER BY nama_kategori";
    $result_kategori = $conn->query($sql_kategori);
    
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

/* Products Header dengan Create Button */
.products-header {
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    width: 100%;
    box-sizing: border-box;
}

.products-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.products-title h1 {
    font-size: 1.8rem;
    margin-bottom: 10px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.products-title p {
    opacity: 0.9;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.header-actions {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    white-space: nowrap;
    flex-shrink: 0;
    text-decoration: none;
}

.btn-primary {
    background: white;
    color: var(--secondary);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(255, 255, 255, 0.2);
}

.btn-create {
    background: var(--success);
    color: white;
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    white-space: nowrap;
    flex-shrink: 0;
    text-decoration: none;
}

.btn-create:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
    background: #218838;
}

/* Products Stats */
.products-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    width: 100%;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    transition: transform 0.3s ease;
    width: 100%;
    box-sizing: border-box;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-label {
    color: var(--gray);
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Products Filter */
.products-filter {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}

.filter-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.filter-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.filter-reset {
    background: none;
    border: none;
    color: var(--secondary);
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    flex-shrink: 0;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    width: 100%;
}

.filter-group {
    margin-bottom: 15px;
    min-width: 0;
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

/* Search Products */
.search-products {
    background: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    width: 100%;
    box-sizing: border-box;
}

.search-container {
    position: relative;
    width: 100%;
}

.search-container input {
    width: 100%;
    padding: 15px 50px 15px 20px;
    border: 2px solid #e1e5eb;
    border-radius: 12px;
    font-size: 16px;
    box-sizing: border-box;
}

.search-container input:focus {
    outline: none;
    border-color: var(--secondary);
}

.search-container i {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--gray);
    font-size: 1.2rem;
}

/* Products Grid */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
    width: 100%;
}

.product-item {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    position: relative;
    width: 100%;
    box-sizing: border-box;
}

.product-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

.product-badge {
    position: absolute;
    top: 15px;
    left: 15px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    z-index: 2;
    white-space: nowrap;
}

.badge-new {
    background: var(--secondary);
    color: white;
}

.badge-bestseller {
    background: var(--warning);
    color: var(--dark);
}

.badge-sale {
    background: var(--danger);
    color: white;
}

.badge-stok {
    background: var(--warning);
    color: var(--dark);
}

.product-image {
    height: 200px;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: 3rem;
    position: relative;
    overflow: hidden;
    width: 100%;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.product-item:hover .product-image img {
    transform: scale(1.05);
}

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

.product-item:hover .product-overlay {
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

.product-content {
    padding: 25px;
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
    font-size: 1.2rem;
    font-weight: 600;
    margin-bottom: 10px;
    color: var(--primary);
    height: 60px;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
}

.product-price {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.current-price {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--secondary);
    white-space: nowrap;
}

.stock-warning {
    display: inline-block;
    background: rgba(255, 193, 7, 0.1);
    color: var(--warning);
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-top: 5px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 0.7; }
    50% { opacity: 1; }
    100% { opacity: 0.7; }
}

/* Stock Control (Seperti Shopee) */
.stock-control {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 5px;
    border: 1px solid #dee2e6;
    margin-bottom: 15px;
    width: fit-content;
    margin-left: auto;
    margin-right: auto;
    justify-content: center;
}

.btn-stock {
    width: 30px;
    height: 30px;
    border: 1px solid #adb5bd;
    border-radius: 4px;
    background: white;
    color: #495057;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 400;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.btn-stock:hover {
    background: #e9ecef;
    border-color: #6c757d;
}

.btn-stock:disabled {
    background: #f8f9fa;
    color: #adb5bd;
    border-color: #dee2e6;
    cursor: not-allowed;
}

.stock-count {
    font-weight: 500;
    color: var(--dark);
    min-width: 30px;
    text-align: center;
    font-size: 1rem;
}

/* Product Actions - Tombol Edit & Delete */
.product-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 15px;
}

.btn-action {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: all 0.3s ease;
    min-width: 110px;
    max-width: 150px;
    box-sizing: border-box;
    font-size: 0.9rem;
}

.btn-edit {
    background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
    color: white;
}

.btn-edit:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
}

.btn-delete {
    background: linear-gradient(135deg, var(--danger) 0%, #c82333 100%);
    color: white;
}

.btn-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
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
    max-width: 500px;
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
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--gray);
}

.modal-body {
    padding: 20px;
}

/* Form Styles */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--dark);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 2px solid #e1e5eb;
    border-radius: 8px;
    font-size: 14px;
    box-sizing: border-box;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--secondary);
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 30px;
    justify-content: flex-end;
}

/* Style untuk preview gambar di modal edit */
#currentImagePreview img {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    border: 2px solid #e1e5eb;
    margin: 10px 0;
}

#imagePreview {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    border: 2px solid var(--secondary);
    margin: 10px 0;
}

.no-image {
    width: 200px;
    height: 150px;
    background: #f8f9fa;
    border: 2px dashed #e1e5eb;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--gray);
    margin: 10px auto;
}

.no-image i {
    font-size: 3rem;
    color: #adb5bd;
}

.btn-cancel {
    background: #6c757d;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-cancel:hover {
    background: #5a6268;
}

.btn-primary {
    background: var(--secondary);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: #2c7be5;
}

.text-muted {
    color: var(--gray);
    font-size: 0.85rem;
    margin-top: 5px;
    display: block;
}

/* Quick View Styles */
.quick-view-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .quick-view-grid {
        grid-template-columns: 1fr 1fr;
    }
}

.quick-view-image img {
    width: 100%;
    height: auto;
    border-radius: 10px;
    object-fit: cover;
}

.quick-view-details h4 {
    font-size: 1.5rem;
    margin-bottom: 15px;
    color: var(--primary);
}

.quick-view-price .price {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--secondary);
}

.quick-view-stock,
.quick-view-category {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 15px 0;
}

.quick-view-description h5 {
    margin: 20px 0 10px;
    color: var(--primary);
}

.quick-view-description p {
    line-height: 1.6;
    color: var(--gray);
}

/* Delete Confirmation Modal */
.delete-confirm {
    text-align: center;
}

.delete-icon-large {
    font-size: 4rem;
    color: var(--danger);
    margin-bottom: 20px;
}

.delete-message {
    margin-bottom: 25px;
    color: var(--dark);
    line-height: 1.6;
}

.delete-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.btn-confirm-delete {
    background: var(--danger);
    color: white;
    padding: 10px 25px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-confirm-delete:hover {
    background: #c82333;
}

/* Delete Confirmation Alert - Menyerupai gambar */
.delete-alert-modal {
    background: white;
    border-radius: 15px;
    padding: 30px;
    text-align: center;
    animation: alertFadeIn 0.3s ease;
}

@keyframes alertFadeIn {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
}

.delete-alert-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--danger);
    margin-bottom: 20px;
}

.delete-alert-message {
    font-size: 1.1rem;
    color: var(--dark);
    margin-bottom: 30px;
    line-height: 1.6;
}

.delete-alert-price {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--dark);
    margin: 15px 0;
}

.delete-alert-stock {
    font-size: 1.2rem;
    color: var(--secondary);
    margin: 15px 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.delete-alert-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 25px;
    flex-wrap: wrap;
}

.delete-alert-btn {
    padding: 12px 30px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    min-width: 120px;
}

.delete-alert-cancel {
    background: #6c757d;
    color: white;
}

.delete-alert-cancel:hover {
    background: #5a6268;
}

.delete-alert-confirm {
    background: var(--danger);
    color: white;
}

.delete-alert-confirm:hover {
    background: #c82333;
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
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
    
    .products-info {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .header-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .btn, .btn-create {
        width: 100%;
        justify-content: center;
    }
    
    .filter-grid {
        grid-template-columns: 1fr;
    }
    
    .products-grid {
        grid-template-columns: 1fr;
    }
    
    .main-content {
        padding: 15px;
    }
    
    .product-actions {
        flex-direction: column;
        gap: 10px;
    }
    
    .modal-content {
        width: 95%;
        margin: 10px;
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
    
    .product-actions {
        flex-direction: column;
    }
    
    .btn-action {
        width: 100%;
    }
    
    .products-header {
        padding: 20px 15px;
    }
    
    .products-filter {
        padding: 20px 15px;
    }
    
    .search-products {
        padding: 20px 15px;
    }
    
    .product-content {
        padding: 20px 15px;
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
                <a href="dashboard.php" class="sidebar-item">
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
                <a href="produk.php" class="sidebar-item active">
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
            <h1 class="page-title">Manajemen Produk</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Produk</span>
            </div>
        </div>

        <!-- Products Header dengan Create Button -->
        <div class="products-header">
            <div class="products-info">
                <div class="products-title">
                    <h1>Produk Anda</h1>
                    <p>Kelola dan pantau produk yang Anda jual</p>
                </div>
                <div class="header-actions">
                    <a href="c_produk.php" class="btn-create">
                        <i class="fas fa-plus"></i>
                        Tambah Produk Baru
                    </a>
                </div>
            </div>
        </div>

        <!-- Search Products -->
        <div class="search-products">
            <div class="search-container">
                <input type="text" placeholder="Cari produk berdasarkan nama, kategori, atau deskripsi..." id="productSearch">
                <i class="fas fa-search"></i>
            </div>
        </div>

        <!-- Products Filter (Hanya Kategori) -->
        <div class="products-filter" id="productsFilter">
            <div class="filter-header">
                <h3 class="filter-title">Filter Kategori</h3>
                <button class="filter-reset" id="resetFilters">
                    <i class="fas fa-redo"></i> Reset Filter
                </button>
            </div>
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Pilih Kategori</label>
                    <select class="filter-select" id="categoryFilter">
                        <option value="">Semua Kategori</option>
                        <?php 
                        // Reset pointer kategori
                        $result_kategori->data_seek(0);
                        while ($kategori = $result_kategori->fetch_assoc()): ?>
                            <option value="<?php echo $kategori['id_kategori']; ?>">
                                <?php echo htmlspecialchars($kategori['nama_kategori']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="products-grid" id="productsGrid">
            <?php if ($result_produk->num_rows > 0): ?>
                <?php while ($produk = $result_produk->fetch_assoc()): ?>
                    <?php 
                    // Tentukan badge berdasarkan kondisi
                    $badge_class = '';
                    $badge_text = '';
                    if ($produk['stok'] <= 0) {
                        $badge_class = 'badge-sale';
                        $badge_text = 'HABIS';
                    } elseif ($produk['stok'] <= 10) {
                        $badge_class = 'badge-stok';
                        $badge_text = '⚠️ Stok Menipis';
                    } elseif ($produk['keuntungan'] > 100000) {
                        $badge_class = 'badge-bestseller';
                        $badge_text = 'BEST SELLER';
                    }
                    
                    // Tentukan status stok
                    $stock_status = '';
                    $stock_class = '';
                    if ($produk['stok'] <= 0) {
                        $stock_status = 'Habis';
                        $stock_class = 'out-stock';
                    } elseif ($produk['stok'] <= 10) {
                        $stock_status = 'Stok Sedikit';
                        $stock_class = 'low-stock';
                    } else {
                        $stock_status = 'Tersedia';
                        $stock_class = 'in-stock';
                    }
                    
                    // Format harga tanpa desimal
                    $harga_jual = $produk['harga_jual'];
                    $harga_display = number_format($harga_jual, 0, ',', '.');
                    
                    // Dapatkan path gambar produk
                    $image_path = getProductImagePath($produk['gambar']);
                    ?>
                    
                    <div class="product-item" data-id="<?php echo $produk['id_produk']; ?>"
                         data-category="<?php echo $produk['id_kategori']; ?>"
                         data-stock="<?php echo $produk['stok']; ?>"
                         data-price="<?php echo $produk['harga_jual']; ?>"
                         data-name="<?php echo htmlspecialchars($produk['nama_produk']); ?>"
                         data-category-name="<?php echo htmlspecialchars($produk['nama_kategori'] ?? ''); ?>"
                         data-description="<?php echo htmlspecialchars($produk['deskripsi'] ?? ''); ?>">
                        <?php if ($badge_text): ?>
                            <div class="product-badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></div>
                        <?php endif; ?>
                        
                        <div class="product-image">
                            <?php if ($image_path): ?>
                                <img src="<?php echo $image_path; ?>" alt="<?php echo htmlspecialchars($produk['nama_produk']); ?>">
                            <?php else: ?>
                                <i class="fas fa-box"></i>
                            <?php endif; ?>
                            <div class="product-overlay">
                                <button class="quick-view-btn" onclick="quickView(<?php echo $produk['id_produk']; ?>)">
                                    <i class="fas fa-eye"></i> Quick View
                                </button>
                            </div>
                        </div>
                        
                        <div class="product-content">
                            <div class="product-category">
                                <?php echo htmlspecialchars($produk['nama_kategori'] ?: 'Tanpa Kategori'); ?>
                            </div>
                            <h3 class="product-title"><?php echo htmlspecialchars($produk['nama_produk']); ?></h3>
                            
                            <div class="stock-status-container">
                                <span class="stock-status <?php echo $stock_class; ?>"><?php echo $stock_status; ?></span>
                                <span class="current-price">Rp <?php echo $harga_display; ?></span>
                            </div>
                            
                            <?php if ($produk['stok'] <= 10 && $produk['stok'] > 0): ?>
                                <div class="stock-warning">Stok Menipis!</div>
                            <?php endif; ?>
                            
                            <!-- Stock Control (Seperti Shopee) -->
                            <div class="stock-control">
                                <button class="btn-stock minus" onclick="updateStock(<?php echo $produk['id_produk']; ?>, -1)" 
                                        <?php echo $produk['stok'] <= 0 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="stock-count" id="stock-<?php echo $produk['id_produk']; ?>">
                                    <?php echo $produk['stok']; ?>
                                </span>
                                <button class="btn-stock plus" onclick="updateStock(<?php echo $produk['id_produk']; ?>, 1)">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            
                            <!-- Tombol Edit & Delete di Bawah Stock Control -->
                            <div class="product-actions">
                                <button class="btn-action btn-edit" onclick="editProduk(<?php echo $produk['id_produk']; ?>)">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                
                                <?php if ($produk['stok'] <= 0): ?>
                                    <button class="btn-action btn-delete" onclick="showDeleteAlert(<?php echo $produk['id_produk']; ?>, '<?php echo htmlspecialchars($produk['nama_produk']); ?>', <?php echo $produk['harga_jual']; ?>, <?php echo $produk['stok']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-products" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                    <i class="fas fa-box-open" style="font-size: 4rem; color: #6c757d; margin-bottom: 20px;"></i>
                    <h3 style="color: #343a40; margin-bottom: 10px;">Tidak ada produk</h3>
                    <p style="color: #6c757d;">Belum ada produk yang ditambahkan. Klik "Tambah Produk Baru" untuk memulai.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Edit Produk -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Produk</h3>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm" enctype="multipart/form-data">
                    <input type="hidden" id="editId" name="id">
                    
                    <!-- Preview Gambar -->
                    <div class="form-group">
                        <label for="currentImage">Gambar Saat Ini</label>
                        <div id="currentImagePreview" style="text-align: center; margin-bottom: 15px;">
                            <!-- Gambar akan ditampilkan di sini -->
                        </div>
                    </div>
                    
                    <!-- Input Upload Gambar Baru -->
                    <div class="form-group">
                        <label for="editGambar">Gambar Produk (Opsional)</label>
                        <input type="file" id="editGambar" name="gambar" accept="image/*" onchange="previewImage(event)">
                        <small class="text-muted">Biarkan kosong jika tidak ingin mengubah gambar</small>
                    </div>
                    
                    <!-- Preview Gambar Baru -->
                    <div class="form-group" id="newImagePreview" style="display: none;">
                        <label>Preview Gambar Baru</label>
                        <div style="text-align: center; margin-top: 10px;">
                            <img id="imagePreview" src="#" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; display: none;">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editNama">Nama Produk</label>
                        <input type="text" id="editNama" name="nama" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="editHarga">Harga Jual</label>
                        <input type="number" id="editHarga" name="harga" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="editStok">Stok</label>
                        <input type="number" id="editStok" name="stok" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="editKategori">Kategori</label>
                        <select id="editKategori" name="kategori">
                            <option value="">Pilih Kategori</option>
                            <?php 
                            $result_kategori->data_seek(0);
                            while($kategori = $result_kategori->fetch_assoc()): ?>
                            <option value="<?php echo $kategori['id_kategori']; ?>">
                                <?php echo htmlspecialchars($kategori['nama_kategori']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="editDeskripsi">Penulis</label>
                        <textarea id="editDeskripsi" name="deskripsi" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Delete Confirmation -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Hapus Produk</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body delete-confirm">
                <div class="delete-icon-large">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <p class="delete-message" id="deleteMessage">
                    Apakah Anda yakin ingin menghapus produk ini?<br>
                    Tindakan ini tidak dapat dibatalkan.
                </p>
                <div class="delete-actions">
                    <button class="btn-cancel" onclick="closeModal('deleteModal')">Batal</button>
                    <button class="btn-confirm-delete" id="confirmDeleteBtn">Ya, Hapus</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Quick View -->
    <div class="modal" id="quickViewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Detail Produk</h3>
                <button class="modal-close" onclick="closeModal('quickViewModal')">&times;</button>
            </div>
            <div class="modal-body" id="quickViewContent">
                <!-- Content akan diisi secara dinamis -->
            </div>
        </div>
    </div>

    <!-- Modal Delete Alert (Seperti di Gambar) -->
    <div class="modal" id="deleteAlertModal">
        <div class="modal-content">
            <div class="delete-alert-modal" id="deleteAlertContent">
                <!-- Content akan diisi secara dinamis -->
            </div>
        </div>
    </div>

    <script>
        // Toggle Sidebar
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

        // Toggle User Dropdown
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

        // Helper Functions
        function formatNumber(num) {
            if (num >= 1000) {
                return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            }
            return num.toString();
        }

        function formatCurrency(num) {
            return 'Rp ' + formatNumber(num);
        }

        function getStockClass(stock) {
            if (stock <= 0) return 'out-stock';
            if (stock <= 10) return 'low-stock';
            return 'in-stock';
        }

        function getStockStatus(stock) {
            if (stock <= 0) return 'Habis';
            if (stock <= 10) return 'Stok Sedikit';
            return 'Tersedia';
        }

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Fungsi untuk preview gambar baru
        function previewImage(event) {
            const input = event.target;
            const preview = document.getElementById('imagePreview');
            const previewContainer = document.getElementById('newImagePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                    previewContainer.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
                previewContainer.style.display = 'none';
            }
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal saat klik di luar
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });

        // Reset Filters
        document.getElementById('resetFilters').addEventListener('click', () => {
            // Reset nilai filter
            document.getElementById('categoryFilter').value = '';
            document.getElementById('productSearch').value = '';
            
            // Tampilkan semua produk
            const products = document.querySelectorAll('.product-item');
            products.forEach(product => {
                product.style.display = 'block';
            });
            
            // Hapus pesan no-products
            const noProductsDiv = document.querySelector('.no-products');
            if (noProductsDiv) {
                noProductsDiv.remove();
            }
        });

        // Variables for delete functionality
        let productToDelete = null;

        // Show delete alert modal (seperti di gambar)
        function showDeleteAlert(productId, productName, productPrice, productStock) {
            productToDelete = productId;
            
            const harga = formatCurrency(productPrice);
            const stockDisplay = productStock + ' +';
            
            const alertContent = `
                <div class="delete-alert-title">Hapus Produk</div>
                <p class="delete-alert-message">
                    Apakah Anda yakin ingin menghapus produk ini?<br>
                    Tindakan ini tidak dapat dibatalkan.
                </p>
                <div class="delete-alert-price">${harga}</div>
                <div class="delete-alert-stock">${stockDisplay}</div>
                <div class="delete-alert-actions">
                    <button class="delete-alert-btn delete-alert-cancel" onclick="closeModal('deleteAlertModal')">Batal</button>
                    <button class="delete-alert-btn delete-alert-confirm" onclick="confirmDelete()">Ya, Hapus</button>
                </div>
            `;
            
            document.getElementById('deleteAlertContent').innerHTML = alertContent;
            openModal('deleteAlertModal');
        }

        // Show delete confirmation modal (versi sederhana)
        function showDeleteModal(productId) {
            productToDelete = productId;
            openModal('deleteModal');
        }

        // Handle delete confirmation
        async function confirmDelete() {
            if (!productToDelete) return;
            
            try {
                const response = await fetch('produk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_product&id=${productToDelete}`
                });
                
                const data = await response.json();
                if (data.success) {
                    // Remove product card from DOM
                    const productCard = document.querySelector(`[data-id="${productToDelete}"]`);
                    if (productCard) {
                        productCard.remove();
                    }
                    
                    // Close modal
                    closeModal('deleteAlertModal');
                    productToDelete = null;
                    
                    // Show success notification
                    showNotification('Produk berhasil dihapus!', 'success');
                    
                    // Check if no products left
                    const productsGrid = document.getElementById('productsGrid');
                    const productItems = productsGrid.querySelectorAll('.product-item');
                    
                    if (productItems.length === 0) {
                        productsGrid.innerHTML = `
                            <div class="no-products" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                                <i class="fas fa-box-open" style="font-size: 4rem; color: #6c757d; margin-bottom: 20px;"></i>
                                <h3 style="color: #343a40; margin-bottom: 10px;">Tidak ada produk</h3>
                                <p style="color: #6c757d;">Belum ada produk yang ditambahkan. Klik "Tambah Produk Baru" untuk memulai.</p>
                            </div>
                        `;
                    }
                } else {
                    showNotification('Gagal menghapus produk: ' + (data.message || 'Terjadi kesalahan'), 'error');
                }
            } catch (error) {
                showNotification('Terjadi kesalahan: ' + error.message, 'error');
            }
        }

        // Event listener untuk modal delete sederhana
        document.getElementById('confirmDeleteBtn').addEventListener('click', confirmDelete);

        // 1. Update Stok dengan Plus/Minus (Seperti Shopee)
        async function updateStock(productId, change) {
            const stockElement = document.getElementById(`stock-${productId}`);
            const currentStock = parseInt(stockElement.textContent) || 0;
            const newStock = currentStock + change;
            
            // Validasi stok tidak boleh negatif
            if (newStock < 0) return;
            
            // Update UI terlebih dahulu
            stockElement.textContent = newStock;
            updateStockDisplay(productId, newStock);
            
            // Update di database
            try {
                const response = await fetch('produk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=update_stock&id=${productId}&stok=${newStock}`
                });
                
                const data = await response.json();
                if (!data.success) {
                    // Jika gagal, kembalikan ke stok sebelumnya
                    stockElement.textContent = currentStock;
                    updateStockDisplay(productId, currentStock);
                } else {
                    // Update product card dengan data terbaru dari server
                    updateProductCard(data.product);
                }
            } catch (error) {
                // Jika error, kembalikan ke stok sebelumnya
                stockElement.textContent = currentStock;
                updateStockDisplay(productId, currentStock);
            }
        }

        function updateStockDisplay(productId, stock) {
            const product = document.querySelector(`[data-id="${productId}"]`);
            if (!product) return;
            
            const stockStatus = product.querySelector('.stock-status');
            const minusButton = product.querySelector('.btn-stock.minus');
            const deleteButton = product.querySelector('.btn-delete');
            const stockWarning = product.querySelector('.stock-warning');
            const badgeElement = product.querySelector('.product-badge');
            
            // Update data attribute
            product.setAttribute('data-stock', stock);
            
            // Update status display
            if (stock <= 0) {
                if (stockStatus) {
                    stockStatus.className = 'stock-status out-stock';
                    stockStatus.textContent = 'Habis';
                }
                if (minusButton) minusButton.disabled = true;
                
                // Show delete button
                if (deleteButton) {
                    deleteButton.style.display = 'flex';
                } else {
                    // Add delete button
                    const actionsDiv = product.querySelector('.product-actions');
                    if (actionsDiv) {
                        const deleteBtn = document.createElement('button');
                        deleteBtn.className = 'btn-action btn-delete';
                        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Hapus';
                        deleteBtn.onclick = () => {
                            const productName = product.getAttribute('data-name');
                            const productPrice = product.getAttribute('data-price');
                            showDeleteAlert(productId, productName, productPrice, stock);
                        };
                        actionsDiv.appendChild(deleteBtn);
                    }
                }
                
                // Remove stock warning if exists
                if (stockWarning) {
                    stockWarning.remove();
                }
                
                // Update badge
                if (badgeElement) {
                    badgeElement.className = 'product-badge badge-sale';
                    badgeElement.textContent = 'HABIS';
                } else {
                    const newBadge = document.createElement('div');
                    newBadge.className = 'product-badge badge-sale';
                    newBadge.textContent = 'HABIS';
                    product.querySelector('.product-image').prepend(newBadge);
                }
                
            } else if (stock <= 10) {
                if (stockStatus) {
                    stockStatus.className = 'stock-status low-stock';
                    stockStatus.textContent = 'Stok Sedikit';
                }
                if (minusButton) minusButton.disabled = false;
                
                // Hide delete button
                if (deleteButton) {
                    deleteButton.style.display = 'none';
                }
                
                // Show or update stock warning
                if (stockWarning) {
                    stockWarning.textContent = 'Stok Menipis!';
                } else {
                    const warning = document.createElement('div');
                    warning.className = 'stock-warning';
                    warning.textContent = 'Stok Menipis!';
                    product.querySelector('.product-content').insertBefore(warning, product.querySelector('.stock-control'));
                }
                
                // Update badge
                if (badgeElement) {
                    badgeElement.className = 'product-badge badge-stok';
                    badgeElement.textContent = '⚠️ Stok Menipis';
                } else {
                    const newBadge = document.createElement('div');
                    newBadge.className = 'product-badge badge-stok';
                    newBadge.textContent = '⚠️ Stok Menipis';
                    product.querySelector('.product-image').prepend(newBadge);
                }
                
            } else {
                if (stockStatus) {
                    stockStatus.className = 'stock-status in-stock';
                    stockStatus.textContent = 'Tersedia';
                }
                if (minusButton) minusButton.disabled = false;
                
                // Hide delete button
                if (deleteButton) {
                    deleteButton.style.display = 'none';
                }
                
                // Remove stock warning if exists
                if (stockWarning) {
                    stockWarning.remove();
                }
                
                // Remove low stock badge if exists
                if (badgeElement && badgeElement.classList.contains('badge-stok')) {
                    badgeElement.remove();
                } else if (badgeElement && badgeElement.classList.contains('badge-sale')) {
                    badgeElement.remove();
                }
            }
        }

        // 2. Edit Produk dengan Gambar
        async function editProduk(productId) {
            try {
                // Ambil data produk dari database
                const response = await fetch('produk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_product&id=${productId}`
                });
                
                const data = await response.json();
                if (data.success) {
                    // Isi form dengan data produk
                    document.getElementById('editId').value = data.product.id_produk;
                    document.getElementById('editNama').value = data.product.nama_produk;
                    document.getElementById('editHarga').value = data.product.harga_jual;
                    document.getElementById('editStok').value = data.product.stok;
                    document.getElementById('editKategori').value = data.product.id_kategori;
                    document.getElementById('editDeskripsi').value = data.product.deskripsi || '';
                    
                    // Reset preview gambar baru
                    document.getElementById('editGambar').value = '';
                    document.getElementById('imagePreview').style.display = 'none';
                    document.getElementById('newImagePreview').style.display = 'none';
                    
                    // Tampilkan gambar saat ini
                    const previewContainer = document.getElementById('currentImagePreview');
                    const imagePath = data.product.gambar_path;
                    
                    if (imagePath) {
                        previewContainer.innerHTML = `
                            <img src="${imagePath}" alt="${data.product.nama_produk}">
                            <p class="text-muted">Gambar saat ini</p>
                        `;
                    } else {
                        previewContainer.innerHTML = `
                            <div class="no-image">
                                <i class="fas fa-image"></i>
                            </div>
                            <p class="text-muted">Belum ada gambar</p>
                        `;
                    }
                    
                    // Tampilkan modal
                    openModal('editModal');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Terjadi kesalahan saat mengambil data produk', 'error');
            }
        }

        // Handle submit form edit dengan gambar
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Tampilkan loading indicator
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
            submitBtn.disabled = true;
            
            const formData = new FormData(this);
            formData.append('action', 'update_product_with_image');
            
            try {
                const response = await fetch('produk.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                // Reset button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                
                if (data.success) {
                    // Update card produk tanpa refresh
                    updateProductCard(data.product);
                    closeModal('editModal');
                    closeModal('deleteAlertModal'); // Tutup juga modal delete alert jika terbuka
                    
                    // Tampilkan notifikasi sukses
                    showNotification('Produk berhasil diperbarui!', 'success');
                } else {
                    showNotification('Gagal memperbarui produk: ' + (data.message || 'Terjadi kesalahan'), 'error');
                }
            } catch (error) {
                // Reset button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                
                console.error('Error:', error);
                showNotification('Terjadi kesalahan saat memperbarui produk', 'error');
            }
        });

        // Fungsi untuk menampilkan notifikasi
        function showNotification(message, type = 'success') {
            // Hapus notifikasi sebelumnya jika ada
            const existingNotification = document.querySelector('.notification-message');
            if (existingNotification) {
                existingNotification.remove();
            }
            
            const notification = document.createElement('div');
            notification.className = `notification-message ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            `;
            
            // Tambahkan notifikasi di atas konten utama
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.insertBefore(notification, mainContent.firstChild);
                
                // Hapus notifikasi setelah 3 detik
                setTimeout(() => {
                    notification.remove();
                }, 3000);
            }
        }

        // Update card produk setelah edit dengan gambar
        function updateProductCard(product) {
            const productCard = document.querySelector(`[data-id="${product.id_produk}"]`);
            if (!productCard) return;
            
            // Update basic info
            productCard.querySelector('.product-title').textContent = product.nama_produk;
            
            // Format harga tanpa desimal
            const hargaDisplay = (product.harga_jual || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            productCard.querySelector('.current-price').textContent = `Rp ${hargaDisplay}`;
            
            // Update stock display
            const stockElement = productCard.querySelector('.stock-count');
            if (stockElement) {
                stockElement.textContent = product.stok;
                stockElement.id = `stock-${product.id_produk}`;
            }
            
            // Update data attributes
            productCard.setAttribute('data-price', product.harga_jual);
            productCard.setAttribute('data-stock', product.stok);
            productCard.setAttribute('data-name', product.nama_produk);
            
            // Update kategori
            const categoryElement = productCard.querySelector('.product-category');
            if (categoryElement) {
                categoryElement.textContent = product.nama_kategori || 'Tanpa Kategori';
                productCard.setAttribute('data-category-name', product.nama_kategori || '');
            }
            
            // Update gambar jika ada perubahan
            if (product.gambar_path) {
                const productImage = productCard.querySelector('.product-image img');
                if (productImage) {
                    productImage.src = product.gambar_path;
                    productImage.alt = product.nama_produk;
                }
            }
            
            // Update stock status and badges
            updateStockDisplay(product.id_produk, product.stok);
            
            // Update delete button event handler
            const deleteButton = productCard.querySelector('.btn-delete');
            if (deleteButton) {
                deleteButton.onclick = () => {
                    showDeleteAlert(product.id_produk, product.nama_produk, product.harga_jual, product.stok);
                };
            }
        }

        // 3. Quick View
        async function quickView(productId) {
            try {
                const response = await fetch('produk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=quick_view&id=${productId}`
                });
                
                const data = await response.json();
                if (data.success) {
                    const product = data.product;
                    const imagePath = product.gambar_path || '../asset/default-product.png';
                    
                    const harga = formatNumber(product.harga_jual || 0);
                    const stock = product.stok || 0;
                    const stockClass = getStockClass(stock);
                    const stockStatus = getStockStatus(stock);
                    
                    const content = `
                        <div class="quick-view-grid">
                            <div class="quick-view-image">
                                <img src="${imagePath}" alt="${product.nama_produk}">
                            </div>
                            <div class="quick-view-details">
                                <h4>${product.nama_produk}</h4>
                                <div class="quick-view-price">
                                    <span class="price">Rp ${harga}</span>
                                </div>
                                <div class="quick-view-stock">
                                    <span class="stock-label">Stok:</span>
                                    <span class="stock-value ${stockClass}">
                                        ${stock} ${stockStatus}
                                    </span>
                                </div>
                                ${stock <= 10 && stock > 0 ? '<div class="stock-warning">⚠️ Stok Menipis!</div>' : ''}
                                <div class="quick-view-category">
                                    <span class="category-label">Kategori:</span>
                                    <span class="category-value">${product.nama_kategori || 'Tanpa Kategori'}</span>
                                </div>
                                <div class="quick-view-description">
                                    <h5>Penulis :</h5> <p>${product.deskripsi || 'Tidak ada deskripsi'}</p>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('quickViewContent').innerHTML = content;
                    openModal('quickViewModal');
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // 4. Filter Produk
        function applyFilters() {
            const category = document.getElementById('categoryFilter').value;
            const search = document.getElementById('productSearch').value.toLowerCase();
            
            const products = Array.from(document.querySelectorAll('.product-item'));
            let hasVisibleProducts = false;
            
            // Filter produk
            products.forEach(product => {
                const productCategory = product.getAttribute('data-category');
                const productName = product.getAttribute('data-name').toLowerCase();
                const productCategoryName = product.getAttribute('data-category-name').toLowerCase();
                const productDescription = product.getAttribute('data-description').toLowerCase();
                
                let isVisible = true;
                
                // Filter kategori
                if (category && productCategory !== category) {
                    isVisible = false;
                }
                
                // Filter search
                if (search && !productName.includes(search) && 
                    !productCategoryName.includes(search) && 
                    !productDescription.includes(search)) {
                    isVisible = false;
                }
                
                product.style.display = isVisible ? 'block' : 'none';
                
                if (isVisible) {
                    hasVisibleProducts = true;
                }
            });
            
            // Check if any products are visible
            const noProductsDiv = document.querySelector('.no-products');
            
            if (!hasVisibleProducts) {
                // Tampilkan pesan tidak ada produk
                const productsGrid = document.getElementById('productsGrid');
                if (!noProductsDiv) {
                    const noProductsHTML = `
                        <div class="no-products" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px;">
                            <i class="fas fa-box-open" style="font-size: 4rem; color: #6c757d; margin-bottom: 20px;"></i>
                            <h3 style="color: #343a40; margin-bottom: 10px;">Tidak ada produk ditemukan</h3>
                            <p style="color: #6c757d;">Coba filter atau kata kunci lain</p>
                        </div>
                    `;
                    productsGrid.insertAdjacentHTML('beforeend', noProductsHTML);
                }
            } else if (noProductsDiv) {
                // Hapus pesan no-products jika ada produk yang visible
                noProductsDiv.remove();
            }
        }

        // Event listeners untuk filter dan search
        document.getElementById('categoryFilter').addEventListener('change', applyFilters);
        document.getElementById('productSearch').addEventListener('input', debounce(applyFilters, 500));

        // Logout confirmation
        const logoutLink = document.getElementById('logoutLink');
        if (logoutLink) {
            logoutLink.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Apakah Anda yakin ingin keluar?')) {
                    window.location.href = this.href;
                }
            });
        }

        // Initialize stock displays
        document.addEventListener('DOMContentLoaded', () => {
            // Update semua tampilan stok saat pertama kali load
            const products = document.querySelectorAll('.product-item');
            products.forEach(product => {
                const productId = product.getAttribute('data-id');
                const stock = parseInt(product.getAttribute('data-stock') || 0);
                updateStockDisplay(productId, stock);
            });
            
            applyFilters();
        });

        // Tambahkan style untuk notifikasi
        const notificationStyle = document.createElement('style');
        notificationStyle.innerHTML = `
        .notification-message {
            position: fixed;
            top: 90px;
            right: 30px;
            padding: 15px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 10000;
            animation: slideInRight 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .notification-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .notification-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        `;
        document.head.appendChild(notificationStyle);
    </script>
</body>
</html>