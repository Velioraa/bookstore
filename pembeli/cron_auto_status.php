<?php
require_once 'koneksi.php';

// Log waktu mulai
echo "=== CRON JOB: AUTO UPDATE STATUS PESANAN ===\n";
echo "Waktu: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Update status pesanan yang sudah dikirim lebih dari 1 hari dan sudah di-approve
$sql_update_status = "UPDATE transaksi 
                      SET status = 'selesai'
                      WHERE status = 'dikirim' 
                      AND approve = 'approve'
                      AND DATE_ADD(tanggal_transaksi, INTERVAL 1 DAY) <= NOW()";

$result_update = $conn->query($sql_update);
$updated_count = $conn->affected_rows;

echo "✅ $updated_count pesanan diubah dari 'dikirim' menjadi 'selesai'\n";

// 2. Update status pesanan yang pending terlalu lama (lebih dari 3 hari)
$sql_expired = "UPDATE transaksi 
                SET status = 'ditolak'
                WHERE status = 'pending'
                AND DATE_ADD(tanggal_transaksi, INTERVAL 3 DAY) <= NOW()
                AND (bukti_pembayaran IS NULL OR bukti_pembayaran = '')";

$result_expired = $conn->query($sql_expired);
$expired_count = $conn->affected_rows;

if ($expired_count > 0) {
    echo "🔄 $expired_count pesanan expired diubah menjadi 'ditolak'\n";
}

// 3. Tambahkan notifikasi untuk pesanan yang selesai otomatis
if ($updated_count > 0) {
    // Ambil semua user yang pesanannya diupdate
    $sql_users = "SELECT DISTINCT id_user FROM transaksi 
                  WHERE status = 'selesai' 
                  AND DATE(updated_at) = CURDATE()";
    
    $result_users = $conn->query($sql_users);
    
    while ($user = $result_users->fetch_assoc()) {
        $user_id = $user['id_user'];
        
        // Hitung berapa pesanan yang selesai untuk user ini
        $sql_count = "SELECT COUNT(*) as count FROM transaksi 
                      WHERE id_user = ? 
                      AND status = 'selesai' 
                      AND DATE(updated_at) = CURDATE()";
        
        $stmt = $conn->prepare($sql_count);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result_count = $stmt->get_result();
        $count_data = $result_count->fetch_assoc();
        $user_updated_count = $count_data['count'];
        
        // Tambahkan notifikasi
        $pesan = "$user_updated_count pesanan Anda telah otomatis diselesaikan karena sudah lebih dari 1 hari sejak dikirim.";
        
        $sql_notif = "INSERT INTO notifikasi (id_user, pesan, tipe, is_read) 
                      VALUES (?, ?, 'order_completed', 'unread')";
        
        $stmt_notif = $conn->prepare($sql_notif);
        $stmt_notif->bind_param("is", $user_id, $pesan);
        $stmt_notif->execute();
        $stmt_notif->close();
        
        $stmt->close();
    }
    
    echo "📢 Notifikasi dikirim ke user terkait\n";
}

$conn->close();

// Log ke file
$log_message = date('Y-m-d H:i:s') . " - $updated_count pesanan selesai, $expired_count pesanan expired\n";
file_put_contents('logs/cron_status.log', $log_message, FILE_APPEND);

echo "\n=== CRON JOB SELESAI ===\n";