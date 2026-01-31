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

// ==================== PROSES UPDATE PEMBELI ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pembeli'])) {
    $id_user = intval($_POST['id_user']);
    $nik = trim($_POST['nik']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $alamat = trim($_POST['alamat']);
    $status = trim($_POST['status']);
    
    // Validasi input
    if (empty($nik) || empty($username) || empty($email)) {
        $notification['type'] = 'error';
        $notification['message'] = "NIK, Nama, dan Email tidak boleh kosong!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $notification['type'] = 'error';
        $notification['message'] = "Format email tidak valid!";
    } else {
        // Cek apakah email sudah digunakan oleh user lain
        $check_query = "SELECT id_user FROM users WHERE email = ? AND id_user != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $email, $id_user);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $notification['type'] = 'error';
            $notification['message'] = "Email '$email' sudah digunakan oleh user lain!";
        } else {
            // Update data pembeli
            if (!empty($_POST['password'])) {
                // Jika password diisi, update dengan password baru (hash)
                $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET nik = ?, username = ?, email = ?, password = ?, alamat = ?, status = ? WHERE id_user = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssssssi", $nik, $username, $email, $password, $alamat, $status, $id_user);
            } else {
                // Jika password tidak diisi, update tanpa mengubah password
                $update_query = "UPDATE users SET nik = ?, username = ?, email = ?, alamat = ?, status = ? WHERE id_user = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("sssssi", $nik, $username, $email, $alamat, $status, $id_user);
            }
            
            if ($update_stmt->execute()) {
                $notification['type'] = 'success';
                $notification['message'] = "Data pembeli berhasil diupdate!";
            } else {
                $notification['type'] = 'error';
                $notification['message'] = "Gagal mengupdate data: " . $conn->error;
            }
            
            if (isset($update_stmt)) $update_stmt->close();
        }
        $check_stmt->close();
    }
}

// ==================== PROSES DELETE PEMBELI ====================
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Ambil data pembeli untuk konfirmasi
    $query = "SELECT username FROM users WHERE id_user = ? AND role = 'pembeli'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $pembeli = $result->fetch_assoc();
        $nama_pembeli = $pembeli['username'];
        
        // Hapus pembeli
        $delete_query = "DELETE FROM users WHERE id_user = ? AND role = 'pembeli'";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $delete_id);
        
        if ($delete_stmt->execute()) {
            $notification['type'] = 'success';
            $notification['message'] = "Pembeli '$nama_pembeli' berhasil dihapus!";
        } else {
            $notification['type'] = 'error';
            $notification['message'] = "Gagal menghapus pembeli: " . $conn->error;
        }
        $delete_stmt->close();
    } else {
        $notification['type'] = 'error';
        $notification['message'] = "Pembeli tidak ditemukan!";
    }
    $stmt->close();
}

// ==================== PROSES SEARCH ====================
$search_query = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = $conn->real_escape_string($_GET['search']);
    $search_query = "AND (username LIKE '%$search_term%' OR email LIKE '%$search_term%' OR nik LIKE '%$search_term%')";
}

$status_filter = "";
if (isset($_GET['status']) && $_GET['status'] !== 'all') {
    $status = $conn->real_escape_string($_GET['status']);
    $status_filter = "AND status = '$status'";
}

// Mengambil data pembeli dari database dengan filter
$sql = "SELECT * FROM users WHERE role = 'pembeli' $search_query $status_filter ORDER BY created_at DESC";
$result = $conn->query($sql);

// Menghitung statistik
$active_count = 0;
$inactive_count = 0;
$total_transaksi = 0;
$pembeli_data = array();

// Hitung total semua pembeli (tanpa filter)
$count_query = "SELECT COUNT(*) as total FROM users WHERE role = 'pembeli'";
$count_result = $conn->query($count_query);
$total_all = $count_result->fetch_assoc()['total'];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $pembeli_data[] = $row;
        
        // Menghitung status aktif/tidak aktif
        if ($row['status'] == 'aktif') {
            $active_count++;
        } else {
            $inactive_count++;
        }
    }
}

$total_pembeli = count($pembeli_data);

// Ambil data untuk modal detail (jika ada parameter detail)
$detail_data = null;
if (isset($_GET['detail_id'])) {
    $detail_id = intval($_GET['detail_id']);
    $query = "SELECT * FROM users WHERE id_user = ? AND role = 'pembeli'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $detail_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $detail_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Ambil data untuk modal edit (jika ada parameter edit)
$edit_data = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $query = "SELECT * FROM users WHERE id_user = ? AND role = 'pembeli'";
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
    <title>Wesley Bookstore - Data Pembeli</title>
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
        
        /* Stats Cards */
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
        
        /* Pembeli Table */
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
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
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
        }
        
        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        .pembeli-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .pembeli-table th {
            background-color: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e9ecef;
        }
        
        .pembeli-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .pembeli-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* User Avatar in Table */
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e1e5eb;
        }
        
        .user-avatar-small {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e1e5eb;
        }
        
        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-initials {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
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
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            padding: 5px;
        }
        
        .modal-body {
            padding: 30px;
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
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
        }
        
        /* Password Display */
        .password-field {
            font-family: monospace;
            letter-spacing: 1px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
        
        .table-search {
            display: flex;
            gap: 10px;
            flex-grow: 1;
        }
        
        .table-search-box {
            position: relative;
            flex-grow: 1;
            max-width: 300px;
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
        
        .search-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
        }
        
        .clear-search {
            padding: 12px 20px;
            background: #f8f9fa;
            color: var(--dark);
            border: 1px solid #e1e5eb;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .clear-search:hover {
            background: #e9ecef;
        }
        
        .status-filter select {
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            min-width: 150px;
            cursor: pointer;
        }
        
        /* Detail Modal Styles */
        .detail-info {
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
            display: block;
        }
        
        .detail-value {
            color: var(--gray);
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 15px;
            word-break: break-word;
        }
        
        .detail-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e1e5eb;
            margin: 0 auto 20px;
            display: block;
        }
        
        .detail-initials {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 2rem;
            margin: 0 auto 20px;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        /* Table Footer */
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
            
            .filter-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .table-search, .status-filter {
                width: 100%;
                max-width: none;
            }
            
            .table-search-box {
                max-width: none;
            }
            
            .pembeli-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .pembeli-table td, 
            .pembeli-table th {
                padding: 10px 8px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
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
            
            .pembeli-table {
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
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
                <a href="categories.php" class="sidebar-item">
                    <i class="fas fa-tags"></i>
                    <span>Categories</span>
                </a>
                <a href="pembeli.php" class="sidebar-item active">
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
            <h1 class="page-title">Data Pembeli</h1>
            <div class="breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Pembeli</span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $active_count; ?></div>
                    <div class="stat-label">Pembeli Aktif</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--danger) 0%, #bd2130 100%);">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $inactive_count; ?></div>
                    <div class="stat-label">Pembeli Tidak Aktif</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $total_pembeli; ?></div>
                    <div class="stat-label">Total Pembeli</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent) 0%, #8e44ad 100%);">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $total_transaksi; ?></div>
                    <div class="stat-label">Total Transaksi</div>
                </div>
            </div>
        </div>

        <!-- Tabel Pembeli -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i> Daftar Pembeli
                </h2>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="" class="table-search-form">
                    <div class="table-search">
                        <div class="table-search-box">
                            <input type="text" 
                                   name="search" 
                                   id="searchInput" 
                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" 
                                   placeholder="Cari pembeli...">
                            <i class="fas fa-search"></i>
                        </div>
                        <select name="status" id="statusFilter" class="status-filter">
                            <option value="all" <?php echo (!isset($_GET['status']) || $_GET['status'] == 'all') ? 'selected' : ''; ?>>Semua Status</option>
                            <option value="aktif" <?php echo (isset($_GET['status']) && $_GET['status'] == 'aktif') ? 'selected' : ''; ?>>Aktif</option>
                            <option value="tidak aktif" <?php echo (isset($_GET['status']) && $_GET['status'] == 'tidak aktif') ? 'selected' : ''; ?>>Tidak Aktif</option>
                        </select>
                        <button type="submit" class="search-btn">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                        <a href="pembeli.php<?php echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '?status=' . urlencode($_GET['status']) : ''; ?>" class="clear-search">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <table class="pembeli-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Gambar</th>
                            <th>NIK</th>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>Password</th>
                            <th>Alamat</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="pembeliTableBody">
                        <?php
                        $no = 1;
                        if (!empty($pembeli_data)):
                        foreach ($pembeli_data as $pembeli):
                            $status = $pembeli['status'];
                            $status_text = $status == 'aktif' ? 'Aktif' : 'Tidak Aktif';
                            $is_active = $status == 'aktif';
                            $alamat = isset($pembeli['alamat']) ? $pembeli['alamat'] : '-';
                            $nik = isset($pembeli['nik']) ? $pembeli['nik'] : '-';
                            $gambar = isset($pembeli['gambar']) ? $pembeli['gambar'] : 'default.png';
                            $gambar_path = '../asset/' . htmlspecialchars($gambar);
                            $username = htmlspecialchars($pembeli['username']);
                        ?>
                        <tr data-status="<?php echo $status; ?>" data-id="<?php echo $pembeli['id_user']; ?>">
                            <td><?php echo $no++; ?></td>
                            <td>
                                <?php if (file_exists($gambar_path) && $gambar != 'default.png'): ?>
                                    <img src="<?php echo $gambar_path; ?>" alt="Profile" class="user-avatar" 
                                         onerror="this.src='../asset/default.png';this.onerror=null;this.className='user-avatar'">
                                <?php else: ?>
                                    <div class="user-initials"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($nik); ?></td>
                            <td>
                                <div class="user-info-cell">
                                    <span><?php echo $username; ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($pembeli['email']); ?></td>
                            <td class="password-field"><?php echo htmlspecialchars(substr($pembeli['password'], 0, 20)); ?>...</td>
                            <td><?php echo htmlspecialchars(substr($alamat, 0, 30)) . (strlen($alamat) > 30 ? '...' : ''); ?></td>
                            <td>
                                <span class="status-badge <?php echo $status == 'aktif' ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-edit" 
                                            onclick="editPembeli(<?php echo $pembeli['id_user']; ?>)" 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-action btn-detail" 
                                            onclick="viewDetail(<?php echo $pembeli['id_user']; ?>)" 
                                            title="Detail">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (!$is_active): ?>
                                    <a href="?delete_id=<?php echo $pembeli['id_user']; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['status']) ? '&status=' . urlencode($_GET['status']) : ''; ?>" 
                                       class="btn-action btn-delete" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus pembeli <?php echo htmlspecialchars(addslashes($username)); ?>?')" 
                                       title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                <i class="fas fa-user-slash" style="font-size: 3rem; color: #ddd; margin-bottom: 15px; display: block;"></i>
                                <p style="color: #6c757d; font-size: 1.1rem;">
                                    <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                                        Tidak ada data pembeli yang cocok dengan pencarian "<?php echo htmlspecialchars($_GET['search']); ?>"
                                    <?php else: ?>
                                        Tidak ada data pembeli
                                    <?php endif; ?>
                                </p>
                                <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                                <a href="pembeli.php" class="btn btn-secondary" style="margin-top: 15px; text-decoration: none;">
                                    <i class="fas fa-times"></i> Hapus Pencarian
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Table Footer -->
            <div class="table-footer">
                <div>
                    <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                        Menampilkan <?php echo $total_pembeli; ?> dari <?php echo $total_all; ?> pembeli untuk pencarian "<?php echo htmlspecialchars($_GET['search']); ?>"
                    <?php elseif (isset($_GET['status']) && $_GET['status'] !== 'all'): ?>
                        Menampilkan <?php echo $total_pembeli; ?> dari <?php echo $total_all; ?> pembeli dengan status "<?php echo htmlspecialchars($_GET['status']); ?>"
                    <?php else: ?>
                        Menampilkan semua <?php echo $total_all; ?> pembeli
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Detail -->
    <div class="modal" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Detail Pembeli</h3>
                <button class="modal-close" onclick="closeDetailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if ($detail_data): ?>
                <div style="text-align: center;">
                    <?php
                    $gambar = isset($detail_data['gambar']) ? $detail_data['gambar'] : 'default.png';
                    $gambar_path = '../asset/' . htmlspecialchars($gambar);
                    $username = htmlspecialchars($detail_data['username']);
                    ?>
                    <?php if (file_exists($gambar_path) && $gambar != 'default.png'): ?>
                        <img src="<?php echo $gambar_path; ?>" alt="Profile" class="detail-avatar">
                    <?php else: ?>
                        <div class="detail-initials"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="detail-info">
                    <span class="detail-label">ID Pembeli:</span>
                    <div class="detail-value"><?php echo $detail_data['id_user']; ?></div>
                    
                    <span class="detail-label">NIK:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['nik'] ?? '-'); ?></div>
                    
                    <span class="detail-label">Nama:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['username']); ?></div>
                    
                    <span class="detail-label">Email:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['email']); ?></div>
                    
                    <span class="detail-label">Password:</span>
                    <div class="detail-value password-field"><?php echo htmlspecialchars($detail_data['password']); ?></div>
                    
                    <span class="detail-label">Alamat:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['alamat'] ?? '-'); ?></div>
                    
                    <span class="detail-label">Status:</span>
                    <div class="detail-value">
                        <span class="status-badge <?php echo $detail_data['status'] == 'aktif' ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $detail_data['status'] == 'aktif' ? 'Aktif' : 'Tidak Aktif'; ?>
                        </span>
                    </div>
                    
                    <span class="detail-label">Tanggal Dibuat:</span>
                    <div class="detail-value"><?php echo date('d-m-Y H:i:s', strtotime($detail_data['created_at'])); ?></div>
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px 0;">Data tidak ditemukan</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
            </div>
        </div>
    </div>

    <!-- Modal Edit -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Pembeli</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST" action="">
                    <input type="hidden" name="update_pembeli" value="1">
                    <input type="hidden" id="editId" name="id_user" value="<?php echo $edit_data['id_user'] ?? ''; ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="editNik">NIK</label>
                        <input type="text" class="form-control" id="editNik" name="nik" 
                               value="<?php echo htmlspecialchars($edit_data['nik'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editNama">Nama</label>
                        <input type="text" class="form-control" id="editNama" name="username" 
                               value="<?php echo htmlspecialchars($edit_data['username'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editEmail">Email</label>
                        <input type="email" class="form-control" id="editEmail" name="email" 
                               value="<?php echo htmlspecialchars($edit_data['email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editPassword">Password</label>
                        <input type="password" class="form-control" id="editPassword" name="password" 
                               placeholder="Kosongkan jika tidak ingin mengubah password">
                        <small style="color: var(--gray); font-size: 0.85rem;">Kosongkan jika tidak ingin mengubah password</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editAlamat">Alamat</label>
                        <textarea class="form-control" id="editAlamat" name="alamat" rows="3"><?php echo htmlspecialchars($edit_data['alamat'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editStatus">Status</label>
                        <select class="form-control" id="editStatus" name="status">
                            <option value="aktif" <?php echo (isset($edit_data['status']) && $edit_data['status'] == 'aktif') ? 'selected' : ''; ?>>Aktif</option>
                            <option value="tidak aktif" <?php echo (isset($edit_data['status']) && $edit_data['status'] == 'tidak aktif') ? 'selected' : ''; ?>>Tidak Aktif</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="editForm" class="btn btn-primary">Simpan Perubahan</button>
            </div>
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

        // Aktifkan sidebar item saat diklik
        document.querySelectorAll('.sidebar-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Fungsi untuk detail pembeli
        function viewDetail(id) {
            window.location.href = '?detail_id=' + id + '<?php 
                echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '&status=' . urlencode($_GET['status']) : '';
            ?>';
        }

        // Fungsi untuk edit pembeli
        function editPembeli(id) {
            window.location.href = '?edit_id=' + id + '<?php 
                echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '&status=' . urlencode($_GET['status']) : '';
            ?>';
        }

        // Fungsi modal
        function closeDetailModal() {
            document.getElementById('detailModal').classList.remove('show');
            // Hapus parameter detail_id dari URL tanpa reload
            const url = new URL(window.location.href);
            url.searchParams.delete('detail_id');
            window.history.replaceState({}, document.title, url.toString());
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
            // Hapus parameter edit_id dari URL tanpa reload
            const url = new URL(window.location.href);
            url.searchParams.delete('edit_id');
            window.history.replaceState({}, document.title, url.toString());
        }

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

        // Jika ada parameter detail_id di URL, buka modal detail
        <?php if (isset($_GET['detail_id']) && $detail_data): ?>
        window.onload = function() {
            document.getElementById('detailModal').classList.add('show');
        };
        <?php endif; ?>

        // Jika ada parameter edit_id di URL, buka modal edit
        <?php if (isset($_GET['edit_id']) && $edit_data): ?>
        window.onload = function() {
            document.getElementById('editModal').classList.add('show');
        };
        <?php endif; ?>

        // Close modal when clicking outside
        document.addEventListener('click', (e) => {
            const detailModal = document.getElementById('detailModal');
            const editModal = document.getElementById('editModal');
            
            if (detailModal.classList.contains('show') && e.target === detailModal) {
                closeDetailModal();
            }
            
            if (editModal.classList.contains('show') && e.target === editModal) {
                closeEditModal();
            }
        });

        // Submit search form dengan Enter
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.closest('form').submit();
            }
        });
    </script>
</body>
</html>