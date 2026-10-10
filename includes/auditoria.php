<?php
/** Auditoria central. Nunca registra senhas, tokens ou o identificador real da sessão. */
function orionAuditoriaData(?int $timestamp = null): string {
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
}
function orionAuditoriaLimpar(array $dados): array {
    $limpos = [];
    foreach ($dados as $chave => $valor) {
        if (preg_match('/password|senha|token|csrf|cookie|session_id|sessionid|secret/i', (string)$chave)) continue;
        $limpos[$chave] = is_array($valor) ? orionAuditoriaLimpar($valor) : $valor;
    }
    return $limpos;
}
function orionResumoAuditoria(?array $dados): ?array {
    if ($dados === null) return null;
    $campos = ['id', 'nome', 'username', 'ativo', 'nivel', 'tipo', 'marca', 'modelo', 'patrimonio', 'serial', 'hostname', 'status', 'centro_custo', 'colaborador_id', 'colaborador_nome', 'numero', 'departamento', 'tipo_trabalho', 'especificacoes', 'categoria', 'servico'];
    return orionAuditoriaLimpar(array_intersect_key($dados, array_flip($campos)));
}
function orionRegistrarLog(string $categoria, string $acao, array $dados = []): bool {
    $lock = null; $temp = null;
    try {
        if (!in_array($categoria, ['seguranca', 'movimentacao', 'usuario'], true)) throw new RuntimeException('Categoria de log inválida.');
        $pasta = dirname(__DIR__) . '/data/log/' . $categoria;
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) throw new RuntimeException('Não foi possível criar a pasta de logs.');
        $lock = @fopen($pasta . '/.logs.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível bloquear o banco de logs.');
        $arquivo = $pasta . '/logs.json';
        $logs = is_file($arquivo) ? json_decode(file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($logs) || array_values($logs) !== $logs) throw new RuntimeException('Banco de logs inválido; dados preservados.');
        $id = bin2hex(random_bytes(12));
        $logs[] = ['id' => $id, 'data' => orionAuditoriaData(), 'acao' => $acao, 'usuario_id' => $_SESSION['usuario_id'] ?? null, 'usuario_nome' => $_SESSION['usuario_nome'] ?? null, 'usuario_login' => $_SESSION['usuario_username'] ?? null, 'sessao_auditoria' => $_SESSION['auditoria_sessao'] ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'pagina' => parse_url($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH), 'dados' => orionAuditoriaLimpar($dados)];
        $json = json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temp = $pasta . '/.logs-' . $id . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Falha ao gravar o banco de logs.');
        $substituido = false;
        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            if (@rename($temp, $arquivo)) { $substituido = true; break; }
            usleep(50000);
        }
        if (!$substituido) throw new RuntimeException('Falha ao substituir o banco de logs.');
        return true;
    } catch (Throwable $e) {
        error_log('Orion auditoria: ' . $e->getMessage());
        return false;
    } finally {
        if ($temp && is_file($temp)) @unlink($temp);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
function orionRegistrarEntrada(): void {
    $_SESSION['auditoria_sessao'] = bin2hex(random_bytes(16));
    $_SESSION['login_time'] = time();
    $_SESSION['auditoria_ultima_atividade'] = time();
    orionRegistrarLog('seguranca', 'entrada', ['entrada' => orionAuditoriaData($_SESSION['login_time'])]);
}
function orionRegistrarAtividade(): void {
    if (!isset($_SESSION['usuario_id'])) return;
    // Sessões abertas antes da implantação conservam a hora original de login.
    if (!isset($_SESSION['auditoria_sessao'])) {
        $_SESSION['auditoria_sessao'] = bin2hex(random_bytes(16));
        orionRegistrarLog('seguranca', 'sessao_existente', ['entrada' => isset($_SESSION['login_time']) ? orionAuditoriaData((int)$_SESSION['login_time']) : null]);
    }
    $_SESSION['auditoria_ultima_atividade'] = time();
}
function orionRegistrarSaida(): void {
    if (!isset($_SESSION['usuario_id'])) return;
    $inicio = isset($_SESSION['login_time']) ? (int)$_SESSION['login_time'] : null;
    $segundos = $inicio === null ? null : max(0, time() - $inicio);
    $duracao = $segundos === null ? null : sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    orionRegistrarLog('seguranca', 'saida', ['entrada' => $inicio === null ? null : orionAuditoriaData($inicio), 'saida' => orionAuditoriaData(), 'ultima_atividade' => isset($_SESSION['auditoria_ultima_atividade']) ? orionAuditoriaData((int)$_SESSION['auditoria_ultima_atividade']) : null, 'duracao_segundos' => $segundos, 'duracao' => $duracao]);
}
function orionRegistrarMovimentacao(string $acao, string $entidade, ?array $antes, ?array $depois, array $detalhes = []): void {
    $antes = orionResumoAuditoria($antes); $depois = orionResumoAuditoria($depois);
    $dados = ['entidade' => $entidade, 'entidade_id' => $depois['id'] ?? $antes['id'] ?? null, 'antes' => $antes, 'depois' => $depois, 'detalhes' => $detalhes];
    orionRegistrarLog('movimentacao', $acao, $dados);
    if ($antes !== null && $depois !== null && ($antes['status'] ?? null) !== ($depois['status'] ?? null)) orionRegistrarLog('movimentacao', 'alterar_status', $dados);
    if (in_array($entidade, ['usuario', 'colaborador'], true)) orionRegistrarLog('usuario', $acao, $dados);
}
/** Usar após a persistência de criação, edição ou inativação de contas do sistema. */
function orionRegistrarUsuario(string $acao, ?array $antes, ?array $depois): void {
    orionRegistrarMovimentacao($acao, 'usuario', $antes, $depois);
}
