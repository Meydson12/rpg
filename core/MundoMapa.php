<?php

class MundoMapa
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function getEvento(): Evento
    {
        return new Evento($this->pdo);
    }

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

    public function buscarLocalAtual(Player $player): array
    {
        $dados = $player->getDados();
        $localId = (int)($dados['local_atual_id'] ?? 0);

        if ($localId <= 0) {
            return [
                'id' => 0,
                'nome' => 'Local desconhecido',
                'slug' => '',
                'tipo' => '',
                'descricao' => '',
                'local_pai_id' => null
            ];
        }

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM locais
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$localId]);

        $local = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$local) {
            return [
                'id' => 0,
                'nome' => 'Local desconhecido',
                'slug' => '',
                'tipo' => '',
                'descricao' => '',
                'local_pai_id' => null
            ];
        }

        return $local;
    }

    public function buscarLocalPorEntrada(string $entrada): ?array
    {
        $entrada = trim($entrada);

        if ($entrada === '') {
            return null;
        }

        $entradaNormalizada = $this->normalizarTexto($entrada);

        $stmt = $this->pdo->prepare("
            SELECT *
            FROM locais
            WHERE ativo = 1
            ORDER BY nome ASC
        ");
        $stmt->execute();

        $locais = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($locais as $local) {
            $nomeNormalizado = $this->normalizarTexto($local['nome'] ?? '');
            $slugNormalizado = $this->normalizarTexto($local['slug'] ?? '');

            if (
                str_contains($nomeNormalizado, $entradaNormalizada) ||
                str_contains($slugNormalizado, $entradaNormalizada)
            ) {
                return $local;
            }
        }

        $stmtAlias = $this->pdo->prepare("
            SELECT l.*, la.alias_nome
            FROM locais_alias la
            INNER JOIN locais l ON l.id = la.local_id
            WHERE l.ativo = 1
            ORDER BY l.nome ASC
        ");
        $stmtAlias->execute();

        $aliases = $stmtAlias->fetchAll(PDO::FETCH_ASSOC);

        foreach ($aliases as $alias) {
            $aliasNormalizado = $this->normalizarTexto($alias['alias_nome'] ?? '');
            $nomeNormalizado = $this->normalizarTexto($alias['nome'] ?? '');
            $slugNormalizado = $this->normalizarTexto($alias['slug'] ?? '');

            if (
                str_contains($aliasNormalizado, $entradaNormalizada) ||
                str_contains($nomeNormalizado, $entradaNormalizada) ||
                str_contains($slugNormalizado, $entradaNormalizada)
            ) {
                return $alias;
            }
        }

        return null;
    }

    public function moverPlayer(Player $player, string $novoLocalTexto): array
    {
        $novoLocalTexto = trim($novoLocalTexto);

        if ($novoLocalTexto === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Você precisa informar para onde deseja se mover.'
            ];
        }

        $local = $this->buscarLocalPorEntrada($novoLocalTexto);

        if (!$local || empty($local['id'])) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Local não reconhecido.'
            ];
        }

        $dadosPlayer = $player->getDados();
        $localAtualId = (int)($dadosPlayer['local_atual_id'] ?? 0);

        if ($localAtualId === (int)$local['id']) {
            return [
                'tipo' => 'sistema',
                'mensagem' => $player->getNome() . ' já está em ' . $local['nome'] . '.'
            ];
        }

        if (!$this->localEhProximo($player, (int)$local['id'])) {
            return [
                'tipo' => 'erro',
                'mensagem' => $local['nome'] . ' não está acessível por movimento normal a partir do seu local atual.'
            ];
        }

        $jaVisitouLocal = false;

        try {
            $jaVisitouLocal = $this->getEvento()->personagemJaVisitouLocal(
                $player->getId(),
                (int)$local['id']
            );
        } catch (Throwable $e) {
            $jaVisitouLocal = false;
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET local_atual_id = ?
                WHERE id = ?
            ");
            $stmt->execute([(int)$local['id'], $player->getId()]);
        } catch (PDOException $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao mover personagem.'
            ];
        }

        if (!$jaVisitouLocal) {
            try {
                $this->getEvento()->registrarSeNaoExistir([
                    'tipo_evento'    => 'exploracao',
                    'subtipo_evento' => 'primeira_visita',
                    'personagem_id'  => $player->getId(),
                    'local_id'       => (int)$local['id'],
                    'titulo'         => 'Primeira visita',
                    'descricao'      => $player->getNome() . ' visitou ' . $local['nome'] . ' pela primeira vez.',
                    'dados_json'     => [
                        'origem' => 'movimentacao',
                        'local_nome' => $local['nome']
                    ],
                    'ativo'          => 1
                ]);
            } catch (Throwable $e) {
                // não quebra o movimento se o evento falhar
            }
        }

        if (method_exists($player, 'recarregar')) {
            $player->recarregar();
        }

        if (!$jaVisitouLocal) {
            return [
                'tipo' => 'movimento',
                'mensagem' => $player->getNome() . ' chegou a ' . $local['nome'] . ' pela primeira vez.'
            ];
        }

        return [
            'tipo' => 'movimento',
            'mensagem' => $player->getNome() . ' retornou a ' . $local['nome'] . '.'
        ];
    }

    public function listarLocaisDisponiveis(): array
    {
        $stmt = $this->pdo->query("
            SELECT *
            FROM locais
            WHERE ativo = 1
            ORDER BY nome ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function localEhProximo(Player $player, int $destinoId): bool
    {
        $localAtual = $this->buscarLocalAtual($player);
        $origemId = (int)($localAtual['id'] ?? 0);

        if ($origemId <= 0 || $destinoId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare("
            SELECT id
            FROM locais_conexoes
            WHERE local_origem_id = ?
              AND local_destino_id = ?
              AND ativo = 1
            LIMIT 1
        ");
        $stmt->execute([$origemId, $destinoId]);

        $conexao = $stmt->fetch(PDO::FETCH_ASSOC);

        return (bool)$conexao;
    }

    public function listarLocaisProximos(Player $player): array
    {
        $localAtual = $this->buscarLocalAtual($player);
        $localAtualId = (int)($localAtual['id'] ?? 0);

        if ($localAtualId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT l.*
            FROM locais_conexoes lc
            INNER JOIN locais l ON l.id = lc.local_destino_id
            WHERE lc.local_origem_id = ?
              AND lc.ativo = 1
              AND l.ativo = 1
            ORDER BY l.nome ASC
        ");
        $stmt->execute([$localAtualId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}