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

// Inisialisasi variabel
$success_message = '';
$error_message = '';

// PROSES AJAX CHECK
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_check'])) {
    $nama_kategori = isset($_POST['nama_kategori']) ? trim($_POST['nama_kategori']) : '';
    $response = ['exists' => false, 'existing_name' => ''];
    
    if (strlen($nama_kategori) >= 3) {
        // Cek apakah kategori sudah ada (case-insensitive)
        $check_kategori = "SELECT nama_kategori FROM katagori WHERE LOWER(nama_kategori) = LOWER(?)";
        $stmt_check = $conn->prepare($check_kategori);
        
        if ($stmt_check) {
            $nama_kategori_lower = strtolower($nama_kategori);
            $stmt_check->bind_param("s", $nama_kategori_lower);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                $existing_kategori = $result_check->fetch_assoc();
                $response['exists'] = true;
                $response['existing_name'] = htmlspecialchars($existing_kategori['nama_kategori']);
            }
            $stmt_check->close();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// PROSES FORM SUBMIT
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_check'])) {
    // Ambil data dari form
    $nama_kategori = isset($_POST['nama_kategori']) ? trim($_POST['nama_kategori']) : '';
    
    // Validasi
    $errors = [];
    
    // Validasi nama kategori
    if (empty($nama_kategori)) {
        $errors[] = "Nama kategori tidak boleh kosong.";
    } elseif (strlen($nama_kategori) < 3) {
        $errors[] = "Nama kategori minimal 3 karakter.";
    } elseif (strlen($nama_kategori) > 100) {
        $errors[] = "Nama kategori maksimal 100 karakter.";
    }
    
    // Cek apakah kategori sudah ada (case-insensitive)
    if (empty($errors)) {
        $check_kategori = "SELECT id_kategori, nama_kategori FROM katagori WHERE LOWER(nama_kategori) = LOWER(?)";
        
        $stmt_check = $conn->prepare($check_kategori);
        if ($stmt_check) {
            $nama_kategori_lower = strtolower($nama_kategori);
            $stmt_check->bind_param("s", $nama_kategori_lower);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                $existing_kategori = $result_check->fetch_assoc();
                $errors[] = "Nama dengan kategori '" . htmlspecialchars($existing_kategori['nama_kategori']) . "' sudah ada. Silakan gunakan nama lain.";
                
                // Simpan pesan untuk alert JavaScript
                echo "<script>
                        setTimeout(function() {
                            alert('Nama dengan kategori \\'" . addslashes($existing_kategori['nama_kategori']) . "\\' sudah ada. Silakan gunakan nama lain.');
                        }, 100);
                      </script>";
            }
            $stmt_check->close();
        } else {
            $errors[] = "Terjadi kesalahan dalam query: " . $conn->error;
        }
    }
    
    // Jika tidak ada error, simpan ke database
    if (empty($errors)) {
        $query = "INSERT INTO katagori (nama_kategori) VALUES (?)";
        $stmt = $conn->prepare($query);
        
        if ($stmt) {
            $stmt->bind_param("s", $nama_kategori);

            if ($stmt->execute()) {
                $last_id = $stmt->insert_id;
                $success_message = "Kategori berhasil ditambahkan! (ID: $last_id)";
                
                // Reset form value
                $_POST['nama_kategori'] = '';
                
                // Redirect ke halaman categories.php setelah 2 detik
                echo "<script>
                        setTimeout(function() {
                            alert('Kategori berhasil ditambahkan!');
                            window.location.href = 'categories.php';
                        }, 100);
                      </script>";
            } else {
                $error_message = "Terjadi kesalahan: " . $stmt->error;
            }

            $stmt->close();
        } else {
            $error_message = "Terjadi kesalahan dalam query: " . $conn->error;
        }
    } else {
        $error_message = implode("<br>", $errors);
    }  
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wesley Bookstore - Tambah Kategori</title>
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
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            position: absolute;
            top: 20px;
            left: 20px;
        }
        
        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-3px);
        }
        
        .back-button i {
            margin-right: 8px;
        }
        
        .content {
            padding: 40px;
        }
        
        /* Success Messages */
        .success-message {
            background-color: #d5edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            animation: fadeIn 0.5s ease;
            border-left: 4px solid var(--success);
        }
        
        .success-message i {
            margin-right: 10px;
        }
        
        /* Error Messages */
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
        }
        
        .error-message i {
            margin-right: 10px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control.duplicate {
            border-color: var(--danger);
            background-color: rgba(220, 53, 69, 0.05);
        }
        
        .form-control.unique {
            border-color: var(--success);
            background-color: rgba(40, 167, 69, 0.05);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
        }
        
        .form-control::placeholder {
            color: #adb5bd;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex: 1;
        }
        
        .btn-secondary {
            background: var(--gray);
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--secondary) 0%, #2c7be5 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #2c7be5 0%, var(--secondary) 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.3);
        }
        
        .btn-primary:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .form-help {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--secondary);
        }
        
        .form-help h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .form-help ul {
            list-style-type: none;
            padding-left: 0;
        }
        
        .form-help li {
            padding: 5px 0;
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .form-help li:before {
            content: "•";
            color: var(--secondary);
            font-weight: bold;
            display: inline-block;
            width: 1em;
            margin-left: -1em;
        }
        
        /* Duplicate warning */
        .duplicate-warning {
            margin-top: 5px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
            opacity: 0;
            height: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }
        
        .duplicate-warning.show {
            opacity: 1;
            height: auto;
            transform: translateY(0);
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 6px;
        }
        
        .duplicate-warning.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger);
            border-left: 3px solid var(--danger);
        }
        
        .duplicate-warning.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success);
            border-left: 3px solid var(--success);
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 20px;
            }
            
            .content {
                padding: 25px;
            }
            
            .header {
                padding: 20px;
            }
            
            .back-button {
                position: relative;
                top: 0;
                left: 0;
                margin-bottom: 20px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .container {
                margin: 10px;
            }
            
            .content {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
        }
        
        /* Loading animation */
        .loading {
            display: none;
            text-align: center;
            margin-top: 10px;
        }
        
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--secondary);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Custom Alert Style */
        .custom-alert {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            z-index: 10000;
            width: 90%;
            max-width: 400px;
            overflow: hidden;
            animation: alertSlideIn 0.3s ease;
        }
        
        @keyframes alertSlideIn {
            from { opacity: 0; transform: translate(-50%, -60%); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }
        
        .alert-header {
            background: linear-gradient(135deg, var(--danger) 0%, #c82333 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .alert-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        
        .alert-body {
            padding: 25px;
            text-align: center;
        }
        
        .alert-icon {
            font-size: 3rem;
            color: var(--danger);
            margin-bottom: 15px;
        }
        
        .alert-message {
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .alert-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .alert-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 100px;
        }
        
        .alert-btn-ok {
            background: var(--secondary);
            color: white;
        }
        
        .alert-btn-ok:hover {
            background: #2c7be5;
        }
        
        .alert-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plus-circle"></i> Tambah Kategori Baru</h1>
            <p>Tambahkan kategori baru untuk mengelola buku di toko</p>
        </div>
        
        <div class="content">
            <!-- Success Messages -->
            <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                <p style="margin-top: 10px; font-size: 0.9rem;">Mengarahkan ke halaman kategori...</p>
            </div>
            
            <!-- Tombol kembali tambahan untuk success state -->
            <a href="categories.php" class="btn btn-secondary" style="margin-top: 15px;">
                <i class="fas fa-arrow-left"></i> Kembali ke Daftar Kategori
            </a>
            <?php endif; ?>
            
            <!-- Error Messages -->
            <?php if (!empty($error_message) && empty($success_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if (empty($success_message)): ?>
            <form id="createCategoryForm" method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="nama_kategori">
                        <i class="fas fa-tag"></i> Nama Kategori
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="nama_kategori" 
                           name="nama_kategori" 
                           placeholder="Masukkan nama kategori (contoh: Novel, Komik, Pendidikan)"
                           required
                           value="<?php echo isset($_POST['nama_kategori']) ? htmlspecialchars($_POST['nama_kategori']) : ''; ?>"
                           oninput="checkDuplicateCategory(this.value)">
                    <div class="duplicate-warning" id="duplicateWarning">
                        <i class="fas fa-info-circle"></i>
                        <span id="duplicateMessage">Memeriksa nama kategori...</span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="categories.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i> Simpan Kategori
                    </button>
                </div>
            </form>
            
            <!-- Loading Animation -->
            <div class="loading" id="loading">
                <div class="loading-spinner"></div>
                <p style="margin-top: 10px; color: var(--gray);">Menyimpan kategori...</p>
            </div>
            
            <div class="form-help">
                <h4><i class="fas fa-info-circle"></i> Ketentuan Penamaan Kategori:</h4>
                <ul>
                    <li>Nama kategori harus unik (tidak boleh sama dengan kategori yang sudah ada)</li>
                    <li>Minimal 3 karakter, maksimal 100 karakter</li>
                    <li>Perbedaan huruf besar/kecil tidak dianggap berbeda</li>
                    <li>Contoh: "Novel" dianggap sama dengan "NOVEL" atau "novel"</li>
                    <li>Sistem akan otomatis memeriksa nama kategori saat Anda mengetik</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // AJAX untuk cek duplikasi kategori
        let checkTimeout;
        let alertShown = false; // Untuk mencegah alert muncul berulang
        
        function checkDuplicateCategory(categoryName) {
            clearTimeout(checkTimeout);
            
            const duplicateWarning = document.getElementById('duplicateWarning');
            const submitBtn = document.getElementById('submitBtn');
            const inputField = document.getElementById('nama_kategori');
            
            // Reset status
            duplicateWarning.classList.remove('show', 'error', 'success');
            inputField.classList.remove('duplicate', 'unique');
            alertShown = false; // Reset alert flag
            
            if (categoryName.trim().length < 3) {
                duplicateWarning.querySelector('#duplicateMessage').textContent = 'Minimal 3 karakter';
                duplicateWarning.classList.add('show');
                duplicateWarning.classList.add('error');
                inputField.classList.add('duplicate');
                if (submitBtn) submitBtn.disabled = true;
                return;
            }
            
            // Tampilkan loading
            duplicateWarning.querySelector('#duplicateMessage').textContent = 'Memeriksa...';
            duplicateWarning.classList.add('show');
            
            checkTimeout = setTimeout(function() {
                // Kirim request AJAX ke file yang sama
                const xhr = new XMLHttpRequest();
                xhr.open('POST', '', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            
                            if (response.exists) {
                                // Kategori sudah ada
                                duplicateWarning.querySelector('#duplicateMessage').innerHTML = 
                                    `<strong>Kategori "${response.existing_name}" sudah ada.</strong> Silakan gunakan nama lain.`;
                                duplicateWarning.classList.add('show', 'error');
                                duplicateWarning.classList.remove('success');
                                inputField.classList.add('duplicate');
                                inputField.classList.remove('unique');
                                
                                // Nonaktifkan tombol submit
                                if (submitBtn) {
                                    submitBtn.disabled = true;
                                    submitBtn.title = 'Kategori sudah ada, gunakan nama lain';
                                }
                                
                                // Tampilkan alert hanya sekali
                                if (!alertShown) {
                                    showCustomAlert(
                                        'Kategori Sudah Ada',
                                        `Nama dengan kategori <strong>"${response.existing_name}"</strong> sudah ada dalam sistem. Silakan gunakan nama lain yang berbeda.`,
                                        'error'
                                    );
                                    alertShown = true;
                                }
                            } else {
                                // Kategori unik
                                duplicateWarning.querySelector('#duplicateMessage').textContent = 
                                    'Nama kategori tersedia!';
                                duplicateWarning.classList.add('show', 'success');
                                duplicateWarning.classList.remove('error');
                                inputField.classList.add('unique');
                                inputField.classList.remove('duplicate');
                                
                                // Aktifkan tombol submit
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                    submitBtn.title = 'Klik untuk menyimpan kategori';
                                }
                            }
                        } catch (e) {
                            console.error('Error parsing response:', e);
                            duplicateWarning.querySelector('#duplicateMessage').textContent = 
                                'Terjadi kesalahan saat memeriksa';
                            duplicateWarning.classList.add('show', 'error');
                        }
                    } else {
                        duplicateWarning.querySelector('#duplicateMessage').textContent = 
                            'Terjadi kesalahan koneksi';
                        duplicateWarning.classList.add('show', 'error');
                    }
                };
                
                xhr.onerror = function() {
                    duplicateWarning.querySelector('#duplicateMessage').textContent = 
                        'Gagal memeriksa. Periksa koneksi internet Anda.';
                    duplicateWarning.classList.add('show', 'error');
                };
                
                // Kirim dengan parameter khusus untuk AJAX
                xhr.send('ajax_check=1&nama_kategori=' + encodeURIComponent(categoryName));
            }, 300); // Delay 300ms
        }
        
        // Fungsi untuk menampilkan custom alert
        function showCustomAlert(title, message, type = 'error') {
            // Hapus alert sebelumnya jika ada
            const existingAlert = document.getElementById('customAlert');
            const existingOverlay = document.getElementById('alertOverlay');
            if (existingAlert) existingAlert.remove();
            if (existingOverlay) existingOverlay.remove();
            
            // Buat overlay
            const overlay = document.createElement('div');
            overlay.id = 'alertOverlay';
            overlay.className = 'alert-overlay';
            
            // Buat alert container
            const alertDiv = document.createElement('div');
            alertDiv.id = 'customAlert';
            alertDiv.className = 'custom-alert';
            
            // Tentukan icon berdasarkan type
            let icon = 'exclamation-triangle';
            let headerBg = 'linear-gradient(135deg, var(--danger) 0%, #c82333 100%)';
            
            if (type === 'success') {
                icon = 'check-circle';
                headerBg = 'linear-gradient(135deg, var(--success) 0%, #1e7e34 100%)';
            } else if (type === 'warning') {
                icon = 'exclamation-circle';
                headerBg = 'linear-gradient(135deg, var(--warning) 0%, #e0a800 100%)';
            }
            
            alertDiv.innerHTML = `
                <div class="alert-header" style="background: ${headerBg};">
                    <h3>${title}</h3>
                </div>
                <div class="alert-body">
                    <div class="alert-icon">
                        <i class="fas fa-${icon}"></i>
                    </div>
                    <div class="alert-message">${message}</div>
                    <div class="alert-actions">
                        <button class="alert-btn alert-btn-ok" onclick="closeCustomAlert()">
                            OK
                        </button>
                    </div>
                </div>
            `;
            
            // Tambahkan ke body
            document.body.appendChild(overlay);
            document.body.appendChild(alertDiv);
            
            // Fokus ke tombol OK
            setTimeout(() => {
                const okBtn = alertDiv.querySelector('.alert-btn-ok');
                if (okBtn) okBtn.focus();
            }, 100);
            
            // Tutup saat klik overlay
            overlay.addEventListener('click', closeCustomAlert);
            
            // Tutup dengan ESC key
            document.addEventListener('keydown', function closeOnEsc(e) {
                if (e.key === 'Escape') {
                    closeCustomAlert();
                    document.removeEventListener('keydown', closeOnEsc);
                }
            });
        }
        
        // Fungsi untuk menutup custom alert
        function closeCustomAlert() {
            const alert = document.getElementById('customAlert');
            const overlay = document.getElementById('alertOverlay');
            
            if (alert) {
                alert.style.animation = 'alertSlideIn 0.3s ease reverse';
                setTimeout(() => alert.remove(), 300);
            }
            if (overlay) overlay.remove();
            
            // Fokus kembali ke input field
            const inputField = document.getElementById('nama_kategori');
            if (inputField) {
                inputField.focus();
                inputField.select();
            }
        }
        
        // Form validation
        document.getElementById('createCategoryForm')?.addEventListener('submit', function(e) {
            const namaKategori = document.getElementById('nama_kategori').value.trim();
            const duplicateWarning = document.getElementById('duplicateWarning');
            const submitBtn = document.getElementById('submitBtn');
            
            // Reset previous errors
            document.getElementById('errorMessage')?.style.display = 'none';
            
            // Validasi nama kategori
            if (!namaKategori) {
                e.preventDefault();
                showError('Nama kategori tidak boleh kosong.');
                return;
            }
            
            if (namaKategori.length < 3) {
                e.preventDefault();
                showError('Nama kategori minimal 3 karakter.');
                return;
            }
            
            if (namaKategori.length > 100) {
                e.preventDefault();
                showError('Nama kategori maksimal 100 karakter.');
                return;
            }
            
            // Cek jika ada warning duplikasi
            if (duplicateWarning.classList.contains('error') || (submitBtn && submitBtn.disabled)) {
                e.preventDefault();
                
                // Tampilkan alert
                showCustomAlert(
                    'Kategori Sudah Ada',
                    'Nama dengan kategori tersebut sudah ada. Silakan gunakan nama lain.',
                    'error'
                );
                
                // Fokus ke input field
                const inputField = document.getElementById('nama_kategori');
                if (inputField) {
                    inputField.focus();
                    inputField.select();
                }
                
                return;
            }
            
            // Show loading animation
            const loadingDiv = document.getElementById('loading');
            
            if (submitBtn && loadingDiv) {
                submitBtn.style.display = 'none';
                loadingDiv.style.display = 'block';
            }
        });
        
        function showError(message) {
            // Create error message div if it doesn't exist
            let errorDiv = document.getElementById('errorMessage');
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.id = 'errorMessage';
                errorDiv.className = 'error-message';
                const contentDiv = document.querySelector('.content');
                const form = document.querySelector('form');
                if (form) {
                    contentDiv.insertBefore(errorDiv, form);
                } else {
                    contentDiv.prepend(errorDiv);
                }
            }
            
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            errorDiv.style.display = 'block';
            
            // Scroll to error message
            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Auto-hide setelah 5 detik
            setTimeout(() => {
                errorDiv.style.display = 'none';
            }, 5000);
        }
        
        // Focus on input field
        document.addEventListener('DOMContentLoaded', function() {
            const namaKategoriInput = document.getElementById('nama_kategori');
            if (namaKategoriInput) {
                namaKategoriInput.focus();
                
                // Cek duplikasi jika ada value sebelumnya
                if (namaKategoriInput.value.trim().length >= 3) {
                    checkDuplicateCategory(namaKategoriInput.value);
                }
            }
            
            // Nonaktifkan tombol submit saat pertama kali load
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn && !namaKategoriInput.value.trim()) {
                submitBtn.disabled = true;
            }
            
            // Tampilkan alert dari PHP jika ada
            <?php if (!empty($success_message)): ?>
            showCustomAlert(
                'Berhasil!',
                'Kategori berhasil ditambahkan ke database.',
                'success'
            );
            <?php endif; ?>
        });
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>