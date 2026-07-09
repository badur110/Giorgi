<?php
session_start();

date_default_timezone_set('Asia/Tbilisi');

function load_env(): array
{
    $path = __DIR__ . '/../.env';
    if (!file_exists($path)) {
        $path = __DIR__ . '/../.env.example';
    }
    $env = [];
    if (file_exists($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim(trim($value), "\"'");
        }
    }
    return $env;
}

$env = load_env();

function envv(string $key, $default = null)
{
    global $env;
    return $env[$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = envv('DB_HOST', '127.0.0.1');
    $port = envv('DB_PORT', '3306');
    $name = envv('DB_DATABASE', 'nineteen_pleats');
    $user = envv('DB_USERNAME', 'root');
    $pass = envv('DB_PASSWORD', '');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float)$v, 2) . ' ₾'; }
function redirect_to(string $page, array $params = []): void
{
    $params = array_merge(['page' => $page], $params);
    header('Location: ?' . http_build_query($params));
    exit;
}
function is_logged_in(): bool { return !empty($_SESSION['logged_in']); }
function require_login(): void { if (!is_logged_in()) redirect_to('login'); }

function current_open_order(int $tableId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE table_id = ? AND status = "open" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$tableId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_order(int $tableId): int
{
    $stmt = db()->prepare('INSERT INTO orders (table_id, status) VALUES (?, "open")');
    $stmt->execute([$tableId]);
    db()->prepare('UPDATE restaurant_tables SET status="occupied" WHERE id=?')->execute([$tableId]);
    return (int)db()->lastInsertId();
}

function order_total(int $orderId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(quantity * price),0) total FROM order_items WHERE order_id=? AND is_cancelled=0');
    $stmt->execute([$orderId]);
    return (float)$stmt->fetchColumn();
}

function order_items(int $orderId): array
{
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id ASC');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function print_to_lan_printer(string $ip, int $port, string $text): bool
{
    if (envv('PRINTING_ENABLED', 'false') !== 'true') return true;
    $fp = @fsockopen($ip, $port, $errno, $errstr, 3);
    if (!$fp) return false;
    fwrite($fp, "\x1B\x40");
    fwrite($fp, $text . "\n\n\n");
    fwrite($fp, "\x1D\x56\x01");
    fclose($fp);
    return true;
}

function build_kitchen_ticket(array $table, array $items): string
{
    $lines = [];
    $lines[] = 'სამზარეულო';
    $lines[] = 'მაგიდა: ' . $table['name'];
    $lines[] = 'დრო: ' . date('Y-m-d H:i');
    $lines[] = str_repeat('-', 32);
    foreach ($items as $item) {
        $lines[] = $item['quantity'] . ' x ' . $item['product_name'];
        if (!empty($item['comment'])) $lines[] = 'კომენტარი: ' . $item['comment'];
    }
    $lines[] = str_repeat('-', 32);
    return implode("\n", $lines);
}

function build_cashier_ticket(array $table, array $items): string
{
    $lines = [];
    $lines[] = 'ცხრამეტი ნაოჭი';
    $lines[] = 'შეკვეთა';
    $lines[] = 'მაგიდა: ' . $table['name'];
    $lines[] = 'დრო: ' . date('Y-m-d H:i');
    $lines[] = str_repeat('-', 32);
    foreach ($items as $item) {
        $sum = $item['quantity'] * $item['price'];
        $lines[] = $item['quantity'] . ' x ' . $item['product_name'] . ' - ' . number_format($sum, 2);
        if (!empty($item['comment'])) $lines[] = 'კომენტარი: ' . $item['comment'];
    }
    $lines[] = str_repeat('-', 32);
    return implode("\n", $lines);
}

function build_final_receipt(array $table, array $order, array $items): string
{
    $lines = [];
    $lines[] = 'ცხრამეტი ნაოჭი';
    $lines[] = 'Nineteen Pleats';
    $lines[] = 'საბოლოო ანგარიში';
    $lines[] = 'მაგიდა: ' . $table['name'];
    $lines[] = 'დრო: ' . date('Y-m-d H:i');
    $lines[] = str_repeat('-', 32);
    foreach ($items as $item) {
        if ((int)$item['is_cancelled'] === 1) continue;
        $sum = $item['quantity'] * $item['price'];
        $lines[] = $item['quantity'] . ' x ' . $item['product_name'];
        $lines[] = number_format($item['price'], 2) . ' x ' . $item['quantity'] . ' = ' . number_format($sum, 2) . ' GEL';
    }
    $lines[] = str_repeat('-', 32);
    $lines[] = 'ჯამი: ' . number_format($order['total'], 2) . ' GEL';
    $pay = ['cash'=>'ნაღდი', 'card'=>'ბარათი', 'mixed'=>'შერეული'][$order['payment_type']] ?? '';
    $lines[] = 'გადახდა: ' . $pay;
    if ($order['payment_type'] === 'mixed') {
        $lines[] = 'ნაღდი: ' . number_format($order['cash_amount'], 2);
        $lines[] = 'ბარათი: ' . number_format($order['card_amount'], 2);
    }
    $lines[] = 'გმადლობთ!';
    return implode("\n", $lines);
}

function layout_header(string $title): void
{
    echo '<!doctype html><html lang="ka"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . '</title><link rel="stylesheet" href="assets/app.css"></head><body>';
    if (is_logged_in()) {
        echo '<header class="top"><div><strong>ცხრამეტი ნაოჭი POS</strong></div><nav><a href="?page=tables">მაგიდები</a><a href="?page=products">პროდუქტები</a><a href="?page=reports">რეპორტები</a><a href="?page=logout">გასვლა</a></nav></header>';
    }
    echo '<main class="wrap">';
    if (!empty($_SESSION['flash'])) { echo '<div class="flash">' . h($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }
}

function layout_footer(): void
{
    echo '</main></body></html>';
}

$page = $_GET['page'] ?? 'tables';
$action = $_POST['action'] ?? null;

if ($page === 'logout') {
    session_destroy();
    redirect_to('login');
}

if ($action === 'login') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    if ($user === envv('ADMIN_USERNAME', 'admin') && $pass === envv('ADMIN_PASSWORD', 'admin123')) {
        $_SESSION['logged_in'] = true;
        redirect_to('tables');
    }
    $_SESSION['flash'] = 'არასწორი მომხმარებელი ან პაროლი';
    redirect_to('login');
}

if ($page === 'login') {
    layout_header('შესვლა');
    echo '<section class="card login"><h1>შესვლა</h1><form method="post"><input type="hidden" name="action" value="login"><label>მომხმარებელი<input name="username" value="admin"></label><label>პაროლი<input name="password" type="password"></label><button>შესვლა</button></form></section>';
    layout_footer();
    exit;
}

require_login();

if ($action === 'add_item') {
    $tableId = (int)$_POST['table_id'];
    $productId = (int)$_POST['product_id'];
    $qty = max(1, (int)$_POST['quantity']);
    $comment = trim($_POST['comment'] ?? '');
    $order = current_open_order($tableId);
    $orderId = $order ? (int)$order['id'] : create_order($tableId);
    $stmt = db()->prepare('SELECT * FROM products WHERE id=? AND is_active=1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if ($product) {
        $stmt = db()->prepare('INSERT INTO order_items (order_id, product_id, product_name, quantity, price, comment) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$orderId, $productId, $product['name'], $qty, $product['price'], $comment]);
        $_SESSION['flash'] = 'პროდუქტი დაემატა';
    }
    redirect_to('table', ['id' => $tableId]);
}

if ($action === 'send_order') {
    $tableId = (int)$_POST['table_id'];
    $order = current_open_order($tableId);
    if (!$order) redirect_to('table', ['id' => $tableId]);
    $stmt = db()->prepare('SELECT * FROM restaurant_tables WHERE id=?');
    $stmt->execute([$tableId]);
    $table = $stmt->fetch();
    $stmt = db()->prepare('SELECT * FROM order_items WHERE order_id=? AND is_cancelled=0 AND printed_at IS NULL ORDER BY id ASC');
    $stmt->execute([$order['id']]);
    $items = $stmt->fetchAll();
    if (!$items) {
        $_SESSION['flash'] = 'ახალი დასაბეჭდი პროდუქტი არ არის';
        redirect_to('table', ['id' => $tableId]);
    }
    $cashierOk = print_to_lan_printer(envv('CASHIER_PRINTER_IP', ''), (int)envv('PRINTER_PORT', 9100), build_cashier_ticket($table, $items));
    $kitchenOk = print_to_lan_printer(envv('KITCHEN_PRINTER_IP', ''), (int)envv('PRINTER_PORT', 9100), build_kitchen_ticket($table, $items));
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE order_items SET printed_at=NOW() WHERE id IN ($placeholders)")->execute($ids);
    $_SESSION['flash'] = ($cashierOk && $kitchenOk) ? 'შეკვეთა დაიბეჭდა' : 'შეკვეთა შეინახა, მაგრამ პრინტერთან კავშირი გადასამოწმებელია';
    redirect_to('table', ['id' => $tableId]);
}

if ($action === 'cancel_item') {
    $tableId = (int)$_POST['table_id'];
    $itemId = (int)$_POST['item_id'];
    $password = $_POST['cancel_password'] ?? '';
    $reason = trim($_POST['cancel_reason'] ?? '');
    if ($password !== envv('CANCEL_PASSWORD', 'cancel123')) {
        $_SESSION['flash'] = 'გაუქმების პაროლი არასწორია';
        redirect_to('table', ['id' => $tableId]);
    }
    db()->prepare('UPDATE order_items SET is_cancelled=1, cancelled_at=NOW(), cancel_reason=? WHERE id=?')->execute([$reason, $itemId]);
    $_SESSION['flash'] = 'პროდუქტი გაუქმდა';
    redirect_to('table', ['id' => $tableId]);
}

if ($action === 'close_order') {
    $tableId = (int)$_POST['table_id'];
    $order = current_open_order($tableId);
    if (!$order) redirect_to('tables');
    $total = order_total((int)$order['id']);
    $paymentType = $_POST['payment_type'] ?? 'cash';
    $cash = $paymentType === 'mixed' ? (float)($_POST['cash_amount'] ?? 0) : ($paymentType === 'cash' ? $total : 0);
    $card = $paymentType === 'mixed' ? (float)($_POST['card_amount'] ?? 0) : ($paymentType === 'card' ? $total : 0);
    db()->prepare('UPDATE orders SET status="closed", total=?, payment_type=?, cash_amount=?, card_amount=?, closed_at=NOW() WHERE id=?')->execute([$total, $paymentType, $cash, $card, $order['id']]);
    db()->prepare('UPDATE restaurant_tables SET status="free" WHERE id=?')->execute([$tableId]);
    $stmt = db()->prepare('SELECT * FROM restaurant_tables WHERE id=?');
    $stmt->execute([$tableId]);
    $table = $stmt->fetch();
    $stmt = db()->prepare('SELECT * FROM orders WHERE id=?');
    $stmt->execute([$order['id']]);
    $closedOrder = $stmt->fetch();
    $items = order_items((int)$order['id']);
    print_to_lan_printer(envv('CASHIER_PRINTER_IP', ''), (int)envv('PRINTER_PORT', 9100), build_final_receipt($table, $closedOrder, $items));
    $_SESSION['flash'] = 'მაგიდა დაიხურა';
    redirect_to('tables');
}

if ($action === 'save_product') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $categoryName = trim($_POST['category_name'] ?? 'სხვა');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($name !== '') {
        $stmt = db()->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
        $stmt->execute([$categoryName]);
        $categoryId = $stmt->fetchColumn();
        if (!$categoryId) {
            db()->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$categoryName]);
            $categoryId = db()->lastInsertId();
        }
        if ($id > 0) {
            db()->prepare('UPDATE products SET category_id=?, name=?, price=?, is_active=? WHERE id=?')->execute([$categoryId, $name, $price, $isActive, $id]);
            $_SESSION['flash'] = 'პროდუქტი განახლდა';
        } else {
            db()->prepare('INSERT INTO products (category_id, name, price, is_active) VALUES (?,?,?,?)')->execute([$categoryId, $name, $price, $isActive]);
            $_SESSION['flash'] = 'პროდუქტი დაემატა';
        }
    }
    redirect_to('products');
}

if ($action === 'deactivate_product') {
    db()->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([(int)$_POST['id']]);
    $_SESSION['flash'] = 'პროდუქტი გაითიშა';
    redirect_to('products');
}

if ($page === 'tables') {
    layout_header('მაგიდები');
    $tables = db()->query('SELECT * FROM restaurant_tables ORDER BY id')->fetchAll();
    echo '<h1>მაგიდები</h1><div class="tables-grid">';
    foreach ($tables as $table) {
        $order = current_open_order((int)$table['id']);
        $total = $order ? order_total((int)$order['id']) : 0;
        $class = $order ? 'occupied' : 'free';
        echo '<a class="table-card ' . $class . '" href="?page=table&id=' . (int)$table['id'] . '"><span>' . h($table['name']) . '</span><strong>' . ($order ? money($total) : 'თავისუფალი') . '</strong></a>';
    }
    echo '</div>';
    layout_footer();
    exit;
}

if ($page === 'table') {
    $tableId = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM restaurant_tables WHERE id=?');
    $stmt->execute([$tableId]);
    $table = $stmt->fetch();
    if (!$table) redirect_to('tables');
    $order = current_open_order($tableId);
    $items = $order ? order_items((int)$order['id']) : [];
    $total = $order ? order_total((int)$order['id']) : 0;
    $categories = db()->query('SELECT * FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
    $products = db()->query('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 ORDER BY c.name, p.name')->fetchAll();
    layout_header($table['name']);
    echo '<div class="table-head"><h1>' . h($table['name']) . '</h1><div class="big-total">' . money($total) . '</div></div>';
    echo '<section class="pos-layout"><div class="card"><h2>პროდუქტის დამატება</h2>';
    $currentCat = '';
    foreach ($products as $product) {
        if ($currentCat !== $product['category_name']) {
            $currentCat = $product['category_name'];
            echo '<h3 class="cat">' . h($currentCat ?: 'სხვა') . '</h3>';
        }
        echo '<form class="product-row" method="post"><input type="hidden" name="action" value="add_item"><input type="hidden" name="table_id" value="' . $tableId . '"><input type="hidden" name="product_id" value="' . (int)$product['id'] . '"><div><strong>' . h($product['name']) . '</strong><small>' . money($product['price']) . '</small></div><input name="quantity" type="number" min="1" value="1"><input name="comment" placeholder="კომენტარი"><button>დამატება</button></form>';
    }
    echo '</div><div class="card"><h2>მიმდინარე შეკვეთა</h2>';
    if (!$items) echo '<p>შეკვეთა ჯერ ცარიელია.</p>';
    foreach ($items as $item) {
        $cancelled = (int)$item['is_cancelled'] === 1;
        echo '<div class="item ' . ($cancelled ? 'cancelled' : '') . '"><div><strong>' . h($item['quantity']) . ' x ' . h($item['product_name']) . '</strong><br><small>' . money($item['price']) . ' / ჯამი: ' . money($item['price'] * $item['quantity']) . '</small>';
        if ($item['comment']) echo '<br><em>' . h($item['comment']) . '</em>';
        if ($item['printed_at']) echo '<br><small>დაბეჭდილია</small>';
        if ($cancelled) echo '<br><small>გაუქმებულია: ' . h($item['cancel_reason']) . '</small>';
        echo '</div>';
        if (!$cancelled) {
            echo '<form method="post" class="cancel-form"><input type="hidden" name="action" value="cancel_item"><input type="hidden" name="table_id" value="' . $tableId . '"><input type="hidden" name="item_id" value="' . (int)$item['id'] . '"><input type="password" name="cancel_password" placeholder="პაროლი"><input name="cancel_reason" placeholder="მიზეზი"><button class="danger">გაუქმება</button></form>';
        }
        echo '</div>';
    }
    echo '<div class="actions"><form method="post"><input type="hidden" name="action" value="send_order"><input type="hidden" name="table_id" value="' . $tableId . '"><button class="primary">შეკვეთის დაბეჭდვა</button></form></div>';
    if ($order) {
        echo '<hr><h2>მაგიდის დახურვა</h2><form method="post" class="close-form"><input type="hidden" name="action" value="close_order"><input type="hidden" name="table_id" value="' . $tableId . '"><label>გადახდის ტიპი<select name="payment_type" id="payment_type" onchange="document.getElementById(\'mixed_fields\').style.display=this.value===\'mixed\'?\'grid\':\'none\'"><option value="cash">ნაღდი</option><option value="card">ბარათი</option><option value="mixed">შერეული</option></select></label><div id="mixed_fields" class="mixed"><label>ნაღდი<input name="cash_amount" type="number" step="0.01"></label><label>ბარათი<input name="card_amount" type="number" step="0.01"></label></div><button class="success">საბოლოო ანგარიში და დახურვა</button></form>';
    }
    echo '</div></section>';
    layout_footer();
    exit;
}

if ($page === 'products') {
    $edit = null;
    if (!empty($_GET['edit'])) {
        $stmt = db()->prepare('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.id=?');
        $stmt->execute([(int)$_GET['edit']]);
        $edit = $stmt->fetch();
    }
    $products = db()->query('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.is_active DESC, c.name, p.name')->fetchAll();
    layout_header('პროდუქტები');
    echo '<h1>პროდუქტები</h1><section class="grid-2"><div class="card"><h2>' . ($edit ? 'პროდუქტის რედაქტირება' : 'ახალი პროდუქტი') . '</h2><form method="post"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="' . h($edit['id'] ?? '') . '"><label>სახელი<input name="name" required value="' . h($edit['name'] ?? '') . '"></label><label>ფასი<input name="price" type="number" step="0.01" required value="' . h($edit['price'] ?? '') . '"></label><label>კატეგორია<input name="category_name" value="' . h($edit['category_name'] ?? 'სხვა') . '"></label><label class="check"><input type="checkbox" name="is_active" ' . (!$edit || (int)$edit['is_active'] === 1 ? 'checked' : '') . '> აქტიური</label><button>შენახვა</button></form></div><div class="card"><h2>სია</h2><table><thead><tr><th>პროდუქტი</th><th>კატეგორია</th><th>ფასი</th><th>სტატუსი</th><th></th></tr></thead><tbody>';
    foreach ($products as $p) {
        echo '<tr><td>' . h($p['name']) . '</td><td>' . h($p['category_name']) . '</td><td>' . money($p['price']) . '</td><td>' . ((int)$p['is_active'] ? 'აქტიური' : 'გათიშული') . '</td><td><a href="?page=products&edit=' . (int)$p['id'] . '">რედაქტირება</a> <form method="post" style="display:inline"><input type="hidden" name="action" value="deactivate_product"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="link danger-text">გათიშვა</button></form></td></tr>';
    }
    echo '</tbody></table></div></section>';
    layout_footer();
    exit;
}

if ($page === 'reports') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = db()->prepare('SELECT * FROM orders WHERE status="closed" AND DATE(closed_at)=? ORDER BY closed_at DESC');
    $stmt->execute([$date]);
    $orders = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT payment_type, SUM(total) total, SUM(cash_amount) cash_total, SUM(card_amount) card_total, COUNT(*) cnt FROM orders WHERE status="closed" AND DATE(closed_at)=? GROUP BY payment_type');
    $stmt->execute([$date]);
    $payments = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT product_name, SUM(quantity) qty, SUM(quantity*price) total FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status="closed" AND oi.is_cancelled=0 AND DATE(o.closed_at)=? GROUP BY product_name ORDER BY qty DESC');
    $stmt->execute([$date]);
    $topProducts = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT oi.*, o.closed_at, rt.name table_name FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN restaurant_tables rt ON rt.id=o.table_id WHERE oi.is_cancelled=1 AND DATE(oi.cancelled_at)=? ORDER BY oi.cancelled_at DESC');
    $stmt->execute([$date]);
    $cancelled = $stmt->fetchAll();
    $grand = array_sum(array_column($orders, 'total'));
    layout_header('რეპორტები');
    echo '<h1>რეპორტები</h1><form class="date-form"><input type="hidden" name="page" value="reports"><label>დღე<input type="date" name="date" value="' . h($date) . '"></label><button>ნახვა</button></form><div class="summary"><div><span>ჯამური გაყიდვა</span><strong>' . money($grand) . '</strong></div><div><span>დახურული მაგიდები</span><strong>' . count($orders) . '</strong></div></div><section class="grid-2"><div class="card"><h2>გადახდები</h2><table><tr><th>ტიპი</th><th>რაოდენობა</th><th>ჯამი</th><th>ნაღდი</th><th>ბარათი</th></tr>';
    foreach ($payments as $p) {
        $label = ['cash'=>'ნაღდი', 'card'=>'ბარათი', 'mixed'=>'შერეული'][$p['payment_type']] ?? $p['payment_type'];
        echo '<tr><td>' . h($label) . '</td><td>' . (int)$p['cnt'] . '</td><td>' . money($p['total']) . '</td><td>' . money($p['cash_total']) . '</td><td>' . money($p['card_total']) . '</td></tr>';
    }
    echo '</table></div><div class="card"><h2>ყველაზე გაყიდვადი პროდუქტები</h2><table><tr><th>პროდუქტი</th><th>რაოდ.</th><th>ჯამი</th></tr>';
    foreach ($topProducts as $p) echo '<tr><td>' . h($p['product_name']) . '</td><td>' . (int)$p['qty'] . '</td><td>' . money($p['total']) . '</td></tr>';
    echo '</table></div></section><section class="card"><h2>დახურული ანგარიშები</h2><table><tr><th>ID</th><th>მაგიდა</th><th>ჯამი</th><th>გადახდა</th><th>დრო</th></tr>';
    foreach ($orders as $o) {
        echo '<tr><td>#' . (int)$o['id'] . '</td><td>' . (int)$o['table_id'] . '</td><td>' . money($o['total']) . '</td><td>' . h($o['payment_type']) . '</td><td>' . h($o['closed_at']) . '</td></tr>';
    }
    echo '</table></section><section class="card"><h2>გაუქმებული პროდუქტები</h2><table><tr><th>მაგიდა</th><th>პროდუქტი</th><th>რაოდ.</th><th>მიზეზი</th><th>დრო</th></tr>';
    foreach ($cancelled as $c) echo '<tr><td>' . h($c['table_name']) . '</td><td>' . h($c['product_name']) . '</td><td>' . (int)$c['quantity'] . '</td><td>' . h($c['cancel_reason']) . '</td><td>' . h($c['cancelled_at']) . '</td></tr>';
    echo '</table></section>';
    layout_footer();
    exit;
}

redirect_to('tables');
