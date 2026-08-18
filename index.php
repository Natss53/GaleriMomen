<?php
session_start();

$upload_dir = 'uploads/';
$admin_key = 'NikoAdmin2026'; // Ganti dengan key admin kamu

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$db = new PDO('sqlite:database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Buat Tabel Photos
$db->exec("CREATE TABLE IF NOT EXISTS photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    username TEXT NOT NULL,
    caption TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Buat Tabel Comments
$db->exec("CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    photo_id INTEGER NOT NULL,
    commenter TEXT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
)");

// ==========================================
// LOGIKA BACKEND
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Admin Login
    if ($action === 'admin_login') {
        if ($_POST['admin_key'] === $admin_key) {
            $_SESSION['is_admin'] = true;
            $_SESSION['message'] = 'Berhasil masuk sebagai Admin!';
            $_SESSION['msg_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Admin key salah!';
            $_SESSION['msg_type'] = 'danger';
        }
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    // 2. Admin Logout
    if ($action === 'admin_logout') {
        unset($_SESSION['is_admin']);
        $_SESSION['message'] = 'Berhasil keluar dari mode Admin.';
        $_SESSION['msg_type'] = 'info';
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    // 3. Proses Upload
    if ($action === 'upload') {
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $username = htmlspecialchars($_POST['username']);
            $caption = htmlspecialchars($_POST['caption']);
            
            $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $new_filename = date('YmdHis') . '_' . uniqid() . '.' . $file_ext;
            $target_file = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                $stmt = $db->prepare("INSERT INTO photos (filename, username, caption) VALUES (?, ?, ?)");
                $stmt->execute([$new_filename, $username, $caption]);
                
                $_SESSION['message'] = 'Foto berhasil di-post!';
                $_SESSION['msg_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Gagal menyimpan file foto.';
                $_SESSION['msg_type'] = 'danger';
            }
        }
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    // 4. Proses Hapus (Hanya Admin)
    if ($action === 'delete' && isset($_SESSION['is_admin'])) {
        $id = $_POST['id'];
        $stmt = $db->prepare("SELECT filename FROM photos WHERE id = ?");
        $stmt->execute([$id]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($photo) {
            $file_path = $upload_dir . $photo['filename'];
            if (file_exists($file_path)) { unlink($file_path); }
            
            $db->prepare("DELETE FROM photos WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM comments WHERE photo_id = ?")->execute([$id]);
            
            $_SESSION['message'] = 'Foto berhasil dihapus.';
            $_SESSION['msg_type'] = 'success';
        }
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    // 5. Proses Edit (Hanya Admin)
    if ($action === 'edit' && isset($_SESSION['is_admin'])) {
        $id = $_POST['id'];
        $new_username = htmlspecialchars($_POST['username']);
        $new_caption = htmlspecialchars($_POST['caption']);

        $stmt = $db->prepare("UPDATE photos SET username = ?, caption = ? WHERE id = ?");
        $stmt->execute([$new_username, $new_caption, $id]);
        
        $_SESSION['message'] = 'Informasi foto berhasil diperbarui.';
        $_SESSION['msg_type'] = 'success';
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }

    // 6. Tambah Komentar
    if ($action === 'add_comment') {
        $photo_id = $_POST['photo_id'];
        $commenter = htmlspecialchars($_POST['commenter']);
        $comment_text = htmlspecialchars($_POST['comment_text']);

        if (!empty($commenter) && !empty($comment_text)) {
            $stmt = $db->prepare("INSERT INTO comments (photo_id, commenter, comment_text) VALUES (?, ?, ?)");
            $stmt->execute([$photo_id, $commenter, $comment_text]);
            $_SESSION['message'] = 'Komentar ditambahkan.';
            $_SESSION['msg_type'] = 'success';
        }
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    }
}

// Logika Pencarian & Filter Tanggal
$search = trim($_GET['search'] ?? '');
$filter_date = trim($_GET['date'] ?? '');

$query = "SELECT * FROM photos WHERE 1=1";
$params = [];

if ($search !== '') {
    $query .= " AND (username LIKE ? OR caption LIKE ?)";
    $searchTerm = "%" . $search . "%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filter_date !== '') {
    $query .= " AND DATE(created_at) = ?";
    $params[] = $filter_date;
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistik Publik (Untuk User & Admin)
$total_photos_public = $db->query("SELECT COUNT(*) FROM photos")->fetchColumn();
$total_unique_users = $db->query("SELECT COUNT(DISTINCT username) FROM photos")->fetchColumn();

// Statistik Khusus Admin
$total_comments_stmt = $db->query("SELECT COUNT(*) FROM comments");
$total_comments = $total_comments_stmt->fetchColumn();

$folder_size = 0;
foreach (glob($upload_dir . "*") as $file) {
    if (is_file($file)) { $folder_size += filesize($file); }
}
$folder_size_mb = round($folder_size / 1024 / 1024, 2);

$message = $_SESSION['message'] ?? '';
$msg_type = $_SESSION['msg_type'] ?? '';
unset($_SESSION['message'], $_SESSION['msg_type']);
?>

<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galeri Momen</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg border-bottom mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-camera-fill me-2 text-primary"></i>Galeri Momen</a>
            
            <div class="d-flex align-items-center gap-2">
                <?php if (isset($_SESSION['is_admin'])): ?>
                    <form action="" method="post" class="m-0">
                        <input type="hidden" name="action" value="admin_logout">
                        <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3"><i class="bi bi-shield-lock-fill me-1"></i> Logout Admin</button>
                    </form>
                <?php else: ?>
                    <!-- Menggunakan btn-outline-secondary agar tetap terlihat jelas di Light maupun Dark Mode -->
                    <button id="adminLoginBtn" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#adminModal">
                        <i class="bi bi-shield-lock me-1"></i> Admin Login
                    </button>
                <?php endif; ?>

                <button id="darkModeToggle" class="btn btn-outline-secondary btn-sm rounded-pill px-3" type="button">
                    <i class="bi bi-moon-fill" id="toggleIcon"></i> <span id="toggleText" class="ms-1 small">Dark</span>
                </button>
            </div>
        </div>
    </nav>

    <div class="container pb-5">
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm rounded-4 border-0" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- DASHBOARD STATISTIK ADMIN -->
        <?php if (isset($_SESSION['is_admin'])): ?>
            <div class="row justify-content-center mb-4">
                <div class="col-md-10 col-lg-8">
                    <div class="card admin-stats-card p-4 bg-primary text-white">
                        <h5 class="fw-bold mb-3"><i class="bi bi-speedometer2 me-2"></i>Dashboard Statistik Admin</h5>
                        <div class="row text-center g-3">
                            <div class="col-4">
                                <div class="p-2 bg-white bg-opacity-10 rounded-3">
                                    <h3 class="fw-bold mb-0"><?= $total_photos_public ?></h3>
                                    <small>Total Foto</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 bg-white bg-opacity-10 rounded-3">
                                    <h3 class="fw-bold mb-0"><?= $total_comments ?></h3>
                                    <small>Total Komentar</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 bg-white bg-opacity-10 rounded-3">
                                    <h3 class="fw-bold mb-0"><?= $folder_size_mb ?> MB</h3>
                                    <small>Penyimpanan</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- STATISTIK PUBLIK UNTUK USER & SEMUA PENGUNJUNG -->
        <div class="row justify-content-center mb-4">
            <div class="col-md-10 col-lg-8">
                <div class="card p-3 shadow-sm border-0 rounded-4 bg-body-tertiary">
                    <div class="row text-center g-2 align-items-center">
                        <div class="col-6 border-end">
                            <h4 class="fw-bold mb-0 text-primary"><?= $total_photos_public ?></h4>
                            <small class="text-muted" style="font-size: 0.8rem;"><i class="bi bi-images me-1"></i>Total Foto Diposting</small>
                        </div>
                        <div class="col-6">
                            <h4 class="fw-bold mb-0 text-success"><?= $total_unique_users ?></h4>
                            <small class="text-muted" style="font-size: 0.8rem;"><i class="bi bi-people me-1"></i>Total Kontributor Unik</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Upload -->
        <div class="row justify-content-center mb-4">
            <div class="col-md-8 col-lg-6">
                <div class="card upload-card p-4">
                    <h5 class="mb-4 text-center fw-bold"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Post Foto Baru</h5>
                    <form action="" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Pilih Foto (Kamera / Galeri)</label>
                            <input class="form-control form-control-lg py-2" type="file" name="photo" accept="image/*" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-semibold">Nama Anda</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" name="username" placeholder="Contoh: Niko" required>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label text-muted small fw-semibold">Keterangan</label>
                            <textarea class="form-control" name="caption" rows="2" placeholder="Ceritakan momen ini..." required></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 fw-semibold"><i class="bi bi-send-fill me-2"></i>Bagikan Sekarang</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Filter & Pencarian -->
        <div class="row justify-content-center mb-5">
            <div class="col-md-10 col-lg-8">
                <div class="card p-3 shadow-sm border-0 rounded-4">
                    <form action="" method="get" class="row g-2 align-items-center">
                        <div class="col-md-5">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" name="search" placeholder="Cari nama / keterangan..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                <input type="date" class="form-control" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                            </div>
                        </div>
                        <div class="col-md-3 d-flex gap-1">
                            <button class="btn btn-dark w-100 rounded-3" type="submit">Filter</button>
                            <?php if ($search !== '' || $filter_date !== ''): ?>
                                <a href="index.php" class="btn btn-outline-secondary rounded-3" title="Reset Filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <hr class="opacity-25 mb-5">

        <!-- Grid Foto -->
        <h4 class="fw-bold mb-4">
            <i class="bi bi-images text-secondary me-2"></i>Feed Terbaru 
        </h4>
        
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4">
            <?php foreach ($photos as $photo): ?>
            <div class="col">
                <div class="card photo-card h-100">
                    <img src="<?= $upload_dir . htmlspecialchars($photo['filename']) ?>" alt="Foto" loading="lazy">
                    
                    <div class="card-body d-flex flex-column">
                        <p class="caption-text mb-2">"<?= htmlspecialchars($photo['caption']) ?>"</p>
                        <p class="author-text mb-1 text-muted"><i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($photo['username']) ?></p>
                        <p class="text-muted mt-1 mb-2" style="font-size: 0.75rem;"><i class="bi bi-clock me-1"></i> <?= $photo['created_at'] ?></p>

                        <div class="comments-section mb-3">
                            <span class="fw-bold text-muted d-block mb-1" style="font-size: 0.75rem;">Komentar:</span>
                            <?php
                            $stmt_c = $db->prepare("SELECT * FROM comments WHERE photo_id = ? ORDER BY created_at ASC");
                            $stmt_c->execute([$photo['id']]);
                            $comments = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <?php if (empty($comments)): ?>
                                <span class="text-muted fst-italic" style="font-size: 0.75rem;">Belum ada komentar.</span>
                            <?php else: ?>
                                <?php foreach ($comments as $c): ?>
                                    <div class="mb-1">
                                        <strong><?= htmlspecialchars($c['commenter']) ?>:</strong> <?= htmlspecialchars($c['comment_text']) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <form action="" method="post" class="mt-auto">
                            <input type="hidden" name="action" value="add_comment">
                            <input type="hidden" name="photo_id" value="<?= $photo['id'] ?>">
                            <div class="input-group input-group-sm mb-1">
                                <input type="text" class="form-control" name="commenter" placeholder="Nama..." required>
                            </div>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" name="comment_text" placeholder="Tulis komentar..." required>
                                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-chat-fill"></i></button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="card-footer border-top-0 pt-0 pb-3 bg-transparent">
                        <div class="d-flex gap-2">
                            <a href="<?= $upload_dir . htmlspecialchars($photo['filename']) ?>" download class="btn btn-sm btn-outline-success w-50 rounded-2" title="Download Foto">
                                <i class="bi bi-download"></i> Unduh
                            </a>

                            <?php if (isset($_SESSION['is_admin'])): ?>
                                <form action="" method="post" onsubmit="return confirm('Yakin ingin menghapus foto ini?');" class="w-50">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $photo['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger w-100 rounded-2" type="submit"><i class="bi bi-trash-fill"></i> Hapus</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if (isset($_SESSION['is_admin'])): ?>
                            <button class="btn btn-sm btn-outline-secondary w-100 rounded-2 mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#editForm<?= $photo['id'] ?>">
                                <i class="bi bi-pencil-square me-1"></i> Edit Momen
                            </button>
                            
                            <div class="collapse mt-2" id="editForm<?= $photo['id'] ?>">
                                <div class="card card-body p-2 border-0 shadow-sm">
                                    <form action="" method="post">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="id" value="<?= $photo['id'] ?>">
                                        <div class="mb-1">
                                            <input type="text" class="form-control form-control-sm" name="username" value="<?= htmlspecialchars($photo['username']) ?>" required>
                                        </div>
                                        <div class="mb-1">
                                            <textarea class="form-control form-control-sm" name="caption" rows="2" required><?= htmlspecialchars($photo['caption']) ?></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-primary w-100">Simpan Perubahan</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($photos)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-camera-fill fs-1 opacity-50"></i>
                <p class="mt-3">Tidak ada foto yang ditemukan.</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL ADMIN LOGIN -->
    <div class="modal fade" id="adminModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold fs-6"><i class="bi bi-shield-lock me-1"></i> Login Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form action="" method="post">
                        <input type="hidden" name="action" value="admin_login">
                        <div class="mb-3">
                            <input type="password" class="form-control" name="admin_key" placeholder="Masukkan Admin Key" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-dark w-100 rounded-3">Masuk</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const toggleBtn = document.getElementById('darkModeToggle');
        const toggleIcon = document.getElementById('toggleIcon');
        const toggleText = document.getElementById('toggleText');
        const adminLoginBtn = document.getElementById('adminLoginBtn');
        const htmlElement = document.documentElement;

        const savedTheme = localStorage.getItem('theme') || 'light';
        setTheme(savedTheme);

        toggleBtn.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            setTheme(newTheme);
            localStorage.setItem('theme', newTheme);
        });

        function setTheme(theme) {
            htmlElement.setAttribute('data-bs-theme', theme);
            if (theme === 'dark') {
                toggleIcon.className = 'bi bi-sun-fill text-warning';
                toggleText.textContent = 'Light';
                toggleBtn.className = 'btn btn-outline-light btn-sm rounded-pill px-3';
                if (adminLoginBtn) {
                    adminLoginBtn.className = 'btn btn-outline-light btn-sm rounded-pill px-3';
                }
            } else {
                toggleIcon.className = 'bi bi-moon-fill';
                toggleText.textContent = 'Dark';
                toggleBtn.className = 'btn btn-outline-secondary btn-sm rounded-pill px-3';
                if (adminLoginBtn) {
                    adminLoginBtn.className = 'btn btn-outline-secondary btn-sm rounded-pill px-3';
                }
            }
        }
    </script>
</body>
</html>