<?php
session_start();
require_once "koneksi.php";  
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Hapus user yang statusnya tidak aktif lebih dari 6 bulan sebelum memproses login
$six_months_ago = date('Y-m-d H:i:s', strtotime('-6 month'));
$delete_query = "DELETE FROM users WHERE status = 'tidak_aktif' AND last_login IS NOT NULL AND last_login < ?";
$stmt = $conn->prepare($delete_query);
$stmt->bind_param("s", $six_months_ago);
$stmt->execute();
$stmt->close();

// Cek jika user sudah login lewat sesi
if (isset($_SESSION["username"]) && !empty($_SESSION["username"])) {
    // Redirect ke halaman sesuai role
    redirectBasedOnRole($_SESSION["role"]);
    exit();
}

// Proses login
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST["remember"]) ? $_POST["remember"] : '';

    // Validasi input
    if (empty($email) || empty($password)) {
        $error_message = "Email dan Password tidak boleh kosong!";
    } else {
        // Siapkan query untuk mengambil user berdasarkan email
        $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $user_id = $row["id_user"];
            $username = $row["username"];
            $role = $row["role"];
            $status = $row["status"];

            // Cek status akun
            if ($status == 'tidak_aktif') {
                $error_message = "Akun Anda dinonaktifkan. Silakan hubungi administrator.";
            } 
            // Cek jika last_login null (user baru)
            else if (is_null($row['last_login'])) {
                // Ini adalah user baru, biarkan login
            }
            // Cek kapan terakhir kali login jika bukan user baru
            else if (!is_null($row['last_login'])) {
                $last_login = strtotime($row['last_login']);
                $six_months_ago = strtotime('-6 months');

                if ($last_login < $six_months_ago) {
                    // Update status menjadi tidak aktif
                    $update_stmt = $conn->prepare("UPDATE users SET status = 'tidak_aktif' WHERE id_user = ?");
                    $update_stmt->bind_param("i", $user_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $error_message = "Maaf, akun Anda telah dinonaktifkan karena sudah 6 bulan tidak login!";
                }
            }

            // Cek jika password benar (gunakan password_verify karena password dihash)
            if (!isset($error_message) && password_verify($password, $row['password'])) {
                $_SESSION['loggedin'] = true;
                $_SESSION['user_id'] = $user_id;
                $_SESSION['email'] = $email;
                $_SESSION["username"] = $username;
                $_SESSION["role"] = $role;
                $_SESSION["nik"] = $row["nik"];

                // Set cookie jika checkbox 'remember me' dicentang
                $cookie = "";
                if (!empty($remember)) {
                    $cookie = bin2hex(random_bytes(16)); // Generate secure cookie
                    setcookie("remember_token", $cookie, time() + (86400 * 30), "/");
                }

                // Update waktu login terakhir
                $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW(), remember_token = ? WHERE id_user = ?");
                $update_stmt->bind_param("si", $cookie, $user_id);
                $update_stmt->execute();
                $update_stmt->close();

                // Redirect ke halaman sesuai role
                redirectBasedOnRole($role);
                exit();
            } else if (!isset($error_message)) {
                $error_message = "Password salah!";
            }
        } else {
            $error_message = "Email tidak ditemukan!";
        }

        $stmt->close();
    }
}

// Fungsi untuk redirect berdasarkan role
function redirectBasedOnRole($role) {
    switch ($role) {
        case 'super_admin':
            header("Location: superadmin/index.php");
            break;
        case 'penjual':
            header("Location: penjual/dashboard.php");
            break;
        case 'pembeli':
            header("Location: pembeli/dashboard.php");
            break;
        default:
            header("Location: index.php"); // Fallback ke halaman utama
            break;
    }
}

// Tutup koneksi database
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WESLEY BOOKSTORE - Login</title>
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
            margin-bottom: 40px;
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
            margin-bottom: 30px;
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
        
        .forgot-link {
            display: block;
            text-align: right;
            margin-top: 5px;
            color: var(--secondary-blue);
            text-decoration: none;
            font-size: 14px;
        }
        
        .forgot-link:hover {
            text-decoration: underline;
        }
        
        .success-message {
            background-color: #d5edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: <?php echo isset($success_message) ? 'block' : 'none'; ?>;
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: <?php echo isset($error_message) ? 'block' : 'none'; ?>;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .remember-me input {
            width: auto;
            margin-right: 10px;
        }
        
        .remember-me label {
            margin-bottom: 0;
            font-weight: normal;
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
            <h2 class="form-title">Masuk ke Akun Anda</h2>
            
            <!-- Success Message -->
            <?php if (isset($success_message)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <!-- Error Message -->
            <?php if (isset($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form id="loginForm" method="POST" action="">
                <div class="input-group">
                    <label for="loginEmail">Email</label>
                    <div class="input-with-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="loginEmail" name="email" placeholder="masukkan email anda" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="loginPassword">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="loginPassword" name="password" placeholder="masukkan password anda" required>
                    </div>
                    <a href="forgot.php" class="forgot-link">Lupa password?</a>
                </div>
                
                <div class="remember-me">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Ingat saya</label>
                </div>
                
                <button type="submit" class="btn">MASUK</button>
            </form>
            
            <div class="form-footer">
                Belum punya akun? <a href="register.php">Daftar disini</a><br>
            </div>
        </div>
    </div>

    <script>
        // Client-side validation
        document.getElementById('loginForm').addEventListener('submit', (e) => {
            const email = document.getElementById('loginEmail').value;
            const password = document.getElementById('loginPassword').value;
            
            if (!validateEmail(email)) {
                e.preventDefault();
                alert('Masukkan alamat email yang valid.');
                return false;
            }
            
            // Tidak ada validasi minimal password di client-side
            return true;
        });
        
        // Helper Functions
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
    </script>
</body>
</html>