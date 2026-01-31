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
    <title>WESLEY BOOKSTORE - Dashboard</title>
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
        
        /* Main Content */
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
        
        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            border-radius: 15px;
            padding: 40px;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: white;
            box-shadow: 0 10px 30px rgba(74, 144, 226, 0.3);
        }
        
        .welcome-text {
            flex: 1;
        }
        
        .welcome-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            line-height: 1.2;
        }
        
        .welcome-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        /* PERBAIKAN LOGO DI WELCOME CARD: Hilangkan background */
        .welcome-logo {
            width: 150px;
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent !important; /* Pastikan tidak ada background */
        }
        
        .welcome-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            background: transparent !important; /* Pastikan tidak ada background */
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-content {
            flex-grow: 1;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .stat-change {
            font-size: 0.85rem;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .stat-change.positive {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }
        
        .stat-change.negative {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
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
            
            .search-bar {
                width: 300px;
            }
            
            .welcome-card {
                flex-direction: column;
                text-align: center;
                padding: 30px;
            }
            
            .welcome-logo {
                margin-top: 20px;
                width: 120px;
                height: 120px;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-title {
                font-size: 1.8rem;
            }
            
            .logo-text {
                font-size: 1.5rem;
            }
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
            
            .welcome-card {
                padding: 20px;
            }
            
            .welcome-title {
                font-size: 1.5rem;
            }
            
            .welcome-subtitle {
                font-size: 0.9rem;
            }
            
            .logo-img {
                height: 35px;
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
            <div class="user-profile" id="userProfile">
                <div class="user-info">
        <div class="avatar">
            <?php
            // PRIORITAS: Ambil dari session dulu, jika tidak ada baru dari database
            $current_gambar = isset($_SESSION['gambar']) && !empty($_SESSION['gambar']) ? $_SESSION['gambar'] : $user['gambar'];
            
            // Path lengkap ke file gambar
            $gambar_file = $current_gambar ?: 'default.png';
            $gambar_path = '../asset/' . htmlspecialchars($gambar_file);
            
            // Cek apakah file benar-benar ada
            $file_exists = file_exists($gambar_path);
            
            // Cache busting: gunakan timestamp file jika ada, atau current time
            $cache_buster = $file_exists ? '?t=' . filemtime($gambar_path) : '?t=' . time();
            
            // Jika ada file gambar custom
            if ($current_gambar && $current_gambar != 'default.png' && $file_exists) {
                echo '<img src="' . $gambar_path . $cache_buster . '" 
                        alt="Profile" 
                        style="width:100%;height:100%;border-radius:50%;object-fit:cover;"
                        onerror="this.onerror=null; this.src=\'../asset/default.png?t=\' + new Date().getTime();">';
            } else {
                // Fallback ke default atau initials
                echo strtoupper(substr($_SESSION['username'], 0, 2));
            }
            ?>
        </div>               
                    <div class="user-details">
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

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-title">Main</div>
                <a href="index.php" class="sidebar-item active">
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
                <a href="help.php" class="sidebar-item">
                    <i class="fas fa-question-circle"></i>
                    <span>Help Center</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="content-header">
            <h1 class="page-title">Dashboard Super Admin</h1>
            <div class="breadcrumb">
                <a href="index.php">Home</a>
                <i class="fas fa-chevron-right"></i>
                <span>Dashboard</span>
            </div>
        </div>

        <!-- Welcome Card -->
        <div class="welcome-card">
            <div class="welcome-text">
                <h2 class="welcome-title">Wesley Bookstore selalu di depan untuk melayani Anda</h2>
                <p class="welcome-subtitle">Selamat datang, <?php echo htmlspecialchars($username); ?>! Mari kita kelola toko buku dengan lebih baik.</p>
            </div>
            <div class="welcome-logo">
                <img src="../asset/wesley.png" alt="Wesley Bookstore Logo">
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
            
            // Ganti ikon menu
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

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target) &&
                !sidebar.classList.contains('collapsed')) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                menuToggle.querySelector('i').className = 'fas fa-bars';
            }
        });

        // Aktifkan sidebar item saat diklik
        document.querySelectorAll('.sidebar-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>