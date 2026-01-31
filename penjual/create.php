<?php
// Koneksi ke database
require_once '../koneksi.php';

// Inisialisasi variabel pesan
$success_message = '';
$error_message = '';

// Proses registrasi jika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Ambil data dari form
    $nik = trim($_POST['nik']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $alamat = trim($_POST['alamat']);
    $role = $_POST['role'];
    
    // Validasi
    $errors = [];
    
    // Validasi NIK (16 digit)
    if (strlen($nik) != 16 || !is_numeric($nik)) {
        $errors[] = "NIK harus 16 digit angka.";
    }
    
    // Validasi username
    if (strlen($username) < 3) {
        $errors[] = "Username minimal 3 karakter.";
    }
    
    // Validasi email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Format email tidak valid.";
    }
    
    // Validasi password (TIDAK ADA BATASAN MINIMAL)
    if (empty($password)) {
        $errors[] = "Password tidak boleh kosong.";
    }
    
    // Validasi konfirmasi password
    if ($password !== $confirm_password) {
        $errors[] = "Password dan konfirmasi password tidak cocok.";
    }
    
    // Validasi alamat
    if (strlen($alamat) < 10) {
        $errors[] = "Alamat minimal 10 karakter.";
    }
    
    // Validasi role
    if (!in_array($role, ['penjual'])) {
        $errors[] = "Role tidak valid.";
    }
    
    // Cek apakah NIK sudah terdaftar
    if (empty($errors)) {
        $check_nik = "SELECT nik FROM users WHERE nik = ?";
        $stmt_check = $conn->prepare($check_nik);
        $stmt_check->bind_param("s", $nik);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $errors[] = "NIK sudah terdaftar.";
        }
        $stmt_check->close();
        
        // Cek apakah email sudah terdaftar
        $check_email = "SELECT email FROM users WHERE email = ?";
        $stmt_check = $conn->prepare($check_email);
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $errors[] = "Email sudah terdaftar.";
        }
        $stmt_check->close();
        
        // Cek apakah username sudah terdaftar
        $check_username = "SELECT username FROM users WHERE username = ?";
        $stmt_check = $conn->prepare($check_username);
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $errors[] = "Username sudah terdaftar.";
        }
        $stmt_check->close();
    }
    
    // Jika tidak ada error, simpan ke database
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $default_gambar = ($role == 'penjual') ? 'default_seller.png' : 'default_buyer.png';

        $query = "INSERT INTO users (nik, username, password, email, role, status, gambar, alamat, created_at) 
                VALUES (?, ?, ?, ?, ?, 'aktif', ?, ?, NOW())";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssssss", $nik, $username, $hashed_password, $email, $role, $default_gambar, $alamat);

        if ($stmt->execute()) {
            $success_message = "Data berhasil ditambahkan! Akun $role dengan username $username berhasil dibuat.";
            
            echo "<script>
                    setTimeout(function() {
                        window.location.href = 'penjual.php';
                    }, 2000);
                  </script>";
        } else {
            $error_message = "Terjadi kesalahan: " . $stmt->error;
        }

        $stmt->close();
    } else {
        $error_message = $errors[0];
    }  
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WESLEY BOOKSTORE - Daftar</title>
    <link rel="website icon" type="image/png" href="../asset/wesley.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #2c3e50;
            --secondary-blue: #3498db;
            --light-blue: #ecf0f9;
            --white: #ffffff;
            --gray: #f5f5f5;
            --dark-gray: #7f8c8d;
            --error: #e74c3c;
            --success: #2ecc71;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: var(--light-blue);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            max-width: 500px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .logo {
            font-size: 3.5rem;
            font-weight: 900;
            color: var(--primary-blue);
            letter-spacing: 2px;
            text-transform: uppercase;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        
        .subtitle {
            font-size: 1.8rem;
            color: var(--secondary-blue);
            font-weight: 300;
            letter-spacing: 1px;
        }
        
        .form-container {
            background-color: var(--white);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            padding: 40px;
            position: relative;
        }
        
        .form-title {
            color: var(--primary-blue);
            margin-bottom: 25px;
            font-size: 1.8rem;
            text-align: center;
        }
        
        .input-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--primary-blue);
            font-weight: 600;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-blue);
        }
        
        input, select {
            width: 100%;
            padding: 15px 15px 15px 50px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        select {
            padding-left: 15px;
            appearance: none;
            background-color: white;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 15px;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .btn {
            display: block;
            width: 100%;
            padding: 16px;
            background-color: var(--secondary-blue);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-top: 10px;
        }
        
        .btn:hover {
            background-color: var(--primary-blue);
        }
        
        .btn-secondary {
            background-color: var(--dark-gray);
        }
        
        .btn-secondary:hover {
            background-color: #5d6d7e;
        }
        
        .form-footer {
            text-align: center;
            margin-top: 25px;
            color: var(--dark-gray);
        }
        
        .form-footer a {
            color: var(--secondary-blue);
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            margin: 5px 10px;
        }
        
        .form-footer a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: <?php echo !empty($error_message) ? 'block' : 'none'; ?>;
        }
        
        .success-message {
            background-color: #d5edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            animation: fadeIn 0.5s ease;
        }
        
        .back-button {
            position: absolute;
            top: 20px;
            left: 20px;
            color: var(--secondary-blue);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            background: var(--light-blue);
            padding: 8px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            background: var(--secondary-blue);
            color: white;
        }
        
        .back-button i {
            margin-right: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .form-container {
                padding: 30px 20px;
            }
            
            .logo {
                font-size: 2.8rem;
            }
            
            .subtitle {
                font-size: 1.5rem;
            }
            
            .back-button {
                position: relative;
                top: 0;
                left: 0;
                margin-bottom: 20px;
                display: inline-flex;
            }
        }
    </style>
</head>
<body>    
    <div class="container">
        <!-- Tombol Back -->
        <a href="penjual.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        
        <div class="form-container">
            <h2 class="form-title">Buat Akun Baru</h2>
            
            <!-- Success Messages -->
            <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                <p style="margin-top: 10px; font-size: 0.9rem;">Mengarahkan ke halaman penjual dalam 2 detik...</p>
            </div>
            
            <!-- Tombol kembali tambahan untuk success state -->
            <a href="penjual.php" class="btn btn-secondary" style="margin-top: 15px;">
                <i class="fas fa-arrow-left"></i> Kembali ke Halaman Penjual
            </a>
            <?php endif; ?>
            
            <!-- Error Messages -->
            <?php if (!empty($error_message) && empty($success_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>
            
            <?php if (empty($success_message)): ?>
            <form id="registerForm" method="POST" action="">
                <div class="input-group">
                    <label for="nik">NIK (Nomor Induk Kependudukan)</label>
                    <div class="input-with-icon">
                        <i class="fas fa-id-card"></i>
                        <input type="text" id="nik" name="nik" placeholder="masukkan 16 digit NIK" 
                               maxlength="16" pattern="[0-9]{16}" required
                               value="<?php echo isset($_POST['nik']) ? htmlspecialchars($_POST['nik']) : ''; ?>">
                    </div>
                    <small style="color: var(--dark-gray); font-size: 0.9rem;">*Harus 16 digit angka</small>
                </div>
                
                <div class="input-group">
                    <label for="username">Username</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder="masukkan username" required
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="email">Email</label>
                    <div class="input-with-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" placeholder="masukkan email anda" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="buat password" required>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="confirm_password">Konfirmasi Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               placeholder="ulangi password" required>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="alamat">Alamat</label>
                    <div class="input-with-icon">
                        <i class="fas fa-map-marker-alt"></i>
                        <input type="text" id="alamat" name="alamat" placeholder="masukkan alamat lengkap" required
                               value="<?php echo isset($_POST['alamat']) ? htmlspecialchars($_POST['alamat']) : ''; ?>">
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <option value="">Pilih role anda</option>
                        <option value="penjual" <?php echo (isset($_POST['role']) && $_POST['role'] == 'penjual') ? 'selected' : ''; ?>>Penjual</option>
                    </select>
                </div>
                
                <button type="submit" class="btn">DAFTAR SEKARANG</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Client-side validation
        document.getElementById('registerForm')?.addEventListener('submit', (e) => {
            const nik = document.getElementById('nik').value;
            const username = document.getElementById('username').value;
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const alamat = document.getElementById('alamat').value;
            const role = document.getElementById('role').value;
            
            // Reset error display
            document.getElementById('errorMessage')?.style.display = 'none';
            
            // Validasi NIK
            const nikRegex = /^[0-9]{16}$/;
            if (!nikRegex.test(nik)) {
                e.preventDefault();
                showError('NIK harus 16 digit angka.');
                return;
            }
            
            // Validasi username
            if (username.length < 3) {
                e.preventDefault();
                showError('Username minimal 3 karakter.');
                return;
            }
            
            // Validasi email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showError('Masukkan alamat email yang valid.');
                return;
            }
            
            // Validasi password (TIDAK ADA BATASAN MINIMAL)
            if (!password) {
                e.preventDefault();
                showError('Password tidak boleh kosong.');
                return;
            }
            
            // Validasi konfirmasi password
            if (password !== confirmPassword) {
                e.preventDefault();
                showError('Password dan konfirmasi password tidak cocok.');
                return;
            }
            
            // Validasi alamat
            if (alamat.length < 10) {
                e.preventDefault();
                showError('Masukkan alamat lengkap (minimal 10 karakter).');
                return;
            }
            
            // Validasi role
            if (!role) {
                e.preventDefault();
                showError('Pilih role Anda.');
                return;
            }
        });
        
        function showError(message) {
            // Create error message div if it doesn't exist
            let errorDiv = document.getElementById('errorMessage');
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.id = 'errorMessage';
                errorDiv.className = 'error-message';
                const formContainer = document.querySelector('.form-container');
                const formTitle = document.querySelector('.form-title');
                formContainer.insertBefore(errorDiv, formTitle.nextSibling);
            }
            
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            errorDiv.style.display = 'block';
            
            // Scroll to error message
            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        // Auto-hide error message after 5 seconds
        setTimeout(() => {
            const errorMessage = document.getElementById('errorMessage');
            if (errorMessage && errorMessage.style.display === 'block') {
                errorMessage.style.display = 'none';
            }
        }, 5000);
        
        // Alert untuk success message
        <?php if (!empty($success_message)): ?>
        alert("Data berhasil ditambahkan!");
        <?php endif; ?>
    </script>
</body>
</html>