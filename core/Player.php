<?php

class Player
{
    private PDO $pdo;
    private int $id;
    private array $dados = [];

    public function __construct(PDO $pdo, int $id)
    {
        $this->pdo = $pdo;
        $this->id = $id;
        $this->carregar();
    }

    private function carregar(): void
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM personagens 
            WHERE id = ? AND tipo = 'player'
            LIMIT 1
        ");
        $stmt->execute([$this->id]);
        $dados = $stmt->fetch();

        if (!$dados) {
            throw new Exception('Player não encontrado.');
        }

        $this->dados = $dados;
    }

    public function getDados(): array
    {
        return $this->dados;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->dados['nome'];
    }

    public function getAtributo(string $atributo): int
    {
        $campo = 'atributos_' . strtolower($atributo);
        return isset($this->dados[$campo]) ? (int)$this->dados[$campo] : 0;
    }

    public function getVidaAtual(): int
    {
        return (int)$this->dados['vida_atual'];
    }

    public function getVidaMax(): int
    {
        return (int)$this->dados['vida_max'];
    }

    public function getManaAtual(): int
    {
        return (int)$this->dados['mana_atual'];
    }

    public function getManaMax(): int
    {
        return (int)$this->dados['mana_max'];
    }

    public function getNivel(): int
    {
        return (int)$this->dados['nivel'];
    }

    public function getXp(): int
    {
        return (int)$this->dados['xp'];
    }

    public function adicionarMemoria(string $tipo, string $conteudo, int $turno = 0): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO memoria (tipo_memoria, conteudo, turno, data_criacao)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$tipo, $conteudo, $turno]);
    }

    public function listarMemoriasRecentes(int $limite = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM memoria 
            ORDER BY id DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listarInventario(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                i.id,
                i.personagem_id,
                i.objeto_id,
                i.quantidade,
                i.equipado,
                i.slot_equipamento,
                i.observacoes,
                o.nome,
                o.slug,
                o.tipo,
                o.subtipo,
                o.descricao_base
            FROM inventario i
            INNER JOIN objetos o ON o.id = i.objeto_id
            WHERE i.personagem_id = ?
            ORDER BY 
                i.equipado DESC,
                o.nome ASC
        ");
        $stmt->execute([$this->id]);

        return $stmt->fetchAll();
    }

    public function listarEquipamentosAtivos(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                i.id,
                i.personagem_id,
                i.objeto_id,
                i.quantidade,
                i.equipado,
                i.slot_equipamento,
                i.observacoes,
                o.nome,
                o.slug,
                o.tipo,
                o.subtipo,
                o.descricao_base
            FROM inventario i
            INNER JOIN objetos o ON o.id = i.objeto_id
            WHERE i.personagem_id = ?
              AND i.equipado = 1
            ORDER BY i.slot_equipamento ASC, o.nome ASC
        ");
        $stmt->execute([$this->id]);

        return $stmt->fetchAll();
    }

    public function buscarEquipamentoPorSlot(string $slot): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                i.id,
                i.personagem_id,
                i.objeto_id,
                i.quantidade,
                i.equipado,
                i.slot_equipamento,
                i.observacoes,
                o.nome,
                o.slug,
                o.tipo,
                o.subtipo,
                o.descricao_base
            FROM inventario i
            INNER JOIN objetos o ON o.id = i.objeto_id
            WHERE i.personagem_id = ?
              AND i.equipado = 1
              AND i.slot_equipamento = ?
            LIMIT 1
        ");
        $stmt->execute([$this->id, $slot]);

        $equipamento = $stmt->fetch();

        return $equipamento ?: null;
    }

    public function getArmaduraEquipada(): ?array
    {
        return $this->buscarEquipamentoPorSlot('armadura');
    }

    public function getEscudoEquipado(): ?array
    {
        return $this->buscarEquipamentoPorSlot('escudo');
    }

    public function getArmaEquipada(): ?array
    {
        return $this->buscarEquipamentoPorSlot('arma');
    }

    public function getCapacidadeInventario(): int
    {
        return $this->getAtributo('for') * 6;
    }

    public function atualizarVida(int $novaVida): void
    {
        $novaVida = max(0, min($novaVida, $this->getVidaMax()));

        $stmt = $this->pdo->prepare("
            UPDATE personagens
            SET vida_atual = ?
            WHERE id = ?
        ");
        $stmt->execute([$novaVida, $this->id]);

        $this->dados['vida_atual'] = $novaVida;
    }

    public function atualizarMana(int $novaMana): void
    {
        $novaMana = max(0, min($novaMana, $this->getManaMax()));

        $stmt = $this->pdo->prepare("
            UPDATE personagens
            SET mana_atual = ?
            WHERE id = ?
        ");
        $stmt->execute([$novaMana, $this->id]);

        $this->dados['mana_atual'] = $novaMana;
    }
    public function getEstadoVida(): string
    {
        return (string)($this->dados['estado_vida'] ?? 'saudavel');
    }

    public function getTurnosRestantesMorte(): int
    {
        return (int)($this->dados['turnos_restantes_morte'] ?? 0);
    }

    public function getLocalAtualId(): int
    {
        return (int)($this->dados['local_atual_id'] ?? 0);
    }

    public function getLocalAtualNome(): string
    {
        $localId = $this->getLocalAtualId();

        if ($localId <= 0) {
            return 'Local desconhecido';
        }

        $stmt = $this->pdo->prepare("
        SELECT nome
        FROM locais
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$localId]);

        $local = $stmt->fetch(PDO::FETCH_ASSOC);

        return $local['nome'] ?? 'Local desconhecido';
    }

    private function formatarEstadoVida(string $estado): string
    {
        $mapa = [
            'saudavel' => 'Saudável',
            'ferido' => 'Ferido',
            'grave' => 'Grave',
            'critico' => 'Crítico',
            'inconsciente' => 'Inconsciente',
            'morrendo' => 'Morrendo',
            'morto' => 'Morto',
        ];

        return $mapa[$estado] ?? ucfirst($estado);
    }

    public function montarStatusCompleto(): array
    {
        $combate = new Combate($this->pdo);
        $dadosCombate = $combate->buscarPersonagemPorId($this->id);

        $equipamentos = [
            'arma' => 'Nenhuma',
            'escudo' => 'Nenhum',
            'armadura' => 'Nenhuma',
        ];

        $arma = $this->getArmaEquipada();
        $escudo = $this->getEscudoEquipado();
        $armadura = $this->getArmaduraEquipada();

        if ($arma) {
            $equipamentos['arma'] = $arma['nome'];
        }

        if ($escudo) {
            $equipamentos['escudo'] = $escudo['nome'];
        }

        if ($armadura) {
            $equipamentos['armadura'] = $armadura['nome'];
        }

        return [
            'nome' => $this->getNome(),
            'classe' => (string)($this->dados['classe'] ?? 'Sem classe'),
            'nivel' => $this->getNivel(),
            'xp' => $this->getXp(),

            'vida_atual' => $this->getVidaAtual(),
            'vida_max' => $this->getVidaMax(),
            'mana_atual' => $this->getManaAtual(),
            'mana_max' => $this->getManaMax(),

            'estado_vida' => $this->getEstadoVida(),
            'estado_vida_formatado' => $this->formatarEstadoVida($this->getEstadoVida()),
            'turnos_restantes_morte' => $this->getTurnosRestantesMorte(),

            'atributos' => [
                'for' => $this->getAtributo('for'),
                'int' => $this->getAtributo('int'),
                'pre' => $this->getAtributo('pre'),
                'agi' => $this->getAtributo('agi'),
                'vi' => $this->getAtributo('vi'),
            ],

            'defesa_base' => (int)($dadosCombate['defesa_base'] ?? 0),
            'defesa_total' => (int)($dadosCombate['defesa_total'] ?? 0),
            'bloqueio' => (int)($dadosCombate['defesa_bloqueio'] ?? 0),
            'esquiva' => (int)($dadosCombate['defesa_esquiva'] ?? 0),
            'reducao_fisica' => (int)($dadosCombate['reducao_dano_fisico'] ?? 0),
            'reducao_magica' => (int)($dadosCombate['reducao_dano_magico'] ?? 0),

            'equipamentos' => $equipamentos,
            'local_atual' => $this->getLocalAtualNome(),
        ];
    }

    public function montarStatusTexto(): string
    {
        $status = $this->montarStatusCompleto();

        $linhas = [];
        $linhas[] = '=== Status de ' . $status['nome'] . ' ===';
        $linhas[] = '';
        $linhas[] = 'Classe: ' . $status['classe'];
        $linhas[] = 'Nível: ' . $status['nivel'];
        $linhas[] = 'XP: ' . $status['xp'];
        $linhas[] = 'Local: ' . $status['local_atual'];
        $linhas[] = '';
        $linhas[] = 'Vida: ' . $status['vida_atual'] . '/' . $status['vida_max'];
        $linhas[] = 'Mana: ' . $status['mana_atual'] . '/' . $status['mana_max'];
        $linhas[] = 'Estado: ' . $status['estado_vida_formatado'];

        if ($status['estado_vida'] === 'morrendo') {
            $linhas[] = 'Turnos restantes para morte: ' . $status['turnos_restantes_morte'];
        }

        $linhas[] = '';
        $linhas[] = 'Atributos';
        $linhas[] = '- FOR: ' . $status['atributos']['for'];
        $linhas[] = '- INT: ' . $status['atributos']['int'];
        $linhas[] = '- PRE: ' . $status['atributos']['pre'];
        $linhas[] = '- AGI: ' . $status['atributos']['agi'];
        $linhas[] = '- VI: ' . $status['atributos']['vi'];

        $linhas[] = '';
        $linhas[] = 'Defesa';
        $linhas[] = '- Defesa base: ' . $status['defesa_base'];
        $linhas[] = '- Defesa total: ' . $status['defesa_total'];
        $linhas[] = '- Bloqueio: ' . $status['bloqueio'];
        $linhas[] = '- Esquiva: ' . $status['esquiva'];
        $linhas[] = '- Redução física: ' . $status['reducao_fisica'];
        $linhas[] = '- Redução mágica: ' . $status['reducao_magica'];

        $linhas[] = '';
        $linhas[] = 'Equipamentos';
        $linhas[] = '- Arma: ' . $status['equipamentos']['arma'];
        $linhas[] = '- Escudo: ' . $status['equipamentos']['escudo'];
        $linhas[] = '- Armadura: ' . $status['equipamentos']['armadura'];

        return implode("\n", $linhas);
    }

    public function recarregar(): void
    {
        $this->carregar();
    }
}
