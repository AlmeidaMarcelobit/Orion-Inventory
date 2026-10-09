<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
date_default_timezone_set('America/Sao_Paulo');
$base = dirname(__DIR__, 2);
$arquivo = $base . '/data/chamados.json';
$categorias = ['hardware' => 'Hardware', 'sistema' => 'Sistema'];
$servicos = ['email' => 'E-mail', 'chat' => 'Chat', 'reset_senha' => 'Reset de senha', 'drive' => 'Drive', 'active_directory' => 'Active Directory', 'equipamento' => 'Equipamento'];
$sintomas = ['travando' => 'Travando / lentidão', 'camera' => 'Câmera', 'teclado' => 'Teclado', 'som' => 'Som', 'outro' => 'Outro problema'];
$encaminhamentos = ['trocar' => 'Trocar equipamento', 'manutencao' => 'Manutenção'];
function h($valor): string { return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); }
function lerChamadosLista(string $path, bool $opcional = false): array {
    if ($opcional && !is_file($path)) return [];
    $lista = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($lista) || array_values($lista) !== $lista) throw new RuntimeException('Formato de dados inválido.');
    return $lista;
}
$_SESSION['chamados_csrf'] ??= bin2hex(random_bytes(32));
$erro = ''; $pessoas = $ativos = $equipamentos = $chamados = [];
try {
    foreach (['ativos', 'inativos', 'terceiros'] as $grupo) foreach (lerChamadosLista($base . '/data/colaboradores/' . $grupo . '.json') as $pessoa) {
        $pessoas[(string)$pessoa['id']] = $pessoa;
        if ($grupo !== 'inativos') $ativos[(string)$pessoa['id']] = $pessoa;
    }
    foreach (['alocados', 'emprestados', 'estoque', 'internos', 'fora_uso', 'manutencao'] as $fonte) foreach (lerChamadosLista($base . '/data/equipamentos/' . $fonte . '.json') as $item) { $item['_chave'] = $fonte . ':' . $item['id']; $equipamentos[] = $item; }
    $chamados = lerChamadosLista($arquivo, true);
} catch (Throwable $e) { $erro = 'Não foi possível carregar os dados de chamados e colaboradores.'; }
$idPessoa = (string)($_POST['colaborador_id'] ?? $_GET['colaborador_id'] ?? '');
$colaborador = $pessoas[$idPessoa] ?? null;
$vinculados = array_values(array_filter($equipamentos, fn($item) => $idPessoa !== '' && (string)($item['colaborador_id'] ?? '') === $idPessoa));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro) {
    $lock = null; $temp = null;
    try {
        if (!hash_equals($_SESSION['chamados_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão inválida. Atualize a página.');
        $lock = fopen($base . '/data/.chamados.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível salvar o chamado.');
        $chamados = lerChamadosLista($arquivo, true);
        $acao = (string)($_POST['acao'] ?? '');
        $agora = date('Y-m-d H:i:s');
        $usuario = $_SESSION['usuario_nome'] ?? 'Usuário';
        if ($acao === 'abrir') {
            if (!$colaborador || !isset($ativos[$idPessoa])) throw new RuntimeException('Selecione um colaborador ativo para abrir o chamado.');
            $categoria = (string)($_POST['categoria'] ?? '');
            $servico = $categoria === 'sistema' ? (string)($_POST['servico'] ?? '') : 'equipamento';
            if (!isset($categorias[$categoria]) || !isset($servicos[$servico])) throw new RuntimeException('Selecione o tipo do problema e o serviço.');
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            if ($descricao === '' || strlen($descricao) > 5000) throw new RuntimeException('Descreva o problema em até 5.000 caracteres.');
            $equipamento = null; $sintoma = null; $encaminhamento = null;
            if ($servico === 'equipamento') {
                foreach ($vinculados as $item) if ($item['_chave'] === (string)($_POST['equipamento'] ?? '')) { $equipamento = $item; break; }
                if (!$equipamento) throw new RuntimeException('Selecione um equipamento vinculado ao colaborador.');
                if (in_array($equipamento['tipo'] ?? '', ['notebook', 'desktop'], true)) {
                    $sintoma = (string)($_POST['sintoma'] ?? '');
                    if (!isset($sintomas[$sintoma])) throw new RuntimeException('Selecione o problema do notebook ou desktop.');
                } else {
                    $encaminhamento = (string)($_POST['encaminhamento'] ?? '');
                    if (!isset($encaminhamentos[$encaminhamento])) throw new RuntimeException('Selecione troca ou manutenção para este equipamento.');
                }
            }
            $maiorId = 0; foreach ($chamados as $chamado) $maiorId = max($maiorId, (int)$chamado['id']);
            $chamados[] = ['id' => $maiorId + 1, 'colaborador_id' => $colaborador['id'], 'colaborador_nome' => $colaborador['nome'], 'departamento' => $colaborador['departamento'] ?? null, 'categoria' => $categoria, 'servico' => $servico, 'equipamento' => $equipamento, 'sintoma' => $sintoma, 'encaminhamento' => $encaminhamento, 'descricao' => $descricao, 'status' => 'aberto', 'data_abertura' => $agora, 'data_atualizacao' => $agora, 'usuario_abertura' => $usuario, 'usuario_id' => $_SESSION['usuario_id'], 'data_fechamento' => null, 'resolucao' => null, 'historico' => [['acao' => 'aberto', 'data' => $agora, 'usuario' => $usuario]]];
            $mensagem = 'Chamado #' . ($maiorId + 1) . ' aberto com sucesso.';
        } elseif ($acao === 'fechar') {
            $indice = null; foreach ($chamados as $i => $chamado) if ((string)$chamado['id'] === (string)($_POST['id'] ?? '')) { $indice = $i; break; }
            if ($indice === null) throw new RuntimeException('Chamado não encontrado.');
            if ($chamados[$indice]['status'] !== 'aberto') throw new RuntimeException('Este chamado já está fechado.');
            $resolucao = trim((string)($_POST['resolucao'] ?? ''));
            if ($resolucao === '' || strlen($resolucao) > 5000) throw new RuntimeException('Informe a solução ou o motivo do fechamento em até 5.000 caracteres.');
            $chamados[$indice]['status'] = 'fechado';
            $chamados[$indice]['resolucao'] = $resolucao;
            $chamados[$indice]['data_fechamento'] = $agora;
            $chamados[$indice]['data_atualizacao'] = $agora;
            $chamados[$indice]['usuario_fechamento'] = $usuario;
            $chamados[$indice]['historico'][] = ['acao' => 'fechado', 'data' => $agora, 'usuario' => $usuario];
            $mensagem = 'Chamado #' . $chamados[$indice]['id'] . ' fechado com sucesso.';
        } else { throw new RuntimeException('Ação inválida.'); }
        $json = json_encode($chamados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temp = $base . '/data/.chamados-' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $arquivo)) throw new RuntimeException('Não foi possível registrar o chamado.');
        flock($lock, LOCK_UN); fclose($lock);
        $_SESSION['chamados_mensagem'] = $mensagem;
        header('Location: chamado.php' . ($idPessoa !== '' ? '?colaborador_id=' . urlencode($idPessoa) : '')); exit;
    } catch (Throwable $e) {
        if ($temp && is_file($temp)) unlink($temp);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível salvar o chamado.';
        $chamados = lerChamadosLista($arquivo, true);
    }
}
$statusFiltro = (string)($_GET['status'] ?? '');
$tipoFiltro = (string)($_GET['categoria'] ?? '');
$exibidos = array_values(array_filter($chamados, fn($c) => ($idPessoa === '' || (string)$c['colaborador_id'] === $idPessoa) && ($statusFiltro === '' || $c['status'] === $statusFiltro) && ($tipoFiltro === '' || $c['categoria'] === $tipoFiltro)));
usort($exibidos, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
$mensagem = $_SESSION['chamados_mensagem'] ?? ''; unset($_SESSION['chamados_mensagem']);
$categoriaForm = (string)($_POST['categoria'] ?? 'hardware');
$servicoForm = (string)($_POST['servico'] ?? 'email');
?>
<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chamado - Orion Inventory</title><link rel="stylesheet" href="../../assets/css/global/import.css"><link rel="stylesheet" href="../../assets/css/pages/dashboard.css"><link rel="stylesheet" href="../../assets/css/pages/equipamentos.css"><link rel="stylesheet" href="../../assets/css/pages/chamado.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><script src="../../assets/js/equipamentos.js" defer></script><script src="../../assets/js/chamado.js" defer></script></head><body>
<header>
<nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul>
<li class="item-menu"><a href="../dashbord/dashbord.php"><span class="item"><i class="bi bi-columns-gap"></i></span><span class="txt-link">Dashboard</span></a></li>
<li class="item-menu"><a href="../colaboradores/colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li>
<li class="item-menu"><a href="../equipamentos/equipamentos.php"><span class="item"><i class="bi bi-pc-display-horizontal"></i></span><span class="txt-link">Equipamentos</span></a></li>
<li class="item-menu"><a href="../linhas/linhas.php"><span class="item"><i class="bi bi-sd-card"></i></span><span class="txt-link">Linhas</span></a></li>
<li class="item-menu"><a href="../termos/termos.php"><span class="item"><i class="bi bi-file-earmark-pdf"></i></span><span class="txt-link">Termos</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-tools"></i></span><span class="txt-link">Manutenção</span></a></li>
<li class="item-menu active"><a href="chamado.php" aria-current="page"><span class="item"><i class="bi bi-headset"></i></span><span class="txt-link">Chamado</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-person-circle"></i></span><span class="txt-link">Usuários</span></a></li>
</ul></nav>
<div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div>
<div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div>
<a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a>
</header>
<main class="equipment-page tickets-page"><div class="equipment-heading"><div><h1><i class="fas fa-headset" aria-hidden="true"></i> Chamado</h1><p>Abra e acompanhe solicitações de suporte por colaborador.</p></div><span class="tickets-total"><?= count(array_filter($chamados, fn($c) => $c['status'] === 'aberto')) ?> chamados abertos</span></div>
<?php if ($erro): ?><p class="equipment-notice equipment-error" role="alert"><?= h($erro) ?></p><?php endif; ?><?php if ($mensagem): ?><p class="equipment-notice" role="status"><?= h($mensagem) ?></p><?php endif; ?>
<section class="equipment-filters"><h2>Colaborador</h2><form method="get"><label class="equipment-collaborator-field" data-collaborator-combobox data-collaborator-filter>Buscar colaborador<select name="colaborador_id"><option value="">Todos os colaboradores</option><?php uasort($pessoas, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($pessoas as $pessoa): ?><option value="<?= h($pessoa['id']) ?>" <?= $idPessoa === (string)$pessoa['id'] ? 'selected' : '' ?>><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label><label>Status<select name="status"><option value="">Todos</option><option value="aberto" <?= $statusFiltro === 'aberto' ? 'selected' : '' ?>>Aberto</option><option value="fechado" <?= $statusFiltro === 'fechado' ? 'selected' : '' ?>>Fechado</option></select></label><label>Tipo<select name="categoria"><option value="">Todos</option><?php foreach ($categorias as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= $tipoFiltro === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><button class="equipment-primary" type="submit">Carregar ficha</button><a class="equipment-secondary" href="chamado.php">Limpar</a></form></section>
<?php if ($colaborador): ?><section class="equipment-form-panel"><div class="tickets-person"><div><h2><?= h($colaborador['nome']) ?></h2><p><?= h(($colaborador['cargo'] ?? '—') . ' · ' . ($colaborador['departamento'] ?? '—')) ?></p></div><span><?= count($vinculados) ?> equipamentos vinculados</span></div>
<?php if ($vinculados): ?><details class="tickets-datasheet"><summary>Datasheet · Equipamentos do colaborador</summary><?php foreach ($vinculados as $item): ?><p><strong><?= h(($item['marca'] ?? '') . ' ' . ($item['modelo'] ?? '')) ?></strong> · <?= h(ucfirst($item['tipo'] ?? '')) ?> · Patrimônio <?= h($item['patrimonio'] ?? '—') ?><?php if (!empty($item['hostname'])): ?> · <?= h($item['hostname']) ?><?php endif; ?></p><?php endforeach; ?></details><?php endif; ?>
<?php if (isset($ativos[$idPessoa])): ?><form method="post" data-ticket-form><input type="hidden" name="csrf" value="<?= h($_SESSION['chamados_csrf']) ?>"><input type="hidden" name="acao" value="abrir"><input type="hidden" name="colaborador_id" value="<?= h($idPessoa) ?>"><fieldset class="tickets-category"><legend>Tipo do problema</legend><?php foreach ($categorias as $valor => $rotulo): ?><label><input type="radio" name="categoria" value="<?= $valor ?>" <?= $categoriaForm === $valor ? 'checked' : '' ?> required><span><i class="fas fa-<?= $valor === 'hardware' ? 'laptop' : 'gear' ?>" aria-hidden="true"></i> <?= $rotulo ?></span></label><?php endforeach; ?></fieldset>
<div class="equipment-form tickets-fields"><label data-ticket-system>Serviço<select name="servico" class="equipment-type-select"><?php foreach ($servicos as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= $servicoForm === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><label data-ticket-equipment>Equipamento<select name="equipamento" class="equipment-type-select"><option value="">Selecione um equipamento</option><?php foreach ($vinculados as $item): ?><option value="<?= h($item['_chave']) ?>" data-type="<?= h($item['tipo'] ?? '') ?>" <?= ($_POST['equipamento'] ?? '') === $item['_chave'] ? 'selected' : '' ?>><?= h(($item['marca'] ?? '') . ' ' . ($item['modelo'] ?? '') . ' · ' . ($item['patrimonio'] ?? '') . (!empty($item['hostname']) ? ' · ' . $item['hostname'] : '')) ?></option><?php endforeach; ?></select></label><label data-ticket-symptom>Problema do notebook / desktop<select name="sintoma" class="equipment-type-select"><option value="">Selecione o problema</option><?php foreach ($sintomas as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= ($_POST['sintoma'] ?? '') === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><label data-ticket-referral>Encaminhamento<select name="encaminhamento" class="equipment-type-select"><option value="">Selecione o encaminhamento</option><?php foreach ($encaminhamentos as $valor => $rotulo): ?><option value="<?= $valor ?>" <?= ($_POST['encaminhamento'] ?? '') === $valor ? 'selected' : '' ?>><?= $rotulo ?></option><?php endforeach; ?></select></label><label class="equipment-form-wide">Descrição do problema<textarea name="descricao" rows="4" maxlength="5000" placeholder="Informe o que acontece e quando o problema começou." required><?= h($_POST['descricao'] ?? '') ?></textarea></label></div><div class="tickets-form-footer"><p>A troca ou manutenção será tratada na área responsável pelo equipamento.</p><button class="equipment-primary" type="submit"><i class="fas fa-plus" aria-hidden="true"></i> Abrir chamado</button></div></form><?php else: ?><p class="tickets-hint">Este colaborador está inativo. Você pode consultar e fechar os chamados existentes.</p><?php endif; ?></section><?php else: ?><div class="equipment-empty"><i class="fas fa-user" aria-hidden="true"></i><p>Selecione um colaborador para abrir um chamado.</p></div><?php endif; ?>
<section class="equipment-list"><div class="equipment-list-heading"><h2><?= $colaborador ? 'Chamados de ' . h($colaborador['nome']) : 'Lista de chamados' ?></h2><span><?= count($exibidos) ?> chamados</span></div><?php if (!$exibidos): ?><div class="equipment-empty"><i class="fas fa-headset" aria-hidden="true"></i><p>Nenhum chamado encontrado.</p></div><?php endif; ?>
<?php foreach ($exibidos as $chamado): ?><article class="ticket-card"><div class="ticket-card-heading"><div class="ticket-id">#<?= h($chamado['id']) ?></div><div><strong><?= h($chamado['colaborador_nome']) ?></strong><small><?= h($categorias[$chamado['categoria']] ?? $chamado['categoria']) ?> · <?= h($servicos[$chamado['servico']] ?? $chamado['servico']) ?></small></div><span class="ticket-status ticket-status-<?= $chamado['status'] === 'aberto' ? 'open' : 'closed' ?>"><?= $chamado['status'] === 'aberto' ? 'Aberto' : 'Fechado' ?></span><time datetime="<?= h(str_replace(' ', 'T', $chamado['data_abertura'])) ?>"><?= h(date('d/m/Y H:i', strtotime($chamado['data_abertura']))) ?></time></div><details <?= ($_POST['acao'] ?? '') === 'fechar' && (string)($_POST['id'] ?? '') === (string)$chamado['id'] ? 'open' : '' ?>><summary>Detalhes<?= $chamado['status'] === 'aberto' ? ' / fechar chamado' : '' ?></summary><p class="ticket-description"><?= h($chamado['descricao']) ?></p><?php if ($chamado['equipamento']): ?><p><strong>Equipamento:</strong> <?= h(($chamado['equipamento']['marca'] ?? '') . ' ' . ($chamado['equipamento']['modelo'] ?? '') . ' · Patrimônio ' . ($chamado['equipamento']['patrimonio'] ?? '—')) ?></p><?php endif; ?><?php if ($chamado['sintoma']): ?><p><strong>Problema:</strong> <?= h($sintomas[$chamado['sintoma']] ?? $chamado['sintoma']) ?></p><?php endif; ?><?php if ($chamado['encaminhamento']): ?><p><strong>Encaminhamento:</strong> <?= h($encaminhamentos[$chamado['encaminhamento']] ?? $chamado['encaminhamento']) ?></p><?php endif; ?><p class="tickets-hint">Aberto por <?= h($chamado['usuario_abertura']) ?></p><?php if ($chamado['status'] === 'aberto'): ?><form method="post" data-confirm="Fechar este chamado com a solução informada?"><input type="hidden" name="csrf" value="<?= h($_SESSION['chamados_csrf']) ?>"><input type="hidden" name="acao" value="fechar"><input type="hidden" name="id" value="<?= h($chamado['id']) ?>"><input type="hidden" name="colaborador_id" value="<?= h($idPessoa) ?>"><label>Solução / motivo do fechamento<textarea name="resolucao" rows="3" maxlength="5000" required><?= ($_POST['acao'] ?? '') === 'fechar' && (string)($_POST['id'] ?? '') === (string)$chamado['id'] ? h($_POST['resolucao'] ?? '') : '' ?></textarea></label><button class="equipment-primary" type="submit">Fechar chamado</button></form><?php else: ?><p class="ticket-description"><strong>Solução:</strong> <?= h($chamado['resolucao']) ?></p><p class="tickets-hint">Fechado em <?= h(date('d/m/Y H:i', strtotime($chamado['data_fechamento']))) ?> por <?= h($chamado['usuario_fechamento'] ?? '—') ?></p><?php endif; ?></details></article><?php endforeach; ?></section>
</main><footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer></body></html>
