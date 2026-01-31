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

// ==================== LOGIKA OTOMATIS UBAH STATUS KE SELESAI ====================
// Cek dan ubah status pesanan yang sudah dikirim lebih dari 1 hari dan sudah di-approve
$sql_update_status = "UPDATE transaksi 
                      SET status = 'selesai'
                      WHERE status = 'dikirim' 
                      AND approve = 'approve'
                      AND DATE_ADD(tanggal_transaksi, INTERVAL 1 DAY) <= NOW()
                      AND id_user = ?";
$stmt_update = $conn->prepare($sql_update_status);
$stmt_update->bind_param("i", $_SESSION['user_id']);
$stmt_update->execute();
$updated_count = $conn->affected_rows;
$stmt_update->close();

// Jika ada yang diupdate, simpan dalam session untuk notifikasi
if ($updated_count > 0) {
    $_SESSION['auto_update_message'] = "$updated_count pesanan telah otomatis diselesaikan";
}

// ==================== LANJUTAN KODE YANG SUDAH ADA ====================
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

// Fungsi untuk mendapatkan inisial dari username
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Fungsi untuk mendapatkan path gambar produk
function getProductImagePath($gambar_filename) {
    if (empty($gambar_filename)) {
        return null;
    }
    
    $img = trim($gambar_filename);
    $possible_paths = [
       "../asset/payment_proofs/" . $img,
        "../asset/uploads/" . $img,
        "../../produk/" . $img,
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

// ==================== GET SEARCH PARAMETER ====================
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// ==================== PAGINATION ====================
$records_per_page = 5; // Set 5 data per halaman

// HITUNG TOTAL RECORDS DENGAN FILTER SEARCH
$sql_count_where = "WHERE t.id_user = ?";
$sql_count_params = [$user_id];
$sql_count_types = "i";

if (!empty($search_query)) {
    $sql_count_where .= " AND (
        t.invoice_number LIKE ? OR 
        t.no_resi LIKE ? OR
        td.nama_produk LIKE ? OR
        u.username LIKE ?
    )";
    
    $search_term = "%" . $search_query . "%";
    $sql_count_params = [
        $user_id, 
        $search_term, 
        $search_term, 
        $search_term, 
        $search_term
    ];
    $sql_count_types = "issss";
}

$sql_count = "SELECT COUNT(DISTINCT t.id_transaksi) as total 
              FROM transaksi t
              LEFT JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
              LEFT JOIN produk p ON td.id_produk = p.id_produk
              LEFT JOIN users u ON p.id_penjual = u.id_user
              $sql_count_where";

$stmt_count = $conn->prepare($sql_count);
if (!empty($search_query)) {
    $stmt_count->bind_param($sql_count_types, ...$sql_count_params);
} else {
    $stmt_count->bind_param($sql_count_types, $user_id);
}
$stmt_count->execute();
$result_count = $stmt_count->get_result();
$total_records = $result_count->fetch_assoc()['total'];

// Hitung total halaman
$total_pages = ceil($total_records / $records_per_page);

// Dapatkan halaman saat ini dari URL
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Hitung offset
$offset = ($page - 1) * $records_per_page;

// ==================== QUERY UTAMA DENGAN SEARCH ====================
$sql_pesanan_where = "WHERE t.id_user = ?";
$sql_pesanan_params = [$user_id];
$sql_pesanan_types = "i";

if (!empty($search_query)) {
    $sql_pesanan_where .= " AND (
        t.invoice_number LIKE ? OR 
        t.no_resi LIKE ? OR
        td.nama_produk LIKE ? OR
        u.username LIKE ?
    )";
    
    $search_term = "%" . $search_query . "%";
    $sql_pesanan_params = [
        $user_id, 
        $search_term, 
        $search_term, 
        $search_term, 
        $search_term
    ];
    $sql_pesanan_types = "issss";
}

// MODIFIKASI QUERY: Ambil data transaksi dengan detail produk dan penjual + PAGINATION + SEARCH
$sql_pesanan = "SELECT 
                t.id_transaksi,
                t.invoice_number,
                t.id_user,
                t.total_harga,
                t.total_bayar,
                t.metode_pembayaran,
                t.status,
                t.tanggal_transaksi,
                t.bukti_pembayaran,
                t.approve,
                t.no_resi,
                t.kurir,
                td.id_produk,
                td.nama_produk,
                td.qty,
                td.harga,
                td.subtotal,
                p.id_penjual,
                u.username as nama_penjual,
                u.email as email_penjual
                FROM transaksi t
                LEFT JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                LEFT JOIN produk p ON td.id_produk = p.id_produk
                LEFT JOIN users u ON p.id_penjual = u.id_user
                $sql_pesanan_where
                ORDER BY t.tanggal_transaksi DESC, td.id_produk
                LIMIT ? OFFSET ?";

// Tambahkan parameter limit dan offset
$sql_pesanan_params[] = $records_per_page;
$sql_pesanan_params[] = $offset;
$sql_pesanan_types .= "ii";

$stmt_pesanan = $conn->prepare($sql_pesanan);
$stmt_pesanan->bind_param($sql_pesanan_types, ...$sql_pesanan_params);
$stmt_pesanan->execute();
$result_pesanan = $stmt_pesanan->get_result();

// Ambil semua detail untuk transaksi yang ditampilkan
$transaksi_ids = [];
$transaksi_data = [];
while ($row = $result_pesanan->fetch_assoc()) {
    $id_transaksi = $row['id_transaksi'];
    $transaksi_ids[] = $id_transaksi;
    
    if (!isset($transaksi_data[$id_transaksi])) {
        // Tentukan teks approve berdasarkan status dan approve
        $approve_value = $row['approve'];
        $status_value = $row['status'];
        
        // LOGIKA APPROVE: Jika status = 'ditolak', tampilkan "Ditolak"
        // Jika approve = 'approve', tampilkan "Disetujui"
        // Jika approve = 'tidak' dan status bukan 'ditolak', tampilkan "Menunggu"
        if ($status_value == 'ditolak') {
            $approve_text = 'Ditolak';
            $approve_badge_class = 'approve-tolak';
        } elseif ($approve_value == 'approve') {
            $approve_text = 'Disetujui';
            $approve_badge_class = 'approve-setuju';
        } elseif ($approve_value == 'tidak') {
            $approve_text = 'Menunggu';
            $approve_badge_class = 'approve-pending';
        } else {
            $approve_text = 'Menunggu';
            $approve_badge_class = 'approve-pending';
        }
        
        $transaksi_data[$id_transaksi] = [
            'id_transaksi' => $row['id_transaksi'],
            'invoice_number' => $row['invoice_number'],
            'id_user' => $row['id_user'],
            'total_harga' => $row['total_harga'],
            'total_bayar' => $row['total_bayar'],
            'metode_pembayaran' => $row['metode_pembayaran'],
            'status' => $row['status'],
            'tanggal_transaksi' => $row['tanggal_transaksi'],
            'bukti_pembayaran' => $row['bukti_pembayaran'],
            'approve' => $row['approve'],
            'approve_text' => $approve_text,
            'approve_badge_class' => $approve_badge_class,
            'no_resi' => $row['no_resi'],
            'kurir' => $row['kurir'],
            'id_penjual' => $row['id_penjual'],
            'nama_penjual' => $row['nama_penjual'],
            'produk_list' => [],
            'total_qty' => 0
        ];
    }
    
    // Tambahkan produk ke dalam array produk_list
    $transaksi_data[$id_transaksi]['produk_list'][] = [
        'id_produk' => $row['id_produk'],
        'nama_produk' => $row['nama_produk'],
        'qty' => $row['qty'],
        'harga' => $row['harga'],
        'subtotal' => $row['subtotal']
    ];
    
    // Hitung total qty
    $transaksi_data[$id_transaksi]['total_qty'] += $row['qty'];
}

// Ambil data untuk statistik menggunakan status dari transaksi
$sql_statistik = "SELECT 
                  COUNT(*) as total_pesanan,
                  SUM(CASE WHEN status = 'dikirim' THEN 1 ELSE 0 END) as total_dikirim,
                  SUM(CASE WHEN status = 'ditolak' THEN 1 ELSE 0 END) as total_ditolak,
                  SUM(CASE WHEN status = 'refund' THEN 1 ELSE 0 END) as total_refund,
                  SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) as total_selesai,
                  SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_pending
                  FROM transaksi WHERE id_user = ?";
$stmt_statistik = $conn->prepare($sql_statistik);
$stmt_statistik->bind_param("i", $user_id);
$stmt_statistik->execute();
$result_statistik = $stmt_statistik->get_result();
$statistik = $result_statistik->fetch_assoc();

// Array web ekspedisi
$ekspedisi_links = [
    'jne' => 'https://www.jne.co.id/id/tracking/trace',
    'tiki' => 'https://www.tiki.id/id/tracking',
    'pos' => 'https://www.posindonesia.co.id/id/tracking',
    'j&t' => 'https://jet.co.id/track',
    'sicepat' => 'https://www.sicepat.com/checkAwb',
    'anteraja' => 'https://anteraja.id/tracking',
    'ninja' => 'https://www.ninjaxpress.co/track',
    'lion' => 'https://lionparcel.com/track',
    'wahana' => 'https://www.wahana.com/cek-resi',
    'rex' => 'https://www.rex.co.id/tracking',
    'indah' => 'https://www.indahlogistics.com/tracking'
];

$conn->close();

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
    <title>Wesley Bookstore - Status Pesanan</title>
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

        .total-orders {
            font-size: 1rem;
            color: var(--gray);
            background: #f0f4f8;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
            flex-shrink: 0;
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
        }

        /* Orders Table */
        .orders-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            margin-bottom: 40px;
            width: 100%;
            overflow-x: auto;
        }

        .orders-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .orders-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .orders-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .orders-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .orders-table tr:hover {
            background: #f8f9fa;
        }

        /* Order Status Badges */
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-dikirim {
            background: #d4edda;
            color: #155724;
        }

        .status-ditolak {
            background: #f8d7da;
            color: #721c24;
        }

        .status-refund {
            background: #fff3cd;
            color: #856404;
        }

        .status-selesai {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-pending {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-processing {
            background: #cce5ff;
            color: #004085;
        }

        /* Approve Badges */
        .approve-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .approve-setuju {
            background: #d4edda;
            color: #155724;
        }

        .approve-tolak {
            background: #f8d7da;
            color: #721c24;
        }

        .approve-pending {
            background: #fff3cd;
            color: #856404;
        }

        /* Product Info - PERBAIKAN: Semua dalam 1 baris */
        .product-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
        }

        .product-name {
            font-weight: 500;
            color: var(--dark);
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .product-more {
            font-size: 0.8rem;
            color: var(--secondary);
            background: #e3f2fd;
            padding: 3px 8px;
            border-radius: 12px;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
        }

        .product-more:hover {
            background: #d1e7ff;
            text-decoration: none;
        }

        /* Bukti Pembayaran Card Modal */
        .bukti-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .bukti-modal.show {
            display: flex;
        }

        .bukti-card {
            background: white;
            width: 90%;
            max-width: 500px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .bukti-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bukti-title {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .bukti-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            transition: background 0.2s ease;
        }

        .bukti-close:hover {
            background: rgba(255,255,255,0.3);
        }

        .bukti-body {
            padding: 25px;
        }

        .bukti-info {
            margin-bottom: 20px;
        }

        .bukti-label {
            font-size: 0.9rem;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .bukti-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .bukti-image-container {
            text-align: center;
            margin-top: 20px;
        }

        .bukti-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .proof-link {
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: color 0.2s ease;
        }

        .proof-link:hover {
            color: #2c7be5;
            text-decoration: underline;
        }

        /* Resi & Link */
        .resi-number {
            font-family: monospace;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: 600;
            color: var(--primary);
        }

        .tracking-link {
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #e3f2fd;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .tracking-link:hover {
            background: #d1e7ff;
            text-decoration: none;
            transform: translateY(-2px);
        }

        /* Chat Link - Style Baru */
        .chat-link {
            color: #9b59b6;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f5eef9;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
            white-space: nowrap;
            margin-top: 5px;
        }

        .chat-link:hover {
            background: #ebd7f5;
            text-decoration: none;
            transform: translateY(-2px);
        }

        /* Aksi Container - PERBAIKAN: 1 baris semua */
        .aksi-container {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Refund Message */
        .refund-message {
            margin-top: 5px;
            font-size: 0.8rem;
            color: var(--danger);
            font-style: italic;
            background: #fff3cd;
            padding: 5px 10px;
            border-radius: 5px;
            border-left: 3px solid var(--warning);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            width: 100%;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .filter-group {
            margin-bottom: 10px;
        }

        .filter-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .filter-select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            box-sizing: border-box;
        }

        /* Order Total */
        .order-total {
            font-weight: 600;
            color: var(--success);
        }

        /* Search Results Info */
        .search-info {
            background: #e3f2fd;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .search-results-text {
            font-weight: 500;
            color: var(--secondary);
        }

        .clear-search-btn {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .clear-search-btn:hover {
            background: #2c7be5;
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
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
            margin-bottom: 20px;
        }

        .btn-primary {
            display: inline-block;
            padding: 10px 20px;
            background: var(--secondary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #2c7be5;
            transform: translateY(-2px);
        }

        /* ==================== PAGINATION STYLES ==================== */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .pagination .page-item {
            list-style: none;
        }

        .pagination .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 12px;
            border: 1px solid #e1e5eb;
            border-radius: 8px;
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            background: white;
        }

        .pagination .page-link:hover {
            background: #f0f4f8;
            border-color: var(--secondary);
            color: var(--secondary);
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            border-color: var(--secondary);
        }

        .pagination .page-item.disabled .page-link {
            background: #f8f9fa;
            color: var(--gray);
            cursor: not-allowed;
            border-color: #e1e5eb;
        }

        .pagination .page-link i {
            font-size: 0.9rem;
        }

        .pagination-info {
            text-align: center;
            margin-top: 15px;
            color: var(--gray);
            font-size: 0.9rem;
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
            
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
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
            
            .main-content {
                padding: 20px 15px;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .orders-container {
                padding: 15px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .aksi-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .pagination .page-link {
                min-width: 35px;
                height: 35px;
                padding: 0 8px;
                font-size: 0.9rem;
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
            
            .content-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .orders-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .user-details {
                display: none;
            }

            .notifications-dropdown {
                width: 280px;
                right: -20px;
            }
            
            .aksi-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .tracking-link, .chat-link {
                width: 100%;
                justify-content: center;
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
                <input type="text" placeholder="Cari pesanan..." id="globalSearch" value="<?php echo htmlspecialchars($search_query); ?>">
                <i class="fas fa-search search-icon" id="searchButton"></i>
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

            <!-- Notifications Dropdown -->
            <div class="notifications-dropdown" id="notificationsDropdown">
                <div class="notifications-header">
                    <h4 class="notifications-title">Notifikasi</h4>
                    <button class="mark-all-read" id="markAllReadBtn">Tandai semua dibaca</button>
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
                <a href="status.php" class="sidebar-item active">
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
            <h1 class="page-title">Status Pesanan</h1>
            <div class="total-orders">
                <?php if (!empty($search_query)): ?>
                    <?php echo $total_records; ?> pesanan ditemukan untuk "<?php echo htmlspecialchars($search_query); ?>"
                <?php else: ?>
                    <?php echo $total_records; ?> pesanan ditemukan | Halaman <?php echo $page; ?> dari <?php echo $total_pages; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($search_query)): ?>
        <div class="search-info">
            <div class="search-results-text">
                <i class="fas fa-search"></i>
                Menampilkan hasil pencarian untuk: <strong>"<?php echo htmlspecialchars($search_query); ?>"</strong>
                (<?php echo $total_records; ?> hasil ditemukan)
            </div>
            <a href="status.php" class="clear-search-btn">
                <i class="fas fa-times"></i> Hapus Pencarian
            </a>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #4a90e2 0%, #2c7be5 100%);">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $statistik['total_pesanan'] ?? 0; ?></div>
                    <div class="stat-label">Total Pesanan</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $statistik['total_dikirim'] ?? 0; ?></div>
                    <div class="stat-label">Dalam Pengiriman</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $statistik['total_refund'] ?? 0; ?></div>
                    <div class="stat-label">Proses Refund</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $statistik['total_selesai'] ?? 0; ?></div>
                    <div class="stat-label">Selesai</div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Filter Status</label>
                    <select class="filter-select" id="statusFilter">
                        <option value="">Semua Status</option>
                        <option value="dikirim">Dikirim</option>
                        <option value="ditolak">Ditolak</option>
                        <option value="refund">Refund</option>
                        <option value="selesai">Selesai</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Filter Approve</label>
                    <select class="filter-select" id="approveFilter">
                        <option value="">Semua Approve</option>
                        <option value="approve">Disetujui</option>
                        <option value="tidak">Ditolak</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Urutkan</label>
                    <select class="filter-select" id="sortFilter">
                        <option value="newest">Terbaru</option>
                        <option value="oldest">Terlama</option>
                        <option value="status">Status</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="orders-container">
            <div class="orders-header">
                <h2 class="orders-title">
                    <?php if (!empty($search_query)): ?>
                        Hasil Pencarian
                    <?php else: ?>
                        Daftar Pesanan Aktif
                    <?php endif; ?>
                </h2>
                <div class="total-orders">Menampilkan <?php echo count($transaksi_data); ?> dari <?php echo $total_records; ?> pesanan</div>
            </div>
            
            <?php if (count($transaksi_data) > 0): ?>
                <table class="orders-table" id="ordersTable">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Kode Pesanan</th>
                            <th>Produk & Qty</th>
                            <th>Total</th>
                            <th>Bukti</th>
                            <th>Approve</th>
                            <th>Status</th>
                            <th>Resi & Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = ($page - 1) * $records_per_page + 1;
                        foreach ($transaksi_data as $transaksi): 
                            
                            // Generate order code
                            $order_code = !empty($transaksi['invoice_number']) ? 
                                        $transaksi['invoice_number'] : 
                                        'ORD' . str_pad($transaksi['id_transaksi'], 6, '0', STR_PAD_LEFT);
                            
                            // Determine status badge class
                            $status_class = 'status-' . $transaksi['status'];
                            $status_text = ucfirst($transaksi['status']);
                            
                            // Approve badge (sudah dihitung di PHP)
                            $approve_class = $transaksi['approve_badge_class'];
                            $approve_text = $transaksi['approve_text'];
                            
                            // Calculate total
                            $total = $transaksi['total_bayar'] ?? ($transaksi['total_harga'] ?? 0);
                            $total_formatted = number_format($total, 0, ',', '.');
                            
                            // Payment method
                            $payment_method = $transaksi['metode_pembayaran'] ?? 'Transfer Bank';
                            
                            // For payment proof
                            $payment_proof = $transaksi['bukti_pembayaran'] ?? '';
                            
                            // Resi and courier
                            $nomor_resi = $transaksi['no_resi'] ?? '';
                            $kurir = $transaksi['kurir'] ?? 'jne';
                            $ekspedisi_link = $ekspedisi_links[$kurir] ?? $ekspedisi_links['jne'];
                            
                            // Penjual info
                            $id_penjual = $transaksi['id_penjual'] ?? 0;
                            $nama_penjual = $transaksi['nama_penjual'] ?? 'Penjual';
                            
                            // Product info
                            $produk_list = $transaksi['produk_list'];
                            $first_product = $produk_list[0] ?? null;
                            $total_qty = $transaksi['total_qty'];
                        ?>
                            <tr data-status="<?php echo $transaksi['status']; ?>" data-approve="<?php echo $transaksi['approve']; ?>">
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo $order_code; ?></strong></td>
                                <td>
                                    <div class="product-info">
                                        <?php if ($first_product): ?>
                                            <span class="product-name" title="<?php echo htmlspecialchars($first_product['nama_produk']); ?>">
                                                <?php echo htmlspecialchars($first_product['nama_produk']); ?>
                                            </span>
                                            <span style="color: var(--gray); font-weight: 600;">×<?php echo $total_qty; ?></span>
                                            <?php if (count($produk_list) > 1): ?>
                                                <span class="product-more" onclick="showProducts(<?php echo $transaksi['id_transaksi']; ?>, '<?php echo htmlspecialchars($order_code); ?>')">
                                                    +<?php echo count($produk_list) - 1; ?> lainnya
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span>Produk tidak ditemukan</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="order-total">Rp <?php echo $total_formatted; ?></td>
                                <td>
                                    <?php if (!empty($payment_proof)): ?>
                                        <a href="javascript:void(0);" class="proof-link" onclick="showBuktiPembayaran(<?php echo $transaksi['id_transaksi']; ?>, '<?php echo $order_code; ?>', '<?php echo getProductImagePath($payment_proof); ?>')">
                                            <i class="fas fa-eye"></i> Lihat
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-size: 0.85rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="approve-badge <?php echo $approve_class; ?>">
                                        <?php echo $approve_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="aksi-container">
                                        <?php if ($transaksi['status'] == 'dikirim' && !empty($nomor_resi)): ?>
                                            <a href="<?php echo $ekspedisi_link; ?>" 
                                               target="_blank" 
                                               class="tracking-link">
                                                <i class="fas fa-truck"></i>
                                                <?php echo substr($nomor_resi, 0, 10) . (strlen($nomor_resi) > 10 ? '...' : ''); ?>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($id_penjual > 0): ?>
                                            <a href="chat.php?action=start_chat&penjual_id=<?php echo $id_penjual; ?>&penjual_name=<?php echo urlencode($nama_penjual); ?>" 
                                               class="chat-link" id="chatLink<?php echo $transaksi['id_transaksi']; ?>">
                                                <i class="fas fa-comments"></i>
                                                Chat
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- ==================== PAGINATION ==================== -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <!-- Previous Page -->
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" aria-label="Previous">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="fas fa-chevron-left"></i></span>
                        </li>
                    <?php endif; ?>
                    
                    <!-- Page Numbers -->
                    <?php
                    // Tampilkan maksimal 5 nomor halaman
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $start_page + 4);
                    
                    // Adjust start_page if needed
                    if ($end_page - $start_page < 4) {
                        $start_page = max(1, $end_page - 4);
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <!-- Next Page -->
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" aria-label="Next">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="fas fa-chevron-right"></i></span>
                        </li>
                    <?php endif; ?>
                </div>
                
                <div class="pagination-info">
                    Menampilkan data <?php echo ($offset + 1); ?> - <?php echo min($offset + $records_per_page, $total_records); ?> dari <?php echo $total_records; ?> data
                </div>
                <?php endif; ?>
                <!-- ==================== END PAGINATION ==================== -->
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <?php if (!empty($search_query)): ?>
                        <h3>Tidak Ada Hasil Pencarian</h3>
                        <p>Tidak ditemukan pesanan dengan kata kunci "<?php echo htmlspecialchars($search_query); ?>"</p>
                        <a href="status.php" class="btn-primary">Lihat Semua Pesanan</a>
                    <?php else: ?>
                        <h3>Belum Ada Pesanan</h3>
                        <p>Anda belum memiliki pesanan aktif.</p>
                        <a href="produk.php" class="btn-primary">Mulai Belanja</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bukti Pembayaran Card Modal -->
        <div class="bukti-modal" id="buktiModal">
            <div class="bukti-card">
                <div class="bukti-header">
                    <h3 class="bukti-title">Bukti Pembayaran</h3>
                    <button class="bukti-close" id="buktiClose">&times;</button>
                </div>
                <div class="bukti-body">
                    <div class="bukti-info">
                        <div class="bukti-label">Nomor Pesanan</div>
                        <div class="bukti-value" id="buktiOrderNumber">-</div>
                    </div>
                    <div class="bukti-image-container">
                        <img id="buktiImage" src="" alt="Bukti Pembayaran" class="bukti-image">
                    </div>
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
        
        // Initialize system
        document.addEventListener('DOMContentLoaded', () => {
            window.realtimeBadgeSystem = new RealtimeBadgeSystem();
            
            // Tampilkan notifikasi auto update jika ada
            <?php if (isset($_SESSION['auto_update_message'])): ?>
                const autoUpdateMessage = "<?php echo $_SESSION['auto_update_message']; ?>";
                const toast = document.createElement('div');
                toast.className = 'notification-toast';
                toast.innerHTML = `
                    <div class="toast-content">
                        <i class="fas fa-check-circle"></i>
                        <span>${autoUpdateMessage}</span>
                        <button onclick="this.parentElement.parentElement.remove()">&times;</button>
                    </div>
                `;
                
                document.body.appendChild(toast);
                
                // Hapus toast setelah 5 detik
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 5000);
                
                // Hapus session message
                <?php unset($_SESSION['auto_update_message']); ?>
            <?php endif; ?>
            
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
            
            // ==================== SEARCH FUNCTIONALITY ====================
            const searchInput = document.getElementById('globalSearch');
            const searchButton = document.getElementById('searchButton');
            
            if (searchButton && searchInput) {
                searchButton.addEventListener('click', () => {
                    performSearch();
                });
                
                searchInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        performSearch();
                    }
                });
            }
            
            function performSearch() {
                const searchTerm = searchInput.value.trim();
                if (searchTerm) {
                    // Redirect ke halaman status dengan parameter search
                    window.location.href = `status.php?search=${encodeURIComponent(searchTerm)}`;
                } else {
                    // Jika search kosong, reload halaman tanpa parameter
                    window.location.href = 'status.php';
                }
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
            
            // Cart icon click event
            document.getElementById('cartIcon').addEventListener('click', function() {
                window.location.href = 'keranjang.php';
            });
            
            // ==================== AUTO CHECK AND UPDATE STATUS ====================
            // Check status otomatis setiap 10 menit
            setInterval(async () => {
                try {
                    await checkAndUpdateStatus();
                } catch (error) {
                    console.error('Gagal check status otomatis:', error);
                }
            }, 600000); // 10 menit
            
            // Check status saat halaman pertama kali dimuat
            setTimeout(async () => {
                try {
                    await checkAndUpdateStatus();
                } catch (error) {
                    console.error('Gagal check status awal:', error);
                }
            }, 3000);
        });
        
        // Fungsi untuk check dan update status
        async function checkAndUpdateStatus() {
            try {
                const response = await fetch('api_notifications.php?action=check_and_update_status');
                const data = await response.json();
                
                if (data.success && data.data.updated > 0) {
                    console.log(`✅ ${data.data.updated} pesanan otomatis diselesaikan`);
                    
                    // Tampilkan toast notification
                    const toast = document.createElement('div');
                    toast.className = 'notification-toast';
                    toast.innerHTML = `
                        <div class="toast-content">
                            <i class="fas fa-check-circle"></i>
                            <span>${data.data.updated} pesanan telah selesai!</span>
                            <button onclick="this.parentElement.parentElement.remove()">&times;</button>
                        </div>
                    `;
                    
                    document.body.appendChild(toast);
                    
                    // Refresh halaman setelah 3 detik
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.remove();
                        }
                        location.reload();
                    }, 3000);
                    
                    return true;
                }
                return false;
            } catch (error) {
                console.error('Error checking status:', error);
                return false;
            }
        }
        
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
                        document.dispatchEvent(new CustomEvent('messageRead'));
                        
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

        // Filter functionality (client-side filtering)
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        document.getElementById('approveFilter').addEventListener('change', applyFilters);
        document.getElementById('sortFilter').addEventListener('change', applyFilters);

        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const approve = document.getElementById('approveFilter').value;
            const sort = document.getElementById('sortFilter').value;
            
            const rows = Array.from(document.querySelectorAll('#ordersTable tbody tr'));
            
            // Filter rows
            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const rowApprove = row.getAttribute('data-approve');
                
                let isVisible = true;
                
                // Filter by status
                if (status && rowStatus !== status) {
                    isVisible = false;
                }
                
                // Filter by approve
                if (approve) {
                    if (approve === 'approve' && rowApprove !== 'approve') {
                        isVisible = false;
                    } else if (approve === 'tidak' && rowApprove !== 'tidak') {
                        isVisible = false;
                    } else if (approve === 'pending' && rowApprove === 'approve') {
                        isVisible = false;
                    }
                }
                
                row.style.display = isVisible ? '' : 'none';
            });
            
            // Sort visible rows
            const visibleRows = rows.filter(row => row.style.display !== 'none');
            
            visibleRows.sort((a, b) => {
                const aStatus = a.getAttribute('data-status');
                const bStatus = b.getAttribute('data-status');
                const aText = a.querySelector('td:nth-child(2)').textContent;
                const bText = b.querySelector('td:nth-child(2)').textContent;
                
                switch(sort) {
                    case 'newest':
                        return 0; // Already sorted by newest in PHP
                    case 'oldest':
                        return 1; // Reverse order
                    case 'status':
                        return aStatus.localeCompare(bStatus);
                    default:
                        return 0;
                }
            });
            
            // Reorder rows in DOM
            const tbody = document.querySelector('#ordersTable tbody');
            visibleRows.forEach(row => {
                tbody.appendChild(row);
            });
        }

        // Bukti Pembayaran Modal
        const buktiModal = document.getElementById('buktiModal');
        const buktiClose = document.getElementById('buktiClose');
        
        function showBuktiPembayaran(idTransaksi, orderCode, imageSrc) {
            document.getElementById('buktiOrderNumber').textContent = orderCode;
            document.getElementById('buktiImage').src = imageSrc;
            buktiModal.classList.add('show');
        }
        
        // Close modal dengan tombol X
        buktiClose.addEventListener('click', () => {
            buktiModal.classList.remove('show');
        });
        
        // Close modal ketika klik di luar card
        buktiModal.addEventListener('click', function(e) {
            if (e.target === this) {
                buktiModal.classList.remove('show');
            }
        });
        
        // Close modal dengan Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && buktiModal.classList.contains('show')) {
                buktiModal.classList.remove('show');
            }
        });

        // Fungsi untuk menampilkan produk dalam pesanan
        function showProducts(idTransaksi, orderCode) {
            alert(`Detail produk untuk pesanan ${orderCode}\n\nFitur ini akan menampilkan semua produk dalam pesanan.`);
            // Bisa diganti dengan modal untuk menampilkan detail produk
        }
    </script>
</body>
</html>