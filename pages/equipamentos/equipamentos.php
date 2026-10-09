<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/auditoria.php';
orionRegistrarAtividade();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
date_default_timezone_set('America/Sao_Paulo');
$base = dirname(__DIR__, 2);
$marcasPorTipo = [
    'desktop' => ['Dell', 'Lenovo', 'Asus', 'Samsung'],
    'notebook' => ['Dell', 'Lenovo', 'Asus', 'Samsung'],
    'celular' => ['Samsung', 'Motorola', 'LG'],
    'suporte' => ['Fussem'],
    'tv' => ['Samsung', 'LG'],
    'monitor' => ['Samsung', 'Dell', 'AOC', 'LG', 'Philips'],
    'teclado' => ['Dell', 'Logitech', 'Philips'],
    'mouse' => ['Dell', 'Logitech', 'Philips'],
    'fone' => ['Jabra', 'Logitech', 'Gamenot'],
];
$modelosFone = ['Jabra' => ['HSC015', 'HSC016'], 'Logitech' => ['H390'], 'Gamenot' => ['FUXI-H3']];
$marcasGerais = array_values(array_filter(array_map('trim', file($base . '/marca/marcar.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
$fontes = ['alocado' => 'alocados', 'emprestado' => 'emprestados', 'estoque' => 'estoque', 'fora_uso' => 'fora_uso', 'interno' => 'internos', 'manutencao' => 'manutencao'];
$statusNomes = ['alocado' => 'Alocado', 'emprestado' => 'Emprestado', 'estoque' => 'Em estoque', 'fora_uso' => 'Fora de uso', 'interno' => 'Uso interno', 'manutencao' => 'Em manutenção', 'pendente_devolucao' => 'Devolução pendente'];
$icones = ['notebook' => 'laptop', 'desktop' => 'desktop', 'monitor' => 'display', 'fone' => 'headphones', 'mouse' => 'computer-mouse', 'teclado' => 'keyboard', 'tv' => 'tv', 'celular' => 'mobile-screen'];
function h($valor): string { return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); }
function lerLista(string $arquivo): array {
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) throw new RuntimeException('Não foi possível ler os dados.');
    $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($dados) || array_values($dados) !== $dados) throw new RuntimeException('Formato de dados inválido.');
    return $dados;
}
function carregarEquipamentos(string $base, array $fontes): array {
    $listas = [];
    foreach ($fontes as $nome) $listas[$nome] = lerLista($base . '/data/equipamentos/' . $nome . '.json');
    return $listas;
}
$_SESSION['equipamentos_csrf'] ??= bin2hex(random_bytes(32));
$erro = '';
$listas = [];
$pessoas = [];
$ativos = [];
try {
    foreach (['ativos', 'inativos', 'terceiros'] as $grupo) {
        foreach (lerLista($base . '/data/colaboradores/' . $grupo . '.json') as $pessoa) {
            $pessoas[(string)$pessoa['id']] = $pessoa;
            if ($grupo !== 'inativos') $ativos[(string)$pessoa['id']] = $pessoa;
        }
    }
    $listas = carregarEquipamentos($base, $fontes);
} catch (Throwable $e) { $erro = 'Não foi possível carregar os arquivos de dados. Verifique os JSON da pasta /data.'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro) {
    $lock = null;
    try {
        if (!hash_equals($_SESSION['equipamentos_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão inválida. Atualize a página e tente novamente.');
        $lock = fopen($base . '/data/equipamentos/.equipamentos.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível acessar os dados para gravação.');
        $listas = carregarEquipamentos($base, $fontes);
        $originais = $listas;
        $acao = (string)($_POST['acao'] ?? '');
        $origem = (string)($_POST['origem'] ?? '');
        $id = (string)($_POST['id'] ?? '');
        $indice = null;
        if ($acao !== 'adicionar') {
            if (!in_array($origem, $fontes, true)) throw new RuntimeException('Origem inválida.');
            foreach ($listas[$origem] as $i => $item) if ((string)($item['id'] ?? '') === $id) { $indice = $i; break; }
            if ($indice === null) throw new RuntimeException('Equipamento não encontrado. Atualize a página.');
            $item = $listas[$origem][$indice];
        } else {
            $maiorId = 0;
            foreach ($listas as $lista) foreach ($lista as $registro) $maiorId = max($maiorId, (int)($registro['id'] ?? 0));
            $item = ['id' => $maiorId + 1, 'status' => 'estoque', 'centro_custo' => '11001', 'colaborador_id' => null, 'especificacoes' => null, 'data_cadastro' => date('Y-m-d H:i:s'), 'data_atribuicao' => null, 'tipo_atribuicao' => null];
        }
        $destino = $origem;
        if (in_array($acao, ['adicionar', 'editar'], true)) {
            foreach (['tipo', 'marca', 'modelo', 'patrimonio', 'serial', 'observacoes'] as $campo) {
                $valor = trim((string)($_POST[$campo] ?? ''));
                if (strlen($valor) > 5000) throw new RuntimeException('Um dos campos excede o limite permitido.');
                $item[$campo] = $valor === '' ? null : $valor;
            }
            $item['tipo'] = strtolower($item['tipo']);
            if (in_array($acao, ['adicionar', 'editar'], true)) {
                if ($item['tipo'] === 'suporte') { $item['marca'] = 'Fussem'; $item['modelo'] = 'Alumínio'; }
                $marcasPermitidas = $marcasPorTipo[$item['tipo']] ?? $marcasGerais;
                $marcaValida = null;
                foreach ($marcasPermitidas as $marcaPermitida) if (strcasecmp($marcaPermitida, (string)$item['marca']) === 0) { $marcaValida = $marcaPermitida; break; }
                if ($marcaValida === null) throw new RuntimeException('Selecione uma marca permitida para este tipo de equipamento.');
                $item['marca'] = $marcaValida;
                if ($item['tipo'] === 'fone') {
                    $modeloValido = null;
                    foreach ($modelosFone[$item['marca']] as $modeloPermitido) if (strcasecmp($modeloPermitido, (string)$item['modelo']) === 0) { $modeloValido = $modeloPermitido; break; }
                    if ($modeloValido === null) throw new RuntimeException('Selecione um modelo de fone permitido para esta marca.');
                    $item['modelo'] = $modeloValido;
                }
            }
            foreach (['tipo', 'marca', 'modelo', 'patrimonio'] as $campo) if (empty($item[$campo])) throw new RuntimeException('Preencha tipo, marca, modelo e patrimônio.');
            if (in_array($item['tipo'], ['notebook', 'desktop'], true)) {
                $item['hostname'] = trim((string)($_POST['hostname'] ?? '')) ?: null;
                $especificacoes = is_array($item['especificacoes'] ?? null) ? $item['especificacoes'] : [];
                $enviadas = is_array($_POST['especificacoes'] ?? null) ? $_POST['especificacoes'] : [];
                $sistemaEnviado = trim((string)($enviadas['sistema_operacional'] ?? ''));
                $sistemaAtual = (string)($especificacoes['sistema_operacional'] ?? $item['sistema_operacional'] ?? '');
                if ($sistemaEnviado !== '' && !in_array($sistemaEnviado, ['Windows 11', 'Windows 10', 'Ubuntu'], true) && ($acao !== 'editar' || $sistemaEnviado !== $sistemaAtual)) throw new RuntimeException('Selecione Windows 11, Windows 10 ou Ubuntu.');
                foreach (['sistema_operacional', 'ram', 'processador'] as $campo) {
                    $valor = trim((string)($enviadas[$campo] ?? ''));
                    if (strlen($valor) > 255) throw new RuntimeException('Uma especificação excede o limite permitido.');
                    $especificacoes[$campo] = $valor === '' ? null : $valor;
                }
                foreach (['bit_instalado', 'milvus_instalado'] as $campo) $especificacoes[$campo] = ($enviadas[$campo] ?? '') === '1';
                if (strlen((string)$item['hostname']) > 255) throw new RuntimeException('O hostname excede o limite permitido.');
                $item['especificacoes'] = $especificacoes;
            }
            foreach ($listas as $nome => $lista) foreach ($lista as $i => $registro) {
                if ($acao === 'editar' && $nome === $origem && $i === $indice) continue;
                if (!empty($item['hostname']) && strcasecmp(trim((string)($registro['hostname'] ?? '')), $item['hostname']) === 0) throw new RuntimeException('Este hostname já está em uso. Escolha outro hostname ou informe um nome personalizado disponível.');
                if (strcasecmp((string)($registro['patrimonio'] ?? ''), $item['patrimonio']) === 0) throw new RuntimeException('Este patrimônio já está cadastrado.');
                if ($item['serial'] && strcasecmp((string)($registro['serial'] ?? ''), $item['serial']) === 0) throw new RuntimeException('Este serial já está cadastrado.');
            }
            {
                $statusAnterior = (string)($item['status'] ?? 'estoque');
                $statusCadastro = (string)($_POST['status'] ?? $statusAnterior);
                $emManutencao = $acao === 'editar' && ($statusAnterior === 'manutencao' || $origem === 'manutencao');
                if ($emManutencao && $statusCadastro !== $statusAnterior) throw new RuntimeException('O status de equipamentos em manutenção só pode ser alterado na área de manutenção.');
                $permitidos = $acao === 'adicionar' ? ['alocado', 'fora_uso', 'estoque', 'interno'] : ['alocado', 'emprestado', 'fora_uso', 'estoque', 'interno'];
                if (!$emManutencao && !in_array($statusCadastro, $permitidos, true) && !($acao === 'editar' && $statusCadastro === $statusAnterior && $statusAnterior === 'pendente_devolucao')) throw new RuntimeException('Selecione um status permitido. Manutenção é gerida em outra área.');
                $item['status'] = $statusCadastro;
                $destino = $fontes[$statusCadastro] ?? $origem;
                $colaboradorId = (string)($_POST['colaborador_id'] ?? $item['colaborador_id'] ?? '');
                $novoVinculo = $acao === 'adicionar' || $statusCadastro !== $statusAnterior || $colaboradorId !== (string)($item['colaborador_id'] ?? '');
                if (in_array($statusCadastro, ['alocado', 'emprestado'], true) && $novoVinculo) {
                    if (!isset($ativos[$colaboradorId])) throw new RuntimeException('Selecione um colaborador ativo para vincular o equipamento.');
                    $centroCusto = trim((string)($ativos[$colaboradorId]['centro_custo'] ?? ''));
                    if ($centroCusto === '') throw new RuntimeException('O colaborador selecionado não possui centro de custo cadastrado.');
                    $item['colaborador_id'] = $ativos[$colaboradorId]['id'];
                    $item['colaborador_nome'] = $ativos[$colaboradorId]['nome'];
                    $item['centro_custo'] = $centroCusto;
                    $item['data_atribuicao'] = date('Y-m-d H:i:s');
                    $item['tipo_atribuicao'] = $statusCadastro === 'emprestado' ? 'emprestimo' : 'alocacao';
                } elseif ($acao === 'editar' && $statusCadastro !== $statusAnterior && in_array($statusCadastro, ['estoque', 'fora_uso', 'interno'], true)) {
                    $item['colaborador_id'] = null;
                    $item['colaborador_nome'] = null;
                    $item['centro_custo'] = '11001';
                    $item['data_atribuicao'] = null;
                    $item['tipo_atribuicao'] = null;
                }
            }
        } elseif ($acao === 'alocar') {
            if (($item['status'] ?? '') !== 'estoque' || !empty($item['colaborador_id'])) throw new RuntimeException('Somente equipamentos disponíveis em estoque podem ser alocados.');
            $colaboradorId = (string)($_POST['colaborador_id'] ?? '');
            if (!isset($ativos[$colaboradorId])) throw new RuntimeException('Selecione um colaborador ativo.');
            $centroCusto = trim((string)($ativos[$colaboradorId]['centro_custo'] ?? ''));
            if ($centroCusto === '') throw new RuntimeException('O colaborador selecionado não possui centro de custo cadastrado.');
            $item['colaborador_id'] = $ativos[$colaboradorId]['id'];
            $item['colaborador_nome'] = $ativos[$colaboradorId]['nome'];
            $item['centro_custo'] = $centroCusto;
            $item['status'] = 'alocado';
            $item['data_atribuicao'] = date('Y-m-d H:i:s');
            $item['tipo_atribuicao'] = 'alocacao';
            $destino = 'alocados';
        } elseif ($acao === 'desvincular') {
            if (!in_array($item['status'] ?? '', ['alocado', 'emprestado'], true) || empty($item['colaborador_id'])) throw new RuntimeException('Este equipamento não possui vínculo disponível para remoção.');
            $item['observacoes'] = trim(($item['observacoes'] ?? '') . "\n[DEVOLUÇÃO] " . date('d/m/Y H:i:s') . ' — Colaborador: ' . ($pessoas[(string)$item['colaborador_id']]['nome'] ?? $item['colaborador_nome'] ?? $item['colaborador_id']) . ' — Destino: Em estoque');
            $item['colaborador_id'] = null;
            $item['colaborador_nome'] = null;
            $item['status'] = 'estoque';
            $item['centro_custo'] = '11001';
            $item['data_atribuicao'] = null;
            $item['tipo_atribuicao'] = null;
            $destino = 'estoque';
        } else { throw new RuntimeException('Ação inválida.'); }
        $item['data_atualizacao'] = date('Y-m-d H:i:s');
        if ($indice !== null) array_splice($listas[$origem], $indice, 1);
        $listas[$destino][] = $item;
        $gravados = [];
        try {
            foreach ($listas as $nome => $lista) {
                if ($lista === $originais[$nome]) continue;
                $arquivo = $base . '/data/equipamentos/' . $nome . '.json';
                $json = json_encode($lista, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $gravados[] = $nome;
                if (file_put_contents($arquivo, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Não foi possível salvar os dados.');
            }
        } catch (Throwable $e) {
            foreach ($gravados as $nome) file_put_contents($base . '/data/equipamentos/' . $nome . '.json', json_encode($originais[$nome], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            throw $e;
        }
        orionRegistrarMovimentacao($acao, 'equipamento', $indice !== null ? $originais[$origem][$indice] : null, $item);
        $_SESSION['equipamentos_mensagem'] = 'Equipamento ' . ['adicionar' => 'adicionado', 'editar' => 'atualizado', 'alocar' => 'alocado', 'desvincular' => 'desvinculado'][$acao] . ' com sucesso.';
        flock($lock, LOCK_UN); fclose($lock);
        header('Location: equipamentos.php'); exit;
    } catch (Throwable $e) {
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível salvar o equipamento.';
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        $listas = $originais ?? $listas;
    }
}
$equipamentos = [];
foreach ($listas as $origem => $lista) foreach ($lista as $item) { $item['_origem'] = $origem; $equipamentos[] = $item; }
$hostnamesUsados = [];
foreach ($equipamentos as $item) {
    $hostname = strtolower(trim((string)($item['hostname'] ?? '')));
    if ($hostname !== '') $hostnamesUsados[$hostname] = true;
}
$hostnamesDisponiveis = [];
for ($numero = 999; $numero >= 1; $numero--) {
    $hostname = 'NT-AS-' . str_pad((string)$numero, 3, '0', STR_PAD_LEFT);
    if (!isset($hostnamesUsados[strtolower($hostname)])) $hostnamesDisponiveis[] = $hostname;
}
$tipos = array_values(array_unique(array_filter(array_column($equipamentos, 'tipo')))); sort($tipos);
$filtros = [];
foreach (['tipo', 'status', 'patrimonio', 'serial', 'colaborador_id'] as $campo) $filtros[$campo] = trim((string)($_GET[$campo] ?? ''));
$exibidos = array_values(array_filter($equipamentos, function ($item) use ($filtros) {
    foreach ($filtros as $campo => $valor) {
        if ($valor === '') continue;
        if (in_array($campo, ['tipo', 'status', 'colaborador_id'], true)) { if ((string)($item[$campo] ?? '') !== $valor) return false; }
        elseif (stripos((string)($item[$campo] ?? ''), $valor) === false) return false;
    }
    return true;
}));
usort($exibidos, fn($a, $b) => strnatcasecmp((string)($a['patrimonio'] ?? ''), (string)($b['patrimonio'] ?? '')));
$acaoForm = (string)($_GET['acao'] ?? '');
$selecionado = null;
foreach ($equipamentos as $item) if ((string)($item['id'] ?? '') === (string)($_GET['id'] ?? '') && $item['_origem'] === ($_GET['origem'] ?? '')) { $selecionado = $item; break; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $erro) { $acaoForm = (string)($_POST['acao'] ?? ''); $selecionado = $_POST; }
$mensagem = $_SESSION['equipamentos_mensagem'] ?? ''; unset($_SESSION['equipamentos_mensagem']);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Equipamentos - Orion Inventory</title>
<link rel="stylesheet" href="../../assets/css/global/import.css">
<link rel="stylesheet" href="../../assets/css/pages/dashboard.css">
<link rel="stylesheet" href="../../assets/css/pages/equipamentos.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="../../assets/js/equipamentos.js" defer></script>
</head>
<body>
<header>
<nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul>
<li class="item-menu"><a href="../dashbord/dashbord.php"><span class="item"><i class="bi bi-columns-gap"></i></span><span class="txt-link">Dashboard</span></a></li>
<li class="item-menu"><a href="../colaboradores/colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li>
<li class="item-menu active"><a href="equipamentos.php" aria-current="page"><span class="item"><i class="bi bi-pc-display-horizontal"></i></span><span class="txt-link">Equipamentos</span></a></li>
<li class="item-menu"><a href="../linhas/linhas.php"><span class="item"><i class="bi bi-sd-card"></i></span><span class="txt-link">Linhas</span></a></li>
<li class="item-menu"><a href="../termos/termos.php"><span class="item"><i class="bi bi-file-earmark-pdf"></i></span><span class="txt-link">Termos</span></a></li>
<li class="item-menu"><a href="../manutencao/manutencao.php"><span class="item"><i class="bi bi-tools"></i></span><span class="txt-link">Manutenção</span></a></li>
<li class="item-menu"><a href="../chamado/chamado.php"><span class="item"><i class="bi bi-headset"></i></span><span class="txt-link">Chamado</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-person-circle"></i></span><span class="txt-link">Usuários</span></a></li>
</ul></nav>
<div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div>
<div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div>
<a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a>
</header>
<main class="equipment-page">
<div class="equipment-heading"><div><h1><i class="fas fa-laptop" aria-hidden="true"></i> Equipamentos</h1><p>Gerencie o inventário e a alocação dos equipamentos.</p></div><a class="equipment-primary" href="?acao=adicionar"><i class="fas fa-plus" aria-hidden="true"></i> Adicionar equipamento</a></div>
<?php if ($erro): ?><p class="equipment-notice equipment-error" role="alert"><?= h($erro) ?></p><?php endif; ?>
<?php if ($mensagem): ?><p class="equipment-notice" role="status"><?= h($mensagem) ?></p><?php endif; ?>
<?php if (in_array($acaoForm, ['adicionar', 'editar', 'alocar'], true) && ($acaoForm === 'adicionar' || $selecionado)): $registro = $selecionado ?? []; ?>
<section class="equipment-form-panel"><h2><?= ['adicionar' => 'Adicionar equipamento', 'editar' => 'Editar equipamento', 'alocar' => 'Alocar equipamento'][$acaoForm] ?></h2>
<form method="post" class="equipment-form">
<input type="hidden" name="csrf" value="<?= h($_SESSION['equipamentos_csrf']) ?>"><input type="hidden" name="acao" value="<?= h($acaoForm) ?>"><input type="hidden" name="id" value="<?= h($registro['id'] ?? '') ?>"><input type="hidden" name="origem" value="<?= h($registro['_origem'] ?? $registro['origem'] ?? '') ?>">
<?php if ($acaoForm === 'alocar'): ?>
<p class="equipment-form-wide"><?= h(($registro['marca'] ?? '') . ' ' . ($registro['modelo'] ?? '') . ' · Patrimônio ' . ($registro['patrimonio'] ?? '')) ?></p>
<label class="equipment-form-wide equipment-collaborator-field" data-collaborator-combobox>Colaborador<select name="colaborador_id" required><option value="">Selecione um colaborador</option><?php uasort($ativos, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($ativos as $pessoa): ?><option value="<?= h($pessoa['id']) ?>"><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label>
<?php else: ?>
<?php $statusForm = (string)($registro['status'] ?? 'estoque'); $origemForm = $registro['_origem'] ?? $registro['origem'] ?? ''; $statusBloqueado = $acaoForm === 'editar' && ($statusForm === 'manutencao' || $origemForm === 'manutencao'); $opcoesStatus = ['estoque' => 'Inventário', 'alocado' => 'Alocado', 'fora_uso' => 'Fora de uso', 'interno' => 'Interno']; if ($acaoForm === 'editar') $opcoesStatus['emprestado'] = 'Emprestado'; if ($statusBloqueado || $statusForm === 'pendente_devolucao') $opcoesStatus[$statusForm] = $statusNomes[$statusForm] ?? $statusForm; $formVinculado = !$statusBloqueado && in_array($statusForm, ['alocado', 'emprestado'], true); ?>
<label>Status<select name="status" class="equipment-type-select" data-equipment-status required <?= $statusBloqueado ? 'disabled' : '' ?>><?php foreach ($opcoesStatus as $valor => $rotulo): ?><option value="<?= h($valor) ?>" <?= $statusForm === $valor ? 'selected' : '' ?>><?= h($rotulo) ?></option><?php endforeach; ?></select><?php if ($statusBloqueado): ?><small>O status é gerido na área de manutenção.</small><?php endif; ?></label>
<label class="equipment-collaborator-field" data-collaborator-combobox data-status-collaborator <?= !$formVinculado ? 'hidden' : '' ?>>Colaborador<select name="colaborador_id" <?= $formVinculado ? 'required' : 'disabled' ?>><option value="">Selecione um colaborador</option><?php $pessoasFormulario = $ativos; $idAtual = (string)($registro['colaborador_id'] ?? ''); if ($idAtual !== '' && !isset($pessoasFormulario[$idAtual])) $pessoasFormulario[$idAtual] = $pessoas[$idAtual] ?? ['id' => $idAtual, 'nome' => $registro['colaborador_nome'] ?? 'Colaborador #' . $idAtual]; uasort($pessoasFormulario, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($pessoasFormulario as $pessoa): ?><option value="<?= h($pessoa['id']) ?>" <?= $idAtual === (string)$pessoa['id'] ? 'selected' : '' ?>><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label>
<label>Tipo<select name="tipo" class="equipment-type-select" data-equipment-type required><option value="">Selecione o tipo</option><?php $tiposFormulario = array_values(array_unique(array_merge(['notebook', 'desktop', 'monitor', 'fone', 'mouse', 'teclado', 'tv', 'celular', 'suporte'], $tipos, array_filter([$registro['tipo'] ?? ''])))); sort($tiposFormulario); foreach ($tiposFormulario as $tipo): ?><option value="<?= h($tipo) ?>" <?= ($registro['tipo'] ?? '') === $tipo ? 'selected' : '' ?>><?= h($tipo === 'tv' ? 'TV' : ucfirst($tipo)) ?></option><?php endforeach; ?></select></label>
<label>Marca<select name="marca" class="equipment-type-select" data-equipment-brand data-headset-models="<?= h(json_encode($modelosFone, JSON_UNESCAPED_UNICODE)) ?>" data-brands="<?= h(json_encode($marcasPorTipo, JSON_UNESCAPED_UNICODE)) ?>" data-default-brands="<?= h(json_encode($marcasGerais, JSON_UNESCAPED_UNICODE)) ?>" required><option value="">Selecione a marca</option><?php foreach (($marcasPorTipo[$registro['tipo'] ?? ''] ?? $marcasGerais) as $marca): ?><option value="<?= h($marca) ?>" <?= strcasecmp($marca, (string)($registro['marca'] ?? '')) === 0 ? 'selected' : '' ?>><?= h($marca) ?></option><?php endforeach; ?></select></label>
<?php foreach (['modelo' => 'Modelo', 'patrimonio' => 'Patrimônio', 'serial' => 'Serial'] as $campo => $rotulo): ?><label><?= $rotulo ?><input name="<?= $campo ?>" value="<?= h(($registro['tipo'] ?? '') === 'suporte' && $campo === 'modelo' ? 'Alumínio' : ($registro[$campo] ?? '')) ?>" maxlength="255" <?= in_array($campo, ['modelo', 'patrimonio'], true) ? 'required' : '' ?> <?= ($registro['tipo'] ?? '') === 'suporte' && $campo === 'modelo' ? 'readonly' : '' ?> <?= ($registro['tipo'] ?? '') === 'fone' && $campo === 'modelo' ? 'hidden disabled' : '' ?>><?php if ($campo === 'modelo'): ?><select name="modelo" class="equipment-type-select" data-headset-model <?= ($registro['tipo'] ?? '') === 'fone' ? 'required' : 'hidden disabled' ?>><option value="">Selecione o modelo</option><?php foreach (($modelosFone[$registro['marca'] ?? ''] ?? []) as $modeloFone): ?><option value="<?= h($modeloFone) ?>" <?= strcasecmp($modeloFone, (string)($registro['modelo'] ?? '')) === 0 ? 'selected' : '' ?>><?= h($modeloFone) ?></option><?php endforeach; ?></select><?php endif; ?></label><?php endforeach; ?>
<?php $especificacoesForm = is_array($registro['especificacoes'] ?? null) ? $registro['especificacoes'] : []; $computador = in_array($registro['tipo'] ?? '', ['notebook', 'desktop'], true); ?>
<fieldset class="equipment-technical equipment-form-wide" data-equipment-technical <?= !$computador ? 'hidden disabled' : '' ?>><legend>Configuração do computador</legend><div class="equipment-technical-grid">
<div class="equipment-hostname-field" data-hostname-combobox><label for="equipment-hostname">Hostname</label><div class="equipment-hostname-control"><input id="equipment-hostname" name="hostname" value="<?= h($registro['hostname'] ?? '') ?>" maxlength="255" placeholder="<?= h($hostnamesDisponiveis[0] ?? 'Nome personalizado') ?>" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="equipment-hostnames" aria-describedby="equipment-hostname-hint"><button type="button" class="equipment-hostname-toggle" aria-label="Mostrar sugestões de hostname" aria-controls="equipment-hostnames" aria-expanded="false"><i class="fas fa-chevron-down" aria-hidden="true"></i></button></div><div class="equipment-hostname-dropdown" hidden><div id="equipment-hostnames" role="listbox" aria-label="Hostnames disponíveis"><?php foreach ($hostnamesDisponiveis as $indiceHostname => $hostname): ?><div class="equipment-hostname-option" id="hostname-option-<?= $indiceHostname ?>" role="option" aria-selected="false" data-value="<?= h($hostname) ?>"><?= h($hostname) ?></div><?php endforeach; ?></div><p class="equipment-hostname-empty" hidden>Nenhuma sugestão disponível. Você pode usar um nome personalizado.</p></div><small id="equipment-hostname-hint">Selecione um hostname disponível ou digite um nome personalizado.</small></div>
<?php $sistemaForm = (string)($especificacoesForm['sistema_operacional'] ?? $registro['sistema_operacional'] ?? ''); ?>
<label>Sistema operacional<select name="especificacoes[sistema_operacional]" class="equipment-type-select"><option value="">Selecione o sistema operacional</option><?php foreach (['Windows 11', 'Windows 10', 'Ubuntu'] as $sistema): ?><option value="<?= h($sistema) ?>" <?= $sistemaForm === $sistema ? 'selected' : '' ?>><?= h($sistema) ?></option><?php endforeach; ?><?php if ($sistemaForm !== '' && !in_array($sistemaForm, ['Windows 11', 'Windows 10', 'Ubuntu'], true)): ?><option value="<?= h($sistemaForm) ?>" selected><?= h($sistemaForm) ?> (atual)</option><?php endif; ?></select></label>
<?php foreach (['ram' => 'Memória RAM', 'processador' => 'Processador'] as $campo => $rotulo): ?><label><?= $rotulo ?><input name="especificacoes[<?= $campo ?>]" value="<?= h($especificacoesForm[$campo] ?? $registro[$campo] ?? '') ?>" maxlength="255" placeholder="<?= h(['ram' => 'Ex.: 16 GB', 'processador' => 'Ex.: Intel Core i5'][$campo]) ?>"></label><?php endforeach; ?>
<?php foreach (['bit_instalado' => 'Bitdefender', 'milvus_instalado' => 'Milvus'] as $campo => $rotulo): $instalado = filter_var($especificacoesForm[$campo] ?? $registro[$campo] ?? false, FILTER_VALIDATE_BOOLEAN); ?><label class="equipment-switch-field"><span><?= $rotulo ?></span><span class="equipment-switch-control"><input class="equipment-switch-input" type="checkbox" role="switch" name="especificacoes[<?= $campo ?>]" value="1" <?= $instalado ? 'checked' : '' ?>><span class="equipment-switch-track" aria-hidden="true"></span><span class="equipment-switch-state" aria-hidden="true"><span class="equipment-switch-no">Não</span><span class="equipment-switch-yes">Sim</span></span></span></label><?php endforeach; ?>
</div></fieldset>
<label class="equipment-form-wide">Observações<textarea name="observacoes" rows="3" maxlength="5000"><?= h($registro['observacoes'] ?? '') ?></textarea></label>
<?php endif; ?>
<div class="equipment-form-wide equipment-form-actions"><button class="equipment-primary" type="submit"><?= $acaoForm === 'adicionar' ? 'Adicionar' : 'Salvar' ?></button><a class="equipment-secondary" href="equipamentos.php">Cancelar</a></div>
</form></section>
<?php endif; ?>
<section class="equipment-filters" aria-label="Filtros de equipamentos"><h2><i class="fas fa-filter" aria-hidden="true"></i> Filtros</h2><form method="get">
<label class="equipment-collaborator-field" data-collaborator-combobox data-collaborator-filter>Colaborador<select name="colaborador_id"><option value="">Todos os colaboradores</option><?php $pessoasFiltro = $pessoas; foreach ($equipamentos as $equipamentoFiltro) { $idPessoaFiltro = (string)($equipamentoFiltro['colaborador_id'] ?? ''); if ($idPessoaFiltro !== '' && !isset($pessoasFiltro[$idPessoaFiltro])) $pessoasFiltro[$idPessoaFiltro] = ['id' => $idPessoaFiltro, 'nome' => $equipamentoFiltro['colaborador_nome'] ?? 'Colaborador #' . $idPessoaFiltro]; } uasort($pessoasFiltro, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($pessoasFiltro as $pessoa): ?><option value="<?= h($pessoa['id']) ?>" <?= $filtros['colaborador_id'] === (string)$pessoa['id'] ? 'selected' : '' ?>><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label>
<label>Tipo<select name="tipo"><option value="">Todos os tipos</option><?php foreach ($tipos as $tipo): ?><option value="<?= h($tipo) ?>" <?= $filtros['tipo'] === $tipo ? 'selected' : '' ?>><?= h($tipo === 'tv' ? 'TV' : ucfirst($tipo)) ?></option><?php endforeach; ?></select></label>
<label>Status<select name="status"><option value="">Todos os status</option><?php foreach ($statusNomes as $valor => $rotulo): ?><option value="<?= h($valor) ?>" <?= $filtros['status'] === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label>
<label>Patrimônio<input name="patrimonio" placeholder="Buscar patrimônio" value="<?= h($filtros['patrimonio']) ?>"></label>
<label>Serial<input name="serial" placeholder="Buscar serial" value="<?= h($filtros['serial']) ?>"></label>
<button class="equipment-primary" type="submit">Filtrar</button><a class="equipment-secondary" href="equipamentos.php">Limpar</a>
</form></section>
<section class="equipment-list" aria-label="Lista de equipamentos"><div class="equipment-list-heading"><h2>Lista de equipamentos</h2><span><?= count($exibidos) ?> de <?= count($equipamentos) ?> equipamentos</span></div>
<?php if (!$exibidos): ?><div class="equipment-empty"><i class="fas fa-box-open" aria-hidden="true"></i><p>Nenhum equipamento encontrado.</p></div><?php endif; ?>
<?php foreach ($exibidos as $item): $status = $item['status'] ?? ''; $pessoaId = (string)($item['colaborador_id'] ?? ''); $nome = $pessoas[$pessoaId]['nome'] ?? $item['colaborador_nome'] ?? ($pessoaId !== '' ? 'Colaborador #' . $pessoaId : 'Sem alocação'); $query = http_build_query(['id' => $item['id'], 'origem' => $item['_origem']]); ?>
<article class="equipment-row"><div class="equipment-icon"><i class="fas fa-<?= h($icones[$item['tipo'] ?? ''] ?? 'computer') ?>" aria-hidden="true"></i><span><?= h(($item['tipo'] ?? '') === 'tv' ? 'TV' : ucfirst($item['tipo'] ?? 'Equipamento')) ?></span></div>
<div class="equipment-field"><span>Marca</span><strong><?= h($item['marca'] ?? '—') ?></strong></div><div class="equipment-field equipment-model"><span>Modelo</span><strong><?= h($item['modelo'] ?? '—') ?></strong><?php if (trim((string)($item['hostname'] ?? '')) !== ''): ?><small>Hostname: <?= h($item['hostname']) ?></small><?php endif; ?></div>
<div class="equipment-field"><span>Patrimônio</span><strong><?= h($item['patrimonio'] ?? '—') ?></strong><small>Serial: <?= h($item['serial'] ?? '—') ?></small></div>
<div class="equipment-field equipment-allocation"><span class="equipment-status equipment-status-<?= h(array_key_exists($status, $statusNomes) ? $status : 'outro') ?>"><?= h($statusNomes[$status] ?? $status) ?></span><strong><?= h($nome) ?></strong></div>
<div class="equipment-actions"><a class="equipment-action" href="?acao=editar&amp;<?= h($query) ?>" aria-label="Editar equipamento <?= h($item['patrimonio']) ?>"><i class="fas fa-pen" aria-hidden="true"></i> Editar</a>
<?php if ($status === 'estoque' && !$pessoaId): ?><a class="equipment-action" href="?acao=alocar&amp;<?= h($query) ?>"><i class="fas fa-user-plus" aria-hidden="true"></i> Alocar</a><?php elseif (in_array($status, ['alocado', 'emprestado'], true) && $pessoaId): ?>
<form method="post" data-confirm="Desvincular este equipamento e devolvê-lo ao estoque?"><input type="hidden" name="csrf" value="<?= h($_SESSION['equipamentos_csrf']) ?>"><input type="hidden" name="acao" value="desvincular"><input type="hidden" name="id" value="<?= h($item['id']) ?>"><input type="hidden" name="origem" value="<?= h($item['_origem']) ?>"><button class="equipment-action equipment-unlink" type="submit"><i class="fas fa-link-slash" aria-hidden="true"></i> Desvincular</button></form>
<?php endif; ?></div></article>
<?php endforeach; ?></section>
</main>
<footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer>
</body></html>
