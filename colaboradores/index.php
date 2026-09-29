<?php
session_start();
require_once '../includes/funcoes.php';

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

// Verificar nível do usuário
$usuario_nivel = $_SESSION['usuario_nivel'] ?? 'user';
$is_admin = ($usuario_nivel === 'admin');

// ── INATIVAÇÃO (POST deve rodar ANTES de qualquer filtro/sort) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inativar'])) {
    $colaboradorId = $_POST['colaborador_id'] ?? null;

    if ($colaboradorId) {
        // Recarregar lista COMPLETA direto do arquivo — nunca usar array já filtrado
        $ativosCompleto = lerArquivoJSON('../data/colaboradores/ativos.json');
        if (!is_array($ativosCompleto)) $ativosCompleto = [];

        $colaboradorEncontrado = null;
        $novosAtivos = [];

        foreach ($ativosCompleto as $colab) {
            if ($colab['id'] == $colaboradorId) {
                $colaboradorEncontrado = $colab;
                // não adiciona em $novosAtivos → remove dos ativos
            } else {
                $novosAtivos[] = $colab;
            }
        }

        if (!$colaboradorEncontrado) {
            $_SESSION['mensagem']      = 'Colaborador não encontrado.';
            $_SESSION['mensagem_tipo'] = 'error';
            header('Location: index.php');
            exit;
        }

        $tipoTrabalho = $colaboradorEncontrado['tipo_trabalho'] ?? 'local';

        $colaboradorEncontrado['data_inativacao']    = date('Y-m-d H:i:s');
        $colaboradorEncontrado['motivo_inativacao']  = $tipoTrabalho === 'home'
            ? 'Aguardando devolução de equipamentos (Home Office)'
            : 'Inativado pelo sistema';
        $colaboradorEncontrado['status_inativacao']  = $tipoTrabalho === 'home' ? 'pendente' : 'inativo';

        // Adicionar aos inativos
        $inativos = lerArquivoJSON('../data/colaboradores/inativos.json');
        if (!is_array($inativos)) $inativos = [];
        $inativos[] = $colaboradorEncontrado;

        // ── Devolver equipamentos (arquitetura atual: arquivos por status) ──────
        $equipamentosAtualizados = 0;

        if ($tipoTrabalho !== 'home') {
            foreach (['alocado', 'emprestado'] as $statusEquip) {
                $listaStatus = carregarEquipamentosPorStatus($statusEquip);
                $ficam       = [];
                $devolver    = [];

                foreach ($listaStatus as $equip) {
                    if ($equip['colaborador_id'] == $colaboradorId) {
                        $obs = $equip['observacoes'] ?? '';
                        $equip['observacoes']    = trim($obs . "\n\n[INATIVAÇÃO] " . date('d/m/Y H:i:s')
                            . "\nColaborador: {$colaboradorEncontrado['nome']} — devolvido ao estoque.");
                        $equip['colaborador_id'] = null;
                        $equip['status']         = 'estoque';
                        $equip['data_atribuicao']= null;
                        $equip['tipo_atribuicao']= null;
                        $equip['data_atualizacao']= date('Y-m-d H:i:s');
                        unset($equip['data_devolucao_prevista']);
                        $devolver[] = $equip;
                        $equipamentosAtualizados++;
                    } else {
                        $ficam[] = $equip;
                    }
                }

                // Salvar lista sem os equipamentos devolvidos
                salvarArquivoJSON(getCaminhoEquipamentoPorStatus($statusEquip), $ficam);

                // Adicionar em lote ao estoque
                if (!empty($devolver)) {
                    $estoque = carregarEquipamentosPorStatus('estoque');
                    $estoque = array_merge($estoque, $devolver);
                    salvarArquivoJSON(getCaminhoEquipamentoPorStatus('estoque'), $estoque);
                }
            }
        }

        // ── Remover vínculo das linhas ────────────────────────────────────────
        $linhasAll = lerArquivoJSON('../data/linhas.json');
        if (!is_array($linhasAll)) $linhasAll = [];
        foreach ($linhasAll as &$linha) {
            if (($linha['colaborador_id'] ?? null) == $colaboradorId) {
                $linha['colaborador_id']   = null;
                $linha['data_atualizacao'] = date('Y-m-d H:i:s');
            }
        }
        unset($linha);

        // ── Salvar tudo ───────────────────────────────────────────────────────
        $saveAtivos   = salvarArquivoJSON('../data/colaboradores/ativos.json',   $novosAtivos);
        $saveInativos = salvarArquivoJSON('../data/colaboradores/inativos.json', $inativos);
        $saveLinhas   = salvarArquivoJSON('../data/linhas.json', $linhasAll);

        if ($saveAtivos && $saveInativos && $saveLinhas) {
            $_SESSION['mensagem'] = $tipoTrabalho === 'home'
                ? "Colaborador movido para inativos com pendência de devolução de equipamentos."
                : "Colaborador inativado com sucesso! {$equipamentosAtualizados} equipamento(s) devolvido(s) ao estoque.";
            $_SESSION['mensagem_tipo'] = 'success';
        } else {
            $_SESSION['mensagem']      = 'Erro ao inativar colaborador. Tente novamente.';
            $_SESSION['mensagem_tipo'] = 'error';
        }

        header('Location: index.php');
        exit;
    }
}

// ── CARREGAR DADOS PARA EXIBIÇÃO ──────────────────────────────────────────────
$colaboradores = lerArquivoJSON('../data/colaboradores/ativos.json');
if (!is_array($colaboradores)) $colaboradores = [];

$linhas = lerArquivoJSON('../data/linhas.json');
if (!is_array($linhas)) $linhas = [];

// Normalizar campos
foreach ($colaboradores as &$colab) {
    if (!isset($colab['matricula']))    $colab['matricula']    = '';
    if (!isset($colab['cpf']))          $colab['cpf']          = '';
    if (!isset($colab['departamento'])) $colab['departamento'] = '';
    if (!isset($colab['email']))        $colab['email']        = '';
    if (!isset($colab['tipo_trabalho']))$colab['tipo_trabalho']= 'local';
    if (!isset($colab['equipamentos']) || !is_array($colab['equipamentos'])) $colab['equipamentos'] = [];
}
unset($colab);

// Ordenar alfabeticamente
usort($colaboradores, fn($a, $b) => strcmp($a['nome'], $b['nome']));

// Mapa de equipamentos por colaborador (arquitetura atual)
$equipamentosPorColaborador = [];
foreach (carregarTodosEquipamentos() as $equip) {
    if (!empty($equip['colaborador_id']) && in_array($equip['status'], ['alocado', 'emprestado'])) {
        $equipamentosPorColaborador[$equip['colaborador_id']][] = $equip;
    }
}

// Mapa de linhas por colaborador
$linhasPorColaborador = [];
foreach ($linhas as $linha) {
    if (!empty($linha['colaborador_id'])) {
        $linhasPorColaborador[$linha['colaborador_id']][] = $linha;
    }
}

// Filtro de busca (só afeta a exibição, nunca os dados salvos)
$busca = trim($_GET['busca'] ?? '');
$buscaCpf = preg_match('/^[0-9.\-\s]+$/', $busca) ? preg_replace('/[^0-9]/', '', $busca) : '';
if ($busca !== '') {
    $colaboradores = array_values(array_filter($colaboradores, function($c) use ($busca, $buscaCpf) {
        return stripos($c['nome'],        $busca) !== false
            || stripos($c['matricula']  ?? '', $busca) !== false
            || stripos($c['cpf']        ?? '', $busca) !== false
            || ($buscaCpf !== '' && strpos(preg_replace('/[^0-9]/', '', $c['cpf'] ?? ''), $buscaCpf) !== false)
            || stripos($c['departamento']?? '', $busca) !== false
            || stripos($c['email']      ?? '', $busca) !== false;
    }));
    usort($colaboradores, fn($a, $b) => strcmp($a['nome'], $b['nome']));
}

// Estatísticas
$totalColaboradores        = count($colaboradores);
$totalEquipamentosAlocados = count(carregarEquipamentosPorStatus('alocado')) + count(carregarEquipamentosPorStatus('emprestado'));
$totalLinhasAtivas         = count($linhas);
$totalHomeOffice           = count(array_filter($colaboradores, fn($c) => ($c['tipo_trabalho'] ?? 'local') === 'home'));
?>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colaboradores - Sistema de Gestão</title>
    <link rel="stylesheet" href="../css/colaboradores/index.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet">
    <link rel="icon" href="../img/favicon/favicon.png">
</head>
<body>

<!-- ==================== HEADER ==================== -->
<header class="header">
    <div class="header-content">
        <div class="logo">
            <a href="../index.php">
                <i class="fas fa-users"></i>
                <h1>Gestão de Colaboradores</h1>
            </a>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span class="user-name"><?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span>
            </div>
            <a href="../logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Sair</span>
            </a>
        </div>
    </div>
    <nav class="nav-container">
        <ul class="nav-menu">
            <li class="nav-item"><a href="../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="index.php" class="nav-link active"><i class="fas fa-users"></i><span>Colaboradores</span></a></li>
            <li class="nav-item"><a href="../equipamentos/index.php" class="nav-link"><i class="fas fa-laptop"></i><span>Equipamentos</span></a></li>
            <li class="nav-item"><a href="../solicitacoes_manutencao/index.php" class="nav-link"><i class="fas fa-tools"></i><span>Solicitações Manutenção</span></a></li>
            <li class="nav-item"><a href="../linhas/index.php" class="nav-link"><i class="fas fa-phone"></i><span>Linhas</span></a></li>
            <?php if ($is_admin): ?>
                <li class="nav-item"><a href="../Termos/index.php" class="nav-link"><i class="fas fa-file-contract"></i><span>Termos</span></a></li>
                <li class="nav-item"><a href="../usuarios/index.php" class="nav-link"><i class="fas fa-user-cog"></i><span>Usuários</span></a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<!-- Mensagens de alerta -->
<?php if (isset($_SESSION['mensagem'])): ?>
    <div class="global-alert" style="background: <?php echo $_SESSION['mensagem_tipo'] === 'success' ? 'rgba(76,175,80,0.1)' : 'rgba(244,67,54,0.1)'; ?>; border-left: 4px solid <?php echo $_SESSION['mensagem_tipo'] === 'success' ? '#4CAF50' : '#F44336'; ?>;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-<?php echo $_SESSION['mensagem_tipo'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>" style="color: <?php echo $_SESSION['mensagem_tipo'] === 'success' ? '#4CAF50' : '#F44336'; ?>;"></i>
            <span><?php echo htmlspecialchars($_SESSION['mensagem']); ?></span>
        </div>
        <button onclick="this.parentElement.style.display='none'" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
    </div>
    <?php unset($_SESSION['mensagem']); unset($_SESSION['mensagem_tipo']); ?>
<?php endif; ?>

<!-- ==================== CONTEÚDO PRINCIPAL ==================== -->
<main class="main-container">
    <div class="page-header">
        <div>
            <h1><i class="fas fa-users"></i> Colaboradores Ativos</h1>
            <p class="page-subtitle">Gerencie todos os colaboradores ativos da sua organização</p>
        </div>
        <?php if ($is_admin): ?>
            <div style="display: flex; gap: 0.75rem;">
                <a href="adicionar.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary"><i class="fas fa-user-plus"></i> Adicionar Colaborador</a>
                <a href="../terceiros/index.php" target="_blank" rel="noopener noreferrer" class="btn btn-secondary"><i class="fas fa-users"></i> Terceiros</a>
                <a href="inativos.php" target="_blank" rel="noopener noreferrer" class="btn btn-secondary"><i class="fas fa-box-archive"></i> Ver Inativos</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Cards de Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary"><i class="fas fa-users"></i></div>
            <div class="stat-content">
                <h3>Total Colaboradores</h3>
                <div class="stat-number"><?php echo $totalColaboradores; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success"><i class="fas fa-laptop"></i></div>
            <div class="stat-content">
                <h3>Equipamentos Alocados</h3>
                <div class="stat-number"><?php echo $totalEquipamentosAlocados; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon info"><i class="fas fa-phone"></i></div>
            <div class="stat-content">
                <h3>Linhas Ativas</h3>
                <div class="stat-number"><?php echo $totalLinhasAtivas; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning"><i class="fas fa-home"></i></div>
            <div class="stat-content">
                <h3>Home Office</h3>
                <div class="stat-number"><?php echo $totalHomeOffice; ?></div>
            </div>
        </div>
    </div>

    <!-- Busca -->
    <div class="search-section">
        <form method="GET" action="">
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" name="busca" class="search-input" placeholder="Buscar por nome, matrícula, CPF, e-mail ou departamento..." value="<?php echo htmlspecialchars($busca); ?>">
                <button type="submit" class="btn btn-primary search-btn">Buscar</button>
                <?php if ($busca): ?>
                    <a href="index.php" class="btn btn-secondary">Limpar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Grid de Colaboradores -->
    <?php if (empty($colaboradores)): ?>
        <div class="empty-state">
            <i class="fas fa-users-slash"></i>
            <p>Nenhum colaborador ativo encontrado</p>
            <?php if ($busca): ?>
                <a href="index.php" class="btn btn-secondary">Limpar busca</a>
            <?php else: ?>
                <a href="adicionar.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Adicionar primeiro colaborador</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="colaboradores-table-wrapper" role="region" aria-label="Lista de colaboradores" tabindex="0">
            <table class="colaboradores-table">
                <thead>
                    <tr>
                        <th scope="col">Número matrícula</th>
                        <th scope="col">Nome do colaborador</th>
                        <th scope="col">CPF</th>
                        <th scope="col">Linhas</th>
                        <th scope="col">Hostname</th>
                        <th scope="col">Sistema Operacional</th>
                        <th scope="col">BitDefender</th>
                        <th scope="col">Milvus</th>
                        <th scope="col">Equipamentos</th>
                        <th scope="col">Detalhes</th>
                        <?php if ($is_admin): ?>
                            <th scope="col">Inativar</th>
                            <th scope="col">Editar</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($colaboradores as $colaborador):
                    $equipamentosColab = $equipamentosPorColaborador[$colaborador['id']] ?? [];
                    $linhasColab = $linhasPorColaborador[$colaborador['id']] ?? [];
                    $computadores = array_values(array_filter($equipamentosColab, fn($equip) => in_array($equip['tipo'] ?? '', ['desktop', 'notebook'], true)));
                    $isHomeOffice = ($colaborador['tipo_trabalho'] ?? 'local') === 'home';
                    $confirmacao = $isHomeOffice
                        ? 'Tem certeza que deseja inativar este colaborador Home Office? Ele será movido para a lista de inativos com pendência de devolução de equipamentos.'
                        : 'Tem certeza que deseja inativar este colaborador? Todos os equipamentos serão devolvidos ao estoque.';
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($colaborador['matricula'] ?: '—'); ?></td>
                        <th scope="row" class="colaborador-table-name"><?php echo htmlspecialchars($colaborador['nome']); ?></th>
                        <td class="nowrap"><?php echo htmlspecialchars(formatarCPF($colaborador['cpf']) ?: '—'); ?></td>
                        <td>
                            <?php foreach ($linhasColab as $linha): ?>
                                <a class="table-line nowrap" target="_blank" rel="noopener noreferrer" href="../linhas/index.php?colaborador=<?php echo (int)$colaborador['id']; ?>"><?php echo htmlspecialchars(formatarTelefone($linha['numero'] ?? '')); ?></a>
                            <?php endforeach; ?>
                            <?php if (!$linhasColab): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($equipamentosColab as $equip): ?>
                                <?php if (!empty($equip['hostname'])): ?>
                                    <a class="table-line nowrap" target="_blank" rel="noopener noreferrer" href="../equipamentos/index.php?colaborador=<?php echo (int)$colaborador['id']; ?>"><?php echo htmlspecialchars($equip['hostname']); ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!array_filter($equipamentosColab, fn($equip) => !empty($equip['hostname']))): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($computadores as $computador):
                                $sistemaOperacional = $computador['especificacoes']['sistema_operacional'] ?? '';
                                $identificacao = ($computador['hostname'] ?? '') ?: ($computador['patrimonio'] ?? 'Computador');
                            ?>
                                <span class="table-line nowrap" title="<?php echo htmlspecialchars($identificacao); ?>">
                                    <?php echo htmlspecialchars($sistemaOperacional ?: 'Não informado'); ?>
                                    <?php if (count($computadores) > 1): ?><small>(<?php echo htmlspecialchars($identificacao); ?>)</small><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (!$computadores): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <?php foreach (['bit_instalado' => 'BitDefender', 'milvus_instalado' => 'Milvus'] as $campo => $software): ?>
                            <td class="software-cell">
                                <?php foreach ($computadores as $computador):
                                    $instalado = $computador['especificacoes'][$campo] ?? null;
                                    $estado = $instalado === true ? 'sim' : ($instalado === false ? 'nao' : 'desconhecido');
                                    $rotulo = $instalado === true ? 'Instalado' : ($instalado === false ? 'Não instalado' : 'Não informado');
                                    $icone = $instalado === true ? 'check' : ($instalado === false ? 'times' : 'minus');
                                    $identificacao = ($computador['hostname'] ?? '') ?: ($computador['patrimonio'] ?? 'Computador');
                                ?>
                                    <span class="software-entry">
                                        <span class="software-indicator software-<?php echo $estado; ?>" role="img" aria-label="<?php echo htmlspecialchars($software . ': ' . $rotulo . ' — ' . $identificacao); ?>" title="<?php echo htmlspecialchars($identificacao . ': ' . $rotulo); ?>"><i class="fas fa-<?php echo $icone; ?>" aria-hidden="true"></i></span>
                                        <?php if (count($computadores) > 1): ?><small><?php echo htmlspecialchars($identificacao); ?></small><?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (!$computadores): ?><span class="text-muted" title="Sem computador vinculado" aria-label="Sem computador vinculado">—</span><?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="table-action">
                            <?php if (count($equipamentosColab) > 0): ?>
                                <a href="../equipamentos/index.php?colaborador=<?php echo (int)$colaborador['id']; ?>" target="_blank" rel="noopener noreferrer"
                                   class="equipment-count-badge has-equipment"
                                   title="Ver <?php echo count($equipamentosColab); ?> equipamento(s) vinculado(s)"
                                   aria-label="<?php echo count($equipamentosColab); ?> equipamento(s) vinculado(s) a <?php echo htmlspecialchars($colaborador['nome']); ?>">
                                    <i class="fas fa-laptop" aria-hidden="true"></i>
                                    <span><?php echo count($equipamentosColab); ?></span>
                                </a>
                            <?php else: ?>
                                <span class="equipment-count-badge empty" title="Nenhum equipamento vinculado" aria-label="Nenhum equipamento vinculado">
                                    <i class="fas fa-laptop" aria-hidden="true"></i>
                                    <span>0</span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="table-action">
                            <button type="button"
                                    class="table-icon-button view"
                                    title="Ver informações do colaborador"
                                    aria-label="Ver informações de <?php echo htmlspecialchars($colaborador['nome']); ?>"
                                    data-details="<?php echo htmlspecialchars(json_encode(['colaborador' => $colaborador, 'linhas' => $linhasColab, 'equipamentos' => $equipamentosColab], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
                                    onclick="showColaboradorDetails(this)">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                            </button>
                        </td>
                        <?php if ($is_admin): ?>
                            <td class="table-action">
                                <form method="POST" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($confirmacao), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <input type="hidden" name="colaborador_id" value="<?php echo (int)$colaborador['id']; ?>">
                                    <button type="submit" name="inativar" class="table-icon-button inactivate" title="Inativar colaborador" aria-label="Inativar <?php echo htmlspecialchars($colaborador['nome']); ?>"><i class="fas fa-user-slash" aria-hidden="true"></i></button>
                                </form>
                            </td>
                            <td class="table-action">
                                <a href="editar.php?id=<?php echo (int)$colaborador['id']; ?>" target="_blank" rel="noopener noreferrer" class="table-icon-button edit" title="Editar colaborador" aria-label="Editar <?php echo htmlspecialchars($colaborador['nome']); ?>"><i class="fas fa-edit" aria-hidden="true"></i></a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>

<!-- ==================== FOOTER ==================== -->
<footer class="footer">
    <div class="footer-content">
        <div class="footer-section">
            <h3><i class="fas fa-users"></i> Gestão de Colaboradores</h3>
            <p>Controle de colaboradores e equipamentos</p>
        </div>
        <div class="footer-section">
            <h3>Links Rápidos</h3>
            <ul class="footer-links">
                <li><a href="../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="index.php"><i class="fas fa-users"></i> Colaboradores</a></li>
                <li><a href="../equipamentos/index.php"><i class="fas fa-laptop"></i> Equipamentos</a></li>
            </ul>
        </div>
        <div class="footer-section">
            <h3>Estatísticas</h3>
            <?php
            $total_colaboradores = count(lerArquivoJSON('../data/colaboradores/ativos.json'));
            $total_equipamentos  = count(carregarTodosEquipamentos());
            ?>
            <div class="footer-stats">
                <div class="footer-stat"><span class="stat-number"><?php echo $total_colaboradores; ?></span><span class="stat-label">Colaboradores</span></div>
                <div class="footer-stat"><span class="stat-number"><?php echo $total_equipamentos; ?></span><span class="stat-label">Equipamentos</span></div>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
        <p>Sistema de Gestão &copy; <?php echo date('Y'); ?> - Todos os direitos reservados</p>
        <p class="footer-version">Última atualização: <?php echo date('d/m/Y H:i'); ?></p>
    </div>
</footer>

<!-- Card de detalhes do colaborador -->
<div id="modalColaborador" class="modal-linhas colaborador-details-modal" role="dialog" aria-modal="true" aria-labelledby="colaboradorDetailsTitle">
    <div class="modal-linhas-content colaborador-details-card">
        <div class="modal-linhas-header colaborador-details-header">
            <div>
                <span class="details-eyebrow">Informações do colaborador</span>
                <h3 id="colaboradorDetailsTitle"><i class="fas fa-user-circle"></i> <span id="colaboradorDetailsName">Colaborador</span></h3>
            </div>
            <button type="button" class="modal-linhas-close" onclick="closeColaboradorDetails()" aria-label="Fechar detalhes">&times;</button>
        </div>
        <div class="modal-linhas-body colaborador-details-body" id="colaboradorDetailsBody"></div>
    </div>
</div>

<!-- Modal para lista completa de linhas -->
<div id="modalLinhas" class="modal-linhas">
    <div class="modal-linhas-content">
        <div class="modal-linhas-header">
            <h3><i class="fas fa-phone"></i> <span id="modalTitle">Linhas do Colaborador</span></h3>
            <button class="modal-linhas-close" onclick="closeLinhasModal()">&times;</button>
        </div>
        <div class="modal-linhas-body" id="modalLinhasBody"></div>
    </div>
</div>

<script>
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, function(character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character];
        });
    }

    function detailValue(value) {
        return value === null || value === undefined || value === '' ? 'Não informado' : escapeHtml(value);
    }

    function formatarCPFLocal(cpf) {
        const numero = String(cpf || '').replace(/\D/g, '');
        return numero.length === 11 ? `${numero.slice(0, 3)}.${numero.slice(3, 6)}.${numero.slice(6, 9)}-${numero.slice(9)}` : detailValue(cpf);
    }

    function showColaboradorDetails(button) {
        const data = JSON.parse(button.dataset.details || '{}');
        const colaborador = data.colaborador || {};
        const linhas = Array.isArray(data.linhas) ? data.linhas : [];
        const equipamentos = Array.isArray(data.equipamentos) ? data.equipamentos : [];
        const endereco = colaborador.endereco && typeof colaborador.endereco === 'object' ? colaborador.endereco : {};
        const enderecoPartes = [endereco.logradouro, endereco.numero, endereco.complemento, endereco.bairro, endereco.cidade, endereco.estado, endereco.cep].filter(Boolean).map(escapeHtml);
        const tipoTrabalho = colaborador.tipo_trabalho === 'home' ? 'Home Office' : 'Presencial';
        const linhaCards = linhas.length
            ? linhas.map(linha => `<div class="compact-related-item"><strong>${escapeHtml(formatarTelefoneLocal(linha.numero || ''))}</strong><span>${detailValue(linha.tipo)}</span></div>`).join('')
            : '<p class="detail-empty">Nenhuma linha vinculada.</p>';
        const equipamentoCards = equipamentos.length
            ? equipamentos.map(equipamento => {
                const identificacao = equipamento.hostname || equipamento.patrimonio || 'Sem identificação';
                const modelo = [equipamento.marca, equipamento.modelo].filter(Boolean).join(' ');
                return `<div class="compact-related-item"><strong>${escapeHtml(identificacao)}</strong><span>${detailValue(modelo || equipamento.tipo)}</span></div>`;
            }).join('')
            : '<p class="detail-empty">Nenhum equipamento vinculado.</p>';

        document.getElementById('colaboradorDetailsName').textContent = colaborador.nome || 'Colaborador';
        document.getElementById('colaboradorDetailsBody').innerHTML = `
            <dl class="compact-fields">
                <div><dt>Matrícula</dt><dd>${detailValue(colaborador.matricula)}</dd></div>
                <div><dt>CPF</dt><dd>${formatarCPFLocal(colaborador.cpf)}</dd></div>
                <div><dt>Cargo</dt><dd>${detailValue(colaborador.cargo)}</dd></div>
                <div><dt>Departamento</dt><dd>${detailValue(colaborador.departamento)}</dd></div>
                <div><dt>Centro de custo</dt><dd>${detailValue(colaborador.centro_custo)}</dd></div>
                <div><dt>Tipo de trabalho</dt><dd>${tipoTrabalho}</dd></div>
                <div><dt>E-mail</dt><dd>${detailValue(colaborador.email)}</dd></div>
                <div><dt>Endereço</dt><dd>${enderecoPartes.length ? enderecoPartes.join(', ') : 'Não informado'}</dd></div>
                <div class="related-row"><dt><i class="fas fa-phone"></i> Linhas (${linhas.length})</dt><dd>${linhaCards}</dd></div>
                <div class="related-row"><dt><i class="fas fa-laptop"></i> Equipamentos (${equipamentos.length})</dt><dd>${equipamentoCards}</dd></div>
            </dl>`;
        document.getElementById('modalColaborador').style.display = 'block';
        document.body.classList.add('modal-open');
    }

    function closeColaboradorDetails() {
        document.getElementById('modalColaborador').style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    function showLinhasModal(linhas, colaboradorNome) {
        const modal = document.getElementById('modalLinhas');
        const modalTitle = document.getElementById('modalTitle');
        const modalBody = document.getElementById('modalLinhasBody');
        
        modalTitle.innerHTML = `<i class="fas fa-phone"></i> Linhas de ${colaboradorNome}`;
        
        if (linhas.length === 0) {
            modalBody.innerHTML = '<div class="no-linhas"><i class="fas fa-phone-slash"></i><p>Nenhuma linha atribuída a este colaborador.</p></div>';
        } else {
            let html = '';
            linhas.forEach(linha => {
                const numeroFormatado = formatarTelefoneLocal(linha.numero);
                const tipoTexto = linha.tipo === 'chip' ? 'Chip Físico' : 'E-Chip';
                html += `
                    <div class="linha-detail-item">
                        <div>
                            <span class="linha-number">${numeroFormatado}</span>
                            <span class="linha-type"> (${tipoTexto})</span>
                        </div>
                        <span class="cc-badge" style="font-size: 0.7rem;">
                            <i class="fas fa-dollar-sign"></i> ${linha.centro_custo || '---'}
                        </span>
                    </div>
                `;
            });
            modalBody.innerHTML = html;
        }
        
        modal.style.display = 'block';
    }
    
    function closeLinhasModal() {
        document.getElementById('modalLinhas').style.display = 'none';
    }
    
    function formatarTelefoneLocal(telefone) {
        if (!telefone) return '';
        const numero = telefone.replace(/\D/g, '');
        if (numero.length === 11) {
            return `${numero.substring(0, 2)} ${numero.substring(2, 7)}-${numero.substring(7, 11)}`;
        } else if (numero.length === 10) {
            return `${numero.substring(0, 2)} ${numero.substring(2, 6)}-${numero.substring(6, 10)}`;
        }
        return telefone;
    }
    
    window.onclick = function(event) {
        const modalLinhas = document.getElementById('modalLinhas');
        const modalColaborador = document.getElementById('modalColaborador');
        if (event.target === modalLinhas) closeLinhasModal();
        if (event.target === modalColaborador) closeColaboradorDetails();
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeLinhasModal();
            closeColaboradorDetails();
        }
    });
    
    setTimeout(function() {
        const alert = document.querySelector('.global-alert');
        if (alert) {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }
    }, 5000);
</script>
</body>
</html>



