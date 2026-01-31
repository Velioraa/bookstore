<?php
// notification_item.php - Template untuk item notifikasi
if (!isset($notif)) return;

// Determine icon and status based on type
$iconClass = 'icon-order';
$statusClass = 'status-pending';
$statusText = 'Pending';
$iconHTML = '<i class="fas fa-info-circle"></i>';

// Determine icon and status based on notification type
if ($notif['type'] == 'new_order' || $notif['judul'] == 'Pesanan Baru') {
    $iconClass = 'icon-new-order';
    $statusClass = 'status-pending';
    $statusText = 'Pesanan Baru';
    $iconHTML = '<i class="fas fa-shopping-cart"></i>';
} elseif ($notif['approve_status'] === 'approve' || 
          $notif['judul'] === 'Pesanan Disetujui' || 
          (isset($notif['judul']) && strpos($notif['judul'], 'Disetujui') !== false)) {
    $iconClass = 'icon-approval';
    $statusClass = 'status-approved';
    $statusText = 'Disetujui';
    $iconHTML = '<i class="fas fa-check-circle"></i>';
} elseif ($notif['approve_status'] === 'reject' || 
          $notif['judul'] === 'Pesanan Ditolak' ||
          (isset($notif['judul']) && strpos($notif['judul'], 'Ditolak') !== false)) {
    $iconClass = 'icon-rejection';
    $statusClass = 'status-rejected';
    $statusText = 'Ditolak';
    $iconHTML = '<i class="fas fa-times-circle"></i>';
} elseif ($notif['status_transaksi'] === 'dikirim') {
    $iconClass = 'icon-shipping';
    $statusClass = 'status-dikirim';
    $statusText = 'Dikirim';
    $iconHTML = '<i class="fas fa-truck"></i>';
}

// Default product image
$productImage = !empty($notif['produk_gambar']) ? '../asset/' . $notif['produk_gambar'] : '../asset/wesley.png';

// Format time
$notifTime = new DateTime($notif['created_at']);
$now = new DateTime();
$diff = $now->diff($notifTime);

if ($diff->d == 0) {
    if ($diff->h == 0) {
        $timeString = $diff->i . ' menit lalu';
    } else {
        $timeString = $diff->h . ' jam lalu';
    }
} elseif ($diff->d == 1) {
    $timeString = 'Kemarin';
} else {
    $timeString = $notifTime->format('d M Y');
}

// Check if unread
$unreadClass = $notif['is_read'] == 0 ? 'unread' : '';
?>

<div class="notif-item <?php echo $unreadClass; ?>" 
     data-id="<?php echo $notif['id_notifikasi']; ?>"
     onclick="viewNotification(<?php echo $notif['id_notifikasi']; ?>)">
    <div class="notif-icon <?php echo $iconClass; ?>">
        <?php echo $iconHTML; ?>
    </div>
    <div class="notif-content">
        <h4 class="notif-title"><?php echo htmlspecialchars($notif['judul'] ?? 'Notifikasi'); ?></h4>
        <p class="notif-message"><?php echo htmlspecialchars($notif['pesan'] ?? ''); ?></p>
        
        <?php if (!empty($notif['nama_produk'])): ?>
            <div class="notif-product">
                <img src="<?php echo $productImage; ?>" 
                     alt="<?php echo htmlspecialchars($notif['nama_produk']); ?>" 
                     class="product-image"
                     onerror="this.src='../asset/wesley.png'">
                <span class="product-name"><?php echo htmlspecialchars($notif['nama_produk']); ?></span>
                <?php if (!empty($notif['pembeli_nama'])): ?>
                    <span style="color: var(--gray); font-size: 0.85rem;">Dari: <?php echo htmlspecialchars($notif['pembeli_nama']); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="notif-meta">
            <span class="notif-time"><?php echo $timeString; ?></span>
            <?php if (!empty($notif['invoice_number'])): ?>
                <span class="notif-status <?php echo $statusClass; ?>">
                    <?php echo $statusText; ?> • <?php echo $notif['invoice_number']; ?>
                </span>
            <?php else: ?>
                <span class="notif-status <?php echo $statusClass; ?>">
                    <?php echo $statusText; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($notif['is_read'] == 0): ?>
        <div class="unread-dot"></div>
    <?php endif; ?>
</div>