<?php

class MundoItem
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

    private function normalizarSlotEquipamentoPorTipo(string $tipo): ?string
    {
        $tipo = trim(mb_strtolower($tipo));

        if ($tipo === 'armadura') {
            return 'armadura';
        }

        if ($tipo === 'escudo') {
            return 'escudo';
        }

        if ($tipo === 'arma' || $tipo === 'item_amaldicoado') {
            return 'arma';
        }

        return null;
    }

    public function listarObjetosDoLocal(Player $player): array
    {
        $dados = $player->getDados();
        $localId = (int)($dados['local_atual_id'] ?? 0);

        if ($localId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT 
                lo.id,
                lo.local_id,
                lo.objeto_id,
                lo.nome_customizado,
                lo.descricao_customizada,
                lo.visivel,
                lo.pegavel,
                lo.interagivel,
                lo.fixo,
                lo.quantidade,
                lo.estado,
                o.nome,
                o.slug,
                o.tipo,
                o.importante,
                o.descricao_base
            FROM locais_objetos lo
            INNER JOIN objetos o ON o.id = lo.objeto_id
            WHERE lo.local_id = ?
              AND lo.ativo = 1
              AND lo.visivel = 1
            ORDER BY o.nome ASC
        ");
        $stmt->execute([$localId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarObjetoNoLocalPorNome(Player $player, string $nomeBusca): ?array
    {
        $objetos = $this->buscarObjetosNoLocalPorNome($player, $nomeBusca);

        if (count($objetos) === 1) {
            return $objetos[0];
        }

        return null;
    }

    public function buscarObjetosNoLocalPorNome(Player $player, string $nomeBusca): array
    {
        $dados = $player->getDados();
        $localId = (int)($dados['local_atual_id'] ?? 0);
        $nomeBusca = trim($nomeBusca);

        if ($localId <= 0 || $nomeBusca === '') {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT 
                lo.id,
                lo.local_id,
                lo.objeto_id,
                lo.nome_customizado,
                lo.descricao_customizada,
                lo.visivel,
                lo.pegavel,
                lo.interagivel,
                lo.fixo,
                lo.quantidade,
                lo.estado,
                o.nome,
                o.slug,
                o.tipo,
                o.importante,
                o.descricao_base
            FROM locais_objetos lo
            INNER JOIN objetos o ON o.id = lo.objeto_id
            WHERE lo.local_id = ?
              AND lo.ativo = 1
              AND lo.visivel = 1
            ORDER BY o.nome ASC
        ");
        $stmt->execute([$localId]);

        $objetos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $buscaNormalizada = $this->normalizarTexto($nomeBusca);
        $resultados = [];

        foreach ($objetos as $objeto) {
            $nomeBase = $this->normalizarTexto($objeto['nome'] ?? '');
            $slug = $this->normalizarTexto($objeto['slug'] ?? '');
            $nomeCustomizado = $this->normalizarTexto($objeto['nome_customizado'] ?? '');

            if (
                str_contains($nomeBase, $buscaNormalizada) ||
                str_contains($slug, $buscaNormalizada) ||
                str_contains($nomeCustomizado, $buscaNormalizada)
            ) {
                $resultados[] = $objeto;
            }
        }

        return $resultados;
    }

    private function montarMensagemAmbiguidadeObjetos(array $objetos): string
    {
        $linhas = ['Há mais de um objeto com esse nome neste local:'];

        foreach ($objetos as $objeto) {
            $nomeExibicao = !empty($objeto['nome_customizado'])
                ? $objeto['nome_customizado']
                : $objeto['nome'];

            if ((int)$objeto['quantidade'] > 1) {
                $linhas[] = '- ' . $nomeExibicao . ' (' . (int)$objeto['quantidade'] . ')';
            } else {
                $linhas[] = '- ' . $nomeExibicao;
            }
        }

        $linhas[] = 'Seja mais específico.';

        return implode("\n", $linhas);
    }

    public function pegarObjeto(Player $player, string $nomeObjeto, array $localAtual): array
    {
        $nomeObjeto = trim($nomeObjeto);

        if ($nomeObjeto === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o objeto que deseja pegar. Exemplo: /item pegar picole'
            ];
        }

        $objetos = $this->buscarObjetosNoLocalPorNome($player, $nomeObjeto);

        if (count($objetos) === 0) {
            $localNome = $localAtual['nome'] ?? 'local desconhecido';

            return [
                'tipo' => 'erro',
                'mensagem' => 'Não há nenhum objeto com esse nome visível em ' . $localNome . '.'
            ];
        }

        if (count($objetos) > 1) {
            return [
                'tipo' => 'erro',
                'mensagem' => $this->montarMensagemAmbiguidadeObjetos($objetos)
            ];
        }

        $objeto = $objetos[0];

        if ((int)$objeto['pegavel'] !== 1) {
            $nomeExibicao = !empty($objeto['nome_customizado'])
                ? $objeto['nome_customizado']
                : $objeto['nome'];

            return [
                'tipo' => 'erro',
                'mensagem' => $nomeExibicao . ' não pode ser pego.'
            ];
        }

        if ((int)$objeto['quantidade'] <= 0) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Esse objeto não está mais disponível.'
            ];
        }

        $objetoId = (int)$objeto['objeto_id'];
        $nomeItem = !empty($objeto['nome_customizado'])
            ? $objeto['nome_customizado']
            : $objeto['nome'];

        $tipoItem = trim((string)($objeto['tipo'] ?? ''));
        $equiparAutomaticamente = false;
        $slotEquipamento = null;

        if ($tipoItem === 'armadura') {
            $equiparAutomaticamente = true;
            $slotEquipamento = 'armadura';
        }

        try {
            if ($equiparAutomaticamente && $slotEquipamento !== null) {
                $stmtDesequipar = $this->pdo->prepare("
                    UPDATE inventario
                    SET equipado = 0, slot_equipamento = NULL
                    WHERE personagem_id = ?
                      AND slot_equipamento = ?
                ");
                $stmtDesequipar->execute([$player->getId(), $slotEquipamento]);
            }

            $stmtInventario = $this->pdo->prepare("
                SELECT id, quantidade, equipado, slot_equipamento
                FROM inventario
                WHERE personagem_id = ?
                  AND objeto_id = ?
                LIMIT 1
            ");
            $stmtInventario->execute([$player->getId(), $objetoId]);
            $itemExistente = $stmtInventario->fetch(PDO::FETCH_ASSOC);

            if ($itemExistente) {
                $novaQuantidade = (int)$itemExistente['quantidade'] + 1;

                if ($equiparAutomaticamente) {
                    $stmtUpdateInventario = $this->pdo->prepare("
                        UPDATE inventario
                        SET quantidade = ?, equipado = 1, slot_equipamento = ?
                        WHERE id = ?
                    ");
                    $stmtUpdateInventario->execute([
                        $novaQuantidade,
                        $slotEquipamento,
                        $itemExistente['id']
                    ]);
                } else {
                    $stmtUpdateInventario = $this->pdo->prepare("
                        UPDATE inventario
                        SET quantidade = ?
                        WHERE id = ?
                    ");
                    $stmtUpdateInventario->execute([
                        $novaQuantidade,
                        $itemExistente['id']
                    ]);
                }
            } else {
                $stmtInsertInventario = $this->pdo->prepare("
                    INSERT INTO inventario (
                        personagem_id,
                        objeto_id,
                        quantidade,
                        equipado,
                        slot_equipamento,
                        observacoes
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtInsertInventario->execute([
                    $player->getId(),
                    $objetoId,
                    1,
                    $equiparAutomaticamente ? 1 : 0,
                    $slotEquipamento,
                    null
                ]);
            }

            $quantidadeAtual = (int)$objeto['quantidade'];
            $novaQuantidadeLocal = $quantidadeAtual - 1;

            if ($novaQuantidadeLocal > 0) {
                $stmtUpdateLocal = $this->pdo->prepare("
                    UPDATE locais_objetos
                    SET quantidade = ?
                    WHERE id = ?
                ");
                $stmtUpdateLocal->execute([$novaQuantidadeLocal, $objeto['id']]);
            } else {
                $stmtDeleteLocal = $this->pdo->prepare("
                    DELETE FROM locais_objetos
                    WHERE id = ?
                ");
                $stmtDeleteLocal->execute([$objeto['id']]);
            }
        } catch (PDOException $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao pegar o objeto.'
            ];
        }

        if ((int)($objeto['importante'] ?? 0) === 1) {
            try {
                $this->getEvento()->registrarSeNaoExistir([
                    'tipo_evento'    => 'item',
                    'subtipo_evento' => 'item_importante_obtido',
                    'personagem_id'  => $player->getId(),
                    'local_id'       => (int)($player->getDados()['local_atual_id'] ?? 0),
                    'referencia_id'  => $objetoId,
                    'titulo'         => 'Item importante obtido',
                    'descricao'      => $player->getNome() . ' obteve ' . $nomeItem . '.',
                    'dados_json'     => [
                        'item_nome' => $nomeItem,
                        'objeto_id' => $objetoId
                    ],
                    'ativo'          => 1
                ]);
            } catch (Throwable $e) {
                // não quebra o fluxo
            }
        }

        if ($equiparAutomaticamente) {
            return [
                'tipo' => 'item',
                'mensagem' => $player->getNome() . ' pegou ' . $nomeItem . ' e equipou automaticamente.'
            ];
        }

        return [
            'tipo' => 'item',
            'mensagem' => $player->getNome() . ' pegou ' . $nomeItem . '.'
        ];
    }

    public function olharObjeto(Player $player, string $nomeObjeto, array $localAtual): array
    {
        $nomeObjeto = trim($nomeObjeto);

        if ($nomeObjeto === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o objeto que deseja observar. Exemplo: /item olhar freezer'
            ];
        }

        $objetos = $this->buscarObjetosNoLocalPorNome($player, $nomeObjeto);

        if (count($objetos) === 0) {
            $localNome = $localAtual['nome'] ?? 'local desconhecido';

            return [
                'tipo' => 'erro',
                'mensagem' => 'Não há nenhum objeto com esse nome visível em ' . $localNome . '.'
            ];
        }

        if (count($objetos) > 1) {
            return [
                'tipo' => 'erro',
                'mensagem' => $this->montarMensagemAmbiguidadeObjetos($objetos)
            ];
        }

        $objeto = $objetos[0];

        $nomeExibicao = !empty($objeto['nome_customizado'])
            ? $objeto['nome_customizado']
            : $objeto['nome'];

        $descricao = !empty($objeto['descricao_customizada'])
            ? $objeto['descricao_customizada']
            : ($objeto['descricao_base'] ?? 'Você não percebe nada de especial.');

        return [
            'tipo' => 'objeto',
            'mensagem' => 'Você observa ' . $nomeExibicao . '. ' . $descricao
        ];
    }

    public function abrirObjeto(Player $player, string $nomeObjeto, array $localAtual): array
    {
        $nomeObjeto = trim($nomeObjeto);

        if ($nomeObjeto === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o objeto que deseja abrir. Exemplo: /item abrir freezer'
            ];
        }

        $objetos = $this->buscarObjetosNoLocalPorNome($player, $nomeObjeto);

        if (count($objetos) === 0) {
            $localNome = $localAtual['nome'] ?? 'local desconhecido';

            return [
                'tipo' => 'erro',
                'mensagem' => 'Não há nenhum objeto com esse nome visível em ' . $localNome . '.'
            ];
        }

        if (count($objetos) > 1) {
            return [
                'tipo' => 'erro',
                'mensagem' => $this->montarMensagemAmbiguidadeObjetos($objetos)
            ];
        }

        $objeto = $objetos[0];

        $nomeExibicao = !empty($objeto['nome_customizado'])
            ? $objeto['nome_customizado']
            : $objeto['nome'];

        if ((int)$objeto['interagivel'] !== 1) {
            return [
                'tipo' => 'erro',
                'mensagem' => $nomeExibicao . ' não parece algo que possa ser aberto.'
            ];
        }

        $estadoAtual = trim((string)($objeto['estado'] ?? ''));
        $estadoNormalizado = $this->normalizarTexto($estadoAtual);
        $estadosAbriveis = ['fechado', 'aberto', 'trancado'];

        if ($estadoNormalizado === '' || !in_array($estadoNormalizado, $estadosAbriveis, true)) {
            return [
                'tipo' => 'erro',
                'mensagem' => $nomeExibicao . ' não parece algo que possa ser aberto.'
            ];
        }

        if ($estadoNormalizado === 'aberto') {
            return [
                'tipo' => 'item',
                'mensagem' => $nomeExibicao . ' já está aberto.'
            ];
        }

        if ($estadoNormalizado === 'trancado') {
            return [
                'tipo' => 'item',
                'mensagem' => $nomeExibicao . ' está trancado.'
            ];
        }

        try {
            $stmt = $this->pdo->prepare("
                UPDATE locais_objetos
                SET estado = 'aberto'
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$objeto['id']]);
        } catch (PDOException $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao abrir o objeto.'
            ];
        }

        return [
            'tipo' => 'item',
            'mensagem' => 'Você abre ' . $nomeExibicao . '.'
        ];
    }

    public function buscarItemNoInventarioPorNome(Player $player, string $nomeBusca): ?array
    {
        $nomeBusca = trim($nomeBusca);

        if ($nomeBusca === '') {
            return null;
        }

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
                o.consumivel,
                o.equipavel,
                o.descricao_base
            FROM inventario i
            INNER JOIN objetos o ON o.id = i.objeto_id
            WHERE i.personagem_id = ?
            ORDER BY o.nome ASC
        ");
        $stmt->execute([$player->getId()]);

        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $buscaNormalizada = $this->normalizarTexto($nomeBusca);

        foreach ($itens as $item) {
            $nomeNormalizado = $this->normalizarTexto($item['nome'] ?? '');
            $slugNormalizado = $this->normalizarTexto($item['slug'] ?? '');

            if (
                str_contains($nomeNormalizado, $buscaNormalizada) ||
                str_contains($slugNormalizado, $buscaNormalizada)
            ) {
                return $item;
            }
        }

        return null;
    }

    private function buscarEfeitosObjeto(int $objetoId, string $gatilho): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                id,
                objeto_id,
                gatilho,
                efeito,
                valor,
                alvo,
                exige_equipado,
                consome_ao_ativar,
                ordem_execucao,
                ativo
            FROM objetos_efeitos
            WHERE objeto_id = ?
              AND gatilho = ?
              AND ativo = 1
            ORDER BY ordem_execucao ASC, id ASC
        ");

        $stmt->execute([$objetoId, $gatilho]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function equiparItem(Player $player, string $nomeItem): array
    {
        $nomeItem = trim($nomeItem);

        if ($nomeItem === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o item que deseja equipar. Exemplo: /item equipar espada de ferro'
            ];
        }

        $item = $this->buscarItemNoInventarioPorNome($player, $nomeItem);

        if (!$item) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Você não possui esse item no inventário.'
            ];
        }

        $nomeExibicao = $item['nome'] ?? 'Item desconhecido';
        $tipoItem = trim((string)($item['tipo'] ?? ''));
        $equipavel = (int)($item['equipavel'] ?? 0);

        if ($equipavel !== 1) {
            return [
                'tipo' => 'erro',
                'mensagem' => $nomeExibicao . ' não pode ser equipado.'
            ];
        }

        $slotEquipamento = $this->normalizarSlotEquipamentoPorTipo($tipoItem);

        if ($slotEquipamento === null) {
            return [
                'tipo' => 'erro',
                'mensagem' => $nomeExibicao . ' não possui um slot de equipamento válido.'
            ];
        }

        if ((int)($item['equipado'] ?? 0) === 1 && ($item['slot_equipamento'] ?? '') === $slotEquipamento) {
            return [
                'tipo' => 'item',
                'mensagem' => $nomeExibicao . ' já está equipado.'
            ];
        }

        try {
            $this->pdo->beginTransaction();

            $stmtDesequipar = $this->pdo->prepare("
                UPDATE inventario
                SET equipado = 0, slot_equipamento = NULL
                WHERE personagem_id = ?
                  AND slot_equipamento = ?
                  AND equipado = 1
            ");
            $stmtDesequipar->execute([$player->getId(), $slotEquipamento]);

            $stmtEquipar = $this->pdo->prepare("
                UPDATE inventario
                SET equipado = 1, slot_equipamento = ?
                WHERE id = ?
            ");
            $stmtEquipar->execute([$slotEquipamento, $item['id']]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao equipar o item.'
            ];
        }

        if (method_exists($player, 'recarregar')) {
            $player->recarregar();
        }

        return [
            'tipo' => 'item',
            'mensagem' => $player->getNome() . ' equipou ' . $nomeExibicao . '.'
        ];
    }

    public function desequiparItem(Player $player, string $nomeOuSlot): array
    {
        $nomeOuSlot = trim($nomeOuSlot);

        if ($nomeOuSlot === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o item ou slot que deseja desequipar. Exemplo: /item desequipar escudo ou /item desequipar arma'
            ];
        }

        $entrada = $this->normalizarTexto($nomeOuSlot);
        $slotsValidos = ['arma', 'escudo', 'armadura'];

        try {
            if (in_array($entrada, $slotsValidos, true)) {
                $stmt = $this->pdo->prepare("
                    SELECT i.id, o.nome
                    FROM inventario i
                    INNER JOIN objetos o ON o.id = i.objeto_id
                    WHERE i.personagem_id = ?
                      AND i.equipado = 1
                      AND i.slot_equipamento = ?
                    LIMIT 1
                ");
                $stmt->execute([$player->getId(), $entrada]);

                $itemEquipado = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$itemEquipado) {
                    return [
                        'tipo' => 'erro',
                        'mensagem' => 'Nenhum item está equipado no slot ' . $entrada . '.'
                    ];
                }

                $stmtDesequipar = $this->pdo->prepare("
                    UPDATE inventario
                    SET equipado = 0, slot_equipamento = NULL
                    WHERE id = ?
                ");
                $stmtDesequipar->execute([$itemEquipado['id']]);

                if (method_exists($player, 'recarregar')) {
                    $player->recarregar();
                }

                return [
                    'tipo' => 'item',
                    'mensagem' => $player->getNome() . ' desequipou ' . $itemEquipado['nome'] . '.'
                ];
            }

            $item = $this->buscarItemNoInventarioPorNome($player, $nomeOuSlot);

            if (!$item) {
                return [
                    'tipo' => 'erro',
                    'mensagem' => 'Você não possui esse item no inventário.'
                ];
            }

            $nomeExibicao = $item['nome'] ?? 'Item desconhecido';

            if ((int)($item['equipado'] ?? 0) !== 1) {
                return [
                    'tipo' => 'erro',
                    'mensagem' => $nomeExibicao . ' não está equipado.'
                ];
            }

            $stmtDesequipar = $this->pdo->prepare("
                UPDATE inventario
                SET equipado = 0, slot_equipamento = NULL
                WHERE id = ?
            ");
            $stmtDesequipar->execute([$item['id']]);

            if (method_exists($player, 'recarregar')) {
                $player->recarregar();
            }

            return [
                'tipo' => 'item',
                'mensagem' => $player->getNome() . ' desequipou ' . $nomeExibicao . '.'
            ];
        } catch (Throwable $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao desequipar o item.'
            ];
        }
    }

    private function consumirItemInventario(int $inventarioId, int $quantidadeAtual): void
    {
        $novaQuantidade = $quantidadeAtual - 1;

        if ($novaQuantidade > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE inventario
                SET quantidade = ?
                WHERE id = ?
            ");
            $stmt->execute([$novaQuantidade, $inventarioId]);
            return;
        }

        $stmt = $this->pdo->prepare("
            DELETE FROM inventario
            WHERE id = ?
        ");
        $stmt->execute([$inventarioId]);
    }

    private function aplicarEfeitoAoUsuario(Player $player, array $efeito): array
    {
        $tipoEfeito = trim((string)($efeito['efeito'] ?? ''));
        $valor = (int)($efeito['valor'] ?? 0);
        $consumirItem = !empty($efeito['consome_ao_ativar']);

        $dados = $player->getDados();
        $vidaAtual = (int)($dados['vida_atual'] ?? 0);
        $vidaMax = (int)($dados['vida_max'] ?? 0);

        if ($tipoEfeito === '') {
            return [
                'sucesso' => false,
                'mensagem' => 'Efeito inválido.',
                'consumir_item' => false
            ];
        }

        if ($valor <= 0) {
            return [
                'sucesso' => false,
                'mensagem' => 'Valor do efeito inválido.',
                'consumir_item' => false
            ];
        }

        if ($tipoEfeito === 'cura') {
            if ($vidaAtual >= $vidaMax) {
                return [
                    'sucesso' => false,
                    'mensagem' => 'Sua vida já está completa.',
                    'consumir_item' => false
                ];
            }

            $novaVida = min($vidaAtual + $valor, $vidaMax);
            $valorAplicado = $novaVida - $vidaAtual;

            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET vida_atual = ?
                WHERE id = ?
            ");
            $stmt->execute([$novaVida, $player->getId()]);

            $combate = new Combate($this->pdo);
            $combate->recalcularEstadoVidaPublico($player->getId());

            return [
                'sucesso' => true,
                'tipo_resultado' => 'cura',
                'valor_aplicado' => $valorAplicado,
                'vida_anterior' => $vidaAtual,
                'vida_nova' => $novaVida,
                'consumir_item' => $consumirItem
            ];
        }

        if ($tipoEfeito === 'dano') {
            $novaVida = max(0, $vidaAtual - $valor);
            $valorAplicado = $vidaAtual - $novaVida;

            $stmt = $this->pdo->prepare("
                UPDATE personagens
                SET vida_atual = ?
                WHERE id = ?
            ");
            $stmt->execute([$novaVida, $player->getId()]);

            $combate = new Combate($this->pdo);
            $combate->recalcularEstadoVidaPublico($player->getId());

            return [
                'sucesso' => true,
                'tipo_resultado' => 'dano',
                'valor_aplicado' => $valorAplicado,
                'vida_anterior' => $vidaAtual,
                'vida_nova' => $novaVida,
                'consumir_item' => $consumirItem
            ];
        }

        return [
            'sucesso' => false,
            'mensagem' => 'O efeito "' . $tipoEfeito . '" ainda não foi implementado.',
            'consumir_item' => false
        ];
    }

    public function usarItem(Player $player, string $nomeItem): array
    {
        $nomeItem = trim($nomeItem);

        if ($nomeItem === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o item que deseja usar. Exemplo: /item usar pocao pequena'
            ];
        }

        $item = $this->buscarItemNoInventarioPorNome($player, $nomeItem);

        if (!$item) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Você não possui esse item no inventário.'
            ];
        }

        $nomeExibicao = $item['nome'] ?? 'Item desconhecido';
        $objetoId = (int)($item['objeto_id'] ?? 0);

        if ($objetoId <= 0) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Objeto inválido no inventário.'
            ];
        }

        $efeitos = $this->buscarEfeitosObjeto($objetoId, 'ao_usar');

        if (empty($efeitos)) {
            return [
                'tipo' => 'erro',
                'mensagem' => $nomeExibicao . ' ainda não possui efeitos configurados para uso.'
            ];
        }

        try {
            $this->pdo->beginTransaction();

            $mensagens = [];
            $deveConsumirItem = false;

            foreach ($efeitos as $efeito) {
                $alvo = trim((string)($efeito['alvo'] ?? 'usuario'));

                if (!in_array($alvo, ['usuario', 'portador'], true)) {
                    throw new Exception('Alvo de efeito ainda não suportado: ' . $alvo);
                }

                $resultado = $this->aplicarEfeitoAoUsuario($player, $efeito);

                if (!$resultado['sucesso']) {
                    $this->pdo->rollBack();

                    return [
                        'tipo' => 'erro',
                        'mensagem' => $resultado['mensagem'] ?? 'Não foi possível usar o item.'
                    ];
                }

                if (!empty($resultado['consumir_item'])) {
                    $deveConsumirItem = true;
                }

                $tipoResultado = $resultado['tipo_resultado'] ?? '';
                $valorAplicado = (int)($resultado['valor_aplicado'] ?? 0);

                if ($tipoResultado === 'cura') {
                    $mensagens[] = 'recupera ' . $valorAplicado . ' de vida';
                } elseif ($tipoResultado === 'dano') {
                    $mensagens[] = 'sofre ' . $valorAplicado . ' de dano';
                }
            }

            if ($deveConsumirItem) {
                $this->consumirItemInventario((int)$item['id'], (int)$item['quantidade']);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao usar o item.'
            ];
        }

        if (method_exists($player, 'recarregar')) {
            $player->recarregar();
        }

        $mensagemFinal = 'Você usa ' . $nomeExibicao;

        if (!empty($mensagens)) {
            $mensagemFinal .= ' e ' . implode(', ', $mensagens) . '.';
        } else {
            $mensagemFinal .= '.';
        }

        return [
            'tipo' => 'item',
            'mensagem' => $mensagemFinal
        ];
    }

    public function soltarItem(Player $player, string $nomeItem): array
    {
        $nomeItem = trim($nomeItem);

        if ($nomeItem === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Informe o item que deseja soltar. Exemplo: /item soltar pocao pequena'
            ];
        }

        $item = $this->buscarItemNoInventarioPorNome($player, $nomeItem);

        if (!$item) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Você não possui esse item no inventário.'
            ];
        }

        $objetoId = (int)$item['objeto_id'];
        $nomeExibicao = $item['nome'] ?? 'Item desconhecido';

        $dadosPlayer = $player->getDados();
        $localId = (int)$dadosPlayer['local_atual_id'];

        try {
            $stmtLocal = $this->pdo->prepare("
                SELECT id, quantidade
                FROM locais_objetos
                WHERE local_id = ?
                  AND objeto_id = ?
                LIMIT 1
            ");
            $stmtLocal->execute([$localId, $objetoId]);

            $objetoLocal = $stmtLocal->fetch(PDO::FETCH_ASSOC);

            if ($objetoLocal) {
                $novaQuantidade = (int)$objetoLocal['quantidade'] + 1;

                $stmtUpdateLocal = $this->pdo->prepare("
                    UPDATE locais_objetos
                    SET quantidade = ?
                    WHERE id = ?
                ");
                $stmtUpdateLocal->execute([$novaQuantidade, $objetoLocal['id']]);
            } else {
                $stmtInsertLocal = $this->pdo->prepare("
                    INSERT INTO locais_objetos
                    (local_id, objeto_id, visivel, pegavel, interagivel, fixo, quantidade, estado, ativo)
                    VALUES (?, ?, 1, 1, 0, 0, 1, 'normal', 1)
                ");
                $stmtInsertLocal->execute([$localId, $objetoId]);
            }

            $quantidadeAtual = (int)$item['quantidade'];
            $novaQuantidade = $quantidadeAtual - 1;

            if ($novaQuantidade > 0) {
                $stmtInv = $this->pdo->prepare("
                    UPDATE inventario
                    SET quantidade = ?
                    WHERE id = ?
                ");
                $stmtInv->execute([$novaQuantidade, $item['id']]);
            } else {
                $stmtInv = $this->pdo->prepare("
                    DELETE FROM inventario
                    WHERE id = ?
                ");
                $stmtInv->execute([$item['id']]);
            }
        } catch (PDOException $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Erro ao soltar o item.'
            ];
        }

        return [
            'tipo' => 'item',
            'mensagem' => $player->getNome() . ' soltou ' . $nomeExibicao . ' no chão.'
        ];
    }

    public function listarEquipamentos(Player $player): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                i.slot_equipamento,
                o.nome,
                o.tipo
            FROM inventario i
            INNER JOIN objetos o ON o.id = i.objeto_id
            WHERE i.personagem_id = ?
              AND i.equipado = 1
              AND i.slot_equipamento IS NOT NULL
            ORDER BY i.slot_equipamento ASC
        ");
        $stmt->execute([$player->getId()]);

        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $equipamentos = [
            'arma' => null,
            'escudo' => null,
            'armadura' => null,
        ];

        foreach ($itens as $item) {
            $slot = trim((string)($item['slot_equipamento'] ?? ''));

            if (array_key_exists($slot, $equipamentos)) {
                $equipamentos[$slot] = $item['nome'];
            }
        }

        $linhas = [];
        $linhas[] = 'Equipamentos de ' . $player->getNome() . ':';
        $linhas[] = '';
        $linhas[] = 'Arma: ' . ($equipamentos['arma'] ?? 'Nenhuma');
        $linhas[] = 'Escudo: ' . ($equipamentos['escudo'] ?? 'Nenhum');
        $linhas[] = 'Armadura: ' . ($equipamentos['armadura'] ?? 'Nenhuma');

        return [
            'tipo' => 'item',
            'mensagem' => implode("\n", $linhas)
        ];
    }

    public function processarAcaoItem(Player $player, string $descricao, array $localAtual): array
    {
        $descricao = trim($descricao);

        if ($descricao === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Use algo como /item olhar freezer, /item pegar picole, /item abrir freezer, /item equipar espada de ferro, /item desequipar escudo, /item usar pocao pequena ou /item soltar pocao pequena'
            ];
        }

        if (preg_match('/^equipamentos$/iu', $descricao)) {
            return $this->listarEquipamentos($player);
        }

        if (preg_match('/^olhar\s+(.+)$/iu', $descricao, $matches)) {
            return $this->olharObjeto($player, trim($matches[1]), $localAtual);
        }

        if (preg_match('/^pegar\s+(.+)$/iu', $descricao, $matches)) {
            return $this->pegarObjeto($player, trim($matches[1]), $localAtual);
        }

        if (preg_match('/^abrir\s+(.+)$/iu', $descricao, $matches)) {
            return $this->abrirObjeto($player, trim($matches[1]), $localAtual);
        }

        if (preg_match('/^equipar\s+(.+)$/iu', $descricao, $matches)) {
            return $this->equiparItem($player, trim($matches[1]));
        }

        if (preg_match('/^desequipar\s+(.+)$/iu', $descricao, $matches)) {
            return $this->desequiparItem($player, trim($matches[1]));
        }

        if (preg_match('/^usar\s+(.+)$/iu', $descricao, $matches)) {
            return $this->usarItem($player, trim($matches[1]));
        }

        if (preg_match('/^soltar\s+(.+)$/iu', $descricao, $matches)) {
            return $this->soltarItem($player, trim($matches[1]));
        }

        return [
            'tipo' => 'item',
            'mensagem' => $player->getNome() . ' tenta interagir com objetos, mas a ação ainda não foi reconhecida.'
        ];
    }
}