<?php
session_start();

$usuariosArquivo = __DIR__ . '/data/usuarios.json';
$usuarios = json_decode(file_get_contents($usuariosArquivo), true) ?: [];
$erro = '';

if (isset($_SESSION['usuario_id'])) {
    header('Location: pages/dashbord/dashbord.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    foreach ($usuarios as $usuario) {
        if (($usuario['username'] ?? '') === $username && ($usuario['ativo'] ?? false) && password_verify($password, $usuario['password'] ?? '')) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];
            $_SESSION['usuario_nivel'] = $usuario['nivel'];
            $_SESSION['login_time'] = time();
            header('Location: pages/dashbord/dashbord.html');
            exit;
        }
    }

    $erro = 'Usuário ou senha inválidos.';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="author" content="Marcelo Almeida">
    <title>Sistema Gestão Login</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/pages/login.css">
    <link rel="icon" type="image/x-icon" href="img/favicon/favicon.ico">
</head>
<body>
    <main class="container">
        <form method="post" action="" autocomplete="on">
            <img src="img/pages/login/orion-title" alt="Orion Inventory">
            <?php if ($erro): ?>
                <div class="login-error"><?php echo htmlspecialchars($erro); ?></div>
            <?php endif; ?>
            <div class="input-box">
                <input type="text" name="username" placeholder="Usuário" autocomplete="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                <i class="bx bxs-user"></i>
            </div>
            <div class="input-box">
                <input type="password" name="password" placeholder="Senha" autocomplete="current-password" required>
                <i class="bx bxs-lock-alt"></i>
            </div>
            <div class="remember-forgot">
                <label><input type="checkbox" name="remember">Lembrar minha senha</label>
                <a href="#" onclick="return false;">Esqueci minha senha</a>
            </div>
            <button type="submit" class="login">Entrar</button>
        </form>
    </main>
</body>
</html>