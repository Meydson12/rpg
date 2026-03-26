<?php

class PlayerController
{
    private PDO $pdo;
    private Player $player;
    private Mundo $mundo;
    private MundoNPC $mundoNpc;

    public function __construct(PDO $pdo, int $playerId)
    {
        $this->pdo = $pdo;
        $this->player = new Player($pdo, $playerId);
        $this->mundo = new Mundo($pdo);
        $this->mundoNpc = new MundoNPC($pdo);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getCenaAtual(): string
    {
        return $this->mundo->gerarCenaInicial($this->player);
    }

    public function getCenaAtualEstruturada(): array
    {
        return $this->mundo->gerarCenaEstruturada($this->player);
    }

    public function getMemoriasRecentes(int $limite = 10): array
    {
        return $this->player->listarMemoriasRecentes($limite);
    }

    public function getSugestoes(): array
    {
        return [
            '/move Praça Central',
            '/item olhar freezer',
            '/item pegar picole',
            '/item abrir freezer',
            '/talk Lyra'
        ];
    }

    public function processarComando(?string $comandoBruto): void
    {
        $parser = new Comando($comandoBruto ?? '');
        $comando = $parser->interpretar();

        if (!$comando['valido']) {
            $this->player->adicionarMemoria(
                'erro',
                $comando['erro'] ?? 'Comando inválido.',
                0
            );
            return;
        }

        switch ($comando['acao']) {
            case 'atk':
                $this->processarAtaque($comando);
                return;

            case 'move':
                $resultado = $this->mundo->processarAcao($comando, $this->player);
                $this->player->adicionarMemoria('instantanea', $resultado['mensagem'], 0);
                return;

            case 'talk':
                $resultado = $this->mundo->processarAcao($comando, $this->player);
                $this->player->adicionarMemoria('instantanea', $resultado['mensagem'], 0);

                if (($resultado['tipo'] ?? '') === 'combate' && !empty($resultado['npc_id'])) {
                    $_SESSION['combate_npc_id'] = (int) $resultado['npc_id'];
                    header('Location: combate.php');
                    exit;
                }

                return;

            case 'ma':
                $resultado = $this->mundo->processarAcao($comando, $this->player);
                $this->player->adicionarMemoria('instantanea', $resultado['mensagem'], 0);
                return;

            case 'def':
                $resultado = $this->mundo->processarAcao($comando, $this->player);
                $this->player->adicionarMemoria('instantanea', $resultado['mensagem'], 0);
                return;

            case 'item':
                $resultado = $this->mundo->processarAcao($comando, $this->player);
                $this->player->adicionarMemoria('instantanea', $resultado['mensagem'], 0);
                return;

            default:
                $resultado = $this->mundo->processarAcao($comando, $this->player);
                $this->player->adicionarMemoria('instantanea', $resultado['mensagem'], 0);
                return;
        }
    }

    private function processarAtaque(array $comando): void
    {
        $descricao = trim($comando['descricao'] ?? '');

        if ($descricao === '') {
            $this->player->adicionarMemoria(
                'erro',
                'Informe quem você quer atacar. Exemplo: /atk Lyra',
                0
            );
            return;
        }

        $npc = $this->mundoNpc->buscarNpcNoLocalPorNome($this->player, $descricao);

        if (!$npc) {
            $localAtual = $this->mundo->buscarLocalAtual($this->player);
            $localNome = $localAtual['nome'] ?? 'Local desconhecido';

            $this->player->adicionarMemoria(
                'erro',
                'Nenhum NPC com esse nome está presente em ' . $localNome . '.',
                0
            );
            return;
        }

        if ((int) $npc['vida_atual'] <= 0) {
            $this->player->adicionarMemoria(
                'erro',
                $npc['nome'] . ' já está derrotado e não pode entrar em combate.',
                0
            );
            return;
        }

        $_SESSION['combate_npc_id'] = (int) $npc['id'];

        $this->player->adicionarMemoria(
            'combate',
            $this->player->getNome() . ' entrou em combate contra ' . $npc['nome'] . '.',
            0
        );

        header('Location: combate.php');
        exit;
    }
}
