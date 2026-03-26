<?php

class DesfechoCombate
{
    private PDO $pdo;
    private Evento $evento;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->evento = new Evento($pdo);
    }

    public function buscarPersonagemPorId(int $personagemId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM personagens
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$personagemId]);

        $personagem = $stmt->fetch(PDO::FETCH_ASSOC);

        return $personagem ?: null;
    }

    public function podeResolver(array $npc): bool
    {
        if (($npc['tipo'] ?? '') !== 'npc') {
            return false;
        }

        if (!empty($npc['morto'])) {
            return false;
        }

        return !empty($npc['inconsciente']) || !empty($npc['morrendo']);
    }

    public function existeDesfechoPendente(?array $npc): bool
    {
        if (!$npc) {
            return false;
        }

        return $this->podeResolver($npc);
    }

    public function pouparNpc(array $player, array $npc): array
    {
        $validacao = $this->validarAcaoDesfecho($player, $npc);

        if (!$validacao['ok']) {
            return $validacao;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET vida_atual = 1,
                    estado_vida = 'critico',
                    inconsciente = 0,
                    morrendo = 0,
                    morto = 0,
                    turnos_restantes_morte = 0,
                    causa_morte = NULL
                WHERE id = ?
            ");
            $stmt->execute([(int)$npc['id']]);

            $npcAtualizado = $this->buscarPersonagemPorId((int)$npc['id']);

            $this->registrarEvento(
                $player,
                $npc,
                'npc_poupado',
                'Inimigo poupado',
                $player['nome'] . ' poupou ' . $npc['nome'] . ' após derrotá-lo em combate.',
                [
                    'npc_nome' => $npc['nome'],
                    'escolha' => 'poupar',
                    'vida_final' => 1,
                    'estado_vida_final' => 'critico'
                ]
            );

            $this->pdo->commit();

            return [
                'ok' => true,
                'acao' => 'poupar',
                'npc' => $npcAtualizado,
                'mensagem' => $npc['nome'] . ' foi poupado, voltou com 1 de vida e pode responder ao que aconteceu.'
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'ok' => false,
                'acao' => 'poupar',
                'mensagem' => 'Falha ao poupar o NPC: ' . $e->getMessage()
            ];
        }
    }

    public function executarNpc(array $player, array $npc): array
    {
        $validacao = $this->validarAcaoDesfecho($player, $npc);

        if (!$validacao['ok']) {
            return $validacao;
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET vida_atual = 0,
                    estado_vida = 'morto',
                    inconsciente = 0,
                    morrendo = 0,
                    morto = 1,
                    turnos_restantes_morte = 0,
                    causa_morte = 'Executado após combate'
                WHERE id = ?
            ");
            $stmt->execute([(int)$npc['id']]);

            $npcAtualizado = $this->buscarPersonagemPorId((int)$npc['id']);

            $this->registrarEvento(
                $player,
                $npc,
                'npc_executado',
                'Inimigo executado',
                $player['nome'] . ' executou ' . $npc['nome'] . ' após derrotá-lo em combate.',
                [
                    'npc_nome' => $npc['nome'],
                    'escolha' => 'executar',
                    'vida_final' => 0,
                    'estado_vida_final' => 'morto',
                    'causa_morte' => 'Executado após combate'
                ]
            );

            $this->pdo->commit();

            return [
                'ok' => true,
                'acao' => 'executar',
                'npc' => $npcAtualizado,
                'mensagem' => $npc['nome'] . ' foi executado após o combate.'
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'ok' => false,
                'acao' => 'executar',
                'mensagem' => 'Falha ao executar o NPC: ' . $e->getMessage()
            ];
        }
    }

    private function validarAcaoDesfecho(array $player, array $npc): array
    {
        if (($player['tipo'] ?? '') !== 'player') {
            return [
                'ok' => false,
                'mensagem' => 'Personagem executor inválido.'
            ];
        }

        if (($npc['tipo'] ?? '') !== 'npc') {
            return [
                'ok' => false,
                'mensagem' => 'O alvo informado não é um NPC.'
            ];
        }

        if (!empty($npc['morto'])) {
            return [
                'ok' => false,
                'mensagem' => $npc['nome'] . ' já está morto.'
            ];
        }

        if (!$this->podeResolver($npc)) {
            return [
                'ok' => false,
                'mensagem' => $npc['nome'] . ' não está em estado de desfecho pós-combate.'
            ];
        }

        return [
            'ok' => true,
            'mensagem' => null
        ];
    }

    private function registrarEvento(
        array $player,
        array $npc,
        string $subtipoEvento,
        string $titulo,
        string $descricao,
        array $dadosJson = []
    ): void {
        try {
            $this->evento->registrarSeNaoExistir([
                'tipo_evento'    => 'combate',
                'subtipo_evento' => $subtipoEvento,
                'personagem_id'  => (int)$player['id'],
                'npc_id'         => (int)$npc['id'],
                'local_id'       => (int)($player['local_atual_id'] ?? 0),
                'titulo'         => $titulo,
                'descricao'      => $descricao,
                'dados_json'     => $dadosJson,
                'ativo'          => 1
            ]);
        } catch (Throwable $e) {
            // evento não deve quebrar o fluxo principal
        }
    }
}