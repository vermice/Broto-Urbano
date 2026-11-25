<?php
declare(strict_types=1);
require __DIR__ . '/../includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute este arquivo pelo terminal.\n");
}
if ($argc < 4) {
    fwrite(STDERR, "Uso: php scripts/criar_admin.php email senha nome\n");
    exit(1);
}
[$script, $email, $password, $name] = $argv;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || trim($name) === '') {
    fwrite(STDERR, "Informe email válido, nome e senha com pelo menos 10 caracteres.\n");
    exit(1);
}
try {
    $stmt = db()->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (:name,:email,:hash,'admin')");
    $stmt->execute(['name' => trim($name), 'email' => strtolower(trim($email)), 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Administrador criado com sucesso.\n");
} catch (PDOException $e) {
    fwrite(STDERR, $e->getCode() === '23000' ? "Este email já está cadastrado.\n" : "Não foi possível criar o administrador. Confira a configuração do banco.\n");
    exit(1);
}
