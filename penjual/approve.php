<?php
// Koneksi ke database
require_once '../koneksi.php';

// Mulai session
session_start();

// Cek apakah user sudah login dan role penjual
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'penjual') {
    // Jika bukan penjual, redirect ke halaman login atau dashboard
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

// Fungsi untuk mendapatkan link tracking berdasarkan kurir
function getTrackingLink($kurir, $resi) {
    if (empty($resi) || empty($kurir)) {
        return null;
    }
    
    $kurir = strtolower($kurir);
    
    $tracking_links = [
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
    
    if (isset($tracking_links[$kurir])) {
        return $tracking_links[$kurir] . '?resi=' . urlencode($resi);
    }
    
    // Cari berdasarkan kata kunci
    foreach ($tracking_links as $key => $link) {
        if (strpos($kurir, $key) !== false) {
            return $link . '?resi=' . urlencode($resi);
        }
    }
    
    return null;
}

// ============ NOTIFIKASI HANDLER ============
// Handle action untuk approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $transaction_id = intval($_POST['transaction_id']);
    $action = $_POST['action']; // 'approve', 'reject', atau 'update_status'
    
    if ($action === 'approve') {
        // Update status menjadi approve
        $sql = "UPDATE transaksi SET approve = 'approve', status = 'processing' WHERE id_transaksi = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $transaction_id);
        
        if ($stmt->execute()) {
            // ============ NOTIFIKASI APPROVE ============
            // Ambil data transaksi untuk notifikasi
            $sql_trans = "SELECT t.*, u.username as nama_pembeli FROM transaksi t 
                         JOIN users u ON t.id_user = u.id_user 
                         WHERE t.id_transaksi = ?";
            $stmt_trans = $conn->prepare($sql_trans);
            $stmt_trans->bind_param("i", $transaction_id);
            $stmt_trans->execute();
            $trans_result = $stmt_trans->get_result();
            
            if ($trans_result->num_rows > 0) {
                $trans_data = $trans_result->fetch_assoc();
                
                // Buat notifikasi untuk pembeli
                $notification_title = "Transaksi Disetujui";
                $notification_message = "Transaksi Anda dengan invoice {$trans_data['invoice_number']} telah disetujui oleh penjual dan sedang diproses.";
                
                $sql_notif = "INSERT INTO notifikasi (id_user, id_order, judul, pesan, type, created_at) 
                             VALUES (?, ?, ?, ?, 'notifikasi', NOW())";
                $stmt_notif = $conn->prepare($sql_notif);
                $stmt_notif->bind_param("iiss", $trans_data['id_user'], $transaction_id, $notification_title, $notification_message);
                $stmt_notif->execute();
            }
            // ============ END NOTIFIKASI ============
            
            $_SESSION['success_message'] = "Transaksi berhasil disetujui! Notifikasi telah dikirim ke pembeli.";
        } else {
            $_SESSION['error_message'] = "Gagal menyetujui transaksi!";
        }
        
    } elseif ($action === 'reject') {
        // Update status menjadi tidak dan status menjadi rejected
        $sql = "UPDATE transaksi SET approve = 'tidak', status = 'ditolak' WHERE id_transaksi = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $transaction_id);
        
        if ($stmt->execute()) {
            // ============ NOTIFIKASI REJECT ============
            // Ambil data transaksi untuk notifikasi
            $sql_trans = "SELECT t.*, u.username as nama_pembeli FROM transaksi t 
                         JOIN users u ON t.id_user = u.id_user 
                         WHERE t.id_transaksi = ?";
            $stmt_trans = $conn->prepare($sql_trans);
            $stmt_trans->bind_param("i", $transaction_id);
            $stmt_trans->execute();
            $trans_result = $stmt_trans->get_result();
            
            if ($trans_result->num_rows > 0) {
                $trans_data = $trans_result->fetch_assoc();
                
                // Buat notifikasi untuk pembeli
                $notification_title = "Transaksi Ditolak";
                $notification_message = "Transaksi Anda dengan invoice {$trans_data['invoice_number']} telah ditolak oleh penjual. Silakan hubungi penjual untuk informasi lebih lanjut.";
                
                $sql_notif = "INSERT INTO notifikasi (id_user, id_order, judul, pesan, type, created_at) 
                             VALUES (?, ?, ?, ?, 'notifikasi', NOW())";
                $stmt_notif = $conn->prepare($sql_notif);
                $stmt_notif->bind_param("iiss", $trans_data['id_user'], $transaction_id, $notification_title, $notification_message);
                $stmt_notif->execute();
                
                // Kembalikan stok produk
                $sql_items = "SELECT id_produk, qty FROM transaksi_detail WHERE id_transaksi = ?";
                $stmt_items = $conn->prepare($sql_items);
                $stmt_items->bind_param("i", $transaction_id);
                $stmt_items->execute();
                $items_result = $stmt_items->get_result();
                
                while ($item = $items_result->fetch_assoc()) {
                    $sql_restock = "UPDATE produk SET stok = stok + ? WHERE id_produk = ?";
                    $stmt_restock = $conn->prepare($sql_restock);
                    $stmt_restock->bind_param("ii", $item['qty'], $item['id_produk']);
                    $stmt_restock->execute();
                }
            }
            // ============ END NOTIFIKASI ============
            
            $_SESSION['success_message'] = "Transaksi berhasil ditolak! Notifikasi telah dikirim ke pembeli dan stok produk telah dikembalikan.";
        } else {
            $_SESSION['error_message'] = "Gagal menolak transaksi!";
        }
        
    } elseif ($action === 'update_status') {
        // Update status pengiriman dan resi
        $new_status = $_POST['status'];
        $resi = isset($_POST['resi']) ? trim($_POST['resi']) : null;
        $kurir = isset($_POST['kurir']) ? trim($_POST['kurir']) : null;
        
        if ($new_status === 'dikirim') {
            if (empty($resi)) {
                $_SESSION['error_message'] = "Nomor resi wajib diisi untuk status Dikirim!";
                header("Location: approve.php");
                exit();
            }
            
            // Update status, resi, dan kurir
            $sql = "UPDATE transaksi SET status = ?, no_resi = ?, kurir = ? WHERE id_transaksi = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $new_status, $resi, $kurir, $transaction_id);
        } else {
            // Update status saja
            $sql = "UPDATE transaksi SET status = ? WHERE id_transaksi = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $new_status, $transaction_id);
        }
        
        if ($stmt->execute()) {
            // ============ NOTIFIKASI BERDASARKAN STATUS ============
            // Ambil data transaksi untuk notifikasi
            $sql_trans = "SELECT t.*, u.username as nama_pembeli FROM transaksi t 
                         JOIN users u ON t.id_user = u.id_user 
                         WHERE t.id_transaksi = ?";
            $stmt_trans = $conn->prepare($sql_trans);
            $stmt_trans->bind_param("i", $transaction_id);
            $stmt_trans->execute();
            $trans_result = $stmt_trans->get_result();
            
            if ($trans_result->num_rows > 0) {
                $trans_data = $trans_result->fetch_assoc();
                $invoice = $trans_data['invoice_number'];
                
                switch($new_status) {
                    case 'dikirim':
                        $notification_title = "Pesanan Dikirim";
                        $notification_message = "Pesanan Anda dengan invoice $invoice telah dikirim. ";
                        if (!empty($resi) && !empty($kurir)) {
                            $notification_message .= "No. Resi: $resi ($kurir). ";
                            // Get tracking link
                            $tracking_link = getTrackingLink($kurir, $resi);
                            if ($tracking_link) {
                                $notification_message .= "Anda dapat melacak paket di: $tracking_link";
                            } else {
                                $notification_message .= "Silakan hubungi penjual untuk info tracking.";
                            }
                        }
                        break;
                        
                    case 'selesai':
                        $notification_title = "Pesanan Selesai";
                        $notification_message = "Pesanan Anda dengan invoice $invoice telah selesai. Terima kasih telah berbelanja!";
                        break;
                        
                    case 'processing':
                        $notification_title = "Pesanan Diproses";
                        $notification_message = "Pesanan Anda dengan invoice $invoice sedang diproses oleh penjual.";
                        break;
                        
                    default:
                        $notification_title = "Status Pesanan Diperbarui";
                        $notification_message = "Status pesanan Anda dengan invoice $invoice telah diperbarui menjadi: " . ucfirst($new_status);
                }
                
                $sql_notif = "INSERT INTO notifikasi (id_user, id_order, judul, pesan, type, created_at) 
                             VALUES (?, ?, ?, ?, 'notifikasi', NOW())";
                $stmt_notif = $conn->prepare($sql_notif);
                $stmt_notif->bind_param("iiss", $trans_data['id_user'], $transaction_id, $notification_title, $notification_message);
                $stmt_notif->execute();
            }
            // ============ END NOTIFIKASI ============
            
            $_SESSION['success_message'] = "Status transaksi berhasil diperbarui! Notifikasi telah dikirim ke pembeli.";
        } else {
            $_SESSION['error_message'] = "Gagal memperbarui status transaksi!";
        }
    }
    
    header("Location: approve.php");
    exit();
}

// Handle input resi langsung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['input_resi'])) {
    $transaction_id = intval($_POST['transaction_id']);
    $resi = trim($_POST['resi']);
    $kurir = trim($_POST['kurir']);
    
    if (empty($resi) || empty($kurir)) {
        $_SESSION['error_message'] = "Nomor resi dan kurir wajib diisi!";
        header("Location: approve.php");
        exit();
    }
    
    // Update status menjadi dikirim dengan resi
    $sql = "UPDATE transaksi SET status = 'dikirim', no_resi = ?, kurir = ? WHERE id_transaksi = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $resi, $kurir, $transaction_id);
    
    if ($stmt->execute()) {
        // ============ NOTIFIKASI PESANAN DIKIRIM ============
        // Ambil data transaksi untuk notifikasi
        $sql_trans = "SELECT t.*, u.username as nama_pembeli FROM transaksi t 
                     JOIN users u ON t.id_user = u.id_user 
                     WHERE t.id_transaksi = ?";
        $stmt_trans = $conn->prepare($sql_trans);
        $stmt_trans->bind_param("i", $transaction_id);
        $stmt_trans->execute();
        $trans_result = $stmt_trans->get_result();
        
        if ($trans_result->num_rows > 0) {
            $trans_data = $trans_result->fetch_assoc();
            $invoice = $trans_data['invoice_number'];
            
            // Buat notifikasi untuk pembeli dengan judul "Pesanan Dikirim"
            $notification_title = "Pesanan Dikirim";
            $notification_message = "Pesanan Anda dengan invoice $invoice telah dikirim. No. Resi: $resi ($kurir). ";
            
            // Get tracking link
            $tracking_link = getTrackingLink($kurir, $resi);
            if ($tracking_link) {
                $notification_message .= "Anda dapat melacak paket di: $tracking_link";
            } else {
                $notification_message .= "Silakan hubungi penjual untuk info tracking.";
            }
            
            $sql_notif = "INSERT INTO notifikasi (id_user, id_order, judul, pesan, type, created_at) 
                         VALUES (?, ?, ?, ?, 'notifikasi', NOW())";
            $stmt_notif = $conn->prepare($sql_notif);
            $stmt_notif->bind_param("iiss", $trans_data['id_user'], $transaction_id, $notification_title, $notification_message);
            $stmt_notif->execute();
        }
        // ============ END NOTIFIKASI ============
        
        $_SESSION['success_message'] = "Nomor resi berhasil ditambahkan! Notifikasi 'Pesanan Dikirim' telah dikirim ke pembeli.";
    } else {
        $_SESSION['error_message'] = "Gagal menambahkan nomor resi!";
    }
    
    header("Location: approve.php");
    exit();
}

// ============ PAGINATION HANDLER ============
$items_per_page = isset($_GET['items_per_page']) ? intval($_GET['items_per_page']) : 5;
if (!in_array($items_per_page, [5, 10, 20, 50])) {
    $items_per_page = 5;
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;

// Hitung total transaksi
$total_sql = "SELECT COUNT(DISTINCT t.id_transaksi) as total 
             FROM transaksi t
             INNER JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
             INNER JOIN produk p ON td.id_produk = p.id_produk
             WHERE p.id_penjual = ?";
$total_stmt = $conn->prepare($total_sql);
$total_stmt->bind_param("i", $user_id);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_data = $total_result->fetch_assoc();
$total_transactions = $total_data['total'] ?? 0;

// Hitung total halaman
$total_pages = ceil($total_transactions / $items_per_page);
if ($page > $total_pages) $page = $total_pages;

// Hitung offset
$offset = ($page - 1) * $items_per_page;

// Ambil data transaksi untuk penjual ini dengan PAGINATION
$sql = "SELECT 
            t.id_transaksi,
            t.invoice_number,
            t.id_user,
            t.total_harga,
            t.total_bayar,
            t.metode_pembayaran,
            t.bukti_pembayaran,
            t.status,
            t.tanggal_transaksi,
            t.approve,
            t.no_resi,
            t.kurir,
            td.id_detail,
            td.id_produk,
            td.nama_produk,
            td.qty,
            td.harga,
            td.subtotal,
            p.id_penjual as id_penjual,
            u_buyer.username as nama_pembeli,
            u_buyer.alamat as alamat_pembeli,
            u_buyer.email as email_pembeli
        FROM transaksi t
        INNER JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
        INNER JOIN produk p ON td.id_produk = p.id_produk
        INNER JOIN users u_buyer ON t.id_user = u_buyer.id_user
        WHERE p.id_penjual = ?
        GROUP BY t.id_transaksi
        ORDER BY t.tanggal_transaksi DESC
        LIMIT ? OFFSET ?";
    
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $user_id, $items_per_page, $offset);
$stmt->execute();
$transactions_result = $stmt->get_result();

// Hitung statistik (untuk semua data, tidak hanya halaman saat ini)
$stats_sql = "SELECT 
                SUM(CASE WHEN t.approve = 'tidak' AND t.status != 'ditolak' THEN 1 ELSE 0 END) as total_menunggu,
                SUM(CASE WHEN t.approve = 'approve' THEN 1 ELSE 0 END) as total_approved,
                SUM(CASE WHEN t.status = 'ditolak' THEN 1 ELSE 0 END) as total_rejected,
                SUM(CASE WHEN t.status = 'dikirim' THEN 1 ELSE 0 END) as total_dikirim,
                SUM(CASE WHEN t.status = 'selesai' THEN 1 ELSE 0 END) as total_selesai,
                SUM(CASE WHEN t.status = 'processing' THEN 1 ELSE 0 END) as total_processing
              FROM transaksi t
              INNER JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
              INNER JOIN produk p ON td.id_produk = p.id_produk
              WHERE p.id_penjual = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats_data = $stats_result->fetch_assoc();

$total_menunggu = $stats_data['total_menunggu'] ?? 0;
$total_approved = $stats_data['total_approved'] ?? 0;
$total_rejected = $stats_data['total_rejected'] ?? 0;
$total_dikirim = $stats_data['total_dikirim'] ?? 0;
$total_selesai = $stats_data['total_selesai'] ?? 0;
$total_processing = $stats_data['total_processing'] ?? 0;

// Buat array untuk menyimpan transaksi
$transactions = [];
while ($row = $transactions_result->fetch_assoc()) {
    $transactions[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Approve Pesanan</title>
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
            overflow-x: hidden;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }

        /* Notification Messages */
        .notification-message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
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
        
        .notification-message i {
            font-size: 1.2rem;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Content Header */
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
            padding: 25px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }
        
        .stat-box:hover {
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
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Tabel Approve */
        .content-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow-x: auto;
        }
        
        .content-section::-webkit-scrollbar {
            height: 8px;
        }
        
        .content-section::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .content-section::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        
        .content-section::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .table-controls {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .search-control {
            position: relative;
        }
        
        .search-control input {
            padding: 10px 15px 10px 40px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 14px;
            width: 250px;
        }
        
        .search-control i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .approve-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            min-width: 1300px;
        }
        
        .approve-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e9ecef;
        }
        
        .approve-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .approve-table tr:hover {
            background: #f8f9fa;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            text-align: center;
            min-width: 90px;
        }
        
        .status-menunggu {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-approve {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-tidak {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .status-dikirim {
            background: rgba(74, 144, 226, 0.1);
            color: var(--secondary);
            border: 1px solid rgba(74, 144, 226, 0.2);
        }
        
        .status-selesai {
            background: rgba(155, 89, 182, 0.1);
            color: var(--accent);
            border: 1px solid rgba(155, 89, 182, 0.2);
        }
        
        .status-ditolak {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .status-processing {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
        }
        
        /* Resi Number */
        .resi-number {
            font-family: monospace;
            font-weight: 600;
            color: var(--primary);
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
            font-size: 0.85rem;
        }
        
        .no-resi {
            color: var(--gray);
            font-style: italic;
            font-size: 0.85rem;
        }
        
        .tracking-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--secondary);
            text-decoration: none;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 5px;
            background: rgba(74, 144, 226, 0.1);
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }
        
        .tracking-link:hover {
            background: rgba(74, 144, 226, 0.2);
            text-decoration: none;
        }
        
        .bukti-link {
            color: var(--secondary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 5px;
            background: rgba(74, 144, 226, 0.1);
            transition: all 0.3s ease;
        }
        
        .bukti-link:hover {
            background: rgba(74, 144, 226, 0.2);
        }
        
        /* Action Buttons - SEMUA DALAM 1 BARIS */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .btn-approve {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .btn-approve:hover {
            background: rgba(40, 167, 69, 0.2);
        }
        
        .btn-reject {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .btn-reject:hover {
            background: rgba(220, 53, 69, 0.2);
        }
        
        .btn-resi {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        
        .btn-resi:hover {
            background: rgba(255, 193, 7, 0.2);
        }
        
        .btn-tracking {
            background: rgba(155, 89, 182, 0.1);
            color: var(--accent);
            border: 1px solid rgba(155, 89, 182, 0.2);
        }
        
        .btn-tracking:hover {
            background: rgba(155, 89, 182, 0.2);
        }
        
        .btn-status {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
        }
        
        .btn-status:hover {
            background: rgba(23, 162, 184, 0.2);
        }
        
        /* Modal untuk semua */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            animation: modalIn 0.3s ease;
            position: relative;
        }
        
        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--gray);
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .close-modal:hover {
            background: #f8f9fa;
        }
        
        /* Bukti Pembayaran Modal */
        .bukti-modal .modal-content {
            max-width: 800px;
            padding: 0;
        }
        
        .bukti-header {
            padding: 20px 30px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .bukti-body {
            padding: 30px;
            text-align: center;
        }
        
        .bukti-image {
            max-width: 100%;
            max-height: 500px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .bukti-info {
            margin-top: 20px;
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        /* Form styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
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
        
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23343a40' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        
        .btn-cancel {
            background: #f8f9fa;
            color: var(--dark);
            border: 1px solid #e1e5eb;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-cancel:hover {
            background: #e9ecef;
        }
        
        .btn-save {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-save:hover {
            background: #2c7be5;
        }

        /* Pagination Styles */
        .pagination-container {
            margin-top: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .pagination-nav {
            display: flex;
            justify-content: center;
        }

        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0;
            gap: 8px;
        }

        .pagination-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f8f9fa;
            color: var(--dark);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .pagination-link:hover:not(.disabled):not(.active) {
            background: #e9ecef;
            color: var(--secondary);
        }

        .pagination-link.active {
            background: var(--secondary);
            color: white;
            font-weight: 600;
        }

        .pagination-link.disabled {
            background: #f8f9fa;
            color: var(--gray);
            cursor: not-allowed;
        }

        /* Items per page selector */
        .items-per-page select {
            padding: 8px 15px;
            border: 1px solid #e1e5eb;
            border-radius: 6px;
            background: white;
            color: var(--dark);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .items-per-page select:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        .items-per-page label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        /* Pagination info */
        .pagination-info {
            margin-bottom: 15px;
            color: var(--gray);
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        /* Responsive Pagination */
        @media (max-width: 768px) {
            .pagination-link {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
            
            .pagination {
                gap: 5px;
            }
            
            .pagination-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }

        @media (max-width: 576px) {
            .pagination-link {
                width: 30px;
                height: 30px;
                font-size: 0.85rem;
            }
            
            .pagination-nav {
                flex-wrap: wrap;
                justify-content: center;
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
        }
        
        @media (max-width: 768px) {
            .top-nav {
                padding: 0 15px;
            }
            
            .user-details {
                display: none;
            }
            
            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-control input {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 3px;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 20px 15px;
            }
            
            .logo-text {
                font-size: 1.5rem;
            }
            
            .modal-content {
                padding: 20px;
            }
        }
        /* HAPUS SCROLLBAR DARI SELURUH HALAMAN */
/* Untuk semua browser */
html {
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE and Edge */
}

/* Untuk WebKit browsers (Chrome, Safari, Opera) */
::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
    background: transparent;
}

body {
    overflow: hidden;
}

/* Main content scrollable tanpa scrollbar */
.main-content {
    overflow-y: auto;
    height: calc(100vh - 70px);
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.main-content::-webkit-scrollbar {
    display: none;
}

/* Sidebar scrollable tanpa scrollbar */
.sidebar {
    height: calc(100vh - 70px);
    overflow-y: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.sidebar::-webkit-scrollbar {
    display: none;
}

/* Dropdown scrollable tanpa scrollbar jika ada */
.dropdown {
    max-height: 300px;
    overflow-y: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.dropdown::-webkit-scrollbar {
    display: none;
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
                <a href="produk.php" class="sidebar-item">
                    <i class="fas fa-box"></i>
                    <span>Produk</span>
                </a>
                <a href="approve.php" class="sidebar-item active">
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
            <h1 class="page-title">Approve Pesanan</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Approve</span>
            </div>
        </div>

        <!-- Notification Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="notification-message success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="notification-message error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning) 0%, #e0a800 100%);">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $total_menunggu; ?></div>
                <div class="stat-label">Menunggu Approve</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $total_approved; ?></div>
                <div class="stat-label">Sudah Disetujui</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-value"><?php echo $total_processing; ?></div>
                <div class="stat-label">Processing</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-value"><?php echo $total_dikirim; ?></div>
                <div class="stat-label">Dalam Pengiriman</div>
            </div>
        </div>

        <!-- Tabel Approve -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Daftar Pesanan</h2>
                <div class="table-controls">
                    <div class="search-control">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Cari kode/nama..." id="searchOrder">
                    </div>
                </div>
            </div>
            
            <!-- Informasi Pagination -->
            <div class="pagination-info">
                <div>
                    Menampilkan <?php echo ($offset + 1); ?> - <?php echo min($offset + $items_per_page, $total_transactions); ?> dari <?php echo $total_transactions; ?> transaksi
                </div>
            </div>
            
            <?php if (empty($transactions)): ?>
                <div style="text-align: center; padding: 40px; color: var(--gray);">
                    <i class="fas fa-clipboard-list" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.5;"></i>
                    <h3>Tidak ada transaksi yang perlu disetujui</h3>
                    <p>Belum ada pesanan untuk produk Anda.</p>
                </div>
            <?php else: ?>
                <table class="approve-table">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th>Kode Pesanan</th>
                            <th>Produk</th>
                            <th>QTY</th>
                            <th>Total</th>
                            <th>Bukti</th>
                            <th>Approve</th>
                            <th>Status</th>
                            <th>No Resi</th>
                            <th>Pembeli</th>
                            <th>Tanggal</th>
                            <th width="180">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = $offset + 1; // Nomor urut berdasarkan pagination
                        foreach ($transactions as $transaction): ?>
                            <?php 
                                // Tentukan teks dan kelas untuk kolom Approve
                                if ($transaction['status'] == 'ditolak') {
                                    $approve_text = 'Ditolak';
                                    $approve_class = 'status-tidak';
                                } elseif ($transaction['approve'] == 'approve') {
                                    $approve_text = 'Disetujui';
                                    $approve_class = 'status-approve';
                                } elseif ($transaction['approve'] == 'tidak') {
                                    $approve_text = 'Menunggu';
                                    $approve_class = 'status-menunggu';
                                } else {
                                    $approve_text = 'Menunggu';
                                    $approve_class = 'status-menunggu';
                                }
                                
                                // Tentukan kelas status berdasarkan status transaksi
                                $status_class = 'status-' . $transaction['status'];
                                $status_text = ucfirst($transaction['status']);
                                
                                // Format tanggal
                                $tanggal = date('d M Y H:i', strtotime($transaction['tanggal_transaksi']));
                                
                                // Format harga
                                $total_harga = number_format($transaction['total_harga'], 0, ',', '.');
                                
                                // Cek apakah ada bukti pembayaran
                                $has_payment_proof = !empty($transaction['bukti_pembayaran']);
                                $payment_proof_path = $has_payment_proof ? '../asset/payment_proofs/' . $transaction['bukti_pembayaran'] : '';
                                
                                // Cek apakah ada resi
                                $has_resi = !empty($transaction['no_resi']);
                                
                                // Dapatkan link tracking jika ada resi
                                $tracking_link = null;
                                if ($has_resi && !empty($transaction['kurir'])) {
                                    $tracking_link = getTrackingLink($transaction['kurir'], $transaction['no_resi']);
                                }
                            ?>
                            <tr data-status="<?php echo $transaction['approve']; ?>" data-resi="<?php echo $has_resi ? 'yes' : 'no'; ?>">
                                <td align="center"><strong><?php echo $no; ?></strong></td>
                                <td><strong><?php echo $transaction['invoice_number']; ?></strong></td>
                                <td>
                                    <div style="font-weight: 500; max-width: 150px; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo htmlspecialchars($transaction['nama_produk']); ?>
                                    </div>
                                    <small style="color: var(--gray);">Rp <?php echo number_format($transaction['harga'], 0, ',', '.'); ?></small>
                                </td>
                                <td align="center"><?php echo $transaction['qty']; ?></td>
                                <td>Rp <?php echo $total_harga; ?></td>
                                <td>
                                    <?php if ($has_payment_proof): ?>
                                        <a href="#" class="bukti-link" onclick="showBuktiModal('<?php echo $payment_proof_path; ?>', '<?php echo $transaction['invoice_number']; ?>')">
                                            <i class="fas fa-eye"></i> Lihat
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--gray); font-style: italic; font-size: 0.85rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $approve_class; ?>">
                                        <?php echo $approve_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($has_resi): ?>
                                        <div style="max-width: 120px;">
                                            <span class="resi-number" title="<?php echo $transaction['no_resi']; ?>">
                                                <?php echo substr($transaction['no_resi'], 0, 10) . (strlen($transaction['no_resi']) > 10 ? '...' : ''); ?>
                                            </span>
                                            <?php if (!empty($transaction['kurir'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--gray); margin-top: 2px;">
                                                    <?php echo ucfirst($transaction['kurir']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-resi">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="max-width: 120px;">
                                        <strong style="font-size: 0.9rem;"><?php echo htmlspecialchars($transaction['nama_pembeli']); ?></strong><br>
                                        <small style="color: var(--gray); font-size: 0.75rem;"><?php echo htmlspecialchars($transaction['email_pembeli']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <small><?php echo $tanggal; ?></small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($transaction['approve'] == 'tidak' && $transaction['status'] != 'ditolak'): ?>
                                            <!-- Tombol untuk approve/reject -->
                                            <button class="btn-action btn-approve" title="Approve Pesanan"
                                                    onclick="approveTransaction(<?php echo $transaction['id_transaksi']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn-action btn-reject" title="Tolak Pesanan"
                                                    onclick="rejectTransaction(<?php echo $transaction['id_transaksi']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        
                                        <?php elseif ($transaction['approve'] == 'approve'): ?>
                                            
                                            <?php if (!$has_resi && $transaction['status'] == 'processing'): ?>
                                                <!-- Tombol untuk input resi -->
                                                <button class="btn-action btn-resi" title="Input Resi"
                                                        onclick="openResiModal(<?php echo $transaction['id_transaksi']; ?>, '<?php echo $transaction['invoice_number']; ?>')">
                                                    <i class="fas fa-truck"></i>
                                                </button>
                                            
                                            <?php elseif ($has_resi && $transaction['status'] == 'dikirim'): ?>
                                                <!-- Tombol untuk lacak paket -->
                                                <?php if ($tracking_link): ?>
                                                    <a href="<?php echo $tracking_link; ?>" 
                                                       class="btn-action btn-tracking" 
                                                       target="_blank"
                                                       title="Lacak Paket">
                                                        <i class="fas fa-shipping-fast"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn-action" 
                                                            onclick="alert('Link tracking tidak tersedia untuk kurir ini.')"
                                                            style="background: #f8f9fa; color: var(--gray);"
                                                            title="Tracking N/A">
                                                        <i class="fas fa-info-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                            
                                            <?php elseif ($has_resi && $transaction['status'] == 'selesai'): ?>
                                                <!-- Status selesai -->
                                                <button class="btn-action" style="background: rgba(155, 89, 182, 0.1); color: var(--accent);" disabled title="Selesai">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            
                                            <?php else: ?>
                                                <!-- Tombol untuk update status -->
                                                <button class="btn-action btn-status" title="Update Status"
                                                        onclick="openStatusModal(<?php echo $transaction['id_transaksi']; ?>, '<?php echo $transaction['status']; ?>')">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        
                                        <?php elseif ($transaction['status'] == 'ditolak'): ?>
                                            <!-- Status ditolak - tidak ada tombol aksi -->
                                            <button class="btn-action" style="background: rgba(220, 53, 69, 0.1); color: var(--danger);" disabled title="Ditolak">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php $no++; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav class="pagination-nav">
                        <ul class="pagination">
                            <!-- Previous Button -->
                            <?php if ($page > 1): ?>
                            <li>
                                <a href="?page=<?php echo $page - 1; ?><?php echo isset($_GET['items_per_page']) ? '&items_per_page=' . $items_per_page : ''; ?>" class="pagination-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php else: ?>
                            <li>
                                <span class="pagination-link disabled">
                                    <i class="fas fa-chevron-left"></i>
                                </span>
                            </li>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <?php 
                            // Hitung range halaman yang akan ditampilkan
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            // Tampilkan halaman pertama jika tidak di range
                            if ($start_page > 1): ?>
                            <li>
                                <a href="?page=1<?php echo isset($_GET['items_per_page']) ? '&items_per_page=' . $items_per_page : ''; ?>" class="pagination-link">
                                    1
                                </a>
                            </li>
                            <?php if ($start_page > 2): ?>
                            <li><span style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; color: var(--gray);">...</span></li>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- Tampilkan halaman dalam range -->
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li>
                                <?php if ($i == $page): ?>
                                    <span class="pagination-link active">
                                        <?php echo $i; ?>
                                    </span>
                                <?php else: ?>
                                    <a href="?page=<?php echo $i; ?><?php echo isset($_GET['items_per_page']) ? '&items_per_page=' . $items_per_page : ''; ?>" class="pagination-link">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                            <?php endfor; ?>
                            
                            <!-- Tampilkan halaman terakhir jika tidak di range -->
                            <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                            <li><span style="display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; color: var(--gray);">...</span></li>
                            <?php endif; ?>
                            <li>
                                <a href="?page=<?php echo $total_pages; ?><?php echo isset($_GET['items_per_page']) ? '&items_per_page=' . $items_per_page : ''; ?>" class="pagination-link">
                                    <?php echo $total_pages; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <!-- Next Button -->
                            <?php if ($page < $total_pages): ?>
                            <li>
                                <a href="?page=<?php echo $page + 1; ?><?php echo isset($_GET['items_per_page']) ? '&items_per_page=' . $items_per_page : ''; ?>" class="pagination-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <?php else: ?>
                            <li>
                                <span class="pagination-link disabled">
                                    <i class="fas fa-chevron-right"></i>
                                </span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <!-- END PAGINATION -->
                
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal untuk lihat bukti pembayaran -->
    <div class="modal bukti-modal" id="buktiModal">
        <div class="modal-content">
            <div class="bukti-header">
                <h3 class="modal-title" id="buktiTitle">Bukti Pembayaran</h3>
                <button class="close-modal" onclick="closeBuktiModal()">&times;</button>
            </div>
            <div class="bukti-body">
                <img id="buktiImage" src="" alt="Bukti Pembayaran" class="bukti-image">
                <div class="bukti-info" id="buktiInfo">
                    Loading bukti pembayaran...
                </div>
            </div>
        </div>
    </div>

    <!-- Modal untuk input resi -->
    <div class="modal" id="resiModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Input Nomori Resi</h3>
                <button class="close-modal" onclick="closeResiModal()">&times;</button>
            </div>
            <form id="resiForm" method="POST">
                <input type="hidden" name="input_resi" value="1">
                <input type="hidden" name="transaction_id" id="modalTransactionId">
                <div style="margin-bottom: 20px; color: var(--primary); font-weight: 600; font-size: 0.9rem;">
                    Invoice: <span id="modalInvoiceNumber"></span>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Kurir / Ekspedisi</label>
                    <select class="form-control form-select" name="kurir" id="kurirSelect" required>
                        <option value="">Pilih Kurir</option>
                        <option value="jne">JNE</option>
                        <option value="tiki">TIKI</option>
                        <option value="pos">POS Indonesia</option>
                        <option value="j&t">J&T Express</option>
                        <option value="sicepat">SiCepat</option>
                        <option value="anteraja">AnterAja</option>
                        <option value="ninja">Ninja Express</option>
                        <option value="lion">Lion Parcel</option>
                        <option value="wahana">Wahana</option>
                        <option value="rex">REX</option>
                        <option value="indah">Indah Logistik</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>
                
                <div class="form-group" id="customKurirField" style="display: none;">
                    <label class="form-label">Nama Kurir</label>
                    <input type="text" class="form-control" name="custom_kurir" id="customKurirInput" placeholder="Masukkan nama kurir">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nomor Resi</label>
                    <input type="text" class="form-control" name="resi" id="resiInput" placeholder="Masukkan nomor resi" required>
                    <small style="color: var(--gray); font-size: 0.85rem;">Nomor resi akan dikirim ke pembeli untuk melacak paket</small>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeResiModal()">Batal</button>
                    <button type="submit" class="btn-save">Simpan Resi</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal untuk update status -->
    <div class="modal" id="statusModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Status</h3>
                <button class="close-modal" onclick="closeStatusModal()">&times;</button>
            </div>
            <form id="statusForm" method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="transaction_id" id="statusTransactionId">
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status" id="statusSelect" required>
                        <option value="processing">Processing</option>
                        <option value="dikirim">Dikirim</option>
                        <option value="selesai">Selesai</option>
                        <option value="ditolak">Ditolak</option>
                    </select>
                </div>
                
                <div class="form-group" id="resiStatusField" style="display: none;">
                    <label class="form-label">Nomor Resi (opsional)</label>
                    <input type="text" class="form-control" name="resi" id="resiStatusInput" placeholder="Masukkan nomor resi">
                    <small style="color: var(--gray); font-size: 0.85rem;">Isi jika sudah mendapatkan nomor resi</small>
                </div>
                
                <div class="form-group" id="kurirStatusField" style="display: none;">
                    <label class="form-label">Kurir</label>
                    <input type="text" class="form-control" name="kurir" id="kurirStatusInput" placeholder="Masukkan nama kurir">
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeStatusModal()">Batal</button>
                    <button type="submit" class="btn-save">Update Status</button>
                </div>
            </form>
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
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!userProfile.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });

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

        // Search functionality
        const searchOrderInput = document.getElementById('searchOrder');
        if (searchOrderInput) {
            searchOrderInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.approve-table tbody tr');
                
                rows.forEach(row => {
                    const orderCode = row.querySelector('td:nth-child(2) strong').textContent.toLowerCase();
                    const bookTitle = row.querySelector('td:nth-child(3) div:first-child').textContent.toLowerCase();
                    const customerName = row.querySelector('td:nth-child(10) strong').textContent.toLowerCase();
                    const resiElement = row.querySelector('.resi-number');
                    const resi = resiElement ? resiElement.textContent.toLowerCase() : '';
                    
                    if (orderCode.includes(searchTerm) || bookTitle.includes(searchTerm) || 
                        customerName.includes(searchTerm) || resi.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        // Function to approve transaction
        function approveTransaction(transactionId) {
            if (confirm('Apakah Anda yakin ingin menyetujui transaksi ini?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve';
                
                const transactionInput = document.createElement('input');
                transactionInput.type = 'hidden';
                transactionInput.name = 'transaction_id';
                transactionInput.value = transactionId;
                
                form.appendChild(actionInput);
                form.appendChild(transactionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Function to reject transaction
        function rejectTransaction(transactionId) {
            if (confirm('Apakah Anda yakin ingin menolak transaksi ini?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'reject';
                
                const transactionInput = document.createElement('input');
                transactionInput.type = 'hidden';
                transactionInput.name = 'transaction_id';
                transactionInput.value = transactionId;
                
                form.appendChild(actionInput);
                form.appendChild(transactionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Function to show bukti pembayaran modal
        function showBuktiModal(imagePath, invoiceNumber) {
            document.getElementById('buktiTitle').textContent = 'Bukti Pembayaran - ' + invoiceNumber;
            document.getElementById('buktiImage').src = imagePath;
            document.getElementById('buktiInfo').innerHTML = `
                <div>Invoice: <strong>${invoiceNumber}</strong></div>
                <div>Klik di luar gambar untuk menutup</div>
            `;
            document.getElementById('buktiModal').style.display = 'flex';
            
            // Handle image error
            document.getElementById('buktiImage').onerror = function() {
                this.src = '../asset/wesley.png';
                document.getElementById('buktiInfo').innerHTML = `
                    <div style="color: var(--danger);">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Gagal memuat gambar. File mungkin tidak tersedia.
                    </div>
                    <div>Invoice: <strong>${invoiceNumber}</strong></div>
                `;
            };
        }

        // Function to close bukti modal
        function closeBuktiModal() {
            document.getElementById('buktiModal').style.display = 'none';
            document.getElementById('buktiImage').src = '';
        }

        // Function to open resi modal
        function openResiModal(transactionId, invoiceNumber) {
            document.getElementById('modalTransactionId').value = transactionId;
            document.getElementById('modalInvoiceNumber').textContent = invoiceNumber;
            document.getElementById('resiModal').style.display = 'flex';
            
            // Reset form
            document.getElementById('kurirSelect').value = '';
            document.getElementById('resiInput').value = '';
            document.getElementById('customKurirField').style.display = 'none';
            document.getElementById('customKurirInput').value = '';
        }

        // Function to close resi modal
        function closeResiModal() {
            document.getElementById('resiModal').style.display = 'none';
        }

        // Function to open status modal
        function openStatusModal(transactionId, currentStatus) {
            document.getElementById('statusTransactionId').value = transactionId;
            document.getElementById('statusSelect').value = currentStatus;
            document.getElementById('statusModal').style.display = 'flex';
            updateStatusFields(currentStatus);
        }

        // Function to close status modal
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        // Show/hide kurir custom field
        const kurirSelect = document.getElementById('kurirSelect');
        const customKurirField = document.getElementById('customKurirField');
        
        if (kurirSelect) {
            kurirSelect.addEventListener('change', function() {
                if (this.value === 'lainnya') {
                    customKurirField.style.display = 'block';
                    document.getElementById('customKurirInput').required = true;
                } else {
                    customKurirField.style.display = 'none';
                    document.getElementById('customKurirInput').required = false;
                    document.getElementById('customKurirInput').value = '';
                }
            });
        }

        // Update resi form before submit
        const resiForm = document.getElementById('resiForm');
        if (resiForm) {
            resiForm.addEventListener('submit', function(e) {
                const kurirSelect = document.getElementById('kurirSelect');
                const customKurir = document.getElementById('customKurirInput').value;
                
                if (kurirSelect.value === 'lainnya' && customKurir) {
                    // Tambahkan hidden input untuk custom kurir
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'kurir';
                    hiddenInput.value = customKurir;
                    this.appendChild(hiddenInput);
                    
                    // Nonaktifkan select
                    kurirSelect.disabled = true;
                }
            });
        }

        // Update status fields based on selection
        const statusSelect = document.getElementById('statusSelect');
        const resiStatusField = document.getElementById('resiStatusField');
        const kurirStatusField = document.getElementById('kurirStatusField');
        
        function updateStatusFields(status) {
            if (status === 'dikirim') {
                resiStatusField.style.display = 'block';
                kurirStatusField.style.display = 'block';
            } else {
                resiStatusField.style.display = 'none';
                kurirStatusField.style.display = 'none';
                document.getElementById('resiStatusInput').value = '';
                document.getElementById('kurirStatusInput').value = '';
            }
        }
        
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                updateStatusFields(this.value);
            });
        }

        // Close modal when clicking outside
        const modals = ['buktiModal', 'resiModal', 'statusModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        if (modalId === 'buktiModal') {
                            closeBuktiModal();
                        } else if (modalId === 'resiModal') {
                            closeResiModal();
                        } else if (modalId === 'statusModal') {
                            closeStatusModal();
                        }
                    }
                });
            }
        });

        // Close modal with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeBuktiModal();
                closeResiModal();
                closeStatusModal();
            }
        });

        // Items per page change handler
        const itemsPerPageSelect = document.getElementById('itemsPerPage');
        if (itemsPerPageSelect) {
            itemsPerPageSelect.addEventListener('change', function() {
                const itemsPerPage = this.value;
                const currentUrl = new URL(window.location.href);
                
                // Update parameter items_per_page
                currentUrl.searchParams.set('items_per_page', itemsPerPage);
                currentUrl.searchParams.set('page', 1); // Reset ke halaman 1
                
                window.location.href = currentUrl.toString();
            });
        }

        // Update URL parameter untuk items_per_page saat load
        document.addEventListener('DOMContentLoaded', () => {
            // Jika ada parameter items_per_page di URL, update select
            const urlParams = new URLSearchParams(window.location.search);
            const itemsPerPage = urlParams.get('items_per_page');
            
            if (itemsPerPage && itemsPerPageSelect) {
                itemsPerPageSelect.value = itemsPerPage;
            }
            
            // Highlight pending transactions
            const rows = document.querySelectorAll('.approve-table tbody tr');
            rows.forEach(row => {
                // Highlight yang masih menunggu approve (approve = 'tidak' dan status bukan 'ditolak')
                const statusCell = row.querySelector('td:nth-child(7) .status-badge');
                if (statusCell && statusCell.textContent === 'Menunggu') {
                    row.style.backgroundColor = 'rgba(255, 193, 7, 0.05)';
                }
                
                // Highlight yang sudah ditolak
                if (statusCell && statusCell.textContent === 'Ditolak') {
                    row.style.backgroundColor = 'rgba(220, 53, 69, 0.05)';
                }
                
                // Highlight jika sudah ada resi
                const resiCell = row.querySelector('td:nth-child(9)');
                if (resiCell && !resiCell.querySelector('.no-resi')) {
                    resiCell.style.backgroundColor = 'rgba(74, 144, 226, 0.05)';
                }
            });
        });

        // JavaScript untuk Notifikasi Realtime
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
            
            updateBadges(data) {
                // Update notification badges
                const unreadCount = data.notifications?.unread || 0;
                
                // Update navbar badge
                const navBadge = document.getElementById('notificationBadge');
                if (navBadge) {
                    navBadge.textContent = unreadCount;
                    if (unreadCount > 0) {
                        navBadge.style.display = 'flex';
                    } else {
                        navBadge.style.display = 'none';
                    }
                }
                
                // Update order badges
                const newOrders = data.orders?.new || 0;
                
                // Update sidebar order badge
                const sidebarOrderBadge = document.getElementById('sidebarOrderBadge');
                if (sidebarOrderBadge) {
                    sidebarOrderBadge.textContent = newOrders;
                    if (newOrders > 0) {
                        sidebarOrderBadge.style.display = 'flex';
                    } else {
                        sidebarOrderBadge.style.display = 'none';
                    }
                }
                
                // Update chat badges
                const unreadChat = data.chat?.unread || 0;
                
                // Update sidebar chat badge
                const sidebarChatBadge = document.getElementById('sidebarChatBadge');
                if (sidebarChatBadge) {
                    sidebarChatBadge.textContent = unreadChat;
                    if (unreadChat > 0) {
                        sidebarChatBadge.style.display = 'flex';
                    } else {
                        sidebarChatBadge.style.display = 'none';
                    }
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
            
            refresh() {
                this.loadInitialCounts();
            }
            
            destroy() {
                this.stopPolling();
            }
        }
        
        // Initialize system
        document.addEventListener('DOMContentLoaded', () => {
            window.penjualNotificationSystem = new PenjualNotificationSystem();
        });
    </script>
</body>
</html>