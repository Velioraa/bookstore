<?php
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Cek apakah role user adalah super_admin
if ($_SESSION['role'] !== 'super_admin') {
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

// Variabel untuk notifikasi
$notification = array(
    'type' => '', // success, error, warning
    'message' => ''
);

// ==================== PROSES DELETE ====================
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Ambil nama kategori untuk konfirmasi
    $query = "SELECT nama_kategori FROM katagori WHERE id_kategori = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $kategori = $result->fetch_assoc();
        $nama_kategori = $kategori['nama_kategori'];
        
        // Update produk yang menggunakan kategori ini menjadi NULL
        $update_query = "UPDATE produk SET id_kategori = NULL WHERE id_kategori = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("i", $delete_id);
        $update_result = $update_stmt->execute();
        $update_stmt->close();
        
        if ($update_result) {
            // Hapus kategori
            $delete_query = "DELETE FROM katagori WHERE id_kategori = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("i", $delete_id);
            
            if ($delete_stmt->execute()) {
                $notification['type'] = 'success';
                $notification['message'] = "Kategori '$nama_kategori' berhasil dihapus! Produk yang terkait telah diupdate.";
            } else {
                $notification['type'] = 'error';
                $notification['message'] = "Gagal menghapus kategori: " . $conn->error;
            }
            $delete_stmt->close();
        } else {
            $notification['type'] = 'error';
            $notification['message'] = "Gagal update produk: " . $conn->error;
        }
    } else {
        $notification['type'] = 'error';
        $notification['message'] = "Kategori tidak ditemukan!";
    }
    $stmt->close();
}

// ==================== PROSES EDIT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_kategori'])) {
    $edit_id = intval($_POST['edit_id']);
    $nama_kategori_baru = trim($_POST['nama_kategori']);
    
    if (empty($nama_kategori_baru)) {
        $notification['type'] = 'error';
        $notification['message'] = "Nama kategori tidak boleh kosong!";
    } else {
        // Cek apakah nama kategori sudah ada (kecuali untuk kategori yang sedang diedit)
        $check_query = "SELECT id_kategori FROM katagori WHERE nama_kategori = ? AND id_kategori != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $nama_kategori_baru, $edit_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $notification['type'] = 'error';
            $notification['message'] = "Nama kategori '$nama_kategori_baru' sudah ada!";
        } else {
            // Update kategori
            $update_query = "UPDATE katagori SET nama_kategori = ? WHERE id_kategori = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $nama_kategori_baru, $edit_id);
            
            if ($update_stmt->execute()) {
                $notification['type'] = 'success';
                $notification['message'] = "Kategori berhasil diupdate!";
            } else {
                $notification['type'] = 'error';
                $notification['message'] = "Gagal mengupdate kategori: " . $conn->error;
            }
            $update_stmt->close();
        }
        $check_stmt->close();
    }
}

// ==================== PROSES ADD ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_kategori'])) {
    $nama_kategori_baru = trim($_POST['nama_kategori']);
    
    if (empty($nama_kategori_baru)) {
        $notification['type'] = 'error';
        $notification['message'] = "Nama kategori tidak boleh kosong!";
    } else {
        // Cek apakah nama kategori sudah ada
        $check_query = "SELECT id_kategori FROM katagori WHERE nama_kategori = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $nama_kategori_baru);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $notification['type'] = 'error';
            $notification['message'] = "Nama kategori '$nama_kategori_baru' sudah ada!";
        } else {
            // Tambah kategori baru
            $insert_query = "INSERT INTO katagori (nama_kategori) VALUES (?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("s", $nama_kategori_baru);
            
            if ($insert_stmt->execute()) {
                $notification['type'] = 'success';
                $notification['message'] = "Kategori '$nama_kategori_baru' berhasil ditambahkan!";
            } else {
                $notification['type'] = 'error';
                $notification['message'] = "Gagal menambahkan kategori: " . $conn->error;
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}

// Ambil data untuk modal edit (jika ada parameter edit)
$edit_data = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $query = "SELECT id_kategori, nama_kategori FROM katagori WHERE id_kategori = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $edit_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Ambil inisial untuk avatar
$initials = strtoupper(substr($username, 0, 2));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Categories</title>
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
    
    /* ========== HIDE SCROLLBARS ========== */
    ::-webkit-scrollbar {
        display: none;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        -ms-overflow-style: none;  /* IE and Edge */
        scrollbar-width: none;  /* Firefox */
    }
    
    body {
        background-color: #f5f7fa;
        color: var(--dark);
        overflow-x: auto;
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
        height: 45px;
        width: auto;
        object-fit: contain;
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
        gap: 25px;
    }
    
    .user-profile {
        position: relative;
        cursor: pointer;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 10px;
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
    }
    
    .user-details {
        display: flex;
        flex-direction: column;
    }
    
    .user-name {
        font-weight: 600;
        color: var(--dark);
    }
    
    .user-role {
        font-size: 0.85rem;
        color: var(--gray);
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
        max-height: 300px;
        overflow-y: auto;
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    
    .dropdown.show {
        display: block;
        animation: fadeIn 0.2s ease;
    }
    
    .dropdown::-webkit-scrollbar {
        display: none;
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
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    
    .sidebar::-webkit-scrollbar {
        display: none;
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
    
    /* Main Content */
    .main-content {
        margin-left: var(--sidebar-width);
        margin-top: 70px;
        padding: 30px;
        height: calc(100vh - 70px);
        overflow-y: auto;
        transition: margin-left 0.3s ease;
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    
    .main-content::-webkit-scrollbar {
        display: none;
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
    
    /* Categories Header */
    .categories-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding: 25px;
        background: white;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }
    
    .header-info h1 {
        font-size: 1.8rem;
        color: var(--primary);
        margin-bottom: 10px;
    }
    
    .header-info p {
        color: var(--gray);
    }
    
    .header-actions {
        display: flex;
        gap: 15px;
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
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
    }
    
    .btn-secondary {
        background: #f8f9fa;
        color: var(--dark);
        border: 1px solid #e1e5eb;
    }
    
    .btn-secondary:hover {
        background: #e9ecef;
    }
    
    /* Categories Grid */
    .categories-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }
    
    .category-card {
        background: white;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
    }
    
    .category-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    
    .category-header {
        padding: 25px;
        background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .category-icon {
        width: 60px;
        height: 60px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
    }
    
    .category-info {
        text-align: right;
    }
    
    .category-count {
        font-size: 2.5rem;
        font-weight: 700;
        line-height: 1;
    }
    
    .category-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    .category-content {
        padding: 25px;
    }
    
    .category-title {
        font-size: 1.3rem;
        font-weight: 600;
        margin-bottom: 15px;
        color: var(--primary);
    }
    
    .category-description {
        color: var(--gray);
        line-height: 1.6;
        margin-bottom: 20px;
    }
    
    .category-books {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
    }
    
    .book-sample {
        width: 40px;
        height: 50px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 0.8rem;
    }
    
    .more-books {
        font-size: 0.9rem;
        color: var(--gray);
    }
    
    .category-actions {
        display: flex;
        gap: 10px;
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
    }
    
    .btn-view {
        background: rgba(74, 144, 226, 0.1);
        color: var(--secondary);
    }
    
    .btn-view:hover {
        background: rgba(74, 144, 226, 0.2);
    }
    
    .btn-edit {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success);
    }
    
    .btn-edit:hover {
        background: rgba(40, 167, 69, 0.2);
    }
    
    .btn-delete {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger);
    }
    
    .btn-delete:hover {
        background: rgba(220, 53, 69, 0.2);
    }
    
    /* Popular Tags */
    .tags-section {
        background: white;
        border-radius: 15px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }
    
    .section-title {
        font-size: 1.3rem;
        font-weight: 600;
        margin-bottom: 20px;
        color: var(--primary);
    }
    
    .tags-container {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }
    
    .tag {
        padding: 10px 20px;
        background: #f8f9fa;
        border: 1px solid #e1e5eb;
        border-radius: 25px;
        font-size: 0.9rem;
        color: var(--dark);
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .tag:hover {
        background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
        color: white;
        border-color: var(--secondary);
    }
    
    .tag.active {
        background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
        color: white;
        border-color: var(--secondary);
    }
    
    /* Stats Overview */
    .stats-overview {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-box {
        background: white;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }
    
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: var(--gray);
        font-size: 0.9rem;
    }
    
    /* Table Styles */
    .categories-table-section {
        background: white;
        border-radius: 15px;
        padding: 30px;
        margin-top: 30px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }
    
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .table-responsive {
        overflow-x: auto;
        border-radius: 10px;
        border: 1px solid #e1e5eb;
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    
    .table-responsive::-webkit-scrollbar {
        display: none;
    }
    
    .table-responsive table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px;
    }
    
    .table-responsive thead tr {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    }
    
    .table-responsive th {
        padding: 15px 20px;
        text-align: center;
        font-weight: 600;
        color: var(--dark);
        border-bottom: 2px solid #dee2e6;
    }
    
    .table-responsive th:first-child {
        width: 80px;
    }
    
    .table-responsive th:nth-child(3) {
        width: 150px;
    }
    
    .table-responsive th:last-child {
        width: 180px;
    }
    
    .table-responsive th:nth-child(2) {
        text-align: left;
    }
    
    .table-responsive td {
        padding: 15px 20px;
        border-bottom: 1px solid #e9ecef;
        transition: all 0.3s ease;
    }
    
    .table-responsive tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .table-responsive td:first-child {
        text-align: center;
        font-weight: 500;
        color: var(--gray);
    }
    
    .table-responsive td:nth-child(3) {
        text-align: center;
    }
    
    .table-responsive td:last-child {
        text-align: center;
    }
    
    .table-search {
        display: flex;
    }
    
    .table-search-box {
        position: relative;
        width: 300px;
    }
    
    .table-search-box input {
        width: 100%;
        padding: 12px 45px 12px 20px;
        border: 2px solid #e1e5eb;
        border-radius: 25px;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .table-search-box input:focus {
        outline: none;
        border-color: var(--secondary);
        box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
    }
    
    .table-search-box i {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--gray);
    }
    
    .category-icon-small {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.1rem;
    }
    
    .product-count-badge {
        display: inline-block;
        background: rgba(74, 144, 226, 0.1);
        color: var(--secondary);
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 0.9rem;
        min-width: 80px;
    }
    
    .product-count-badge span {
        font-weight: 500;
        font-size: 0.85rem;
        margin-left: 3px;
    }
    
    .table-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #e9ecef;
        color: var(--gray);
        font-size: 0.9rem;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--gray);
    }
    
    .empty-state-icon {
        font-size: 4rem;
        color: #e1e5eb;
        margin-bottom: 20px;
    }
    
    .empty-state h3 {
        font-size: 1.2rem;
        margin-bottom: 10px;
        color: var(--gray);
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 2000;
        align-items: center;
        justify-content: center;
    }
    
    .modal.show {
        display: flex;
        animation: fadeIn 0.3s ease;
    }
    
    .modal-content {
        background: white;
        width: 500px;
        max-width: 90%;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        overflow: hidden;
        max-height: 90vh;
        overflow-y: auto;
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    
    .modal-content::-webkit-scrollbar {
        display: none;
    }
    
    .modal-header {
        padding: 20px 25px;
        background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-title {
        font-size: 1.3rem;
        font-weight: 600;
    }
    
    .modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 5px;
    }
    
    .modal-body {
        padding: 25px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--dark);
    }
    
    .form-input {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e1e5eb;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .form-input:focus {
        outline: none;
        border-color: var(--secondary);
        box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
    }
    
    .modal-footer {
        padding: 20px 25px;
        background: #f8f9fa;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
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
        
        .categories-grid {
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
        
        .categories-header {
            flex-direction: column;
            gap: 20px;
            text-align: center;
        }
        
        .header-actions {
            width: 100%;
            justify-content: center;
        }
        
        .categories-grid {
            grid-template-columns: 1fr;
        }
        
        .table-search {
            justify-content: flex-start;
        }
        
        .table-search-box {
            width: 100%;
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
        
        .category-header {
            flex-direction: column;
            text-align: center;
            gap: 15px;
        }
        
        .category-info {
            text-align: center;
        }
        
        .table-footer {
            flex-direction: column;
            gap: 10px;
            text-align: center;
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
            <div class="user-profile" id="userProfile">
                <div class="user-info">
                    <div class="avatar">
                        <?php
                        $gambar_path = '../asset/' . htmlspecialchars($user['gambar'] ?: 'default.png');
                        if (file_exists($gambar_path) && $user['gambar'] && $user['gambar'] != 'default.png') {
                            echo '<img src="' . $gambar_path . '" alt="Profile" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">';
                        } else {
                            echo strtoupper(substr($username, 0, 2));
                        }
                        ?>
                    </div>                     
                    <div class="user-details">
                        <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                        <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></span>
                    </div>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown" id="userDropdown">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user"></i>
                        <span>Profile</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Log Out</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Main</div>
                <a href="index.php" class="sidebar-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Management</div>
                <a href="categories.php" class="sidebar-item active">
                    <i class="fas fa-tags"></i>
                    <span>Categories</span>
                </a>
                <a href="pembeli.php" class="sidebar-item">
                    <i class="fas fa-user"></i>
                    <span>Pembeli</span>
                </a>
                <a href="penjual.php" class="sidebar-item">
                    <i class="fas fa-user-cog"></i>
                    <span>Admin</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Support</div>
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
            <h1 class="page-title">Categories Management</h1>
            <div class="breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Categories</span>
            </div>
        </div>

        <!-- Categories Header -->
        <div class="categories-header">
            <div class="header-info">
                <h1>Book Categories</h1>
                <p>Manage and organize your book collection by categories</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="showAddModal()">
                    <i class="fas fa-plus"></i> Add Category
                </button>
            </div>
        </div>

        <!-- Tabel Kategori -->
        <div class="categories-table-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i> Categories List
                </h2>
                <div class="table-search">
                    <div class="table-search-box">
                        <input type="text" id="searchTable" onkeyup="searchCategories()" placeholder="Search categories...">
                        <i class="fas fa-search"></i>
                    </div>
                </div>
            </div>

            <?php
            // Query untuk tabel kategori
            $query_table = "SELECT k.id_kategori, k.nama_kategori, 
                                   COUNT(p.id_produk) as jumlah_produk
                            FROM katagori k
                            LEFT JOIN produk p ON k.id_kategori = p.id_kategori
                            GROUP BY k.id_kategori
                            ORDER BY k.nama_kategori ASC";
            
            $result_table = $conn->query($query_table);
            $total_kategori = 0;
            
            if ($result_table && $result_table->num_rows > 0) {
                $total_kategori = $result_table->num_rows;
                ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Kategori</th>
                                <th>Jumlah Produk</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php 
                            $no = 1;
                            while ($row = $result_table->fetch_assoc()): 
                            ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div>
                                            <div style="font-weight: 600; color: var(--dark);">
                                                <?php echo htmlspecialchars($row['nama_kategori']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="product-count-badge">
                                        <?php echo $row['jumlah_produk']; ?>
                                        <span>produk</span>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px; justify-content: center;">
                                        <button class="btn-action btn-edit" 
                                                onclick="showEditModal(<?php echo $row['id_kategori']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kategori'])); ?>')">
                                            <i class="fas fa-edit" style="font-size: 0.8rem;"></i>
                                            Edit
                                        </button>
                                        <button class="btn-action btn-delete" 
                                                onclick="confirmDelete(<?php echo $row['id_kategori']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kategori'])); ?>')">
                                            <i class="fas fa-trash" style="font-size: 0.8rem;"></i>
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php
            } else {
                ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-table"></i>
                    </div>
                    <h3>Belum ada kategori</h3>
                    <p style="margin-bottom: 25px;">Tambahkan kategori pertama Anda untuk mulai mengelola buku</p>
                    <button class="btn btn-primary" onclick="showAddModal()">
                        <i class="fas fa-plus"></i> Tambah Kategori Pertama
                    </button>
                </div>
                <?php
            }
            ?>
        </div>
    </main>

    <!-- Modal Edit -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Edit Kategori</div>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <input type="hidden" name="edit_kategori" value="1">
                    
                    <div class="form-group">
                        <label for="edit_nama_kategori" class="form-label">Nama Kategori</label>
                        <input type="text" 
                               id="edit_nama_kategori" 
                               name="nama_kategori" 
                               class="form-input" 
                               required
                               placeholder="Masukkan nama kategori">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Add -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Tambah Kategori Baru</div>
                <button class="modal-close" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="add_kategori" value="1">
                    
                    <div class="form-group">
                        <label for="add_nama_kategori" class="form-label">Nama Kategori</label>
                        <input type="text" 
                               id="add_nama_kategori" 
                               name="nama_kategori" 
                               class="form-input" 
                               required
                               placeholder="Masukkan nama kategori baru">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">Tambah Kategori</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    // Tutup koneksi di akhir
    $conn->close();
    ?>

    <script>
        // Toggle Sidebar
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Ganti ikon menu
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
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!userProfile.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target) &&
                !sidebar.classList.contains('collapsed')) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                menuToggle.querySelector('i').className = 'fas fa-bars';
            }
        });

        // Update total kategori counter
        document.getElementById('totalCategories').textContent = <?php echo $total_kategori; ?>;
        
        // Fungsi untuk searching di tabel
        function searchCategories() {
            const input = document.getElementById('searchTable');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('tableBody');
            const rows = table.getElementsByTagName('tr');
            let visibleCount = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                // Cari di kolom nama kategori (kolom ke-2)
                if (cells[1]) {
                    const textValue = cells[1].textContent || cells[1].innerText;
                    if (textValue.toUpperCase().indexOf(filter) > -1) {
                        found = true;
                        visibleCount++;
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
            
            document.getElementById('visibleRows').textContent = visibleCount;
        }
        
        // Fungsi modal Edit
        function showEditModal(id, nama) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama_kategori').value = nama;
            document.getElementById('editModal').classList.add('show');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }
        
        // Fungsi modal Add
        function showAddModal() {
            document.getElementById('add_nama_kategori').value = '';
            document.getElementById('addModal').classList.add('show');
        }
        
        function closeAddModal() {
            document.getElementById('addModal').classList.remove('show');
        }
        
        // Fungsi konfirmasi delete
        function confirmDelete(id, nama) {
            if (confirm('Apakah Anda yakin ingin menghapus kategori "' + nama + '"?\n\n' +
                       'Perhatian: Produk yang menggunakan kategori ini akan kehilangan kategorinya.')) {
                window.location.href = '?delete_id=' + id;
            }
        }
        
        // Aktifkan sidebar item saat diklik
        document.querySelectorAll('.sidebar-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
            });
        });
        
        // Auto-hide notifikasi setelah 5 detik
        const notificationContainer = document.getElementById('notificationContainer');
        if (notificationContainer) {
            setTimeout(() => {
                notificationContainer.style.animation = 'fadeOut 0.5s ease';
                setTimeout(() => {
                    notificationContainer.style.display = 'none';
                }, 500);
            }, 5000);
        }
        
        // Jika ada parameter edit_id di URL, buka modal edit
        <?php if (isset($_GET['edit_id']) && $edit_data): ?>
        window.onload = function() {
            showEditModal(<?php echo $edit_data['id_kategori']; ?>, '<?php echo htmlspecialchars(addslashes($edit_data['nama_kategori'])); ?>');
        };
        <?php endif; ?>
        
        // Close modal when clicking outside
        document.addEventListener('click', (e) => {
            const editModal = document.getElementById('editModal');
            const addModal = document.getElementById('addModal');
            
            if (editModal.classList.contains('show') && e.target === editModal) {
                closeEditModal();
            }
            
            if (addModal.classList.contains('show') && e.target === addModal) {
                closeAddModal();
            }
        });
    </script>
</body>
</html>