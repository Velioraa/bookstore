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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'invoice_number' => '', 'transaction_id' => '', 'redirect' => ''];
    $user_id = $_SESSION['user_id'];
    
    switch ($_POST['ajax_action']) {
        case 'update_quantity':
            $cart_id = intval($_POST['cart_id']);
            $quantity = intval($_POST['quantity']);
            
            // Validasi stok
            $sql = "SELECT p.stok FROM keranjang k 
                    JOIN produk p ON k.id_produk = p.id_produk 
                    WHERE k.id_keranjang = ? AND k.id_user = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $cart_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $product = $result->fetch_assoc();
                if ($quantity > $product['stok']) {
                    $response['message'] = "Stok hanya tersedia {$product['stok']} item";
                } else if ($quantity < 1) {
                    $response['message'] = "Jumlah tidak boleh kurang dari 1";
                } else {
                    // Update quantity
                    $sql = "UPDATE keranjang SET qty = ? WHERE id_keranjang = ? AND id_user = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("iii", $quantity, $cart_id, $user_id);
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = "Jumlah berhasil diupdate";
                    } else {
                        $response['message'] = "Gagal mengupdate jumlah";
                    }
                }
            } else {
                $response['message'] = "Item tidak ditemukan";
            }
            break;
            
        case 'remove_item':
            $cart_id = intval($_POST['cart_id']);
            
            $sql = "DELETE FROM keranjang WHERE id_keranjang = ? AND id_user = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $cart_id, $user_id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Item berhasil dihapus";
            } else {
                $response['message'] = "Gagal menghapus item";
            }
            break;
            
        case 'remove_selected':
            $cart_ids = $_POST['cart_ids'] ?? [];
            if (empty($cart_ids)) {
                $response['message'] = "Tidak ada item yang dipilih";
            } else {
                // Konversi ke integer dan buat placeholders
                $ids = array_map('intval', $cart_ids);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                
                $sql = "DELETE FROM keranjang WHERE id_keranjang IN ($placeholders) AND id_user = ?";
                $stmt = $conn->prepare($sql);
                
                // Bind parameters: cart_ids + user_id
                $types = str_repeat('i', count($ids)) . 'i';
                $params = array_merge($ids, [$user_id]);
                $stmt->bind_param($types, ...$params);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = count($ids) . " item berhasil dihapus";
                } else {
                    $response['message'] = "Gagal menghapus item";
                }
            }
            break;
            
        case 'create_transaction':
            $cart_ids = $_POST['cart_ids'] ?? [];
            $payment_method = $_POST['payment_method'] ?? 'transfer';
            $member_phone = $_POST['member_phone'] ?? '';
            $discount = floatval($_POST['discount'] ?? 0);
            $total_amount = floatval($_POST['total_amount'] ?? 0);
            
            if (empty($cart_ids)) {
                $response['message'] = "Tidak ada item yang dipilih";
                echo json_encode($response);
                exit();
            }
            
            // Mulai transaksi database
            $conn->begin_transaction();
            
            try {
                // Ambil data produk dari keranjang
                $ids = array_map('intval', $cart_ids);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                
                $sql = "SELECT k.id_produk, k.qty, p.nama_produk, p.harga_jual, p.stok, p.id_penjual as id_penjual
                        FROM keranjang k 
                        JOIN produk p ON k.id_produk = p.id_produk 
                        WHERE k.id_keranjang IN ($placeholders) AND k.id_user = ?";
                $stmt = $conn->prepare($sql);
                $types = str_repeat('i', count($ids)) . 'i';
                $params = array_merge($ids, [$user_id]);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $cart_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                if (empty($cart_items)) {
                    throw new Exception("Item keranjang tidak ditemukan");
                }
                
                // Validasi stok
                foreach ($cart_items as $item) {
                    if ($item['qty'] > $item['stok']) {
                        throw new Exception("Stok {$item['nama_produk']} tidak cukup. Stok tersedia: {$item['stok']}");
                    }
                }
                
                // Generate invoice number
                $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Cek apakah tabel transaksi punya kolom approve_status
                $check_column_sql = "SHOW COLUMNS FROM transaksi LIKE 'approve_status'";
                $check_result = $conn->query($check_column_sql);
                $has_approve_column = ($check_result->num_rows > 0);
                
                // Simpan transaksi - dengan atau tanpa kolom approve_status
                if ($has_approve_column) {
                    // Jika ada kolom approve_status, set ke 'pending'
                    $sql = "INSERT INTO transaksi (invoice_number, id_user, total_harga, total_bayar, 
                                                  metode_pembayaran, status, approve_status) 
                            VALUES (?, ?, ?, ?, ?, 'pending', 'pending')";
                    
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Prepare statement gagal: " . $conn->error);
                    }
                    
                    $stmt->bind_param("sidds", 
                        $invoice_number, 
                        $user_id, 
                        $total_amount, 
                        $total_amount,
                        $payment_method
                    );
                } else {
                    // Jika tidak ada kolom approve_status
                    $sql = "INSERT INTO transaksi (invoice_number, id_user, total_harga, total_bayar, 
                                                  metode_pembayaran, status) 
                            VALUES (?, ?, ?, ?, ?, 'pending')";
                    
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception("Prepare statement gagal: " . $conn->error);
                    }
                    
                    $stmt->bind_param("sidds", 
                        $invoice_number, 
                        $user_id, 
                        $total_amount, 
                        $total_amount,
                        $payment_method
                    );
                }
                
                if (!$stmt->execute()) {
                    throw new Exception("Gagal menyimpan transaksi: " . $conn->error);
                }
                
                $transaction_id = $conn->insert_id;
                
                // Simpan detail transaksi
                $sql_detail = "INSERT INTO transaksi_detail (id_transaksi, id_produk, nama_produk, qty, harga, subtotal) 
                               VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_detail = $conn->prepare($sql_detail);
                
                if (!$stmt_detail) {
                    throw new Exception("Prepare statement detail gagal: " . $conn->error);
                }
                
                foreach ($cart_items as $item) {
                    $subtotal = $item['harga_jual'] * $item['qty'];
                    $stmt_detail->bind_param("iisidd", 
                        $transaction_id, 
                        $item['id_produk'], 
                        $item['nama_produk'], 
                        $item['qty'], 
                        $item['harga_jual'],
                        $subtotal
                    );
                    if (!$stmt_detail->execute()) {
                        throw new Exception("Gagal menyimpan detail transaksi: " . $conn->error);
                    }
                    
                    // Kurangi stok
                    $sql_update = "UPDATE produk SET stok = stok - ? WHERE id_produk = ?";
                    $stmt_update = $conn->prepare($sql_update);
                    if (!$stmt_update) {
                        throw new Exception("Prepare statement update stok gagal: " . $conn->error);
                    }
                    
                    $stmt_update->bind_param("ii", $item['qty'], $item['id_produk']);
                    if (!$stmt_update->execute()) {
                        throw new Exception("Gagal update stok: " . $conn->error);
                    }
                }
                
                // Hapus dari keranjang
                $sql_delete = "DELETE FROM keranjang WHERE id_keranjang IN ($placeholders) AND id_user = ?";
                $stmt_delete = $conn->prepare($sql_delete);
                if (!$stmt_delete) {
                    throw new Exception("Prepare statement delete keranjang gagal: " . $conn->error);
                }
                
                $stmt_delete->bind_param($types, ...$params);
                if (!$stmt_delete->execute()) {
                    throw new Exception("Gagal menghapus dari keranjang: " . $conn->error);
                }
                
                // Commit transaksi
                $conn->commit();
                
                // ==================== NOTIFIKASI SETELAH TRANSAKSI BERHASIL ====================
                try {
                    // 1. Notifikasi untuk pembeli
                    $notification_title_pembeli = "Transaksi Berhasil";
                    $notification_message_pembeli = "Transaksi dengan invoice $invoice_number berhasil diproses. Silakan upload bukti pembayaran.";
                    
                    $sql_notif = "INSERT INTO notifikasi (id_user, id_order, id_produk, judul, pesan, type, created_at) 
                                  VALUES (?, ?, NULL, ?, ?, 'notifikasi', NOW())";
                    
                    // Notifikasi untuk pembeli
                    $stmt_notif = $conn->prepare($sql_notif);
                    if (!$stmt_notif) {
                        throw new Exception("Prepare statement notifikasi gagal: " . $conn->error);
                    }
                    
                    $stmt_notif->bind_param("iiss", $user_id, $transaction_id, $notification_title_pembeli, $notification_message_pembeli);
                    if (!$stmt_notif->execute()) {
                        error_log("Gagal membuat notifikasi pembeli: " . $conn->error);
                    }
                    
                    // 2. Notifikasi untuk penjual
                    // Kelompokkan item berdasarkan penjual
                    $penjual_items = [];
                    foreach ($cart_items as $item) {
                        $id_penjual = $item['id_penjual'] ?? null;
                        if ($id_penjual && $id_penjual != $user_id) {
                            if (!isset($penjual_items[$id_penjual])) {
                                $penjual_items[$id_penjual] = [];
                            }
                            $penjual_items[$id_penjual][] = $item;
                        }
                    }
                    
                    // Buat notifikasi untuk setiap penjual
                    foreach ($penjual_items as $id_penjual => $items) {
                        $product_names = array_column($items, 'nama_produk');
                        $product_list = implode(", ", array_slice($product_names, 0, 3));
                        if (count($product_names) > 3) {
                            $product_list .= " dan " . (count($product_names) - 3) . " produk lainnya";
                        }
                        
                        $notification_title_penjual = "Pesanan Baru";
                        $notification_message_penjual = "Anda memiliki pesanan baru untuk produk: $product_list. Invoice: $invoice_number";
                        
                        $stmt_notif_penjual = $conn->prepare($sql_notif);
                        if ($stmt_notif_penjual) {
                            $stmt_notif_penjual->bind_param("iiss", $id_penjual, $transaction_id, $notification_title_penjual, $notification_message_penjual);
                            $stmt_notif_penjual->execute();
                        }
                    }
                    
                    // 3. Notifikasi untuk admin (jika ada user dengan role admin)
                    $sql_admin = "SELECT id_user FROM users WHERE role = 'admin'";
                    $result_admin = $conn->query($sql_admin);
                    if ($result_admin && $result_admin->num_rows > 0) {
                        $admin_title = "Transaksi Baru";
                        $admin_message = "Ada transaksi baru dengan invoice $invoice_number dari user ID: $user_id";
                        
                        while ($admin = $result_admin->fetch_assoc()) {
                            $stmt_notif_admin = $conn->prepare($sql_notif);
                            if ($stmt_notif_admin) {
                                $stmt_notif_admin->bind_param("iiss", $admin['id_user'], $transaction_id, $admin_title, $admin_message);
                                $stmt_notif_admin->execute();
                            }
                        }
                    }
                    
                } catch (Exception $e) {
                    // Jangan ganggu transaksi utama jika notifikasi gagal
                    error_log("Notification error (non-critical): " . $e->getMessage());
                }
                // ==================== END NOTIFIKASI ====================
                
                $response['success'] = true;
                $response['message'] = "Transaksi berhasil dibuat! Silakan upload bukti pembayaran.";
                $response['invoice_number'] = $invoice_number;
                $response['transaction_id'] = $transaction_id;
                $response['redirect'] = 'struk.php?id=' . $transaction_id;
                
            } catch (Exception $e) {
                $conn->rollback();
                
                // Log error untuk debugging
                error_log("Transaction Error: " . $e->getMessage());
                error_log("Cart IDs: " . implode(',', $cart_ids ?? []));
                
                $response['message'] = $e->getMessage();
            }
            break;
            
        default:
            $response['message'] = "Aksi tidak valid";
    }
    
    echo json_encode($response);
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
    $username = $user['username'];
    $role = $user['role'];
} else {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Fungsi untuk mendapatkan data keranjang dengan path gambar yang fleksibel
function getCartData($conn, $user_id) {
    $sql = "SELECT 
                k.id_keranjang,
                k.id_produk,
                k.qty as quantity,
                p.nama_produk,
                p.harga_jual,
                p.gambar, 
                p.stok,
                u.id_user as id_penjual,
                u.username as nama_toko
            FROM keranjang k
            LEFT JOIN produk p ON k.id_produk = p.id_produk
            LEFT JOIN users u ON p.id_penjual = u.id_user
            WHERE k.id_user = ? AND k.status = 'active'
            ORDER BY k.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($item = $result->fetch_assoc()) {
        // Tentukan path gambar berdasarkan struktur folder
        if (!empty($item['gambar'])) {
            // Cek apakah gambar ada di folder uploads atau asset langsung
            $uploads_path = '../asset/uploads/' . $item['gambar'];
            $asset_path = '../asset/' . $item['gambar'];
            
            if (file_exists($uploads_path)) {
                $item['gambar_path'] = $uploads_path;
            } else if (file_exists($asset_path)) {
                $item['gambar_path'] = $asset_path;
            } else {
                // Jika file tidak ditemukan di kedua lokasi, gunakan gambar default
                $item['gambar_path'] = '../asset/wesley.png';
            }
        } else {
            $item['gambar_path'] = '../asset/wesley.png';
        }
        
        $items[] = $item;
    }
    
    return $items;
}

// Ambil data keranjang
$cart_items = getCartData($conn, $user_id);
$total_items = 0;
$total_price = 0;
$shops = [];

foreach ($cart_items as $item) {
    $total_items += $item['quantity'];
    $total_price += $item['harga_jual'] * $item['quantity'];
    
    // Group by shop
    $shop_id = $item['id_penjual'];
    if (!isset($shops[$shop_id])) {
        $shops[$shop_id] = [
            'nama_toko' => $item['nama_toko'] ?? 'Toko Umum',
            'items' => [],
            'subtotal' => 0,
            'selected' => true
        ];
    }
    $shops[$shop_id]['items'][] = $item;
    $shops[$shop_id]['subtotal'] += $item['harga_jual'] * $item['quantity'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keranjang & Checkout - Wesley Bookstore</title>
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
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .cart-header {
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

        .cart-stats {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--light-blue);
            padding: 10px 20px;
            border-radius: 25px;
        }

        .cart-count {
            background: var(--secondary);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Cart Container */
        .cart-container {
            display: flex;
            flex: 1;
            gap: 25px;
            padding: 25px;
            overflow: hidden;
            max-width: 100%;
        }

        /* Cart Items Section */
        .cart-items-section {
            flex: 1;
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .section-header {
            padding: 25px;
            border-bottom: 1px solid var(--border);
            background: var(--light-blue);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select-all-container {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
        }

        .select-all-container label {
            cursor: pointer;
            font-size: 1rem;
            color: var(--dark);
            font-weight: 500;
            user-select: none;
        }

        /* Custom Checkbox */
        .custom-checkbox {
            position: relative;
            width: 20px;
            height: 20px;
        }

        .custom-checkbox input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 2;
        }

        .custom-checkbox .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray);
            border-radius: 4px;
            background: white;
            transition: all 0.3s ease;
        }

        .custom-checkbox .checkmark:after {
            content: "";
            position: absolute;
            display: none;
            left: 6px;
            top: 2px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .custom-checkbox input:checked ~ .checkmark {
            background: var(--secondary);
            border-color: var(--secondary);
        }

        .custom-checkbox input:checked ~ .checkmark:after {
            display: block;
        }

        .custom-checkbox input:indeterminate ~ .checkmark {
            background: var(--secondary);
            border-color: var(--secondary);
        }

        .custom-checkbox input:indeterminate ~ .checkmark:after {
            display: block;
            left: 3px;
            top: 8px;
            width: 10px;
            height: 2px;
            border: solid white;
            border-width: 0 0 2px 0;
            transform: none;
        }

        /* Shop Section */
        .shop-section {
            border-bottom: 1px solid var(--border);
        }

        .shop-header {
            padding: 20px 25px;
            background: #f9f9f9;
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid var(--border);
        }

        .shop-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .shop-icon {
            color: var(--secondary);
            font-size: 1.2rem;
        }

        .shop-name {
            font-weight: 600;
            color: var(--primary);
            font-size: 1rem;
        }

        /* Items Container */
        .items-container {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        /* Cart Item */
        .cart-item {
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            background: #f9f9f9;
        }

        .item-details-container {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .item-image {
            width: 100px;
            height: 100px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--border);
            background: white;
            padding: 5px;
        }

        .item-details {
            flex: 1;
            min-width: 0;
        }

        .item-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .item-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--danger);
            margin-bottom: 12px;
        }

        .item-stock {
            font-size: 0.95rem;
            color: var(--gray);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stock-available {
            color: var(--success);
        }

        .stock-low {
            color: var(--warning);
        }

        .stock-out {
            color: var(--danger);
        }

        .item-actions {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-top: 15px;
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--light);
            border-radius: 25px;
            padding: 8px;
        }

        .quantity-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: white;
            color: var(--dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .quantity-btn:hover:not(.disabled) {
            background: var(--secondary);
            color: white;
            border-color: var(--secondary);
        }

        .quantity-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .quantity-input {
            width: 50px;
            text-align: center;
            border: none;
            background: transparent;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
            outline: none;
            -moz-appearance: textfield;
        }

        .quantity-input::-webkit-outer-spin-button,
        .quantity-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .remove-btn {
            color: var(--danger);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .remove-btn:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        .item-total {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.3rem;
            min-width: 150px;
            text-align: right;
        }

        /* Cart Summary Section */
        .cart-summary-section {
            width: 450px;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            flex-shrink: 0;
        }

        .summary-header {
            padding: 25px;
            border-bottom: 1px solid var(--border);
            background: var(--light-blue);
        }

        .summary-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
        }

        .summary-content {
            padding: 25px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 1.05rem;
        }

        .summary-label {
            color: var(--gray);
        }

        .summary-value {
            font-weight: 600;
            color: var(--primary);
        }

        .summary-row.total {
            padding-top: 15px;
            border-top: 2px solid var(--border);
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--danger);
            margin-top: 10px;
            margin-bottom: 20px;
        }

        /* Checkout Button */
        .checkout-btn {
            width: 100%;
            padding: 20px;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            margin-top: 25px;
        }

        .checkout-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(74, 144, 226, 0.4);
        }

        .checkout-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            background: var(--gray);
        }

        /* Invoice Section (Hidden by default) */
        .invoice-section {
            display: none;
            flex: 1;
            flex-direction: column;
            gap: 25px;
            overflow: hidden;
            padding: 25px;
        }

        .invoice-container {
            display: flex;
            gap: 25px;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        /* Invoice Card */
        .invoice-card {
            flex: 1;
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .invoice-header {
            padding: 20px 25px;
            background: var(--light-blue);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
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
            padding: 0;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        /* Pembayaran Summary - Container Scrollable */
        .payment-summary-container {
            flex: 1;
            overflow-y: auto;
            padding: 25px;
            min-height: 0;
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

        .payment-section {
            padding: 25px;
            border-top: 2px solid var(--border);
            flex-shrink: 0;
        }

        .payment-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }

        .payment-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 25px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 1rem;
        }

        .payment-label {
            color: var(--gray);
        }

        .payment-value {
            font-weight: 600;
            color: var(--primary);
        }

        .payment-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--danger);
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid var(--border);
        }

        /* Place Order Button */
        .place-order-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--success) 0%, #28a745 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            margin-top: 25px;
        }

        .place-order-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .place-order-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            background: var(--gray);
        }

        /* Bulk Actions */
        .bulk-actions {
            padding: 15px 25px;
            background: var(--light);
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .selected-count {
            font-weight: 600;
            color: var(--primary);
        }

        .bulk-buttons {
            display: flex;
            gap: 10px;
        }

        .bulk-btn {
            padding: 8px 15px;
            border: 1px solid var(--border);
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .bulk-btn.remove {
            color: var(--danger);
            border-color: var(--danger);
        }

        .bulk-btn.remove:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        /* Empty Cart */
        .empty-cart {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px;
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .empty-cart i {
            font-size: 6rem;
            color: var(--sky-blue);
            margin-bottom: 30px;
            opacity: 0.8;
        }

        .empty-cart h3 {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 15px;
            font-weight: 700;
        }

        .empty-cart p {
            color: var(--gray);
            margin-bottom: 35px;
            max-width: 500px;
            font-size: 1.1rem;
            line-height: 1.6;
        }

        .shop-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 15px 35px;
            background: var(--secondary);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .shop-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(74, 144, 226, 0.4);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .cart-container, .invoice-container {
                flex-direction: column;
                gap: 20px;
            }
            
            .cart-summary-section {
                width: 100%;
            }
            
            .cart-header {
                padding: 15px;
            }
            
            .header-title h1 {
                font-size: 1.5rem;
            }
            
            .invoice-card {
                min-height: 500px;
            }
        }

        @media (max-width: 768px) {
            .cart-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
                padding: 20px;
            }
            
            .item-details-container {
                width: 100%;
            }
            
            .item-image {
                width: 80px;
                height: 80px;
            }
            
            .item-total {
                text-align: left;
                min-width: auto;
                width: 100%;
                padding-top: 15px;
                border-top: 1px solid var(--border);
            }
            
            .item-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .quantity-control {
                order: 1;
            }
            
            .remove-btn {
                order: 2;
            }
            
            .cart-header {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .cart-stats {
                order: 3;
                width: 100%;
                justify-content: center;
            }
            
            .bulk-actions {
                flex-direction: column;
                text-align: center;
            }
            
            .invoice-table {
                font-size: 0.85rem;
            }
            
            .invoice-table th,
            .invoice-table td {
                padding: 10px;
            }
            
            .invoice-table-container {
                max-height: 300px;
            }
        }

        @media (max-width: 576px) {
            .cart-container, .invoice-section {
                padding: 15px;
            }
            
            .cart-item {
                padding: 15px;
            }
            
            .section-header {
                padding: 15px;
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .shop-header {
                padding: 15px;
                flex-wrap: wrap;
            }
            
            .empty-cart {
                padding: 30px;
            }
            
            .empty-cart i {
                font-size: 4rem;
            }
            
            .empty-cart h3 {
                font-size: 1.5rem;
            }
            
            .payment-summary-container {
                padding: 15px;
            }
            
            .payment-section {
                padding: 15px;
            }
        }

        /* Scrollbar Styling */
        .items-container::-webkit-scrollbar,
        .invoice-content::-webkit-scrollbar,
        .invoice-table-container::-webkit-scrollbar,
        .payment-summary-container::-webkit-scrollbar {
            width: 8px;
        }

        .items-container::-webkit-scrollbar-track,
        .invoice-content::-webkit-scrollbar-track,
        .invoice-table-container::-webkit-scrollbar-track,
        .payment-summary-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .items-container::-webkit-scrollbar-thumb,
        .invoice-content::-webkit-scrollbar-thumb,
        .invoice-table-container::-webkit-scrollbar-thumb,
        .payment-summary-container::-webkit-scrollbar-thumb {
            background: var(--secondary);
            border-radius: 4px;
        }

        .items-container::-webkit-scrollbar-thumb:hover,
        .invoice-content::-webkit-scrollbar-thumb:hover,
        .invoice-table-container::-webkit-scrollbar-thumb:hover,
        .payment-summary-container::-webkit-scrollbar-thumb:hover {
            background: #2c7be5;
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .cart-item, .summary-card, .invoice-card {
            animation: fadeIn 0.3s ease;
        }
        
        .fade-out {
            animation: fadeOut 0.3s ease forwards;
        }
        
        @keyframes fadeOut {
            from { 
                opacity: 1; 
                transform: translateY(0); 
                height: auto;
                padding: 25px;
                margin: 0;
                border-bottom: 1px solid var(--border);
            }
            to { 
                opacity: 0; 
                transform: translateY(-10px); 
                height: 0; 
                padding: 0; 
                margin: 0; 
                border: none;
            }
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
        
        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9998;
            display: none;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow: auto;
            animation: modalIn 0.3s ease;
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
    </style>
</head>
<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast notification -->
    <div class="toast" id="toast"></div>
    
    <!-- Header -->
    <div class="cart-header">
        <button class="back-button" id="backButton">
            <i class="fas fa-arrow-left"></i>
        </button>
        
        <div class="header-title">
            <i class="fas fa-shopping-cart"></i>
            <h1 id="pageTitle">Keranjang Belanja</h1>
        </div>
        
        <div class="cart-stats">
            <div class="cart-count" id="cartCount"><?php echo $total_items; ?></div>
            <span>Total Item</span>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <?php if (empty($cart_items)): ?>
            <!-- Empty Cart State -->
            <div class="empty-cart">
                <i class="fas fa-shopping-cart"></i>
                <h3>Keranjang Belanja Kosong</h3>
                <p>Belum ada produk yang ditambahkan ke keranjang. Ayo mulai belanja dan temukan buku favoritmu!</p>
                <a href="dashboard.php" class="shop-btn">
                    <i class="fas fa-store"></i>
                    Mulai Belanja
                </a>
            </div>
        <?php else: ?>
            <!-- Cart Content -->
            <div class="cart-container" id="cartSection">
                <!-- Cart Items -->
                <div class="cart-items-section">
                    <div class="section-header">
                        <div class="select-all-container">
                            <div class="custom-checkbox">
                                <input type="checkbox" id="selectAll" checked>
                                <span class="checkmark"></span>
                            </div>
                            <label for="selectAll">Pilih Semua</label>
                        </div>
                        <div class="section-title">
                            <i class="fas fa-store"></i>
                            Daftar Produk
                        </div>
                    </div>

                    <div class="items-container">
                        <?php foreach ($shops as $shop_id => $shop): ?>
                            <div class="shop-section" data-shop="<?php echo $shop_id; ?>">
                                <div class="shop-header">
                                    <div class="shop-info">
                                        <i class="fas fa-store shop-icon"></i>
                                        <span class="shop-name"><?php echo htmlspecialchars($shop['nama_toko']); ?></span>
                                    </div>
                                </div>
                                
                                <?php foreach ($shop['items'] as $item): ?>
                                    <div class="cart-item" data-item="<?php echo $item['id_keranjang']; ?>"
                                         data-price="<?php echo $item['harga_jual']; ?>"
                                         data-stock="<?php echo $item['stok']; ?>">
                                        <div class="custom-checkbox">
                                            <input type="checkbox" 
                                                   class="item-select" 
                                                   data-shop="<?php echo $shop_id; ?>"
                                                   data-item="<?php echo $item['id_keranjang']; ?>"
                                                   data-price="<?php echo $item['harga_jual']; ?>"
                                                   data-name="<?php echo htmlspecialchars($item['nama_produk']); ?>"
                                                   checked>
                                            <span class="checkmark"></span>
                                        </div>
                                        
                                        <div class="item-details-container">
                                            <img src="<?php echo $item['gambar_path']; ?>" 
                                                 alt="<?php echo htmlspecialchars($item['nama_produk']); ?>" 
                                                 class="item-image"
                                                 onerror="this.src='../asset/wesley.png'">
                                            
                                            <div class="item-details">
                                                <h3 class="item-name"><?php echo htmlspecialchars($item['nama_produk']); ?></h3>
                                                <div class="item-price">Rp <?php echo number_format($item['harga_jual'], 0, ',', '.'); ?></div>
                                                
                                                <?php if ($item['stok'] > 10): ?>
                                                    <div class="item-stock stock-available">
                                                        <i class="fas fa-check-circle"></i>
                                                        Stok: <?php echo $item['stok']; ?> tersedia
                                                    </div>
                                                <?php elseif ($item['stok'] > 0 && $item['stok'] <= 10): ?>
                                                    <div class="item-stock stock-low">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                        Stok: <?php echo $item['stok']; ?> tersedia (hampir habis)
                                                    </div>
                                                <?php else: ?>
                                                    <div class="item-stock stock-out">
                                                        <i class="fas fa-times-circle"></i>
                                                        Stok habis
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="item-actions">
                                                    <div class="quantity-control">
                                                        <button class="quantity-btn minus <?php echo ($item['quantity'] <= 1) ? 'disabled' : ''; ?>" 
                                                                data-cart-id="<?php echo $item['id_keranjang']; ?>"
                                                                onclick="updateQuantity(<?php echo $item['id_keranjang']; ?>, -1)">
                                                            <i class="fas fa-minus"></i>
                                                        </button>
                                                        <input type="number" 
                                                               class="quantity-input" 
                                                               id="qty-<?php echo $item['id_keranjang']; ?>"
                                                               value="<?php echo $item['quantity']; ?>" 
                                                               min="1" 
                                                               max="<?php echo $item['stok']; ?>"
                                                               onchange="updateQuantityInput(<?php echo $item['id_keranjang']; ?>, this.value)"
                                                               onblur="validateQuantity(<?php echo $item['id_keranjang']; ?>, this)">
                                                        <button class="quantity-btn plus <?php echo ($item['quantity'] >= $item['stok']) ? 'disabled' : ''; ?>" 
                                                                data-cart-id="<?php echo $item['id_keranjang']; ?>"
                                                                onclick="updateQuantity(<?php echo $item['id_keranjang']; ?>, 1)">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </div>
                                                    
                                                    <button class="remove-btn" onclick="removeItem(<?php echo $item['id_keranjang']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                        Hapus
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="item-total" id="total-<?php echo $item['id_keranjang']; ?>">
                                            Rp <?php echo number_format($item['harga_jual'] * $item['quantity'], 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Bulk Actions Section -->
                    <div class="bulk-actions">
                        <div class="selected-count">
                            <span id="selectedCount"><?php echo count($cart_items); ?></span> item terpilih
                        </div>
                        <div class="bulk-buttons">
                            <button class="bulk-btn remove" onclick="removeSelectedItems()">
                                <i class="fas fa-trash"></i>
                                Hapus Terpilih
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Cart Summary -->
                <div class="cart-summary-section">
                    <div class="summary-card">
                        <div class="summary-header">
                            <h3 class="summary-title">Ringkasan Belanja</h3>
                        </div>
                        <div class="summary-content">
                            <div class="summary-row">
                                <span class="summary-label">Total Harga (<span id="summaryItemCount"><?php echo $total_items; ?></span> barang)</span>
                                <span class="summary-value" id="subtotal">Rp <?php echo number_format($total_price, 0, ',', '.'); ?></span>
                            </div>
                            <div class="summary-row total">
                                <span class="summary-label">Total Pembayaran</span>
                                <span class="summary-value" id="total">Rp <?php echo number_format($total_price, 0, ',', '.'); ?></span>
                            </div>
                            
                            <button class="checkout-btn" id="checkoutBtn">
                                <i class="fas fa-shopping-bag"></i>
                                Lanjut ke Pembayaran (<?php echo $total_items; ?>)
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Section (Hidden by default) -->
            <div class="invoice-section" id="invoiceSection">
                <div class="invoice-container">
                    <!-- Invoice Card -->
                    <div class="invoice-card">
                        <div class="invoice-header">
                            <h3 class="invoice-title">
                                <i class="fas fa-receipt"></i>
                                Invoice Pembayaran
                            </h3>
                        </div>
                        
                        <div class="invoice-content" id="invoiceContent">
                            <!-- Content akan di-generate oleh JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Data cart
        let cartItems = <?php echo json_encode($cart_items); ?>;
        let selectedItems = new Set(<?php echo json_encode(array_column($cart_items, 'id_keranjang')); ?>);
        let selectedPaymentMethod = '';

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateCartSummary();
            updateSelectedCount();
            updateSelectAllCheckbox();
            updateCartCount();
            
            // Add event listeners to all checkboxes
            setupCheckboxListeners();
            
            // Setup checkout button
            const checkoutBtn = document.getElementById('checkoutBtn');
            if (checkoutBtn) {
                checkoutBtn.onclick = showInvoice;
            }
            
            // Setup back button
            const backButton = document.getElementById('backButton');
            if (backButton) {
                backButton.onclick = function() {
                    if (document.getElementById('invoiceSection').style.display === 'flex') {
                        backToCart();
                    } else {
                        window.location.href = 'dashboard.php';
                    }
                };
            }
        });

        // Setup checkbox event listeners
        function setupCheckboxListeners() {
            // Select All checkbox
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', handleSelectAllChange);
            }
            
            // Item checkboxes
            document.querySelectorAll('.item-select').forEach(checkbox => {
                checkbox.addEventListener('change', handleItemCheckboxChange);
            });
        }

        // Handle Select All checkbox change
        function handleSelectAllChange(e) {
            const isChecked = e.target.checked;
            const allItemCheckboxes = document.querySelectorAll('.item-select');
            
            // Update all item checkboxes
            allItemCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
                
                const itemId = parseInt(checkbox.dataset.item);
                if (isChecked) {
                    selectedItems.add(itemId);
                } else {
                    selectedItems.delete(itemId);
                }
            });
            
            // Update checkboxes state
            updateSelectAllCheckbox();
            
            // Update UI
            updateSelectedCount();
            updateCartSummary();
        }

        // Handle individual item checkbox change
        function handleItemCheckboxChange(e) {
            const checkbox = e.target;
            const itemId = parseInt(checkbox.dataset.item);
            
            if (checkbox.checked) {
                selectedItems.add(itemId);
            } else {
                selectedItems.delete(itemId);
            }
            
            // Update Select All checkbox
            updateSelectAllCheckbox();
            
            // Update UI
            updateSelectedCount();
            updateCartSummary();
        }

        // Update Select All checkbox state
        function updateSelectAllCheckbox() {
            const selectAllCheckbox = document.getElementById('selectAll');
            if (!selectAllCheckbox) return;
            
            const allItemCheckboxes = document.querySelectorAll('.item-select');
            const totalItems = allItemCheckboxes.length;
            const checkedItems = Array.from(allItemCheckboxes).filter(cb => cb.checked).length;
            
            if (checkedItems === 0) {
                // No items checked
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedItems === totalItems) {
                // All items checked
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                // Some items checked
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }

        // Update selected items count display
        function updateSelectedCount() {
            const selectedCountElement = document.getElementById('selectedCount');
            if (selectedCountElement) {
                selectedCountElement.textContent = selectedItems.size;
            }
        }

        // Update cart count
        function updateCartCount() {
            const cartCountElement = document.getElementById('cartCount');
            if (cartCountElement) {
                let totalItems = 0;
                cartItems.forEach(item => {
                    totalItems += item.quantity;
                });
                cartCountElement.textContent = totalItems;
            }
        }

        // Update cart summary
        function updateCartSummary() {
            let subtotal = 0;
            let itemCount = 0;
            
            // Calculate subtotal and item count from selected items
            selectedItems.forEach(itemId => {
                const item = cartItems.find(item => item.id_keranjang == itemId);
                if (item) {
                    subtotal += item.harga_jual * item.quantity;
                    itemCount += item.quantity;
                }
            });
            
            const total = subtotal;
            
            // Update display
            const summaryItemCount = document.getElementById('summaryItemCount');
            const subtotalElement = document.getElementById('subtotal');
            const totalElement = document.getElementById('total');
            
            if (summaryItemCount) summaryItemCount.textContent = itemCount;
            if (subtotalElement) subtotalElement.textContent = formatCurrency(subtotal);
            if (totalElement) totalElement.textContent = formatCurrency(total);
            
            // Update checkout button
            const checkoutBtn = document.getElementById('checkoutBtn');
            if (checkoutBtn) {
                if (itemCount > 0) {
                    checkoutBtn.innerHTML = `<i class="fas fa-shopping-bag"></i> Lanjut ke Pembayaran (${itemCount})`;
                    checkoutBtn.disabled = false;
                } else {
                    checkoutBtn.innerHTML = `<i class="fas fa-shopping-bag"></i> Lanjut ke Pembayaran`;
                    checkoutBtn.disabled = true;
                }
            }
        }

        // Format currency
        function formatCurrency(amount) {
            if (amount === 0) return 'Rp 0';
            return 'Rp ' + amount.toLocaleString('id-ID');
        }

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

        // Show invoice section (move to payment)
        function showInvoice() {
            if (selectedItems.size === 0) {
                showToast('Pilih minimal 1 item untuk checkout', 'error');
                return;
            }
            
            // Get selected items
            const selectedCartIds = Array.from(selectedItems);
            const selectedItemsData = cartItems.filter(item => selectedCartIds.includes(item.id_keranjang));
            
            // Calculate total
            let subtotal = 0;
            selectedItemsData.forEach(item => {
                subtotal += item.harga_jual * item.quantity;
            });
            
            const total = subtotal;
            
            // Generate invoice HTML
            const invoiceContent = document.getElementById('invoiceContent');
            invoiceContent.innerHTML = generateInvoiceHTML(selectedItemsData, total);
            
            // Switch to invoice section
            document.getElementById('cartSection').style.display = 'none';
            document.getElementById('invoiceSection').style.display = 'flex';
            document.getElementById('pageTitle').textContent = 'Pembayaran';
            
            // Setup scroll setelah konten di-render
            setTimeout(setupInvoiceScroll, 100);
        }

        // Setup scroll behavior untuk invoice
        function setupInvoiceScroll() {
            const invoiceContent = document.getElementById('invoiceContent');
            
            if (invoiceContent) {
                // Reset scroll position saat invoice ditampilkan
                invoiceContent.scrollTop = 0;
            }
        }

        // Back to cart
        function backToCart() {
            document.getElementById('cartSection').style.display = 'flex';
            document.getElementById('invoiceSection').style.display = 'none';
            document.getElementById('pageTitle').textContent = 'Keranjang Belanja';
        }

        // Generate invoice HTML yang bisa discroll
        function generateInvoiceHTML(items, total) {
            let html = `
                <div class="payment-summary-container">
                    <div style="margin-bottom: 25px;">
                        <h4 style="font-size: 1.1rem; color: var(--primary); margin-bottom: 15px; font-weight: 600;">
                            <i class="fas fa-shopping-cart"></i> Daftar Produk (${items.length} item)
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
            `;
            
            items.forEach((item, index) => {
                const subtotal = item.harga_jual * item.quantity;
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${item.nama_produk}</td>
                        <td>${item.quantity}</td>
                        <td>${formatCurrency(item.harga_jual)}</td>
                        <td>${formatCurrency(subtotal)}</td>
                    </tr>
                `;
            });
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Tambahkan section untuk pilihan metode pembayaran (dropdown) -->
                    <div class="payment-method-section" style="padding: 25px; border-bottom: 1px solid var(--border);">
                        <h4 class="payment-title" style="margin-bottom: 20px;">
                            <i class="fas fa-credit-card"></i> Pilih Metode Pembayaran
                        </h4>
                        
                        <div class="form-group">
                            <select id="paymentMethodSelect" class="form-control" onchange="updatePaymentMethod(this.value)" style="padding: 12px 15px;">
                                <option value="">Pilih metode pembayaran</option>
                                <option value="transfer">Transfer Bank</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="payment-section">
                        <h4 class="payment-title">
                            <i class="fas fa-credit-card"></i> Ringkasan Pembayaran
                        </h4>
                        
                        <div class="payment-summary">
                            <div class="payment-row">
                                <span class="payment-label">Total Item:</span>
                                <span class="payment-value">${items.length} item</span>
                            </div>
                            
                            <div class="payment-row">
                                <span class="payment-label">Total Harga:</span>
                                <span class="payment-value" id="invoiceSubtotal">${formatCurrency(total)}</span>
                            </div>
                            
                            <div class="payment-row total">
                                <span class="payment-label">TOTAL BAYAR:</span>
                                <span class="payment-value" id="finalTotal">${formatCurrency(total)}</span>
                            </div>
                        </div>
                        
                        <p style="color: var(--gray); font-size: 0.9rem; margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                            <i class="fas fa-info-circle"></i> 
                            Pilih metode pembayaran dan klik "Chekout" untuk melanjutkan
                        </p>
                        
                        <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                            <strong><i class="fas fa-clock"></i> Status:</strong> Setelah transaksi dibuat, Anda akan diarahkan ke halaman upload bukti pembayaran.
                        </div>
                        
                        <button class="place-order-btn" onclick="createTransaction()" style="margin-top: 25px;" id="placeOrderBtn" disabled>
                            <i class="fas fa-check-circle"></i>
                            Chekout
                        </button>
                    </div>
                </div>
            `;
            
            return html;
        }

        // Update payment method
        function updatePaymentMethod(method) {
            selectedPaymentMethod = method;
            const placeOrderBtn = document.getElementById('placeOrderBtn');
            const paymentDetails = document.getElementById('paymentMethodDetails');
            
            if (method) {
                placeOrderBtn.disabled = false;
                paymentDetails.style.display = 'block';
                
                if (method === 'transfer') {
                    document.getElementById('transferDetails').style.display = 'block';
                }
            } else {
                placeOrderBtn.disabled = true;
                paymentDetails.style.display = 'none';
            }
        }

        // Create transaction
        async function createTransaction() {
            if (selectedItems.size === 0) {
                showToast('Tidak ada item yang dipilih', 'error');
                return;
            }
            
            if (!selectedPaymentMethod) {
                showToast('Pilih metode pembayaran terlebih dahulu', 'error');
                return;
            }
            
            const selectedCartIds = Array.from(selectedItems);
            const selectedItemsData = cartItems.filter(item => selectedCartIds.includes(item.id_keranjang));
            
            // Calculate total
            let total = 0;
            selectedItemsData.forEach(item => {
                total += item.harga_jual * item.quantity;
            });
            
            // Show loading
            showLoading(true);
            
            try {
                console.log('Creating transaction...');
                console.log('Payment method:', selectedPaymentMethod);
                console.log('Selected items:', selectedCartIds);
                console.log('Total amount:', total);
                
                // Create transaction via AJAX
                const formData = new FormData();
                formData.append('ajax_action', 'create_transaction');
                
                // Pastikan cart_ids dikirim sebagai array
                selectedCartIds.forEach(id => {
                    formData.append('cart_ids[]', id);
                });
                
                formData.append('payment_method', selectedPaymentMethod);
                formData.append('discount', 0);
                formData.append('total_amount', total);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                });
                
                console.log('Response status:', response.status);
                
                // Cek jika response bukan JSON
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const text = await response.text();
                    console.error('Response is not JSON:', text.substring(0, 500));
                    throw new Error('Server response is not valid JSON');
                }
                
                const data = await response.json();
                console.log('Response data:', data);
                
                if (data.success) {
                    // Update cart items lokal
                    selectedCartIds.forEach(cartId => {
                        const itemIndex = cartItems.findIndex(item => item.id_keranjang == cartId);
                        if (itemIndex !== -1) {
                            cartItems.splice(itemIndex, 1);
                        }
                        
                        // Remove from DOM
                        const itemElement = document.querySelector(`.cart-item[data-item="${cartId}"]`);
                        if (itemElement) {
                            itemElement.classList.add('fade-out');
                            setTimeout(() => {
                                if (itemElement.parentNode) {
                                    itemElement.remove();
                                    
                                    // Check if shop section is empty
                                    const shopSection = itemElement.closest('.shop-section');
                                    if (shopSection) {
                                        const itemsInShop = shopSection.querySelectorAll('.cart-item');
                                        if (itemsInShop.length === 0 && shopSection.parentNode) {
                                            shopSection.remove();
                                        }
                                    }
                                }
                            }, 300);
                        }
                    });
                    
                    // Clear selected items
                    selectedItems.clear();
                    
                    // Update UI
                    updateSelectAllCheckbox();
                    updateSelectedCount();
                    updateCartSummary();
                    updateCartCount();
                    
                    showToast('Transaksi berhasil dibuat! Mengarahkan ke halaman upload bukti...', 'success');
                    
                    // Redirect ke halaman upload bukti setelah 1.5 detik
                    setTimeout(() => {
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        } else if (data.transaction_id) {
                            window.location.href = 'struk.php?id=' + data.transaction_id;
                        } else {
                            window.location.href = 'dashboard.php';
                        }
                    }, 1500);
                    
                } else {
                    // Tampilkan pesan error dari server
                    const errorMsg = data.message || 'Gagal membuat transaksi';
                    showToast(errorMsg, 'error');
                    console.error('Transaction creation failed:', errorMsg);
                }
            } catch (error) {
                console.error('Error details:', error);
                console.error('Error name:', error.name);
                console.error('Error message:', error.message);
                
                // Tampilkan pesan error yang lebih spesifik
                let errorMessage = 'Terjadi kesalahan. Silakan coba lagi.';
                if (error.message.includes('JSON')) {
                    errorMessage = 'Server merespon dengan format yang tidak valid.';
                } else if (error.message.includes('network')) {
                    errorMessage = 'Koneksi jaringan bermasalah. Cek koneksi internet Anda.';
                }
                
                showToast(errorMessage, 'error');
            } finally {
                showLoading(false);
            }
        }

        // Quantity update functions
        async function updateQuantity(cartId, change) {
            const item = cartItems.find(item => item.id_keranjang == cartId);
            if (!item) return;
            
            const newQuantity = item.quantity + change;
            
            // Validate min and max
            if (newQuantity < 1) {
                showToast('Jumlah tidak boleh kurang dari 1', 'error');
                return;
            }
            
            if (newQuantity > item.stok) {
                showToast(`Stok hanya tersedia ${item.stok} item`, 'error');
                return;
            }
            
            // Show loading
            showLoading(true);
            
            try {
                // Update database via AJAX
                const formData = new FormData();
                formData.append('ajax_action', 'update_quantity');
                formData.append('cart_id', cartId);
                formData.append('quantity', newQuantity);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update local data
                    item.quantity = newQuantity;
                    
                    // Update UI elements
                    updateItemUI(cartId, newQuantity);
                    
                    // Update summary if item is selected
                    if (selectedItems.has(parseInt(cartId))) {
                        updateCartSummary();
                    }
                    
                    // Update cart count
                    updateCartCount();
                    
                    showToast('Jumlah berhasil diupdate', 'success');
                } else {
                    showToast(data.message || 'Gagal mengupdate jumlah', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
            } finally {
                showLoading(false);
            }
        }

        // Validate quantity input
        function validateQuantity(cartId, input) {
            const quantity = parseInt(input.value);
            const item = cartItems.find(item => item.id_keranjang == cartId);
            
            if (isNaN(quantity) || quantity < 1) {
                input.value = item.quantity;
                showToast('Jumlah tidak valid', 'error');
                return;
            }
            
            if (quantity > item.stok) {
                input.value = item.quantity;
                showToast(`Stok hanya tersedia ${item.stok} item`, 'error');
                return;
            }
        }

        // Update quantity from input
        async function updateQuantityInput(cartId, value) {
            const quantity = parseInt(value);
            
            // Validate input
            if (isNaN(quantity) || quantity < 1) {
                // Reset to current quantity
                const item = cartItems.find(item => item.id_keranjang == cartId);
                if (item) {
                    const input = document.getElementById(`qty-${cartId}`);
                    input.value = item.quantity;
                }
                return;
            }
            
            const item = cartItems.find(item => item.id_keranjang == cartId);
            if (!item) return;
            
            // Validate max stock
            if (quantity > item.stok) {
                showToast(`Stok hanya tersedia ${item.stok} item`, 'error');
                const input = document.getElementById(`qty-${cartId}`);
                input.value = item.quantity;
                return;
            }
            
            // Show loading
            showLoading(true);
            
            try {
                // Update database via AJAX
                const formData = new FormData();
                formData.append('ajax_action', 'update_quantity');
                formData.append('cart_id', cartId);
                formData.append('quantity', quantity);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update local data
                    item.quantity = quantity;
                    
                    // Update UI elements
                    updateItemUI(cartId, quantity);
                    
                    // Update summary if item is selected
                    if (selectedItems.has(parseInt(cartId))) {
                        updateCartSummary();
                    }
                    
                    // Update cart count
                    updateCartCount();
                    
                    showToast('Jumlah berhasil diupdate', 'success');
                } else {
                    showToast(data.message || 'Gagal mengupdate jumlah', 'error');
                    // Revert input value
                    const input = document.getElementById(`qty-${cartId}`);
                    input.value = item.quantity;
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
                // Revert input value
                const input = document.getElementById(`qty-${cartId}`);
                input.value = item.quantity;
            } finally {
                showLoading(false);
            }
        }

        // Update item UI after quantity change
        function updateItemUI(cartId, newQuantity) {
            const item = cartItems.find(item => item.id_keranjang == cartId);
            if (!item) return;
            
            // Update input value
            const input = document.getElementById(`qty-${cartId}`);
            if (input) input.value = newQuantity;
            
            // Update plus/minus buttons state
            const minusBtn = document.querySelector(`.cart-item[data-item="${cartId}"] .minus`);
            const plusBtn = document.querySelector(`.cart-item[data-item="${cartId}"] .plus`);
            
            if (minusBtn) {
                minusBtn.disabled = newQuantity <= 1;
                if (newQuantity <= 1) {
                    minusBtn.classList.add('disabled');
                } else {
                    minusBtn.classList.remove('disabled');
                }
            }
            
            if (plusBtn) {
                plusBtn.disabled = newQuantity >= item.stok;
                if (newQuantity >= item.stok) {
                    plusBtn.classList.add('disabled');
                } else {
                    plusBtn.classList.remove('disabled');
                }
            }
            
            // Update total price for this item
            const totalElement = document.getElementById(`total-${cartId}`);
            if (totalElement) {
                const totalPrice = item.harga_jual * newQuantity;
                totalElement.textContent = formatCurrency(totalPrice);
            }
        }

        // Remove single item
        async function removeItem(cartId) {
            if (!confirm('Hapus item dari keranjang?')) return;
            
            // Start fade out animation
            const itemElement = document.querySelector(`.cart-item[data-item="${cartId}"]`);
            if (itemElement) {
                itemElement.classList.add('fade-out');
            }
            
            // Show loading
            showLoading(true);
            
            try {
                // Remove from database
                const formData = new FormData();
                formData.append('ajax_action', 'remove_item');
                formData.append('cart_id', cartId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Remove from selected items
                    selectedItems.delete(parseInt(cartId));
                    
                    // Remove from cart items array
                    const itemIndex = cartItems.findIndex(item => item.id_keranjang == cartId);
                    if (itemIndex !== -1) {
                        cartItems.splice(itemIndex, 1);
                    }
                    
                    showToast('Item berhasil dihapus', 'success');
                } else {
                    showToast(data.message || 'Gagal menghapus item', 'error');
                    if (itemElement) {
                        itemElement.classList.remove('fade-out');
                    }
                    return;
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
                if (itemElement) {
                    itemElement.classList.remove('fade-out');
                }
                return;
            } finally {
                showLoading(false);
            }
            
            // Update UI after animation
            setTimeout(() => {
                if (itemElement) {
                    itemElement.remove();
                    
                    // Check if shop section is empty
                    const shopSection = itemElement.closest('.shop-section');
                    if (shopSection) {
                        const itemsInShop = shopSection.querySelectorAll('.cart-item');
                        if (itemsInShop.length === 0) {
                            shopSection.remove();
                        }
                    }
                }
                
                // Update all states
                updateSelectAllCheckbox();
                updateSelectedCount();
                updateCartSummary();
                updateCartCount();
                
                // Check if cart is empty
                if (cartItems.length === 0) {
                    location.reload();
                }
            }, 300);
        }

        // Remove selected items
        async function removeSelectedItems() {
            if (selectedItems.size === 0) {
                showToast('Pilih minimal 1 item untuk dihapus', 'error');
                return;
            }
            
            if (!confirm(`Hapus ${selectedItems.size} item dari keranjang?`)) return;
            
            const itemsToRemove = Array.from(selectedItems);
            
            // Start fade out animation for all selected items
            itemsToRemove.forEach(cartId => {
                const itemElement = document.querySelector(`.cart-item[data-item="${cartId}"]`);
                if (itemElement) {
                    itemElement.classList.add('fade-out');
                }
            });
            
            // Show loading
            showLoading(true);
            
            try {
                // Remove from database
                const formData = new FormData();
                formData.append('ajax_action', 'remove_selected');
                itemsToRemove.forEach(id => {
                    formData.append('cart_ids[]', id);
                });
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Remove from cart items array
                    itemsToRemove.forEach(cartId => {
                        const itemIndex = cartItems.findIndex(item => item.id_keranjang == cartId);
                        if (itemIndex !== -1) {
                            cartItems.splice(itemIndex, 1);
                        }
                    });
                    
                    // Clear selected items
                    selectedItems.clear();
                    
                    showToast(data.message, 'success');
                } else {
                    showToast(data.message || 'Gagal menghapus item', 'error');
                    // Revert animation
                    itemsToRemove.forEach(cartId => {
                        const itemElement = document.querySelector(`.cart-item[data-item="${cartId}"]`);
                        if (itemElement) {
                            itemElement.classList.remove('fade-out');
                        }
                    });
                    return;
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Terjadi kesalahan. Silakan coba lagi.', 'error');
                // Revert animation
                itemsToRemove.forEach(cartId => {
                    const itemElement = document.querySelector(`.cart-item[data-item="${cartId}"]`);
                    if (itemElement) {
                        itemElement.classList.remove('fade-out');
                    }
                });
                return;
            } finally {
                showLoading(false);
            }
            
            // Update UI after animation
            setTimeout(() => {
                // Remove from DOM
                itemsToRemove.forEach(cartId => {
                    const itemElement = document.querySelector(`.cart-item[data-item="${cartId}"]`);
                    if (itemElement) {
                        itemElement.remove();
                    }
                });
                
                // Update UI
                updateSelectAllCheckbox();
                updateSelectedCount();
                updateCartSummary();
                updateCartCount();
                
                // Check if cart is empty
                if (cartItems.length === 0) {
                    location.reload();
                }
            }, 300);
        }
    </script>
</body>
</html>