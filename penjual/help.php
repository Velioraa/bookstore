<?php
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

    $conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Help</title>
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
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        /* Styles khusus untuk Help Center */
        .help-header {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(74, 144, 226, 0.3);
        }

        .help-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.2;
        }

        .help-header p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .search-help {
            margin: 30px auto;
            max-width: 600px;
        }

        .search-help input {
            width: 100%;
            padding: 15px 50px 15px 20px;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .search-help input:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
        }

        /* Content Section */
        .content-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
        }

        /* FAQ Accordion */
        .faq-item {
            background: white;
            border-radius: 10px;
            margin-bottom: 15px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }

        .faq-question {
            padding: 20px;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
        }

        .faq-question:hover {
            background: #f8f9fa;
        }

        .faq-answer {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            color: var(--gray);
            line-height: 1.6;
        }

        .faq-item.active .faq-answer {
            padding: 0 20px 20px;
            max-height: 500px;
        }

        .faq-toggle {
            color: var(--secondary);
            transition: transform 0.3s ease;
        }

        .faq-item.active .faq-toggle {
            transform: rotate(180deg);
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

            .help-header {
                padding: 30px;
            }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 0 15px;
            }

            .user-details {
                display: none;
            }

            .help-header h1 {
                font-size: 1.8rem;
            }

            .help-header p {
                font-size: 1rem;
            }

            .logo-text {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 20px 15px;
            }

            .logo-text {
                font-size: 1.5rem;
            }

            .help-header {
                padding: 20px;
            }

            .help-header h1 {
                font-size: 1.5rem;
            }

            .content-section {
                padding: 20px;
            }

            .page-title {
                font-size: 1.5rem;
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
                        <span>Profil</span>
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
                <div class="nav-title">Utama</div>
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
                <div class="nav-title">Manajemen Produk</div>
                <a href="produk.php" class="sidebar-item">
                    <i class="fas fa-box"></i>
                    <span>Produk</span>
                </a>
                <?php if ($role == 'penjual' || $role == 'super_admin'): ?>
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
                <div class="nav-title">Dukungan</div>
                <a href="help.php" class="sidebar-item active">
                    <i class="fas fa-question-circle"></i>
                    <span>Help</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="content-header">
            <h1 class="page-title">Help</h1>
            <div class="breadcrumb">
                <a href="dashboard.php">Beranda</a>
                <i class="fas fa-chevron-right"></i>
                <span>Help</span>
            </div>
        </div>

        <!-- FAQ Section -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">FAQ</h2>
            </div>

            <!-- Pertanyaan 1 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana cara pembeli mengetahui apakah pesanan mereka sudah diproses oleh penjual?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Pembeli dapat mengecek menu Status yang menampilkan daftar transaksi, baik yang sedang diproses
                        maupun yang sudah selesai. Selain itu, pembeli akan menerima notifikasi khusus yang
                        memberitahukan apakah pesanan mereka di-Approve (disetujui) atau Ditolak oleh penjual.</p>
                </div>
            </div>

            <!-- Pertanyaan 2 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana sistem memastikan bukti pembayaran saya tidak disalahgunakan?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Wesley Bookstore mengenkripsi file bukti pembayaran, membatasi akses hanya untuk tim verifikasi,
                        dan menyamarkan sebagian data sensitif (misalnya nomor rekening). Bukti hanya dipakai untuk
                        validasi transaksi dan tidak dibagikan ke pihak lain.</p>
                </div>
            </div>

            <!-- Pertanyaan 3 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana Penjual mengelola stok dan memantau keuntungan dari buku yang mereka jual?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Penjual dapat melakukan CRUD produk yang mereka input sendiri, dan jika stok buku sudah mencapai
                        0, penjual dapat menghapus data buku tersebut. Dalam menu detail produk, penjual bisa melihat
                        informasi lengkap mulai dari modal, margin, hingga keuntungan yang didapatkan dari setiap buku.
                    </p>
                </div>
            </div>

            <!-- Pertanyaan 4 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Apa saja batasan akses yang dimiliki Super Admin dalam mengelola database pengguna?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Super Admin memiliki wewenang penuh (CRUD) untuk mengelola akun Penjual dan Kategori Buku.
                        Namun, untuk akun Pembeli, Super Admin tidak bisa menambah akun baru secara langsung (registrasi
                        dilakukan oleh pembeli sendiri) dan hanya memiliki akses untuk melakukan update (edit), delete
                        (hapus), serta melihat data saja. Super Admin juga bertanggung jawab menghapus akun penjual atau
                        pembeli yang statusnya sudah tidak aktif.</p>
                </div>
            </div>

            <!-- Pertanyaan 5 -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Fitur apa yang dapat digunakan Penjual untuk memantau performa penjualan secara visual?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Penjual dapat mengakses menu Laporan Penjual yang dilengkapi dengan fitur sortir berdasarkan
                        bulan dan tahun. Sistem ini akan secara otomatis menampilkan grafik garis penjualan yang
                        dihitung berdasarkan margin dan keuntungan dari buku-buku yang terjual. Selain itu, laporan ini
                        juga tersedia dalam format yang dapat diunduh (download).</p>
                </div>
            </div>

            <!-- FAQ tambahan -->
            <div class="faq-item">
                <div class="faq-question">
                    <span>Bagaimana cara saya mengubah informasi profil saya?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Untuk mengubah informasi profil, buka menu profil Anda melalui ikon pengguna di pojok kanan atas,
                        lalu pilih "Profil". Di halaman tersebut, Anda dapat memperbarui informasi pribadi, foto profil,
                        dan preferensi akun Anda.</p>
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question">
                    <span>Apa yang harus saya lakukan jika lupa password?</span>
                    <i class="fas fa-chevron-down faq-toggle"></i>
                </div>
                <div class="faq-answer">
                    <p>Di halaman login, klik tautan "Lupa Password". Anda akan diminta untuk memasukkan alamat email
                        yang terdaftar. Sistem akan mengirimkan tautan reset password ke email Anda. Ikuti instruksi
                        dalam email untuk membuat password baru.</p>
                </div>
            </div>
        </div>
    </main>

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
            logoutLink.addEventListener('click', function (e) {
                e.preventDefault();
                if (confirm('Apakah Anda yakin ingin keluar?')) {
                    window.location.href = this.href;
                }
            });
        }

        // FAQ Accordion
        document.querySelectorAll('.faq-question').forEach(question => {
            question.addEventListener('click', () => {
                const item = question.parentElement;
                item.classList.toggle('active');
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