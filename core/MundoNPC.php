<?php

class MundoNPC
{
    /**
     * Conexão com o banco de dados.
     */
    private PDO $pdo;

    /**
     * ID canônico do Grimório no banco.
     */
    private const ID_GRIMORIO = 9;

    /**
     * Inicializa a classe com a conexão PDO.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Retorna uma instância da classe Evento.
     * Centraliza acesso ao sistema de eventos do mundo.
     */
    private function getEvento(): Evento
    {
        return new Evento($this->pdo);
    }

    /**
     * Normaliza texto para facilitar comparação:
     * - minúsculas
     * - remoção de acentos principais
     */
    private function normalizarTexto(string $texto): string
    {
        $texto = trim(mb_strtolower($texto));

        $mapa = [
            'á' => 'a',
            'à' => 'a',
            'ã' => 'a',
            'â' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'õ' => 'o',
            'ô' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'ç' => 'c'
        ];

        return strtr($texto, $mapa);
    }

    /**
     * Verifica se o player possui um objeto específico no inventário.
     * Usado principalmente para checar se o Grimório está em posse atual.
     */
    private function personagemPossuiObjetoNoInventario(Player $player, int $objetoId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM inventario
            WHERE personagem_id = ?
              AND objeto_id = ?
              AND quantidade > 0
            LIMIT 1
        ");
        $stmt->execute([$player->getId(), $objetoId]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Lista todos os NPCs vivos no mesmo local do player.
     */
    public function listarNpcsNoLocal(Player $player): array
    {
        $dados = $player->getDados();
        $localId = (int) ($dados['local_atual_id'] ?? 0);

        if ($localId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM personagens
            WHERE tipo = 'npc'
              AND local_atual_id = ?
              AND vida_atual > 0
            ORDER BY importancia DESC, nome ASC
        ");
        $stmt->execute([$localId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca NPC por nome no local atual, incluindo mortos.
     * Útil para respostas do tipo: “ele já morreu”.
     */
    public function buscarNpcNoLocalPorNomeIncluindoMortos(Player $player, string $nomeBusca): ?array
    {
        $dados = $player->getDados();
        $localId = (int) ($dados['local_atual_id'] ?? 0);
        $nomeBusca = trim($nomeBusca);

        if ($localId <= 0 || $nomeBusca === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM personagens
            WHERE tipo = 'npc'
              AND local_atual_id = ?
            ORDER BY importancia DESC, nome ASC
        ");
        $stmt->execute([$localId]);

        $npcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $buscaNormalizada = $this->normalizarTexto($nomeBusca);

        foreach ($npcs as $npc) {
            $nomeNormalizado = $this->normalizarTexto($npc['nome'] ?? '');

            if (str_contains($nomeNormalizado, $buscaNormalizada)) {
                return $npc;
            }
        }

        return null;
    }

    /**
     * Busca NPC vivo por nome no local atual.
     * Usado em diálogo e início de combate.
     */
    public function buscarNpcNoLocalPorNome(Player $player, string $nomeBusca): ?array
    {
        $dados = $player->getDados();
        $localId = (int) ($dados['local_atual_id'] ?? 0);
        $nomeBusca = trim($nomeBusca);

        if ($localId <= 0 || $nomeBusca === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM personagens
            WHERE tipo = 'npc'
              AND local_atual_id = ?
              AND vida_atual > 0
            ORDER BY importancia DESC, nome ASC
        ");
        $stmt->execute([$localId]);

        $npcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $buscaNormalizada = $this->normalizarTexto($nomeBusca);

        foreach ($npcs as $npc) {
            $nomeNormalizado = $this->normalizarTexto($npc['nome'] ?? '');

            if (str_contains($nomeNormalizado, $buscaNormalizada)) {
                return $npc;
            }
        }

        return null;
    }

    /**
     * Busca a relação atual entre player e NPC.
     * Se não existir, cria uma relação padrão automaticamente.
     */
    private function buscarRelacaoNpc(Player $player, int $npcId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM npc_relacoes
            WHERE personagem_id = ?
              AND npc_id = ?
            LIMIT 1
        ");
        $stmt->execute([$player->getId(), $npcId]);

        $relacao = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($relacao) {
            return $relacao;
        }

        $this->criarRelacaoNpcPadrao($player, $npcId);

        $stmt->execute([$player->getId(), $npcId]);
        $relacao = $stmt->fetch(PDO::FETCH_ASSOC);

        return $relacao ?: [
            'npc_id' => $npcId,
            'personagem_id' => $player->getId(),
            'estado' => 'neutro',
            'afinidade' => 0,
            'confianca' => 0,
            'irritacao' => 0,
        ];
    }

    /**
     * Cria uma relação padrão entre player e NPC.
     * Estado inicial sempre neutro.
     */
    private function criarRelacaoNpcPadrao(Player $player, int $npcId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO npc_relacoes (
                npc_id,
                personagem_id,
                estado,
                afinidade,
                confianca,
                irritacao,
                atualizado_em
            ) VALUES (?, ?, 'neutro', 0, 0, 0, NOW())
        ");
        $stmt->execute([$npcId, $player->getId()]);
    }

    /**
     * Deriva o estado textual da relação a partir dos valores numéricos.
     */
    private function definirEstadoRelacao(array $relacao): string
    {
        $afinidade = (int) ($relacao['afinidade'] ?? 0);
        $confianca = (int) ($relacao['confianca'] ?? 0);
        $irritacao = (int) ($relacao['irritacao'] ?? 0);

        if ($irritacao >= 15) {
            return 'hostil';
        }

        if ($confianca >= 12) {
            return 'aliado';
        }

        if ($afinidade >= 8) {
            return 'respeitoso';
        }

        if ($irritacao >= 6) {
            return 'desconfiado';
        }

        return 'neutro';
    }

    /**
     * Atualiza os valores da relação do NPC com o player
     * e recalcula o estado final.
     */
    private function atualizarRelacaoNpc(Player $player, int $npcId, array $alteracoes): void
    {
        $relacaoAtual = $this->buscarRelacaoNpc($player, $npcId);

        $afinidade = (int) ($relacaoAtual['afinidade'] ?? 0) + (int) ($alteracoes['afinidade'] ?? 0);
        $confianca = (int) ($relacaoAtual['confianca'] ?? 0) + (int) ($alteracoes['confianca'] ?? 0);
        $irritacao = (int) ($relacaoAtual['irritacao'] ?? 0) + (int) ($alteracoes['irritacao'] ?? 0);

        $afinidade = max(-100, min(100, $afinidade));
        $confianca = max(-100, min(100, $confianca));
        $irritacao = max(0, min(100, $irritacao));

        $novoEstado = $this->definirEstadoRelacao([
            'afinidade' => $afinidade,
            'confianca' => $confianca,
            'irritacao' => $irritacao,
        ]);

        $stmt = $this->pdo->prepare("
            UPDATE npc_relacoes
            SET estado = ?,
                afinidade = ?,
                confianca = ?,
                irritacao = ?,
                atualizado_em = NOW()
            WHERE personagem_id = ?
              AND npc_id = ?
        ");

        $stmt->execute([
            $novoEstado,
            $afinidade,
            $confianca,
            $irritacao,
            $player->getId(),
            $npcId
        ]);
    }

    /**
     * Verifica se um determinado subtipo de evento de relação já foi registrado.
     * Usado para impedir duplicações indevidas.
     */
    private function eventoRelacaoNpcJaRegistrado(Player $player, int $npcId, string $subtipoEvento): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM eventos_mundo
            WHERE personagem_id = ?
              AND npc_id = ?
              AND tipo_evento = ?
              AND subtipo_evento = ?
            LIMIT 1
        ");
        $stmt->execute([
            $player->getId(),
            $npcId,
            'npc_relacao',
            $subtipoEvento
        ]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Aplica mudança de relação apenas uma vez para um subtipo de evento específico.
     * Ideal para eventos únicos, como perceber grimório ou resquício.
     */
    private function aplicarRelacaoNpcUmaVez(
        Player $player,
        int $npcId,
        string $subtipoEvento,
        array $alteracoes,
        string $descricaoEvento
    ): void {
        if ($this->eventoRelacaoNpcJaRegistrado($player, $npcId, $subtipoEvento)) {
            return;
        }

        $this->atualizarRelacaoNpc($player, $npcId, $alteracoes);

        try {
            $this->getEvento()->registrarSeNaoExistir([
                'tipo_evento'    => 'npc_relacao',
                'subtipo_evento' => $subtipoEvento,
                'personagem_id'  => $player->getId(),
                'npc_id'         => $npcId,
                'local_id'       => (int) ($player->getDados()['local_atual_id'] ?? 0),
                'titulo'         => 'Mudança de relação com NPC',
                'descricao'      => $descricaoEvento,
                'dados_json'     => [
                    'alteracoes' => $alteracoes
                ],
                'ativo'          => 1
            ]);
        } catch (Throwable $e) {
            // Não quebra o fluxo se o evento falhar.
        }
    }

    /**
     * Trata o primeiro contato do player com um NPC.
     * Registra evento de descoberta e cria relação inicial.
     */
    private function processarPrimeiroContato(Player $player, array $npc): array
    {
        $nome = $player->getNome();

        try {
            $this->getEvento()->registrarSeNaoExistir([
                'tipo_evento'    => 'npc',
                'subtipo_evento' => 'primeiro_contato',
                'personagem_id'  => $player->getId(),
                'npc_id'         => (int) $npc['id'],
                'local_id'       => (int) ($npc['local_atual_id'] ?? 0),
                'titulo'         => 'Primeiro contato',
                'descricao'      => $nome . ' conheceu ' . $npc['nome'] . ' pela primeira vez.',
                'dados_json'     => [
                    'npc_nome' => $npc['nome']
                ],
                'ativo'          => 1
            ]);
        } catch (Throwable $e) {
            // Não quebra o diálogo.
        }

        $this->buscarRelacaoNpc($player, (int) $npc['id']);

        return [
            'tipo' => 'dialogo',
            'mensagem' => "{$nome} encontra {$npc['nome']} pela primeira vez."
        ];
    }

    /**
     * Busca o Kael no mesmo local do player.
     * Usado para verificar se ele está presente para observar combate e conversar.
     */
    private function buscarKaelNoLocal(Player $player): ?array
    {
        $npcs = $this->listarNpcsNoLocal($player);

        foreach ($npcs as $npc) {
            $nomeNormalizado = $this->normalizarTexto($npc['nome'] ?? '');

            if (str_contains($nomeNormalizado, 'kael')) {
                return $npc;
            }
        }

        return null;
    }

    /**
     * Avalia como o Kael percebeu o combate recém-encerrado.
     * Cada combate usa token único para não ser tratado como evento duplicado.
     */
    private function avaliarKaelAposCombate(Player $player, array $npc, array $resultadoCombate): void
    {
        $npcId = (int) ($npc['id'] ?? 0);

        if ($npcId <= 0) {
            return;
        }

        $foiVitoria = (bool) ($resultadoCombate['vitoria'] ?? false);
        $entrouCritico = (bool) ($resultadoCombate['entrou_critico'] ?? false);
        $danoRecebido = (int) ($resultadoCombate['dano_recebido'] ?? 0);
        $combateToken = trim((string) ($resultadoCombate['combate_token'] ?? ''));

        if (!$foiVitoria) {
            return;
        }

        if ($combateToken === '') {
            $combateToken = substr(md5(uniqid('cmb_', true)), 0, 12);
        }

        $teveDescontrole = $entrouCritico || $danoRecebido >= 20;

        if ($teveDescontrole) {
            $subtipoEvento = 'kael_viu_imprudencia_em_combate_' . $combateToken;

            $this->aplicarRelacaoNpcUmaVez(
                $player,
                $npcId,
                $subtipoEvento,
                [
                    'afinidade' => -1,
                    'confianca' => 0,
                    'irritacao' => 3
                ],
                'Kael observou o personagem vencer, mas de forma imprudente e descontrolada.'
            );

            return;
        }

        $subtipoEvento = 'kael_respeitou_controle_em_combate_' . $combateToken;

        $this->aplicarRelacaoNpcUmaVez(
            $player,
            $npcId,
            $subtipoEvento,
            [
                'afinidade' => 2,
                'confianca' => 1,
                'irritacao' => 0
            ],
            'Kael observou o personagem vencer com controle e competência em combate.'
        );
    }

    /**
     * Método público para NPC reagir ao fim do combate.
     * Hoje, usado principalmente para o Kael.
     */
    public function reagirAoFimDoCombate(Player $player, array $resultadoCombate): void
    {
        $kael = $this->buscarKaelNoLocal($player);

        if (!$kael) {
            return;
        }

        $this->avaliarKaelAposCombate($player, $kael, $resultadoCombate);
    }

    /**
     * Busca o último evento relevante de combate que o Kael testemunhou.
     * Serve para ele comentar no diálogo o combate mais recente.
     */
    private function buscarUltimoEventoCombateKael(Player $player, int $npcId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT subtipo_evento
            FROM eventos_mundo
            WHERE personagem_id = ?
              AND npc_id = ?
              AND tipo_evento = 'npc_relacao'
              AND (
                  subtipo_evento LIKE 'kael_viu_imprudencia_em_combate_%'
                  OR subtipo_evento LIKE 'kael_respeitou_controle_em_combate_%'
              )
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([$player->getId(), $npcId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Monta um comentário complementar do Kael sobre o último combate.
     * Não substitui o tema principal; apenas acrescenta memória expressiva.
     */
    private function montarComentarioCombateKael(?array $evento, string $estado): string
    {
        if (!$evento) {
            return '';
        }

        $subtipo = $evento['subtipo_evento'] ?? '';

        if (str_contains($subtipo, 'imprudencia')) {
            return match ($estado) {
                'hostil' => ' Vi o bastante no seu combate para saber que força sem controle continua sendo fraqueza.',
                'desconfiado' => ' E, pelo que vi no seu combate, cautela ainda não é o seu ponto forte.',
                'respeitoso' => ' Você venceu, mas daquele jeito ainda se expôs mais do que deveria.',
                'aliado' => ' Você consegue melhor do que aquilo. Não lute como se quisesse sobreviver por sorte.',
                default => ' Você se expôs demais naquele combate.',
            };
        }

        if (str_contains($subtipo, 'controle')) {
            return match ($estado) {
                'hostil' => ' Ainda assim, admito: no combate você não lutou como um amador.',
                'desconfiado' => ' Pelo menos no combate você mostrou mais controle do que eu esperava.',
                'respeitoso' => ' No combate, você mostrou disciplina. Isso conta.',
                'aliado' => ' E, no combate, você provou que sabe manter a cabeça no lugar.',
                default => ' Pelo menos você manteve o controle no combate.',
            };
        }

        return '';
    }

    /**
     * Converte o estado da relação do Kael em um nível de cooperação.
     * Esse nível define se ele ajuda, avisa ou nega apoio.
     */
    private function obterNivelCooperacaoKael(array $relacao): int
    {
        $estado = $relacao['estado'] ?? 'neutro';

        return match ($estado) {
            'hostil' => 0,
            'desconfiado' => 1,
            'neutro' => 1,
            'respeitoso' => 2,
            'aliado' => 3,
            default => 1,
        };
    }

    /**
     * Monta uma ajuda contextual do Kael baseada no nível de cooperação.
     * É o primeiro passo para transformar relação em consequência real de gameplay.
     */
    private function montarAjudaContextualKael(array $relacao, array $localAtual = []): string
    {
        $nivelCooperacao = $this->obterNivelCooperacaoKael($relacao);
        $nomeLocal = trim((string) ($localAtual['nome'] ?? ''));

        if ($nivelCooperacao <= 1) {
            return '';
        }

        if ($nivelCooperacao === 2) {
            if ($nomeLocal !== '') {
                return ' Há algo neste lugar que merece mais atenção do que parece. Fique alerta.';
            }

            return ' Há algo errado adiante. Fique atento.';
        }

        if ($nivelCooperacao >= 3) {
            if ($nomeLocal !== '') {
                return ' Não baixe a guarda aqui. Se alguma coisa acontecer, reaja rápido.';
            }

            return ' Há algo errado adiante. Se for seguir, não avance despreparado.';
        }

        return '';
    }

    private function kaelFoiPoupadoRecentemente(Player $player, int $npcId): bool
    {
        try {
            return $this->getEvento()->npcFoiPoupadoRecentemente(
                $player->getId(),
                $npcId
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    private function registrarReacaoKaelAposPoupado(Player $player, int $npcId): void
    {
        try {
            $this->getEvento()->registrarSeNaoExistir([
                'tipo_evento'    => 'npc',
                'subtipo_evento' => 'kael_reagiu_apos_poupado',
                'personagem_id'  => $player->getId(),
                'npc_id'         => $npcId,
                'local_id'       => (int)($player->getDados()['local_atual_id'] ?? 0),
                'titulo'         => 'Kael reagiu após ser poupado',
                'descricao'      => 'Kael respondeu ao fato de ter sido poupado.',
                'dados_json'     => [],
                'ativo'          => 1
            ]);
        } catch (Throwable $e) {
            // não quebra fluxo
        }
    }

    private function montarDialogoKaelAposPoupado(array $relacao): array
    {
        $estado = $relacao['estado'] ?? 'neutro';

        $mensagem = match ($estado) {
            'hostil' =>
            "Kael ainda respira com dificuldade, mas sustenta o olhar sem vacilar.\n\n" .
                "\"Não pense que isso apagou o que eu vi... nem o que você carrega.\"\n\n" .
                "Ele seca o sangue no canto da boca e continua:\n\n" .
                "\"Mas você me poupou.\"\n\n" .
                "\"Então não. Eu não vou fingir que isso não importa.\"",

            'desconfiado' =>
            "Kael pressiona o próprio ferimento e te observa.\n\n" .
                "\"Você podia ter terminado.\"\n\n" .
                "\"Ainda não sei o que pensar de você.\"",

            'respeitoso' =>
            "Kael respira fundo antes de falar.\n\n" .
                "\"Você venceu... e ainda assim escolheu não matar.\"\n\n" .
                "\"Isso diz mais do que qualquer discurso.\"",

            'aliado' =>
            "Kael mantém a postura, mesmo ferido.\n\n" .
                "\"Boa escolha.\"\n\n" .
                "\"Agora não jogue isso fora.\"",

            default =>
            "Kael ainda está de pé por puro esforço.\n\n" .
                "\"Você podia ter me matado.\"\n\n" .
                "\"Então fale. Mas saiba que eu vou lembrar disso.\""
        };

        return [
            'tipo' => 'dialogo',
            'mensagem' => $mensagem
        ];
    }

    /**
     * Processa o diálogo especial do Kael.
     *
     * Prioridade de tema:
     * 1. Reação imediata após ser poupado
     * 2. Grimório em posse
     * 3. Resquício do Grimório
     * 4. Fala normal baseada na relação
     *
     * Complementos possíveis:
     * - comentário do combate recente
     * - ajuda contextual baseada na cooperação
     * - combate forçado em caso extremo
     */
    private function processarDialogoKael(Player $player, array $npc, array $localAtual = []): ?array
    {
        $npcId = (int) $npc['id'];
        $relacao = $this->buscarRelacaoNpc($player, $npcId);

        $foiPoupadoRecentemente = $this->kaelFoiPoupadoRecentemente($player, $npcId);

        $jaReagiuAposPoupado = false;
        try {
            $jaReagiuAposPoupado = $this->getEvento()->npcJaReagiuAposPoupado(
                $player->getId(),
                $npcId
            );
        } catch (Throwable $e) {
            $jaReagiuAposPoupado = false;
        }

        if (
            $foiPoupadoRecentemente &&
            !$jaReagiuAposPoupado &&
            (int)($npc['vida_atual'] ?? 0) <= 1
        ) {
            $this->registrarReacaoKaelAposPoupado($player, $npcId);

            $this->atualizarRelacaoNpc($player, $npcId, [
                'afinidade' => 1,
                'confianca' => 1,
                'irritacao' => -1
            ]);

            $relacao = $this->buscarRelacaoNpc($player, $npcId);

            return $this->montarDialogoKaelAposPoupado($relacao);
        }

        $estaComGrimorio = false;
        $jaTeveContatoComGrimorio = false;

        try {
            $estaComGrimorio = $this->personagemPossuiObjetoNoInventario($player, self::ID_GRIMORIO);
        } catch (Throwable $e) {
            $estaComGrimorio = false;
        }

        try {
            $jaTeveContatoComGrimorio = $this->getEvento()->personagemPossuiItemImportante(
                $player->getId(),
                self::ID_GRIMORIO
            );
        } catch (Throwable $e) {
            $jaTeveContatoComGrimorio = false;
        }

        if ($estaComGrimorio) {
            $this->aplicarRelacaoNpcUmaVez(
                $player,
                $npcId,
                'kael_percebeu_grimorio_em_posse',
                [
                    'afinidade' => 2,
                    'confianca' => 0,
                    'irritacao' => 4
                ],
                'Kael percebeu que o personagem estava carregando o Grimório.'
            );
        } elseif ($jaTeveContatoComGrimorio) {
            $this->aplicarRelacaoNpcUmaVez(
                $player,
                $npcId,
                'kael_percebeu_residuo_grimorio',
                [
                    'afinidade' => 1,
                    'confianca' => 0,
                    'irritacao' => 2
                ],
                'Kael percebeu resquícios da influência do Grimório no personagem.'
            );
        }

        $relacao = $this->buscarRelacaoNpc($player, $npcId);
        $estadoRelacao = $relacao['estado'] ?? 'neutro';

        $eventoCombate = $this->buscarUltimoEventoCombateKael($player, $npcId);
        $comentarioCombate = $this->montarComentarioCombateKael($eventoCombate, $estadoRelacao);
        $ajudaContextual = $this->montarAjudaContextualKael($relacao, $localAtual);

        /**
         * Regra especial:
         * Kael hostil + player com Grimório = combate imediato.
         */
        if ($estadoRelacao === 'hostil' && $estaComGrimorio) {
            $foiPoupadoRecentemente = $this->kaelFoiPoupadoRecentemente($player, $npcId);
            $vidaNpc = (int)($npc['vida_atual'] ?? 0);

            if ($foiPoupadoRecentemente && $vidaNpc <= 1) {
                return [
                    'tipo' => 'dialogo',
                    'mensagem' =>
                    "Kael endurece o olhar no instante em que percebe o Grimório novamente.\n\n" .
                        "\"Não me faz mudar de ideia.\"\n\n" .
                        "\"Você ainda está carregando isso... então não testa minha paciência.\""
                ];
            }

            return [
                'tipo' => 'combate',
                'mensagem' => "Kael trava o maxilar e avança um passo.\n\n\"Ô seu louco... o que você pensa que está fazendo com isso nas mãos?\"\n\nKael parte para cima de você.",
                'npc_id' => $npcId
            ];
        }

        if ($estaComGrimorio) {
            switch ($estadoRelacao) {
                case 'hostil':
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael recua meio passo, mas a firmeza no olhar não cede.\n\n\"Você trouxe esse mal até aqui. Se não souber exatamente o que está fazendo, vai condenar mais do que a si mesmo.\"" . $comentarioCombate . $ajudaContextual
                    ];

                case 'desconfiado':
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael mantém os olhos fixos em você.\n\n\"Ainda carrega o grimório... então continua brincando perto demais do abismo.\"" . $comentarioCombate . $ajudaContextual
                    ];

                case 'respeitoso':
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael cruza os braços e observa você por um instante.\n\n\"Poucos suportariam carregar algo assim sem quebrar. Isso não torna sua escolha menos perigosa.\"" . $comentarioCombate . $ajudaContextual
                    ];

                case 'aliado':
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael abaixa a voz, mas não a tensão.\n\n\"Se vai insistir em carregar isso, então mantenha a cabeça fria. Poder sem controle mata mais rápido do que uma lâmina.\"" . $comentarioCombate . $ajudaContextual
                    ];

                default:
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael fica em silêncio por alguns segundos.\n\n\"Não... isso não é só um resíduo.\"\n\nO olhar dele endurece.\n\"A presença está forte em você.\"\n\n\"Você está carregando o grimório.\"" . $comentarioCombate . $ajudaContextual
                    ];
            }
        }

        if ($jaTeveContatoComGrimorio) {
            switch ($estadoRelacao) {
                case 'hostil':
                case 'desconfiado':
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael fala sem rodeios.\n\n\"O objeto pode não estar com você agora, mas o rastro ainda está aí. Quem chega perto demais dessas coisas quase nunca entende o preço.\"" . $comentarioCombate . $ajudaContextual
                    ];

                case 'respeitoso':
                case 'aliado':
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael suspira baixo.\n\n\"Você já esteve perto demais daquilo. Sobreviveu, o que já diz alguma coisa. Só não confunda sobrevivência com domínio.\"" . $comentarioCombate . $ajudaContextual
                    ];

                default:
                    return [
                        'tipo' => 'dialogo',
                        'mensagem' => "Kael observa você com atenção incomum.\n\n\"Tem algo errado em você...\"\n\n\"Você já teve contato com uma magia que não deveria.\"" . $comentarioCombate . $ajudaContextual
                    ];
            }
        }

        $dialogoBase = $this->montarDialogoNormalKael($relacao);

        if ($comentarioCombate !== '') {
            $dialogoBase['mensagem'] .= $comentarioCombate;
        }

        if ($ajudaContextual !== '') {
            $dialogoBase['mensagem'] .= $ajudaContextual;
        }

        return $dialogoBase;
    }

    /**
     * Monta a fala base do Kael de acordo com o estado atual da relação.
     */
    private function montarDialogoNormalKael(array $relacao): array
    {
        $estado = $relacao['estado'] ?? 'neutro';

        switch ($estado) {
            case 'hostil':
                return [
                    'tipo' => 'dialogo',
                    'mensagem' => "Kael não desvia o olhar.\n\n\"Se veio desperdiçar meu tempo, volte quando tiver algo que realmente importe.\""
                ];

            case 'desconfiado':
                return [
                    'tipo' => 'dialogo',
                    'mensagem' => "Kael mantém a postura rígida, atento a cada movimento seu.\n\n\"Fale. E vá direto ao ponto.\""
                ];

            case 'respeitoso':
                return [
                    'tipo' => 'dialogo',
                    'mensagem' => "Kael faz um leve aceno com a cabeça.\n\n\"Você tem minha atenção. Não me faça achar que foi um erro concedê-la.\""
                ];

            case 'aliado':
                return [
                    'tipo' => 'dialogo',
                    'mensagem' => "Kael relaxa só o bastante para não parecer uma ameaça imediata.\n\n\"Bom. Você voltou vivo. Isso já vale mais do que promessas.\""
                ];

            default:
                return [
                    'tipo' => 'dialogo',
                    'mensagem' => "Kael observa você em silêncio por um instante antes de falar.\n\n\"Fale. Mas fale algo que valha ser ouvido.\""
                ];
        }
    }

    /**
     * Processa o comando /talk:
     * - valida entrada
     * - busca NPC
     * - trata primeiro contato
     * - aplica lógica especial do Kael
     * - responde para NPC morto
     * - responde se não encontrar ninguém
     */
    public function processarTalk(Player $player, string $descricao, array $localAtual): array
    {
        $nome = $player->getNome();
        $descricao = trim($descricao);

        if ($descricao === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Diga com quem você quer falar. Exemplo: /talk Lyra'
            ];
        }

        $npc = $this->buscarNpcNoLocalPorNome($player, $descricao);

        if ($npc) {
            $jaConheceNpc = false;

            try {
                $jaConheceNpc = $this->getEvento()->personagemJaConheceNpc(
                    $player->getId(),
                    (int) $npc['id']
                );
            } catch (Throwable $e) {
                $jaConheceNpc = false;
            }

            if (!$jaConheceNpc) {
                return $this->processarPrimeiroContato($player, $npc);
            }

            $nomeNpcNormalizado = $this->normalizarTexto($npc['nome'] ?? '');
            $kaelEhEsteNpc = str_contains($nomeNpcNormalizado, 'kael');

            if ($kaelEhEsteNpc) {
                $respostaKael = $this->processarDialogoKael($player, $npc, $localAtual);

                if ($respostaKael !== null) {
                    return $respostaKael;
                }
            }

            return [
                'tipo' => 'dialogo',
                'mensagem' => "{$nome} volta a falar com {$npc['nome']}."
            ];
        }

        $npcIncluindoMortos = $this->buscarNpcNoLocalPorNomeIncluindoMortos($player, $descricao);

        if ($npcIncluindoMortos && !empty($npcIncluindoMortos['morto'])) {
            $jaDerrotouNpc = false;

            try {
                $jaDerrotouNpc = $this->getEvento()->personagemJaDerrotouNpc(
                    $player->getId(),
                    (int) $npcIncluindoMortos['id']
                );
            } catch (Throwable $e) {
                $jaDerrotouNpc = false;
            }

            if ($jaDerrotouNpc) {
                return [
                    'tipo' => 'dialogo',
                    'mensagem' => "O corpo de {$npcIncluindoMortos['nome']} está caído aqui. Não há mais resposta."
                ];
            }

            return [
                'tipo' => 'dialogo',
                'mensagem' => "{$npcIncluindoMortos['nome']} está morto e não pode responder."
            ];
        }

        return [
            'tipo' => 'dialogo',
            'mensagem' => "{$nome} tenta falar com {$descricao}, mas ninguém com esse nome está em " . ($localAtual['nome'] ?? 'local desconhecido') . "."
        ];
    }
}
