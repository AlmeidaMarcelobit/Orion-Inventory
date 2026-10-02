<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
$arquivo = dirname(__DIR__, 2) . '/data/colaboradores/ativos.json';
$colaboradores = json_decode(file_get_contents($arquivo), true) ?: [];
function formatarCpf($cpf): string {
    $numero = preg_replace('/\\D/', '', (string)$cpf);
    return strlen($numero) === 11 ? substr($numero, 0, 3) . '.' . substr($numero, 3, 3) . '.' . substr($numero, 6, 3) . '-' . substr($numero, 9, 2) : ((string)$cpf ?: '—');
}
$busca = trim($_GET['busca'] ?? '');
$departamento = $_GET['departamento'] ?? 'todos';
$bit = $_GET['bit'] ?? 'todos';
$milvus = $_GET['milvus'] ?? 'todos';
$acao = $_POST['acao'] ?? '';
$arquivoInativos = dirname(__DIR__, 2) . '/data/colaboradores/inativos.json';
$equipamentosAlocados = json_decode(file_get_contents(dirname(__DIR__, 2) . '/data/equipamentos/alocados.json'), true) ?: [];
$contagemEquipamentos = [];
foreach ($equipamentosAlocados as $equipamento) {
    if (($equipamento['status'] ?? '') !== 'alocado' || empty($equipamento['colaborador_id'])) continue;
    $idEquip = (string)$equipamento['colaborador_id'];
    $contagemEquipamentos[$idEquip] = ($contagemEquipamentos[$idEquip] ?? 0) + 1;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'inativar') {
    $idInativar = (string)($_POST['id'] ?? '');
    foreach ($colaboradores as $indice => $colaborador) {
        if ((string)($colaborador['id'] ?? '') !== $idInativar) continue;
        $inativos = json_decode(file_get_contents($arquivoInativos), true) ?: [];
        $colaborador['data_inativacao'] = date('Y-m-d H:i:s');
        $inativos[] = $colaborador; unset($colaboradores[$indice]);
        file_put_contents($arquivo, json_encode(array_values($colaboradores), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        file_put_contents($arquivoInativos, json_encode($inativos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        header('Location: colaboradores.php'); exit;
    }
}

$softwarePorColaborador = [];
foreach ($equipamentosAlocados as $equipamento) {
    if (($equipamento['tipo'] ?? '') !== 'notebook' || empty($equipamento['colaborador_id'])) continue;
    $idColaborador = (string)$equipamento['colaborador_id'];
    $softwarePorColaborador[$idColaborador] = [
        'bit' => filter_var($equipamento['especificacoes']['bit_instalado'] ?? $equipamento['bit_instalado'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'milvus' => filter_var($equipamento['especificacoes']['milvus_instalado'] ?? $equipamento['milvus_instalado'] ?? false, FILTER_VALIDATE_BOOLEAN)
    ];
}
$departamentos = array_values(array_unique(array_filter(array_map(fn($c) => $c['departamento'] ?? '', $colaboradores))));
sort($departamentos);
$colaboradores = array_values(array_filter($colaboradores, function ($c) use ($busca, $departamento, $bit, $milvus, $softwarePorColaborador) {
    $texto = strtolower(($c['nome'] ?? '') . ' ' . ($c['cpf'] ?? '') . ' ' . ($c['email'] ?? ''));
    $okBusca = $busca === '' || str_contains($texto, strtolower($busca));
    $okDepartamento = $departamento === 'todos' || ($c['departamento'] ?? '') === $departamento;
    $okBit = $bit === 'todos' || (($softwarePorColaborador[(string)($c['id'] ?? '')]['bit'] ?? false) === ($bit === 'sim'));
    $okMilvus = $milvus === 'todos' || (($softwarePorColaborador[(string)($c['id'] ?? '')]['milvus'] ?? false) === ($milvus === 'sim'));
    return $okBusca && $okDepartamento && $okBit && $okMilvus;
}));
usort($colaboradores, fn($a,$b) => strcasecmp($a['nome'] ?? '', $b['nome'] ?? ''));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Colaboradores - Orion Inventory</title>
<link rel="stylesheet" href="../../assets/css/global/import.css">
<link rel="stylesheet" href="../../assets/css/pages/dashboard.css">
<link rel="stylesheet" href="../../assets/css/pages/colaboradores.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<header>
<nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul>
<li class="item-menu"><a href="../dashbord/dashbord.php"><span class="item"><i class="bi bi-columns-gap"></i></span><span class="txt-link">Dashboard</span></a></li>
<li class="item-menu active"><a href="colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-pc-display-horizontal"></i></span><span class="txt-link">Equipamentos</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-sd-card"></i></span><span class="txt-link">Linhas</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-file-earmark-pdf"></i></span><span class="txt-link">Termos</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-tools"></i></span><span class="txt-link">Manutenção</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-person-circle"></i></span><span class="txt-link">Usuários</span></a></li>
</ul></nav>
<div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div>
<div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div>
<a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a>
</header>
<main class="collaborators-page">
<div class="page-title"><div><h1><i class="fas fa-users"></i> Colaboradores</h1><p>Gerencie os colaboradores cadastrados no sistema</p></div><a class="add-collaborator" href="adicionar.php"><i class="fas fa-user-plus"></i> Adicionar colaborador</a></div>
<section class="collaborators-filters"><form method="get"><div class="filter-search"><i class="fas fa-search"></i><input type="search" name="busca" value="<?php echo htmlspecialchars($busca); ?>" placeholder="Buscar por nome, CPF ou e-mail"></div><select name="departamento"><option value="todos">Todos os departamentos</option><?php foreach($departamentos as $dep): ?><option value="<?php echo htmlspecialchars($dep); ?>" <?php echo $departamento === $dep ? 'selected' : ''; ?>><?php echo htmlspecialchars($dep); ?></option><?php endforeach; ?></select><select name="bit"><option value="todos">Antivírus: todos</option><option value="sim" <?php echo $bit === 'sim' ? 'selected' : ''; ?>>Antivírus instalado</option><option value="nao" <?php echo $bit === 'nao' ? 'selected' : ''; ?>>Sem antivírus</option></select><button class="filter-button" type="submit"><i class="fas fa-filter"></i> Filtrar</button><a class="clear-button" href="colaboradores.php"><i class="fas fa-rotate-left"></i></a></form></section>
<section class="collaborators-card"><div class="table-heading"><h2><i class="fas fa-list"></i> Lista de colaboradores</h2></div><div class="collaborators-table-wrap"><table><thead><tr><th>Nome</th><th>Departamento</th><th>Cargo</th><th>E-mail</th><th>Tipo</th><th>Software</th><th>Equipamentos</th><th>Ações</th></tr></thead><tbody><?php if(!$colaboradores): ?><tr><td colspan="8" class="empty-row">Nenhum colaborador encontrado.</td></tr><?php else: foreach($colaboradores as $c): ?><tr><td><strong><?php echo htmlspecialchars($c['nome'] ?? ''); ?></strong><small><?php echo htmlspecialchars(formatarCpf($c['cpf'] ?? '')); ?></small></td><td><?php echo htmlspecialchars($c['departamento'] ?? '—'); ?></td><td><?php echo htmlspecialchars($c['cargo'] ?? '—'); ?></td><td><?php echo htmlspecialchars($c['email'] ?? '—'); ?></td><td><span class="work-badge"><?php echo ($c['tipo_trabalho'] ?? 'local') === 'home' ? 'Home Office' : 'Presencial'; ?></span></td><td class="software-status"><span title="Antivírus"><i class="fas fa-<?php echo ($softwarePorColaborador[(string)($c['id'] ?? '')]['bit'] ?? false) ? 'check-circle status-ok' : 'times-circle status-no'; ?>"></i></span><span title="Milvus"><i class="fas fa-<?php echo ($softwarePorColaborador[(string)($c['id'] ?? '')]['milvus'] ?? false) ? 'check-circle status-ok' : 'times-circle status-no'; ?>"></i></span></td><td><span class="equipment-count"><i class="fas fa-laptop"></i> <?php echo $contagemEquipamentos[(string)($c['id'] ?? '')] ?? 0; ?></span></td><td class="table-actions"><a href="editar.php?id=<?php echo urlencode($c['id']); ?>" class="edit-icon" title="Editar colaborador"><i class="fas fa-pen"></i></a><form method="post" class="inline-action" onsubmit="return confirm('Inativar este colaborador?');"><input type="hidden" name="acao" value="inativar"><input type="hidden" name="id" value="<?php echo htmlspecialchars($c['id']); ?>"><button class="inactive-icon" title="Inativar colaborador" type="submit"><i class="fas fa-user-slash"></i></button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
</main>
<footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer>
</body></html>













