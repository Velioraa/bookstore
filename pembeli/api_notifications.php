<?php
// api_notifications.php
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
            // Get unread notification count
            $sql_notif = "SELECT COUNT(*) as count FROM notifikasi 
                         WHERE id_user = ? AND is_read = 'unread'";
            $stmt_notif = $conn->prepare($sql_notif);
            $stmt_notif->bind_param("i", $user_id);
            $stmt_notif->execute();
            $notif_result = $stmt_notif->get_result();
            $notif_data = $notif_result->fetch_assoc();
            
            // Get cart count
            $sql_cart = "SELECT COUNT(*) as count FROM keranjang 
                        WHERE id_user = ? AND status = 'active'";
            $stmt_cart = $conn->prepare($sql_cart);
            $stmt_cart->bind_param("i", $user_id);
            $stmt_cart->execute();
            $cart_result = $stmt_cart->get_result();
            $cart_data = $cart_result->fetch_assoc();
            
            // Get unread messages count
            $sql_messages = "SELECT COUNT(*) as count FROM chat 
                            WHERE id_penerima = ? AND status_baca = 'terkirim'";
            $stmt_messages = $conn->prepare($sql_messages);
            $stmt_messages->bind_param("i", $user_id);
            $stmt_messages->execute();
            $messages_result = $stmt_messages->get_result();
            $messages_data = $messages_result->fetch_assoc();
            
            $response['success'] = true;
            $response['data'] = [
                'notifications' => [
                    'unread' => $notif_data['count'] ?? 0
                ],
                'cart' => [
                    'count' => $cart_data['count'] ?? 0
                ],
                'messages' => [
                    'unread' => $messages_data['count'] ?? 0
                ]
            ];
            break;
            
        case 'get_notifications':
            // Get recent notifications
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
            
        case 'get_recent_messages':
            // Get recent chat messages
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
            $sql = "SELECT c.*, 
                           u_sender.username as sender_name,
                           u_receiver.username as receiver_name,
                           DATE_FORMAT(c.waktu_kirim, '%d %b %Y %H:%i') as formatted_time
                    FROM chat c
                    LEFT JOIN users u_sender ON c.id_pengirim = u_sender.id_user
                    LEFT JOIN users u_receiver ON c.id_penerima = u_receiver.id_user
                    WHERE c.id_penerima = ? OR c.id_pengirim = ?
                    ORDER BY c.waktu_kirim DESC 
                    LIMIT ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iii", $user_id, $user_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $messages = [];
            while ($row = $result->fetch_assoc()) {
                // Determine if message is from current user or to current user
                $row['is_sender'] = ($row['id_pengirim'] == $user_id);
                $row['is_unread'] = ($row['status_baca'] == 'terkirim' && $row['id_penerima'] == $user_id);
                $messages[] = $row;
            }
            
            $response['success'] = true;
            $response['data'] = [
                'messages' => $messages
            ];
            break;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch($_POST['action']) {
        case 'mark_all_read':
            $sql = "UPDATE notifikasi SET is_read = 'read' WHERE id_user = ?";
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
                $sql = "UPDATE notifikasi SET is_read = 'read' 
                        WHERE id_notifikasi = ? AND id_user = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $notif_id, $user_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Notification marked as read';
                }
            }
            break;
            
        case 'add_to_cart':
            // Handle add to cart
            $product_id = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 1);
            
            if ($product_id > 0 && $quantity > 0) {
                // Cek apakah produk sudah ada di keranjang
                $sql_check = "SELECT * FROM keranjang 
                             WHERE id_user = ? AND id_produk = ? AND status = 'active'";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->bind_param("ii", $user_id, $product_id);
                $stmt_check->execute();
                $check_result = $stmt_check->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Update quantity
                    $sql = "UPDATE keranjang SET qty = qty + ? 
                           WHERE id_user = ? AND id_produk = ? AND status = 'active'";
                } else {
                    // Insert new
                    $sql = "INSERT INTO keranjang (id_user, id_produk, qty, status) 
                           VALUES (?, ?, ?, 'active')";
                }
                
                $stmt = $conn->prepare($sql);
                if (isset($check_result) && $check_result->num_rows > 0) {
                    $stmt->bind_param("iii", $quantity, $user_id, $product_id);
                } else {
                    $stmt->bind_param("iii", $user_id, $product_id, $quantity);
                }
                
                if ($stmt->execute()) {
                    // Get updated cart count
                    $sql_count = "SELECT COUNT(*) as count FROM keranjang 
                                 WHERE id_user = ? AND status = 'active'";
                    $stmt_count = $conn->prepare($sql_count);
                    $stmt_count->bind_param("i", $user_id);
                    $stmt_count->execute();
                    $count_result = $stmt_count->get_result();
                    $count_data = $count_result->fetch_assoc();
                    
                    $response['success'] = true;
                    $response['data'] = [
                        'cart_count' => $count_data['count'] ?? 0
                    ];
                }
            }
            break;
            
        case 'mark_message_read':
            // Mark a single message as read
            $message_id = intval($_POST['message_id'] ?? 0);
            if ($message_id > 0) {
                $sql = "UPDATE chat SET status_baca = 'terbaca' 
                        WHERE id_chat = ? AND id_penerima = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $message_id, $user_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Message marked as read';
                    
                    // Get updated counts
                    $sql_count = "SELECT COUNT(*) as count FROM chat 
                                 WHERE id_penerima = ? AND status_baca = 'terkirim'";
                    $stmt_count = $conn->prepare($sql_count);
                    $stmt_count->bind_param("i", $user_id);
                    $stmt_count->execute();
                    $count_result = $stmt_count->get_result();
                    $count_data = $count_result->fetch_assoc();
                    
                    $response['data'] = [
                        'unread_count' => $count_data['count'] ?? 0
                    ];
                } else {
                    $response['message'] = 'Failed to mark message as read';
                }
            } else {
                $response['message'] = 'Invalid message ID';
            }
            break;
            
        case 'mark_all_messages_read':
            // Mark all messages as read for current user
            $sql = "UPDATE chat SET status_baca = 'terbaca' 
                    WHERE id_penerima = ? AND status_baca = 'terkirim'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'All messages marked as read';
                $response['data'] = [
                    'unread_count' => 0
                ];
            } else {
                $response['message'] = 'Failed to mark messages as read';
            }
            break;
            
        case 'mark_conversation_read':
            // Mark all messages from a specific sender as read
            $sender_id = intval($_POST['sender_id'] ?? 0);
            if ($sender_id > 0) {
                $sql = "UPDATE chat SET status_baca = 'terbaca' 
                        WHERE id_pengirim = ? AND id_penerima = ? AND status_baca = 'terkirim'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $sender_id, $user_id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Conversation marked as read';
                    
                    // Get updated counts
                    $sql_count = "SELECT COUNT(*) as count FROM chat 
                                 WHERE id_penerima = ? AND status_baca = 'terkirim'";
                    $stmt_count = $conn->prepare($sql_count);
                    $stmt_count->bind_param("i", $user_id);
                    $stmt_count->execute();
                    $count_result = $stmt_count->get_result();
                    $count_data = $count_result->fetch_assoc();
                    
                    $response['data'] = [
                        'unread_count' => $count_data['count'] ?? 0
                    ];
                } else {
                    $response['message'] = 'Failed to mark conversation as read';
                }
            } else {
                $response['message'] = 'Invalid sender ID';
            }
            break;
            
        case 'send_message':
            // Send a new message
            $receiver_id = intval($_POST['receiver_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $message_type = $_POST['message_type'] ?? 'teks';
            $product_id = intval($_POST['product_id'] ?? 0);
            $transaction_id = intval($_POST['transaction_id'] ?? 0);
            
            if ($receiver_id > 0 && !empty($message)) {
                // Get user role
                $role = $_SESSION['role'] ?? 'pembeli';
                $role_map = [
                    'pembeli' => 'pembeli',
                    'penjual' => 'penjual',
                    'admin' => 'admin',
                    'super_admin' => 'admin'
                ];
                $peran_pengirim = $role_map[$role] ?? 'pembeli';
                
                // Prepare file data if any
                $file_name = $_POST['file_name'] ?? null;
                $file_size = intval($_POST['file_size'] ?? 0);
                $file_type = $_POST['file_type'] ?? null;
                
                $sql = "INSERT INTO chat (id_pengirim, id_penerima, peran_pengirim, 
                                        isi_pesan, tipe_pesan, nama_file, ukuran_file, 
                                        tipe_file, id_produk, id_transaksi, status_baca) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'terkirim')";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iissssisii", 
                    $user_id, $receiver_id, $peran_pengirim, $message, $message_type,
                    $file_name, $file_size, $file_type, $product_id, $transaction_id);
                
                if ($stmt->execute()) {
                    $message_id = $conn->insert_id;
                    
                    // Get message details
                    $sql_details = "SELECT c.*, 
                                           u.username as sender_name,
                                           DATE_FORMAT(c.waktu_kirim, '%d %b %Y %H:%i') as formatted_time
                                    FROM chat c
                                    LEFT JOIN users u ON c.id_pengirim = u.id_user
                                    WHERE c.id_chat = ?";
                    
                    $stmt_details = $conn->prepare($sql_details);
                    $stmt_details->bind_param("i", $message_id);
                    $stmt_details->execute();
                    $details_result = $stmt_details->get_result();
                    $message_data = $details_result->fetch_assoc();
                    
                    // Create notification for receiver
                    $notification_message = "Anda memiliki pesan baru dari " . $message_data['sender_name'];
                    $sql_notif = "INSERT INTO notifikasi (id_user, pesan, tipe, is_read) 
                                  VALUES (?, ?, 'message', 'unread')";
                    $stmt_notif = $conn->prepare($sql_notif);
                    $stmt_notif->bind_param("is", $receiver_id, $notification_message);
                    $stmt_notif->execute();
                    
                    $response['success'] = true;
                    $response['message'] = 'Message sent successfully';
                    $response['data'] = [
                        'message' => $message_data
                    ];
                } else {
                    $response['message'] = 'Failed to send message';
                }
            } else {
                $response['message'] = 'Receiver ID and message are required';
            }
            break;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
$conn->close();
?>