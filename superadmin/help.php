<?php
session_start();

// Cek apakah user sudah login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Cek apakah role user adalah super_admin
if ($_SESSION['role'] !== 'super_admin') {
    // Redirect ke halaman sesuai role
    switch ($_SESSION['role']) {
        case 'penjual':
            header("Location: ../penjual/dashboard.php");
            break;
        case 'pembeli':
            header("Location: ../pembeli/dashboard.php");
            break;
        default:
            header("Location: ../login.php");
            break;
    }
    exit();
}

// Koneksi ke database
require_once '../koneksi.php';

// Ambil data user dari database untuk informasi terbaru
$user_id = $_SESSION['user_id'];
$query = "SELECT username, email, nik, gambar FROM users WHERE id_user = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $username = $user['username'];
    $email = $user['email'];
    $nik = $user['nik'];
} else {
    // Jika user tidak ditemukan, logout
    session_destroy();
    header("Location: ../login.php");
    exit();
}

$stmt->close();

// Ambil inisial untuk avatar
$initials = strtoupper(substr($username, 0, 2));
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Help Center</title>
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
        
        /* Top Navigation - SAMA PERSIS dengan dashboard.php */
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
            height: 45px;
            width: auto;
            object-fit: contain;
        }
        
        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--secondary);
            letter-spacing: 0.5px;
        }
        
        .search-bar {
            position: relative;
            width: 400px;
        }
        
        .search-bar input {
            width: 100%;
            padding: 12px 45px 12px 20px;
            border: 2px solid #e1e5eb;
            border-radius: 25px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }
        
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            cursor: pointer;
        }
        
        .nav-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .user-profile {
            position: relative;
            cursor: pointer;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
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
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        .user-role {
            font-size: 0.85rem;
            color: var(--gray);
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
        
        /* Sidebar - SAMA PERSIS dengan dashboard.php */
        .sidebar {
            position: fixed;
            left: 0;
            top: 70px;
            bottom: 0;
            width: var(--sidebar-width);
            background: white;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            padding: 30px 0;
            overflow-y: hidden; 
            overflow-x: hidden;
            transform: translateX(0);
            transition: transform 0.3s ease;
            z-index: 999;
        }
        
        .sidebar.collapsed {
            transform: translateX(-100%);
        }
        
        .sidebar-nav {
            padding: 0 20px;
            height: 100%;
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
        
        /* Main Content - SAMA PERSIS dengan dashboard.php */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: 70px;
            padding: 30px;
            min-height: calc(100vh - 70px);
            transition: margin-left 0.3s ease;
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
        
        /* FAQ Accordion - DIUBAH untuk memastikan klik berfungsi */
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
            cursor: pointer; /* Pastikan cursor berubah menjadi pointer */
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s ease;
            -webkit-tap-highlight-color: transparent; /* Untuk mobile */
            user-select: none; /* Mencegah seleksi teks */
        }
        
        .faq-question:hover {
            background: #f8f9fa;
        }
        
        .faq-question:active {
            background: #e9ecef; /* Feedback saat diklik */
        }
        
        .faq-answer {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s ease, padding 0.5s ease;
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
        
        /* Contact Section */
        .contact-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .contact-title {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--primary);
        }
        
        .contact-desc {
            color: var(--gray);
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .contact-methods {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .contact-method {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 10px;
            min-width: 200px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .contact-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: white;
            font-size: 1.5rem;
        }
        
        .contact-method-title {
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .contact-method-detail {
            color: var(--gray);
            font-size: 0.95rem;
        }
        
        .contact-btn {
            display: inline-block;
            margin-top: 30px;
            padding: 15px 30px;
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .contact-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(74, 144, 226, 0.3);
        }
        
        /* Responsive - SAMA PERSIS dengan dashboard.php */
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
            
            .search-bar {
                width: 300px;
            }
            
            .help-header {
                padding: 30px;
            }
        }
        
        @media (max-width: 768px) {
            .top-nav {
                padding: 0 15px;
            }
            
            .search-bar {
                width: 200px;
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
            
            .contact-methods {
                flex-direction: column;
                align-items: center;
            }
            
            .contact-method {
                width: 100%;
                max-width: 300px;
            }
            
            .logo-text {
                font-size: 1.5rem;
            }
        }

        ::-webkit-scrollbar {
            width: 0;
            height: 0;
        }

        * {
            scrollbar-width: none;
        }

        * {
            -ms-overflow-style: none;
        }
        
        @media (max-width: 576px) {
            .search-bar {
                display: none;
            }
            
            .main-content {
                padding: 20px 15px;
            }
            
            .logo-text {
                font-size: 1.3rem;
            }
            
            .help-header {
                padding: 20px;
            }
            
            .help-header h1 {
                font-size: 1.5rem;
            }
            
            .contact-section {
                padding: 30px 20px;
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
    <!-- Top Navigation - SAMA PERSIS dengan dashboard.php -->
    <nav class="top-nav">
        <div class="nav-left">
            <div class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </div>
            <div class="logo">
                <img src="../asset/wesley.png" alt="Wesley Bookstore Logo" class="logo-img">
                <span class="logo-text">WESLEY BOOKSTORE</span>
            </div>
            <div class="search-bar">
                <input type="text" placeholder="Type here to search books, authors, categories...">
                <i class="fas fa-search search-icon"></i>
            </div>
        </div>
        <div class="nav-right">
            <div class="user-profile" id="userProfile">
                <div class="user-info">
                    <div class="avatar">
                        <?php
                        $gambar_path = '../asset/' . htmlspecialchars($user['gambar'] ?: 'default.png');
                        if (file_exists($gambar_path) && $user['gambar'] && $user['gambar'] != 'default.png') {
                            echo '<img src="' . $gambar_path . '" alt="Profile" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">';
                        } else {
                            echo strtoupper(substr($username, 0, 2));
                        }
                        ?>
                    </div>                     <div class="user-details">
                        <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                        <span class="user-role"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?></span>
                    </div>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="dropdown" id="userDropdown">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user"></i>
                        <span>Profile</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Log Out</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar - SAMA PERSIS dengan dashboard.php -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Main</div>
                <a href="index.php" class="sidebar-item">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Management</div>
                <a href="categories.php" class="sidebar-item">
                    <i class="fas fa-tags"></i>
                    <span>Categories</span>
                </a>
                <a href="pembeli.php" class="sidebar-item">
                    <i class="fas fa-user"></i>
                    <span>Pembeli</span>
                </a>
                <a href="penjual.php" class="sidebar-item">
                    <i class="fas fa-user-cog"></i>
                    <span>Admin</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="nav-title">Support</div>
                <a href="help.php" class="sidebar-item active">
                    <i class="fas fa-question-circle"></i>
                    <span>Help Center</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="content-header">
            <h1 class="page-title">Help Center</h1>
            <div class="breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Help Center</span>
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

            <!-- FAQ tambahan dalam bahasa Inggris (dapat dihapus atau diubah) -->
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
        // Toggle Sidebar - SAMA dengan dashboard.php
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

        // Notification icon click
        const notificationIcon = document.getElementById('notificationIcon');
        if (notificationIcon) {
            notificationIcon.addEventListener('click', () => {
                alert('Fitur notifikasi akan segera hadir!');
            });
        }

        // Logout confirmation
        const logoutLink = document.querySelector('a[href="../logout.php"]');
        if (logoutLink) {
            logoutLink.addEventListener('click', function (e) {
                e.preventDefault();
                if (confirm('Apakah Anda yakin ingin keluar?')) {
                    window.location.href = this.href;
                }
            });
        }

        // FAQ Accordion - FIXED VERSION
        document.addEventListener('DOMContentLoaded', function() {
            const faqQuestions = document.querySelectorAll('.faq-question');
            
            console.log('Jumlah FAQ ditemukan:', faqQuestions.length); // Debug
            
            faqQuestions.forEach(question => {
                console.log('Menambahkan event listener ke:', question); // Debug
                
                question.addEventListener('click', function(e) {
                    e.stopPropagation(); // Mencegah event bubbling
                    console.log('FAQ diklik:', this); // Debug
                    
                    // Cari parent .faq-item
                    const faqItem = this.closest('.faq-item');
                    
                    if (!faqItem) {
                        console.error('Tidak menemukan parent .faq-item');
                        return;
                    }
                    
                    console.log('Toggle FAQ:', faqItem); // Debug
                    
                    // Tutup semua FAQ lainnya
                    document.querySelectorAll('.faq-item').forEach(item => {
                        if (item !== faqItem && item.classList.contains('active')) {
                            item.classList.remove('active');
                        }
                    });
                    
                    // Toggle FAQ yang diklik
                    faqItem.classList.toggle('active');
                });
            });
            
            // Juga tambahkan event listener ke seluruh .faq-item sebagai backup
            document.querySelectorAll('.faq-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    // Cegah jika yang diklik adalah anak dari .faq-question
                    if (!e.target.closest('.faq-question')) {
                        const faqItem = this;
                        
                        // Tutup semua FAQ lainnya
                        document.querySelectorAll('.faq-item').forEach(item => {
                            if (item !== faqItem && item.classList.contains('active')) {
                                item.classList.remove('active');
                            }
                        });
                        
                        // Toggle FAQ yang diklik
                        faqItem.classList.toggle('active');
                    }
                });
            });
        });

        // Search functionality (basic)
        const searchInput = document.querySelector('.search-help input');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase();
                const faqItems = document.querySelectorAll('.faq-item');

                faqItems.forEach(item => {
                    const question = item.querySelector('.faq-question span').textContent.toLowerCase();
                    const answer = item.querySelector('.faq-answer').textContent.toLowerCase();

                    if (question.includes(searchTerm) || answer.includes(searchTerm)) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }
    </script>
</body>
</html>