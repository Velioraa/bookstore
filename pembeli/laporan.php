<?php
// Koneksi ke database
require_once '../koneksi.php';

// Include library TCPDF
require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';

// Mulai session
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    // Jika belum login, redirect ke halaman login
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

// Inisialisasi filter
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
    
// Daftar bulan
$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// Daftar tahun (5 tahun terakhir)
$current_year = date('Y');
$years = [];
for ($i = $current_year; $i >= $current_year - 4; $i--) {
    $years[] = $i;
}

// QUERY UNTUK STATISTIK LAPORAN BERDASARKAN PENJUAL
$stats = [
    'total_penjualan' => 0,
    'total_pendapatan' => 0,
    'total_produk_terjual' => 0
];

$weekly_data = [
    'minggu_1' => 0,
    'minggu_2' => 0,
    'minggu_3' => 0,
    'minggu_4' => 0
];

// Query untuk statistik berdasarkan bulan dan tahun untuk penjual yang login
try {
    // Pertama, kita perlu mengetahui ID penjual dari tabel produk yang terkait dengan transaksi
    // Asumsi: tabel produk memiliki kolom id_penjual yang merujuk ke id_user di tabel users
    
    // Untuk penjual, kita hanya ambil transaksi yang berisi produk mereka
    if ($role == 'penjual') {
        // Query untuk total penjualan (jumlah transaksi yang approved dan berisi produk penjual)
        $sql_total_penjualan = "SELECT COUNT(DISTINCT t.id_transaksi) as total 
                               FROM transaksi t
                               JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                               JOIN produk p ON td.id_produk = p.id_produk
                               WHERE MONTH(t.tanggal_transaksi) = ? 
                               AND YEAR(t.tanggal_transaksi) = ? 
                               AND t.approve = 'approve'
                               AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_total_penjualan);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_penjualan'] = $row['total'] ?? 0;
        
        // Query untuk total pendapatan penjual (hanya subtotal dari produk mereka)
        $sql_total_pendapatan = "SELECT SUM(td.subtotal) as total 
                                FROM transaksi t
                                JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                                JOIN produk p ON td.id_produk = p.id_produk
                                WHERE MONTH(t.tanggal_transaksi) = ? 
                                AND YEAR(t.tanggal_transaksi) = ? 
                                AND t.approve = 'approve'
                                AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_total_pendapatan);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_pendapatan'] = $row['total'] ?? 0;
        
        // Query untuk total produk terjual penjual
        $sql_total_produk = "SELECT SUM(td.qty) as total 
                            FROM transaksi t
                            JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                            JOIN produk p ON td.id_produk = p.id_produk
                            WHERE MONTH(t.tanggal_transaksi) = ? 
                            AND YEAR(t.tanggal_transaksi) = ? 
                            AND t.approve = 'approve'
                            AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_total_produk);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_produk_terjual'] = $row['total'] ?? 0;
        
        // Query untuk data per minggu (untuk chart)
        // Minggu 1: tanggal 1-7
        $sql_week1 = "SELECT SUM(td.subtotal) as total 
                     FROM transaksi t
                     JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                     JOIN produk p ON td.id_produk = p.id_produk
                     WHERE MONTH(t.tanggal_transaksi) = ? 
                     AND YEAR(t.tanggal_transaksi) = ? 
                     AND DAY(t.tanggal_transaksi) BETWEEN 1 AND 7
                     AND t.approve = 'approve'
                     AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_week1);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_1'] = $row['total'] ?? 0;
        
        // Minggu 2: tanggal 8-14
        $sql_week2 = "SELECT SUM(td.subtotal) as total 
                     FROM transaksi t
                     JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                     JOIN produk p ON td.id_produk = p.id_produk
                     WHERE MONTH(t.tanggal_transaksi) = ? 
                     AND YEAR(t.tanggal_transaksi) = ? 
                     AND DAY(t.tanggal_transaksi) BETWEEN 8 AND 14
                     AND t.approve = 'approve'
                     AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_week2);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_2'] = $row['total'] ?? 0;
        
        // Minggu 3: tanggal 15-21
        $sql_week3 = "SELECT SUM(td.subtotal) as total 
                     FROM transaksi t
                     JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                     JOIN produk p ON td.id_produk = p.id_produk
                     WHERE MONTH(t.tanggal_transaksi) = ? 
                     AND YEAR(t.tanggal_transaksi) = ? 
                     AND DAY(t.tanggal_transaksi) BETWEEN 15 AND 21
                     AND t.approve = 'approve'
                     AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_week3);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_3'] = $row['total'] ?? 0;
        
        // Minggu 4: tanggal 22-last day of month
        $sql_week4 = "SELECT SUM(td.subtotal) as total 
                     FROM transaksi t
                     JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                     JOIN produk p ON td.id_produk = p.id_produk
                     WHERE MONTH(t.tanggal_transaksi) = ? 
                     AND YEAR(t.tanggal_transaksi) = ? 
                     AND DAY(t.tanggal_transaksi) >= 22
                     AND t.approve = 'approve'
                     AND p.id_penjual = ?";
        $stmt = $conn->prepare($sql_week4);
        $stmt->bind_param("ssi", $selected_month, $selected_year, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_4'] = $row['total'] ?? 0;
        
    } else {
        // Untuk role super_admin, tampilkan semua data (seperti sebelumnya)
        // Query untuk total penjualan (jumlah transaksi yang approved)
        $sql_total_penjualan = "SELECT COUNT(*) as total 
                               FROM transaksi 
                               WHERE MONTH(tanggal_transaksi) = ? 
                               AND YEAR(tanggal_transaksi) = ? 
                               AND approve = 'approve'";
        $stmt = $conn->prepare($sql_total_penjualan);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_penjualan'] = $row['total'] ?? 0;
        
        // Query untuk total pendapatan (total harga dari transaksi approved)
        $sql_total_pendapatan = "SELECT SUM(total_harga) as total 
                                FROM transaksi 
                                WHERE MONTH(tanggal_transaksi) = ? 
                                AND YEAR(tanggal_transaksi) = ? 
                                AND approve = 'approve'";
        $stmt = $conn->prepare($sql_total_pendapatan);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_pendapatan'] = $row['total'] ?? 0;
        
        // Query untuk total produk terjual
        $sql_total_produk = "SELECT SUM(td.qty) as total 
                            FROM transaksi_detail td
                            JOIN transaksi t ON td.id_transaksi = t.id_transaksi
                            WHERE MONTH(t.tanggal_transaksi) = ? 
                            AND YEAR(t.tanggal_transaksi) = ? 
                            AND t.approve = 'approve'";
        $stmt = $conn->prepare($sql_total_produk);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_produk_terjual'] = $row['total'] ?? 0;
        
        // Query untuk data per minggu (untuk chart)
        // Minggu 1: tanggal 1-7
        $sql_week1 = "SELECT SUM(total_harga) as total 
                     FROM transaksi 
                     WHERE MONTH(tanggal_transaksi) = ? 
                     AND YEAR(tanggal_transaksi) = ? 
                     AND DAY(tanggal_transaksi) BETWEEN 1 AND 7
                     AND approve = 'approve'";
        $stmt = $conn->prepare($sql_week1);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_1'] = $row['total'] ?? 0;
        
        // Minggu 2: tanggal 8-14
        $sql_week2 = "SELECT SUM(total_harga) as total 
                     FROM transaksi 
                     WHERE MONTH(tanggal_transaksi) = ? 
                     AND YEAR(tanggal_transaksi) = ? 
                     AND DAY(tanggal_transaksi) BETWEEN 8 AND 14
                     AND approve = 'approve'";
        $stmt = $conn->prepare($sql_week2);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_2'] = $row['total'] ?? 0;
        
        // Minggu 3: tanggal 15-21
        $sql_week3 = "SELECT SUM(total_harga) as total 
                     FROM transaksi 
                     WHERE MONTH(tanggal_transaksi) = ? 
                     AND YEAR(tanggal_transaksi) = ? 
                     AND DAY(tanggal_transaksi) BETWEEN 15 AND 21
                     AND approve = 'approve'";
        $stmt = $conn->prepare($sql_week3);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_3'] = $row['total'] ?? 0;
        
        // Minggu 4: tanggal 22-last day of month
        $sql_week4 = "SELECT SUM(total_harga) as total 
                     FROM transaksi 
                     WHERE MONTH(tanggal_transaksi) = ? 
                     AND YEAR(tanggal_transaksi) = ? 
                     AND DAY(tanggal_transaksi) >= 22
                     AND approve = 'approve'";
        $stmt = $conn->prepare($sql_week4);
        $stmt->bind_param("ss", $selected_month, $selected_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $weekly_data['minggu_4'] = $row['total'] ?? 0;
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Format angka untuk ditampilkan
function formatRupiah($angka) {
    if ($angka >= 1000000000) {
        return 'Rp ' . number_format($angka / 1000000000, 1, ',', '.') . ' M';
    } elseif ($angka >= 1000000) {
        return 'Rp ' . number_format($angka / 1000000, 1, ',', '.') . ' JT';
    } elseif ($angka >= 1000) {
        return 'Rp ' . number_format($angka / 1000, 1, ',', '.') . ' K';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Fungsi untuk membuat laporan PDF
function generatePDFReport($user_id, $username, $role, $selected_month, $selected_year, $months, $stats, $weekly_data, $conn) {
    // Buat instance TCPDF baru
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set informasi dokumen
    $pdf->SetCreator('Wesley Bookstore');
    $pdf->SetAuthor('Wesley Bookstore System');
    $pdf->SetTitle('Laporan Penjualan ' . $months[$selected_month] . ' ' . $selected_year);
    $pdf->SetSubject('Laporan Penjualan');
    
    // Set margin
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', 'B', 16);
    
    // Judul laporan
    $pdf->Cell(0, 10, 'LAPORAN PENJUALAN', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 10, 'Wesley Bookstore', 0, 1, 'C');
    $pdf->Cell(0, 10, 'Periode: ' . $months[$selected_month] . ' ' . $selected_year, 0, 1, 'C');
    
    // Informasi pengguna
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Ln(5);
    $pdf->Cell(0, 10, 'Informasi Pengguna', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Nama: ' . $username, 0, 1);
    $pdf->Cell(0, 7, 'Role: ' . $role, 0, 1);
    $pdf->Cell(0, 7, 'Tanggal Cetak: ' . date('d-m-Y H:i:s'), 0, 1);
    
    // Statistik penjualan
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Ln(5);
    $pdf->Cell(0, 10, 'Statistik Penjualan', 0, 1);
    
    // Table header
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(60, 8, 'Jenis Statistik', 1, 0, 'C', 1);
    $pdf->Cell(40, 8, 'Jumlah', 1, 0, 'C', 1);
    $pdf->Cell(70, 8, 'Nilai (Rp)', 1, 1, 'C', 1);
    
    // Table data
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(245, 245, 245);
    
    // Row 1: Total Penjualan
    $pdf->Cell(60, 8, 'Total Penjualan', 1, 0, 'L', 1);
    $pdf->Cell(40, 8, $stats['total_penjualan'] . ' transaksi', 1, 0, 'C', 1);
    $pdf->Cell(70, 8, '-', 1, 1, 'C', 1);
    
    // Row 2: Total Pendapatan
    $pdf->Cell(60, 8, 'Total Pendapatan', 1, 0, 'L', 0);
    $pdf->Cell(40, 8, '-', 1, 0, 'C', 0);
    $pdf->Cell(70, 8, number_format($stats['total_pendapatan'], 0, ',', '.'), 1, 1, 'C', 0);
    
    // Row 3: Total Produk Terjual
    $pdf->Cell(60, 8, 'Produk Terjual', 1, 0, 'L', 1);
    $pdf->Cell(40, 8, $stats['total_produk_terjual'] . ' unit', 1, 0, 'C', 1);
    $pdf->Cell(70, 8, '-', 1, 1, 'C', 1);
    
    // Grafik penjualan per minggu
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Ln(5);
    $pdf->Cell(0, 10, 'Penjualan per Minggu', 0, 1);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Minggu 1 (1-7): Rp ' . number_format($weekly_data['minggu_1'], 0, ',', '.'), 0, 1);
    $pdf->Cell(0, 7, 'Minggu 2 (8-14): Rp ' . number_format($weekly_data['minggu_2'], 0, ',', '.'), 0, 1);
    $pdf->Cell(0, 7, 'Minggu 3 (15-21): Rp ' . number_format($weekly_data['minggu_3'], 0, ',', '.'), 0, 1);
    $pdf->Cell(0, 7, 'Minggu 4 (22-akhir): Rp ' . number_format($weekly_data['minggu_4'], 0, ',', '.'), 0, 1);
    
    // Query untuk detail transaksi
    if ($role == 'penjual') {
        $sql_detail = "SELECT t.id_transaksi, t.tanggal_transaksi, p.nama_produk, td.qty, td.subtotal
                      FROM transaksi t
                      JOIN transaksi_detail td ON t.id_transaksi = td.id_transaksi
                      JOIN produk p ON td.id_produk = p.id_produk
                      WHERE MONTH(t.tanggal_transaksi) = ? 
                      AND YEAR(t.tanggal_transaksi) = ? 
                      AND t.approve = 'approve'
                      AND p.id_penjual = ?
                      ORDER BY t.tanggal_transaksi DESC";
        $stmt_detail = $conn->prepare($sql_detail);
        $stmt_detail->bind_param("ssi", $selected_month, $selected_year, $user_id);
    } else {
        $sql_detail = "SELECT t.id_transaksi, t.tanggal_transaksi, u.username, t.total_harga
                      FROM transaksi t
                      JOIN users u ON t.id_user = u.id_user
                      WHERE MONTH(t.tanggal_transaksi) = ? 
                      AND YEAR(t.tanggal_transaksi) = ? 
                      AND t.approve = 'approve'
                      ORDER BY t.tanggal_transaksi DESC";
        $stmt_detail = $conn->prepare($sql_detail);
        $stmt_detail->bind_param("ss", $selected_month, $selected_year);
    }
    
    $stmt_detail->execute();
    $result_detail = $stmt_detail->get_result();
    
    // Detail transaksi
    if ($result_detail->num_rows > 0) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Ln(5);
        $pdf->Cell(0, 10, 'Detail Transaksi', 0, 1);
        
        if ($role == 'penjual') {
            // Header untuk penjual
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Cell(30, 8, 'ID Transaksi', 1, 0, 'C', 1);
            $pdf->Cell(40, 8, 'Tanggal', 1, 0, 'C', 1);
            $pdf->Cell(60, 8, 'Produk', 1, 0, 'C', 1);
            $pdf->Cell(20, 8, 'Qty', 1, 0, 'C', 1);
            $pdf->Cell(40, 8, 'Subtotal', 1, 1, 'C', 1);
            
            $pdf->SetFont('helvetica', '', 8);
            $fill = 0;
            while ($row = $result_detail->fetch_assoc()) {
                $fill = !$fill;
                $pdf->SetFillColor($fill ? 245 : 255, 245, 245);
                $pdf->Cell(30, 7, $row['id_transaksi'], 1, 0, 'C', $fill);
                $pdf->Cell(40, 7, date('d-m-Y', strtotime($row['tanggal_transaksi'])), 1, 0, 'C', $fill);
                $pdf->Cell(60, 7, substr($row['nama_produk'], 0, 30), 1, 0, 'L', $fill);
                $pdf->Cell(20, 7, $row['qty'], 1, 0, 'C', $fill);
                $pdf->Cell(40, 7, number_format($row['subtotal'], 0, ',', '.'), 1, 1, 'R', $fill);
            }
        } else {
            // Header untuk admin
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Cell(30, 8, 'ID Transaksi', 1, 0, 'C', 1);
            $pdf->Cell(40, 8, 'Tanggal', 1, 0, 'C', 1);
            $pdf->Cell(70, 8, 'Pembeli', 1, 0, 'C', 1);
            $pdf->Cell(50, 8, 'Total', 1, 1, 'C', 1);
            
            $pdf->SetFont('helvetica', '', 8);
            $fill = 0;
            while ($row = $result_detail->fetch_assoc()) {
                $fill = !$fill;
                $pdf->SetFillColor($fill ? 245 : 255, 245, 245);
                $pdf->Cell(30, 7, $row['id_transaksi'], 1, 0, 'C', $fill);
                $pdf->Cell(40, 7, date('d-m-Y', strtotime($row['tanggal_transaksi'])), 1, 0, 'C', $fill);
                $pdf->Cell(70, 7, substr($row['username'], 0, 25), 1, 0, 'L', $fill);
                $pdf->Cell(50, 7, number_format($row['total_harga'], 0, ',', '.'), 1, 1, 'R', $fill);
            }
        }
    }
    
    // Footer
    $pdf->SetY(-30);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Dokumen ini dibuat secara otomatis oleh Sistem Wesley Bookstore', 0, 0, 'C');
    
    // Return PDF content
    return $pdf->Output('', 'S');
}

// Proses download PDF
if (isset($_GET['action']) && $_GET['action'] == 'download_pdf') {
    // Generate PDF content
    $pdf_content = generatePDFReport($user_id, $username, $role_display, $selected_month, $selected_year, $months, $stats, $weekly_data, $conn);
    
    // Set headers for download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Laporan_Penjualan_' . $months[$selected_month] . '_' . $selected_year . '.pdf"');
    header('Content-Length: ' . strlen($pdf_content));
    
    // Output PDF content
    echo $pdf_content;
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Laporan Penjualan</title>
    <link rel="website icon" type="png" href="../asset/wesley.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .filter-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 180px;
        }
        
        .form-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-select {
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
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
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.2);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: var(--dark);
            border: 2px solid #e1e5eb;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.2);
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
        
        .stat-change {
            font-size: 0.85rem;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            margin-top: 5px;
        }
        
        .stat-change.positive {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }
        
        .stat-change.negative {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        /* Chart Section */
        .chart-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .chart-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .chart-container {
            height: 400px;
            position: relative;
        }

        /* Action Buttons Section */
        .action-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .action-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .action-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            min-width: 180px;
            justify-content: center;
        }

        /* Notification Toast */
        .notification-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            animation: slideIn 0.3s ease;
            max-width: 300px;
        }

        .notification-toast.success {
            background: var(--success);
            color: white;
        }
        
        .notification-toast.error {
            background: var(--danger);
            color: white;
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
        
        /* Loading overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
        }
        
        .loading-overlay.show {
            display: flex;
            animation: fadeIn 0.3s ease;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>Sedang memproses...</p>
    </div>

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
                <a href="laporan.php" class="sidebar-item active">
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
            <h1 class="page-title">Laporan Penjualan</h1>
            <?php if ($role == 'penjual'): ?>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Laporan Penjualan Saya</span>
            </div>
            <?php else: ?>
            <div class="breadcrumb">
                <a href="dashboard.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Laporan Keseluruhan</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-header">
                <h2 class="filter-title">Filter Laporan</h2>
            </div>
            <form method="GET" action="" class="filter-form">
                <div class="form-group">
                    <label class="form-label">Bulan</label>
                    <select name="month" class="form-select" id="monthSelect">
                        <?php foreach ($months as $month_num => $month_name): ?>
                            <option value="<?php echo $month_num; ?>" <?php echo $selected_month == $month_num ? 'selected' : ''; ?>>
                                <?php echo $month_name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tahun</label>
                    <select name="year" class="form-select" id="yearSelect">
                        <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Terapkan Filter
                    </button>
                    <button type="button" class="btn btn-secondary" id="resetFilter">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Action Buttons Section -->
        <div class="action-section">
            <div class="action-header">
                <h2 class="action-title">Ekspor Laporan</h2>
            </div>
            <div class="action-buttons">
                <button class="btn btn-success" id="downloadPDFBtn">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </button>
            </div>
        </div>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_penjualan']; ?></div>
                <div class="stat-label">Total Penjualan</div>
                <?php if ($role == 'penjual'): ?>
                <div class="stat-label" style="font-size: 0.8rem; color: var(--accent);">
                    <i class="fas fa-user"></i> Transaksi Anda
                </div>
                <?php endif; ?>
            </div>
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-value"><?php echo formatRupiah($stats['total_pendapatan']); ?></div>
                <div class="stat-label">Total Pendapatan</div>
                <?php if ($role == 'penjual'): ?>
                <div class="stat-label" style="font-size: 0.8rem; color: var(--accent);">
                    <i class="fas fa-coins"></i> Pendapatan Bersih
                </div>
                <?php endif; ?>
            </div>
            <div class="stat-box">
                <div class="stat-icon" style="background: linear-gradient(135deg, var(--accent) 0%, #8e44ad 100%);">
                    <i class="fas fa-box"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_produk_terjual']; ?></div>
                <div class="stat-label">Produk Terjual</div>
                <?php if ($role == 'penjual'): ?>
                <div class="stat-label" style="font-size: 0.8rem; color: var(--accent);">
                    <i class="fas fa-box-open"></i> Stok Terjual
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chart Section -->
        <div class="chart-section">
            <div class="chart-header">
                <h2 class="chart-title">Grafik Penjualan Bulan <?php echo $months[$selected_month] . ' ' . $selected_year; ?></h2>
                <?php if ($role == 'penjual'): ?>
                <span style="color: var(--gray); font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Menampilkan pendapatan dari produk Anda
                </span>
                <?php endif; ?>
            </div>
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </main>

    <script>
        // Fungsi sederhana untuk inisialisasi
        document.addEventListener('DOMContentLoaded', function() {
            console.log("Halaman dimuat dengan sukses");
            
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

            if (userProfile) {
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
            }

            // Reset filter
            const resetFilter = document.getElementById('resetFilter');
            if (resetFilter) {
                resetFilter.addEventListener('click', () => {
                    window.location.href = 'laporan.php';
                });
            }

            // Download PDF Button
            const downloadPDFBtn = document.getElementById('downloadPDFBtn');
            if (downloadPDFBtn) {
                downloadPDFBtn.addEventListener('click', () => {
                    showLoading();
                    const month = document.getElementById('monthSelect').value;
                    const year = document.getElementById('yearSelect').value;
                    
                    // Redirect to download PDF
                    window.location.href = `laporan.php?action=download_pdf&month=${month}&year=${year}`;
                    
                    // Hide loading after 2 seconds (in case download is slow)
                    setTimeout(() => {
                        hideLoading();
                    }, 2000);
                });
            }

            // Initialize Chart dengan data dari PHP
            try {
                const ctx = document.getElementById('salesChart').getContext('2d');
                
                // Data dari PHP (dikonversi ke array)
                const weeklyData = [
                    <?php echo $weekly_data['minggu_1']; ?>,
                    <?php echo $weekly_data['minggu_2']; ?>,
                    <?php echo $weekly_data['minggu_3']; ?>,
                    <?php echo $weekly_data['minggu_4']; ?>
                ];
                
                const chartLabel = "<?php echo $role == 'penjual' ? 'Pendapatan Anda (Rp)' : 'Pendapatan (Rp)'; ?>";
                
                const salesChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'],
                        datasets: [{
                            label: chartLabel,
                            data: weeklyData,
                            borderColor: '#4a90e2',
                            backgroundColor: 'rgba(74, 144, 226, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        if (value >= 1000000) {
                                            return 'Rp ' + (value / 1000000) + ' JT';
                                        } else if (value >= 1000) {
                                            return 'Rp ' + (value / 1000) + ' K';
                                        }
                                        return 'Rp ' + value;
                                    }
                                }
                            }
                        }
                    }
                });
                
                console.log("Chart berhasil dibuat dengan data:", weeklyData);
            } catch (error) {
                console.error("Error membuat chart:", error);
            }

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
        });

        // Utility Functions
        function showLoading() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.classList.add('show');
            }
        }
        
        function hideLoading() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.classList.remove('show');
            }
        }
        
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            const existingToasts = document.querySelectorAll('.notification-toast');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `notification-toast ${type}`;
            toast.innerHTML = `
                <div class="toast-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
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