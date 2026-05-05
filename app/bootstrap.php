<?php

session_start();

function config(string $key): mixed
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/../config/database.php';
    }

    return $config[$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $charset = config('charset') ?: 'utf8mb4';
    if (config('socket')) {
        $dsn = 'mysql:unix_socket=' . config('socket') . ';dbname=' . config('database') . ';charset=' . $charset;
    } else {
        $dsn = 'mysql:host=' . config('host') . ';port=' . config('port') . ';dbname=' . config('database') . ';charset=' . $charset;
    }

    $pdo = new PDO($dsn, config('username'), config('password'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(mixed $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function excerpt(string $value, int $limit = 92): string
{
    $text = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $limit - 3))) . '...';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

function redirect_to(string $page = 'home', array $params = []): never
{
    $query = http_build_query(array_merge(['page' => $page], $params));
    header('Location: index.php?' . $query);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function logout_user(): void
{
    unset($_SESSION['user']);
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

function require_login(): void
{
    if (!current_user()) {
        flash('warning', 'Silakan login terlebih dahulu untuk melanjutkan.');
        redirect_to('login');
    }
}

function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        flash('danger', 'Halaman admin hanya dapat diakses oleh administrator.');
        redirect_to('home');
    }
}

function cart(): array
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    return $_SESSION['cart'];
}

function set_cart(array $cart): void
{
    $_SESSION['cart'] = $cart;
}

function cart_count(): int
{
    return array_sum(array_map('intval', cart()));
}

function cart_details(): array
{
    $cart = cart();
    if (!$cart) {
        return ['items' => [], 'total' => 0];
    }

    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->execute($ids);

    $products = [];
    foreach ($stmt->fetchAll() as $product) {
        $products[(int) $product['id']] = $product;
    }

    $items = [];
    $total = 0;
    $normalizedCart = [];

    foreach ($cart as $productId => $quantity) {
        $productId = (int) $productId;
        if (!isset($products[$productId])) {
            continue;
        }

        $product = $products[$productId];
        $quantity = max(1, min((int) $quantity, (int) $product['stock']));
        if ($quantity <= 0 || (int) $product['stock'] <= 0) {
            continue;
        }

        $subtotal = $quantity * (float) $product['price'];
        $items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
        $total += $subtotal;
        $normalizedCart[$productId] = $quantity;
    }

    set_cart($normalizedCart);

    return ['items' => $items, 'total' => $total];
}

function product_by_id(int $id, bool $includeInactive = false): ?array
{
    $sql = 'SELECT * FROM products WHERE id = ?';
    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    return $product ?: null;
}

function categories(): array
{
    return db()
        ->query("SELECT DISTINCT category FROM products WHERE is_active = 1 ORDER BY category")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function payment_code(): string
{
    return 'PAY-' . date('ymd') . '-' . random_int(1000, 9999);
}
