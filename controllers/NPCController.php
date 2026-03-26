<?php

class NPCController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function buscarPorId(int $npcId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM personagens
            WHERE id = ? AND tipo = 'npc'
            LIMIT 1
        ");
        $stmt->execute([$npcId]);
        $npc = $stmt->fetch();

        return $npc ?: null;
    }

    public function listarImportantes(int $limite = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM personagens
            WHERE tipo = 'npc' AND importancia >= 3
            ORDER BY importancia DESC, nivel DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function interagir(int $npcId, string $fala, Player $player): string
    {
        $npc = $this->buscarPorId($npcId);

        if (!$npc) {
            return 'NPC não encontrado.';
        }

        $fala = trim($fala);

        if ($fala === '') {
            return $player->getNome() . ' observa ' . $npc['nome'] . ', mas não diz nada.';
        }

        return $player->getNome() . ' fala com ' . $npc['nome'] . ': "' . $fala . '"';
    }
}