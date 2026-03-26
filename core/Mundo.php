<?php

class Mundo
{
    private PDO $pdo;
    private MundoDebug $debug;
    private MundoMapa $mapa;
    private MundoNPC $npc;
    private MundoItem $item;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->debug = new MundoDebug($pdo);
        $this->mapa = new MundoMapa($pdo);
        $this->npc = new MundoNPC($pdo);
        $this->item = new MundoItem($pdo);
    }

    public function gerarCenaInicial(Player $player): string
    {
        $localAtual = $this->mapa->buscarLocalAtual($player);
        $npcsNoLocal = $this->npc->listarNpcsNoLocal($player);
        $objetosNoLocal = $this->item->listarObjetosDoLocal($player);
        $locaisProximos = $this->mapa->listarLocaisProximos($player);

        $linhas = [];

        $linhas[] = $localAtual['nome'];
        $linhas[] = '';

        if (!empty($localAtual['descricao'])) {
            $linhas[] = $localAtual['descricao'];
            $linhas[] = '';
        }

        $linhas[] = 'NPCs presentes:';
        if (count($npcsNoLocal) > 0) {
            foreach ($npcsNoLocal as $npc) {
                $linhas[] = '- ' . $npc['nome'];
            }
        } else {
            $linhas[] = '- Nenhum NPC visível no momento.';
        }

        $linhas[] = '';
        $linhas[] = 'Objetos visíveis:';
        if (count($objetosNoLocal) > 0) {
            foreach ($objetosNoLocal as $objeto) {
                $nomeObjeto = !empty($objeto['nome_customizado'])
                    ? $objeto['nome_customizado']
                    : $objeto['nome'];

                if ((int)$objeto['quantidade'] > 1) {
                    $linhas[] = '- ' . $nomeObjeto . ' (' . (int)$objeto['quantidade'] . ')';
                } else {
                    $linhas[] = '- ' . $nomeObjeto;
                }
            }
        } else {
            $linhas[] = '- Nada de interessante aqui.';
        }

        $linhas[] = '';
        $linhas[] = 'Locais próximos:';
        if (count($locaisProximos) > 0) {
            foreach ($locaisProximos as $local) {
                $linhas[] = '- ' . $local['nome'];
            }
        } else {
            $linhas[] = '- Nenhum caminho disponível no momento.';
        }

        return implode("\n", $linhas);
    }

    public function buscarLocalAtual(Player $player): ?array
    {
        $dados = $player->getDados();
        $localId = (int)($dados['local_atual_id'] ?? 0);

        if ($localId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
        SELECT *
        FROM locais
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$localId]);

        $local = $stmt->fetch(PDO::FETCH_ASSOC);

        return $local ?: null;
    }

    public function gerarCenaEstruturada(Player $player): array
    {
        $localAtual = $this->mapa->buscarLocalAtual($player);
        $npcsNoLocal = $this->npc->listarNpcsNoLocal($player);
        $objetosNoLocal = $this->item->listarObjetosDoLocal($player);
        $locaisProximos = $this->mapa->listarLocaisProximos($player);

        $npcs = [];
        foreach ($npcsNoLocal as $npc) {
            $npcs[] = $npc['nome'];
        }

        $objetos = [];
        foreach ($objetosNoLocal as $objeto) {
            $nomeObjeto = !empty($objeto['nome_customizado'])
                ? $objeto['nome_customizado']
                : $objeto['nome'];

            if ((int)$objeto['quantidade'] > 1) {
                $nomeObjeto .= ' (' . (int)$objeto['quantidade'] . ')';
            }

            $objetos[] = $nomeObjeto;
        }

        $locais = [];
        foreach ($locaisProximos as $local) {
            $locais[] = $local['nome'];
        }

        return [
            'local' => $localAtual['nome'] ?? 'Local desconhecido',
            'descricao' => $localAtual['descricao'] ?? '',
            'npcs' => $npcs,
            'objetos' => $objetos,
            'locais_proximos' => $locais,
        ];
    }

    private function mostrarStatus(Player $player): array
    {
        return [
            'tipo' => 'status',
            'mensagem' => $player->montarStatusTexto()
        ];
    }

    public function processarAcao(array $comando, Player $player): array
    {
        if (!$comando['valido']) {
            return [
                'tipo' => 'erro',
                'mensagem' => $comando['erro']
            ];
        }

        $nome = $player->getNome();
        $combate = new Combate($this->pdo);
        $estadoAcao = $combate->podeAgir($player->getDados());

        if (!$estadoAcao['pode_agir']) {
            return [
                'tipo' => 'erro',
                'mensagem' => $estadoAcao['mensagem']
            ];
        }

        $acao = $comando['acao'];
        $descricao = trim($comando['descricao']);
        $localAtual = $this->mapa->buscarLocalAtual($player);

        switch ($acao) {
            case 'talk':
                return $this->npc->processarTalk($player, $descricao, $localAtual);

            case 'move':
                return $this->mapa->moverPlayer($player, $descricao);

            case 'atk':
                return [
                    'tipo' => 'combate',
                    'mensagem' => "{$nome} inicia uma ação ofensiva. {$descricao}"
                ];

            case 'def':
                return [
                    'tipo' => 'defesa',
                    'mensagem' => "{$nome} assume posição defensiva. {$descricao}"
                ];

            case 'ma':
                return [
                    'tipo' => 'magia',
                    'mensagem' => "{$nome} canaliza poder mágico. {$descricao}"
                ];

            case 'debug':
                return $this->debug->processar($player, $descricao);

            case 'status':
                return $this->mostrarStatus($player);

            case 'item':
                return $this->item->processarAcaoItem($player, $descricao, $localAtual);

            default:
                return [
                    'tipo' => 'sistema',
                    'mensagem' => 'Ação reconhecida, mas ainda sem tratamento.'
                ];
        }
    }
}
