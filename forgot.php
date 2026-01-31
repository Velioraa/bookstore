<?php
session_start();

// Koneksi database (sesuaikan dengan database Anda)
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "toko_buku";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Import PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// Step 1: Kirim kode OTP ke email user
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_code'])) {
    $email = trim($_POST['email']);

    // Validasi format email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Format email tidak valid';
    } else {
        // Cek email di database - sesuaikan dengan tabel users Anda
        $stmt = $conn->prepare("SELECT nik, username FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            // Generate kode OTP
            $otp = rand(100000, 999999);

            // Simpan OTP dan email di session
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_user_id'] = $user['nik'];
            $_SESSION['otp'] = $otp;
            $_SESSION['otp_time'] = time(); // simpan waktu OTP dibuat
            $_SESSION['reset_step'] = 2;

            // Kirim email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'smknhumas1@gmail.com'; // ganti email pengirim
                $mail->Password = 'xido qqfm xpyt xdrd';       // ganti App Password Gmail
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('smknhumas1@gmail.com', 'WESLEY BOOKSTORE'); 
                $mail->addAddress($email); 

                $mail->isHTML(true);
                $mail->Subject = 'Kode OTP Reset Password - WESLEY BOOKSTORE';
                
                // Email template yang lebih baik
                $emailBody = '
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                        .container { max-width: 600px; margin: 0 auto; background-color: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                        .header { background-color: #2c3e50; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                        .content { padding: 30px; text-align: center; }
                        .otp-code { font-size: 32px; font-weight: bold; color: #3498db; letter-spacing: 5px; margin: 20px 0; padding: 15px; background-color: #ecf0f9; border-radius: 5px; }
                        .footer { text-align: center; color: #7f8c8d; font-size: 12px; margin-top: 30px; }
                        .warning { background-color: #fff3cd; color: #856404; padding: 10px; border-radius: 5px; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>WESLEY BOOKSTORE</h1>
                            <p>Reset Password</p>
                        </div>
                        <div class="content">
                            <h2>Halo ' . htmlspecialchars($user['username']) . ',</h2>
                            <p>Kami menerima permintaan reset password untuk akun Anda. Gunakan kode OTP berikut untuk melanjutkan:</p>
                            <div class="otp-code">' . $otp . '</div>
                            <p>Kode ini akan kedaluwarsa dalam <strong>10 menit</strong>.</p>
                            <p>Jika Anda tidak meminta reset password, abaikan email ini.</p>
                            <div class="warning">
                                <strong>Peringatan:</strong> Jangan berikan kode OTP ini kepada siapa pun.
                            </div>
                        </div>
                        <div class="footer">
                            <p>&copy; ' . date('Y') . ' WESLEY BOOKSTORE. Semua hak dilindungi undang-undang.</p>
                            <p>Email ini dikirim secara otomatis, mohon tidak membalas email ini.</p>
                        </div>
                    </div>
                </body>
                </html>
                ';
                
                $mail->Body = $emailBody;
                $mail->AltBody = "Kode OTP Reset Password: $otp\nKode ini berlaku selama 10 menit.";

                $mail->send();
                $_SESSION['success'] = 'Kode OTP sudah dikirim ke email';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Email gagal dikirim. Error: ' . $mail->ErrorInfo;
            }
        } else {
            $_SESSION['error'] = 'Email tidak terdaftar dalam sistem';
        }
        $stmt->close();
    }
}

// Step 2: Verifikasi kode OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_code'])) {
    $otp_input = trim($_POST['otp']);
    $current_time = time();

    if (isset($_SESSION['otp']) && isset($_SESSION['otp_time']) && $current_time - $_SESSION['otp_time'] <= 600) { // 10 menit
        if ($_SESSION['otp'] == $otp_input) {
            $_SESSION['reset_step'] = 3; // lanjut ke reset password
            $_SESSION['otp_verified'] = true;
            $_SESSION['success'] = 'OTP berhasil diverifikasi';
        } else {
            $_SESSION['error'] = 'Kode OTP salah';
        }
    } else {
        $_SESSION['error'] = 'Kode OTP sudah kadaluarsa. Silakan minta OTP baru.';
        unset($_SESSION['otp']);
        unset($_SESSION['otp_time']);
        $_SESSION['reset_step'] = 1;
    }
}

// Step 3: Reset Password - DIUBAH: tidak ada batasan minimal karakter
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    $email = $_SESSION['reset_email'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? '';

    if (!empty($email) && isset($_SESSION['otp_verified']) && $_SESSION['otp_verified']) {
        if ($new_password === $confirm_password) {
            // DIUBAH: Hapus validasi minimal 6 karakter
            // Password bisa berapa pun karakternya, asalkan tidak kosong
            if (!empty($new_password)) {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE users SET password=? WHERE nik=?");
                $stmt->bind_param("ss", $hash, $user_id);

                if ($stmt->execute()) {
                    $_SESSION['success'] = 'Password berhasil direset';
                    
                    // Hapus semua session reset password
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['otp']);
                    unset($_SESSION['otp_time']);
                    unset($_SESSION['otp_verified']);
                    unset($_SESSION['reset_step']);
                    
                    // Redirect ke login setelah 2 detik
                    echo "<script>
                            setTimeout(function() {
                                window.location.href = 'login.php';
                            }, 2000);
                          </script>";
                } else {
                    $_SESSION['error'] = 'Gagal mengubah password';
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = 'Password tidak boleh kosong';
            }
        } else {
            $_SESSION['error'] = 'Password baru dan konfirmasi tidak cocok';
        }
    } else {
        $_SESSION['error'] = 'Terjadi kesalahan. Silakan ulangi proses forgot password.';
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WESLEY BOOKSTORE - Lupa Password</title>
    <link rel="website icon" type="image/png" href="asset/wesley.png">
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
            max-width: 450px;
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
        
        input {
            width: 100%;
            padding: 15px 15px 15px 50px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        input:focus {
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
        
        .otp-message {
            background-color: #e8f4fc;
            border-left: 4px solid var(--secondary-blue);
            padding: 12px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .otp-message i {
            color: var(--secondary-blue);
            margin-right: 8px;
        }
        
        .step {
            display: none;
        }
        
        .step.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: var(--secondary-blue);
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
        }
        
        .back-link i {
            margin-right: 5px;
        }
        
        .success-message {
            background-color: #d5edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: <?php echo isset($_SESSION['success']) ? 'block' : 'none'; ?>;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: <?php echo isset($_SESSION['error']) ? 'block' : 'none'; ?>;
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
        }
    </style>
</head>
<body>    
    <div class="container">
        <div class="form-container">
            <?php
            $reset_step = $_SESSION['reset_step'] ?? 1;
            $email = $_SESSION['reset_email'] ?? '';
            ?>
            
            <!-- Step 1: Masukkan Email -->
            <div class="step <?php echo $reset_step == 1 ? 'active' : ''; ?>" id="step1">
                <h2 class="form-title">Reset Password</h2>
                
                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="otp-message">
                    <i class="fas fa-info-circle"></i>
                    Masukkan email Anda. Kami akan mengirimkan kode OTP ke email tersebut untuk verifikasi.
                </div>
                
                <form method="POST" action="">
                    <div class="input-group">
                        <label for="forgotEmail">Email</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="forgotEmail" name="email" placeholder="masukkan email terdaftar" required 
                                   value="<?php echo htmlspecialchars($email); ?>">
                        </div>
                    </div>
                    
                    <button type="submit" name="send_code" class="btn">KIRIM KODE OTP</button>
                </form>
                
                <div class="form-footer">
                    Ingat password? <a href="login.php">Masuk disini</a><br>
                </div>
            </div>
            
            <!-- Step 2: Verifikasi OTP -->
            <div class="step <?php echo $reset_step == 2 ? 'active' : ''; ?>" id="step2">                
                <h2 class="form-title">Verifikasi OTP</h2>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="otp-message">
                    <i class="fas fa-envelope"></i>
                    Kode OTP telah dikirim ke <strong><?php echo htmlspecialchars($email); ?></strong>. Periksa kotak masuk atau folder spam Anda.
                </div>
                
                <form method="POST" action="">
                    <div class="input-group">
                        <label for="otpCode">Kode OTP (6 digit)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-key"></i>
                            <input type="text" id="otpCode" name="otp" placeholder="masukkan 6 digit kode OTP" maxlength="6" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="verify_code" class="btn">VERIFIKASI OTP</button>
                </form>
            </div>
            
            <!-- Step 3: Password Baru - DIUBAH: menghapus keterangan "min. 6 karakter" -->
            <div class="step <?php echo $reset_step == 3 ? 'active' : ''; ?>" id="step3">                
                <h2 class="form-title">Password Baru</h2>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="input-group">
                        <label for="newPassword">Password Baru</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <!-- DIUBAH: Menghapus placeholder "(min. 6 karakter)" -->
                            <input type="password" id="newPassword" name="new_password" placeholder="masukkan password baru" required>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label for="confirmNewPassword">Konfirmasi Password Baru</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirmNewPassword" name="confirm_password" placeholder="ulangi password baru" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="reset_password" class="btn">RESET PASSWORD</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Untuk menangani back link
        document.addEventListener('DOMContentLoaded', function() {
            const backLinks = document.querySelectorAll('.back-link');
            backLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!this.getAttribute('href').includes('step=')) {
                        e.preventDefault();
                        const step = this.getAttribute('href') === '?step=1' ? 1 : 2;
                        window.location.href = `?step=${step}`;
                    }
                });
            });
            
            // Validasi OTP hanya angka
            const otpInput = document.getElementById('otpCode');
            if (otpInput) {
                otpInput.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
            
            // Validasi password match - DIUBAH: menghapus validasi minimal 6 karakter
            const resetForm = document.querySelector('#step3 form');
            if (resetForm) {
                resetForm.addEventListener('submit', function(e) {
                    const newPassword = document.getElementById('newPassword').value;
                    const confirmPassword = document.getElementById('confirmNewPassword').value;
                    
                    // DIUBAH: Hanya cek password tidak kosong
                    if (newPassword.trim() === '') {
                        e.preventDefault();
                        alert('Password tidak boleh kosong');
                        return false;
                    }
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('Password dan konfirmasi password tidak cocok');
                        return false;
                    }
                });
            }
        });
        
        // Helper function untuk validasi email
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        // Validasi form email sebelum submit
        const emailForm = document.querySelector('#step1 form');
        if (emailForm) {
            emailForm.addEventListener('submit', function(e) {
                const email = document.getElementById('forgotEmail').value;
                if (!validateEmail(email)) {
                    e.preventDefault();
                    alert('Masukkan alamat email yang valid.');
                    return false;
                }
            });
        }
    </script>
    
    <?php
    // Handle step parameter dari URL
    if (isset($_GET['step'])) {
        $step = intval($_GET['step']);
        if ($step >= 1 && $step <= 3) {
            $_SESSION['reset_step'] = $step;
            echo "<script>window.location.href = 'forgot-password.php';</script>";
            exit();
        }
    }
    ?>
</body>
</html>