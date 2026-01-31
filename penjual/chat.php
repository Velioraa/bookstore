<?php
// chat.php (FIXED VERSION - PROFILE GAMBAR & KONEKSI)
// Koneksi ke database
require_once '../koneksi.php';

// Mulai session
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    // Jika belum login, redirect ke halaman login
    header("Location: ../login.php");
    exit();
}

$penjual_id = isset($_GET['penjual_id']) ? (int)$_GET['penjual_id'] : 0;
$penjual_name = isset($_GET['penjual_name']) ? urldecode($_GET['penjual_name']) : '';
$produk_nama = isset($_GET['produk_nama']) ? urldecode($_GET['produk_nama']) : '';
$transaksi_id = isset($_GET['transaksi_id']) ? (int)$_GET['transaksi_id'] : 0;

// Jika ada penjual_id, simpan di session untuk digunakan oleh JavaScript
if ($penjual_id > 0) {
    $_SESSION['auto_chat_data'] = [
        'penjual_id' => $penjual_id,
        'penjual_name' => $penjual_name,
        'produk_nama' => $produk_nama,
        'transaksi_id' => $transaksi_id,
        'role' => 'penjual'
    ];
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
    
    // Path gambar profile - PERBAIKAN DI SINI
    $gambar_path = '';
    $has_image = false;
    
    if (!empty($gambar)) {
        // Coba beberapa lokasi untuk gambar profile
        $possible_paths = [
            '../asset/' . $gambar,
            '../asset/uploads/' . $gambar,
            '../asset/profile/' . $gambar,
            '../uploads/' . $gambar,
            '../../asset/' . $gambar,
            $gambar  // jika sudah full path
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $gambar_path = $path;
                $has_image = true;
                break;
            }
        }
    }
    
    // AMBIL KONTAK CHAT DENGAN QUERY MANUAL
    $contacts_sql = "
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
    
    $contacts_stmt = $conn->prepare($contacts_sql);
    $contacts_stmt->bind_param("iiiiiiii", 
        $user_id, $user_id,
        $user_id, $user_id,
        $user_id,
        $user_id,
        $user_id,
        $user_id
    );
    
    $contacts_stmt->execute();
    $contacts_result = $contacts_stmt->get_result();
    $contacts = [];
    
    while ($contact = $contacts_result->fetch_assoc()) {
        // Format waktu jika ada
        if ($contact['last_message_time'] && $contact['last_message_time'] != 'NOW()') {
            $contact['last_message_time'] = date('H:i', strtotime($contact['last_message_time']));
        } else {
            $contact['last_message_time'] = '-';
        }
        
        // Default untuk last_message
        if (empty($contact['last_message']) || $contact['last_message'] == 'Belum ada pesan') {
            $contact['last_message'] = 'Belum ada pesan';
        } else {
            // Potong pesan jika terlalu panjang
            if (strlen($contact['last_message']) > 30) {
                $contact['last_message'] = substr($contact['last_message'], 0, 30) . '...';
            }
        }
        
        // Cek gambar kontak
        $contact_has_image = false;
        $contact_image_path = '';
        if (!empty($contact['contact_image'])) {
            $possible_paths = [
                '../asset/' . $contact['contact_image'],
                '../asset/uploads/' . $contact['contact_image'],
                '../asset/profile/' . $contact['contact_image'],
                '../uploads/' . $contact['contact_image'],
                $contact['contact_image']
            ];
            
            foreach ($possible_paths as $path) {
                if (file_exists($path)) {
                    $contact_image_path = $path;
                    $contact_has_image = true;
                    break;
                }
            }
        }
        $contact['contact_has_image'] = $contact_has_image;
        $contact['contact_image_path'] = $contact_image_path;
        
        $contacts[] = $contact;
    }
    
} else {
    // Jika user tidak ditemukan, logout
    session_destroy();
    header("Location: ../login.php");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Chat</title>
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
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header dengan tombol back */
        .chat-header-back {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            z-index: 10;
        }
        
        .back-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f0f4f8;
            color: var(--primary);
            border: none;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            background: #e3e9f1;
            transform: translateX(-2px);
        }
        
        .header-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        .logo-small {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-left: auto;
        }
        
        .logo-img-small {
            height: 30px;
            width: 30px;
            border-radius: 6px;
            object-fit: contain;
        }

        /* Chat Container */
        .chat-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Chat Sidebar */
        .chat-sidebar {
            width: 350px;
            background: white;
            border-right: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
        }
        
        .chat-sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .chat-sidebar-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .chat-search {
            position: relative;
        }
        
        .chat-search input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .chat-search i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .chat-contacts {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
        }
        
        .chat-contact {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            cursor: pointer;
            transition: background 0.3s ease;
            border-bottom: 1px solid #f8f9fa;
        }
        
        .chat-contact:hover {
            background: #f8f9fa;
        }
        
        .chat-contact.active {
            background: #f0f4f8;
            border-left: 3px solid var(--secondary);
        }
        
        .contact-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent) 0%, #8e44ad 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            margin-right: 15px;
        }
        
        .contact-info {
            flex: 1;
            min-width: 0;
        }
        
        .contact-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
            font-size: 0.95rem;
        }
        
        .contact-role {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 5px;
        }
        
        .contact-last-message {
            font-size: 0.85rem;
            color: var(--gray);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .contact-meta {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
        }
        
        .contact-time {
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        .contact-unread {
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
        }

        /* Chat Main Area */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8f9fa;
        }
        
        .chat-header {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .chat-user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .chat-user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }
        
        .chat-user-details h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 3px;
        }
        
        .chat-user-status {
            font-size: 0.85rem;
            color: var(--success);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .chat-user-status.offline {
            color: var(--gray);
        }
        
        .chat-header-actions {
            display: flex;
            gap: 15px;
        }
        
        .chat-action-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f0f4f8;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }
        
        .chat-action-btn:hover {
            background: #e3e9f1;
            color: var(--secondary);
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px 30px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .message-date {
            text-align: center;
            margin: 10px 0;
        }
        
        .date-label {
            background: #e9ecef;
            color: var(--gray);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .message {
            display: flex;
            gap: 10px;
            max-width: 70%;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .message.received {
            align-self: flex-start;
        }
        
        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent) 0%, #8e44ad 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .message-content {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .message-bubble {
            padding: 12px 15px;
            border-radius: 15px;
            position: relative;
            max-width: 100%;
            word-wrap: break-word;
        }
        
        .message.received .message-bubble {
            background: white;
            border: 1px solid #e9ecef;
            border-top-left-radius: 5px;
        }
        
        .message.sent .message-bubble {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            border-top-right-radius: 5px;
        }
        
        .message-text {
            font-size: 0.95rem;
            line-height: 1.4;
        }
        
        .message-time {
            font-size: 0.75rem;
            opacity: 0.8;
            text-align: right;
            margin-top: 3px;
        }
        
        .message.sent .message-time {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .chat-input-area {
            background: white;
            padding: 20px 30px;
            border-top: 1px solid #e9ecef;
        }
        
        .chat-input-container {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        
        .input-actions {
            display: flex;
            gap: 10px;
        }
        
        .input-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f0f4f8;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }
        
        .input-btn:hover {
            background: #e3e9f1;
            color: var(--secondary);
        }
        
        .chat-input-wrapper {
            flex: 1;
            background: #f8f9fa;
            border-radius: 25px;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 2px solid transparent;
            transition: border-color 0.3s ease;
        }
        
        .chat-input-wrapper:focus-within {
            border-color: var(--secondary);
        }
        
        .chat-input {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 0.95rem;
            color: var(--dark);
            resize: none;
            max-height: 100px;
            min-height: 40px;
            padding: 10px 0;
            outline: none;
            font-family: inherit;
        }
        
        .send-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            border: none;
            color: white;
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .send-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
        }
        
        .send-btn:disabled {
            background: var(--gray);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Typing indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 10px 15px;
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 15px;
            max-width: fit-content;
            margin: 5px 0;
        }
        
        .typing-dots {
            display: flex;
            gap: 3px;
        }
        
        .typing-dot {
            width: 6px;
            height: 6px;
            background: var(--gray);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }
        
        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-5px); }
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--gray);
            text-align: center;
            padding: 40px 20px;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #e9ecef;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .empty-state p {
            max-width: 300px;
            line-height: 1.5;
        }
        
        /* Connection status */
        .connection-status {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .connection-status.connected {
            background: var(--success);
            color: white;
        }
        
        .connection-status.disconnected {
            background: var(--danger);
            color: white;
        }
        
        .connection-status.connecting {
            background: var(--warning);
            color: var(--dark);
        }
        
        /* Product info in chat */
        .chat-product-info {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 2px;
            background: #f0f4f8;
            padding: 4px 8px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .chat-sidebar {
                width: 300px;
            }
        }
        
        @media (max-width: 768px) {
            .chat-sidebar {
                width: 100%;
                position: absolute;
                left: 0;
                top: 60px;
                bottom: 0;
                z-index: 1001;
                display: none;
            }
            
            .chat-sidebar.active {
                display: flex;
            }
            
            .chat-main {
                width: 100%;
            }
            
            .message {
                max-width: 85%;
            }
            
            .chat-header {
                padding: 15px 20px;
            }
            
            .chat-messages {
                padding: 15px 20px;
            }
            
            .chat-input-area {
                padding: 15px 20px;
            }
        }
        
        @media (max-width: 576px) {
            .chat-header,
            .chat-messages,
            .chat-input-area {
                padding: 15px;
            }
            
            .message {
                max-width: 90%;
            }
            
            .chat-user-details h4 {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Connection Status Indicator -->
    <div id="connectionStatus" class="connection-status disconnected" style="display: none;">
        <i class="fas fa-circle"></i>
        <span>Disconnected</span>
    </div>

    <!-- Header dengan tombol back -->
    <div class="chat-header-back">
        <button class="back-button" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="header-title">Chat</div>
        <div class="logo-small">
            <span id="onlineStatus" style="font-size: 0.8rem; color: var(--gray);">
                <i class="fas fa-circle" style="color: var(--danger); font-size: 0.7rem;"></i>
                Offline
            </span>
        </div>
    </div>

    <!-- Chat Container -->
    <div class="chat-container">
        <!-- Chat Sidebar -->
        <div class="chat-sidebar" id="chatSidebar">
            <div class="chat-sidebar-header">
                <h3 class="chat-sidebar-title">Pesan</h3>
                <div class="chat-search">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Cari percakapan..." id="searchChat">
                </div>
            </div>
            <div class="chat-contacts" id="chatContacts">
                <!-- Contacts will be loaded dynamically -->
                <?php if (empty($contacts)): ?>
                <div class="empty-state" id="emptyContacts">
                    <i class="fas fa-comments"></i>
                    <h3>Belum ada percakapan</h3>
                    <p>Mulailah percakapan dengan pembeli atau penjual lain</p>
                </div>
                <?php else: ?>
                    <?php foreach ($contacts as $contact): ?>
                    <div class="chat-contact" data-user-id="<?php echo $contact['contact_id']; ?>" onclick="selectContactFromPHP(<?php echo htmlspecialchars(json_encode($contact), ENT_QUOTES, 'UTF-8'); ?>)">
                        <div class="contact-avatar">
                            <?php if ($contact['contact_has_image']): ?>
                                <img src="<?php echo $contact['contact_image_path']; ?>" alt="<?php echo htmlspecialchars($contact['contact_name']); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: 
                                $name = $contact['contact_name'] ?? 'User';
                                $contact_initials = getInitials($name);
                                echo $contact_initials;
                            endif; ?>
                        </div>
                        <div class="contact-info">
                            <div class="contact-name"><?php echo htmlspecialchars($contact['contact_name']); ?></div>
                            <div class="contact-role"><?php echo htmlspecialchars($contact['contact_role']); ?></div>
                            <div class="contact-last-message"><?php echo htmlspecialchars($contact['last_message']); ?></div>
                        </div>
                        <div class="contact-meta">
                            <div class="contact-time"><?php echo htmlspecialchars($contact['last_message_time']); ?></div>
                            <?php if ($contact['unread_count'] > 0): ?>
                            <div class="contact-unread"><?php echo $contact['unread_count']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Main Area -->
        <div class="chat-main">
            <!-- Chat Header -->
            <div class="chat-header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <button class="chat-action-btn" id="toggleSidebar" style="display: none;">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="chat-user-info" id="currentUserInfo" style="display: none;">
                        <div class="chat-user-avatar" id="currentUserAvatar">
                            <?php if ($has_image): ?>
                                <img src="<?php echo $gambar_path; ?>" alt="<?php echo htmlspecialchars($username); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <?php echo $initials; ?>
                            <?php endif; ?>
                        </div>
                        <div class="chat-user-details">
                            <h4 id="currentUserName"></h4>
                            <div class="chat-user-status offline" id="currentUserStatus">
                                <i class="fas fa-circle" style="font-size: 0.7rem;"></i>
                                <span>Offline</span>
                            </div>
                        </div>
                    </div>
                    <div id="noChatSelected" style="display: block;">
                        <h4 style="color: var(--gray); font-weight: normal;">Pilih percakapan untuk memulai chat</h4>
                    </div>
                </div>
                <div class="chat-header-actions" id="chatActions" style="display: none;">
                    <button class="chat-action-btn" title="Info Percakapan">
                        <i class="fas fa-info-circle"></i>
                    </button>
                </div>
            </div>

            <!-- Chat Messages -->
            <div class="chat-messages" id="chatMessages">
                <!-- Messages will be loaded dynamically -->
                <div class="empty-state" id="emptyMessages">
                    <i class="fas fa-comment-alt"></i>
                    <h3>Belum ada pesan</h3>
                    <p>Kirim pesan untuk memulai percakapan</p>
                </div>
            </div>

            <!-- Chat Input Area -->
            <div class="chat-input-area" id="chatInputArea" style="display: none;">
                <div class="chat-input-container">
                    <div class="chat-input-wrapper">
                        <textarea class="chat-input" id="messageInput" placeholder="Ketik pesan..." rows="1"></textarea>
                    </div>
                    <button class="send-btn" id="sendButton" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden input for file upload -->
    <input type="file" id="fileUpload" style="display: none;" accept="image/*,.pdf,.doc,.docx,.txt">
    
    <!-- Include Socket.io client library -->
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    
<script>
    // ============================================
    // GLOBAL VARIABLES & CONFIGURATION
    // ============================================
    
    // Data user saat ini dari PHP
    const currentUser = {
        id: <?php echo $user_id; ?>,
        name: "<?php echo htmlspecialchars($username); ?>",
        role: "<?php echo htmlspecialchars($role); ?>",
        initials: "<?php echo $initials; ?>",
        profilePic: "<?php echo $has_image ? $gambar_path : ''; ?>",
        hasImage: <?php echo $has_image ? 'true' : 'false'; ?>
    };
    
    // Data chat otomatis dari session PHP
    const autoChatData = <?php 
        if (isset($_SESSION['auto_chat_data'])) {
            echo json_encode($_SESSION['auto_chat_data']);
        } else {
            echo 'null';
        }
    ?>;
    
    // Kontak dari PHP
    const contactsFromPHP = <?php echo json_encode($contacts); ?>;
    
    // API endpoint - PERBAIKAN: Gunakan path yang benar
    const API_URL = 'chat_api.php';
    
    // State variables
    let selectedContact = null;
    let isConnected = true; // Selalu true karena menggunakan AJAX
    let pollingInterval = null;
    
    // DOM Elements
    const chatContacts = document.getElementById('chatContacts');
    const chatMessages = document.getElementById('chatMessages');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendButton');
    const toggleSidebarBtn = document.getElementById('toggleSidebar');
    const chatSidebar = document.getElementById('chatSidebar');
    const searchChatInput = document.getElementById('searchChat');
    const connectionStatus = document.getElementById('connectionStatus');
    const onlineStatus = document.getElementById('onlineStatus');
    const emptyContacts = document.getElementById('emptyContacts');
    const emptyMessages = document.getElementById('emptyMessages');
    const chatInputArea = document.getElementById('chatInputArea');
    const currentUserInfo = document.getElementById('currentUserInfo');
    const noChatSelected = document.getElementById('noChatSelected');
    const chatActions = document.getElementById('chatActions');
    
    // ============================================
    // INITIALIZATION
    // ============================================
    
    document.addEventListener('DOMContentLoaded', function() {
        initResponsive();
        initializeChatConnection();
        
        // Setup event listeners
        setupEventListeners();
        
        // Auto-select contact if from status page
        setTimeout(() => {
            autoSelectContactFromParams();
        }, 1000);
    });
    
    function initResponsive() {
        if (window.innerWidth <= 768) {
            if (toggleSidebarBtn) toggleSidebarBtn.style.display = 'block';
            chatSidebar.classList.add('active');
        } else {
            if (toggleSidebarBtn) toggleSidebarBtn.style.display = 'none';
            chatSidebar.classList.remove('active');
        }
    }
    
    function setupEventListeners() {
        // Toggle sidebar on mobile
        if (toggleSidebarBtn) {
            toggleSidebarBtn.addEventListener('click', () => {
                chatSidebar.classList.toggle('active');
            });
        }
        
        // Send message on button click
        sendButton.addEventListener('click', sendMessage);
        
        // Send message on Enter key (Shift+Enter for new line)
        messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        // Auto-resize textarea
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            
            // Enable/disable send button
            sendButton.disabled = this.value.trim() === '';
        });
        
        // Search contacts
        if (searchChatInput) {
            searchChatInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const contacts = document.querySelectorAll('.chat-contact');
                
                contacts.forEach(contact => {
                    const name = contact.querySelector('.contact-name').textContent.toLowerCase();
                    const message = contact.querySelector('.contact-last-message').textContent.toLowerCase();
                    
                    if (name.includes(searchTerm) || message.includes(searchTerm)) {
                        contact.style.display = 'flex';
                    } else {
                        contact.style.display = 'none';
                    }
                });
            });
        }
        
        // Back button
        document.querySelector('.back-button').addEventListener('click', () => {
            window.location.href = 'dashboard.php';
        });
    }
    
    // ============================================
    // CHAT CONNECTION - PERBAIKAN KONEKSI
    // ============================================
    
    function initializeChatConnection() {
        updateOnlineStatus('Online');
        updateConnectionStatus('connected', 'Chat Siap');
        
        // Start polling for new messages
        startPolling();
    }
    
    function startPolling() {
        // Check for new messages every 3 seconds
        if (pollingInterval) {
            clearInterval(pollingInterval);
        }
        
        pollingInterval = setInterval(() => {
            if (selectedContact) {
                loadNewMessages();
            }
        }, 3000);
    }
    
    function updateOnlineStatus(status) {
        const isOnline = status === 'Online';
        onlineStatus.innerHTML = `
            <i class="fas fa-circle" style="color: ${isOnline ? '#28a745' : '#dc3545'}; font-size: 0.7rem;"></i>
            ${status}
        `;
    }
    
    function updateConnectionStatus(status, text) {
        connectionStatus.className = `connection-status ${status}`;
        
        let icon = 'fa-circle';
        let iconColor = '#6c757d';
        
        switch(status) {
            case 'connected':
                icon = 'fa-check-circle';
                iconColor = '#28a745';
                break;
            case 'disconnected':
                icon = 'fa-times-circle';
                iconColor = '#dc3545';
                break;
            case 'connecting':
                icon = 'fa-sync-alt fa-spin';
                iconColor = '#ffc107';
                break;
        }
        
        connectionStatus.innerHTML = `<i class="fas ${icon}" style="color: ${iconColor}"></i><span>${text}</span>`;
        connectionStatus.style.display = 'block';
        
        if (status === 'connected') {
            setTimeout(() => {
                connectionStatus.style.display = 'none';
            }, 3000);
        }
    }
    
    // ============================================
    // CONTACT MANAGEMENT
    // ============================================
    
    function selectContactFromPHP(contact) {
        selectContact(contact);
    }
    
    function selectContact(contact) {
        if (!contact) return;
        
        // Update selected contact
        selectedContact = contact;
        
        // Update UI
        document.querySelectorAll('.chat-contact').forEach(el => {
            el.classList.remove('active');
        });
        
        const contactElement = document.querySelector(`.chat-contact[data-user-id="${contact.contact_id}"]`);
        if (contactElement) {
            contactElement.classList.add('active');
            
            // Remove unread badge
            const unreadBadge = contactElement.querySelector('.contact-unread');
            if (unreadBadge) {
                unreadBadge.remove();
            }
        }
        
        // Update chat header
        updateChatHeader(contact);
        
        // Add product info if available
        if (autoChatData && autoChatData.penjual_id == contact.contact_id) {
            updateChatHeaderWithProduct(contact, autoChatData.produk_nama);
        }
        
        // Show chat area
        chatInputArea.style.display = 'block';
        currentUserInfo.style.display = 'flex';
        noChatSelected.style.display = 'none';
        chatActions.style.display = 'flex';
        emptyMessages.style.display = 'none';
        
        // Load chat history
        loadChatHistory(contact.contact_id);
        
        // Mark messages as read
        markAsRead(contact.contact_id);
        
        // Hide sidebar on mobile
        if (window.innerWidth <= 768) {
            chatSidebar.classList.remove('active');
        }
    }
    
    function updateChatHeader(contact) {
        document.getElementById('currentUserName').textContent = contact.contact_name;
        
        // Set avatar - PERBAIKAN: Gunakan gambar jika ada
        const avatar = document.getElementById('currentUserAvatar');
        if (contact.contact_has_image && contact.contact_image_path) {
            avatar.innerHTML = `<img src="${contact.contact_image_path}" alt="${contact.contact_name}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
        } else {
            const name = contact.contact_name || 'User';
            const initials = name.split(' ').map(word => word.charAt(0).toUpperCase()).join('').substring(0, 2);
            avatar.textContent = initials;
        }
        
        // Set status (assume online for now)
        const status = document.getElementById('currentUserStatus');
        status.className = 'chat-user-status';
        status.innerHTML = `<i class="fas fa-circle" style="font-size: 0.7rem;"></i><span>Online</span>`;
    }
    
    function updateChatHeaderWithProduct(contact, produkNama) {
        const chatUserDetails = document.querySelector('.chat-user-details');
        
        if (chatUserDetails && produkNama) {
            // Remove existing product info
            const existingProductInfo = chatUserDetails.querySelector('.chat-product-info');
            if (existingProductInfo) {
                existingProductInfo.remove();
            }
            
            // Add new product info
            const productInfo = document.createElement('div');
            productInfo.className = 'chat-product-info';
            productInfo.innerHTML = `
                <i class="fas fa-box" style="font-size: 0.7rem;"></i>
                <span>Produk: ${produkNama}</span>
            `;
            
            // Insert after the h4
            const userNameH4 = chatUserDetails.querySelector('h4');
            if (userNameH4) {
                userNameH4.parentNode.insertBefore(productInfo, userNameH4.nextSibling);
            }
        }
    }
    
    // ============================================
    // MESSAGE MANAGEMENT - PERBAIKAN ERROR HANDLING
    // ============================================
    
    async function loadChatHistory(otherUserId) {
        if (!selectedContact) return;
        
        try {
            // PERBAIKAN: Tambahkan timeout dan error handling
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);
            
            const response = await fetch(
                `${API_URL}?action=get-messages&other_user_id=${otherUserId}`,
                {
                    signal: controller.signal
                }
            );
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                displayMessages(data.messages);
            } else {
                console.warn('Peringatan:', data.message);
                // Tetap tampilkan UI kosong, jangan error
                chatMessages.innerHTML = `
                    <div class="empty-state" id="emptyMessages">
                        <i class="fas fa-comment-alt"></i>
                        <h3>Belum ada pesan</h3>
                        <p>Kirim pesan untuk memulai percakapan</p>
                    </div>
                `;
            }
        } catch (error) {
            console.warn('Peringatan koneksi:', error.message);
            // Jangan tampilkan error, biarkan UI tetap berfungsi
            chatMessages.innerHTML = `
                <div class="empty-state" id="emptyMessages">
                    <i class="fas fa-comment-alt"></i>
                    <h3>Belum ada pesan</h3>
                    <p>Kirim pesan untuk memulai percakapan</p>
                </div>
            `;
        }
    }
    
    async function loadNewMessages() {
        if (!selectedContact) return;
        
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 3000);
            
            const response = await fetch(
                `${API_URL}?action=get-messages&other_user_id=${selectedContact.contact_id}&check_new=true`,
                {
                    signal: controller.signal
                }
            );
            
            clearTimeout(timeoutId);
            
            if (!response.ok) return;
            
            const data = await response.json();
            
            if (data.success && data.messages && data.messages.length > 0) {
                // Check for new messages
                const existingIds = new Set(
                    Array.from(chatMessages.querySelectorAll('.message'))
                        .map(msg => msg.dataset.messageId)
                        .filter(id => id)
                );
                
                let hasNewMessages = false;
                data.messages.forEach(msg => {
                    if (!existingIds.has(msg.id_chat.toString()) && !msg.is_me) {
                        displayMessage(msg, false);
                        hasNewMessages = true;
                    }
                });
                
                if (hasNewMessages) {
                    // Scroll to bottom
                    scrollToBottom();
                }
            }
        } catch (error) {
            // Silent fail untuk polling
            console.log('Polling timeout/error (normal untuk polling):', error.message);
        }
    }
    
    function displayMessages(messages) {
        // Clear messages
        chatMessages.innerHTML = '';
        
        if (!messages || messages.length === 0) {
            chatMessages.innerHTML = `
                <div class="empty-state" id="emptyMessages">
                    <i class="fas fa-comment-alt"></i>
                    <h3>Belum ada pesan</h3>
                    <p>Kirim pesan untuk memulai percakapan</p>
                </div>
            `;
            return;
        }
        
        // Group messages by date
        const messagesByDate = {};
        messages.forEach(msg => {
            const date = msg.date || new Date(msg.waktu_kirim).toLocaleDateString('id-ID');
            if (!messagesByDate[date]) {
                messagesByDate[date] = [];
            }
            messagesByDate[date].push(msg);
        });
        
        // Display messages
        Object.keys(messagesByDate).forEach(date => {
            // Add date separator
            const dateElement = document.createElement('div');
            dateElement.className = 'message-date';
            dateElement.innerHTML = `<div class="date-label">${date}</div>`;
            chatMessages.appendChild(dateElement);
            
            // Add messages for this date
            messagesByDate[date].forEach(msg => {
                displayMessage(msg, true);
            });
        });
        
        // Scroll to bottom
        scrollToBottom();
    }
    
    function displayMessage(msg, isInitialLoad = true) {
        const isMe = msg.id_pengirim == currentUser.id;
        
        // Create message element
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${isMe ? 'sent' : 'received'}`;
        messageDiv.dataset.messageId = msg.id_chat;
        
        // Avatar dengan initials atau gambar
        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'message-avatar';
        
        const senderName = msg.sender_name || (isMe ? currentUser.name : selectedContact.contact_name);
        
        if (isMe && currentUser.hasImage) {
            // Avatar untuk pengirim (user sendiri)
            avatarDiv.innerHTML = `<img src="${currentUser.profilePic}" alt="${senderName}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
        } else if (!isMe && selectedContact && selectedContact.contact_has_image && selectedContact.contact_image_path) {
            // Avatar untuk penerima (kontak)
            avatarDiv.innerHTML = `<img src="${selectedContact.contact_image_path}" alt="${senderName}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
        } else {
            // Gunakan initials
            const initials = senderName.split(' ').map(word => word.charAt(0).toUpperCase()).join('').substring(0, 2);
            avatarDiv.textContent = initials;
        }
        
        // Message content
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        const bubbleDiv = document.createElement('div');
        bubbleDiv.className = 'message-bubble';
        
        const textDiv = document.createElement('div');
        textDiv.className = 'message-text';
        textDiv.textContent = msg.isi_pesan;
        
        const timeDiv = document.createElement('div');
        timeDiv.className = 'message-time';
        timeDiv.textContent = msg.time || new Date(msg.waktu_kirim).toLocaleTimeString('id-ID', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
        
        // Add read status for sent messages
        if (isMe) {
            const statusSpan = document.createElement('span');
            statusSpan.style.marginLeft = '5px';
            statusSpan.style.fontSize = '0.7rem';
            statusSpan.innerHTML = msg.status_baca === 'terbaca' ? '✓✓' : '✓';
            timeDiv.appendChild(statusSpan);
        }
        
        bubbleDiv.appendChild(textDiv);
        bubbleDiv.appendChild(timeDiv);
        contentDiv.appendChild(bubbleDiv);
        
        // Assemble message
        if (isMe) {
            messageDiv.appendChild(contentDiv);
            messageDiv.appendChild(avatarDiv);
        } else {
            messageDiv.appendChild(avatarDiv);
            messageDiv.appendChild(contentDiv);
        }
        
        // Add to chat
        if (isInitialLoad) {
            chatMessages.appendChild(messageDiv);
        } else {
            // For new messages, add at the end
            const lastDate = chatMessages.querySelector('.message-date:last-child');
            if (lastDate) {
                lastDate.after(messageDiv);
            } else {
                chatMessages.appendChild(messageDiv);
            }
        }
    }
    
    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // ============================================
    // SEND MESSAGE - PERBAIKAN ERROR HANDLING
    // ============================================
    
    async function sendMessage() {
        const message = messageInput.value.trim();
        
        if (!message || !selectedContact) {
            return;
        }
        
        // Disable send button and show loading
        const originalHTML = sendButton.innerHTML;
        sendButton.disabled = true;
        sendButton.innerHTML = '<div class="loading-spinner"></div>';
        
        try {
            // Prepare data
            const formData = new FormData();
            formData.append('action', 'send-message');
            formData.append('receiver_id', selectedContact.contact_id);
            formData.append('message', message);
            formData.append('message_type', 'teks');
            
            // Add product and transaction info if available
            if (autoChatData) {
                formData.append('produk_id', 0);
                formData.append('transaksi_id', autoChatData.transaksi_id || 0);
            }
            
            // Send via AJAX dengan timeout
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000);
            
            const response = await fetch(API_URL, {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                // Display sent message
                const messageObj = {
                    id_chat: data.data.id_chat,
                    id_pengirim: currentUser.id,
                    id_penerima: selectedContact.contact_id,
                    isi_pesan: message,
                    waktu_kirim: new Date().toISOString(),
                    time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
                    sender_name: currentUser.name,
                    status_baca: 'terkirim',
                    is_me: true
                };
                
                displayMessage(messageObj, false);
                
                // Clear input
                messageInput.value = '';
                messageInput.style.height = 'auto';
                
                // Scroll to bottom
                scrollToBottom();
                
                // Update contact list last message
                updateContactLastMessage(selectedContact.contact_id, message);
            } else {
                alert('Gagal mengirim pesan: ' + (data.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Gagal mengirim pesan. Coba lagi nanti.');
        } finally {
            // Reset send button
            sendButton.disabled = false;
            sendButton.innerHTML = originalHTML;
        }
    }
    
    function updateContactLastMessage(contactId, message) {
        const contactElement = document.querySelector(`.chat-contact[data-user-id="${contactId}"]`);
        if (contactElement) {
            const lastMessageEl = contactElement.querySelector('.contact-last-message');
            if (lastMessageEl) {
                // Truncate long messages
                const truncated = message.length > 30 ? message.substring(0, 30) + '...' : message;
                lastMessageEl.textContent = truncated;
            }
            
            // Update time
            const timeEl = contactElement.querySelector('.contact-time');
            if (timeEl) {
                const now = new Date();
                timeEl.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
            }
            
            // Move contact to top
            const contactsContainer = contactElement.parentElement;
            contactsContainer.insertBefore(contactElement, contactsContainer.firstChild);
        }
    }
    
    // ============================================
    // AUTO-SELECT CONTACT
    // ============================================
    
    function autoSelectContactFromParams() {
        // Check URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const penjualId = urlParams.get('penjual_id');
        
        if (penjualId) {
            // Check if contact exists in list
            const contactElement = document.querySelector(`.chat-contact[data-user-id="${penjualId}"]`);
            
            if (contactElement) {
                // Find contact in contactsFromPHP
                const contact = contactsFromPHP.find(c => c.contact_id == penjualId);
                if (contact) {
                    // Add product info to contact
                    if (autoChatData) {
                        contact.produk_nama = autoChatData.produk_nama;
                        contact.transaksi_id = autoChatData.transaksi_id;
                    }
                    
                    // Click contact after delay
                    setTimeout(() => {
                        contactElement.click();
                    }, 500);
                }
            } else if (autoChatData) {
                // Create virtual contact if not in list
                const virtualContact = {
                    contact_id: parseInt(penjualId),
                    contact_name: autoChatData.penjual_name,
                    contact_role: 'penjual',
                    contact_image: '',
                    contact_has_image: false,
                    contact_image_path: '',
                    last_message: 'Mulai percakapan baru',
                    last_message_time: 'Sekarang',
                    unread_count: 0,
                    produk_nama: autoChatData.produk_nama,
                    transaksi_id: autoChatData.transaksi_id
                };
                
                // Add to contacts list
                addContactToList(virtualContact);
                
                // Select after delay
                setTimeout(() => {
                    selectContact(virtualContact);
                }, 500);
            }
        }
    }
    
    function addContactToList(contact) {
        // Hide empty state
        if (emptyContacts) {
            emptyContacts.style.display = 'none';
        }
        
        // Create contact element
        const contactDiv = document.createElement('div');
        contactDiv.className = 'chat-contact';
        contactDiv.setAttribute('data-user-id', contact.contact_id);
        contactDiv.onclick = () => selectContact(contact);
        
        // Generate avatar
        let avatarHTML = '';
        if (contact.contact_has_image && contact.contact_image_path) {
            avatarHTML = `<img src="${contact.contact_image_path}" alt="${contact.contact_name}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
        } else {
            const name = contact.contact_name || 'User';
            const initials = name.split(' ').map(word => word.charAt(0).toUpperCase()).join('').substring(0, 2);
            avatarHTML = initials;
        }
        
        // Role badge color
        let roleColor = '#6c757d';
        if (contact.contact_role === 'penjual') roleColor = '#9b59b6';
        if (contact.contact_role === 'admin') roleColor = '#e74c3c';
        if (contact.contact_role === 'pembeli') roleColor = '#3498db';
        
        contactDiv.innerHTML = `
            <div class="contact-avatar" style="background: ${roleColor}">
                ${avatarHTML}
            </div>
            <div class="contact-info">
                <div class="contact-name">${contact.contact_name}</div>
                <div class="contact-role">${contact.contact_role}</div>
                <div class="contact-last-message">${contact.last_message}</div>
            </div>
            <div class="contact-meta">
                <div class="contact-time">${contact.last_message_time}</div>
                ${contact.unread_count > 0 ? `<div class="contact-unread">${contact.unread_count}</div>` : ''}
            </div>
        `;
        
        // Add to top of contacts list
        chatContacts.insertBefore(contactDiv, chatContacts.firstChild);
    }
    
    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    
    async function markAsRead(otherUserId) {
        try {
            const formData = new FormData();
            formData.append('action', 'mark-as-read');
            formData.append('other_user_id', otherUserId);
            
            await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
        } catch (error) {
            console.log('Error marking as read (non-critical):', error.message);
        }
    }
    
    // Window resize handler
    window.addEventListener('resize', initResponsive);
    
    // Add CSS for animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .message {
            animation: slideIn 0.3s ease;
        }
        
        /* Fix untuk gambar avatar */
        .contact-avatar img,
        .chat-user-avatar img,
        .message-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }
    `;
    document.head.appendChild(style);
</script>
</body>
</html>