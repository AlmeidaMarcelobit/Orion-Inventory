<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
$tipo = basename(__FILE__, '.php');
$arquivo = dirname(__DIR__, 2) . '/data/colaboradores/' . ($tipo === 'inativos' ? 'inativos.json' : 'terceiros.json');
$lista = json_decode(file_get_contents($arquivo), true) ?: [];
$nomesColaboradores = []; foreach ($lista as $colaborador) { $nomesColaboradores[(string)($colaborador['id'] ?? '')] = $colaborador['nome'] ?? ''; }
$pendencias = json_decode(file_get_contents(dirname(__DIR__, 2) . '/data/equipamentos/devolucoes_pendentes.json'), true) ?: [];if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'devolver') {
    $idEquipamento = (string)($_POST['equipamento_id'] ?? '');
    $arquivoAlocados = dirname(__DIR__, 2) . '/data/equipamentos/alocados.json';
    $alocados = json_decode(file_get_contents($arquivoAlocados), true) ?: [];
    $estoque = json_decode(file_get_contents(dirname(__DIR__, 2) . '/data/equipamentos/estoque.json'), true) ?: [];
    $devolvido = null;
    foreach ($alocados as $indice => &$item) {
        if ((string)($item['id'] ?? '') === $idEquipamento) {
            $item['status'] = 'estoque'; $item['colaborador_id'] = null; $item['data_devolucao'] = date('Y-m-d H:i:s');
            $devolvido = $item; unset($alocados[$indice]); break;
        }
    }
    unset($item);
    if ($devolvido) { $estoque[] = $devolvido; }
    $pendencias = array_values(array_filter($pendencias, fn($item) => (string)($item['id'] ?? '') !== $idEquipamento));
    file_put_contents($arquivoAlocados, json_encode(array_values($alocados), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    file_put_contents(dirname(__DIR__, 2) . '/data/equipamentos/estoque.json', json_encode($estoque, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    file_put_contents(dirname(__DIR__, 2) . '/data/equipamentos/devolucoes_pendentes.json', json_encode($pendencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    header('Location: inativos.php'); exit;
}
$titulo = $tipo === 'inativos' ? 'Colaboradores inativos' : 'Terceiros';
?>
<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo $titulo; ?> - Orion Inventory</title><link rel="stylesheet" href="../../assets/css/global/import.css"><link rel="stylesheet" href="../../assets/css/pages/dashboard.css"><link rel="stylesheet" href="../../assets/css/pages/colaboradores.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head><body><header><nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul><li class="item-menu active"><a href="colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li></ul></nav><div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div><div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div><a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a></header><main class="collaborators-page"><div class="page-title"><div><h1><i class="fas fa-users"></i> <?php echo $titulo; ?></h1><p>Registros separados dos colaboradores ativos</p></div><a class="secondary-action" href="colaboradores.php"><i class="fas fa-arrow-left"></i> Colaboradores</a></div><section class="collaborators-card"><div class="table-heading"><h2><i class="fas fa-list"></i> <?php echo $titulo; ?></h2></div><div class="collaborators-table-wrap"><table><thead><tr><th>Nome</th><th>CPF</th><th>Departamento</th><th>E-mail</th></tr></thead><tbody><?php if (!$lista): ?><tr><td colspan="4" class="empty-row">Nenhum registro encontrado.</td></tr><?php else: foreach ($lista as $item): ?><tr><td><strong><?php echo htmlspecialchars($item['nome'] ?? ''); ?></strong></td><td><?php echo htmlspecialchars($item['cpf'] ?? '—'); ?></td><td><?php echo htmlspecialchars($item['departamento'] ?? '—'); ?></td><td><?php echo htmlspecialchars($item['email'] ?? '—'); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section><section class="collaborators-card" style="margin-top:22px"><div class="table-heading"><h2><i class="fas fa-box-open"></i> Equipamentos pendentes de devolução</h2></div><div class="collaborators-table-wrap"><table><thead><tr><th>Patrimônio</th><th>Tipo</th><th>Hostname</th><th>Colaborador</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php if (!$pendencias): ?><tr><td colspan="6" class="empty-row">Nenhum equipamento pendente.</td></tr><?php else: foreach ($pendencias as $equipamento): ?><tr class="pending-return"><td><?php echo htmlspecialchars($equipamento['patrimonio'] ?? '—'); ?></td><td><?php echo htmlspecialchars($equipamento['tipo'] ?? '—'); ?></td><td><?php echo htmlspecialchars($equipamento['hostname'] ?? '—'); ?></td><td><?php echo htmlspecialchars($equipamento['colaborador_nome'] ?? ($nomesColaboradores[(string)($equipamento['colaborador_id'] ?? '')] ?? ($equipamento['colaborador_id'] ?? '—'))); ?></td><td><span class="work-badge">Aguardando devolução</span></td><td><form method="post"><input type="hidden" name="acao" value="devolver"><input type="hidden" name="equipamento_id" value="<?php echo htmlspecialchars($equipamento['id'] ?? ''); ?>"><button class="return-button" type="submit"><i class="fas fa-boxes-packing"></i> Devolver</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></section></main><footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer></body></html>



