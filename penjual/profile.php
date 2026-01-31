<?php
session_start();
require_once '../koneksi.php';

// Cek apakah user sudah login
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../login.php");
    exit();
}

$id_user = $_SESSION['user_id'];
$error = '';
$success = '';

// Ambil data user dari database
$stmt = $conn->prepare("SELECT * FROM users WHERE id_user = ?");
$stmt->bind_param("i", $id_user);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("User tidak ditemukan");
}

// Proses update profile
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $email = $_POST['email'];
    $nama_bank = $_POST['nama_bank'] ?? '';
    $no_rekening = $_POST['no_rekening'] ?? '';
    
    // Handle upload gambar
    $gambar = $user['gambar']; // Default ke gambar lama
    
    if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (in_array($_FILES['gambar']['type'], $allowed_types) && $_FILES['gambar']['size'] <= $max_size) {
            $ext = pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION);
            $gambar_name = 'profile_' . $id_user . '_' . time() . '.' . $ext;
            $upload_path = '../asset/' . $gambar_name;
            
            if (move_uploaded_file($_FILES['gambar']['tmp_name'], $upload_path)) {
                // Hapus gambar lama jika bukan default
                if ($user['gambar'] && $user['gambar'] != 'default.png') {
                    @unlink('../asset/' . $user['gambar']);
                }
                $gambar = $gambar_name;
            }
        }
    }
    
    // Handle password jika diisi
    $password_query = "";
    $params = [];
    $types = "sssssi";
    
    if (!empty($_POST['password'])) {
        if ($_POST['password'] === $_POST['confirm_password']) {
            $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $password_query = ", password = ?";
            $types .= "s";
            $params[] = $password_hash;
        } else {
            $error = "Password dan konfirmasi password tidak cocok";
        }
    }
    
    if (empty($error)) {
        $params = array_merge([$username, $email, $nama_bank, $no_rekening, $gambar, $id_user], $params);
        
        $sql = "UPDATE users SET username = ?, email = ?, nama_bank = ?, no_rekening = ?, gambar = ? $password_query WHERE id_user = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $success = "Profile berhasil diupdate";
            
            // UPDATE SESSION dengan data baru
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['gambar'] = $gambar;
            $_SESSION['nama_bank'] = $nama_bank;
            $_SESSION['no_rekening'] = $no_rekening;
            
            // Refresh data user
            $stmt = $conn->prepare("SELECT * FROM users WHERE id_user = ?");
            $stmt->bind_param("i", $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
        } else {
            $error = "Gagal mengupdate profile: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - <?php echo htmlspecialchars($user['username']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f8ff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .profile-container {
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 100, 200, 0.1);
            width: 100%;
            max-width: 600px;
            padding: 40px;
            border: 1px solid #e0f0ff;
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            flex: 1;
            text-align: left;
        }
        
        .header-right {
            flex-shrink: 0;
        }
        
        .profile-header h1 {
            color: #2c3e50;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .profile-header p {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .profile-picture-section {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 2px solid #f0f8ff;
        }
        
        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #e0f2ff;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(64, 156, 255, 0.2);
        }
        
        .picture-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        
        .btn {
            padding: 10px 25px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-delete {
            background-color: #ffebee;
            color: #e53935;
            border: 2px solid #ffcdd2;
        }
        
        .btn-delete:hover {
            background-color: #ffcdd2;
        }
        
        .btn-change {
            background-color: #e3f2fd;
            color: #1976d2;
            border: 2px solid #bbdefb;
        }
        
        .btn-change:hover {
            background-color: #bbdefb;
        }
        
        .btn-back {
            background-color: #f5f5f5;
            color: #757575;
            border: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back:hover {
            background-color: #e0e0e0;
        }
        
        .file-info {
            margin-top: 15px;
            font-size: 13px;
            color: #78909c;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0f0ff;
            border-radius: 8px;
            font-size: 15px;
            color: #34495e;
            transition: border 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #64b5f6;
            box-shadow: 0 0 0 3px rgba(100, 181, 246, 0.2);
        }
        
        .bank-section {
            background-color: #f8fdff;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #4caf50;
            margin: 30px 0;
        }
        
        .bank-section h3 {
            color: #2e7d32;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .bank-section h3::before {
            content: "🏦";
            font-size: 20px;
        }
        
        .bank-note {
            font-size: 13px;
            color: #78909c;
            font-style: italic;
            margin-top: 5px;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .password-section {
            background-color: #f8fdff;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #64b5f6;
            margin: 30px 0;
        }
        
        .password-section h3 {
            color: #1976d2;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .password-note {
            font-size: 13px;
            color: #78909c;
            font-style: italic;
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn-update {
            background-color: #2196f3;
            color: white;
            padding: 12px 35px;
            font-size: 16px;
        }
        
        .btn-update:hover {
            background-color: #0d8bf2;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }
        
        .btn-cancel {
            background-color: #f5f5f5;
            color: #757575;
            padding: 12px 30px;
            font-size: 16px;
            border: 2px solid #e0e0e0;
        }
        
        .btn-cancel:hover {
            background-color: #e0e0e0;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            position: relative;
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        
        .alert.fade-out {
            opacity: 0;
        }
        
        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #4caf50;
        }
        
        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }
        
        .role-badge {
            display: inline-block;
            padding: 5px 15px;
            background-color: #e3f2fd;
            color: #1976d2;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
            text-transform: capitalize;
        }
        
        .alert-countdown {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: rgba(0, 0, 0, 0.5);
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <div class="profile-header">
            <div class="header-left">
                <h1>Edit Profile</h1>
                <p>Kelola informasi profil Anda</p>
                <div class="role-badge"><?php echo htmlspecialchars($user['role']); ?></div>
            </div>
            <div class="header-right">
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success" id="successAlert">
                <?php echo htmlspecialchars($success); ?>
                <span class="alert-countdown" id="countdown">30</span>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error" id="errorAlert">
                <?php echo htmlspecialchars($error); ?>
                <span class="alert-countdown" id="errorCountdown">30</span>
            </div>
        <?php endif; ?>
        
        <form action="" method="POST" enctype="multipart/form-data">
            <div class="profile-picture-section">
                <img src="../asset/<?php echo htmlspecialchars($user['gambar'] ?: 'default.png'); ?>" 
                     alt="Profile Picture" class="profile-picture" id="profileImage">
                
                <div class="picture-actions">
                    <button type="button" class="btn btn-delete" onclick="deletePicture()">Hapus Foto</button>
                    <label for="gambar" class="btn btn-change">Ganti Foto</label>
                    <input type="file" id="gambar" name="gambar" accept=".jpg,.jpeg,.png,.gif" style="display: none;" onchange="previewImage(this)">
                </div>
                
                <p class="file-info">Format: JPG, PNG, GIF (Maks. 2MB)</p>
            </div>
            
            <div class="form-group">
                <label for="username">Nama:</label>
                <input type="text" id="username" name="username" class="form-control" 
                       value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" class="form-control" 
                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>
            
            <div class="bank-section">
                <h3>Informasi Rekening Bank</h3>
                <p class="bank-note">*Informasi ini akan digunakan untuk pembayaran</p>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="nama_bank">Nama Bank:</label>
                        <input type="text" id="nama_bank" name="nama_bank" class="form-control" 
                               value="<?php echo htmlspecialchars($user['nama_bank'] ?? ''); ?>"
                               placeholder="Contoh: BCA, Mandiri, BRI">
                    </div>
                    
                    <div class="form-group">
                        <label for="no_rekening">Nomor Rekening:</label>
                        <input type="text" id="no_rekening" name="no_rekening" class="form-control" 
                               value="<?php echo htmlspecialchars($user['no_rekening'] ?? ''); ?>"
                               placeholder="Contoh: 1234567890">
                    </div>
                </div>
            </div>
            
            <div class="password-section">
                <h3>Ubah Password</h3>
                <p class="password-note">*Kosongkan field password jika tidak ingin mengubah password</p>
                
                <div class="form-group">
                    <label for="password">Password Baru:</label>
                    <input type="password" id="password" name="password" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Konfirmasi Password Baru:</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                           placeholder="Konfirmasi password baru">
                </div>
            </div>
            
            <div class="form-actions">
                <!-- TOMBOL BATAL MENUJU DASHBOARD.PHP -->
                <button type="button" class="btn btn-cancel" onclick="window.location.href='dashboard.php'">Batal</button>
                <button type="submit" class="btn btn-update">Update Profile</button>
            </div>
        </form>
    </div>
    
    <script>
        // Fungsi untuk menghilangkan alert otomatis setelah 30 detik
        function setupAlertAutoHide() {
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');
            
            if (successAlert) {
                startCountdown(successAlert, 'countdown');
            }
            
            if (errorAlert) {
                startCountdown(errorAlert, 'errorCountdown');
            }
        }
        
        function startCountdown(alertElement, countdownId) {
            let seconds = 30;
            const countdownElement = document.getElementById(countdownId);
            
            const countdownInterval = setInterval(() => {
                seconds--;
                if (countdownElement) {
                    countdownElement.textContent = seconds;
                }
                
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    alertElement.classList.add('fade-out');
                    setTimeout(() => {
                        alertElement.style.display = 'none';
                    }, 500);
                }
            }, 1000);
        }
        
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('profileImage').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function deletePicture() {
            if (confirm('Apakah Anda yakin ingin menghapus foto profil?')) {
                document.getElementById('profileImage').src = '../asset/default.png';
                // Anda bisa menambahkan AJAX request untuk menghapus dari server
                fetch('delete_picture.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'delete_picture=1'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Foto profil berhasil dihapus');
                    }
                });
            }
        }
        
        // Validasi nomor rekening hanya angka
        document.getElementById('no_rekening').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Jalankan fungsi setup alert saat halaman dimuat
        document.addEventListener('DOMContentLoaded', setupAlertAutoHide);
    </script>
</body>
</html>