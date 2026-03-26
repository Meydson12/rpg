<?php

class MundoDebug
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function processar(Player $player, string $descricao): array
    {
        $descricao = trim($descricao);

        if ($descricao === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Use /debug estado, /debug dano 30, /debug cura 20 ou /debug turno'
            ];
        }

        if (preg_match('/^estado$/iu', $descricao)) {
            return $this->debugEstado($player);
        }

        if (preg_match('/^dano\s+(\d+)$/iu', $descricao, $matches)) {
            return $this->debugDano($player, (int)$matches[1]);
        }

        if (preg_match('/^cura\s+(\d+)$/iu', $descricao, $matches)) {
            return $this->debugCura($player, (int)$matches[1]);
        }

        if (preg_match('/^turno$/iu', $descricao)) {
            return $this->debugTurno($player);
        }

        return [
            'tipo' => 'erro',
            'mensagem' => 'Debug não reconhecido. Use /debug estado, /debug dano 30, /debug cura 20 ou /debug turno'
        ];
    }

    private function debugEstado(Player $player): array
    {
        $dados = $player->getDados();

        return [
            'tipo' => 'debug',
            'mensagem' =>
                'DEBUG ESTADO | ' .
                'Vida: ' . (int)($dados['vida_atual'] ?? 0) . '/' . (int)($dados['vida_max'] ?? 0) .
                ' | Mana: ' . (int)($dados['mana_atual'] ?? 0) . '/' . (int)($dados['mana_max'] ?? 0) .
                ' | Estado: ' . ($dados['estado_vida'] ?? 'saudavel') .
                ' | Inconsciente: ' . (int)($dados['inconsciente'] ?? 0) .
                ' | Morrendo: ' . (int)($dados['morrendo'] ?? 0) .
                ' | Morto: ' . (int)($dados['morto'] ?? 0) .
                ' | Turnos até morte: ' . (int)($dados['turnos_restantes_morte'] ?? 0)
        ];
    }

    private function debugCura(Player $player, int $cura): array
    {
        if ($cura <= 0) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe um valor de cura maior que 0. Exemplo: /debug cura 20'
            ];
        }

        $dados = $player->getDados();

        if ((int)($dados['morto'] ?? 0) === 1) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Não é possível curar um personagem morto com debug de cura comum.'
            ];
        }

        $vidaAtual = (int)($dados['vida_atual'] ?? 0);
        $vidaMax = (int)($dados['vida_max'] ?? 0);
        $novaVida = min($vidaAtual + $cura, $vidaMax);

        try {
            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET vida_atual = ?
                WHERE id = ?
            ");
            $stmt->execute([$novaVida, $player->getId()]);

            if ($novaVida > 0) {
                $stmt = $this->pdo->prepare("
                    UPDATE personagens
                    SET inconsciente = 0,
                        morrendo = 0,
                        turnos_restantes_morte = 0,
                        causa_morte = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$player->getId()]);
            }

            $combate = new Combate($this->pdo);
            $combate->recalcularEstadoVidaPublico($player->getId());
        } catch (Throwable $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro no debug de cura.'
            ];
        }

        if (method_exists($player, 'recarregar')) {
            $player->recarregar();
        }

        return [
            'tipo' => 'debug',
            'mensagem' => 'DEBUG | ' . $player->getNome() . ' recuperou ' . ($novaVida - $vidaAtual) . ' de vida.'
        ];
    }

    private function debugDano(Player $player, int $dano): array
    {
        if ($dano <= 0) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe um valor de dano maior que 0. Exemplo: /debug dano 30'
            ];
        }

        try {
            $combate = new Combate($this->pdo);
            $resultado = $combate->aplicarDanoDebug($player->getId(), $dano, 'debug');

            if (method_exists($player, 'recarregar')) {
                $player->recarregar();
            }

            $mensagem = 'DEBUG | ' . $player->getNome() . ' sofreu ' . $dano . ' de dano.';

            if (!empty($resultado['morte_instantanea'])) {
                $mensagem .= ' Morte instantânea.';
            } elseif (!empty($resultado['entrou_morrendo'])) {
                $mensagem .= ' Entrou em morrendo com ' . (int)($resultado['turnos_restantes_morte'] ?? 0) . ' turnos restantes.';
            } else {
                $mensagem .= ' Vida atual: ' . (int)($resultado['vida_atual'] ?? 0) . '.';
            }

            return [
                'tipo' => 'debug',
                'mensagem' => $mensagem
            ];
        } catch (Throwable $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro no debug de dano.'
            ];
        }
    }

    private function debugTurno(Player $player): array
    {
        try {
            $combate = new Combate($this->pdo);
            $resultado = $combate->processarMorrendo($player->getId());

            if (method_exists($player, 'recarregar')) {
                $player->recarregar();
            }

            return [
                'tipo' => 'debug',
                'mensagem' => 'DEBUG | ' . ($resultado['mensagem'] ?? 'Turno processado.')
            ];
        } catch (Throwable $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro no debug de turno.'
            ];
        }
    }
}