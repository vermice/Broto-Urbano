<?php
declare(strict_types=1);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/functions.php';

$page = (string)($_GET['page'] ?? 'home');
$pdo = null;
try {
    $pdo = db();
} catch (Throwable $exception) {
    http_response_code(503);
    ?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Banco não configurado</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><body class="container py-5"><h1>Falta configurar o banco de dados</h1><p>Confira se o MySQL está ativo, importe <code>database/schema.sql</code> e configure seu arquivo <code>.env</code>. Os passos estão no README.</p></body></html><?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($page) {
            case 'register':
                $name = trim((string)($_POST['name'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $password = (string)($_POST['password'] ?? '');
                if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                    throw new RuntimeException('Informe nome, e-mail válido e senha com pelo menos 8 caracteres.');
                }
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash) VALUES (:name,:email,:hash)');
                $stmt->execute(['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'customer'];
                flash('Conta criada. Boas-vindas à Broto Urbano!');
                redirect('index.php');

            case 'login':
                $stmt = $pdo->prepare('SELECT id,name,email,password_hash,role FROM users WHERE email = :email');
                $stmt->execute(['email' => strtolower(trim((string)($_POST['email'] ?? '')))]);
                $user = $stmt->fetch();
                if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                    throw new RuntimeException('E-mail ou senha incorretos.');
                }
                session_regenerate_id(true);
                unset($user['password_hash']);
                $_SESSION['user'] = $user;
                flash('Que bom ter você de volta, ' . $user['name'] . '.');
                redirect('index.php');

            case 'logout':
                unset($_SESSION['user']);
                session_regenerate_id(true);
                flash('Você saiu da sua conta.');
                redirect('index.php');

            case 'add':
                $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
                $quantity = max(1, min(20, (int)($_POST['quantity'] ?? 1)));
                $stmt = $pdo->prepare('SELECT id,stock FROM products WHERE id = :id AND active = 1');
                $stmt->execute(['id' => $id]);
                $product = $stmt->fetch();
                if (!$product || (int)$product['stock'] < 1) throw new RuntimeException('Este produto está indisponível.');
                $newQuantity = min((int)$product['stock'], (int)($_SESSION['cart'][$id] ?? 0) + $quantity);
                $_SESSION['cart'][$id] = $newQuantity;
                flash('Produto adicionado à sacola.');
                redirect('index.php?page=cart');

            case 'cart_update':
                foreach ((array)($_POST['quantity'] ?? []) as $id => $quantity) {
                    $id = (int)$id;
                    $quantity = (int)$quantity;
                    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = :id AND active = 1');
                    $stmt->execute(['id' => $id]);
                    $stock = $stmt->fetchColumn();
                    if ($stock === false || $quantity < 1) unset($_SESSION['cart'][$id]);
                    else $_SESSION['cart'][$id] = min((int)$stock, $quantity);
                }
                flash('Sacola atualizada.');
                redirect('index.php?page=cart');

            case 'cart_remove':
                unset($_SESSION['cart'][(int)($_POST['id'] ?? 0)]);
                flash('Item removido da sacola.');
                redirect('index.php?page=cart');

            case 'checkout':
                require_login();
                $items = cart_items();
                if (!$items) throw new RuntimeException('Sua sacola está vazia.');
                $method = (string)($_POST['payment_method'] ?? '');
                if (!in_array($method, ['pix','boleto','cartao'], true)) throw new RuntimeException('Selecione uma forma de pagamento.');
                $pdo->beginTransaction();
                $total = 0.0;
                foreach ($items as $item) {
                    $lock = $pdo->prepare('SELECT price,stock,active FROM products WHERE id = :id FOR UPDATE');
                    $lock->execute(['id' => $item['id']]);
                    $locked = $lock->fetch();
                    if (!$locked || !(int)$locked['active'] || (int)$locked['stock'] < (int)$item['quantity']) {
                        throw new RuntimeException('O estoque de ' . $item['name'] . ' mudou. Atualize sua sacola e tente novamente.');
                    }
                    $total += (float)$locked['price'] * (int)$item['quantity'];
                }
                $order = $pdo->prepare('INSERT INTO orders (user_id,total,payment_method) VALUES (:user,:total,:method)');
                $order->execute(['user' => current_user()['id'], 'total' => number_format($total, 2, '.', ''), 'method' => $method]);
                $orderId = (int)$pdo->lastInsertId();
                foreach ($items as $item) {
                    $lock = $pdo->prepare('SELECT price,stock FROM products WHERE id = :id FOR UPDATE');
                    $lock->execute(['id' => $item['id']]);
                    $locked = $lock->fetch();
                    $line = $pdo->prepare('INSERT INTO order_items (order_id,product_id,quantity,unit_price) VALUES (:order,:product,:quantity,:price)');
                    $line->execute(['order' => $orderId, 'product' => $item['id'], 'quantity' => $item['quantity'], 'price' => $locked['price']]);
                    $stockUpdate = $pdo->prepare('UPDATE products SET stock = stock - :decrease WHERE id = :id AND stock >= :minimum');
                    $stockUpdate->execute(['decrease' => $item['quantity'], 'id' => $item['id'], 'minimum' => $item['quantity']]);
                    if ($stockUpdate->rowCount() !== 1) throw new RuntimeException('Não foi possível confirmar o estoque.');
                }
                $pdo->commit();
                $_SESSION['cart'] = [];
                flash('Pedido #' . $orderId . ' confirmado! Pagamento demonstrativo via ' . ['pix' => 'PIX', 'boleto' => 'boleto', 'cartao' => 'cartão'][$method] . '.');
                redirect('index.php?page=orders');

            case 'admin_save':
                require_admin();
                $id = (int)($_POST['id'] ?? 0);
                $data = ['name' => trim((string)($_POST['name'] ?? '')), 'description' => trim((string)($_POST['description'] ?? '')), 'price' => (float)($_POST['price'] ?? 0), 'stock' => max(0, (int)($_POST['stock'] ?? 0)), 'category' => (int)($_POST['category_id'] ?? 0), 'image' => trim((string)($_POST['image_url'] ?? '')), 'badge' => trim((string)($_POST['badge'] ?? ''))];
                if ($data['name'] === '' || $data['description'] === '' || $data['price'] < 0 || $data['category'] < 1 || !filter_var($data['image'], FILTER_VALIDATE_URL)) throw new RuntimeException('Preencha os campos obrigatórios com valores válidos.');
                if ($id) {
                    $stmt = $pdo->prepare('UPDATE products SET category_id=:category,name=:name,description=:description,price=:price,stock=:stock,image_url=:image,badge=:badge WHERE id=:id');
                    $data['id'] = $id;
                    $stmt->execute($data);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO products (category_id,name,description,price,stock,image_url,badge) VALUES (:category,:name,:description,:price,:stock,:image,:badge)');
                    $stmt->execute($data);
                }
                flash('Produto salvo.');
                redirect('index.php?page=admin');

            case 'admin_toggle':
                require_admin();
                $stmt = $pdo->prepare('UPDATE products SET active = IF(active=1,0,1) WHERE id=:id');
                $stmt->execute(['id' => (int)($_POST['id'] ?? 0)]);
                flash('Disponibilidade do produto atualizada.');
                redirect('index.php?page=admin');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($exception->getMessage(), 'danger');
        redirect('index.php?page=' . urlencode($page));
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($page === 'register' && $exception->getCode() === '23000' ? 'Este e-mail já tem cadastro.' : 'Não foi possível concluir a operação. Confira os dados e tente novamente.', 'danger');
        redirect('index.php?page=' . urlencode($page));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Não foi possível concluir sua solicitação. Tente novamente.', 'danger');
        redirect('index.php?page=' . urlencode($page));
    }
}

switch ($page) {
    case 'home':
        $search = trim((string)($_GET['q'] ?? ''));
        $categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT) ?: 0;
        $categories = $pdo->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();
        $sql = 'SELECT p.*,c.name AS category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.active=1';
        $params = [];
        if ($search !== '') { $sql .= ' AND (p.name LIKE :search_name OR p.description LIKE :search_description)'; $params['search_name'] = '%' . $search . '%'; $params['search_description'] = '%' . $search . '%'; }
        if ($categoryId) { $sql .= ' AND p.category_id=:category'; $params['category'] = $categoryId; }
        $sql .= ' ORDER BY p.id';
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $products = $stmt->fetchAll();
        render_header('Cultive seu cantinho');
        ?>
        <section class="hero"><div class="hero-copy"><div class="eyebrow">um pouco de verde muda tudo</div><h1>Seu próximo começo floresce aqui.</h1><p>Plantas, ferramentas e pequenos rituais para trazer a natureza para mais perto.</p><a href="#colecao" class="btn btn-forest mt-2">Descobrir a coleção <span class="ms-2">→</span></a></div><div class="hero-image" role="img" aria-label="Horta caseira iluminada"></div></section>
        <section id="colecao"><div class="section-heading"><div><div class="eyebrow">feito para cultivar</div><h2>Nossa coleção</h2><p>Ideias simples para deixar o dia mais verde.</p></div><span class="muted small"><?= count($products) ?> produtos</span></div><div class="category-pills"><a class="<?= !$categoryId?'active':'' ?>" href="index.php">Tudo</a><?php foreach ($categories as $category): ?><a class="<?= $categoryId===(int)$category['id']?'active':'' ?>" href="index.php?category=<?= (int)$category['id'] ?>"><?= e($category['name']) ?></a><?php endforeach ?></div>
        <?php if ($search !== ''): ?><p class="muted">Resultados para “<?= e($search) ?>”</p><?php endif ?><?php if ($products): ?><div class="product-grid"><?php foreach ($products as $product) product_card($product); ?></div><?php else: ?><div class="text-center py-5"><h2>Nenhum produto encontrado</h2><p class="muted">Tente outro termo ou categoria.</p><a class="btn btn-forest" href="index.php">Ver tudo</a></div><?php endif ?></section>
        <?php render_footer(); break;

    case 'product':
        $stmt=$pdo->prepare('SELECT p.*,c.name AS category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=:id AND p.active=1');$stmt->execute(['id'=>(int)($_GET['id']??0)]);$product=$stmt->fetch();
        if (!$product) { http_response_code(404); render_header('Produto não encontrado'); echo '<h1 class="page-title">Produto não encontrado</h1><a href="index.php">Voltar à loja</a>'; render_footer(); break; }
        render_header($product['name']); ?>
        <div class="mb-4"><a class="muted text-decoration-none" href="index.php">← Voltar à coleção</a></div><section class="product-detail"><div class="detail-image" style="--product-image:url('<?= e($product['image_url']) ?>')"></div><div class="detail-copy"><div class="eyebrow"><?= e($product['category_name']) ?></div><h1 class="mt-3"><?= e($product['name']) ?></h1><div class="detail-price"><?= money($product['price']) ?></div><p><?= nl2br(e($product['description'])) ?></p><div class="stock"><?= (int)$product['stock'] > 0 ? '● Em estoque · ' . (int)$product['stock'] . ' disponíveis' : 'Produto indisponível no momento' ?></div><?php if ((int)$product['stock'] > 0): ?><form method="post" action="index.php?page=add" class="d-flex gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><input class="form-control" type="number" name="quantity" value="1" min="1" max="<?= (int)$product['stock'] ?>" style="max-width:90px"><button class="btn btn-forest" type="submit">Adicionar à sacola</button></form><?php endif ?></div></section>
        <?php render_footer(); break;

    case 'register':
        render_header('Criar conta'); ?><div class="auth-wrap"><div class="eyebrow">seu cantinho começa aqui</div><h1 class="page-title mt-2">Criar conta</h1><p class="muted mb-4">Acompanhe seus pedidos e comece a cultivar.</p><form method="post" action="index.php?page=register" class="vstack gap-3"><?= csrf_field() ?><div><label class="form-label">Nome</label><input class="form-control" name="name" required minlength="2" value="<?= e($_POST['name']??'') ?>"></div><div><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" required></div><div><label class="form-label">Senha</label><input class="form-control" type="password" name="password" required minlength="8"></div><button class="btn btn-forest">Criar minha conta</button></form><p class="mt-4 muted">Já tem uma conta? <a href="index.php?page=login">Entrar</a></p></div><?php render_footer(); break;

    case 'login':
        render_header('Entrar'); ?><div class="auth-wrap"><div class="eyebrow">que bom ver você por aqui</div><h1 class="page-title mt-2">Entrar</h1><p class="muted mb-4">Acesse sua conta Broto Urbano.</p><form method="post" action="index.php?page=login" class="vstack gap-3"><?= csrf_field() ?><div><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" required></div><div><label class="form-label">Senha</label><input class="form-control" type="password" name="password" required></div><button class="btn btn-forest">Entrar</button></form><p class="mt-4 muted">Primeira visita? <a href="index.php?page=register">Criar conta</a></p></div><?php render_footer(); break;

    case 'cart':
        $items=cart_items();$total=array_sum(array_column($items,'line_total'));render_header('Sua sacola'); ?><div class="eyebrow">um passo mais perto</div><h1 class="page-title mt-2 mb-4">Sua sacola</h1><?php if (!$items): ?><div class="text-center py-5"><p class="muted">Sua sacola ainda está vazia.</p><a href="index.php" class="btn btn-forest">Explorar produtos</a></div><?php else: ?><div class="row g-4"><div class="col-lg-8"><form id="cart-form" method="post" action="index.php?page=cart_update"><?= csrf_field() ?><div class="table-responsive"><table class="table align-middle cart-table"><thead><tr><th>Produto</th><th>Preço</th><th>Qtd.</th><th>Subtotal</th><th></th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><div class="cart-product"><a href="index.php?page=product&id=<?= (int)$item['id'] ?>" class="cart-thumb" style="--product-image:url('<?= e($item['image_url']) ?>')"></a><a class="text-decoration-none text-dark fw-semibold" href="index.php?page=product&id=<?= (int)$item['id'] ?>"><?= e($item['name']) ?></a></div></td><td><?= money($item['price']) ?></td><td><input class="form-control quantity-input" type="number" min="1" max="<?= (int)$item['stock'] ?>" name="quantity[<?= (int)$item['id'] ?>]" value="<?= (int)$item['quantity'] ?>"></td><td><?= money($item['line_total']) ?></td><td><button form="remove-<?= (int)$item['id'] ?>" class="btn btn-sm btn-outline-secondary">Remover</button></td></tr><?php endforeach ?></tbody></table></div><button class="btn btn-outline-secondary btn-sm">Atualizar quantidades</button></form><?php foreach($items as $item): ?><form id="remove-<?= (int)$item['id'] ?>" method="post" action="index.php?page=cart_remove" class="d-none"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"></form><?php endforeach ?></div><div class="col-lg-4"><div class="summary-box"><h2>Resumo do pedido</h2><div class="d-flex justify-content-between py-3 border-bottom"><span>Subtotal</span><strong><?= money($total) ?></strong></div><div class="d-flex justify-content-between py-3"><span>Total</span><strong><?= money($total) ?></strong></div><form method="post" action="index.php?page=checkout"><?= csrf_field() ?><div class="mb-3"><label class="form-label small">Pagamento demonstrativo</label><select name="payment_method" class="form-select"><option value="pix">PIX</option><option value="boleto">Boleto</option><option value="cartao">Cartão de crédito</option></select></div><button class="btn btn-forest w-100">Finalizar pedido</button></form><small class="muted d-block mt-3">A cobrança não é real; pagamento simulado para o projeto acadêmico.</small></div></div></div><?php endif ?><?php render_footer(); break;

    case 'orders':
        require_login();$stmt=$pdo->prepare('SELECT * FROM orders WHERE user_id=:user ORDER BY created_at DESC');$stmt->execute(['user'=>current_user()['id']]);$orders=$stmt->fetchAll();render_header('Meus pedidos'); ?><div class="eyebrow">suas escolhas verdes</div><h1 class="page-title mt-2 mb-4">Meus pedidos</h1><?php if(!$orders): ?><p class="muted">Você ainda não tem pedidos.</p><a href="index.php" class="btn btn-forest">Ver coleção</a><?php else: ?><div class="accordion" id="orders"><?php foreach($orders as $index=>$order): ?><div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button <?= $index?'collapsed':'' ?>" data-bs-toggle="collapse" data-bs-target="#order<?= (int)$order['id'] ?>">Pedido #<?= (int)$order['id'] ?> · <?= date('d/m/Y H:i',strtotime($order['created_at'])) ?> · <?= money($order['total']) ?></button></h2><div id="order<?= (int)$order['id'] ?>" class="accordion-collapse collapse <?= !$index?'show':'' ?>" data-bs-parent="#orders"><div class="accordion-body"><p>Pagamento: <?= e(strtoupper($order['payment_method'])) ?> · <?= e($order['status']) ?></p><?php $lines=$pdo->prepare('SELECT oi.quantity,oi.unit_price,p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=:id');$lines->execute(['id'=>$order['id']]);foreach($lines->fetchAll() as $line): ?><div class="d-flex justify-content-between border-top py-2"><span><?= (int)$line['quantity'] ?> × <?= e($line['name']) ?></span><span><?= money((float)$line['quantity']*(float)$line['unit_price']) ?></span></div><?php endforeach ?></div></div></div><?php endforeach ?></div><?php endif ?><?php render_footer(); break;

    case 'admin':
        require_admin();$edit=null;if(isset($_GET['edit'])){$stmt=$pdo->prepare('SELECT * FROM products WHERE id=:id');$stmt->execute(['id'=>(int)$_GET['edit']]);$edit=$stmt->fetch();}$products=$pdo->query('SELECT p.*,c.name AS category_name FROM products p JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC')->fetchAll();$categories=$pdo->query('SELECT id,name FROM categories ORDER BY name')->fetchAll();render_header('Administração'); ?><div class="d-flex flex-wrap justify-content-between align-items-end gap-3"><div><div class="eyebrow">área administrativa</div><h1 class="page-title mt-2">Loja em suas mãos</h1></div><a href="index.php?page=admin_orders" class="btn btn-outline-success">Ver pedidos</a></div><div class="admin-nav"><a href="#form-produto">Cadastrar produto</a><a href="#lista-produtos">Gerenciar catálogo</a></div><section id="form-produto" class="summary-box mb-4"><h2><?= $edit?'Editar produto':'Novo produto' ?></h2><form method="post" action="index.php?page=admin_save" class="row g-3"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><div class="col-md-6"><label class="form-label">Nome</label><input class="form-control" name="name" required value="<?= e($edit['name']??'') ?>"></div><div class="col-md-3"><label class="form-label">Categoria</label><select class="form-select" name="category_id" required><?php foreach($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= (int)($edit['category_id']??0)===(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach ?></select></div><div class="col-md-3"><label class="form-label">Preço (R$)</label><input class="form-control" name="price" type="number" min="0" step="0.01" required value="<?= e($edit['price']??'') ?>"></div><div class="col-md-3"><label class="form-label">Estoque</label><input class="form-control" name="stock" type="number" min="0" required value="<?= e($edit['stock']??0) ?>"></div><div class="col-md-9"><label class="form-label">URL da imagem</label><input class="form-control" name="image_url" type="url" required value="<?= e($edit['image_url']??'') ?>"></div><div class="col-md-4"><label class="form-label">Selo (opcional)</label><input class="form-control" name="badge" value="<?= e($edit['badge']??'') ?>"></div><div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="3" required><?= e($edit['description']??'') ?></textarea></div><div class="col-12"><button class="btn btn-forest">Salvar produto</button><?php if($edit): ?> <a href="index.php?page=admin" class="btn btn-outline-secondary">Cancelar</a><?php endif ?></div></form></section><section id="lista-produtos"><div class="section-heading"><h2>Catálogo</h2><span class="muted small"><?= count($products) ?> produtos</span></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Estoque</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($products as $product): ?><tr><td><?= e($product['name']) ?></td><td><?= e($product['category_name']) ?></td><td><?= money($product['price']) ?></td><td><?= (int)$product['stock'] ?></td><td><?= $product['active']?'Ativo':'Pausado' ?></td><td><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-success" href="index.php?page=admin&edit=<?= (int)$product['id'] ?>">Editar</a><form method="post" action="index.php?page=admin_toggle"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><button class="btn btn-sm btn-outline-secondary">Ativar/pausar</button></form></div></td></tr><?php endforeach ?></tbody></table></div></section><?php render_footer(); break;

    case 'admin_orders':
        require_admin();$orders=$pdo->query('SELECT o.*,u.name AS customer_name,u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC')->fetchAll();render_header('Pedidos da loja'); ?><div class="eyebrow">visão da loja</div><h1 class="page-title mt-2 mb-4">Pedidos realizados</h1><?php if(!$orders): ?><p class="muted">Nenhum pedido realizado ainda.</p><?php else: ?><div class="accordion" id="admin-orders"><?php foreach($orders as $index=>$order): ?><div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button <?= $index?'collapsed':'' ?>" data-bs-toggle="collapse" data-bs-target="#admin-order<?= (int)$order['id'] ?>">#<?= (int)$order['id'] ?> · <?= e($order['customer_name']) ?> · <?= money($order['total']) ?> · <?= date('d/m/Y H:i',strtotime($order['created_at'])) ?></button></h2><div id="admin-order<?= (int)$order['id'] ?>" class="accordion-collapse collapse <?= !$index?'show':'' ?>" data-bs-parent="#admin-orders"><div class="accordion-body"><p><?= e($order['email']) ?> · <?= e(strtoupper($order['payment_method'])) ?> · <?= e($order['status']) ?></p><?php $lines=$pdo->prepare('SELECT oi.quantity,oi.unit_price,p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=:id');$lines->execute(['id'=>$order['id']]);foreach($lines->fetchAll() as $line): ?><div class="d-flex justify-content-between border-top py-2"><span><?= (int)$line['quantity'] ?> × <?= e($line['name']) ?></span><span><?= money((float)$line['quantity']*(float)$line['unit_price']) ?></span></div><?php endforeach ?></div></div></div><?php endforeach ?></div><?php endif ?><a class="btn btn-outline-secondary mt-4" href="index.php?page=admin">Voltar ao catálogo</a><?php render_footer(); break;

    default:
        http_response_code(404);render_header('Página não encontrada'); ?><h1 class="page-title">Essa página não está aqui.</h1><a href="index.php">Voltar à loja</a><?php render_footer();
}
