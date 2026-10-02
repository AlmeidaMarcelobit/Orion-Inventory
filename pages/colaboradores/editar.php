<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
$arquivo = dirname(__DIR__, 2) . '/data/colaboradores/ativos.json';
$colaboradores = json_decode(file_get_contents($arquivo), true) ?: [];
$id = $_GET['id'] ?? $_POST['id'] ?? '';
$indice = null;
foreach ($colaboradores as $i => $colaborador) {
    if ((string)($colaborador['id'] ?? '') === (string)$id) { $indice = $i; break; }
}
if ($indice === null) { header('Location: colaboradores.php'); exit; }
$colaborador = $colaboradores[$indice];
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $matricula = trim($_POST['matricula'] ?? '');
    $cargo = trim($_POST['cargo'] ?? '');
    $departamento = trim($_POST['departamento'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cpf = preg_replace('/D/', '', $_POST['cpf'] ?? '');
    $tipoTrabalho = $_POST['tipo_trabalho'] ?? 'local';
    if ($nome === '' || $email === '') $erro = 'Nome e e-mail são obrigatórios.';
    else {
        $colaboradores[$indice]['nome'] = $nome;
        $colaboradores[$indice]['matricula'] = $matricula;
        $colaboradores[$indice]['cargo'] = $cargo;
        $colaboradores[$indice]['departamento'] = $departamento;
        $colaboradores[$indice]['email'] = $email;
        $colaboradores[$indice]['cpf'] = $cpf;
        $colaboradores[$indice]['tipo_trabalho'] = $tipoTrabalho;
        $colaboradores[$indice]['data_atualizacao'] = date('Y-m-d H:i:s');
        file_put_contents($arquivo, json_encode($colaboradores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: colaboradores.php'); exit;
    }
    $colaborador = array_merge($colaborador, $_POST);
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar Colaborador - Orion Inventory</title><link rel="stylesheet" href="../../assets/css/global/import.css"><link rel="stylesheet" href="../../assets/css/pages/dashboard.css"><link rel="stylesheet" href="../../assets/css/pages/colaboradores.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><style>
.edit-page{flex:1;width:min(900px,calc(100% - 56px));margin:38px auto}.edit-card{padding:28px;background:#fff;border:1px solid var(--orion-border);border-radius:16px;box-shadow:var(--orion-shadow)}.edit-title{margin-bottom:24px}.edit-title h1{margin:0;font-size:25px}.edit-title h1 i{color:var(--orion-pink)}.edit-title p{margin:7px 0;color:var(--orion-muted);font-size:13px}.edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.edit-field{display:flex;flex-direction:column;gap:7px}.edit-field.full{grid-column:1/-1}.edit-field label{color:var(--orion-text);font-size:13px;font-weight:600}.edit-field input,.edit-field select{height:44px;padding:0 13px;border:1px solid var(--orion-border);border-radius:10px;outline:0;font:inherit}.edit-field input:focus,.edit-field select:focus{border-color:var(--orion-pink);box-shadow:0 0 0 3px #ff007722}.edit-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:25px}.edit-actions a,.edit-actions button{padding:11px 18px;border:0;border-radius:10px;text-decoration:none;font:inherit;cursor:pointer}.edit-actions a{color:var(--orion-text);background:#f1f2f4}.edit-actions button{color:#fff;background:var(--orion-pink)}.edit-error{margin-bottom:18px;padding:11px;color:#a5224d;background:#fff0f7;border-radius:9px}@media(max-width:650px){.edit-page{width:calc(100% - 28px);margin:24px auto}.edit-grid{grid-template-columns:1fr}.edit-field.full{grid-column:auto}}
</style></head><body>
<header><nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul><li class="item-menu"><a href="../dashbord/dashbord.php"><span class="item"><i class="bi bi-columns-gap"></i></span><span class="txt-link">Dashboard</span></a></li><li class="item-menu active"><a href="colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li><li class="item-menu"><a href="#"><span class="item"><i class="bi bi-pc-display-horizontal"></i></span><span class="txt-link">Equipamentos</span></a></li><li class="item-menu"><a href="#"><span class="item"><i class="bi bi-sd-card"></i></span><span class="txt-link">Linhas</span></a></li><li class="item-menu"><a href="#"><span class="item"><i class="bi bi-file-earmark-pdf"></i></span><span class="txt-link">Termos</span></a></li><li class="item-menu"><a href="#"><span class="item"><i class="bi bi-tools"></i></span><span class="txt-link">Manutenção</span></a></li><li class="item-menu"><a href="#"><span class="item"><i class="bi bi-person-circle"></i></span><span class="txt-link">Usuários</span></a></li></ul></nav><div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div><div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div><a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a></header>
<main class="edit-page"><div class="edit-card"><div class="edit-title"><h1><i class="fas fa-user-pen"></i> Editar colaborador</h1><p>Atualize as informações cadastrais do colaborador.</p></div><?php if($erro): ?><div class="edit-error"><?php echo htmlspecialchars($erro); ?></div><?php endif; ?><form method="post"><input type="hidden" name="id" value="<?php echo htmlspecialchars($colaborador['id']); ?>"><div class="edit-grid"><div class="edit-field full"><label>Nome completo</label><input name="nome" required value="<?php echo htmlspecialchars($colaborador['nome'] ?? ''); ?>"></div><div class="edit-field"><label>Matrícula</label><input name="matricula" value="<?php echo htmlspecialchars($colaborador['matricula'] ?? ''); ?>"></div><div class="edit-field"><label>CPF</label><input name="cpf" value="<?php echo htmlspecialchars($colaborador['cpf'] ?? ''); ?>"></div><div class="edit-field"><label>Cargo</label><input name="cargo" value="<?php echo htmlspecialchars($colaborador['cargo'] ?? ''); ?>"></div><div class="edit-field"><label>Departamento</label><input name="departamento" value="<?php echo htmlspecialchars($colaborador['departamento'] ?? ''); ?>"></div><div class="edit-field full"><label>E-mail</label><input type="email" name="email" required value="<?php echo htmlspecialchars($colaborador['email'] ?? ''); ?>"></div><div class="edit-field"><label>Tipo de trabalho</label><select name="tipo_trabalho"><option value="local" <?php echo ($colaborador['tipo_trabalho'] ?? 'local') === 'local' ? 'selected' : ''; ?>>Presencial</option><option value="home" <?php echo ($colaborador['tipo_trabalho'] ?? '') === 'home' ? 'selected' : ''; ?>>Home Office</option></select></div></div><div class="edit-actions"><a href="colaboradores.php">Cancelar</a><button type="submit"><i class="fas fa-save"></i> Salvar alterações</button></div></form></div></main><footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer></body></html>