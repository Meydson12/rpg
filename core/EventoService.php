<?php

require_once __DIR__ . '/Evento.php';

class EventoService
{
    private PDO $pdo;
    private Evento $evento;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->evento = new Evento($pdo);
    }

    /**
     * Registra um evento no mundo e, se houver NPC envolvido,
     * aplica impacto na relação com base no subtipo do evento.
     */
    public function registrar(array $dados): bool
    {
        $tipo = trim((string)($dados['tipo'] ?? ''));
        $subtipo = trim((string)($dados['subtipo'] ?? ''));
        $personagemId = (int)($dados['personagem_id'] ?? 0);
        $npcId = isset($dados['npc_id']) ? (int)$dados['npc_id'] : null;
        $localId = isset($dados['local_id']) ? (int)$dados['local_id'] : null;
        $referenciaId = isset($dados['referencia_id']) ? (int)$dados['referencia_id'] : null;

        if ($tipo === '' || $subtipo === '' || $personagemId <= 0) {
            return false;
        }

        $titulo = trim((string)($dados['titulo'] ?? 'Evento do mundo'));
        $descricao = trim((string)($dados['descricao'] ?? ''));
        $dadosJson = $dados['dados'] ?? [];
        $turno = $dados['turno'] ?? null;
        $ativo = isset($dados['ativo']) ? (int)$dados['ativo'] : 1;

        $registrado = $this->evento->registrarSeNaoExistir([
            'tipo_evento' => $tipo,
            'subtipo_evento' => $subtipo,
            'personagem_id' => $personagemId,
            'npc_id' => $npcId,
            'local_id' => $localId,
            'referencia_id' => $referenciaId,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'dados_json' => $dadosJson,
            'turno' => $turno,
            'ativo' => $ativo
        ]);

        if (!$registrado) {
            return false;
        }

        if ($npcId !== null && $npcId > 0) {
            $impacto = $this->calcularImpacto($tipo, $subtipo, $dadosJson);

            if ($impacto !== null) {
                $this->aplicarImpactoRelacao(
                    $personagemId,
                    $npcId,
                    $impacto
                );
            }
        }

        return true;
    }

    /**
     * Define o impacto de determinados eventos na relação.
     * Aqui é o lugar certo para crescer no futuro.
     */
    private function calcularImpacto(string $tipo, string $subtipo, array $dados = []): ?array
    {
        return match ($subtipo) {
            'npc_poupado' => [
                'afinidade' => 1,
                'confianca' => 2,
                'irritacao' => -1
            ],

            'npc_executado' => [
                'afinidade' => -3,
                'confianca' => -4,
                'irritacao' => 5
            ],

            'npc_ajudado' => [
                'afinidade' => 3,
                'confianca' => 3,
                'irritacao' => -1
            ],

            'npc_traido' => [
                'afinidade' => -4,
                'confianca' => -5,
                'irritacao' => 6
            ],

            'npc_hostilizado' => [
                'afinidade' => -2,
                'confianca' => -2,
                'irritacao' => 3
            ],

            default => null
        };
    }

    /**
     * Busca a relação atual entre personagem e NPC.
     */
    private function buscarRelacao(int $personagemId, int $npcId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM npc_relacoes
            WHERE personagem_id = ?
              AND npc_id = ?
            LIMIT 1
        ");
        $stmt->execute([$personagemId, $npcId]);

        $relacao = $stmt->fetch(PDO::FETCH_ASSOC);

        return $relacao ?: null;
    }

    /**
     * Cria relação base caso ainda não exista.
     */
    private function criarRelacaoPadrao(int $personagemId, int $npcId): void
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
        $stmt->execute([$npcId, $personagemId]);
    }

    /**
     * Mesma lógica já usada no MundoNPC.
     */
    private function definirEstadoRelacao(array $relacao): string
    {
        $afinidade = (int)($relacao['afinidade'] ?? 0);
        $confianca = (int)($relacao['confianca'] ?? 0);
        $irritacao = (int)($relacao['irritacao'] ?? 0);

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
     * Aplica impacto numérico na relação e recalcula o estado.
     */
    private function aplicarImpactoRelacao(int $personagemId, int $npcId, array $impacto): void
    {
        $relacao = $this->buscarRelacao($personagemId, $npcId);

        if (!$relacao) {
            $this->criarRelacaoPadrao($personagemId, $npcId);
            $relacao = $this->buscarRelacao($personagemId, $npcId);
        }

        if (!$relacao) {
            return;
        }

        $afinidade = (int)($relacao['afinidade'] ?? 0) + (int)($impacto['afinidade'] ?? 0);
        $confianca = (int)($relacao['confianca'] ?? 0) + (int)($impacto['confianca'] ?? 0);
        $irritacao = (int)($relacao['irritacao'] ?? 0) + (int)($impacto['irritacao'] ?? 0);

        $afinidade = max(-100, min(100, $afinidade));
        $confianca = max(-100, min(100, $confianca));
        $irritacao = max(0, min(100, $irritacao));

        $estado = $this->definirEstadoRelacao([
            'afinidade' => $afinidade,
            'confianca' => $confianca,
            'irritacao' => $irritacao
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
            $estado,
            $afinidade,
            $confianca,
            $irritacao,
            $personagemId,
            $npcId
        ]);
    }
}
