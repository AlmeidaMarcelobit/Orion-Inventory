<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
date_default_timezone_set('America/Sao_Paulo');
$base = dirname(__DIR__, 2);
function h($valor): string { return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8'); }
function lerListaTermos(string $path, bool $opcional = false): array {
    if ($opcional && !file_exists($path)) return [];
    $lista = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($lista) || array_values($lista) !== $lista) throw new RuntimeException('Formato de dados inválido.');
    return $lista;
}
require __DIR__ . '/modelo.php';
$_SESSION['termos_csrf'] ??= bin2hex(random_bytes(32));
$erro = ''; $pessoas = $equipamentos = $linhas = $termos = [];
try {
    foreach (['ativos', 'inativos', 'terceiros'] as $grupo) foreach (lerListaTermos($base . '/data/colaboradores/' . $grupo . '.json') as $pessoa) $pessoas[(string)$pessoa['id']] = $pessoa;
    foreach (['alocados', 'emprestados', 'estoque', 'internos', 'fora_uso', 'manutencao'] as $fonte) foreach (lerListaTermos($base . '/data/equipamentos/' . $fonte . '.json') as $item) { $item['_chave'] = $fonte . ':' . $item['id']; $equipamentos[] = $item; }
    $linhas = lerListaTermos($base . '/data/linhas.json');
    $termos = lerListaTermos($base . '/data/termos.json', true);
} catch (Throwable $e) { $erro = 'Não foi possível carregar os dados. Verifique os JSON da pasta /data.'; }
$idPessoa = (string)($_POST['colaborador_id'] ?? $_GET['colaborador_id'] ?? '');
$colaborador = $pessoas[$idPessoa] ?? null;
$vinculados = array_values(array_filter($equipamentos, fn($item) => $idPessoa !== '' && (string)($item['colaborador_id'] ?? '') === $idPessoa));
$linhasVinculadas = array_values(array_filter($linhas, fn($item) => $idPessoa !== '' && (string)($item['colaborador_id'] ?? '') === $idPessoa));
if (($_GET['acao'] ?? '') === 'arquivo' && !$erro) {
    $registro = null; foreach ($termos as $termo) if ($termo['id'] === (string)($_GET['id'] ?? '')) { $registro = $termo; break; }
    $raiz = realpath($base . '/termo');
    $path = $registro ? realpath($base . '/' . $registro['arquivo']) : false;
    if (!$path || !$raiz || strncmp($path, $raiz . DIRECTORY_SEPARATOR, strlen($raiz) + 1) !== 0 || !is_file($path)) { http_response_code(404); exit('Termo não encontrado.'); }
    $mime = $registro['mime'] ?? '';
    if (!in_array($mime, ['text/html', 'application/pdf', 'image/jpeg', 'image/png'], true)) { http_response_code(400); exit('Formato inválido.'); }
    header('Content-Type: ' . $mime . ($mime === 'text/html' ? '; charset=UTF-8' : ''));
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="termo-' . preg_replace('/[^a-z0-9-]/i', '', $registro['id']) . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    readfile($path); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erro) {
    $lock = null; $salvo = null;
    try {
        if (!hash_equals($_SESSION['termos_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão inválida. Atualize a página.');
        if (!$colaborador) throw new RuntimeException('Selecione um colaborador cadastrado.');
        $tipo = (string)($_POST['tipo'] ?? '');
        if (!in_array($tipo, ['entrega', 'devolucao'], true)) throw new RuntimeException('Selecione entrega ou devolução.');
        $acao = (string)($_POST['acao'] ?? '');
        $observacoes = trim((string)($_POST['observacoes'] ?? ''));
        if (strlen($observacoes) > 5000) throw new RuntimeException('Um dos campos excede o limite permitido.');
        $conteudo = null; $upload = null;
        if ($acao === 'gerar') {
            $chaves = is_array($_POST['equipamentos'] ?? null) ? $_POST['equipamentos'] : [];
            $idsLinhas = is_array($_POST['linhas'] ?? null) ? array_map('strval', $_POST['linhas']) : [];
            $selecionados = array_values(array_filter($vinculados, fn($item) => in_array($item['_chave'], $chaves, true)));
            $linhasSelecionadas = array_values(array_filter($linhasVinculadas, fn($item) => in_array((string)$item['id'], $idsLinhas, true)));
            if (count(array_unique($chaves)) !== count($selecionados) || count(array_unique($idsLinhas)) !== count($linhasSelecionadas)) throw new RuntimeException('A seleção contém itens que não estão vinculados ao colaborador. Atualize a ficha.');
            if (!$selecionados && !$linhasSelecionadas) throw new RuntimeException('Selecione ao menos um equipamento ou linha para o termo.');
            $conteudo = gerarDocumentoTermo($colaborador, $selecionados, $linhasSelecionadas, $tipo, $observacoes, $base);
            $extensao = 'html'; $mime = 'text/html'; $nomeOriginal = 'Termo de ' . ($tipo === 'entrega' ? 'entrega' : 'devolução') . '.html';
        } elseif ($acao === 'upload') {
            $upload = $_FILES['arquivo'] ?? null;
            if (!$upload || is_array($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Selecione um arquivo válido para enviar.');
            if ($upload['size'] < 1 || $upload['size'] > 10 * 1024 * 1024) throw new RuntimeException('O arquivo deve ter até 10 MB.');
            $extensao = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            $mime = null;
            if ($extensao === 'pdf' && file_get_contents($upload['tmp_name'], false, null, 0, 5) === '%PDF-') $mime = 'application/pdf';
            elseif (in_array($extensao, ['png', 'jpg', 'jpeg'], true)) { $info = @getimagesize($upload['tmp_name']); if ($info && (($info[2] === IMAGETYPE_PNG && $extensao === 'png') || ($info[2] === IMAGETYPE_JPEG && in_array($extensao, ['jpg', 'jpeg'], true)))) $mime = $info['mime']; }
            if (!$mime) throw new RuntimeException('Envie um PDF, JPG ou PNG válido.');
            $nomeOriginal = basename((string)$upload['name']);
        } else { throw new RuntimeException('Ação inválida.'); }
        $nomePasta = preg_replace('/[^a-z0-9]+/i', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $colaborador['nome']));
        $nomePasta = strtolower(trim($nomePasta, '-')) ?: 'colaborador';
        $nomePasta = substr($nomePasta, 0, 100) . '-' . preg_replace('/[^a-z0-9-]/i', '', $idPessoa);
        $pasta = $base . '/termo/' . $nomePasta;
        if (!is_dir($pasta) && !mkdir($pasta, 0775, true)) throw new RuntimeException('Não foi possível criar a pasta do colaborador.');
        $lock = fopen($base . '/data/.termos.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível salvar o termo.');
        $termos = lerListaTermos($base . '/data/termos.json', true);
        $id = bin2hex(random_bytes(12));
        $nomeArquivo = date('Ymd-His') . '-' . $tipo . '-' . $id . '.' . $extensao;
        $salvo = $pasta . '/' . $nomeArquivo;
        if ($upload) { if (!move_uploaded_file($upload['tmp_name'], $salvo)) throw new RuntimeException('Não foi possível salvar o arquivo enviado.'); }
        elseif (file_put_contents($salvo, $conteudo, LOCK_EX) !== strlen($conteudo)) throw new RuntimeException('Não foi possível salvar o documento.');
        $termos[] = ['id' => $id, 'colaborador_id' => $colaborador['id'], 'colaborador_nome' => $colaborador['nome'], 'tipo' => $tipo, 'origem' => $acao === 'gerar' ? 'gerado' : 'upload', 'arquivo' => 'termo/' . $nomePasta . '/' . $nomeArquivo, 'mime' => $mime, 'nome_original' => $nomeOriginal, 'data' => date('Y-m-d H:i:s'), 'usuario' => $_SESSION['usuario_nome'] ?? 'Usuário'];
        $json = json_encode($termos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tempIndice = $base . '/data/.termos-' . $id . '.tmp';
        if (file_put_contents($tempIndice, $json, LOCK_EX) !== strlen($json) || !rename($tempIndice, $base . '/data/termos.json')) { if (is_file($tempIndice)) unlink($tempIndice); throw new RuntimeException('Não foi possível registrar o termo.'); }
        flock($lock, LOCK_UN); fclose($lock);
        $_SESSION['termos_mensagem'] = $acao === 'gerar' ? 'Termo criado. Abra o documento para imprimir ou salvar em PDF.' : 'Arquivo enviado e salvo na pasta do colaborador.';
        header('Location: termos.php?colaborador_id=' . urlencode($idPessoa) . ($acao === 'gerar' ? '&novo=' . $id : '&upload=1')); exit;
    } catch (Throwable $e) {
        if ($salvo && is_file($salvo)) unlink($salvo);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        $erro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível salvar o termo.';
    }
}
$mensagem = $_SESSION['termos_mensagem'] ?? ''; unset($_SESSION['termos_mensagem']);
usort($termos, fn($a, $b) => strcmp($b['data'], $a['data']));
$uploads = array_values(array_filter($termos, fn($t) => $t['origem'] === 'upload'));
?>
<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Termos - Orion Inventory</title><link rel="stylesheet" href="../../assets/css/global/import.css"><link rel="stylesheet" href="../../assets/css/pages/dashboard.css"><link rel="stylesheet" href="../../assets/css/pages/equipamentos.css"><link rel="stylesheet" href="../../assets/css/pages/termos.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><script src="../../assets/js/equipamentos.js" defer></script><script src="../../assets/js/termos.js" defer></script></head><body>
<header>
<nav class="menu-lateral"><div class="btn-expandir"><i class="bi bi-card-list"></i></div><ul>
<li class="item-menu"><a href="../dashbord/dashbord.php"><span class="item"><i class="bi bi-columns-gap"></i></span><span class="txt-link">Dashboard</span></a></li>
<li class="item-menu"><a href="../colaboradores/colaboradores.php"><span class="item"><i class="bi bi-person"></i></span><span class="txt-link">Colaboradores</span></a></li>
<li class="item-menu"><a href="../equipamentos/equipamentos.php"><span class="item"><i class="bi bi-pc-display-horizontal"></i></span><span class="txt-link">Equipamentos</span></a></li>
<li class="item-menu"><a href="../linhas/linhas.php"><span class="item"><i class="bi bi-sd-card"></i></span><span class="txt-link">Linhas</span></a></li>
<li class="item-menu active"><a href="termos.php" aria-current="page"><span class="item"><i class="bi bi-file-earmark-pdf"></i></span><span class="txt-link">Termos</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-tools"></i></span><span class="txt-link">Manutenção</span></a></li>
<li class="item-menu"><a href="#"><span class="item"><i class="bi bi-person-circle"></i></span><span class="txt-link">Usuários</span></a></li>
</ul></nav>
<div class="logo"><a href="../dashbord/dashbord.php"><img src="../../img/global/logos/orion" alt="Orion Inventory"></a></div>
<div class="usuario-menu"><span class="usuario-nome"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></span></div>
<a href="../../logout.php" class="sair-btn"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a>
</header>
<main class="equipment-page terms-page"><div class="equipment-heading"><div><h1><i class="fas fa-file-signature" aria-hidden="true"></i> Termos</h1><p>Confira os itens, gere o documento e arquive o termo assinado.</p></div></div>
<?php if ($erro): ?><p class="equipment-notice equipment-error" role="alert"><?= h($erro) ?></p><?php endif; ?><?php if ($mensagem): ?><p class="equipment-notice" role="status"><?= h($mensagem) ?></p><?php endif; ?>
<?php if (isset($_GET['novo'])): ?><p class="terms-new"><a class="equipment-primary" href="?acao=arquivo&amp;id=<?= h($_GET['novo']) ?>" target="_blank" rel="noopener">Abrir termo criado / imprimir</a></p><?php endif; ?>
<section class="equipment-filters"><h2>Colaborador</h2><form method="get"><label class="equipment-collaborator-field" data-collaborator-combobox>Buscar colaborador<select name="colaborador_id" required><option value="">Selecione um colaborador</option><?php uasort($pessoas, fn($a, $b) => strcasecmp($a['nome'], $b['nome'])); foreach ($pessoas as $pessoa): ?><option value="<?= h($pessoa['id']) ?>" <?= $idPessoa === (string)$pessoa['id'] ? 'selected' : '' ?>><?= h($pessoa['nome'] . ' · ' . ($pessoa['departamento'] ?? '')) ?></option><?php endforeach; ?></select></label><button class="equipment-primary" type="submit">Carregar ficha</button><a class="equipment-secondary" href="termos.php">Limpar</a></form></section>
<?php if ($colaborador): ?><section class="equipment-form-panel"><div class="terms-person"><div><h2><?= h($colaborador['nome']) ?></h2><p><?= h(($colaborador['cargo'] ?? '—') . ' · ' . ($colaborador['departamento'] ?? '—')) ?></p></div><span>Centro de custo <?= h($colaborador['centro_custo'] ?? '—') ?></span></div><dl class="terms-person-details"><?php foreach (['matricula' => 'Matrícula', 'cpf' => 'CPF', 'email' => 'E-mail', 'tipo_trabalho' => 'Tipo de trabalho'] as $campo => $rotulo): ?><div><dt><?= $rotulo ?></dt><dd><?= h($colaborador[$campo] ?? '—') ?></dd></div><?php endforeach; ?></dl>
<form method="post" class="terms-generate"><input type="hidden" name="acao" value="gerar"><input type="hidden" name="csrf" value="<?= h($_SESSION['termos_csrf']) ?>"><input type="hidden" name="colaborador_id" value="<?= h($idPessoa) ?>"><h2>Equipamentos vinculados (<?= count($vinculados) ?>)</h2><p class="terms-hint">Selecione os itens que devem constar no termo.</p><div class="terms-table-wrap"><table><thead><tr><th>Incluir</th><th>Tipo / marca / modelo</th><th>Patrimônio / serial</th><th>Hostname / status</th><th>Especificações</th><th>Centro de custo</th></tr></thead><tbody><?php foreach ($vinculados as $item): ?><tr><td><input type="checkbox" name="equipamentos[]" value="<?= h($item['_chave']) ?>" checked aria-label="Incluir equipamento <?= h($item['patrimonio'] ?? $item['id']) ?>"></td><td><strong><?= h(ucfirst($item['tipo'] ?? '')) ?></strong><small><?= h(($item['marca'] ?? '') . ' ' . ($item['modelo'] ?? '')) ?></small></td><td><?= h($item['patrimonio'] ?? '—') ?><small><?= h($item['serial'] ?? '—') ?></small></td><td><?= h($item['hostname'] ?? '—') ?><small><?= h(ucfirst(str_replace('_', ' ', $item['status'] ?? ''))) ?></small></td><td><?php foreach (($item['especificacoes'] ?? []) as $campo => $valor): if (!is_scalar($valor) && $valor !== null) continue; ?><small><?= h(ucfirst(str_replace('_', ' ', $campo))) ?>: <?= h(is_bool($valor) ? ($valor ? 'Sim' : 'Não') : ($valor ?? '—')) ?></small><?php endforeach; ?></td><td><?= h($item['centro_custo'] ?? '—') ?></td></tr><?php endforeach; ?><?php if (!$vinculados): ?><tr><td colspan="6">Nenhum equipamento vinculado a este colaborador.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($linhasVinculadas): ?><h2>Linhas vinculadas</h2><div class="terms-lines"><?php foreach ($linhasVinculadas as $linha): ?><label><input type="checkbox" name="linhas[]" value="<?= h($linha['id']) ?>" checked> <?= h($linha['numero']) ?> · <?= ($linha['tipo'] ?? '') === 'echip' ? 'eSIM' : 'Chip físico' ?></label><?php endforeach; ?></div><?php endif; ?><div class="equipment-form"><label class="equipment-form-wide">Observações / condições dos itens<textarea name="observacoes" rows="3" maxlength="5000"><?= h($_POST['observacoes'] ?? '') ?></textarea></label></div><div class="terms-actions"><button class="equipment-primary" type="submit" name="tipo" value="entrega"><i class="fas fa-arrow-right" aria-hidden="true"></i> Gerar entrega</button><button class="equipment-secondary" type="submit" name="tipo" value="devolucao"><i class="fas fa-arrow-left" aria-hidden="true"></i> Gerar devolução</button><button class="equipment-secondary" type="button" data-upload-toggle aria-controls="terms-upload" aria-expanded="<?= isset($_GET['upload']) ? 'true' : 'false' ?>"><i class="fas fa-cloud-arrow-up" aria-hidden="true"></i> Upload</button></div><p class="terms-hint">Gerar um termo registra o documento; a movimentação dos itens é feita nas páginas de equipamentos e linhas.</p></form></section>
<section class="equipment-form-panel" id="terms-upload" <?= !isset($_GET['upload']) && ($_POST['acao'] ?? '') !== 'upload' ? 'hidden' : '' ?>><h2>Enviar termo assinado</h2><form method="post" enctype="multipart/form-data" data-terms-upload><input type="hidden" name="acao" value="upload"><input type="hidden" name="csrf" value="<?= h($_SESSION['termos_csrf']) ?>"><input type="hidden" name="colaborador_id" value="<?= h($idPessoa) ?>"><label>Tipo do termo<select name="tipo" class="equipment-type-select" required><option value="entrega">Entrega</option><option value="devolucao" <?= ($_POST['tipo'] ?? '') === 'devolucao' ? 'selected' : '' ?>>Devolução</option></select></label><label class="terms-dropzone" data-terms-drop><i class="fas fa-cloud-arrow-up" aria-hidden="true"></i><strong>Arraste o arquivo ou clique para selecionar</strong><span>PDF, JPG ou PNG · até 10 MB</span><input type="file" name="arquivo" accept="application/pdf,image/jpeg,image/png" required aria-label="Selecionar termo assinado"><span data-file-name role="status">Nenhum arquivo selecionado.</span></label><div class="terms-actions"><button class="equipment-primary" type="submit">Salvar arquivo</button><button class="equipment-secondary" type="reset">Limpar</button></div></form></section><?php else: ?><div class="equipment-empty"><i class="fas fa-user" aria-hidden="true"></i><p>Selecione um colaborador para carregar os equipamentos e criar um termo.</p></div><?php endif; ?>
<section class="equipment-form-panel terms-history"><h2>Últimos termos enviados</h2><?php if (!$uploads): ?><p class="terms-hint">Nenhum termo enviado ainda.</p><?php endif; ?><?php foreach (array_slice($uploads, 0, 15) as $termo): ?><article class="terms-history-row"><i class="fas fa-file-arrow-up" aria-hidden="true"></i><div><strong><?= h($termo['colaborador_nome']) ?></strong><small><?= h($termo['nome_original']) ?></small></div><span><?= $termo['tipo'] === 'entrega' ? 'Entrega' : 'Devolução' ?></span><time datetime="<?= h(str_replace(' ', 'T', $termo['data'])) ?>"><?= h(date('d/m/Y H:i', strtotime($termo['data']))) ?></time><a class="equipment-secondary" href="?acao=arquivo&amp;id=<?= h($termo['id']) ?>" target="_blank" rel="noopener">Abrir</a></article><?php endforeach; ?></section>
<?php $gerados = array_values(array_filter($termos, fn($t) => $t['origem'] === 'gerado' && ($idPessoa === '' || (string)$t['colaborador_id'] === $idPessoa))); if ($gerados): ?><section class="equipment-form-panel terms-history"><h2>Termos gerados<?= $colaborador ? ' para este colaborador' : '' ?></h2><?php foreach (array_slice($gerados, 0, 15) as $termo): ?><article class="terms-history-row"><i class="fas fa-file-signature" aria-hidden="true"></i><strong><?= h($termo['colaborador_nome']) ?></strong><span><?= $termo['tipo'] === 'entrega' ? 'Entrega' : 'Devolução' ?></span><time><?= h(date('d/m/Y H:i', strtotime($termo['data']))) ?></time><a class="equipment-secondary" href="?acao=arquivo&amp;id=<?= h($termo['id']) ?>" target="_blank" rel="noopener">Abrir</a></article><?php endforeach; ?></section><?php endif; ?>
</main><footer><p>Orion Inventory © 2023 - 2026 - Todos os direitos reservados</p></footer></body></html>
