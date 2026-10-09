<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
date_default_timezone_set('America/Sao_Paulo');
$base = dirname(__DIR__, 2);
$arquivo = $base . '/data/linhas.json';
$tipos = ['chip' => 'Chip físico', 'echip' => 'eSIM'];
$statusNomes = ['disponivel' => 'Disponível', 'alocado' => 'Alocado', 'indisponivel' => 'Indisponível', 'whatsapp_bloqueado' => 'WhatsApp bloqueado'];
function h($valor): string { return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); }
function lerLista(string $arquivo): array {
    $handle = fopen($arquivo, 'r');
    if (!$handle) throw new RuntimeException('Não foi possível abrir os dados.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Não foi possível acessar os dados.');
        $lista = json_decode(stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($lista) || array_values($lista) !== $lista) throw new RuntimeException('Formato de dados inválido.');
        return $lista;
    } finally { fclose($handle); }
}
function numeroFormatado($numero): string {
    $numero = (string)$numero;
    if (strlen($numero) === 11) return '(' . substr($numero, 0, 2) . ') ' . substr($numero, 2, 5) . '-' . substr($numero, 7);
    if (strlen($numero) === 10) return '(' . substr($numero, 0, 2) . ') ' . substr($numero, 2, 4) . '-' . substr($numero, 6);
    return $numero;
}
$_SESSION['linhas_csrf'] ??= bin2hex(random_bytes(32));
$erro = '';
$linhas = $pessoas = $ativos = [];
try {
    $linhas = lerLista($arquivo);
    foreach (['ativos', 'inativos', 'terceiros'] as $grupo) foreach (lerLista($base . '/data/colaboradores/' . $grupo . '.json') as $pessoa) {
        $pessoas[(string)$pessoa['id']] = $pessoa;
        if ($grupo !== 'inativos') $ativos[(string)$pessoa['id']] = $pessoa;
    }
} catch (Throwable $e) { $erro = 'Não foi possível carregar os dados de linhas e colaboradores. Verifique a pasta /data.'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro) {
    $lock = null;
    try {
        if (!hash_equals($_SESSION['linhas_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão inválida. Atualize a página.');
        $lock = fopen($base . '/data/.linhas.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível salvar os dados.');
        $linhas = lerLista($arquivo);
        $originais = $linhas;
        $acao = (string)($_POST['acao'] ?? '');
        $indice = null;
        if ($acao === 'adicionar') {
            $maiorId = 0; foreach ($linhas as $linha) $maiorId = max($maiorId, (int)($linha['id'] ?? 0));
            $linha = ['id' => $maiorId + 1, 'status' => 'disponivel', 'centro_custo' => '11001', 'colaborador_id' => null, 'data_atribuicao' => null, 'data_cadastro' => date('Y-m-d H:i:s')];
        } else {
            foreach ($linhas as $i => $registro) if ((string)($registro['id'] ?? '') === (string)($_POST['id'] ?? '')) { $indice = $i; break; }
            if ($indice === null) throw new RuntimeException('Linha não encontrada. Atualize a página.');
            $linha = $linhas[$indice];
        }
        $centroAnterior = (string)($linha['centro_custo'] ?? '11001');
        $colaboradorAnterior = $linha['colaborador_id'] ?? null;
        $statusAnterior = $linha['status'];
        if (in_array($acao, ['adicionar', 'editar'], true)) {
            $numero = preg_replace('/\D/', '', (string)($_POST['numero'] ?? ''));
            if (strlen($numero) < 10 || strlen($numero) > 15) throw new RuntimeException('Informe um número de linha válido, com DDD.');
            $tipo = (string)($_POST['tipo'] ?? '');
            if (!isset($tipos[$tipo])) throw new RuntimeException('Selecione chip físico ou eSIM.');
            foreach ($linhas as $i => $registro) if ($i !== $indice && preg_replace('/\D/', '', (string)$registro['numero']) === $numero) throw new RuntimeException('Este número já está cadastrado.');
            $linha['numero'] = $numero;
            $linha['tipo'] = $tipo;
            $linha['observacoes'] = trim((string)($_POST['observacoes'] ?? ''));
            if (strlen($linha['observacoes']) > 10000) throw new RuntimeException('As observações excedem o limite permitido.');
        } elseif ($acao === 'alocar') {
            if ($linha['status'] !== 'disponivel' || !empty($linha['colaborador_id'])) throw new RuntimeException('Somente linhas disponíveis podem ser alocadas.');
            $idPessoa = (string)($_POST['colaborador_id'] ?? '');
            if (!isset($ativos[$idPessoa])) throw new RuntimeException('Selecione um colaborador ativo.');
            $centro = trim((string)($ativos[$idPessoa]['centro_custo'] ?? ''));
            if ($centro === '') throw new RuntimeException('O colaborador não possui centro de custo cadastrado.');
            $linha['colaborador_id'] = $ativos[$idPessoa]['id'];
            $linha['colaborador_nome'] = $ativos[$idPessoa]['nome'];
            $linha['centro_custo'] = $centro;
            $linha['status'] = 'alocado';
            $linha['data_atribuicao'] = date('Y-m-d H:i:s');
        } elseif (in_array($acao, ['desvincular', 'bloquear', 'desbloquear'], true)) {
            if ($acao === 'desvincular' && empty($linha['colaborador_id'])) throw new RuntimeException('Esta linha não possui colaborador vinculado.');
            if ($acao === 'bloquear' && $linha['status'] === 'whatsapp_bloqueado') throw new RuntimeException('Esta linha já está bloqueada.');
            if ($acao === 'desbloquear' && $linha['status'] !== 'whatsapp_bloqueado') throw new RuntimeException('Esta linha não está bloqueada.');
            $linha['status'] = $acao === 'bloquear' ? 'whatsapp_bloqueado' : 'disponivel';
            $linha['colaborador_id'] = null;
            $linha['colaborador_nome'] = null;
            $linha['centro_custo'] = '11001';
            $linha['data_atribuicao'] = null;
        } else { throw new RuntimeException('Ação inválida.'); }
        $agora = date('Y-m-d H:i:s');
        $linha['data_atualizacao'] = $agora;
        $linha['historico'] = is_array($linha['historico'] ?? null) ? $linha['historico'] : [];
        $linha['historico'][] = ['data' => $agora, 'acao' => $acao, 'usuario' => $_SESSION['usuario_nome'] ?? 'Usuário', 'status_anterior' => $statusAnterior, 'status_novo' => $linha['status'], 'colaborador_anterior' => $colaboradorAnterior, 'colaborador_novo' => $linha['colaborador_id'], 'centro_custo_anterior' => $centroAnterior, 'centro_custo_novo' => $linha['centro_custo']];
        if ($centroAnterior !== (string)$linha['centro_custo']) {
            $linha['historico_centro_custo'] = is_array($linha['historico_centro_custo'] ?? null) ? $linha['historico_centro_custo'] : [];
            $linha['historico_centro_custo'][] = ['data' => $agora, 'usuario' => $_SESSION['usuario_nome'] ?? 'Usuário', 'centro_custo_anterior' => $centroAnterior, 'centro_custo_novo' => $linha['centro_custo'], 'motivo' => 'Atualização automática: ' . $acao];
        }
        if ($indice === null) $linhas[] = $linha; else $linhas[$indice] = $linha;
        $json = json_encode($linhas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($arquivo, $json, LOCK_EX) !== strlen($json)) {
            file_put_contents($arquivo, json_encode($originais, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            throw new RuntimeException('Não foi possível salvar a linha.');
        }
        $_SESSION['linhas_mensagem'] = 'Linha ' . ['adicionar' => 'adicionada', 'editar' => 'atualizada', 'alocar' => 'alocada', 'desvincular' => 'desvinculada', 'bloquear' => 'bloqueada', 'desbloquear' => 'desbloqueada'][$acao] . ' com sucesso.';
        flock($lock, LOCK_UN); fclose($lock);
        header('Location: linhas.php'); exit;
    } catch (Throwable $e) {
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível salvar os dados da linha.';
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        $linhas = $originais ?? $linhas;
    }
}
$filtros = [];
foreach (['tipo', 'status', 'numero', 'colaborador_id'] as $campo) $filtros[$campo] = trim((string)($_GET[$campo] ?? ''));
$exibidas = array_values(array_filter($linhas, function ($linha) use ($filtros) {
    foreach ($filtros as $campo => $valor) {
        if ($valor === '') continue;
        if ($campo === 'numero') { $busca = preg_replace('/\D/', '', $valor); if ($busca === '' || strpos(preg_replace('/\D/', '', (string)$linha['numero']), $busca) === false) return false; }
        elseif ((string)($linha[$campo] ?? '') !== $valor) return false;
    }
    return true;
}));
usort($exibidas, fn($a, $b) => strnatcasecmp((string)$a['numero'], (string)$b['numero']));
$acaoForm = (string)($_GET['acao'] ?? '');
$selecionada = null;
foreach ($linhas as $linha) if ((string)$linha['id'] === (string)($_GET['id'] ?? '')) { $selecionada = $linha; break; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $erro) { $acaoForm = (string)($_POST['acao'] ?? ''); $selecionada = $_POST; }
$mensagem = $_SESSION['linhas_mensagem'] ?? ''; unset($_SESSION['linhas_mensagem']);
?>
<!doctype html><html lang="pt-br"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Linhas - Orion Inventory</title>
<link rel="stylesheet" href="../../assets/css/global/import.css"><link rel="stylesheet" href="../../assets/css/pages/dashboard.css"><link rel="stylesheet" href="../../assets/css/pages/equipamentos.css"><link rel="stylesheet" href="../../assets/css/pages/linhas.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><script src="../../assets/js/equipamentos.js" defer></script>
</head><body>
<header>
<nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul>
<li class="item-menu"><a href="../dashbord/dashbord.php"><span class="item"><i class="bi bi-columns-gap"></i></span><span class="txt-link">Dashboard</span></a></li>
<li class="item-menu"><a href="../colaboradores/colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li>
<li class="item-menu"><a href="../equipamentos/equipamentos.php"><span class="item"><i class="bi bi-pc-display-horizontal"></i></span><span class="txt-link">Equipamentos</span></a></li>
<li class="item-menu active"><a href="linhas.php" aria-current="page"><span class="item"><i class="bi bi-sd-card"></i></span><span class="txt-link">Linhas</span></a></li>
<li class="item-menu"><a href="../termos/termos.php"><span class="item"><i class="bi bi-file-earmark-pdf"></i></span><span class="txt-link">Termos</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-tools"></i></span><span class="txt-link">Manutenção</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-person-circle"></i></span><span class="txt-link">Usuários</span></a></li>
</ul></nav>
<div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div>
<div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div>
<a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a>
</header>
<main class="equipment-page lines-page">
<div class="equipment-heading"><div><h1><i class="fas fa-sim-card" aria-hidden="true"></i> Linhas</h1><p>Gerencie as linhas telefônicas e seus colaboradores.</p></div><a class="equipment-primary" href="?acao=adicionar"><i class="fas fa-plus" aria-hidden="true"></i> Adicionar linha</a></div>
<?php if ($erro): ?><p class="equipment-notice equipment-error" role="alert"><?= h($erro) ?></p><?php endif; ?><?php if ($mensagem): ?><p class="equipment-notice" role="status"><?= h($mensagem) ?></p><?php endif; ?>
<?php if (in_array($acaoForm, ['adicionar', 'editar', 'alocar'], true) && ($acaoForm === 'adicionar' || $selecionada)): $registro = $selecionada ?? []; ?>
<section class="equipment-form-panel"><h2><?= ['adicionar' => 'Adicionar linha', 'editar' => 'Editar linha', 'alocar' => 'Alocar linha'][$acaoForm] ?></h2><form method="post" class="equipment-form"><input type="hidden" name="csrf" value="<?= h($_SESSION['linhas_csrf']) ?>"><input type="hidden" name="acao" value="<?= h($acaoForm) ?>"><input type="hidden" name="id" value="<?= h($registro['id'] ?? '') ?>">
<?php if ($acaoForm === 'alocar'): ?><p class="equipment-form-wide">Linha <?= h(numeroFormatado($registro['numero'] ?? '')) ?></p><label class="equipment-form-wide equipment-collaborator-field" data-collaborator-combobox>Colaborador<select name="colaborador_id" required><option value="">Selecione um colaborador</option><?php uasort($ativos, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($ativos as $pessoa): ?><option value="<?= h($pessoa['id']) ?>" <?= (string)($registro['colaborador_id'] ?? '') === (string)$pessoa['id'] ? 'selected' : '' ?>><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label>
<?php else: ?><label>Número da linha<input type="tel" name="numero" value="<?= h($registro['numero'] ?? '') ?>" maxlength="25" placeholder="(16) 99999-9999" required></label><label>Tipo<select name="tipo" class="equipment-type-select" required><?php foreach ($tipos as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= ($registro['tipo'] ?? 'chip') === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><label>Centro de custo<input value="<?= h($registro['centro_custo'] ?? '11001') ?>" readonly></label><label class="equipment-form-wide">Observações<textarea name="observacoes" rows="3" maxlength="10000"><?= h($registro['observacoes'] ?? '') ?></textarea></label><?php if ($acaoForm === 'adicionar'): ?><p class="equipment-form-wide lines-hint">A nova linha ficará disponível para alocação.</p><?php endif; ?><?php endif; ?>
<div class="equipment-form-wide equipment-form-actions"><button class="equipment-primary" type="submit"><?= $acaoForm === 'adicionar' ? 'Adicionar' : ($acaoForm === 'alocar' ? 'Alocar' : 'Salvar') ?></button><a class="equipment-secondary" href="linhas.php">Cancelar</a></div></form></section><?php endif; ?>
<section class="equipment-filters" aria-label="Filtros de linhas"><h2><i class="fas fa-filter" aria-hidden="true"></i> Filtros</h2><form method="get"><label>Tipo<select name="tipo"><option value="">Todos os tipos</option><?php foreach ($tipos as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= $filtros['tipo'] === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><label>Status<select name="status"><option value="">Todos os status</option><?php foreach ($statusNomes as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= $filtros['status'] === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><label>Número<input type="search" name="numero" value="<?= h($filtros['numero']) ?>" placeholder="Buscar número com DDD"></label>
<label class="equipment-collaborator-field" data-collaborator-combobox data-collaborator-filter>Colaborador<select name="colaborador_id"><option value="">Todos os colaboradores</option><?php $pessoasFiltro = $pessoas; foreach ($linhas as $linha) { $idPessoa = (string)($linha['colaborador_id'] ?? ''); if ($idPessoa !== '' && !isset($pessoasFiltro[$idPessoa])) $pessoasFiltro[$idPessoa] = ['id' => $idPessoa, 'nome' => $linha['colaborador_nome'] ?? 'Colaborador #' . $idPessoa]; } uasort($pessoasFiltro, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($pessoasFiltro as $pessoa): ?><option value="<?= h($pessoa['id']) ?>" <?= $filtros['colaborador_id'] === (string)$pessoa['id'] ? 'selected' : '' ?>><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label><button class="equipment-primary" type="submit">Filtrar</button><a class="equipment-secondary" href="linhas.php">Limpar</a></form></section>
<section class="equipment-list" aria-label="Lista de linhas"><div class="equipment-list-heading"><h2>Lista de linhas</h2><span><?= count($exibidas) ?> de <?= count($linhas) ?> linhas</span></div><?php if (!$exibidas): ?><div class="equipment-empty"><i class="fas fa-sim-card" aria-hidden="true"></i><p>Nenhuma linha encontrada.</p></div><?php endif; ?>
<?php foreach ($exibidas as $linha): $status = $linha['status'] ?? ''; $idPessoa = (string)($linha['colaborador_id'] ?? ''); $nomePessoa = $pessoas[$idPessoa]['nome'] ?? $linha['colaborador_nome'] ?? ($idPessoa !== '' ? 'Colaborador #' . $idPessoa : 'Sem alocação'); ?>
<article class="equipment-row line-row"><div class="equipment-icon"><i class="fas fa-<?= ($linha['tipo'] ?? '') === 'echip' ? 'mobile-screen' : 'sim-card' ?>" aria-hidden="true"></i><span><?= h($tipos[$linha['tipo'] ?? ''] ?? $linha['tipo'] ?? 'Linha') ?></span></div><div class="equipment-field"><span>Número</span><strong><?= h(numeroFormatado($linha['numero'])) ?></strong><small><?= h($tipos[$linha['tipo'] ?? ''] ?? $linha['tipo'] ?? '') ?></small></div><div class="equipment-field"><span>Centro de custo</span><strong><?= h($linha['centro_custo'] ?? '—') ?></strong></div><div class="equipment-field equipment-allocation"><span class="equipment-status line-status-<?= h(isset($statusNomes[$status]) ? $status : 'outro') ?>"><?= h($statusNomes[$status] ?? $status) ?></span><strong><?= h($nomePessoa) ?></strong></div><div class="equipment-actions"><a class="equipment-action" href="?acao=editar&amp;id=<?= h($linha['id']) ?>"><i class="fas fa-pen" aria-hidden="true"></i> Editar</a>
<?php if ($status === 'disponivel' && $idPessoa === ''): ?><a class="equipment-action" href="?acao=alocar&amp;id=<?= h($linha['id']) ?>"><i class="fas fa-user-plus" aria-hidden="true"></i> Alocar</a><?php endif; ?>
<?php $acoesLinha = []; if ($idPessoa !== '') $acoesLinha['desvincular'] = ['Desvincular', 'link-slash', 'Desvincular esta linha e devolver ao centro de custo 11001?']; if ($status === 'whatsapp_bloqueado') $acoesLinha['desbloquear'] = ['Desbloquear', 'unlock', 'Desbloquear esta linha e torná-la disponível?']; else $acoesLinha['bloquear'] = ['Bloquear', 'ban', 'Marcar esta linha como WhatsApp bloqueado e remover o vínculo com o colaborador?']; foreach ($acoesLinha as $acao => [$rotulo, $icone, $confirmacao]): ?><form method="post" data-confirm="<?= h($confirmacao) ?>"><input type="hidden" name="csrf" value="<?= h($_SESSION['linhas_csrf']) ?>"><input type="hidden" name="acao" value="<?= h($acao) ?>"><input type="hidden" name="id" value="<?= h($linha['id']) ?>"><button class="equipment-action equipment-unlink" type="submit"><i class="fas fa-<?= $icone ?>" aria-hidden="true"></i> <?= $rotulo ?></button></form><?php endforeach; ?></div></article><?php endforeach; ?></section>
</main><footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer></body></html>
