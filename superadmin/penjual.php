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

// ==================== PROSES UPDATE ADMIN ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
    $id_user = intval($_POST['id_user']);
    $nik = trim($_POST['nik']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $role = trim($_POST['role']);
    $alamat = trim($_POST['alamat']);
    $nama_bank = trim($_POST['nama_bank'] ?? '');
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $status = trim($_POST['status']);
    
    // Validasi input
    if (empty($username) || empty($email)) {
        $notification['type'] = 'error';
        $notification['message'] = "Nama dan Email tidak boleh kosong!";
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
            // Handle upload gambar profil jika ada
            $gambar = null;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                $file_type = $_FILES['profile_image']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                    $gambar = 'profile_' . time() . '_' . uniqid() . '.' . $ext;
                    $upload_path = '../asset/' . $gambar;
                    
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                        // Hapus gambar lama jika bukan default
                        $old_img_query = "SELECT gambar FROM users WHERE id_user = ?";
                        $old_img_stmt = $conn->prepare($old_img_query);
                        $old_img_stmt->bind_param("i", $id_user);
                        $old_img_stmt->execute();
                        $old_img_result = $old_img_stmt->get_result();
                        
                        if ($old_img_result->num_rows > 0) {
                            $old_user = $old_img_result->fetch_assoc();
                            $old_gambar = $old_user['gambar'];
                            if ($old_gambar && $old_gambar != 'default.png' && file_exists('../asset/' . $old_gambar)) {
                                unlink('../asset/' . $old_gambar);
                            }
                        }
                        $old_img_stmt->close();
                    } else {
                        $gambar = null; // Jika gagal upload, tetap gunakan gambar lama
                    }
                }
            }
            
            // Update data admin
            if (!empty($_POST['password'])) {
                // Jika password diisi, update dengan password baru (hash)
                $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
                if ($gambar) {
                    // Update dengan gambar profil baru
                    $update_query = "UPDATE users SET nik = ?, username = ?, email = ?, password = ?, role = ?, alamat = ?, nama_bank = ?, no_rekening = ?, status = ?, gambar = ? WHERE id_user = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("ssssssssssi", $nik, $username, $email, $password, $role, $alamat, $nama_bank, $no_rekening, $status, $gambar, $id_user);
                } else {
                    // Update tanpa gambar baru
                    $update_query = "UPDATE users SET nik = ?, username = ?, email = ?, password = ?, role = ?, alamat = ?, nama_bank = ?, no_rekening = ?, status = ? WHERE id_user = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("sssssssssi", $nik, $username, $email, $password, $role, $alamat, $nama_bank, $no_rekening, $status, $id_user);
                }
            } else {
                // Jika password tidak diisi, update tanpa mengubah password
                if ($gambar) {
                    $update_query = "UPDATE users SET nik = ?, username = ?, email = ?, role = ?, alamat = ?, nama_bank = ?, no_rekening = ?, status = ?, gambar = ? WHERE id_user = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("sssssssssi", $nik, $username, $email, $role, $alamat, $nama_bank, $no_rekening, $status, $gambar, $id_user);
                } else {
                    $update_query = "UPDATE users SET nik = ?, username = ?, email = ?, role = ?, alamat = ?, nama_bank = ?, no_rekening = ?, status = ? WHERE id_user = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("ssssssssi", $nik, $username, $email, $role, $alamat, $nama_bank, $no_rekening, $status, $id_user);
                }
            }
            
            if ($update_stmt->execute()) {
                $notification['type'] = 'success';
                $notification['message'] = "Data admin berhasil diupdate!";
            } else {
                $notification['type'] = 'error';
                $notification['message'] = "Gagal mengupdate data: " . $conn->error;
            }
            
            if (isset($update_stmt)) $update_stmt->close();
        }
        $check_stmt->close();
    }
}

// ==================== PROSES DELETE ADMIN ====================
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Ambil data admin untuk konfirmasi
    $query = "SELECT username, gambar FROM users WHERE id_user = ? AND (role = 'admin' OR role = 'penjual')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        $nama_admin = $admin['username'];
        $gambar = $admin['gambar'];
        
        // Hapus gambar profil jika bukan default
        if ($gambar && $gambar != 'default.png' && file_exists('../asset/' . $gambar)) {
            unlink('../asset/' . $gambar);
        }
        
        // Hapus admin
        $delete_query = "DELETE FROM users WHERE id_user = ? AND (role = 'admin' OR role = 'penjual')";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $delete_id);
        
        if ($delete_stmt->execute()) {
            $notification['type'] = 'success';
            $notification['message'] = "Admin '$nama_admin' berhasil dihapus!";
        } else {
            $notification['type'] = 'error';
            $notification['message'] = "Gagal menghapus admin: " . $conn->error;
        }
        $delete_stmt->close();
    } else {
        $notification['type'] = 'error';
        $notification['message'] = "Admin tidak ditemukan!";
    }
    $stmt->close();
}

// ==================== PROSES SEARCH ====================
$search_query = "";
$search_params = "";

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = $conn->real_escape_string($_GET['search']);
    $search_query = "AND (username LIKE '%$search_term%' OR email LIKE '%$search_term%' OR nik LIKE '%$search_term%' OR alamat LIKE '%$search_term%' OR nama_bank LIKE '%$search_term%' OR no_rekening LIKE '%$search_term%')";
    $search_params = "&search=" . urlencode($_GET['search']);
}

$status_filter = "";
$status_params = "";

if (isset($_GET['status']) && $_GET['status'] !== 'all') {
    $status = $conn->real_escape_string($_GET['status']);
    $status_filter = "AND status = '$status'";
    $status_params = "&status=" . urlencode($_GET['status']);
}

// ==================== PAGINATION SETUP ====================
$rows_per_page = 5; // Jumlah data per halaman

// Hitung total data untuk pagination (tanpa limit)
$count_sql = "SELECT COUNT(*) as total FROM users WHERE (role = 'admin' OR role = 'penjual') $search_query $status_filter";
$count_result = $conn->query($count_sql);
$total_rows = $count_result->fetch_assoc()['total'];

// Hitung total halaman
$total_pages = ceil($total_rows / $rows_per_page);

// Tentukan halaman saat ini
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

// Hitung offset
$offset = ($current_page - 1) * $rows_per_page;

// ==================== AMBIL DATA DENGAN PAGINATION ====================
$sql = "SELECT * FROM users WHERE (role = 'admin' OR role = 'penjual') $search_query $status_filter ORDER BY id_user DESC LIMIT $offset, $rows_per_page";
$result = $conn->query($sql);

// Menghitung statistik
$active_count = 0;
$inactive_count = 0;
$admin_count = 0;
$penjual_count = 0;
$admin_data = array();

// Hitung total semua admin (tanpa filter)
$count_query = "SELECT COUNT(*) as total FROM users WHERE role = 'admin' OR role = 'penjual'";
$count_result = $conn->query($count_query);
$total_all = $count_result->fetch_assoc()['total'];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $admin_data[] = $row;
        
        // Hitung status
        $status_value = trim($row['status']);
        if ($status_value === '1' || $status_value === 1 || 
            strtolower($status_value) === 'aktif' || 
            strtolower($status_value) === 'active' ||
            strtolower($status_value) === 'true' ||
            strtolower($status_value) === 'yes' ||
            strtolower($status_value) === 'y' ||
            $status_value === true) {
            $active_count++;
        } else {
            $inactive_count++;
        }
        
        // Hitung role
        if ($row['role'] == 'admin') {
            $admin_count++;
        } else {
            $penjual_count++;
        }
    }
}

$total_admin = count($admin_data);

// Ambil data untuk modal detail (jika ada parameter detail)
$detail_data = null;
if (isset($_GET['detail_id'])) {
    $detail_id = intval($_GET['detail_id']);
    $query = "SELECT * FROM users WHERE id_user = ? AND (role = 'admin' OR role = 'penjual')";
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
    $query = "SELECT * FROM users WHERE id_user = ? AND (role = 'admin' OR role = 'penjual')";
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
    <title>Wesley Bookstore - Data Admin</title>
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
        
        /* Admin Table */
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
            text-decoration: none;
        }
        
        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .admin-table th {
            background-color: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e9ecef;
        }
        
        .admin-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .admin-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* User Avatar in Table */
        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9ecef;
        }
        
        .profile-img-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e9ecef;
        }
        
        .user-info-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-initials {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
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
        
        .role-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .role-admin {
            background-color: rgba(74, 144, 226, 0.1);
            color: var(--secondary);
        }
        
        .role-penjual {
            background-color: rgba(155, 89, 182, 0.1);
            color: var(--accent);
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
            max-width: 700px;
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
        
        /* Bank Information Section */
        .bank-info-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            border-left: 4px solid var(--secondary);
        }
        
        .bank-info-title {
            font-size: 1.1rem;
            color: var(--primary);
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .bank-info-title i {
            color: var(--secondary);
        }
        
        /* Image Preview Styles */
        .image-preview-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .image-preview {
            width: 150px;
            height: 150px;
            border-radius: 10px;
            object-fit: cover;
            border: 2px solid #e1e5eb;
            display: block;
        }
        
        .image-preview-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .image-preview-info {
            flex-grow: 1;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 30px;
            gap: 8px;
        }
        
        .pagination-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            color: var(--dark);
            text-decoration: none;
            font-weight: 600;
            border: 2px solid #e1e5eb;
            transition: all 0.3s ease;
        }
        
        .pagination-link:hover {
            background: #f8f9fa;
            border-color: var(--secondary);
            color: var(--secondary);
        }
        
        .pagination-link.active {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            border-color: var(--secondary);
        }
        
        .pagination-link.disabled {
            background: #f8f9fa;
            color: var(--gray);
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .pagination-ellipsis {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            color: var(--gray);
        }
        
        .pagination-info {
            text-align: center;
            margin-top: 15px;
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
            
            .admin-table {
                font-size: 0.9rem;
            }
            
            .admin-table th,
            .admin-table td {
                padding: 10px 8px;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .modal-content {
                width: 95%;
                max-width: 95%;
            }
            
            .image-preview-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .image-preview {
                width: 120px;
                height: 120px;
            }
            
            .pagination {
                flex-wrap: wrap;
                gap: 5px;
            }
            
            .pagination-link {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
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
            
            .pagination {
                gap: 3px;
            }
            
            .pagination-link {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 0;
            height: 0;
        }

        * {
            scrollbar-width: none;
        }

        * {
            -ms-overflow-style: none;
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
                <input type="text" placeholder="Type here to search books, authors, categories...">
                <i class="fas fa-search search-icon"></i>
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
                <a href="pembeli.php" class="sidebar-item">
                    <i class="fas fa-user"></i>
                    <span>Pembeli</span>
                </a>
                <a href="penjual.php" class="sidebar-item active">
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
            <h1 class="page-title">Data Admin/Penjual</h1>
            <div class="breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Admin</span>
            </div>
        </div>

        <!-- Notification -->
        <?php if (!empty($notification['message'])): ?>
        <div class="notification-container" id="notificationContainer">
            <div class="alert alert-<?php echo $notification['type']; ?>" style="
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                background-color: <?php echo $notification['type'] === 'success' ? '#d4edda' : '#f8d7da'; ?>;
                color: <?php echo $notification['type'] === 'success' ? '#155724' : '#721c24'; ?>;
                border: 1px solid <?php echo $notification['type'] === 'success' ? '#c3e6cb' : '#f5c6cb'; ?>;
                animation: slideInRight 0.3s ease;
            ">
                <i class="fas fa-<?php echo $notification['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($notification['message']); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $active_count; ?></div>
                    <div class="stat-label">Admin Aktif</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--danger) 0%, #bd2130 100%);">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $inactive_count; ?></div>
                    <div class="stat-label">Admin Tidak Aktif</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $penjual_count; ?></div>
                    <div class="stat-label">Penjual</div>
                </div>
            </div>
        </div>

        <!-- Admin Table Section -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Daftar Admin/Penjual</h2>
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Tambah Admin Baru
                </a>
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
                                   placeholder="Cari admin...">
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
                        <a href="penjual.php<?php 
                            echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '?status=' . urlencode($_GET['status']) : '';
                        ?>" class="clear-search">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Gambar</th>
                            <th>NIK</th>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>Password</th>
                            <th>Role</th>
                            <th>Alamat</th>
                            <th>Nama Bank</th>
                            <th>No. Rekening</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="adminTableBody">
                        <?php
                        $start_no = ($current_page - 1) * $rows_per_page + 1;
                        if (!empty($admin_data)):
                        foreach ($admin_data as $admin):
                            // Escape special characters
                            $id = htmlspecialchars($admin['id_user'], ENT_QUOTES, 'UTF-8');
                            $name = htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8');
                            $nik = isset($admin['nik']) ? htmlspecialchars($admin['nik'], ENT_QUOTES, 'UTF-8') : '-';
                            $email = htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8');
                            $password = htmlspecialchars($admin['password'], ENT_QUOTES, 'UTF-8');
                            $alamat = isset($admin['alamat']) ? htmlspecialchars($admin['alamat'], ENT_QUOTES, 'UTF-8') : '-';
                            $nama_bank = isset($admin['nama_bank']) ? htmlspecialchars($admin['nama_bank'], ENT_QUOTES, 'UTF-8') : '-';
                            $no_rekening = isset($admin['no_rekening']) ? htmlspecialchars($admin['no_rekening'], ENT_QUOTES, 'UTF-8') : '-';
                            $role = htmlspecialchars($admin['role'], ENT_QUOTES, 'UTF-8');
                            $gambar = isset($admin['gambar']) && !empty($admin['gambar']) ? $admin['gambar'] : 'default.png';
                            
                            // Cek file gambar
                            $gambar_path = '../asset/' . $gambar;
                            $gambar_url = file_exists($gambar_path) ? '../asset/' . $gambar : '../asset/default.png';
                            
                            // Cek status aktif
                            $status_value = trim($admin['status']);
                            $is_active = false;
                            
                            if ($status_value === '1' || $status_value === 1 || 
                                strtolower($status_value) === 'aktif' || 
                                strtolower($status_value) === 'active' ||
                                strtolower($status_value) === 'true' ||
                                strtolower($status_value) === 'yes' ||
                                strtolower($status_value) === 'y' ||
                                $status_value === true) {
                                $is_active = true;
                            }
                            
                            $status = $is_active ? 'aktif' : 'tidak aktif';
                            $status_text = $is_active ? 'Aktif' : 'Tidak Aktif';
                            $role_display = $role == 'admin' ? 'Admin' : 'Penjual';
                            $role_class = $role == 'admin' ? 'role-admin' : 'role-penjual';
                            
                            // Bersihkan alamat
                            $alamat_clean = preg_replace('/\s+/', ' ', trim($alamat));
                        ?>
                        <tr data-status="<?php echo $status; ?>" data-id="<?php echo $id; ?>">
                            <td><?php echo $start_no++; ?></td>
                            <td>
                                <?php if (file_exists($gambar_path) && $gambar != 'default.png'): ?>
                                    <img src="<?php echo $gambar_url; ?>" alt="Profile" class="profile-img">
                                <?php else: ?>
                                    <div class="user-initials"><?php echo strtoupper(substr($name, 0, 2)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $nik; ?></td>
                            <td>
                                <div class="user-info-cell">
                                    <span><?php echo $name; ?></span>
                                </div>
                            </td>
                            <td><?php echo $email; ?></td>
                            <td class="password-field"><?php echo substr($password, 0, 20); ?>...</td>
                            <td>
                                <span class="role-badge <?php echo $role_class; ?>">
                                    <?php echo $role_display; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($alamat_clean); ?></td>
                            <td><?php echo $nama_bank; ?></td>
                            <td><?php echo $no_rekening; ?></td>
                            <td>
                                <span class="status-badge <?php echo $is_active ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-edit" 
                                            onclick="editAdmin(<?php echo $id; ?>)" 
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-action btn-detail" 
                                            onclick="viewDetail(<?php echo $id; ?>)" 
                                            title="Detail">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="?delete_id=<?php echo $id; ?><?php 
                                        echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                                        echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '&status=' . urlencode($_GET['status']) : '';
                                        echo '&page=' . $current_page;
                                    ?>" 
                                       class="btn-action btn-delete" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus <?php echo $role_display; ?> <?php echo htmlspecialchars(addslashes($name)); ?>?')" 
                                       title="Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px;">
                                <i class="fas fa-user-slash" style="font-size: 3rem; color: #ddd; margin-bottom: 15px; display: block;"></i>
                                <p style="color: #6c757d; font-size: 1.1rem;">
                                    <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                                        Tidak ada data penjual yang cocok dengan pencarian "<?php echo htmlspecialchars($_GET['search']); ?>"
                                    <?php else: ?>
                                        Tidak ada data penjual
                                    <?php endif; ?>
                                </p>
                                <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                                <a href="penjual.php" class="btn btn-secondary" style="margin-top: 15px; text-decoration: none;">
                                    <i class="fas fa-times"></i> Hapus Pencarian
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Menampilkan <?php echo min($rows_per_page, $total_rows - $offset); ?> dari <?php echo $total_rows; ?> data
                    (Halaman <?php echo $current_page; ?> dari <?php echo $total_pages; ?>)
                </div>
                
                <div class="pagination">
                    <!-- First Page -->
                    <?php if ($current_page > 1): ?>
                    <a href="?page=1<?php echo $search_params . $status_params; ?>" class="pagination-link" title="Halaman Pertama">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <?php else: ?>
                    <span class="pagination-link disabled">
                        <i class="fas fa-angle-double-left"></i>
                    </span>
                    <?php endif; ?>
                    
                    <!-- Previous Page -->
                    <?php if ($current_page > 1): ?>
                    <a href="?page=<?php echo $current_page - 1; ?><?php echo $search_params . $status_params; ?>" class="pagination-link" title="Halaman Sebelumnya">
                        <i class="fas fa-angle-left"></i>
                    </a>
                    <?php else: ?>
                    <span class="pagination-link disabled">
                        <i class="fas fa-angle-left"></i>
                    </span>
                    <?php endif; ?>
                    
                    <!-- Page Numbers -->
                    <?php
                    // Tentukan range halaman yang ditampilkan
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    // Jika halaman pertama tidak termasuk, tambahkan ellipsis
                    if ($start_page > 1) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                    
                    // Tampilkan halaman
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <?php if ($i == $current_page): ?>
                        <span class="pagination-link active"><?php echo $i; ?></span>
                        <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search_params . $status_params; ?>" class="pagination-link"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                    <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                    
                    <!-- Next Page -->
                    <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?php echo $current_page + 1; ?><?php echo $search_params . $status_params; ?>" class="pagination-link" title="Halaman Berikutnya">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <?php else: ?>
                    <span class="pagination-link disabled">
                        <i class="fas fa-angle-right"></i>
                    </span>
                    <?php endif; ?>
                    
                    <!-- Last Page -->
                    <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?php echo $total_pages; ?><?php echo $search_params . $status_params; ?>" class="pagination-link" title="Halaman Terakhir">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                    <?php else: ?>
                    <span class="pagination-link disabled">
                        <i class="fas fa-angle-double-right"></i>
                    </span>
                    <?php endif; ?>
                </div>
                
                <!-- Jump to Page -->
                <?php if ($total_pages > 5): ?>
                <div class="pagination-jump" style="text-align: center; margin-top: 15px;">
                    <form method="GET" action="" style="display: inline-flex; gap: 10px; align-items: center;">
                        <?php if (isset($_GET['search'])): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search']); ?>">
                        <?php endif; ?>
                        <?php if (isset($_GET['status']) && $_GET['status'] !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($_GET['status']); ?>">
                        <?php endif; ?>
                        <span style="color: var(--gray); font-size: 0.9rem;">Lompat ke halaman:</span>
                        <input type="number" 
                               name="page" 
                               min="1" 
                               max="<?php echo $total_pages; ?>" 
                               value="<?php echo $current_page; ?>" 
                               style="width: 70px; padding: 8px; border: 2px solid #e1e5eb; border-radius: 6px; text-align: center;"
                               onchange="if(this.value > <?php echo $total_pages; ?>) this.value = <?php echo $total_pages; ?>; if(this.value < 1) this.value = 1;">
                        <button type="submit" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9rem;">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Table Footer -->
            <div class="table-footer">
                <div>
                    <?php if (isset($_GET['search']) && !empty($_GET['search'])): ?>
                        Total: <?php echo $total_rows; ?> admin/penjual untuk pencarian "<?php echo htmlspecialchars($_GET['search']); ?>"
                    <?php elseif (isset($_GET['status']) && $_GET['status'] !== 'all'): ?>
                        Total: <?php echo $total_rows; ?> admin/penjual dengan status "<?php echo htmlspecialchars($_GET['status']); ?>"
                    <?php else: ?>
                        Total: <?php echo $total_rows; ?> admin/penjual
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Detail -->
    <div class="modal" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Detail Admin/Penjual</h3>
                <button class="modal-close" onclick="closeDetailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <?php if ($detail_data): ?>
                <div style="text-align: center;">
                    <?php
                    $gambar = isset($detail_data['gambar']) && !empty($detail_data['gambar']) ? $detail_data['gambar'] : 'default.png';
                    $gambar_path = '../asset/' . $gambar;
                    $gambar_url = file_exists($gambar_path) && $gambar != 'default.png' ? '../asset/' . $gambar : '';
                    $username = htmlspecialchars($detail_data['username']);
                    ?>
                    <?php if ($gambar_url): ?>
                        <img src="<?php echo $gambar_url; ?>" alt="Profile" class="detail-avatar">
                    <?php else: ?>
                        <div class="detail-initials"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="detail-info">
                    <span class="detail-label">ID:</span>
                    <div class="detail-value"><?php echo $detail_data['id_user']; ?></div>
                    
                    <span class="detail-label">NIK:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['nik'] ?? '-'); ?></div>
                    
                    <span class="detail-label">Nama:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['username']); ?></div>
                    
                    <span class="detail-label">Email:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['email']); ?></div>
                    
                    <span class="detail-label">Password:</span>
                    <div class="detail-value password-field"><?php echo htmlspecialchars($detail_data['password']); ?></div>
                    
                    <span class="detail-label">Role:</span>
                    <div class="detail-value">
                        <span class="role-badge <?php echo $detail_data['role'] == 'admin' ? 'role-admin' : 'role-penjual'; ?>">
                            <?php echo $detail_data['role'] == 'admin' ? 'Admin' : 'Penjual'; ?>
                        </span>
                    </div>
                    
                    <span class="detail-label">Alamat:</span>
                    <div class="detail-value"><?php echo htmlspecialchars($detail_data['alamat'] ?? '-'); ?></div>
                    
                    <!-- Bank Information Section -->
                    <div class="bank-info-section">
                        <div class="bank-info-title">
                            <i class="fas fa-university"></i> Informasi Bank
                        </div>
                        
                        <span class="detail-label">Nama Bank:</span>
                        <div class="detail-value"><?php echo htmlspecialchars($detail_data['nama_bank'] ?? '-'); ?></div>
                        
                        <span class="detail-label">No. Rekening:</span>
                        <div class="detail-value"><?php echo htmlspecialchars($detail_data['no_rekening'] ?? '-'); ?></div>
                    </div>
                    
                    <span class="detail-label">Status:</span>
                    <div class="detail-value">
                        <?php
                        $status_value = trim($detail_data['status']);
                        $is_active_detail = false;
                        if ($status_value === '1' || $status_value === 1 || 
                            strtolower($status_value) === 'aktif' || 
                            strtolower($status_value) === 'active' ||
                            strtolower($status_value) === 'true' ||
                            strtolower($status_value) === 'yes' ||
                            strtolower($status_value) === 'y' ||
                            $status_value === true) {
                            $is_active_detail = true;
                        }
                        ?>
                        <span class="status-badge <?php echo $is_active_detail ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $is_active_detail ? 'Aktif' : 'Tidak Aktif'; ?>
                        </span>
                    </div>
                    
                    <span class="detail-label">Tanggal Dibuat:</span>
                    <div class="detail-value"><?php echo date('d-m-Y H:i:s', strtotime($detail_data['created_at'])); ?></div>
                    
                </div>
                <?php else: ?>
                <p style="text-align: center; color: var(--gray); padding: 40px 0;">Data tidak ditemukan</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Edit -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Admin/Penjual</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="update_admin" value="1">
                    <input type="hidden" id="editId" name="id_user" value="<?php echo $edit_data['id_user'] ?? ''; ?>">
                    
                    <!-- Profile Image Section -->
                    <div class="form-group">
                        <label class="form-label">Gambar Profil Saat Ini</label>
                        <div class="image-preview-container">
                            <div class="image-preview-row">
                                <?php if ($edit_data): 
                                    $gambar = isset($edit_data['gambar']) && !empty($edit_data['gambar']) ? $edit_data['gambar'] : 'default.png';
                                    $gambar_path = '../asset/' . $gambar;
                                    $gambar_url = file_exists($gambar_path) && $gambar != 'default.png' ? '../asset/' . $gambar : '';
                                    $username = htmlspecialchars($edit_data['username']);
                                ?>
                                <?php if ($gambar_url): ?>
                                    <img src="<?php echo $gambar_url; ?>" alt="Profile" class="image-preview">
                                <?php else: ?>
                                    <div class="detail-initials" style="width: 150px; height: 150px; font-size: 2.5rem;"><?php echo strtoupper(substr($username, 0, 2)); ?></div>
                                <?php endif; ?>
                                <div class="image-preview-info">
                                    <label class="form-label" for="editImage">Ubah Gambar Profil</label>
                                    <input type="file" class="form-control" id="editImage" name="profile_image" accept="image/*">
                                    <small style="color: var(--gray); font-size: 0.85rem;">Kosongkan jika tidak ingin mengubah gambar (JPEG, PNG, GIF, JPG maks 2MB)</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Basic Information -->
                    <div class="form-group">
                        <label class="form-label" for="editNik">NIK</label>
                        <input type="text" class="form-control" id="editNik" name="nik" 
                               value="<?php echo htmlspecialchars($edit_data['nik'] ?? ''); ?>">
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
                        <label class="form-label" for="editRole">Role</label>
                        <select class="form-control" id="editRole" name="role" required>
                            <option value="penjual" <?php echo (isset($edit_data['role']) && $edit_data['role'] == 'penjual') ? 'selected' : ''; ?>>Penjual</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editAlamat">Alamat</label>
                        <textarea class="form-control" id="editAlamat" name="alamat" rows="3"><?php echo htmlspecialchars($edit_data['alamat'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Bank Information Section -->
                    <div class="bank-info-section" style="margin-top: 20px; margin-bottom: 20px;">
                        <div class="bank-info-title">
                            <i class="fas fa-university"></i> Informasi Bank
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="editNamaBank">Nama Bank</label>
                            <input type="text" class="form-control" id="editNamaBank" name="nama_bank" 
                                   value="<?php echo htmlspecialchars($edit_data['nama_bank'] ?? ''); ?>"
                                   placeholder="Contoh: BCA, Mandiri, BRI, dll">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="editNoRekening">No. Rekening</label>
                            <input type="text" class="form-control" id="editNoRekening" name="no_rekening" 
                                   value="<?php echo htmlspecialchars($edit_data['no_rekening'] ?? ''); ?>"
                                   placeholder="Masukkan nomor rekening">
                            <small style="color: var(--gray); font-size: 0.85rem;">Hanya angka</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="editStatus">Status</label>
                        <select class="form-control" id="editStatus" name="status">
                            <?php
                            $is_active_edit = false;
                            if (isset($edit_data['status'])) {
                                $status_value = trim($edit_data['status']);
                                if ($status_value === '1' || $status_value === 1 || 
                                    strtolower($status_value) === 'aktif' || 
                                    strtolower($status_value) === 'active' ||
                                    strtolower($status_value) === 'true' ||
                                    strtolower($status_value) === 'yes' ||
                                    strtolower($status_value) === 'y' ||
                                    $status_value === true) {
                                    $is_active_edit = true;
                                }
                            }
                            ?>
                            <option value="aktif" <?php echo $is_active_edit ? 'selected' : ''; ?>>Aktif</option>
                            <option value="tidak aktif" <?php echo !$is_active_edit ? 'selected' : ''; ?>>Tidak Aktif</option>
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

        // Fungsi untuk detail admin
        function viewDetail(id) {
            window.location.href = '?detail_id=' + id + '<?php 
                echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '&status=' . urlencode($_GET['status']) : '';
                echo '&page=' . $current_page;
            ?>';
        }

        // Fungsi untuk edit admin
        function editAdmin(id) {
            window.location.href = '?edit_id=' + id + '<?php 
                echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                echo isset($_GET['status']) && $_GET['status'] !== 'all' ? '&status=' . urlencode($_GET['status']) : '';
                echo '&page=' . $current_page;
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

        // Validasi form edit
        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            const noRekening = document.getElementById('editNoRekening').value;
            const profileImage = document.getElementById('editImage');
            
            // Validasi nomor rekening harus angka jika diisi
            if (noRekening && !/^\d+$/.test(noRekening)) {
                e.preventDefault();
                alert('Nomor rekening harus berupa angka!');
                document.getElementById('editNoRekening').focus();
                return;
            }
            
            // Validasi ukuran file gambar profil (maks 2MB)
            if (profileImage && profileImage.files.length > 0) {
                const fileSize = profileImage.files[0].size / 1024 / 1024; // dalam MB
                if (fileSize > 2) {
                    e.preventDefault();
                    alert('Ukuran gambar profil maksimal 2MB!');
                    profileImage.value = '';
                    return;
                }
            }
        });

        // Preview gambar sebelum upload
        document.getElementById('editImage')?.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewContainer = document.querySelector('.image-preview-container .image-preview-row');
                    if (previewContainer) {
                        const existingImg = previewContainer.querySelector('img');
                        if (existingImg) {
                            existingImg.src = e.target.result;
                        } else {
                            const existingDiv = previewContainer.querySelector('.detail-initials');
                            if (existingDiv) {
                                existingDiv.style.display = 'none';
                            }
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.className = 'image-preview';
                            previewContainer.prepend(img);
                        }
                    }
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    </script>
</body>
</html>