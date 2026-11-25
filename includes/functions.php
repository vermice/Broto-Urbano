<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|int|string $amount): string
{
    return 'R$ ' . number_format((float)$amount, 2, ',', '.');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        flash('Entre na sua conta para continuar.', 'warning');
        redirect('index.php?page=login');
    }
}

function require_admin(): void
{
    require_login();
    if ((current_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso permitido apenas para administradores.');
    }
}

function cart_count(): int
{
    return array_sum(array_map('intval', $_SESSION['cart'] ?? []));
}

function cart_items(): array
{
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) return [];
    $ids = array_values(array_filter(array_map('intval', array_keys($cart)), fn($id) => $id > 0));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE active = 1 AND id IN ($marks)");
    $stmt->execute($ids);
    $products = $stmt->fetchAll();
    foreach ($products as &$product) {
        $product['quantity'] = min((int)$cart[$product['id']], (int)$product['stock']);
        $product['line_total'] = (float)$product['price'] * $product['quantity'];
    }
    return $products;
}

function render_header(string $title): void
{
    $user = current_user();
    $notice = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · Broto Urbano</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"></head><body>
    <div class="announcement">Cultive seu cantinho verde · Frete grátis acima de R$ 180</div>
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top"><div class="container py-2"><a class="navbar-brand" href="index.php">broto<span class="brand-light">urbano</span></a><button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="nav"><form class="search-form mx-lg-auto my-3 my-lg-0" action="index.php" method="get"><input type="hidden" name="page" value="home"><input class="form-control" name="q" placeholder="Buscar plantas, sementes..." value="<?= e($_GET['q'] ?? '') ?>"><button aria-label="Buscar">⌕</button></form><div class="nav-links d-flex align-items-center gap-3"><a href="index.php?page=cart">Sacola <span class="cart-count"><?= cart_count() ?></span></a><?php if ($user): ?><a href="index.php?page=orders">Pedidos</a><?php if ($user['role'] === 'admin'): ?><a href="index.php?page=admin">Administração</a><?php endif ?><form method="post" action="index.php?page=logout" class="m-0"><?= csrf_field() ?><button class="link-button">Sair</button></form><?php else: ?><a href="index.php?page=login">Entrar</a><?php endif ?></div></div></div></nav>
    <main class="container py-4 py-lg-5"><?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?> alert-dismissible fade show" role="alert"><?= e($notice['message']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif ?>
    <?php
}

function render_footer(): void
{
    ?>
    </main><footer class="site-footer"><div class="container d-flex flex-column flex-md-row justify-content-between gap-2"><span class="navbar-brand">broto<span class="brand-light">urbano</span></span><span>Pequenos começos, um verde de cada vez.</span><span>Projeto acadêmico · pagamentos demonstrativos</span></div></footer><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>
    <?php
}

function product_card(array $product): void
{
    ?>
    <article class="product-card"><a class="product-image" href="index.php?page=product&id=<?= (int)$product['id'] ?>" style="--product-image:url('<?= e($product['image_url']) ?>')"><span><?= e($product['badge'] ?: 'Cultive em casa') ?></span></a><div class="product-info"><div class="product-category"><?= e($product['category_name'] ?? 'Jardinagem') ?></div><h3><a href="index.php?page=product&id=<?= (int)$product['id'] ?>"><?= e($product['name']) ?></a></h3><div class="product-bottom"><strong><?= money($product['price']) ?></strong><form method="post" action="index.php?page=add" class="m-0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><button class="quick-add" aria-label="Adicionar <?= e($product['name']) ?> à sacola">+</button></form></div></div></article>
    <?php
}
