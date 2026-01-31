<?php
// penjual/api_notifications.php
require_once '../koneksi.php';
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Handle different actions
if (isset($_GET['action'])) {
    switch($_GET['action']) {
        case 'get_counts':
            // Get unread notification count for seller
            $sql_notif = "SELECT COUNT(*) as count FROM notifikasi 
                         WHERE id_user = ? AND is_read = '0'";
            $stmt_notif = $conn->prepare($sql_notif);
            $stmt_notif->bind_param("i", $user_id);
            $stmt_notif->execute();
            $notif_result = $stmt_notif->get_result();
            $notif_data = $notif_result->fetch_assoc();
            
            // Get new orders count for seller
            $sql_orders = "SELECT COUNT(*) as count FROM transaksi 
                          WHERE id_penjual = ? AND status = 'pending' AND approve_status = 'pending'";
            $stmt_orders = $conn->prepare($sql_orders);
            $stmt_orders->bind_param("i", $user_id);
            $stmt_orders->execute();
            $orders_result = $stmt_orders->get_result();
            $orders_data = $orders_result->fetch_assoc();
            
            // TAMBAHAN: Get unread chat messages count for seller
            $sql_chat = "SELECT COUNT(*) as count FROM chat 
                        WHERE id_penerima = ? AND status_baca = 'terkirim'";
            $stmt_chat = $conn->prepare($sql_chat);
            $stmt_chat->bind_param("i", $user_id);
            $stmt_chat->execute();
            $chat_result = $stmt_chat->get_result();
            $chat_data = $chat_result->fetch_assoc();
            
            $response['success'] = true;
            $response['data'] = [
                'notifications' => [
                    'unread' => $notif_data['count'] ?? 0
                ],
                'orders' => [
                    'new' => $orders_data['count'] ?? 0
                ],
                'chat' => [
                    'unread' => $chat_data['count'] ?? 0
                ]
            ];
            break;
            
        case 'get_seller_stats':
            // Get seller statistics
            // Total products
            $sql_products = "SELECT COUNT(*) as count FROM produk WHERE id_penjual = ?";
            $stmt_products = $conn->prepare($sql_products);
            $stmt_products->bind_param("i", $user_id);
            $stmt_products->execute();
            $products_result = $stmt_products->get_result();
            $products_data = $products_result->fetch_assoc();
            
            // Total orders (all time)
            $sql_total_orders = "SELECT COUNT(*) as count FROM transaksi WHERE id_penjual = ?";
            $stmt_total_orders = $conn->prepare($sql_total_orders);
            $stmt_total_orders->bind_param("i", $user_id);
            $stmt_total_orders->execute();
            $total_orders_result = $stmt_total_orders->get_result();
            $total_orders_data = $total_orders_result->fetch_assoc();
            
            // Total revenue (completed orders only)
            $sql_revenue = "SELECT SUM(total_harga) as total FROM transaksi 
                           WHERE id_penjual = ? AND status = 'completed'";
            $stmt_revenue = $conn->prepare($sql_revenue);
            $stmt_revenue->bind_param("i", $user_id);
            $stmt_revenue->execute();
            $revenue_result = $stmt_revenue->get_result();
            $revenue_data = $revenue_result->fetch_assoc();
            
            $response['success'] = true;
            $response['data'] = [
                'products' => $products_data['count'] ?? 0,
                'total_orders' => $total_orders_data['count'] ?? 0,
                'total_revenue' => $revenue_data['total'] ?? 0
            ];
            break;
            
        case 'get_notifications':
            // Get recent notifications for seller
            $sql = "SELECT n.*, DATE_FORMAT(n.created_at, '%d %b %Y %H:%i') as formatted_date 
                    FROM notifikasi n 
                    WHERE n.id_user = ? 
                    ORDER BY n.created_at DESC 
                    LIMIT 10";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $notifications = [];
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
            }
            
            $response['success'] = true;
            $response['data'] = [
                'notifications' => $notifications
            ];
            break;
            
        case 'get_chat_stats':
            // TAMBAHAN: Get chat statistics for seller
            $sql_unread = "SELECT COUNT(*) as unread_count FROM chat 
                          WHERE id_penerima = ? AND status_baca = 'terkirim'";
            $stmt_unread = $conn->prepare($sql_unread);
            $stmt_unread->bind_param("i", $user_id);
            $stmt_unread->execute();
            $unread_result = $stmt_unread->get_result();
            $unread_data = $unread_result->fetch_assoc();
            
            $sql_recent = "SELECT COUNT(DISTINCT id_pengirim) as recent_chats 
                          FROM chat 
                          WHERE id_penerima = ? 
                          AND DATE(waktu_kirim) = CURDATE()";
            $stmt_recent = $conn->prepare($sql_recent);
            $stmt_recent->bind_param("i", $user_id);
            $stmt_recent->execute();
            $recent_result = $stmt_recent->get_result();
            $recent_data = $recent_result->fetch_assoc();
            
            $response['success'] = true;
            $response['data'] = [
                'unread_chats' => $unread_data['unread_count'] ?? 0,
                'recent_chats' => $recent_data['recent_chats'] ?? 0
            ];
            break;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch($_POST['action']) {
        case 'mark_all_read':
            $sql = "UPDATE notifikasi SET is_read = '1' WHERE id_user = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'All notifications marked as read';
            } else {
                $response['message'] = 'Failed to mark notifications as read';
            }
            break;
            
        case 'mark_single_read':
            $notif_id = intval($_POST['notif_id'] ?? 0);
            if ($notif_id > 0) {
                $sql = "UPDATE notifikasi SET is_read = '1' 
                        WHERE id_notifikasi = ? AND id_user = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $notif_id, $user_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Notification marked as read';
                }
            }
            break;
            
        case 'create_notification':
            // Create notification for seller (when new order is placed)
            $target_user_id = intval($_POST['target_user_id'] ?? 0);
            $id_order = intval($_POST['id_order'] ?? 0);
            $id_produk = intval($_POST['id_produk'] ?? 0);
            $judul = $_POST['judul'] ?? 'Pesanan Baru';
            $pesan = $_POST['pesan'] ?? '';
            
            if ($target_user_id > 0 && $id_order > 0) {
                // Get order details
                $sql_order = "SELECT t.*, p.nama_produk, u.username as pembeli_nama 
                             FROM transaksi t
                             JOIN produk p ON t.id_produk = p.id_produk
                             JOIN users u ON t.id_user = u.id_user
                             WHERE t.id_transaksi = ?";
                $stmt_order = $conn->prepare($sql_order);
                $stmt_order->bind_param("i", $id_order);
                $stmt_order->execute();
                $order_result = $stmt_order->get_result();
                
                if ($order_result->num_rows > 0) {
                    $order = $order_result->fetch_assoc();
                    
                    // Create notification message
                    if (empty($pesan)) {
                        $pesan = "Pesanan baru dari {$order['pembeli_nama']} untuk produk {$order['nama_produk']} dengan total Rp {$order['total_harga']}";
                    }
                    
                    $sql = "INSERT INTO notifikasi (id_user, id_order, id_produk, judul, pesan, is_read, type, created_at) 
                           VALUES (?, ?, ?, ?, ?, '0', 'new_order', NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("iiisss", $target_user_id, $id_order, $id_produk, $judul, $pesan);
                    
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Notification created successfully';
                        $response['data'] = [
                            'notification_id' => $conn->insert_id
                        ];
                    } else {
                        $response['message'] = 'Failed to create notification';
                    }
                } else {
                    $response['message'] = 'Order not found';
                }
            } else {
                $response['message'] = 'Missing required parameters';
            }
            break;
            
        case 'mark_chat_read':
            // TAMBAHAN: Mark specific chat messages as read
            $other_user_id = intval($_POST['other_user_id'] ?? 0);
            
            if ($other_user_id > 0) {
                $sql = "UPDATE chat SET status_baca = 'terbaca' 
                        WHERE id_penerima = ? AND id_pengirim = ? AND status_baca = 'terkirim'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $user_id, $other_user_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Chat messages marked as read';
                } else {
                    $response['message'] = 'Failed to mark chat messages as read';
                }
            } else {
                $response['message'] = 'Missing other_user_id parameter';
            }
            break;
            
        case 'mark_all_chat_read':
            // TAMBAHAN: Mark all chat messages as read for this user
            $sql = "UPDATE chat SET status_baca = 'terbaca' 
                    WHERE id_penerima = ? AND status_baca = 'terkirim'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'All chat messages marked as read';
            } else {
                $response['message'] = 'Failed to mark all chat messages as read';
            }
            break;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();
?>