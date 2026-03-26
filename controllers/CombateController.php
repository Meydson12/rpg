<?php

class CombateController
{
    private PDO $pdo;
    private Combate $combate;
    private Player $player;

    public function __construct(PDO $pdo, int $playerId)
    {
        $this->pdo = $pdo;
        $this->combate = new Combate($pdo);
        $this->player = new Player($pdo, $playerId);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['turno_combate'])) {
            $_SESSION['turno_combate'] = 'player';
        }
    }

    public function getPlayer(): array
    {
        $player = $this->combate->buscarPersonagemPorId($this->player->getId());

        if (!$player || ($player['tipo'] ?? '') !== 'player') {
            throw new Exception('Player de combate não encontrado.');
        }

        return $player;
    }

    public function getNpc(int $npcId): ?array
    {
        $npc = $this->combate->buscarPersonagemPorId($npcId);

        if (!$npc || ($npc['tipo'] ?? '') !== 'npc') {
            return null;
        }

        return $npc;
    }

    public function getTurnoAtual(): string
    {
        return $_SESSION['turno_combate'] ?? 'player';
    }

    public function getResumoIniciativa(): ?array
    {
        return $_SESSION['iniciativa_combate'] ?? null;
    }

    public function getNomeTurnoAtual(): string
    {
        return $this->getTurnoAtual() === 'player' ? 'Jogador' : 'Inimigo';
    }

    private function definirTurno(string $turno): void
    {
        $_SESSION['turno_combate'] = $turno;
    }

    private function gerarTokenCombate(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return substr(md5(uniqid('cmb_', true)), 0, 16);
        }
    }

    private function iniciarStatsCombate(int $npcId): void
    {
        $statsAtuais = $_SESSION['combate_stats'] ?? null;

        if (
            is_array($statsAtuais)
            && (int)($statsAtuais['npc_id'] ?? 0) === $npcId
            && !empty($statsAtuais['combate_token'])
        ) {
            return;
        }

        $_SESSION['combate_stats'] = [
            'npc_id' => $npcId,
            'dano_recebido' => 0,
            'entrou_critico' => false,
            'combate_token' => $this->gerarTokenCombate()
        ];
    }

    private function registrarDanoRecebidoPeloPlayer(int $dano): void
    {
        if ($dano <= 0) {
            return;
        }

        if (!isset($_SESSION['combate_stats']) || !is_array($_SESSION['combate_stats'])) {
            return;
        }

        $_SESSION['combate_stats']['dano_recebido'] =
            (int)($_SESSION['combate_stats']['dano_recebido'] ?? 0) + $dano;
    }

    private function marcarPlayerEntrouCriticoSeNecessario(): void
    {
        if (!isset($_SESSION['combate_stats']) || !is_array($_SESSION['combate_stats'])) {
            return;
        }

        $player = $this->getPlayer();
        $estadoVida = trim((string)($player['estado_vida'] ?? ''));

        if (in_array($estadoVida, ['critico', 'inconsciente', 'morrendo', 'morto'], true)) {
            $_SESSION['combate_stats']['entrou_critico'] = true;
        }
    }

    private function obterResumoCombateAtual(): array
    {
        $stats = $_SESSION['combate_stats'] ?? [];

        return [
            'dano_recebido' => (int)($stats['dano_recebido'] ?? 0),
            'entrou_critico' => (bool)($stats['entrou_critico'] ?? false),
            'combate_token' => (string)($stats['combate_token'] ?? '')
        ];
    }

    private function processarObservacaoNpcAoFimDoCombate(): void
    {
        $playerAtual = $this->getPlayer();

        if (!empty($playerAtual['morto'])) {
            return;
        }

        $mundoNpc = new MundoNPC($this->pdo);
        $resumo = $this->obterResumoCombateAtual();

        $mundoNpc->reagirAoFimDoCombate($this->player, [
            'vitoria' => true,
            'dano_recebido' => $resumo['dano_recebido'],
            'entrou_critico' => $resumo['entrou_critico'],
            'combate_token' => $resumo['combate_token']
        ]);
    }

    public function resetarTurnoCombate(): void
    {
        $_SESSION['turno_combate'] = 'player';
        unset($_SESSION['reacao_defensiva_player']);
        unset($_SESSION['reacao_defensiva_npc']);
        unset($_SESSION['postura_defensiva_player']);
        unset($_SESSION['postura_defensiva_npc']);
        unset($_SESSION['iniciativa_definida']);
        unset($_SESSION['iniciativa_combate']);
        unset($_SESSION['combate_stats']);
    }

    public function iniciativaJaDefinida(): bool
    {
        return !empty($_SESSION['iniciativa_definida']);
    }

    public function prepararInicioCombate(int $npcId): void
    {
        $this->iniciarStatsCombate($npcId);

        if ($this->iniciativaJaDefinida()) {
            return;
        }

        $player = $this->getPlayer();
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return;
        }

        $resultado = $this->combate->disputarIniciativa($player, $npc);

        $_SESSION['iniciativa_definida'] = true;
        $_SESSION['turno_combate'] = $resultado['primeiro'];
        $_SESSION['iniciativa_combate'] = $resultado;

        $this->registrarLog($resultado['mensagem']);
    }

    private function salvarReacaoDefensiva(string $alvo, string $tipo): void
    {
        if ($alvo === 'player') {
            $_SESSION['reacao_defensiva_player'] = $tipo;
            return;
        }

        if ($alvo === 'npc') {
            $_SESSION['reacao_defensiva_npc'] = $tipo;
        }
    }

    private function obterReacaoDefensiva(string $alvo): ?array
    {
        if ($alvo === 'player' && !empty($_SESSION['reacao_defensiva_player'])) {
            return ['tipo' => $_SESSION['reacao_defensiva_player']];
        }

        if ($alvo === 'npc' && !empty($_SESSION['reacao_defensiva_npc'])) {
            return ['tipo' => $_SESSION['reacao_defensiva_npc']];
        }

        return null;
    }

    private function consumirReacaoDefensiva(string $alvo): void
    {
        if ($alvo === 'player') {
            unset($_SESSION['reacao_defensiva_player']);
            return;
        }

        if ($alvo === 'npc') {
            unset($_SESSION['reacao_defensiva_npc']);
        }
    }

    private function ativarPosturaDefensiva(string $alvo): void
    {
        if ($alvo === 'player') {
            $_SESSION['postura_defensiva_player'] = true;
            return;
        }

        if ($alvo === 'npc') {
            $_SESSION['postura_defensiva_npc'] = true;
        }
    }

    private function possuiPosturaDefensiva(string $alvo): bool
    {
        if ($alvo === 'player') {
            return !empty($_SESSION['postura_defensiva_player']);
        }

        if ($alvo === 'npc') {
            return !empty($_SESSION['postura_defensiva_npc']);
        }

        return false;
    }

    private function consumirPosturaDefensiva(string $alvo): void
    {
        if ($alvo === 'player') {
            unset($_SESSION['postura_defensiva_player']);
            return;
        }

        if ($alvo === 'npc') {
            unset($_SESSION['postura_defensiva_npc']);
        }
    }

    private function processarEstadoInicioTurno(int $personagemId, string $rotulo = 'Personagem'): array
    {
        $personagem = $this->combate->buscarPersonagemPorId($personagemId);

        if (!$personagem) {
            return [
                'houve_evento' => true,
                'bloqueia_acao' => true,
                'mensagem' => $rotulo . ' não encontrado.'
            ];
        }

        if (!empty($personagem['morrendo'])) {
            $resultado = $this->combate->processarMorrendo($personagemId);
            $personagemAtualizado = $this->combate->buscarPersonagemPorId($personagemId);

            $bloqueiaAcao = true;

            if ($personagemAtualizado && empty($personagemAtualizado['morto']) && empty($personagemAtualizado['inconsciente']) && empty($personagemAtualizado['morrendo'])) {
                $bloqueiaAcao = false;
            }

            return [
                'houve_evento' => true,
                'bloqueia_acao' => $bloqueiaAcao,
                'mensagem' => $resultado['mensagem'] ?? ($rotulo . ' teve o estado processado.')
            ];
        }

        return [
            'houve_evento' => false,
            'bloqueia_acao' => false,
            'mensagem' => null
        ];
    }

    private function decidirAcaoNpc(array $npc): array
    {
        $vidaAtual = max(0, (int)($npc['vida_atual'] ?? 0));
        $vidaMax = max(1, (int)($npc['vida_max'] ?? 1));
        $percentualVida = ($vidaAtual / $vidaMax) * 100;

        $rolagem = rand(1, 100);

        if ($percentualVida <= 30) {
            if ($rolagem <= 30) {
                return ['tipo' => 'ataque'];
            }

            if ($rolagem <= 55) {
                return ['tipo' => 'postura'];
            }

            if ($rolagem <= 75) {
                return ['tipo' => 'defesa', 'modo' => 'contra_ataque'];
            }

            return [
                'tipo' => 'defesa',
                'modo' => rand(0, 1) === 0 ? 'bloqueio' : 'esquiva'
            ];
        }

        if ($rolagem <= 55) {
            return ['tipo' => 'ataque'];
        }

        if ($rolagem <= 75) {
            return ['tipo' => 'postura'];
        }

        if ($rolagem <= 85) {
            return ['tipo' => 'defesa', 'modo' => 'contra_ataque'];
        }

        return [
            'tipo' => 'defesa',
            'modo' => rand(0, 1) === 0 ? 'bloqueio' : 'esquiva'
        ];
    }

    private function personagemEstaForaDeCombate(array $personagem): bool
    {
        if (!empty($personagem['morto'])) {
            return true;
        }

        if (!empty($personagem['inconsciente'])) {
            return true;
        }

        if (!empty($personagem['morrendo'])) {
            return true;
        }

        return false;
    }

    private function descreverEstadoFinalCombate(array $personagem): string
    {
        if (!empty($personagem['morto'])) {
            return 'morto';
        }

        if (!empty($personagem['morrendo'])) {
            return 'em estado de morte';
        }

        if (!empty($personagem['inconsciente'])) {
            return 'inconsciente';
        }

        return 'fora de combate';
    }

    private function getDesfechoCombate(): DesfechoCombate
    {
        return new DesfechoCombate($this->pdo);
    }

    private function iniciarPosCombate(int $npcId): void
    {
        $_SESSION['pos_combate'] = [
            'npc_id' => $npcId,
            'status' => 'aguardando_decisao'
        ];
    }

    private function limparPosCombate(): void
    {
        unset($_SESSION['pos_combate']);
    }

    public function getPosCombateAtual(): ?array
    {
        $posCombate = $_SESSION['pos_combate'] ?? null;

        return is_array($posCombate) ? $posCombate : null;
    }

    public function existePosCombatePendente(): bool
    {
        $posCombate = $this->getPosCombateAtual();

        if (!$posCombate) {
            return false;
        }

        return !empty($posCombate['npc_id']) && ($posCombate['status'] ?? '') === 'aguardando_decisao';
    }

    public function resolverPosCombate(string $acao): string
    {
        $posCombate = $this->getPosCombateAtual();

        if (!$posCombate || empty($posCombate['npc_id'])) {
            return 'Não existe desfecho pós-combate pendente.';
        }

        $npcId = (int)$posCombate['npc_id'];

        $player = $this->getPlayer();
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            $this->limparPosCombate();
            return 'NPC do pós-combate não encontrado.';
        }

        $desfecho = $this->getDesfechoCombate();

        if (!$desfecho->existeDesfechoPendente($npc)) {
            $this->limparPosCombate();
            return 'Esse NPC não está mais em estado válido de pós-combate.';
        }

        $acao = trim(mb_strtolower($acao));

        if ($acao === 'poupar') {
            $resultado = $desfecho->pouparNpc($player, $npc);
        } elseif ($acao === 'executar') {
            $resultado = $desfecho->executarNpc($player, $npc);
        } else {
            return 'Ação de pós-combate inválida. Use /pos poupar ou /pos executar.';
        }

        if (!empty($resultado['mensagem'])) {
            $this->registrarLog($resultado['mensagem']);
        }

        $this->limparPosCombate();

        return $resultado['mensagem'] ?? 'Desfecho resolvido.';
    }

    private function encerrarCombateSeNecessario(int $npcId, array &$mensagens): bool
    {
        $player = $this->getPlayer();
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            $this->resetarTurnoCombate();
            unset($_SESSION['combate_npc_id']);
            return true;
        }

        if ($this->personagemEstaForaDeCombate($npc)) {
            $estadoNpc = $this->descreverEstadoFinalCombate($npc);

            $mensagem = $npc['nome'] . ' foi derrotado e ficou ' . $estadoNpc . '. Use /pos poupar ou /pos executar.';
            $mensagens[] = $mensagem;
            $this->registrarLog($mensagem);

            $this->limparCombateSeNpcDerrotado($npcId);
            return true;
        }

        if ($this->personagemEstaForaDeCombate($player)) {
            $estadoPlayer = $this->descreverEstadoFinalCombate($player);

            $mensagem = $player['nome'] . ' foi derrotado e ficou ' . $estadoPlayer . '.';
            $mensagens[] = $mensagem;
            $this->registrarLog($mensagem);

            $this->resetarTurnoCombate();
            unset($_SESSION['combate_npc_id']);
            $this->limparPosCombate();
            return true;
        }

        return false;
    }

    private function executarTurnoNpc(int $npcId): array
    {
        $mensagens = [];

        $npc = $this->getNpc($npcId);
        $player = $this->getPlayer();

        if (!$npc) {
            $mensagem = 'NPC de combate não encontrado.';
            $this->registrarLog($mensagem);
            return [$mensagem];
        }

        $inicioTurnoNpc = $this->processarEstadoInicioTurno($npcId, $npc['nome']);
        if ($inicioTurnoNpc['houve_evento']) {
            $mensagens[] = $inicioTurnoNpc['mensagem'];
            $this->registrarLog($inicioTurnoNpc['mensagem']);
        }

        $npc = $this->getNpc($npcId);

        if (!$npc) {
            $this->definirTurno('player');
            return $mensagens;
        }

        if ($this->personagemEstaForaDeCombate($npc)) {
            $this->encerrarCombateSeNecessario($npcId, $mensagens);
            return $mensagens;
        }

        $podeAgirNpc = $this->combate->podeAgir($npc);
        if (!$podeAgirNpc['pode_agir']) {
            $mensagens[] = $podeAgirNpc['mensagem'];
            $this->registrarLog($podeAgirNpc['mensagem']);
            $this->definirTurno('player');
            return $mensagens;
        }

        $acaoNpc = $this->decidirAcaoNpc($npc);

        if ($acaoNpc['tipo'] === 'ataque') {
            $reacaoPlayer = $this->obterReacaoDefensiva('player');
            if ($reacaoPlayer) {
                $player['reacao_defensiva'] = $reacaoPlayer;
            }

            if ($this->possuiPosturaDefensiva('player')) {
                $player['postura_defensiva'] = true;
            }

            $this->consumirPosturaDefensiva('npc');

            $resultadoNpc = $this->combate->rolarAtaqueNpc($npc, $player);

            $this->consumirReacaoDefensiva('player');
            $this->consumirPosturaDefensiva('player');

            $mensagens[] = $resultadoNpc['mensagem'];
            $this->registrarLog($resultadoNpc['mensagem']);

            if (!empty($resultadoNpc['dano'])) {
                $this->registrarDanoRecebidoPeloPlayer((int)$resultadoNpc['dano']);
            }

            if (method_exists($this->player, 'recarregar')) {
                $this->player->recarregar();
            }

            $this->marcarPlayerEntrouCriticoSeNecessario();

            if ($this->encerrarCombateSeNecessario($npcId, $mensagens)) {
                return $mensagens;
            }
        } elseif ($acaoNpc['tipo'] === 'postura') {
            $this->ativarPosturaDefensiva('npc');
            $this->consumirReacaoDefensiva('npc');

            $mensagem = $npc['nome'] . ' entrou em postura defensiva.';
            $mensagens[] = $mensagem;
            $this->registrarLog($mensagem);
        } else {
            $modo = $acaoNpc['modo'] ?? 'bloqueio';

            if ($modo === 'contra_ataque') {
                $npc['reacao_defensiva'] = ['tipo' => 'contra_ataque'];
                $this->salvarReacaoDefensiva('npc', 'contra_ataque');
                $this->consumirPosturaDefensiva('npc');

                $mensagem = $npc['nome'] . ' preparou um contra-ataque.';
                $mensagens[] = $mensagem;
                $this->registrarLog($mensagem);
            } elseif ($modo === 'bloqueio') {
                $npc['reacao_defensiva'] = ['tipo' => 'bloqueio'];
                $this->salvarReacaoDefensiva('npc', 'bloqueio');
                $this->consumirPosturaDefensiva('npc');

                $resultadoNpc = $this->combate->assumirBloqueio($npc);
                $mensagens[] = $resultadoNpc['mensagem'];
                $this->registrarLog($resultadoNpc['mensagem']);
            } else {
                $npc['reacao_defensiva'] = ['tipo' => 'esquiva'];
                $this->salvarReacaoDefensiva('npc', 'esquiva');
                $this->consumirPosturaDefensiva('npc');

                $resultadoNpc = $this->combate->assumirEsquiva($npc);
                $mensagens[] = $resultadoNpc['mensagem'];
                $this->registrarLog($resultadoNpc['mensagem']);
            }
        }

        $this->definirTurno('player');

        return $mensagens;
    }

    public function avancarTurnoNpc(int $npcId): string
    {
        $mensagens = [];

        if ($this->getTurnoAtual() !== 'npc') {
            $mensagem = 'Ainda não é o turno do inimigo.';
            $this->registrarLog($mensagem);
            return $mensagem;
        }

        $mensagensNpc = $this->executarTurnoNpc($npcId);

        foreach ($mensagensNpc as $mensagemNpc) {
            $mensagens[] = $mensagemNpc;
        }

        return implode(' ', array_filter($mensagens));
    }

    public function processarAcao(?string $acaoBruta, int $npcId): string
    {
        $acaoBruta = trim($acaoBruta ?? '');
        $this->iniciarStatsCombate($npcId);

        if ($acaoBruta === '') {
            $mensagem = 'Nenhuma ação foi enviada.';
            $this->registrarLog($mensagem);
            return $mensagem;
        }

        if (stripos($acaoBruta, '/pos') === 0) {
            $acaoPos = trim(preg_replace('/^\/pos\s*/i', '', $acaoBruta));
            return $this->resolverPosCombate($acaoPos);
        }

        if ($this->existePosCombatePendente() && stripos($acaoBruta, '/pos') !== 0) {
            return 'Existe um desfecho pós-combate pendente. Use /pos poupar ou /pos executar.';
        }

        $player = $this->getPlayer();
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            $mensagem = 'NPC de combate não encontrado.';
            $this->registrarLog($mensagem);
            return $mensagem;
        }

        if (stripos($acaoBruta, '/debug') === 0) {
            $mensagem = $this->processarDebugCombate($acaoBruta, $player, $npcId);
            $this->registrarLog($mensagem);
            return $mensagem;
        }

        if (stripos($acaoBruta, '/turno') === 0) {
            $mensagem = $this->avancarTurnoNpc($npcId);
            return $mensagem;
        }

        $mensagens = [];

        if ($this->getTurnoAtual() !== 'player') {
            $mensagem = 'Não é o turno do jogador.';
            $mensagens[] = $mensagem;
            $this->registrarLog($mensagem);
            return implode(' ', array_filter($mensagens));
        }

        $inicioTurnoPlayer = $this->processarEstadoInicioTurno($this->player->getId(), $player['nome']);
        if ($inicioTurnoPlayer['houve_evento']) {
            $mensagens[] = $inicioTurnoPlayer['mensagem'];
            $this->registrarLog($inicioTurnoPlayer['mensagem']);
        }

        if (method_exists($this->player, 'recarregar')) {
            $this->player->recarregar();
        }

        $player = $this->getPlayer();

        if ($inicioTurnoPlayer['bloqueia_acao']) {
            return implode(' ', array_filter($mensagens));
        }

        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return implode(' ', array_filter($mensagens));
        }

        if ($this->personagemEstaForaDeCombate($npc)) {
            $this->encerrarCombateSeNecessario($npcId, $mensagens);
            return implode(' ', array_filter($mensagens));
        }

        $podeAgirPlayer = $this->combate->podeAgir($player);
        if (!$podeAgirPlayer['pode_agir']) {
            $mensagens[] = $podeAgirPlayer['mensagem'];
            $this->registrarLog($podeAgirPlayer['mensagem']);
            return implode(' ', array_filter($mensagens));
        }

        $playerAtualizadoParaDefesa = $player;

        if (stripos($acaoBruta, '/atk') === 0) {
            $reacaoNpc = $this->obterReacaoDefensiva('npc');
            if ($reacaoNpc) {
                $npc['reacao_defensiva'] = $reacaoNpc;
            }

            if ($this->possuiPosturaDefensiva('npc')) {
                $npc['postura_defensiva'] = true;
            }

            $this->consumirPosturaDefensiva('player');

            $resultadoPlayer = $this->combate->rolarAtaqueFisico($player, $npc);

            $this->consumirReacaoDefensiva('npc');
            $this->consumirPosturaDefensiva('npc');

            $mensagens[] = $resultadoPlayer['mensagem'];
            $this->registrarLog($resultadoPlayer['mensagem']);

            if ($this->encerrarCombateSeNecessario($npcId, $mensagens)) {
                return implode(' ', array_filter($mensagens));
            }
        } elseif (stripos($acaoBruta, '/def') === 0) {
            if (
                stripos($acaoBruta, 'postura') !== false ||
                stripos($acaoBruta, 'defensiva') !== false
            ) {
                $this->ativarPosturaDefensiva('player');
                $this->consumirReacaoDefensiva('player');

                $mensagem = $player['nome'] . ' entrou em postura defensiva.';
                $mensagens[] = $mensagem;
                $this->registrarLog($mensagem);
            } elseif (
                stripos($acaoBruta, 'contra-ataque') !== false ||
                stripos($acaoBruta, 'contraataque') !== false ||
                stripos($acaoBruta, 'counter') !== false
            ) {
                $playerAtualizadoParaDefesa['reacao_defensiva'] = [
                    'tipo' => 'contra_ataque'
                ];
                $this->salvarReacaoDefensiva('player', 'contra_ataque');
                $this->consumirPosturaDefensiva('player');

                $mensagem = $player['nome'] . ' preparou um contra-ataque.';
                $mensagens[] = $mensagem;
                $this->registrarLog($mensagem);
            } elseif (
                stripos($acaoBruta, 'bloqueio') !== false ||
                stripos($acaoBruta, 'block') !== false
            ) {
                $playerAtualizadoParaDefesa['reacao_defensiva'] = [
                    'tipo' => 'bloqueio'
                ];
                $this->salvarReacaoDefensiva('player', 'bloqueio');
                $this->consumirPosturaDefensiva('player');

                $resultadoPlayer = $this->combate->assumirBloqueio($playerAtualizadoParaDefesa);
                $mensagens[] = $resultadoPlayer['mensagem'];
                $this->registrarLog($resultadoPlayer['mensagem']);
            } elseif (
                stripos($acaoBruta, 'esquiva') !== false ||
                stripos($acaoBruta, 'dodge') !== false
            ) {
                $playerAtualizadoParaDefesa['reacao_defensiva'] = [
                    'tipo' => 'esquiva'
                ];
                $this->salvarReacaoDefensiva('player', 'esquiva');
                $this->consumirPosturaDefensiva('player');

                $resultadoPlayer = $this->combate->assumirEsquiva($playerAtualizadoParaDefesa);
                $mensagens[] = $resultadoPlayer['mensagem'];
                $this->registrarLog($resultadoPlayer['mensagem']);
            } else {
                $mensagem = 'Use /def postura, /def bloqueio, /def esquiva ou /def contra-ataque.';
                $mensagens[] = $mensagem;
                $this->registrarLog($mensagem);
                return implode(' ', array_filter($mensagens));
            }
        } else {
            $mensagem = 'Ação inválida no combate. Use /atk, /def bloqueio, /def esquiva, /pos ou /debug.';
            $mensagens[] = $mensagem;
            $this->registrarLog($mensagem);
            return implode(' ', array_filter($mensagens));
        }

        if (method_exists($this->player, 'recarregar')) {
            $this->player->recarregar();
        }

        $this->marcarPlayerEntrouCriticoSeNecessario();

        $this->definirTurno('npc');
        $mensagemTurno = 'Turno do inimigo.';
        $mensagens[] = $mensagemTurno;
        $this->registrarLog($mensagemTurno);

        return implode(' ', array_filter($mensagens));
    }

    private function processarDebugCombate(string $acaoBruta, array $player, int $npcId): string
    {
        $comando = trim(preg_replace('/^\/debug\s*/i', '', $acaoBruta));

        if ($comando === '') {
            return 'Use /debug estado, /debug dano 30, /debug cura 20, /debug turno, /debug danonpc 30, /debug curanpc 20, /debug turnonpc ou /debug revivenpc.';
        }

        if (preg_match('/^estado$/i', $comando)) {
            return $this->debugEstadoCombate($player, $npcId);
        }

        if (preg_match('/^dano\s+(\d+)$/i', $comando, $matches)) {
            return $this->debugDanoPlayer((int)$matches[1]);
        }

        if (preg_match('/^cura\s+(\d+)$/i', $comando, $matches)) {
            return $this->debugCuraPlayer((int)$matches[1]);
        }

        if (preg_match('/^turno$/i', $comando)) {
            return $this->debugTurnoPlayer();
        }

        if (preg_match('/^danonpc\s+(\d+)$/i', $comando, $matches)) {
            return $this->debugDanoNpc($npcId, (int)$matches[1]);
        }

        if (preg_match('/^curanpc\s+(\d+)$/i', $comando, $matches)) {
            return $this->debugCuraNpc($npcId, (int)$matches[1]);
        }

        if (preg_match('/^turnonpc$/i', $comando)) {
            return $this->debugTurnoNpc($npcId);
        }

        if (preg_match('/^revivenpc$/i', $comando)) {
            return $this->debugReviveNpc($npcId);
        }

        return 'Debug não reconhecido. Use /debug estado, /debug dano 30, /debug cura 20, /debug turno, /debug danonpc 30, /debug curanpc 20, /debug turnonpc ou /debug revivenpc.';
    }

    private function debugEstadoCombate(array $player, int $npcId): string
    {
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return 'DEBUG | NPC não encontrado.';
        }

        return
            'DEBUG ESTADO | PLAYER ' . $player['nome'] .
            ' | Vida: ' . (int)$player['vida_atual'] . '/' . (int)$player['vida_max'] .
            ' | Estado: ' . ($player['estado_vida'] ?? 'saudavel') .
            ' | Inconsciente: ' . (int)($player['inconsciente'] ?? 0) .
            ' | Morrendo: ' . (int)($player['morrendo'] ?? 0) .
            ' | Morto: ' . (int)($player['morto'] ?? 0) .
            ' | Turnos: ' . (int)($player['turnos_restantes_morte'] ?? 0) .
            ' | Turno atual: ' . $this->getTurnoAtual() .
            ' || NPC ' . $npc['nome'] .
            ' | Vida: ' . (int)$npc['vida_atual'] . '/' . (int)$npc['vida_max'] .
            ' | Estado: ' . ($npc['estado_vida'] ?? 'saudavel') .
            ' | Inconsciente: ' . (int)($npc['inconsciente'] ?? 0) .
            ' | Morrendo: ' . (int)($npc['morrendo'] ?? 0) .
            ' | Morto: ' . (int)($npc['morto'] ?? 0) .
            ' | Turnos: ' . (int)($npc['turnos_restantes_morte'] ?? 0);
    }

    private function debugDanoPlayer(int $dano): string
    {
        if ($dano <= 0) {
            return 'DEBUG | Informe um dano maior que 0.';
        }

        $resultado = $this->combate->aplicarDanoDebug($this->player->getId(), $dano, 'debug');

        if (method_exists($this->player, 'recarregar')) {
            $this->player->recarregar();
        }

        $this->registrarDanoRecebidoPeloPlayer($dano);
        $this->marcarPlayerEntrouCriticoSeNecessario();

        $mensagem = 'DEBUG | Player sofreu ' . $dano . ' de dano.';

        if (!empty($resultado['morte_instantanea'])) {
            $mensagem .= ' Morte instantânea.';
        } elseif (!empty($resultado['entrou_morrendo'])) {
            $mensagem .= ' Entrou em morrendo com ' . (int)($resultado['turnos_restantes_morte'] ?? 0) . ' turnos restantes.';
        } else {
            $mensagem .= ' Vida atual: ' . (int)($resultado['vida_atual'] ?? 0) . '.';
        }

        return $mensagem;
    }

    private function debugCuraPlayer(int $cura): string
    {
        if ($cura <= 0) {
            return 'DEBUG | Informe uma cura maior que 0.';
        }

        $dados = $this->player->getDados();

        if ((int)($dados['morto'] ?? 0) === 1) {
            return 'DEBUG | Não é possível curar um player morto com debug de cura comum.';
        }

        $vidaAtual = (int)($dados['vida_atual'] ?? 0);
        $vidaMax = (int)($dados['vida_max'] ?? 0);
        $novaVida = min($vidaAtual + $cura, $vidaMax);

        $stmt = $this->pdo->prepare("
            UPDATE personagens
            SET vida_atual = ?
            WHERE id = ?
        ");
        $stmt->execute([$novaVida, $this->player->getId()]);

        if ($novaVida > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET inconsciente = 0,
                    morrendo = 0,
                    turnos_restantes_morte = 0,
                    causa_morte = NULL
                WHERE id = ?
            ");
            $stmt->execute([$this->player->getId()]);
        }

        $this->combate->recalcularEstadoVidaPublico($this->player->getId());

        if (method_exists($this->player, 'recarregar')) {
            $this->player->recarregar();
        }

        return 'DEBUG | Player recuperou ' . ($novaVida - $vidaAtual) . ' de vida.';
    }

    private function debugTurnoPlayer(): string
    {
        $resultado = $this->combate->processarMorrendo($this->player->getId());

        if (method_exists($this->player, 'recarregar')) {
            $this->player->recarregar();
        }

        $this->marcarPlayerEntrouCriticoSeNecessario();

        return 'DEBUG | ' . ($resultado['mensagem'] ?? 'Turno do player processado.');
    }

    private function debugDanoNpc(int $npcId, int $dano): string
    {
        if ($dano <= 0) {
            return 'DEBUG | Informe um dano maior que 0.';
        }

        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return 'DEBUG | NPC não encontrado.';
        }

        $resultado = $this->combate->aplicarDanoDebug($npcId, $dano, 'debug npc');

        $mensagem = 'DEBUG | NPC sofreu ' . $dano . ' de dano.';

        if (!empty($resultado['morte_instantanea'])) {
            $mensagem .= ' Morte instantânea.';
        } elseif (!empty($resultado['entrou_morrendo'])) {
            $mensagem .= ' Entrou em morrendo com ' . (int)($resultado['turnos_restantes_morte'] ?? 0) . ' turnos restantes.';
        } else {
            $mensagem .= ' Vida atual: ' . (int)($resultado['vida_atual'] ?? 0) . '.';
        }

        return $mensagem;
    }

    private function debugCuraNpc(int $npcId, int $cura): string
    {
        if ($cura <= 0) {
            return 'DEBUG | Informe uma cura maior que 0.';
        }

        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return 'DEBUG | NPC não encontrado.';
        }

        if ((int)($npc['morto'] ?? 0) === 1) {
            return 'DEBUG | Não é possível curar um NPC morto com debug de cura comum.';
        }

        $vidaAtual = (int)($npc['vida_atual'] ?? 0);
        $vidaMax = (int)($npc['vida_max'] ?? 0);
        $novaVida = min($vidaAtual + $cura, $vidaMax);

        $stmt = $this->pdo->prepare("
            UPDATE personagens
            SET vida_atual = ?
            WHERE id = ?
        ");
        $stmt->execute([$novaVida, $npcId]);

        if ($novaVida > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET inconsciente = 0,
                    morrendo = 0,
                    turnos_restantes_morte = 0,
                    causa_morte = NULL
                WHERE id = ?
            ");
            $stmt->execute([$npcId]);
        }

        $this->combate->recalcularEstadoVidaPublico($npcId);

        return 'DEBUG | NPC recuperou ' . ($novaVida - $vidaAtual) . ' de vida.';
    }

    private function debugTurnoNpc(int $npcId): string
    {
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return 'DEBUG | NPC não encontrado.';
        }

        $resultado = $this->combate->processarMorrendo($npcId);

        return 'DEBUG | ' . ($resultado['mensagem'] ?? 'Turno do NPC processado.');
    }

    private function debugReviveNpc(int $npcId): string
    {
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return 'DEBUG | NPC não encontrado.';
        }

        $stmt = $this->pdo->prepare("
            UPDATE personagens
            SET vida_atual = vida_max,
                estado_vida = 'saudavel',
                inconsciente = 0,
                morrendo = 0,
                morto = 0,
                turnos_restantes_morte = 0,
                causa_morte = NULL
            WHERE id = ?
        ");
        $stmt->execute([$npcId]);

        $this->resetarTurnoCombate();
        $this->limparPosCombate();

        return 'DEBUG | NPC revivido/resetado para teste com vida cheia.';
    }

    private function getEvento(): Evento
    {
        return new Evento($this->pdo);
    }

    public function buscarLogsRecentes(int $limite = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM memoria
            WHERE tipo_memoria = 'combate'
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function limparCombateSeNpcDerrotado(int $npcId): void
    {
        $npc = $this->getNpc($npcId);

        if (!$npc) {
            return;
        }

        if ($this->personagemEstaForaDeCombate($npc)) {
            $player = $this->getPlayer();
            $estadoFinal = $this->descreverEstadoFinalCombate($npc);

            try {
                $this->getEvento()->registrarSeNaoExistir([
                    'tipo_evento'    => 'combate',
                    'subtipo_evento' => 'npc_derrotado',
                    'personagem_id'  => (int)$player['id'],
                    'npc_id'         => (int)$npc['id'],
                    'local_id'       => (int)($player['local_atual_id'] ?? 0),
                    'titulo'         => 'Inimigo derrotado',
                    'descricao'      => $player['nome'] . ' derrotou ' . $npc['nome'] . ' em combate.',
                    'dados_json'     => [
                        'npc_nome' => $npc['nome'],
                        'estado_final' => $estadoFinal,
                        'causa_morte' => $npc['causa_morte'] ?? null
                    ],
                    'ativo'          => 1
                ]);
            } catch (Throwable $e) {
                // não quebra o fluxo principal
            }

            try {
                $this->processarObservacaoNpcAoFimDoCombate();
            } catch (Throwable $e) {
                // não quebra o fluxo principal
            }

            $this->resetarTurnoCombate();
            $this->iniciarPosCombate($npcId);
            unset($_SESSION['combate_npc_id']);
        }
    }

    private function registrarLog(string $mensagem): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO memoria (tipo_memoria, conteudo, turno, data_criacao)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            'combate',
            $mensagem,
            0
        ]);
    }
}
