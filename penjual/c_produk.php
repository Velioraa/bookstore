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
    $id_user = $user['id_user']; // Ambil id_user (bukan nik) untuk foreign key
    $nik = $user['nik']; // Simpan nik juga jika diperlukan
    
    // Ambil data kategori dari database untuk dropdown
    $sql_kategori = "SELECT id_kategori, nama_kategori FROM katagori ORDER BY nama_kategori ASC";
    $result_kategori = $conn->query($sql_kategori);
    $kategori_data = array();
    
    if ($result_kategori->num_rows > 0) {
        while ($row = $result_kategori->fetch_assoc()) {
            $kategori_data[] = $row;
        }
    }
    
} else {
    // Jika user tidak ditemukan, logout
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// Proses form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $errors = array();
    $success = false;
    
    // Validasi dan sanitasi input
    $nama_produk = trim($_POST['nama_produk'] ?? '');
    $id_kategori = $_POST['id_kategori'] ?? '';
    $stok = $_POST['stok'] ?? 0;
    $modal = $_POST['modal'] ?? 0;
    $harga_jual = $_POST['harga_jual'] ?? 0;
    $deskripsi = trim($_POST['deskripsi'] ?? '');
    $gambar_filename = ''; // Initialize
    
    // Validasi nama produk
    if (empty($nama_produk)) {
        $errors['nama_produk'] = 'Nama produk wajib diisi';
    } elseif (strlen($nama_produk) < 3) {
        $errors['nama_produk'] = 'Nama produk minimal 3 karakter';
    } else {
        // ============================
        // PERIKSA APAKAH NAMA PRODUK SUDAH ADA
        // ============================
        $sql_check = "SELECT COUNT(*) as total FROM produk WHERE nama_produk = ? AND id_penjual = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("si", $nama_produk, $id_user);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $row_check = $result_check->fetch_assoc();
        
        if ($row_check['total'] > 0) {
            $errors['nama_produk'] = 'Nama produk "' . htmlspecialchars($nama_produk) . '" sudah ada. Silakan gunakan nama lain.';
        }
        $stmt_check->close();
    }
    
    // Validasi kategori
    if (empty($id_kategori) || $id_kategori == '0') {
        $errors['id_kategori'] = 'Kategori wajib dipilih';
    }
    
    // Validasi stok
    $stok = (int)$stok;
    if ($stok < 0) {
        $errors['stok'] = 'Stok tidak boleh negatif';
    }
    
    // Validasi modal
    $modal = (float)str_replace(['.', ','], ['', '.'], $modal);
    if ($modal <= 0) {
        $errors['modal'] = 'Modal harus lebih dari 0';
    }
    
    // Validasi harga jual
    $harga_jual = (float)str_replace(['.', ','], ['', '.'], $harga_jual);
    if ($harga_jual <= 0) {
        $errors['harga_jual'] = 'Harga jual harus lebih dari 0';
    } elseif ($harga_jual <= $modal) {
        $errors['harga_jual'] = 'Harga jual harus lebih besar dari modal';
    }
    
    // ============================
    // PERBAIKAN UPLOAD GAMBAR DI SINI
    // ============================
    
    // Proses upload gambar
    if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['gambar'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_error = $file['error'];
        
        // Cek jika ada error dalam upload
        if ($file_error === UPLOAD_ERR_OK) {
            // Validasi ekstensi file yang diizinkan
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, $allowed_extensions)) {
                $errors['gambar'] = 'Format file tidak didukung. Hanya JPG, JPEG, PNG, WEBP, GIF yang diperbolehkan';
            }
            // Validasi ukuran file (max 2MB = 2097152 bytes)
            elseif ($file_size > 2097152) {
                $errors['gambar'] = 'Ukuran file maksimal 2MB';
            }
            // Validasi tipe MIME
            else {
                $allowed_mime_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file_tmp);
                finfo_close($finfo);
                
                if (!in_array($mime_type, $allowed_mime_types)) {
                    $errors['gambar'] = 'File bukan gambar yang valid';
                } else {
                    // Generate unique filename
                    $gambar_filename = 'produk_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $upload_path = '../asset/' . $gambar_filename;
                    
                    // Pastikan folder asset ada
                    if (!is_dir('../asset/')) {
                        // Coba buat folder jika tidak ada
                        if (!mkdir('../asset/', 0755, true)) {
                            $errors['gambar'] = 'Folder asset tidak ditemukan dan gagal dibuat';
                        }
                    }
                    
                    // Coba upload file
                    if (empty($errors)) {
                        if (!move_uploaded_file($file_tmp, $upload_path)) {
                            $errors['gambar'] = 'Gagal mengupload gambar. Pastikan folder asset ada dan writable';
                            $gambar_filename = '';
                        }
                    }
                }
            }
        } else {
            // Handle error upload
            $upload_errors = [
                0 => 'File berhasil diupload',
                1 => 'File melebihi ukuran maksimal php.ini',
                2 => 'File melebihi ukuran maksimal form',
                3 => 'File hanya terupload sebagian',
                4 => 'Tidak ada file yang diupload',
                6 => 'Tidak ada folder temporary',
                7 => 'Gagal menulis ke disk',
                8 => 'Ekstensi PHP menghentikan upload'
            ];
            $errors['gambar'] = 'Error upload: ' . ($upload_errors[$file_error] ?? 'Unknown error');
        }
    }
    
    // Hitung keuntungan
    $keuntungan = 0;
    if ($modal > 0 && $harga_jual > 0) {
        $keuntungan = $harga_jual - $modal;
    }
    
    // Jika tidak ada error, simpan ke database
    if (empty($errors)) {
        // Format harga untuk database
        $modal_db = number_format($modal, 0, '', '');
        $harga_jual_db = number_format($harga_jual, 0, '', '');
        $keuntungan_db = number_format($keuntungan, 0, '', '');
        
        // Insert data ke database - PERBAIKAN: gunakan id_user bukan nik
        $sql_insert = "INSERT INTO produk (
            id_penjual, 
            id_kategori, 
            nama_produk, 
            stok, 
            harga_jual, 
            modal, 
            keuntungan, 
            gambar, 
            deskripsi,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param(
            "iisiiidss",  // i untuk id_user (integer), bukan s untuk string
            $id_user,      // Gunakan id_user bukan nik
            $id_kategori, 
            $nama_produk, 
            $stok, 
            $harga_jual_db, 
            $modal_db, 
            $keuntungan_db, 
            $gambar_filename, 
            $deskripsi
        );
        
        if ($stmt_insert->execute()) {
            $success = true;
            $success_message = 'Produk berhasil ditambahkan!';
            
            // Reset form setelah sukses
            $_POST = array();
        } else {
            $errors['database'] = 'Gagal menyimpan ke database: ' . $conn->error;
            
            // Hapus file yang sudah diupload jika gagal insert
            if (!empty($gambar_filename) && file_exists('../asset/' . $gambar_filename)) {
                unlink('../asset/' . $gambar_filename);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Produk Baru - Wesley Bookstore</title>
    <link rel="website icon" type="png" href="../asset/wesley.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
:root {
    --primary: #4a90e2;
    --secondary: #2c7be5;
    --accent: #5d9cec;
    --light: #f8faff;
    --dark: #34495e;
    --gray: #7f8c8d;
    --success: #27ae60;
    --danger: #e74c3c;
    --warning: #f39c12;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

html, body {
    overflow-x: hidden;
    width: 100%;
    background: linear-gradient(135deg, #f5f7fa 0%, #e3e9f1 100%);
    color: var(--dark);
    min-height: 100vh;
}

/* Main Container */
.main-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Form Container */
.form-container {
    background: white;
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(74, 144, 226, 0.1);
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
    border: 1px solid #e3f2fd;
}

.form-header {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e3f2fd;
}

.form-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-subtitle {
    color: var(--gray);
    font-size: 0.9rem;
    margin-top: 5px;
}

/* Form Groups */
.form-group {
    margin-bottom: 25px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
    margin-bottom: 25px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--dark);
    font-size: 0.95rem;
}

.form-label .required {
    color: var(--danger);
    margin-left: 3px;
}

.form-input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e3f2fd;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    box-sizing: border-box;
    background: #f8faff;
    color: var(--dark);
}

.form-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
    background: white;
}

.form-input::placeholder {
    color: #a0aec0;
}

.form-textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e3f2fd;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    box-sizing: border-box;
    background: #f8faff;
    color: var(--dark);
    min-height: 120px;
    resize: vertical;
    font-family: inherit;
}

.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
    background: white;
}

.form-select {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e3f2fd;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    box-sizing: border-box;
    background: #f8faff;
    color: var(--dark);
    cursor: pointer;
}

.form-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
    background: white;
}

/* File Upload */
.file-upload {
    position: relative;
}

.file-input {
    width: 0.1px;
    height: 0.1px;
    opacity: 0;
    overflow: hidden;
    position: absolute;
    z-index: -1;
}

.file-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    border: 2px dashed #e3f2fd;
    border-radius: 8px;
    background: #f8faff;
    cursor: pointer;
    transition: all 0.3s ease;
}

.file-label:hover {
    border-color: var(--primary);
    background: #e3f2fd;
}

.file-label.highlight {
    border-color: var(--primary);
    background: #e3f2fd;
    border-style: solid;
}

.file-icon {
    color: var(--primary);
    font-size: 1.2rem;
}

.file-text {
    flex-grow: 1;
    color: var(--gray);
    font-size: 0.9rem;
}

.file-name {
    color: var(--primary);
    font-weight: 500;
}

.file-info {
    margin-top: 8px;
    font-size: 0.85rem;
    color: var(--gray);
}

/* Preview Image */
.preview-container {
    margin-top: 15px;
    display: none;
}

.preview-image {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    border: 2px solid #e3f2fd;
    object-fit: cover;
}

/* Form Help Text */
.form-help {
    margin-top: 5px;
    font-size: 0.85rem;
    color: var(--gray);
    font-style: italic;
}

/* Error Messages */
.error-message {
    margin-top: 5px;
    font-size: 0.85rem;
    color: var(--danger);
    display: flex;
    align-items: center;
    gap: 5px;
}

.error-message i {
    font-size: 0.8rem;
}

/* Success Message */
.success-message {
    background: rgba(39, 174, 96, 0.1);
    border: 1px solid var(--success);
    color: var(--success);
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Keuntungan Info */
.keuntungan-info {
    background: #f8faff;
    border: 1px solid #e3f2fd;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.keuntungan-title {
    font-weight: 600;
    color: var(--primary);
    margin-bottom: 10px;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.keuntungan-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
}

.keuntungan-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px dashed #e3f2fd;
}

.keuntungan-item:last-child {
    border-bottom: none;
    font-weight: 600;
    color: var(--success);
    background: rgba(39, 174, 96, 0.1);
    padding: 10px;
    border-radius: 6px;
    margin-top: 5px;
}

.keuntungan-label {
    color: var(--gray);
    font-size: 0.9rem;
}

.keuntungan-value {
    color: var(--dark);
    font-weight: 500;
}

.keuntungan-nilai {
    color: var(--success);
    font-weight: 600;
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 40px;
    padding-top: 25px;
    border-top: 2px solid #e3f2fd;
}

.btn {
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    white-space: nowrap;
    flex-shrink: 0;
    border: none;
    font-size: 0.95rem;
}

.btn-primary {
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    color: white;
    box-shadow: 0 4px 10px rgba(74, 144, 226, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(74, 144, 226, 0.4);
}

.btn-secondary {
    background: #f8faff;
    color: var(--primary);
    border: 2px solid #e3f2fd;
}

.btn-secondary:hover {
    background: #e3f2fd;
}

.btn-cancel {
    background: white;
    color: var(--gray);
    border: 2px solid #e3f2fd;
}

.btn-cancel:hover {
    background: #f8f9fa;
    color: var(--dark);
}

/* Responsive */
@media (max-width: 768px) {
    .main-container {
        padding: 0 15px;
    }
    
    .form-container {
        padding: 20px 15px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .header-content {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .back-link {
        align-self: flex-start;
    }
}

@media (max-width: 576px) {
    .main-container {
        padding: 0 10px;
    }
    
    .form-container {
        padding: 15px 10px;
    }
    
    .logo-text {
        font-size: 1.3rem;
    }
}
    </style>
</head>
<body>    <!-- Main Content -->
    <main class="main-container">
        <!-- Form Container -->
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    Form Tambah Produk Baru
                </h2>
                <p class="form-subtitle">
                    Lengkapi form berikut untuk menambahkan produk baru ke toko Anda.
                </p>
            </div>

            <?php if (isset($success) && $success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($errors['database'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $errors['database']; ?></span>
                </div>
            <?php endif; ?>

            <!-- FORM dengan enctype untuk upload -->
            <form method="POST" enctype="multipart/form-data" id="produkForm">
                <div class="form-row">
                    <!-- Nama Produk -->
                    <div class="form-group">
                        <label for="nama_produk" class="form-label">
                            Nama Produk <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="nama_produk" 
                            name="nama_produk" 
                            class="form-input" 
                            placeholder="Masukkan nama produk"
                            value="<?php echo htmlspecialchars($_POST['nama_produk'] ?? ''); ?>"
                            required
                        >
                        <div class="form-help">
                            Nama produk yang akan ditampilkan (tidak boleh duplikat)
                        </div>
                        <?php if (isset($errors['nama_produk'])): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?php echo $errors['nama_produk']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Kategori -->
                    <div class="form-group">
                        <label for="id_kategori" class="form-label">
                            Kategori <span class="required">*</span>
                        </label>
                        <select 
                            id="id_kategori" 
                            name="id_kategori" 
                            class="form-select"
                            required
                        >
                            <option value="0">Pilih Kategori</option>
                            <?php foreach ($kategori_data as $kategori): ?>
                                <option 
                                    value="<?php echo $kategori['id_kategori']; ?>"
                                    <?php echo (($_POST['id_kategori'] ?? '') == $kategori['id_kategori']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($kategori['nama_kategori']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-help">
                            Pilih kategori produk
                        </div>
                        <?php if (isset($errors['id_kategori'])): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?php echo $errors['id_kategori']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <!-- Stok -->
                    <div class="form-group">
                        <label for="stok" class="form-label">
                            Stok <span class="required">*</span>
                        </label>
                        <input 
                            type="number" 
                            id="stok" 
                            name="stok" 
                            class="form-input" 
                            placeholder="Jumlah stok"
                            value="<?php echo htmlspecialchars($_POST['stok'] ?? '0'); ?>"
                            min="0"
                            required
                        >
                        <div class="form-help">
                            Jumlah produk yang tersedia
                        </div>
                        <?php if (isset($errors['stok'])): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?php echo $errors['stok']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Modal -->
                    <div class="form-group">
                        <label for="modal" class="form-label">
                            Modal (Rp) <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="modal" 
                            name="modal" 
                            class="form-input" 
                            placeholder="Harga modal"
                            value="<?php echo htmlspecialchars($_POST['modal'] ?? '0'); ?>"
                            required
                        >
                        <div class="form-help">
                            Harga beli produk
                        </div>
                        <?php if (isset($errors['modal'])): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?php echo $errors['modal']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <!-- Harga Jual -->
                    <div class="form-group">
                        <label for="harga_jual" class="form-label">
                            Harga Jual (Rp) <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="harga_jual" 
                            name="harga_jual" 
                            class="form-input" 
                            placeholder="Harga jual"
                            value="<?php echo htmlspecialchars($_POST['harga_jual'] ?? '0'); ?>"
                            required
                        >
                        <div class="form-help">
                            Harga jual ke konsumen
                        </div>
                        <?php if (isset($errors['harga_jual'])): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?php echo $errors['harga_jual']; ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Keuntungan Info -->
                <div id="keuntunganInfo" class="keuntungan-info" style="display: none;">
                    <div class="keuntungan-title">
                        <i class="fas fa-calculator"></i>
                        Perhitungan Otomatis
                    </div>
                    <div class="keuntungan-details">
                        <div class="keuntungan-item">
                            <span class="keuntungan-label">Modal:</span>
                            <span class="keuntungan-value" id="infoModal">Rp 0</span>
                        </div>
                        <div class="keuntungan-item">
                            <span class="keuntungan-label">Harga Jual:</span>
                            <span class="keuntungan-value" id="infoHargaJual">Rp 0</span>
                        </div>
                        <div class="keuntungan-item">
                            <span class="keuntungan-label">Keuntungan per unit:</span>
                            <span class="keuntungan-nilai" id="infoKeuntungan">Rp 0</span>
                        </div>
                    </div>
                </div>

                <!-- Gambar Produk -->
                <div class="form-group">
                    <label class="form-label">
                        Gambar Produk
                    </label>
                    <div class="file-upload">
                        <input 
                            type="file" 
                            id="gambar" 
                            name="gambar" 
                            class="file-input" 
                            accept=".jpg,.jpeg,.png,.webp,.gif"
                            onchange="previewImage(this)"
                        >
                        <label for="gambar" class="file-label" id="fileLabel">
                            <span class="file-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </span>
                            <span class="file-text" id="fileText">
                                Klik untuk memilih gambar atau drag & drop
                            </span>
                        </label>
                        <div class="preview-container" id="previewContainer">
                            <img src="" alt="Preview" class="preview-image" id="previewImage">
                        </div>
                    </div>
                    <div class="form-help">
                        Format: JPG, JPEG, PNG, WEBP, GIF (opsional, maksimal 2MB)
                    </div>
                    <?php if (isset($errors['gambar'])): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?php echo htmlspecialchars($errors['gambar']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Deskripsi Produk -->
                <div class="form-group">
                    <label for="deskripsi" class="form-label">
                        Deskripsi Produk
                    </label>
                    <textarea 
                        id="deskripsi" 
                        name="deskripsi" 
                        class="form-textarea" 
                        placeholder="Masukkan deskripsi produk"
                        rows="5"
                    ><?php echo htmlspecialchars($_POST['deskripsi'] ?? ''); ?></textarea>
                    <div class="form-help">
                        Deskripsi detail tentang produk
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="window.location.href='produk.php'">
                        <i class="fas fa-times"></i>
                        Batal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Simpan Produk
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Format Rupiah
        function formatRupiah(angka) {
            if (!angka || angka === 0) return 'Rp 0';
            const number_string = angka.toString().replace(/[^,\d]/g, '');
            const split = number_string.split(',');
            const sisa = split[0].length % 3;
            let rupiah = split[0].substr(0, sisa);
            const ribuan = split[0].substr(sisa).match(/\d{3}/gi);
            
            if (ribuan) {
                const separator = sisa ? '.' : '';
                rupiah += separator + ribuan.join('.');
            }
            
            rupiah = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
            return 'Rp ' + rupiah;
        }

        // Format input rupiah
        function formatInputRupiah(input) {
            let value = input.value.replace(/[^0-9]/g, '');
            if (value) {
                value = parseInt(value, 10);
                input.value = formatRupiah(value).replace('Rp ', '');
            } else {
                input.value = '';
            }
        }

        // Initialize rupiah formatting
        document.getElementById('modal')?.addEventListener('input', function() {
            formatInputRupiah(this);
            calculateKeuntungan();
        });

        document.getElementById('harga_jual')?.addEventListener('input', function() {
            formatInputRupiah(this);
            calculateKeuntungan();
        });

        // Calculate keuntungan
        function calculateKeuntungan() {
            const modalInput = document.getElementById('modal');
            const hargaJualInput = document.getElementById('harga_jual');
            const keuntunganInfo = document.getElementById('keuntunganInfo');
            
            if (!modalInput || !hargaJualInput || !keuntunganInfo) return;
            
            const modal = parseFloat(modalInput.value.replace(/[^0-9]/g, '')) || 0;
            const hargaJual = parseFloat(hargaJualInput.value.replace(/[^0-9]/g, '')) || 0;
            
            if (modal > 0 && hargaJual > 0) {
                const keuntungan = hargaJual - modal;
                
                document.getElementById('infoModal').textContent = formatRupiah(modal);
                document.getElementById('infoHargaJual').textContent = formatRupiah(hargaJual);
                document.getElementById('infoKeuntungan').textContent = formatRupiah(keuntungan);
                
                keuntunganInfo.style.display = 'block';
            } else {
                keuntunganInfo.style.display = 'none';
            }
        }

        // Format bytes untuk display file size
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        // Image preview dengan validasi
        function previewImage(input) {
            const fileText = document.getElementById('fileText');
            const previewContainer = document.getElementById('previewContainer');
            const previewImage = document.getElementById('previewImage');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validasi ukuran file (max 2MB)
                if (file.size > 2097152) {
                    alert('Ukuran file terlalu besar. Maksimal 2MB');
                    input.value = '';
                    fileText.textContent = 'Klik untuk memilih gambar atau drag & drop';
                    previewContainer.style.display = 'none';
                    return;
                }
                
                // Validasi tipe file
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Format file tidak didukung. Hanya JPG, JPEG, PNG, WEBP, GIF yang diperbolehkan');
                    input.value = '';
                    fileText.textContent = 'Klik untuk memilih gambar atau drag & drop';
                    previewContainer.style.display = 'none';
                    return;
                }
                
                // Tampilkan nama file dan ukuran
                fileText.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                
                // Tampilkan preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewContainer.style.display = 'block';
                }
                reader.readAsDataURL(file);
            } else {
                fileText.textContent = 'Klik untuk memilih gambar atau drag & drop';
                previewContainer.style.display = 'none';
            }
        }

        // Drag and drop functionality
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('gambar');
            const fileLabel = document.getElementById('fileLabel');
            
            // Prevent default drag behaviors
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                fileLabel.addEventListener(eventName, preventDefaults, false);
                document.body.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            // Highlight drop area when item is dragged over it
            ['dragenter', 'dragover'].forEach(eventName => {
                fileLabel.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                fileLabel.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight(e) {
                fileLabel.classList.add('highlight');
            }
            
            function unhighlight(e) {
                fileLabel.classList.remove('highlight');
            }
            
            // Handle dropped files
            fileLabel.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files.length > 0) {
                    fileInput.files = files;
                    previewImage(fileInput);
                }
            }, false);
            
            // AJAX check for duplicate product name
            const namaProdukInput = document.getElementById('nama_produk');
            let checkTimeout;
            
            namaProdukInput?.addEventListener('input', function() {
                clearTimeout(checkTimeout);
                
                checkTimeout = setTimeout(function() {
                    const namaProduk = namaProdukInput.value.trim();
                    
                    if (namaProduk.length >= 3) {
                        // Kirim AJAX request untuk mengecek duplikasi
                        fetch('check_product.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'nama_produk=' + encodeURIComponent(namaProduk)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.exists) {
                                // Tampilkan pesan error
                                let errorElement = namaProdukInput.parentNode.querySelector('.duplicate-error');
                                if (!errorElement) {
                                    errorElement = document.createElement('div');
                                    errorElement.className = 'error-message duplicate-error';
                                    errorElement.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>Nama produk "' + namaProduk + '" sudah ada. Silakan gunakan nama lain.</span>';
                                    namaProdukInput.parentNode.appendChild(errorElement);
                                }
                                namaProdukInput.style.borderColor = 'var(--danger)';
                            } else {
                                // Hapus pesan error jika ada
                                const errorElement = namaProdukInput.parentNode.querySelector('.duplicate-error');
                                if (errorElement) {
                                    errorElement.remove();
                                }
                                namaProdukInput.style.borderColor = '';
                            }
                        })
                        .catch(error => {
                            console.error('Error checking product name:', error);
                        });
                    }
                }, 500); // Delay 500ms untuk mengurangi request
            });
            
            // Form validation
            document.getElementById('produkForm')?.addEventListener('submit', function(e) {
                let valid = true;
                const errors = [];
                
                // Check nama produk
                const namaProduk = document.getElementById('nama_produk').value.trim();
                if (!namaProduk) {
                    errors.push('Nama produk wajib diisi');
                    valid = false;
                } else if (namaProduk.length < 3) {
                    errors.push('Nama produk minimal 3 karakter');
                    valid = false;
                }
                
                // Check kategori
                const kategori = document.getElementById('id_kategori').value;
                if (kategori === '0') {
                    errors.push('Kategori wajib dipilih');
                    valid = false;
                }
                
                // Check stok
                const stok = parseInt(document.getElementById('stok').value) || 0;
                if (stok < 0) {
                    errors.push('Stok tidak boleh negatif');
                    valid = false;
                }
                
                // Check modal and harga jual
                const modal = parseFloat(document.getElementById('modal').value.replace(/[^0-9]/g, '')) || 0;
                const hargaJual = parseFloat(document.getElementById('harga_jual').value.replace(/[^0-9]/g, '')) || 0;
                
                if (modal <= 0) {
                    errors.push('Modal harus lebih dari 0');
                    valid = false;
                }
                
                if (hargaJual <= 0) {
                    errors.push('Harga jual harus lebih dari 0');
                    valid = false;
                } else if (hargaJual <= modal) {
                    errors.push('Harga jual harus lebih besar dari modal');
                    valid = false;
                }
                
                // Validasi file jika diupload
                const fileInput = document.getElementById('gambar');
                if (fileInput.files.length > 0) {
                    const file = fileInput.files[0];
                    if (file.size > 2097152) {
                        errors.push('Ukuran gambar maksimal 2MB');
                        valid = false;
                    }
                    
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        errors.push('Format gambar tidak didukung. Hanya JPG, JPEG, PNG, WEBP, GIF');
                        valid = false;
                    }
                }
                
                if (!valid) {
                    e.preventDefault();
                    alert('Terdapat kesalahan dalam form:\n\n' + errors.join('\n'));
                }
            });
            
            // Initialize on load
            calculateKeuntungan();
            
            // Format existing values
            const modalInput = document.getElementById('modal');
            const hargaJualInput = document.getElementById('harga_jual');
            
            if (modalInput && modalInput.value) {
                formatInputRupiah(modalInput);
            }
            
            if (hargaJualInput && hargaJualInput.value) {
                formatInputRupiah(hargaJualInput);
            }
        });
    </script>
</body>
</html>