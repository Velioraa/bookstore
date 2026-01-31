<?php
// chat_api.php - FIXED VERSION (Better error handling)
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Cek koneksi database
try {
    require_once '../koneksi.php';
    
    // Test koneksi
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];

// Ambil action dari GET atau POST
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit();
}

// Fungsi untuk handle error
function handleError($message, $conn = null) {
    error_log("Chat API Error: " . $message);
    echo json_encode(['success' => false, 'message' => $message]);
    if ($conn) $conn->close();
    exit();
}

switch ($action) {
    case 'get-contacts':
        getContacts($conn, $user_id);
        break;
        
    case 'get-messages':
        getMessages($conn, $user_id);
        break;
        
    case 'send-message':
        sendMessage($conn, $user_id);
        break;
        
    case 'mark-as-read':
        markAsRead($conn, $user_id);
        break;
        
    case 'check-chat-room':
        checkChatRoom($conn, $user_id);
        break;
        
    default:
        handleError('Invalid action: ' . $action, $conn);
}

function getContacts($conn, $user_id) {
    try {
        $sql = "
            SELECT DISTINCT 
                u.id_user as contact_id,
                u.username as contact_name,
                u.role as contact_role,
                u.gambar as contact_image,
                COALESCE(
                    (SELECT c.isi_pesan 
                     FROM chat c 
                     WHERE (c.id_pengirim = ? AND c.id_penerima = u.id_user)
                        OR (c.id_pengirim = u.id_user AND c.id_penerima = ?)
                     ORDER BY c.waktu_kirim DESC 
                     LIMIT 1),
                    'Belum ada pesan'
                ) as last_message,
                COALESCE(
                    (SELECT c.waktu_kirim 
                     FROM chat c 
                     WHERE (c.id_pengirim = ? AND c.id_penerima = u.id_user)
                        OR (c.id_pengirim = u.id_user AND c.id_penerima = ?)
                     ORDER BY c.waktu_kirim DESC 
                     LIMIT 1),
                    NOW()
                ) as last_message_time,
                COALESCE(
                    (SELECT COUNT(*) 
                     FROM chat c 
                     WHERE c.id_penerima = ? 
                       AND c.id_pengirim = u.id_user 
                       AND c.status_baca = 'terkirim'),
                    0
                ) as unread_count
            FROM users u
            WHERE u.id_user IN (
                SELECT DISTINCT id_pengirim FROM chat WHERE id_penerima = ?
                UNION
                SELECT DISTINCT id_penerima FROM chat WHERE id_pengirim = ?
            )
            AND u.id_user != ?
            ORDER BY last_message_time DESC
        ";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iiiiiiii", 
            $user_id, $user_id,
            $user_id, $user_id,
            $user_id,
            $user_id,
            $user_id,
            $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $contacts = [];
        
        while ($row = $result->fetch_assoc()) {
            // Format waktu
            if ($row['last_message_time'] && $row['last_message_time'] != 'NOW()') {
                $row['last_message_time'] = date('H:i', strtotime($row['last_message_time']));
            } else {
                $row['last_message_time'] = '-';
            }
            
            $contacts[] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $contacts]);
        
    } catch (Exception $e) {
        handleError('Failed to load contacts: ' . $e->getMessage(), $conn);
    }
}

function getMessages($conn, $user_id) {
    $other_user_id = $_GET['other_user_id'] ?? 0;
    $check_new = isset($_GET['check_new']) ? true : false;
    
    if (!$other_user_id) {
        echo json_encode(['success' => false, 'message' => 'No user specified']);
        return;
    }
    
    try {
        // Query untuk mendapatkan pesan
        $sql = "SELECT c.*, 
                       u.username as sender_name,
                       DATE_FORMAT(c.waktu_kirim, '%H:%i') as time,
                       DATE_FORMAT(c.waktu_kirim, '%d/%m/%Y') as date,
                       CASE WHEN c.id_pengirim = ? THEN 1 ELSE 0 END as is_me
                FROM chat c
                LEFT JOIN users u ON c.id_pengirim = u.id_user
                WHERE (c.id_pengirim = ? AND c.id_penerima = ?)
                   OR (c.id_pengirim = ? AND c.id_penerima = ?)
                ORDER BY c.waktu_kirim ASC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iiiii", 
            $user_id,
            $user_id, $other_user_id,
            $other_user_id, $user_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $messages = [];
        
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        
        // Tandai sebagai terbaca jika bukan cek baru
        if (!$check_new) {
            $update_sql = "UPDATE chat SET status_baca = 'terbaca' 
                          WHERE id_penerima = ? AND id_pengirim = ? 
                          AND status_baca = 'terkirim'";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("ii", $user_id, $other_user_id);
                $update_stmt->execute();
            }
        }
        
        echo json_encode(['success' => true, 'messages' => $messages]);
        
    } catch (Exception $e) {
        // Return empty messages instead of error for better UX
        echo json_encode(['success' => true, 'messages' => []]);
    }
}

function sendMessage($conn, $user_id) {
    $receiver_id = $_POST['receiver_id'] ?? 0;
    $message = $_POST['message'] ?? '';
    $message_type = $_POST['message_type'] ?? 'teks';
    $produk_id = $_POST['produk_id'] ?? 0;
    $transaksi_id = $_POST['transaksi_id'] ?? 0;
    
    if (!$receiver_id || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        return;
    }
    
    try {
        // Ambil role pengirim
        $user_sql = "SELECT role FROM users WHERE id_user = ?";
        $user_stmt = $conn->prepare($user_sql);
        if (!$user_stmt) {
            throw new Exception("User prepare failed: " . $conn->error);
        }
        
        $user_stmt->bind_param("i", $user_id);
        if (!$user_stmt->execute()) {
            throw new Exception("User execute failed: " . $user_stmt->error);
        }
        
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        
        $peran_pengirim = ($user_data['role'] == 'penjual') ? 'penjual' : 'pembeli';
        
        // Simpan pesan
        $sql = "INSERT INTO chat (id_pengirim, id_penerima, peran_pengirim, isi_pesan, waktu_kirim, status_baca, tipe_pesan, id_produk, id_transaksi) 
                VALUES (?, ?, ?, ?, NOW(), 'terkirim', ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Message prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iisssii", 
            $user_id, 
            $receiver_id, 
            $peran_pengirim, 
            $message, 
            $message_type,
            $produk_id,
            $transaksi_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Message execute failed: " . $stmt->error);
        }
        
        $message_id = $conn->insert_id;
        
        // Ambil data pesan yang baru dikirim
        $get_sql = "SELECT c.*, 
                           u.username as sender_name,
                           DATE_FORMAT(c.waktu_kirim, '%H:%i') as time,
                           DATE_FORMAT(c.waktu_kirim, '%d/%m/%Y') as date
                    FROM chat c
                    LEFT JOIN users u ON c.id_pengirim = u.id_user
                    WHERE c.id_chat = ?";
        
        $get_stmt = $conn->prepare($get_sql);
        if ($get_stmt) {
            $get_stmt->bind_param("i", $message_id);
            $get_stmt->execute();
            $result = $get_stmt->get_result();
            $new_message = $result->fetch_assoc();
        } else {
            $new_message = ['id_chat' => $message_id];
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Pesan berhasil dikirim',
            'data' => $new_message
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to send message: ' . $e->getMessage(), $conn);
    }
}

function markAsRead($conn, $user_id) {
    $other_user_id = $_POST['other_user_id'] ?? 0;
    
    if (!$other_user_id) {
        echo json_encode(['success' => false, 'message' => 'No user specified']);
        return;
    }
    
    try {
        $sql = "UPDATE chat SET status_baca = 'terbaca' 
                WHERE id_penerima = ? AND id_pengirim = ? 
                AND status_baca = 'terkirim'";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $user_id, $other_user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        echo json_encode(['success' => true, 'message' => 'Messages marked as read']);
        
    } catch (Exception $e) {
        // Non-critical error, just log it
        error_log("Mark as read error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to mark as read']);
    }
}

function checkChatRoom($conn, $user_id) {
    $transaksi_id = $_POST['transaksi_id'] ?? 0;
    $produk_id = $_POST['produk_id'] ?? 0;
    $penjual_id = $_POST['penjual_id'] ?? 0;
    $pembeli_id = $_POST['pembeli_id'] ?? $user_id;
    
    try {
        $sql = "SELECT COUNT(*) as total, 
                       SUM(CASE WHEN status_baca = 'terkirim' AND id_penerima = ? THEN 1 ELSE 0 END) as unread
                FROM chat 
                WHERE ((id_pengirim = ? AND id_penerima = ?) OR (id_pengirim = ? AND id_penerima = ?))
                AND (id_transaksi = ? OR id_produk = ? OR ? = 0)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iiiiiii", 
            $pembeli_id,
            $pembeli_id, $penjual_id,
            $penjual_id, $pembeli_id,
            $transaksi_id,
            $produk_id,
            $produk_id
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'has_chat' => $data['total'] > 0,
            'unread_count' => $data['unread'] ?? 0,
            'total_messages' => $data['total'] ?? 0
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to check chat room: ' . $e->getMessage(), $conn);
    }
}

// Close connection
if (isset($conn)) {
    $conn->close();
}
?>