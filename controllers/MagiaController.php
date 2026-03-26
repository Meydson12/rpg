<?php

class MagiaController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listarMagiasDoPersonagem(int $personagemId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM magias
            WHERE personagem_id = ?
            ORDER BY nivel ASC, nome_magia ASC
        ");
        $stmt->execute([$personagemId]);

        return $stmt->fetchAll();
    }

    public function buscarMagiaPorNome(int $personagemId, string $nomeMagia): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM magias
            WHERE personagem_id = ? AND nome_magia = ?
            LIMIT 1
        ");
        $stmt->execute([$personagemId, $nomeMagia]);
        $magia = $stmt->fetch();

        return $magia ?: null;
    }

    public function usarMagia(Player $player, string $nomeMagia): array
    {
        $magia = $this->buscarMagiaPorNome($player->getId(), $nomeMagia);

        if (!$magia) {
            return [
                'sucesso' => false,
                'mensagem' => 'Magia não encontrada para esse personagem.'
            ];
        }

        $custo = (int)$magia['custo_mana'];

        if ($player->getManaAtual() < $custo) {
            return [
                'sucesso' => false,
                'mensagem' => 'Mana insuficiente para usar essa magia.'
            ];
        }

        $novaMana = $player->getManaAtual() - $custo;
        $player->atualizarMana($novaMana);

        $novoXp = ((int)$magia['xp']) + 5;

        $stmt = $this->pdo->prepare("
            UPDATE magias
            SET xp = ?
            WHERE id = ?
        ");
        $stmt->execute([$novoXp, $magia['id']]);

        return [
            'sucesso' => true,
            'mensagem' => $player->getNome() . ' usou a magia ' . $magia['nome_magia'] . ' e gastou ' . $custo . ' de mana.'
        ];
    }
}