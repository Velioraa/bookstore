<?php
// Koneksi ke database
require_once '../koneksi.php';

// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mulai session
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// Cek apakah ada parameter ID transaksi
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = $_SESSION['user_id'];
$download_pdf = isset($_GET['download']) ? true : false;
$show_struk = isset($_GET['show_struk']) ? true : false;

// Jika tidak ada ID, redirect ke keranjang
if ($transaction_id === 0) {
    header("Location: keranjang.php");
    exit();
}

// =============================================================================
// Handle upload bukti pembayaran untuk SEMUA penjual sekaligus
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_all_proofs'])) {
    error_log("Upload all proofs POST received");
    
    try {
        // Ambil data transaksi untuk validasi
        $sql_check = "SELECT * FROM transaksi WHERE id_transaksi = ? AND id_user = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $transaction_id, $user_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows === 0) {
            throw new Exception("Transaksi tidak ditemukan atau tidak memiliki akses");
        }
        
        $transaction_data = $result_check->fetch_assoc();
        
        // Ambil semua penjual dalam transaksi ini
        $sql_sellers = "SELECT DISTINCT p.id_penjual, u.username 
                       FROM transaksi_detail td 
                       JOIN produk p ON td.id_produk = p.id_produk 
                       JOIN users u ON p.id_penjual = u.id_user 
                       WHERE td.id_transaksi = ?";
        $stmt_sellers = $conn->prepare($sql_sellers);
        $stmt_sellers->bind_param("i", $transaction_id);
        $stmt_sellers->execute();
        $result_sellers = $stmt_sellers->get_result();
        
        $uploaded_files = [];
        $upload_errors = [];
        $total_sellers = $result_sellers->num_rows;
        $uploaded_count = 0;
        
        // Loop melalui semua penjual
        while ($seller = $result_sellers->fetch_assoc()) {
            $seller_id = $seller['id_penjual'];
            $seller_name = $seller['username'];
            
            // Cek apakah ada file untuk penjual ini
            $file_key = 'payment_proof_' . $seller_id;
            
            if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) {
                $upload_errors[] = "File untuk penjual {$seller_name} tidak ditemukan";
                continue;
            }
            
            $file_error = $_FILES[$file_key]['error'];
            if ($file_error !== UPLOAD_ERR_OK) {
                $upload_errors[] = "Error upload file untuk penjual {$seller_name} (Error code: {$file_error})";
                continue;
            }
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg', 'application/pdf'];
            $file_type = $_FILES[$file_key]['type'];
            $file_size = $_FILES[$file_key]['size'];
            $file_name = $_FILES[$file_key]['name'];
            
            error_log("Processing file for seller $seller_id ($seller_name): $file_name, Type: $file_type, Size: $file_size bytes");
            
            if (!in_array($file_type, $allowed_types)) {
                $upload_errors[] = "File untuk penjual {$seller_name} harus berupa gambar (JPG, PNG, GIF) atau PDF. Type: {$file_type}";
                continue;
            }
            
            if ($file_size > 5 * 1024 * 1024) {
                $upload_errors[] = "Ukuran file untuk penjual {$seller_name} maksimal 5MB. Ukuran: " . round($file_size / (1024 * 1024), 2) . "MB";
                continue;
            }
            
            // Generate nama file unik
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $new_file_name = 'payment_proof_' . $transaction_id . '_' . $seller_id . '_' . time() . '_' . $uploaded_count . '.' . $file_extension;
            $upload_dir = '../asset/payment_proofs/';
            $upload_path = $upload_dir . $new_file_name;
            
            // Buat folder jika belum ada
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    $upload_errors[] = "Gagal membuat folder upload";
                    continue;
                }
            }
            
            // Cek apakah folder bisa ditulisi
            if (!is_writable($upload_dir)) {
                $upload_errors[] = "Folder upload tidak dapat ditulisi. Periksa permissions folder: " . realpath($upload_dir);
                continue;
            }
            
            // Upload file
            if (!move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_path)) {
                $upload_errors[] = "Gagal mengupload file untuk penjual {$seller_name}";
                error_log("Failed to move uploaded file: " . $_FILES[$file_key]['tmp_name'] . " to " . $upload_path);
                continue;
            }
            
            // Cek apakah file berhasil diupload
            if (!file_exists($upload_path)) {
                $upload_errors[] = "File untuk penjual {$seller_name} tidak berhasil disimpan";
                continue;
            }
            
            error_log("File successfully saved: $upload_path");
            
            // Simpan info file yang berhasil diupload
            $uploaded_files[] = [
                'seller_id' => $seller_id,
                'seller_name' => $seller_name,
                'file_name' => $new_file_name,
                'file_path' => $upload_path
            ];
            
            $uploaded_count++;
            
            // Kirim notifikasi ke penjual
            $notification_title = "Bukti Pembayaran Diupload";
            $notification_message = "Pembeli telah mengupload bukti pembayaran untuk invoice " . ($transaction_data['invoice_number'] ?? $transaction_id);
            
            $sql_notif = "INSERT INTO notifikasi (id_user, id_order, id_produk, judul, pesan, type, created_at) 
                         VALUES (?, ?, NULL, ?, ?, 'notifikasi', NOW())";
            $stmt_notif = $conn->prepare($sql_notif);
            if ($stmt_notif) {
                $stmt_notif->bind_param("iiss", $seller_id, $transaction_id, $notification_title, $notification_message);
                $stmt_notif->execute();
            }
        }
        
        // Jika ada file yang berhasil diupload, update transaksi
        if ($uploaded_count > 0) {
            // Update status transaksi dan simpan bukti pembayaran
            // Simpan file pertama sebagai bukti utama di tabel transaksi
            $first_file = $uploaded_files[0]['file_name'] ?? '';
            
            $sql_update = "UPDATE transaksi SET bukti_pembayaran = ?, status = 'pending' WHERE id_transaksi = ? AND id_user = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("sii", $first_file, $transaction_id, $user_id);
            $stmt_update->execute();
            
            if ($uploaded_count === $total_sellers) {
                $_SESSION['success'] = "✅ Semua bukti pembayaran berhasil diupload! Total {$uploaded_count} dari {$total_sellers} penjual.";
            } else {
                $_SESSION['success'] = "✅ {$uploaded_count} dari {$total_sellers} bukti pembayaran berhasil diupload!";
            }
            
            // Redirect langsung ke dashboard.php setelah berhasil
            header("Location: struk.php?id=" . $transaction_id . "&show_struk=true");
            exit();
            
        } else {
            throw new Exception("❌ Tidak ada file yang berhasil diupload. " . implode(' ', $upload_errors));
        }
        
    } catch (Exception $e) {
        // Hapus file yang sudah terupload jika ada error
        if (isset($uploaded_files) && is_array($uploaded_files)) {
            foreach ($uploaded_files as $file) {
                if (file_exists($file['file_path'])) {
                    @unlink($file['file_path']);
                }
            }
        }
        
        $_SESSION['error'] = $e->getMessage();
        error_log("Upload error: " . $e->getMessage());
        header("Location: struk.php?id=" . $transaction_id);
        exit();
    }
}

// =============================================================================
// Halaman 2: Download PDF Struk
// =============================================================================

// JIKA PARAMETER DOWNLOAD=TRUE, GENERATE PDF DENGAN TCPDF
if ($download_pdf) {
    // Include Composer autoloader untuk TCPDF
    if (file_exists('../vendor/autoload.php')) {
        require_once '../vendor/autoload.php';
    } else {
        die("TCPDF tidak ditemukan. Silakan install dengan: composer require tecnickcom/tcpdf");
    }
    
    // Ambil data transaksi dari tabel 'transaksi'
    $transaksi = null;
    $sql = "SELECT * FROM transaksi WHERE id_transaksi = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ii", $transaction_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $transaksi = $result->fetch_assoc();
            
            // Query untuk mendapatkan item transaksi dari tabel 'transaksi_detail'
            $sql_items = "
                SELECT td.*, p.nama_produk, p.harga_jual
                FROM transaksi_detail td 
                JOIN produk p ON td.id_produk = p.id_produk 
                WHERE td.id_transaksi = ?
            ";
            $stmt_items = $conn->prepare($sql_items);
            if ($stmt_items) {
                $stmt_items->bind_param("i", $transaction_id);
                $stmt_items->execute();
                $items_result = $stmt_items->get_result();
                $items = [];
                while ($row = $items_result->fetch_assoc()) {
                    $items[] = $row;
                }
            }
        }
    }
    
    // Jika tidak ada transaksi, redirect
    if (!$transaksi) {
        header("Location: dashboard.php?error=transaksi_tidak_ditemukan");
        exit();
    }
    
    // Ambil username user dari tabel 'users'
    $username = 'Customer';
    $sql_user = "SELECT username FROM users WHERE id_user = ?";
    $stmt_user = $conn->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $user_result = $stmt_user->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $username = $user_row['username'];
        }
    }
    
    // Format nomor transaksi: TRX-00000(id)
    $no_transaksi = 'TRX-' . str_pad($transaksi['id_transaksi'], 5, '0', STR_PAD_LEFT);
    
    // Hitung kembalian jika ada uang bayar
    $uang_bayar = $transaksi['uang_bayar'] ?? 0;
    $kembalian = max(0, $uang_bayar - $transaksi['total_harga']);
    
    // Hitung subtotal
    $subtotal = 0;
    $items_data = [];
    if (isset($items) && count($items) > 0) {
        foreach ($items as $item) {
            $quantity = isset($item['jumlah']) ? $item['jumlah'] : (isset($item['kuantitas']) ? $item['kuantitas'] : 1);
            $harga = $item['harga_jual'] ?? 0;
            $item_total = $harga * $quantity;
            $subtotal += $item_total;
            
            $items_data[] = [
                'nama_produk' => $item['nama_produk'] ?? 'Produk',
                'quantity' => $quantity,
                'harga_jual' => $harga,
                'total_item' => $item_total
            ];
        }
    } else {
        $subtotal = $transaksi['total_harga'];
    }
    
    // Hitung grand total
    $diskonPoin = $transaksi['diskon'] ?? 0;
    $grandTotal = $subtotal - $diskonPoin;
    
    // Buat PDF baru dengan TCPDF
    $pdf = new TCPDF('P', 'mm', array(80, 297), true, 'UTF-8', false);
    
    // Set dokumen information
    $pdf->SetCreator('Wesley Bookstore');
    $pdf->SetAuthor('Wesley Bookstore');
    $pdf->SetTitle('Struk ' . $no_transaksi);
    $pdf->SetSubject('Struk Pembayaran');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(true, 5);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 9);
    
    // HTML content untuk PDF
    $html = '
    <style>
        body { font-family: helvetica; font-size: 9pt; }
        .header { text-align: center; margin-bottom: 5mm; border-bottom: 1px dashed #4dabf7; padding-bottom: 3mm; }
        .store-name { font-size: 12pt; font-weight: bold; color: #1971c2; }
        .store-address { font-size: 7pt; color: #495057; }
        .receipt-info { margin: 3mm 0; font-size: 8pt; }
        .transaction-items { width: 100%; border-collapse: collapse; margin: 3mm 0; font-size: 8pt; }
        .transaction-items th { border-bottom: 1px solid #4dabf7; padding: 2mm 0; color: #1971c2; }
        .transaction-items td { padding: 1.5mm 0; border-bottom: 1px dashed #dee2e6; }
        .item-qty { width: 10mm; text-align: center; }
        .item-price { width: 15mm; text-align: right; }
        .divider { border-top: 1px dashed #4dabf7; margin: 3mm 0; }
        .total-section { margin: 4mm 0; font-size: 9pt; }
        .total-line { margin: 1mm 0; }
        .grand-total { font-weight: bold; font-size: 10pt; margin: 2mm 0; padding: 2mm 0; border-top: 1px solid #4dabf7; border-bottom: 1px solid #4dabf7; }
        .footer-message { text-align: center; margin-top: 5mm; padding-top: 3mm; border-top: 1px dashed #4dabf7; font-size: 7pt; }
    </style>
    
    <div class="header">
        <div class="store-name">WESLEY BOOKSTORE</div>
        <div class="store-address">
            Jl. Dr. KRT Radjiman Widjoyodiningrat<br>
            Jatinegara, Jakarta Timur<br>
            Telp: (021) 1234-5678
        </div>
    </div>
    
    <div class="receipt-info">
        <div><strong>Tanggal:</strong> ' . date('d/m/Y H:i:s', strtotime($transaksi['tanggal_transaksi'] ?? $transaksi['tanggal'] ?? date('Y-m-d H:i:s'))) . '</div>
        <div><strong>No. Transaksi:</strong> ' . $no_transaksi . '</div>
        <div><strong>Invoice:</strong> ' . ($transaksi['invoice_number'] ?? 'N/A') . '</div>
    </div>
    
    <table class="transaction-items">
        <tr>
            <th class="item-qty">Qty</th>
            <th>Item</th>
            <th class="item-price">Harga</th>
            <th class="item-price">Subtotal</th>
        </tr>';
    
    if (count($items_data) > 0) {
        foreach ($items_data as $item) {
            $html .= '
            <tr>
                <td class="item-qty">' . $item['quantity'] . '</td>
                <td>' . htmlspecialchars($item['nama_produk']) . '</td>
                <td class="item-price">Rp ' . number_format($item['harga_jual'], 0, ',', '.') . '</td>
                <td class="item-price">Rp ' . number_format($item['total_item'], 0, ',', '.') . '</td>
            </tr>';
        }
    } else {
        $html .= '
            <tr>
                <td colspan="4" style="text-align: center; color: #868e96; font-style: italic;">
                    Tidak ada item dalam transaksi ini
                </td>
            </tr>';
    }
    
    $html .= '
    </table>
    
    <div class="divider"></div>
    
    <div class="total-section">
        <div class="total-line"><span>Subtotal:</span> <span style="float: right;">Rp ' . number_format($subtotal, 0, ',', '.') . '</span></div>';
    
    if ($diskonPoin > 0) {
        $html .= '
        <div class="total-line"><span>Potongan Koin:</span> <span style="float: right;">- Rp ' . number_format($diskonPoin, 0, ',', '.') . '</span></div>';
    }
    
    $html .= '
        <div class="total-line grand-total"><span>TOTAL:</span> <span style="float: right;">Rp ' . number_format($grandTotal, 0, ',', '.') . '</span></div>';
    
    if ($uang_bayar > 0) {
        $html .= '
        <div class="total-line"><span>Uang Bayar:</span> <span style="float: right;">Rp ' . number_format($uang_bayar, 0, ',', '.') . '</span></div>
        <div class="total-line"><span>Kembalian:</span> <span style="float: right;">Rp ' . number_format($kembalian, 0, ',', '.') . '</span></div>';
    }
    
    if (isset($transaksi['metode_pembayaran'])) {
        $html .= '
        <div style="margin-top: 3mm; padding: 2mm; background: #e7f5ff; border-radius: 2mm; font-size: 8pt;">
            <strong>Metode Pembayaran:</strong> ' . htmlspecialchars($transaksi['metode_pembayaran']) . '
        </div>';
    }
    
    $html .= '
    </div>
    
    <div class="footer-message">
        Terima kasih atas kunjungan Anda<br>
        Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan<br>
        Selamat datang kembali
    </div>';
    
    // Write HTML content
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Output PDF untuk di-download
    $pdf->Output('Struk_' . $no_transaksi . '_' . date('Ymd_His') . '.pdf', 'D');
    
    exit();
}

// =============================================================================
// Logika tampilan (hanya tampil jika belum upload)
// =============================================================================

// Ambil data transaksi untuk ditampilkan
$sql = "SELECT t.*, u.username, u.email 
        FROM transaksi t 
        LEFT JOIN users u ON t.id_user = u.id_user 
        WHERE t.id_transaksi = ? AND t.id_user = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $transaction_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: keranjang.php");
    exit();
}

$transaction = $result->fetch_assoc();

// Cek apakah sudah ada bukti pembayaran
$has_uploaded_proof = !empty($transaction['bukti_pembayaran']);

// Jika sudah upload dan tidak menampilkan struk, langsung redirect ke halaman struk
if ($has_uploaded_proof && !$show_struk) {
    header("Location: struk.php?id=" . $transaction_id . "&show_struk=true");
    exit();
}

// Ambil detail transaksi per penjual untuk ditampilkan
$sql_sellers = "SELECT 
    p.id_penjual,
    u.username as nama_penjual,
    u.nama_bank,
    u.no_rekening,
    COUNT(td.id_detail) as jumlah_item,
    SUM(td.subtotal) as total_harga
FROM transaksi_detail td 
JOIN produk p ON td.id_produk = p.id_produk 
JOIN users u ON p.id_penjual = u.id_user 
WHERE td.id_transaksi = ? 
GROUP BY p.id_penjual, u.username, u.nama_bank, u.no_rekening
ORDER BY p.id_penjual";

$stmt_sellers = $conn->prepare($sql_sellers);
$stmt_sellers->bind_param("i", $transaction_id);
$stmt_sellers->execute();
$result_sellers = $stmt_sellers->get_result();
$all_sellers_data = [];

while ($seller = $result_sellers->fetch_assoc()) {
    $seller_id_penjual = $seller['id_penjual'];
    
    // Ambil detail item untuk penjual ini
    $sql_seller_items = "SELECT td.* 
                        FROM transaksi_detail td 
                        JOIN produk p ON td.id_produk = p.id_produk 
                        WHERE td.id_transaksi = ? AND p.id_penjual = ?";
    $stmt_items = $conn->prepare($sql_seller_items);
    $stmt_items->bind_param("ii", $transaction_id, $seller_id_penjual);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    
    $seller_items = [];
    while ($item = $result_items->fetch_assoc()) {
        $seller_items[] = $item;
    }
    
    // Tambahkan items ke data seller
    $seller['items'] = $seller_items;
    $seller['seller_id'] = $seller_id_penjual;
    
    $all_sellers_data[] = $seller;
}

// Hitung total keseluruhan
$total_all_items = 0;
$total_all_price = 0;
foreach ($all_sellers_data as $seller) {
    $total_all_items += $seller['jumlah_item'];
    $total_all_price += $seller['total_harga'];
}

// Ambil detail transaksi untuk halaman struk
$sql_detail = "SELECT * FROM transaksi_detail WHERE id_transaksi = ?";
$stmt_detail = $conn->prepare($sql_detail);
$stmt_detail->bind_param("i", $transaction_id);
$stmt_detail->execute();
$detail_result = $stmt_detail->get_result();
$transaction_details = [];

while ($detail = $detail_result->fetch_assoc()) {
    $transaction_details[] = $detail;
}

// Data untuk struk
$transaksi = $transaction;
$items_data = $transaction_details;
$no_transaksi = 'TRX-' . str_pad($transaction_id, 5, '0', STR_PAD_LEFT);
$subtotal = $transaction['total_harga'] ?? 0;
$diskonPoin = $transaction['diskon'] ?? 0;
$grandTotal = $subtotal - $diskonPoin;
$uang_bayar = $transaction['uang_bayar'] ?? 0;
$kembalian = max(0, $uang_bayar - $grandTotal);

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $show_struk ? 'Struk Pembayaran' : 'Upload Bukti Pembayaran'; ?> - Wesley Bookstore</title>
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
            --sky-blue: #87ceeb;
            --light-blue: #e3f2fd;
            --border: #e0e0e0;
            --shadow: 0 2px 8px rgba(0,0,0,0.08);
            --hover-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .header {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .back-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: var(--light-blue);
            color: var(--secondary);
            border: none;
            cursor: pointer;
            font-size: 1.3rem;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: #d4e4f7;
            transform: translateX(-3px);
        }

        .header-title {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-title i {
            color: var(--secondary);
            font-size: 1.5rem;
        }

        .header-title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: 25px;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        /* Status Bar */
        .status-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            margin-bottom: 30px;
            position: relative;
        }

        .status-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            z-index: 2;
        }

        .status-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: white;
            border: 3px solid var(--border);
            color: var(--gray);
            transition: all 0.3s ease;
        }

        .status-step.active .status-icon {
            background: var(--secondary);
            border-color: var(--secondary);
            color: white;
            box-shadow: 0 0 0 8px rgba(74, 144, 226, 0.1);
        }

        .status-step.completed .status-icon {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .status-label {
            font-weight: 600;
            color: var(--gray);
            text-align: center;
        }

        .status-step.active .status-label {
            color: var(--secondary);
        }

        .status-step.completed .status-label {
            color: var(--success);
        }

        .status-connector {
            position: absolute;
            top: 30px;
            left: 150px;
            right: 150px;
            height: 3px;
            background: var(--border);
            z-index: 1;
        }

        /* Invoice Card */
        .invoice-card {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .invoice-header {
            padding: 25px;
            border-bottom: 1px solid var(--border);
            background: var(--light-blue);
        }

        .invoice-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .invoice-content {
            padding: 25px;
        }

        /* Transaction Info */
        .transaction-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 500;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--primary);
            font-weight: 600;
        }

        /* Seller Section */
        .seller-section {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 25px;
            border: 1px solid var(--border);
        }

        .seller-header {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .seller-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .seller-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .seller-status.pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .seller-content {
            padding: 25px;
        }

        /* Table container for invoice items */
        .invoice-table-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .invoice-table th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--primary);
            border-bottom: 2px solid var(--border);
            z-index: 10;
        }

        .invoice-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        .invoice-table tr:last-child td {
            border-bottom: none;
        }

        /* Payment Summary per seller */
        .seller-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .seller-summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 1rem;
        }

        .seller-summary-label {
            color: var(--gray);
        }

        .seller-summary-value {
            font-weight: 600;
            color: var(--primary);
        }

        .seller-summary-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--danger);
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid var(--border);
        }

        /* Bank Info */
        .bank-info {
            background: #e6f7ff;
            border: 1px solid var(--secondary);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .bank-title {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 10px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bank-details {
            color: var(--primary);
            font-size: 1.1rem;
            line-height: 1.8;
        }

        /* File Upload Per Seller - VERSI SIMPLE */
        .seller-upload-simple {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed var(--border);
        }

        .upload-label-simple {
            display: block;
            padding: 12px;
            background: white;
            border: 2px solid var(--secondary);
            border-radius: 8px;
            color: var(--secondary);
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .upload-label-simple:hover {
            background: var(--light-blue);
            transform: translateY(-2px);
        }

        .file-input-simple {
            display: none;
        }

        .file-preview-simple {
            margin-top: 10px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-preview-simple img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
        }

        .file-info-simple {
            flex: 1;
        }

        .file-name-simple {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            margin-bottom: 3px;
        }

        .file-size-simple {
            color: var(--gray);
            font-size: 0.8rem;
        }

        .remove-btn-simple {
            padding: 5px 10px;
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.8rem;
        }

        /* Upload Button - TOMBOL UTAMA */
        .upload-all-btn {
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, var(--success) 0%, #28a745 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.3rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            transition: all 0.3s ease;
            margin-top: 30px;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .upload-all-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }

        .upload-all-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            background: var(--gray);
        }

        /* Success Message */
        .success-message {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-top: 25px;
        }

        .success-icon {
            font-size: 5rem;
            color: var(--success);
            margin-bottom: 20px;
        }

        .success-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .success-description {
            color: var(--gray);
            font-size: 1.1rem;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .action-btn {
            flex: 1;
            min-width: 200px;
            padding: 18px;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            border: none;
        }

        .action-btn.primary {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
        }

        .action-btn.primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(74, 144, 226, 0.4);
        }

        .action-btn.secondary {
            background: white;
            color: var(--primary);
            border: 2px solid var(--border);
        }

        .action-btn.secondary:hover {
            border-color: var(--secondary);
            background: var(--light-blue);
        }

        /* Upload Status */
        .upload-status {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            text-align: center;
        }

        .upload-status-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 10px;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            display: none;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--secondary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast notification */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            display: none;
        }
        
        .toast.success {
            background: var(--success);
        }
        
        .toast.error {
            background: var(--danger);
        }
        
        .toast.warning {
            background: var(--warning);
        }
        
        .toast.info {
            background: var(--info);
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

        /* =============================================================================
           Halaman 2: Struk Pembayaran (Tampilan khusus untuk halaman struk)
           ============================================================================= */
        .receipt-page-content {
            flex: 1;
            padding: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #e6f7ff 0%, #f0f9ff 100%);
        }

        /* Receipt Container (Halaman 2) */
        .receipt-container {
            background: white;
            padding: 25px;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 119, 204, 0.15);
            border-top: 4px solid #4dabf7;
            position: relative;
            margin-bottom: 30px;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px dashed #4dabf7;
        }

        .store-name {
            font-weight: bold;
            font-size: 24px;
            margin-bottom: 5px;
            color: #1971c2;
        }

        .store-address {
            font-size: 14px;
            margin-bottom: 5px;
            line-height: 1.4;
            color: #495057;
        }

        .store-contact {
            font-size: 13px;
            color: #868e96;
        }

        .receipt-info {
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border-left: 3px solid #4dabf7;
        }

        .transaction-items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .transaction-items th {
            border-bottom: 2px solid #4dabf7;
            padding: 10px 5px;
            text-align: left;
            background: #e7f5ff;
            color: #1971c2;
        }

        .transaction-items td {
            padding: 8px 5px;
            vertical-align: top;
            border-bottom: 1px dashed #dee2e6;
        }

        .item-name {
            width: 50%;
        }

        .item-qty {
            width: 15%;
            text-align: center;
        }

        .item-price {
            width: 35%;
            text-align: right;
        }

        .divider {
            border-top: 2px dashed #4dabf7;
            margin: 15px 0;
        }

        .total-section {
            font-size: 16px;
            margin-top: 15px;
        }

        .total-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 5px 0;
        }

        .grand-total {
            font-weight: bold;
            font-size: 18px;
            border-top: 2px solid #4dabf7;
            padding-top: 10px;
            margin-top: 10px;
            color: #1971c2;
        }

        .footer-message {
            text-align: center;
            font-size: 13px;
            margin-top: 20px;
            line-height: 1.5;
            padding-top: 15px;
            border-top: 2px dashed #4dabf7;
            color: #495057;
        }

        .receipt-number {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 12px;
            color: #868e96;
            font-weight: bold;
        }

        /* Button Container (Halaman 2) */
        .button-container {
            text-align: center;
            margin-top: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }

        /* Download PDF Button */
        .btn-pdf {
            background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(51, 154, 240, 0.3);
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
            text-decoration: none;
        }
        
        .btn-pdf:hover {
            background: linear-gradient(135deg, #228be6 0%, #1971c2 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(51, 154, 240, 0.4);
        }

        /* Overall Summary */
        .overall-summary {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: 25px;
            margin-top: 20px;
        }

        @media print {
            .header,
            .status-bar,
            .action-buttons,
            .success-message,
            .button-container,
            .back-button,
            .upload-status,
            .overall-summary {
                display: none !important;
            }
            
            .receipt-container {
                box-shadow: none;
                border: none;
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
        }

        @media (max-width: 768px) {
            .status-bar {
                gap: 15px;
            }
            
            .status-icon {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
            
            .status-connector {
                left: 120px;
                right: 120px;
            }
            
            .seller-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-btn {
                min-width: 100%;
            }
            
            .button-container {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-pdf {
                width: 100%;
                max-width: 250px;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }
            
            .invoice-header,
            .invoice-content,
            .seller-content {
                padding: 15px;
            }
            
            .status-bar {
                gap: 10px;
            }
            
            .status-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .status-connector {
                left: 90px;
                right: 90px;
            }
            
            .receipt-container {
                padding: 15px;
                max-width: 350px;
            }
        }
    </style>
</head>
<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast notification -->
    <div class="toast" id="toast"></div>
    
    <?php if (!$show_struk): ?>
    <!-- =============================================================================
         Halaman 1: Upload Bukti Pembayaran untuk Multi-Penjual
         ============================================================================= -->
    
    <!-- Header -->
    <div class="header">
        <button class="back-button" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i>
        </button>
        
        <div class="header-title">
            <i class="fas fa-file-upload"></i>
            <h1>Upload Bukti Pembayaran</h1>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Status Bar -->
        <div class="status-bar">
            <div class="status-step completed">
                <div class="status-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="status-label">Keranjang</div>
            </div>
            
            <div class="status-step completed">
                <div class="status-icon">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="status-label">Pembayaran</div>
            </div>
            
            <div class="status-step active">
                <div class="status-icon">
                    <i class="fas fa-upload"></i>
                </div>
                <div class="status-label">Upload Bukti</div>
            </div>
            
            <div class="status-step">
                <div class="status-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="status-label">Selesai</div>
            </div>
            
            <div class="status-connector"></div>
        </div>

        <!-- Invoice Card -->
        <div class="invoice-card">
            <div class="invoice-header">
                <h3 class="invoice-title">
                    <i class="fas fa-receipt"></i>
                    Detail Transaksi - <?php echo $transaction['invoice_number']; ?>
                </h3>
            </div>
            
            <div class="invoice-content">
                <!-- Transaction Info -->
                <div class="transaction-info">
                    <div class="info-item">
                        <span class="info-label">Tanggal Transaksi</span>
                        <span class="info-value"><?php echo date('d/m/Y H:i:s', strtotime($transaction['tanggal_transaksi'] ?? date('Y-m-d H:i:s'))); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Metode Pembayaran</span>
                        <span class="info-value"><?php echo strtoupper($transaction['metode_pembayaran']); ?></span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Jumlah Penjual</span>
                        <span class="info-value"><?php echo count($all_sellers_data); ?> Penjual</span>
                    </div>
                    
                    <div class="info-item">
                        <span class="info-label">Total Item</span>
                        <span class="info-value"><?php echo $total_all_items; ?> item</span>
                    </div>
                </div>
                
                <!-- Status Pembayaran -->
                <div style="margin: 20px 0; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Status:</strong> 
                    Transaksi ini terdiri dari <?php echo count($all_sellers_data); ?> penjual. 
                    Anda perlu mengupload bukti pembayaran untuk setiap penjual.
                </div>
            </div>
        </div>

        <?php if (!$has_uploaded_proof): ?>
        <!-- FORM UTAMA dengan 1 TOMBOL untuk upload semua bukti -->
        <form id="uploadAllForm" method="POST" enctype="multipart/form-data" action="">
            <input type="hidden" name="upload_all_proofs" value="true">
            
            <?php foreach ($all_sellers_data as $index => $seller): ?>
            <!-- Seller Section -->
            <div class="seller-section" id="seller-<?php echo $seller['seller_id']; ?>">
                <div class="seller-header">
                    <div class="seller-title">
                        <i class="fas fa-store"></i>
                        Penjual <?php echo $index + 1; ?>: <?php echo htmlspecialchars($seller['nama_penjual']); ?>
                    </div>
                    
                    <div class="seller-status pending">
                        <i class="fas fa-exclamation-circle"></i>
                        Belum Bayar
                    </div>
                </div>
                
                <div class="seller-content">
                    <!-- Transaction Items for this seller -->
                    <h4 style="font-size: 1.1rem; color: var(--primary); margin-bottom: 15px; font-weight: 600;">
                        <i class="fas fa-box"></i> Daftar Produk (<?php echo $seller['jumlah_item']; ?> item)
                    </h4>
                    
                    <div class="invoice-table-container">
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Produk</th>
                                    <th>Jumlah</th>
                                    <th>Harga</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seller['items'] as $item_index => $item): ?>
                                <tr>
                                    <td><?php echo $item_index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($item['nama_produk']); ?></td>
                                    <td><?php echo $item['qty']; ?></td>
                                    <td>Rp <?php echo number_format($item['harga'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Payment Summary for this seller -->
                    <div class="seller-summary">
                        <div class="seller-summary-row">
                            <span class="seller-summary-label">Total Item:</span>
                            <span class="seller-summary-value"><?php echo $seller['jumlah_item']; ?> item</span>
                        </div>
                        
                        <div class="seller-summary-row total">
                            <span class="seller-summary-label">TOTAL BAYAR untuk <?php echo htmlspecialchars($seller['nama_penjual']); ?>:</span>
                            <span class="seller-summary-value">Rp <?php echo number_format($seller['total_harga'], 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    
                    <!-- Bank Info for this seller -->
                    <div class="bank-info">
                        <div class="bank-title">
                            <i class="fas fa-university"></i>
                            Rekening Penjual <?php echo htmlspecialchars($seller['nama_penjual']); ?>:
                        </div>
                        <div class="bank-details">
                            <strong><?php echo htmlspecialchars($seller['nama_bank'] ?? 'Bank Tidak Tersedia'); ?></strong><br>
                            No. Rekening: <?php echo htmlspecialchars($seller['no_rekening'] ?? 'Tidak Tersedia'); ?><br>
                            Transfer sebesar: <strong>Rp <?php echo number_format($seller['total_harga'], 0, ',', '.'); ?></strong>
                        </div>
                    </div>
                    
                    <!-- File Upload Input -->
                    <div class="seller-upload-simple">
                        <label class="upload-label-simple" for="paymentProof_<?php echo $seller['seller_id']; ?>">
                            <i class="fas fa-upload"></i> Pilih File untuk <?php echo htmlspecialchars($seller['nama_penjual']); ?>
                        </label>
                        <input type="file" class="file-input-simple" 
                               id="paymentProof_<?php echo $seller['seller_id']; ?>" 
                               name="payment_proof_<?php echo $seller['seller_id']; ?>" 
                               accept=".jpg,.jpeg,.png,.gif,.pdf" 
                               data-seller-id="<?php echo $seller['seller_id']; ?>"
                               data-seller-name="<?php echo htmlspecialchars($seller['nama_penjual']); ?>"
                               onchange="handleFileSelectSimple(this)">
                        
                        <div class="file-preview-simple" id="preview_<?php echo $seller['seller_id']; ?>" style="display: none;">
                            <!-- Preview akan muncul di sini -->
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Upload Status -->
            <div class="upload-status">
                <div class="upload-status-title" id="uploadStatus">
                    Pilih file untuk <?php echo count($all_sellers_data); ?> penjual
                </div>
                <div id="selectedFilesCount">0 dari <?php echo count($all_sellers_data); ?> file dipilih</div>
            </div>
            
            <!-- TOMBOL UPLOAD UTAMA - HANYA SATU -->
            <button type="submit" class="upload-all-btn" id="uploadAllBtn" disabled>
                <i class="fas fa-upload"></i>
                Upload Semua Bukti Pembayaran
            </button>
        </form>
        
        <?php else: ?>
        <!-- Jika sudah upload -->
        <div class="success-message">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="success-title">Bukti Pembayaran Sudah Diupload</div>
            <div class="success-description">
                Terima kasih telah mengupload bukti pembayaran untuk semua penjual. Transaksi Anda sedang menunggu approval dari penjual. Anda akan mendapatkan notifikasi ketika transaksi sudah diproses.
            </div>
            
            <div class="action-buttons">
                <button class="action-btn primary" onclick="window.location.href='struk.php?id=<?php echo $transaction_id; ?>&show_struk=true'">
                    <i class="fas fa-receipt"></i>
                    Lihat Struk
                </button>
                <button class="action-btn secondary" onclick="window.location.href='dashboard.php'">
                    <i class="fas fa-home"></i>
                    Kembali ke Dashboard
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Overall Summary -->
        <div class="overall-summary">
            <h3 style="font-size: 1.3rem; font-weight: 700; color: var(--primary); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-chart-bar"></i>
                Ringkasan Transaksi
            </h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div>
                    <div style="font-size: 1rem; color: var(--gray); font-weight: 500;">Total Penjual</div>
                    <div style="font-size: 1.5rem; color: var(--primary); font-weight: 700;"><?php echo count($all_sellers_data); ?></div>
                </div>
                
                <div>
                    <div style="font-size: 1rem; color: var(--gray); font-weight: 500;">Total Item</div>
                    <div style="font-size: 1.5rem; color: var(--primary); font-weight: 700;"><?php echo $total_all_items; ?></div>
                </div>
                
                <div>
                    <div style="font-size: 1rem; color: var(--gray); font-weight: 500;">Total Transaksi</div>
                    <div style="font-size: 1.5rem; color: var(--danger); font-weight: 700;">
                        Rp <?php echo number_format($total_all_price, 0, ',', '.'); ?>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 25px; padding: 15px; background: #fff3cd; border-radius: 8px;">
                <div style="font-weight: 600; color: #856404; margin-bottom: 8px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Status Upload:
                </div>
                <div style="color: #856404;">
                    <?php if ($has_uploaded_proof): ?>
                        <strong>✅ Semua bukti pembayaran sudah diupload (Menunggu approval)</strong>
                    <?php else: ?>
                        <strong><?php echo count($all_sellers_data); ?> penjual menunggu upload bukti</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- =============================================================================
         Halaman 2: Struk Pembayaran
         ============================================================================= -->
    
    <!-- Header -->
    <div class="header">
        <button class="back-button" onclick="window.location.href='struk.php?id=<?php echo $transaction_id; ?>'">
            <i class="fas fa-arrow-left"></i>
        </button>
        
        <div class="header-title">
            <i class="fas fa-receipt"></i>
            <h1>Struk Pembayaran</h1>
        </div>
    </div>

    <!-- Main Content untuk Halaman Struk -->
    <div class="receipt-page-content">
        <!-- Struk Pembayaran -->
        <div class="receipt-container">
            <div class="receipt-number"><?php echo $no_transaksi; ?></div>
            
            <div class="receipt-header">
                <div class="store-name">WESLEY BOOKSTORE</div>
                <div class="store-address">
                    Jl. Dr. KRT Radjiman Widjoyodiningrat<br>
                    Jatinegara, Jakarta Timur
                </div>
                <div class="store-contact">📞 (021) 1234-5678</div>
            </div>
            
            <div class="receipt-info">
                <div><strong>Tanggal:</strong> <?php echo date('d/m/Y H:i:s', strtotime($transaksi['tanggal_transaksi'] ?? $transaksi['tanggal'] ?? date('Y-m-d H:i:s'))); ?></div>
                <div><strong>No. Transaksi:</strong> <?php echo $no_transaksi; ?></div>
                <div><strong>Invoice:</strong> <?php echo $transaksi['invoice_number']; ?></div>
                <div><strong>Jumlah Penjual:</strong> <?php echo count($all_sellers_data); ?> Penjual</div>
            </div>
            
            <table class="transaction-items">
                <tr>
                    <th class="item-qty">Qty</th>
                    <th class="item-name">Item</th>
                    <th class="item-price">Harga</th>
                    <th class="item-price">Subtotal</th>
                </tr>
                <?php if (count($items_data) > 0): ?>
                    <?php foreach ($items_data as $item): ?>
                    <tr>
                        <td class="item-qty"><?php echo $item['qty'] ?? 1; ?></td>
                        <td class="item-name"><?php echo htmlspecialchars($item['nama_produk']); ?></td>
                        <td class="item-price">Rp <?php echo number_format($item['harga'], 0, ',', '.'); ?></td>
                        <td class="item-price">Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 15px; color: #868e96; font-style: italic;">
                            Tidak ada item dalam transaksi ini
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            
            <div class="divider"></div>
            
            <div class="total-section">
                <div class="total-line">
                    <span>Subtotal:</span>
                    <span>Rp <?php echo number_format($subtotal, 0, ',', '.'); ?></span>
                </div>
                
                <?php if ($diskonPoin > 0): ?>
                <div class="total-line">
                    <span>Potongan Koin:</span>
                    <span>- Rp <?php echo number_format($diskonPoin, 0, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="total-line grand-total">
                    <span>TOTAL:</span>
                    <span>Rp <?php echo number_format($grandTotal, 0, ',', '.'); ?></span>
                </div>
                
                <?php if ($uang_bayar > 0): ?>
                <div class="total-line">
                    <span>Uang Bayar:</span>
                    <span>Rp <?php echo number_format($uang_bayar, 0, ',', '.'); ?></span>
                </div>
                
                <div class="total-line">
                    <span>Kembalian:</span>
                    <span>Rp <?php echo number_format($kembalian, 0, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Metode Pembayaran -->
            <?php if (isset($transaksi['metode_pembayaran'])): ?>
            <div style="margin-top: 15px; padding: 10px; background: #e7f5ff; border-radius: 5px; font-size: 14px; border-left: 3px solid #4dabf7;">
                <strong>Metode Pembayaran:</strong> <?php echo htmlspecialchars($transaksi['metode_pembayaran']); ?>
            </div>
            <?php endif; ?>
            
            <!-- Status Bukti Transfer -->
            <div style="margin-top: 10px; padding: 8px; background: #f8f9fa; border-radius: 5px; font-size: 13px; border: 1px dashed #4dabf7;">
                <strong>Status Pembayaran:</strong> 
                <?php if ($has_uploaded_proof): ?>
                    <span style="color: var(--success);">✅ Bukti sudah diupload untuk semua penjual (Menunggu approval)</span>
                <?php else: ?>
                    <span style="color: var(--danger);">❌ Belum upload bukti untuk <?php echo count($all_sellers_data); ?> penjual</span>
                <?php endif; ?>
            </div>
            
            <div class="footer-message">
                Terima kasih atas kunjungan Anda<br>
                Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan<br>
                Selamat datang kembali
            </div>
        </div>
        
        <!-- Button Container DI BAWAH STRUK -->
        <div class="button-container">
            <a href="struk.php?id=<?php echo $transaction_id; ?>&download=true" class="btn-pdf">
                <i class="fas fa-download"></i> DOWNLOAD PDF STRUK
            </a>
            <button class="action-btn secondary" onclick="window.location.href='dashboard.php'">
                <i class="fas fa-home"></i>
                Kembali ke Dashboard
            </button>
            <?php if (!$has_uploaded_proof): ?>
            <button class="action-btn primary" onclick="window.location.href='struk.php?id=<?php echo $transaction_id; ?>'">
                <i class="fas fa-upload"></i>
                Upload Bukti Pembayaran
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Track selected files
        let selectedFiles = {};
        const totalSellers = <?php echo count($all_sellers_data); ?>;
        
        // Show toast notification
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }

        // Show loading overlay
        function showLoading(show = true) {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.style.display = show ? 'flex' : 'none';
            }
        }

        // Simple file selection handler
        function handleFileSelectSimple(input) {
            const file = input.files[0];
            const sellerId = input.getAttribute('data-seller-id');
            const sellerName = input.getAttribute('data-seller-name');
            const previewDiv = document.getElementById('preview_' + sellerId);
            
            if (!file) {
                // Remove file
                delete selectedFiles[sellerId];
                previewDiv.style.display = 'none';
                previewDiv.innerHTML = '';
                updateUploadStatus();
                return;
            }
            
            // Validate file
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg', 'application/pdf'];
            const maxSize = 5 * 1024 * 1024; // 5MB
            
            if (!allowedTypes.includes(file.type)) {
                showToast('File untuk ' + sellerName + ' harus berupa gambar (JPG, PNG, GIF) atau PDF', 'error');
                input.value = '';
                delete selectedFiles[sellerId];
                previewDiv.style.display = 'none';
                previewDiv.innerHTML = '';
                updateUploadStatus();
                return;
            }
            
            if (file.size > maxSize) {
                showToast('File untuk ' + sellerName + ' maksimal 5MB. Ukuran: ' + Math.round(file.size / (1024 * 1024) * 100) / 100 + 'MB', 'error');
                input.value = '';
                delete selectedFiles[sellerId];
                previewDiv.style.display = 'none';
                previewDiv.innerHTML = '';
                updateUploadStatus();
                return;
            }
            
            // Add to selected files
            selectedFiles[sellerId] = {
                file: file,
                sellerId: sellerId,
                sellerName: sellerName
            };
            
            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                let previewHTML = '';
                
                if (file.type.startsWith('image/')) {
                    previewHTML = `
                        <img src="${e.target.result}" alt="Preview">
                    `;
                } else {
                    previewHTML = `
                        <div style="font-size: 2rem; color: var(--danger);">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                    `;
                }
                
                previewHTML += `
                    <div class="file-info-simple">
                        <div class="file-name-simple">${file.name}</div>
                        <div class="file-size-simple">${Math.round(file.size / 1024 * 100) / 100} KB</div>
                    </div>
                    <button type="button" class="remove-btn-simple" onclick="removeFileSimple('${sellerId}')">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                previewDiv.innerHTML = previewHTML;
                previewDiv.style.display = 'flex';
            };
            
            reader.readAsDataURL(file);
            updateUploadStatus();
        }

        // Remove file
        function removeFileSimple(sellerId) {
            const input = document.getElementById('paymentProof_' + sellerId);
            const previewDiv = document.getElementById('preview_' + sellerId);
            
            if (input) {
                input.value = '';
            }
            
            delete selectedFiles[sellerId];
            
            if (previewDiv) {
                previewDiv.style.display = 'none';
                previewDiv.innerHTML = '';
            }
            
            updateUploadStatus();
        }

        // Update upload status
        function updateUploadStatus() {
            const selectedCount = Object.keys(selectedFiles).length;
            const statusElement = document.getElementById('uploadStatus');
            const countElement = document.getElementById('selectedFilesCount');
            const uploadBtn = document.getElementById('uploadAllBtn');
            
            if (statusElement) {
                countElement.textContent = selectedCount + ' dari ' + totalSellers + ' file dipilih';
                
                if (selectedCount === 0) {
                    statusElement.innerHTML = '<i class="fas fa-info-circle"></i> Pilih file untuk ' + totalSellers + ' penjual';
                    if (uploadBtn) uploadBtn.disabled = true;
                    if (uploadBtn) uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Semua Bukti Pembayaran';
                } else if (selectedCount === totalSellers) {
                    statusElement.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> Semua file sudah dipilih';
                    if (uploadBtn) uploadBtn.disabled = false;
                    if (uploadBtn) uploadBtn.innerHTML = '<i class="fas fa-upload"></i> Upload Semua Bukti Pembayaran';
                } else {
                    statusElement.innerHTML = '<i class="fas fa-exclamation-circle" style="color: var(--warning);"></i> Pilih semua file untuk ' + totalSellers + ' penjual';
                    if (uploadBtn) uploadBtn.disabled = true;
                    if (uploadBtn) uploadBtn.innerHTML = '<i class="fas fa-exclamation-circle"></i> Pilih semua file (' + selectedCount + '/' + totalSellers + ')';
                }
            }
        }

        // Handle form submit
        document.addEventListener('DOMContentLoaded', function() {
            const uploadForm = document.getElementById('uploadAllForm');
            
            if (uploadForm) {
                uploadForm.addEventListener('submit', function(e) {
                    const selectedCount = Object.keys(selectedFiles).length;
                    
                    if (selectedCount !== totalSellers) {
                        e.preventDefault();
                        showToast('Harap pilih semua file untuk ' + totalSellers + ' penjual', 'error');
                        return;
                    }
                    
                    // Show loading
                    showLoading(true);
                });
            }
            
            // Tampilkan pesan error/success dari session
            <?php if (isset($_SESSION['error'])): ?>
                showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            // Initialize upload status
            updateUploadStatus();
        });

        // Auto print struk jika sudah upload bukti
        <?php if ($show_struk && $has_uploaded_proof): ?>
        setTimeout(() => {
            window.print();
        }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>