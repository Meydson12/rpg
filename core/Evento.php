<?php

class Evento
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function registrarEventoMundo(array $dados): bool
    {
        if (
            empty($dados['tipo_evento']) ||
            empty($dados['subtipo_evento']) ||
            empty($dados['titulo'])
        ) {
            return false;
        }

        $sql = "INSERT INTO eventos_mundo (
                    tipo_evento,
                    subtipo_evento,
                    personagem_id,
                    npc_id,
                    local_id,
                    referencia_id,
                    titulo,
                    descricao,
                    dados_json,
                    turno,
                    ativo
                ) VALUES (
                    :tipo_evento,
                    :subtipo_evento,
                    :personagem_id,
                    :npc_id,
                    :local_id,
                    :referencia_id,
                    :titulo,
                    :descricao,
                    :dados_json,
                    :turno,
                    :ativo
                )";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            ':tipo_evento'      => $dados['tipo_evento'] ?? null,
            ':subtipo_evento'   => $dados['subtipo_evento'] ?? null,
            ':personagem_id'    => $dados['personagem_id'] ?? null,
            ':npc_id'           => $dados['npc_id'] ?? null,
            ':local_id'         => $dados['local_id'] ?? null,
            ':referencia_id'    => $dados['referencia_id'] ?? null,
            ':titulo'           => $dados['titulo'] ?? '',
            ':descricao'        => $dados['descricao'] ?? null,
            ':dados_json'       => isset($dados['dados_json'])
                ? json_encode($dados['dados_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            ':turno'            => $dados['turno'] ?? null,
            ':ativo'            => $dados['ativo'] ?? 1,
        ]);
    }

    public function jaAconteceu(
        string $tipoEvento,
        string $subtipoEvento,
        ?int $personagemId = null,
        ?int $npcId = null,
        ?int $localId = null,
        ?int $referenciaId = null
    ): bool {
        $sql = "SELECT id
                FROM eventos_mundo
                WHERE tipo_evento = :tipo_evento
                  AND subtipo_evento = :subtipo_evento";

        $params = [
            ':tipo_evento' => $tipoEvento,
            ':subtipo_evento' => $subtipoEvento,
        ];

        if ($personagemId !== null) {
            $sql .= " AND personagem_id = :personagem_id";
            $params[':personagem_id'] = $personagemId;
        }

        if ($npcId !== null) {
            $sql .= " AND npc_id = :npc_id";
            $params[':npc_id'] = $npcId;
        }

        if ($localId !== null) {
            $sql .= " AND local_id = :local_id";
            $params[':local_id'] = $localId;
        }

        if ($referenciaId !== null) {
            $sql .= " AND referencia_id = :referencia_id";
            $params[':referencia_id'] = $referenciaId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function registrarSeNaoExistir(array $dados): bool
    {
        $jaExiste = $this->jaAconteceu(
            $dados['tipo_evento'] ?? '',
            $dados['subtipo_evento'] ?? '',
            $dados['personagem_id'] ?? null,
            $dados['npc_id'] ?? null,
            $dados['local_id'] ?? null,
            $dados['referencia_id'] ?? null
        );

        if ($jaExiste) {
            return false;
        }

        return $this->registrarEventoMundo($dados);
    }

    public function buscarEventosDoPersonagem(int $personagemId, ?string $tipoEvento = null): array
    {
        $sql = "SELECT *
                FROM eventos_mundo
                WHERE personagem_id = :personagem_id";

        $params = [
            ':personagem_id' => $personagemId
        ];

        if ($tipoEvento !== null) {
            $sql .= " AND tipo_evento = :tipo_evento";
            $params[':tipo_evento'] = $tipoEvento;
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($eventos as &$evento) {
            if (!empty($evento['dados_json'])) {
                $evento['dados_json'] = json_decode($evento['dados_json'], true);
            }
        }
        unset($evento);

        return $eventos;
    }

    public function personagemJaVisitouLocal(int $personagemId, int $localId): bool
    {
        return $this->jaAconteceu(
            'exploracao',
            'primeira_visita',
            $personagemId,
            null,
            $localId
        );
    }

    public function personagemJaConheceNpc(int $personagemId, int $npcId): bool
    {
        return $this->jaAconteceu(
            'npc',
            'primeiro_contato',
            $personagemId,
            $npcId
        );
    }

    public function personagemJaDerrotouNpc(int $personagemId, int $npcId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM eventos_mundo
            WHERE personagem_id = ?
              AND npc_id = ?
              AND tipo_evento = 'combate'
              AND (
                  subtipo_evento = 'npc_derrotado'
                  OR subtipo_evento = 'npc_poupado'
                  OR subtipo_evento = 'npc_executado'
              )
            LIMIT 1
        ");
        $stmt->execute([$personagemId, $npcId]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function personagemPossuiItemImportante(int $personagemId, int $objetoId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM eventos_mundo
            WHERE personagem_id = ?
              AND tipo_evento = 'item'
              AND (
                  subtipo_evento = 'item_importante_obtido'
                  OR subtipo_evento = 'item_importante_em_posse'
              )
              AND referencia_id = ?
            LIMIT 1
        ");
        $stmt->execute([$personagemId, $objetoId]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function npcJaReagiuAoGrimorio(int $personagemId, int $npcId): bool
    {
        return $this->jaAconteceu(
            'npc',
            'reacao_grimorio',
            $personagemId,
            $npcId
        );
    }

    public function buscarUltimoEventoEntrePersonagemENpc(
        int $personagemId,
        int $npcId,
        ?string $tipoEvento = null,
        ?string $subtipoEvento = null
    ): ?array {
        $sql = "SELECT *
                FROM eventos_mundo
                WHERE personagem_id = :personagem_id
                  AND npc_id = :npc_id";

        $params = [
            ':personagem_id' => $personagemId,
            ':npc_id' => $npcId
        ];

        if ($tipoEvento !== null) {
            $sql .= " AND tipo_evento = :tipo_evento";
            $params[':tipo_evento'] = $tipoEvento;
        }

        if ($subtipoEvento !== null) {
            $sql .= " AND subtipo_evento = :subtipo_evento";
            $params[':subtipo_evento'] = $subtipoEvento;
        }

        $sql .= " ORDER BY id DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $evento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$evento) {
            return null;
        }

        if (!empty($evento['dados_json'])) {
            $evento['dados_json'] = json_decode($evento['dados_json'], true);
        }

        return $evento;
    }

    public function npcFoiPoupadoRecentemente(int $personagemId, int $npcId): bool
    {
        $evento = $this->buscarUltimoEventoEntrePersonagemENpc(
            $personagemId,
            $npcId,
            'combate'
        );

        if (!$evento) {
            return false;
        }

        return ($evento['subtipo_evento'] ?? '') === 'npc_poupado';
    }
    public function npcJaReagiuAposPoupado(int $personagemId, int $npcId): bool
    {
        return $this->jaAconteceu(
            'npc',
            'kael_reagiu_apos_poupado',
            $personagemId,
            $npcId
        );
    }
}
