<?php

require_once __DIR__ . '/../app/bootstrap.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_post();
    }

    render_page();
} catch (PDOException $exception) {
    render_database_error($exception);
}

function handle_post(): void
{
    $action = $_POST['action'] ?? '';

    match ($action) {
        'register' => register_user(),
        'login' => authenticate_user(),
        'logout' => do_logout(),
        'add_to_cart' => add_to_cart(),
        'update_cart' => update_cart(),
        'remove_from_cart' => remove_from_cart(),
        'checkout' => process_checkout(),
        'simulate_payment' => simulate_payment(),
        'save_product' => save_product(),
        'delete_product' => delete_product(),
        'update_order_status' => update_order_status(),
        default => redirect_to('home'),
    };
}

function register_user(): void
{
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        flash('danger', 'Nama, email valid, dan password minimal 6 karakter wajib diisi.');
        redirect_to('register');
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        flash('danger', 'Email sudah terdaftar. Silakan gunakan email lain atau login.');
        redirect_to('register');
    }

    $stmt = db()->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'user']);

    login_user([
        'id' => (int) db()->lastInsertId(),
        'name' => $name,
        'email' => $email,
        'role' => 'user',
    ]);

    flash('success', 'Registrasi berhasil. Selamat datang di ArrunaCoffee.');
    redirect_to('home');
}

function authenticate_user(): void
{
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        flash('danger', 'Email atau password tidak sesuai.');
        redirect_to('login');
    }

    login_user($user);
    flash('success', 'Login berhasil.');
    redirect_to('home');
}

function do_logout(): void
{
    logout_user();
    flash('success', 'Anda sudah logout.');
    redirect_to('home');
}

function add_to_cart(): void
{
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
    $product = product_by_id($productId);

    if (!$product || (int) $product['stock'] <= 0) {
        flash('danger', 'Produk tidak tersedia.');
        redirect_to('products');
    }

    $cart = cart();
    $currentQuantity = (int) ($cart[$productId] ?? 0);
    $cart[$productId] = min((int) $product['stock'], $currentQuantity + $quantity);
    set_cart($cart);

    flash('success', $product['name'] . ' ditambahkan ke keranjang.');
    redirect_to('cart');
}

function update_cart(): void
{
    $quantities = $_POST['quantities'] ?? [];
    $cart = [];

    foreach ($quantities as $productId => $quantity) {
        $productId = (int) $productId;
        $quantity = (int) $quantity;
        if ($productId > 0 && $quantity > 0) {
            $cart[$productId] = $quantity;
        }
    }

    set_cart($cart);
    cart_details();
    flash('success', 'Keranjang berhasil diperbarui.');
    redirect_to('cart');
}

function remove_from_cart(): void
{
    $productId = (int) ($_POST['product_id'] ?? 0);
    $cart = cart();
    unset($cart[$productId]);
    set_cart($cart);

    flash('success', 'Produk dihapus dari keranjang.');
    redirect_to('cart');
}

function process_checkout(): void
{
    require_login();

    $cartDetails = cart_details();
    if (!$cartDetails['items']) {
        flash('warning', 'Keranjang masih kosong.');
        redirect_to('cart');
    }

    $name = trim($_POST['customer_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? 'Transfer Bank');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || $address === '') {
        flash('danger', 'Lengkapi nama, email, nomor WhatsApp, dan alamat pengiriman.');
        redirect_to('checkout');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        foreach ($cartDetails['items'] as $item) {
            $productId = (int) $item['product']['id'];
            $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $stock = (int) $stmt->fetchColumn();

            if ($stock < (int) $item['quantity']) {
                throw new RuntimeException('Stok produk ' . $item['product']['name'] . ' tidak mencukupi.');
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO orders (user_id, customer_name, email, phone, address, payment_method, total, payment_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            current_user()['id'],
            $name,
            $email,
            $phone,
            $address,
            $paymentMethod,
            $cartDetails['total'],
            payment_code(),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, quantity, price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');

        foreach ($cartDetails['items'] as $item) {
            $product = $item['product'];
            $quantity = (int) $item['quantity'];
            $itemStmt->execute([
                $orderId,
                (int) $product['id'],
                $product['name'],
                $quantity,
                $product['price'],
                $item['subtotal'],
            ]);
            $stockStmt->execute([$quantity, (int) $product['id']]);
        }

        $pdo->commit();
        set_cart([]);
        flash('success', 'Checkout berhasil. Lanjutkan simulasi pembayaran.');
        redirect_to('order', ['id' => $orderId]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect_to('checkout');
    }
}

function simulate_payment(): void
{
    require_login();

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $where = is_admin() ? 'id = ?' : 'id = ? AND user_id = ?';
    $params = is_admin() ? [$orderId] : [$orderId, current_user()['id']];

    $stmt = db()->prepare("SELECT * FROM orders WHERE $where LIMIT 1");
    $stmt->execute($params);
    $order = $stmt->fetch();

    if (!$order) {
        flash('danger', 'Pesanan tidak ditemukan.');
        redirect_to('home');
    }

    db()->prepare("UPDATE orders SET payment_status = 'paid', order_status = 'diproses', paid_at = NOW() WHERE id = ?")
        ->execute([$orderId]);

    flash('success', 'Pembayaran dummy berhasil dikonfirmasi.');
    redirect_to('order', ['id' => $orderId]);
}

function save_product(): void
{
    require_admin();

    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $stock = max(0, (int) ($_POST['stock'] ?? 0));
    $imageUrl = trim($_POST['image_url'] ?? '');
    $featured = isset($_POST['featured']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $category === '' || $description === '' || $price <= 0 || $imageUrl === '') {
        flash('danger', 'Nama, kategori, deskripsi, harga, dan URL gambar wajib diisi.');
        redirect_to('product-form', $id ? ['id' => $id] : []);
    }

    if ($id > 0) {
        $stmt = db()->prepare(
            'UPDATE products
             SET name = ?, category = ?, description = ?, price = ?, stock = ?, image_url = ?, featured = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $category, $description, $price, $stock, $imageUrl, $featured, $isActive, $id]);
        flash('success', 'Produk berhasil diperbarui.');
    } else {
        $stmt = db()->prepare(
            'INSERT INTO products (name, category, description, price, stock, image_url, featured, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $category, $description, $price, $stock, $imageUrl, $featured, $isActive]);
        flash('success', 'Produk baru berhasil ditambahkan.');
    }

    redirect_to('admin');
}

function delete_product(): void
{
    require_admin();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        flash('success', 'Produk berhasil dihapus.');
    }

    redirect_to('admin');
}

function update_order_status(): void
{
    require_admin();

    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = $_POST['order_status'] ?? 'baru';
    $allowed = ['baru', 'diproses', 'dikirim', 'selesai', 'dibatalkan'];

    if ($orderId > 0 && in_array($status, $allowed, true)) {
        db()->prepare('UPDATE orders SET order_status = ? WHERE id = ?')->execute([$status, $orderId]);
        flash('success', 'Status pesanan diperbarui.');
    }

    redirect_to('admin');
}

function render_page(): void
{
    $page = $_GET['page'] ?? 'home';

    if (in_array($page, ['checkout', 'order'], true)) {
        require_login();
    }

    if (in_array($page, ['admin', 'product-form'], true)) {
        require_admin();
    }

    if (current_user() && in_array($page, ['login', 'register'], true)) {
        redirect_to('home');
    }

    render_header(page_title($page));

    match ($page) {
        'home' => render_home(),
        'products' => render_products(),
        'product' => render_product_detail(),
        'cart' => render_cart(),
        'checkout' => render_checkout(),
        'order' => render_order(),
        'login' => render_login(),
        'register' => render_register(),
        'admin' => render_admin(),
        'product-form' => render_product_form(),
        default => render_not_found(),
    };

    render_footer();
}

function page_title(string $page): string
{
    return match ($page) {
        'products' => 'Katalog Produk',
        'product' => 'Detail Produk',
        'cart' => 'Keranjang Belanja',
        'checkout' => 'Checkout',
        'order' => 'Detail Pesanan',
        'login' => 'Login',
        'register' => 'Registrasi',
        'admin' => 'Dashboard Admin',
        'product-form' => 'Form Produk',
        default => 'ArrunaCoffee',
    };
}

function render_header(string $title): void
{
    $user = current_user();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | ArrunaCoffee</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
        <aside class="site-sidebar">
            <div class="sidebar-tagline">WHERE DRINKS MEET COMFORT</div>
        </aside>
        <header class="site-header">
            <a class="brand" href="index.php">
                <span class="brand-mark">A</span>
                <span>ArrunaCoffee</span>
            </a>
            <nav class="main-nav" aria-label="Navigasi utama">
                <a href="index.php">Beranda</a>
                <a href="index.php?page=products">Produk</a>
                <a href="index.php?page=cart">Keranjang <span class="badge"><?= cart_count() ?></span></a>
                <?php if (is_admin()): ?>
                    <a href="index.php?page=admin">Admin</a>
                <?php endif; ?>
            </nav>
            <div class="user-area">
                <?php if ($user): ?>
                    <span class="user-name"><?= e($user['name']) ?></span>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="logout">
                        <button class="btn btn-ghost" type="submit">Logout</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-ghost" href="index.php?page=login">Login</a>
                    <a class="btn btn-primary" href="index.php?page=register">Daftar</a>
                <?php endif; ?>
            </div>
        </header>

        <main>
            <?php foreach (take_flashes() as $message): ?>
                <div class="flash flash-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
            <?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    ?>
        </main>
        <footer class="site-footer">
            <div class="footer-content">
                <div class="footer-brand">
                    <h3>ArrunaCoffee</h3>
                    <p>Where Drinks Meet Comfort</p>
                </div>
                <div class="footer-contact">
                    <h4>Hubungi Kami</h4>
                    <p>Email: info@arrunacoffee.com</p>
                    <p>Whatsapp: +62 812-3456-7890</p>
                    <p class="social-media">Follow us @ArrunaCoffee_</p>
                </div>
                <div class="footer-info">
                    <p>Toko kopi online dengan minuman premium dan pastry berkualitas tinggi.</p>
                </div>
            </div>
        </footer>
        <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}

function render_home(): void
{
    $stmt = db()->query('SELECT * FROM products WHERE featured = 1 AND is_active = 1 ORDER BY id DESC LIMIT 4');
    $featuredProducts = $stmt->fetchAll();
    ?>
    <section class="hero">
        
        <div class="hero-content">
            <p class="eyebrow">Eksplorasi Kopi Premium</p>
            <h1>ArrunaCoffee</h1>
            <p>Nikmati minuman kopi specialty dan pastry artisanal yang dibuat dengan passion. Setiap cangkir adalah pengalaman yang berkesan.</p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="index.php?page=products">Belanja Sekarang</a>
                <a class="btn btn-light" href="index.php?page=cart">Lihat Keranjang</a>
            </div>
        </div>
    </section>

    <section class="below-hero">
        <div class="below-hero-image">
            <img src="/img/bawah-hero.jpeg" alt="ArrunaCoffee - Kopi Premium dan Pastry Artisanal">
        </div>
        <div class="below-hero-content">
            <h2>Pengalaman Kopi yang Tak Terlupakan</h2>
            <p>ArrunaCoffee menghadirkan koleksi minuman kopi specialty yang dipilih langsung dari perkebunan terbaik di nusantara. Setiap biji kopi disangrai dengan cermat untuk menghadirkan cita rasa yang kompleks dan seimbang.</p>
            <p>Kami juga menyediakan berbagai pilihan pastry artisanal premium, mulai dari croissant berlapis yang renyah hingga chocolate roll yang lezat. Sempurna untuk menemani momen istirahat Anda.</p>
            <ul class="highlight-list">
                <li>Biji kopi pilihan dari berbagai daerah</li>
                <li>Minuman specialty siap saji berkualitas tinggi</li>
                <li>Pastry artisanal dengan bahan premium</li>
                <li>Pengalaman berbelanja yang nyaman dan personal</li>
            </ul>
            <a class="btn btn-primary" href="index.php?page=products">Jelajahi Katalog Lengkap</a>
        </div>
    </section>

    <section class="section">
        <div class="section-heading">
            <p class="eyebrow">Produk pilihan</p>
            <h2>Rekomendasi untuk pesanan hari ini</h2>
            <a class="text-link" href="index.php?page=products">Lihat semua produk</a>
        </div>
        <div class="product-grid">
            <?php foreach ($featuredProducts as $product): ?>
                <?php render_product_card($product); ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="info-band">
        <div>
            <strong>Pesanan tercatat</strong>
            <span>Checkout menyimpan data pembeli, item, total, dan status pembayaran.</span>
        </div>
        <div>
            <strong>Stok terkendali</strong>
            <span>Stok produk otomatis berkurang setelah checkout berhasil.</span>
        </div>
        <div>
            <strong>Admin siap pakai</strong>
            <span>Admin dapat menambah, mengubah, dan menghapus produk dari dashboard.</span>
        </div>
    </section>
    <?php
}

function render_products(): void
{
    $search = trim($_GET['q'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $params = [];
    $sql = 'SELECT * FROM products WHERE is_active = 1';

    if ($search !== '') {
        $sql .= ' AND (name LIKE ? OR category LIKE ? OR description LIKE ?)';
        $keyword = '%' . $search . '%';
        array_push($params, $keyword, $keyword, $keyword);
    }

    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }

    $sql .= ' ORDER BY featured DESC, name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    ?>
    <section class="section compact">
        <div class="section-heading">
            <p class="eyebrow">Katalog</p>
            <h1>Katalog Produk</h1>
        </div>

        <form class="filter-bar" method="get">
            <input type="hidden" name="page" value="products">
            <label>
                <span>Cari produk</span>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Arabika, cold brew, filter">
            </label>
            <label>
                <span>Kategori</span>
                <select name="category">
                    <option value="">Semua kategori</option>
                    <?php foreach (categories() as $item): ?>
                        <option value="<?= e($item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= e($item) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit">Terapkan</button>
        </form>

        <?php if (!$products): ?>
            <div class="empty-state">Produk tidak ditemukan.</div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <?php render_product_card($product); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function render_product_card(array $product): void
{
    ?>
    <article class="product-card">
        <a class="product-image" href="index.php?page=product&id=<?= (int) $product['id'] ?>">
            <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
        </a>
        <div class="product-body">
            <div class="product-meta">
                <span><?= e($product['category']) ?></span>
                <span>Stok <?= (int) $product['stock'] ?></span>
            </div>
            <h3><a href="index.php?page=product&id=<?= (int) $product['id'] ?>"><?= e($product['name']) ?></a></h3>
            <p><?= e(excerpt($product['description'])) ?></p>
            <div class="product-footer">
                <strong><?= rupiah($product['price']) ?></strong>
                <form method="post">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button class="btn btn-small btn-primary" type="submit" <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>Tambah</button>
                </form>
            </div>
        </div>
    </article>
    <?php
}

function render_product_detail(): void
{
    $product = product_by_id((int) ($_GET['id'] ?? 0));

    if (!$product) {
        render_not_found('Produk tidak ditemukan.');
        return;
    }
    ?>
    <section class="section compact">
        <div class="detail-layout">
            <div class="detail-image">
                <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>">
            </div>
            <div class="detail-copy">
                <p class="eyebrow"><?= e($product['category']) ?></p>
                <h1><?= e($product['name']) ?></h1>
                <p class="detail-description"><?= e($product['description']) ?></p>
                <div class="price-row">
                    <strong><?= rupiah($product['price']) ?></strong>
                    <span><?= (int) $product['stock'] ?> stok tersedia</span>
                </div>
                <form class="purchase-form" method="post">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                    <label>
                        <span>Jumlah</span>
                        <input type="number" name="quantity" min="1" max="<?= (int) $product['stock'] ?>" value="1">
                    </label>
                    <button class="btn btn-primary" type="submit" <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>Masukkan Keranjang</button>
                </form>
            </div>
        </div>
    </section>
    <?php
}

function render_cart(): void
{
    $cartDetails = cart_details();
    ?>
    <section class="section compact">
        <div class="section-heading">
            <p class="eyebrow">Belanja</p>
            <h1>Keranjang Belanja</h1>
        </div>

        <?php if (!$cartDetails['items']): ?>
            <div class="empty-state">
                Keranjang masih kosong.
                <a class="btn btn-primary" href="index.php?page=products">Pilih Produk</a>
            </div>
        <?php else: ?>
            <div class="cart-panel">
                <form method="post" id="cart-update-form">
                    <input type="hidden" name="action" value="update_cart">
                </form>
                <div class="responsive-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Harga</th>
                                <th>Jumlah</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cartDetails['items'] as $item): ?>
                                <?php $product = $item['product']; ?>
                                <tr>
                                    <td>
                                        <div class="table-product">
                                            <img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>">
                                            <span><?= e($product['name']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= rupiah($product['price']) ?></td>
                                    <td>
                                        <input class="qty-input" form="cart-update-form" type="number" name="quantities[<?= (int) $product['id'] ?>]" min="0" max="<?= (int) $product['stock'] ?>" value="<?= (int) $item['quantity'] ?>">
                                    </td>
                                    <td><?= rupiah($item['subtotal']) ?></td>
                                    <td>
                                        <form method="post" data-confirm="Hapus produk dari keranjang?">
                                            <input type="hidden" name="action" value="remove_from_cart">
                                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                            <button class="btn btn-small btn-muted" type="submit">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="cart-summary">
                    <span>Total</span>
                    <strong><?= rupiah($cartDetails['total']) ?></strong>
                    <button class="btn btn-muted" form="cart-update-form" type="submit">Perbarui</button>
                    <a class="btn btn-primary" href="index.php?page=checkout">Checkout</a>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function render_checkout(): void
{
    $cartDetails = cart_details();
    $user = current_user();
    ?>
    <section class="section compact">
        <div class="section-heading">
            <p class="eyebrow">Checkout</p>
            <h1>Data Pengiriman</h1>
        </div>

        <?php if (!$cartDetails['items']): ?>
            <div class="empty-state">Keranjang kosong. <a class="text-link" href="index.php?page=products">Belanja dulu</a>.</div>
        <?php else: ?>
            <div class="checkout-layout">
                <form class="form-panel" method="post">
                    <input type="hidden" name="action" value="checkout">
                    <label>
                        <span>Nama penerima</span>
                        <input type="text" name="customer_name" value="<?= e($user['name']) ?>" required>
                    </label>
                    <label>
                        <span>Email</span>
                        <input type="email" name="email" value="<?= e($user['email']) ?>" required>
                    </label>
                    <label>
                        <span>Nomor WhatsApp</span>
                        <input type="tel" name="phone" placeholder="08xxxxxxxxxx" required>
                    </label>
                    <label>
                        <span>Alamat lengkap</span>
                        <textarea name="address" rows="5" placeholder="Nama jalan, kecamatan, kota, kode pos" required></textarea>
                    </label>
                    <label>
                        <span>Metode pembayaran</span>
                        <select name="payment_method">
                            <option>Transfer Bank</option>
                            <option>E-Wallet</option>
                            <option>COD Simulasi</option>
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">Buat Pesanan</button>
                </form>

                <aside class="summary-panel">
                    <h2>Ringkasan</h2>
                    <?php foreach ($cartDetails['items'] as $item): ?>
                        <div class="summary-row">
                            <span><?= e($item['product']['name']) ?> x <?= (int) $item['quantity'] ?></span>
                            <strong><?= rupiah($item['subtotal']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="summary-total">
                        <span>Total Bayar</span>
                        <strong><?= rupiah($cartDetails['total']) ?></strong>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function render_order(): void
{
    $orderId = (int) ($_GET['id'] ?? 0);
    $where = is_admin() ? 'o.id = ?' : 'o.id = ? AND o.user_id = ?';
    $params = is_admin() ? [$orderId] : [$orderId, current_user()['id']];

    $stmt = db()->prepare("SELECT o.*, u.name AS account_name FROM orders o JOIN users u ON u.id = o.user_id WHERE $where LIMIT 1");
    $stmt->execute($params);
    $order = $stmt->fetch();

    if (!$order) {
        render_not_found('Pesanan tidak ditemukan.');
        return;
    }

    $itemStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll();
    ?>
    <section class="section compact">
        <div class="order-panel">
            <div class="section-heading">
                <p class="eyebrow">Pesanan #<?= (int) $order['id'] ?></p>
                <h1>Status Pesanan</h1>
            </div>
            <div class="status-grid">
                <div>
                    <span>Status pembayaran</span>
                    <strong class="status-pill status-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></strong>
                </div>
                <div>
                    <span>Status pesanan</span>
                    <strong><?= e($order['order_status']) ?></strong>
                </div>
                <div>
                    <span>Kode pembayaran</span>
                    <strong><?= e($order['payment_code']) ?></strong>
                </div>
                <div>
                    <span>Total</span>
                    <strong><?= rupiah($order['total']) ?></strong>
                </div>
            </div>

            <?php if ($order['payment_status'] === 'pending'): ?>
                <form method="post" class="payment-box">
                    <input type="hidden" name="action" value="simulate_payment">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <div>
                        <strong>Simulasi Pembayaran</strong>
                        <p>Gunakan kode <?= e($order['payment_code']) ?> lalu klik konfirmasi untuk menandai pesanan sebagai lunas.</p>
                    </div>
                    <button class="btn btn-primary" type="submit">Konfirmasi Bayar</button>
                </form>
            <?php endif; ?>

            <div class="responsive-table">
                <table>
                    <thead>
                        <tr>
                            <th>Produk</th>
                            <th>Jumlah</th>
                            <th>Harga</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= e($item['product_name']) ?></td>
                                <td><?= (int) $item['quantity'] ?></td>
                                <td><?= rupiah($item['price']) ?></td>
                                <td><?= rupiah($item['subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php
}

function render_login(): void
{
    ?>
    <section class="auth-page">
        <form class="auth-card" method="post">
            <input type="hidden" name="action" value="login">
            <p class="eyebrow">Akun pengguna</p>
            <h1>Login</h1>
            <label>
                <span>Email</span>
                <input type="email" name="email" required>
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" required>
            </label>
            <button class="btn btn-primary" type="submit">Masuk</button>
            <p class="muted">Admin demo: admin@kopinusa.test / admin123</p>
            <a class="text-link" href="index.php?page=register">Buat akun pelanggan</a>
        </form>
    </section>
    <?php
}

function render_register(): void
{
    ?>
    <section class="auth-page">
        <form class="auth-card" method="post">
            <input type="hidden" name="action" value="register">
            <p class="eyebrow">Pelanggan baru</p>
            <h1>Registrasi</h1>
            <label>
                <span>Nama lengkap</span>
                <input type="text" name="name" required>
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="email" required>
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" minlength="6" required>
            </label>
            <button class="btn btn-primary" type="submit">Daftar</button>
            <a class="text-link" href="index.php?page=login">Sudah punya akun</a>
        </form>
    </section>
    <?php
}

function render_admin(): void
{
    $stats = [
        'products' => db()->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'orders' => db()->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'users' => db()->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
        'revenue' => db()->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn(),
    ];

    $products = db()->query('SELECT * FROM products ORDER BY updated_at DESC, id DESC')->fetchAll();
    $orders = db()->query(
        'SELECT o.*, u.name AS account_name
         FROM orders o
         JOIN users u ON u.id = o.user_id
         ORDER BY o.created_at DESC
         LIMIT 8'
    )->fetchAll();
    ?>
    <section class="section compact admin-section">
        <div class="section-heading">
            <p class="eyebrow">Admin</p>
            <h1>Dashboard Admin</h1>
            <a class="btn btn-primary" href="index.php?page=product-form">Tambah Produk</a>
        </div>

        <div class="stats-grid">
            <div><span>Produk</span><strong><?= (int) $stats['products'] ?></strong></div>
            <div><span>Pesanan</span><strong><?= (int) $stats['orders'] ?></strong></div>
            <div><span>Pelanggan</span><strong><?= (int) $stats['users'] ?></strong></div>
            <div><span>Omzet Lunas</span><strong><?= rupiah($stats['revenue']) ?></strong></div>
        </div>

        <div class="admin-grid">
            <section class="admin-panel">
                <div class="panel-heading">
                    <h2>CRUD Produk</h2>
                </div>
                <div class="responsive-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Produk</th>
                                <th>Kategori</th>
                                <th>Harga</th>
                                <th>Stok</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?= e($product['name']) ?></td>
                                    <td><?= e($product['category']) ?></td>
                                    <td><?= rupiah($product['price']) ?></td>
                                    <td><?= (int) $product['stock'] ?></td>
                                    <td><?= (int) $product['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                    <td class="action-cell">
                                        <a class="btn btn-small btn-muted" href="index.php?page=product-form&id=<?= (int) $product['id'] ?>">Edit</a>
                                        <form method="post" data-confirm="Hapus produk ini?">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                            <button class="btn btn-small btn-danger" type="submit">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="admin-panel">
                <div class="panel-heading">
                    <h2>Pesanan Terbaru</h2>
                </div>
                <?php if (!$orders): ?>
                    <div class="empty-state slim">Belum ada pesanan.</div>
                <?php else: ?>
                    <div class="order-list">
                        <?php foreach ($orders as $order): ?>
                            <article class="order-item">
                                <div>
                                    <strong>#<?= (int) $order['id'] ?> - <?= e($order['customer_name']) ?></strong>
                                    <span><?= rupiah($order['total']) ?> / <?= e($order['payment_status']) ?></span>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="action" value="update_order_status">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                    <select name="order_status">
                                        <?php foreach (['baru', 'diproses', 'dikirim', 'selesai', 'dibatalkan'] as $status): ?>
                                            <option value="<?= e($status) ?>" <?= $order['order_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-small btn-muted" type="submit">Simpan</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </section>
    <?php
}

function render_product_form(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $product = $id ? product_by_id($id, true) : null;

    if ($id && !$product) {
        render_not_found('Produk tidak ditemukan.');
        return;
    }

    $product = $product ?: [
        'id' => 0,
        'name' => '',
        'category' => '',
        'description' => '',
        'price' => '',
        'stock' => 0,
        'image_url' => '',
        'featured' => 0,
        'is_active' => 1,
    ];
    ?>
    <section class="section compact">
        <div class="section-heading">
            <p class="eyebrow">Admin</p>
            <h1><?= $id ? 'Edit Produk' : 'Tambah Produk' ?></h1>
            <a class="text-link" href="index.php?page=admin">Kembali ke dashboard</a>
        </div>

        <form class="form-panel wide" method="post">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
            <label>
                <span>Nama produk</span>
                <input type="text" name="name" value="<?= e($product['name']) ?>" required>
            </label>
            <label>
                <span>Kategori</span>
                <input type="text" name="category" value="<?= e($product['category']) ?>" required>
            </label>
            <div class="form-grid">
                <label>
                    <span>Harga</span>
                    <input type="number" name="price" min="1" value="<?= e($product['price']) ?>" required>
                </label>
                <label>
                    <span>Stok</span>
                    <input type="number" name="stock" min="0" value="<?= (int) $product['stock'] ?>" required>
                </label>
            </div>
            <label>
                <span>URL gambar produk</span>
                <input type="url" name="image_url" value="<?= e($product['image_url']) ?>" required>
            </label>
            <label>
                <span>Deskripsi</span>
                <textarea name="description" rows="6" required><?= e($product['description']) ?></textarea>
            </label>
            <div class="check-row">
                <label><input type="checkbox" name="featured" <?= (int) $product['featured'] ? 'checked' : '' ?>> Produk pilihan</label>
                <label><input type="checkbox" name="is_active" <?= (int) $product['is_active'] ? 'checked' : '' ?>> Aktif</label>
            </div>
            <button class="btn btn-primary" type="submit">Simpan Produk</button>
        </form>
    </section>
    <?php
}

function render_not_found(string $message = 'Halaman tidak ditemukan.'): void
{
    ?>
    <section class="section compact">
        <div class="empty-state">
            <?= e($message) ?>
            <a class="btn btn-primary" href="index.php">Kembali ke Beranda</a>
        </div>
    </section>
    <?php
}

function render_database_error(PDOException $exception): void
{
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Database belum siap | KopiNusa Market</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
        <main class="setup-page">
            <section class="setup-panel">
                <p class="eyebrow">Konfigurasi database</p>
                <h1>Database belum dapat diakses</h1>
                <p>Pastikan MySQL/MariaDB aktif, lalu import file <strong>database/schema.sql</strong>. Default koneksi memakai database <strong>uja_ecommerce</strong>, user <strong>root</strong>, dan password kosong.</p>
                <pre>mysql -u root -p &lt; database/schema.sql</pre>
                <p class="muted">Detail teknis: <?= e($exception->getMessage()) ?></p>
            </section>
        </main>
    </body>
    </html>
    <?php
}
