<?php

class EventoController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listarAtivos(): array
    {
        $stmt = $this->pdo->query("
            SELECT * 
            FROM eventos
            WHERE ativo = 1
            ORDER BY id DESC
        ");

        return $stmt->fetchAll();
    }

    public function contarEventosAtivos(): int
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) AS total
            FROM eventos
            WHERE ativo = 1
        ");
        $resultado = $stmt->fetch();

        return (int)($resultado['total'] ?? 0);
    }

    public function criarEvento(string $nome, string $tipo, int $turnoInicio, int $turnoFim, string $descricao): array
    {
        if ($this->contarEventosAtivos() >= 3) {
            return [
                'sucesso' => false,
                'mensagem' => 'Limite máximo de eventos ativos atingido.'
            ];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO eventos (nome_evento, tipo_evento, turno_inicio, turno_fim, ativo, descricao)
            VALUES (?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$nome, $tipo, $turnoInicio, $turnoFim, $descricao]);

        return [
            'sucesso' => true,
            'mensagem' => 'Evento criado com sucesso.'
        ];
    }

    public function encerrarEvento(int $eventoId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE eventos
            SET ativo = 0
            WHERE id = ?
        ");

        return $stmt->execute([$eventoId]);
    }
}