<?php

class Combate
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function buscarPersonagemPorId(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
        SELECT * 
        FROM personagens
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$id]);

        $personagem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$personagem) {
            return null;
        }

        $bonusEquipamento = $this->calcularBonusEquipamento($id);
        $personagem = array_merge($personagem, $bonusEquipamento);

        $defesaBase = $this->calcularDefesaBase($personagem);
        $defesaTotal = $this->calcularDefesaTotal($personagem);

        $bonusLuta = $this->buscarBonusPericia((int)$personagem['id'], 'Luta');
        $bonusReflexos = $this->buscarBonusPericia((int)$personagem['id'], 'Reflexos');
        $bonusBloqueioEquip = $this->obterBonusBloqueioEquipamento($personagem);

        $personagem['defesa_base'] = $defesaBase;
        $personagem['defesa_total'] = $defesaTotal;
        $personagem['defesa_bloqueio'] = $defesaTotal + $bonusLuta + $bonusBloqueioEquip;
        $personagem['defesa_esquiva'] = $defesaTotal + $bonusReflexos;

        return $personagem;
    }

    // Bonus de equipamentos
    private function calcularBonusEquipamento(int $personagemId): array
    {
        $stmt = $this->pdo->prepare("
        SELECT
            i.id,
            i.objeto_id,
            i.equipado,
            i.slot_equipamento,
            o.nome,
            o.slug,
            o.tipo,
            o.subtipo,
            oe.efeito,
            oe.valor,
            oe.gatilho,
            oe.alvo,
            oe.exige_equipado,
            oe.ordem_execucao
        FROM inventario i
        INNER JOIN objetos o ON o.id = i.objeto_id
        LEFT JOIN objetos_efeitos oe ON oe.objeto_id = o.id
            AND oe.gatilho = 'passivo'
            AND oe.ativo = 1
        WHERE i.personagem_id = ?
          AND i.equipado = 1
        ORDER BY i.slot_equipamento ASC, oe.ordem_execucao ASC, oe.id ASC
    ");
        $stmt->execute([$personagemId]);

        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bonus = [
            'bonus_defesa_armadura' => 0,
            'bonus_defesa_escudo' => 0,
            'bonus_defesa_extra' => 0,
            'bonus_ataque_fisico' => 0,
            'bonus_bloqueio' => 0,
            'reducao_dano_fisico' => 0,
            'reducao_dano_magico' => 0,
            'dano_arma_base' => 0,
            'arma_equipada_nome' => null,
            'escudo_equipado_nome' => null,
            'armadura_equipada_nome' => null,
        ];

        $objetosProcessados = [];

        foreach ($linhas as $linha) {
            $objetoId = (int)($linha['objeto_id'] ?? 0);
            $tipo = trim((string)($linha['tipo'] ?? ''));
            $nome = $linha['nome'] ?? null;
            $efeito = trim((string)($linha['efeito'] ?? ''));
            $valor = (int)($linha['valor'] ?? 0);
            $exigeEquipado = (int)($linha['exige_equipado'] ?? 0);

            if ($exigeEquipado === 1 && (int)($linha['equipado'] ?? 0) !== 1) {
                continue;
            }

            if (!isset($objetosProcessados[$objetoId])) {
                if ($tipo === 'arma' || $tipo === 'item_amaldicoado') {
                    $bonus['arma_equipada_nome'] = $nome;
                }

                if ($tipo === 'escudo') {
                    $bonus['escudo_equipado_nome'] = $nome;
                }

                if ($tipo === 'armadura') {
                    $bonus['armadura_equipada_nome'] = $nome;
                }

                $objetosProcessados[$objetoId] = true;
            }

            if ($efeito === '' || $valor === 0) {
                continue;
            }

            switch ($efeito) {
                case 'defesa':
                    if ($tipo === 'armadura') {
                        $bonus['bonus_defesa_armadura'] += $valor;
                    } elseif ($tipo === 'escudo') {
                        $bonus['bonus_defesa_escudo'] += $valor;
                    } else {
                        $bonus['bonus_defesa_extra'] += $valor;
                    }
                    break;

                case 'bloqueio':
                    $bonus['bonus_bloqueio'] += $valor;
                    break;

                case 'bonus_ataque':
                    $bonus['bonus_ataque_fisico'] += $valor;
                    break;

                case 'dano_base':
                    $bonus['dano_arma_base'] = max($bonus['dano_arma_base'], $valor);
                    break;

                case 'reducao_fisica':
                    $bonus['reducao_dano_fisico'] += $valor;
                    break;

                case 'reducao_magica':
                    $bonus['reducao_dano_magico'] += $valor;
                    break;
            }
        }

        return $bonus;
    }

    /**
     * Defesa base:
     * 10 + AGI
     */
    public function calcularDefesaBase(array $personagem): int
    {
        $agi = max(0, min(6, (int)($personagem['atributos_agi'] ?? 0)));

        return 10 + $agi;
    }

    /**
     * Defesa total:
     * Defesa base + armadura + escudo + bônus extras
     */
    public function calcularDefesaTotal(array $personagem): int
    {
        $defesa = $this->calcularDefesaBase($personagem);

        $bonusArmadura = (int)($personagem['bonus_defesa_armadura'] ?? 0);
        $bonusEscudo = (int)($personagem['bonus_defesa_escudo'] ?? 0);
        $bonusExtra = (int)($personagem['bonus_defesa_extra'] ?? 0);

        return $defesa + $bonusArmadura + $bonusEscudo + $bonusExtra;
    }

    // Bonus de perícias
    public function buscarBonusPericia(int $personagemId, string $nomePericia): int
    {
        $stmt = $this->pdo->prepare("
            SELECT bonus
            FROM pericias
            WHERE personagem_id = ?
              AND nome_pericia = ?
            LIMIT 1
        ");
        $stmt->execute([$personagemId, $nomePericia]);

        $pericia = $stmt->fetch();

        return $pericia ? (int)$pericia['bonus'] : 0;
    }

    /**
     * Rola X d20, pega o maior, soma bônus.
     */
    private function rolarTestePorAtributo(int $quantidadeDados, int $bonus = 0): array
    {
        $quantidadeDados = max(1, min(6, $quantidadeDados));

        $resultados = [];

        for ($i = 0; $i < $quantidadeDados; $i++) {
            $resultados[] = rand(1, 20);
        }

        $maiorDado = max($resultados);
        $total = $maiorDado + $bonus;

        return [
            'dados' => $resultados,
            'maior_dado' => $maiorDado,
            'bonus' => $bonus,
            'total' => $total
        ];
    }

    //teste de iniciativa
    public function rolarIniciativa(array $personagem): array
    {
        $agilidade = max(1, min(6, (int)($personagem['atributos_agi'] ?? 1)));
        $bonusIniciativa = $this->buscarBonusPericia((int)$personagem['id'], 'Iniciativa');

        $teste = $this->rolarTestePorAtributo($agilidade, $bonusIniciativa);

        return [
            'dados' => $teste['dados'],
            'maior_dado' => $teste['maior_dado'],
            'bonus' => $bonusIniciativa,
            'total' => $teste['total'],
        ];
    }

    public function disputarIniciativa(array $player, array $npc): array
    {
        $iniciativaPlayer = $this->rolarIniciativa($player);
        $iniciativaNpc = $this->rolarIniciativa($npc);

        $primeiro = 'player';

        if ($iniciativaNpc['total'] > $iniciativaPlayer['total']) {
            $primeiro = 'npc';
        } elseif ($iniciativaNpc['total'] === $iniciativaPlayer['total']) {
            $agiPlayer = (int)($player['atributos_agi'] ?? 0);
            $agiNpc = (int)($npc['atributos_agi'] ?? 0);

            if ($agiNpc > $agiPlayer) {
                $primeiro = 'npc';
            }
        }

        return [
            'player' => $iniciativaPlayer,
            'npc' => $iniciativaNpc,
            'primeiro' => $primeiro,
            'mensagem' =>
            'Iniciativa - '
                . $player['nome'] . ': [' . implode(', ', $iniciativaPlayer['dados']) . ']'
                . ', maior dado: ' . $iniciativaPlayer['maior_dado']
                . ', bônus: ' . $iniciativaPlayer['bonus']
                . ', total: ' . $iniciativaPlayer['total']
                . ' || '
                . $npc['nome'] . ': [' . implode(', ', $iniciativaNpc['dados']) . ']'
                . ', maior dado: ' . $iniciativaNpc['maior_dado']
                . ', bônus: ' . $iniciativaNpc['bonus']
                . ', total: ' . $iniciativaNpc['total']
                . '. Quem começa: ' . ($primeiro === 'player' ? $player['nome'] : $npc['nome']) . '.'
        ];
    }

    // Debug de dano
    public function aplicarDanoDebug(int $personagemId, int $dano, string $origem = 'debug'): array
    {
        $personagem = $this->buscarPersonagemPorId($personagemId);

        if (!$personagem) {
            throw new Exception('Personagem não encontrado para dano debug.');
        }

        return $this->aplicarDano(
            $personagemId,
            (int)$personagem['vida_atual'],
            $dano,
            $origem
        );
    }

    public function recalcularEstadoVidaPublico(int $personagemId): array
    {
        return $this->recalcularEstadoVida($personagemId);
    }

    /**
     * Dano provisório da arma enquanto o arsenal completo não existe.
     */
    private function obterDanoArma(array $atacante, int $danoMin, int $danoMax): int
    {
        $danoArmaBase = (int)($atacante['dano_arma_base'] ?? 0);

        if ($danoArmaBase > 0) {
            return $danoArmaBase;
        }

        return rand($danoMin, $danoMax);
    }

    /**
     * Bônus de ataque físico vindo de arma/equipamento.
     */
    private function obterBonusAtaqueFisicoEquipamento(array $personagem): int
    {
        return (int)($personagem['bonus_ataque_fisico'] ?? 0);
    }

    /**
     * Bônus extra de bloqueio por equipamento.
     */
    private function obterBonusBloqueioEquipamento(array $personagem): int
    {
        return (int)($personagem['bonus_bloqueio'] ?? 0);
    }

    /**
     * Redução de dano físico.
     */
    private function obterReducaoDanoFisico(array $personagem): int
    {
        return (int)($personagem['reducao_dano_fisico'] ?? 0);
    }

    /**
     * Redução de dano mágico.
     */
    private function obterReducaoDanoMagico(array $personagem): int
    {
        return (int)($personagem['reducao_dano_magico'] ?? 0);
    }

    public function rolarAtaqueFisico(array $atacante, array $alvo): array
    {
        return $this->executarAtaqueFisico(
            $atacante,
            $alvo,
            5,
            10,
            false
        );
    }

    public function rolarAtaqueNpc(array $npc, array $alvo): array
    {
        return $this->executarAtaqueFisico(
            $npc,
            $alvo,
            4,
            8,
            true
        );
    }

    private function executarAtaqueFisico(
        array $atacante,
        array $alvo,
        int $danoMin,
        int $danoMax,
        bool $ataqueNpc = false
    ): array {
        $forca = max(1, min(6, (int)($atacante['atributos_for'] ?? 1)));
        $bonusLuta = $this->buscarBonusPericia((int)$atacante['id'], 'Luta');
        $bonusAtaqueEquip = $this->obterBonusAtaqueFisicoEquipamento($atacante);

        $testeAtaque = $this->rolarTestePorAtributo($forca, $bonusLuta + $bonusAtaqueEquip);

        $totalAtaque = $testeAtaque['total'];
        $maiorDado = $testeAtaque['maior_dado'];

        $resultadoDefesa = $this->resolverDefesa($totalAtaque, $alvo);
        $defesaComparacao = $resultadoDefesa['defesa_usada'] ?? $this->calcularDefesaTotal($alvo);

        // Falha crítica:
        // acontece quando o total do ataque fica 6 ou mais abaixo da defesa usada
        if ($totalAtaque <= ($defesaComparacao - 6)) {
            return [
                'sucesso' => false,
                'critico_falha' => true,
                'mensagem' => $atacante['nome']
                    . ' falhou criticamente no ataque. Rolagem: ['
                    . implode(', ', $testeAtaque['dados'])
                    . '], maior dado: ' . $maiorDado
                    . ', bônus total: ' . ($bonusLuta + $bonusAtaqueEquip)
                    . ', total do ataque: ' . $totalAtaque
                    . ', defesa alvo: ' . $defesaComparacao . '.'
            ];
        }

        if ($resultadoDefesa['tipo'] === 'anulado') {
            $mensagem = $resultadoDefesa['mensagem']
                . ' Rolagem de ataque: ['
                . implode(', ', $testeAtaque['dados'])
                . '], maior dado: ' . $maiorDado
                . ', bônus total: ' . ($bonusLuta + $bonusAtaqueEquip)
                . ', total do ataque: ' . $totalAtaque . '.';

            $resultadoFinal = [
                'sucesso' => false,
                'defendido' => true,
                'mensagem' => $mensagem
            ];

            if (!empty($resultadoDefesa['gera_contra_ataque'])) {
                $contraAtaque = $this->executarContraAtaque($alvo, $atacante, $ataqueNpc);

                $resultadoFinal['mensagem'] .= ' ' . $contraAtaque['mensagem'];
                $resultadoFinal['contra_ataque'] = true;
                $resultadoFinal['contra_ataque_resultado'] = $contraAtaque;
            }

            return $resultadoFinal;
        }

        $danoArma = $this->obterDanoArma($atacante, $danoMin, $danoMax);

        // Regra canônica:
        // dano normal = arma + FOR
        $danoBruto = $danoArma + $forca;

        // Regra canônica:
        // crítico quando ultrapassa a defesa por 5 ou mais
        $critico = ($totalAtaque >= ($defesaComparacao + 5));

        if ($critico) {
            // Regra canônica:
            // dano crítico = 2 * arma + 2 * FOR
            $danoBruto = ($danoArma * 2) + ($forca * 2);
        }

        $danoAposDefesaParcial = $danoBruto;

        if ($resultadoDefesa['tipo'] === 'parcial') {
            $danoAposDefesaParcial = (int) ceil($danoBruto / 2);
        }

        // Defesa não reduz dano.
        // Só redução específica reduz dano.
        $reducaoFisica = $this->obterReducaoDanoFisico($alvo);

        $danoFinal = $danoAposDefesaParcial;

        if ($reducaoFisica > 0) {
            $danoFinal = max(0, $danoAposDefesaParcial - $reducaoFisica);
        }

        $resultadoDano = $this->aplicarDano(
            (int)$alvo['id'],
            (int)$alvo['vida_atual'],
            $danoFinal,
            'ataque físico'
        );

        $mensagem = $atacante['nome']
            . ' acertou ' . $alvo['nome']
            . ' e causou ' . $danoFinal . ' de dano.';

        $mensagem .= ' Rolagem: [' . implode(', ', $testeAtaque['dados']) . ']';
        $mensagem .= ', maior dado: ' . $maiorDado;
        $mensagem .= ', bônus de Luta: ' . $bonusLuta;
        if ($bonusAtaqueEquip !== 0) {
            $mensagem .= ', bônus de equipamento: ' . $bonusAtaqueEquip;
        }
        $mensagem .= ', total do ataque: ' . $totalAtaque . '.';
        $mensagem .= ' Defesa alvo: ' . $defesaComparacao . '.';

        if ($critico) {
            $mensagem .= $ataqueNpc ? ' Ataque crítico do inimigo!' : ' Ataque crítico!';
        }

        if ($resultadoDefesa['tipo'] === 'parcial') {
            $mensagem = $resultadoDefesa['mensagem'] . ' ' . $mensagem;
            $mensagem .= ' Dano após defesa parcial: ' . $danoAposDefesaParcial . '.';
        }

        if ($reducaoFisica > 0) {
            $reducaoAplicada = max(0, $danoAposDefesaParcial - $danoFinal);

            if ($reducaoAplicada > 0) {
                $mensagem .= ' ' . $alvo['nome'] . ' reduziu ' . $reducaoAplicada . ' de dano físico.';
            }
        }

        if ($danoBruto !== $danoFinal) {
            $mensagem .= ' Dano bruto: ' . $danoBruto . '. Dano final: ' . $danoFinal . '.';
        }

        if (!empty($resultadoDano['morte_instantanea'])) {
            $mensagem .= ' ' . $alvo['nome'] . ' sofreu morte instantânea.';
        } elseif (!empty($resultadoDano['entrou_morrendo'])) {
            $mensagem .= ' ' . $alvo['nome'] . ' caiu inconsciente e está morrendo.';
        } elseif (($resultadoDano['estado_vida'] ?? '') === 'inconsciente') {
            $mensagem .= ' ' . $alvo['nome'] . ' caiu inconsciente.';
        }

        return [
            'sucesso' => true,
            'critico' => $critico,
            'dano' => $danoFinal,
            'vida_restante_alvo' => (int)$resultadoDano['vida_atual'],
            'mensagem' => $mensagem
        ];
    }


    //contra ataque
    private function executarContraAtaque(array $defensor, array $atacanteOriginal, bool $ataqueNpcOriginal = false): array
    {
        $forca = max(1, min(6, (int)($defensor['atributos_for'] ?? 1)));
        $bonusLuta = $this->buscarBonusPericia((int)$defensor['id'], 'Luta');
        $bonusAtaqueEquip = $this->obterBonusAtaqueFisicoEquipamento($defensor);

        $testeAtaque = $this->rolarTestePorAtributo($forca, $bonusLuta + $bonusAtaqueEquip);

        $totalAtaque = $testeAtaque['total'];
        $maiorDado = $testeAtaque['maior_dado'];

        $defesaAlvo = $this->calcularDefesaTotal($atacanteOriginal);

        if ($totalAtaque < $defesaAlvo) {
            return [
                'sucesso' => false,
                'mensagem' => $defensor['nome']
                    . ' tentou contra-atacar, mas errou. Rolagem: ['
                    . implode(', ', $testeAtaque['dados'])
                    . '], maior dado: ' . $maiorDado
                    . ', bônus total: ' . ($bonusLuta + $bonusAtaqueEquip)
                    . ', total: ' . $totalAtaque
                    . ', defesa alvo: ' . $defesaAlvo . '.'
            ];
        }

        $danoArma = $this->obterDanoArma($defensor, 5, 10);
        $danoBase = $danoArma + $forca;

        $critico = ($totalAtaque >= ($defesaAlvo + 5));
        if ($critico) {
            $danoBase = ($danoArma * 2) + ($forca * 2);
        }

        $reducaoFisica = $this->obterReducaoDanoFisico($atacanteOriginal);
        $danoFinal = max(0, $danoBase - $reducaoFisica);

        $resultadoDano = $this->aplicarDano(
            (int)$atacanteOriginal['id'],
            (int)$atacanteOriginal['vida_atual'],
            $danoFinal,
            'contra-ataque'
        );

        $mensagem = $defensor['nome']
            . ' respondeu com um contra-ataque e causou ' . $danoFinal . ' de dano.'
            . ' Rolagem: [' . implode(', ', $testeAtaque['dados']) . ']'
            . ', maior dado: ' . $maiorDado
            . ', bônus de Luta: ' . $bonusLuta;

        if ($bonusAtaqueEquip !== 0) {
            $mensagem .= ', bônus de equipamento: ' . $bonusAtaqueEquip;
        }

        $mensagem .= ', total do ataque: ' . $totalAtaque . '.';
        $mensagem .= ' Defesa alvo: ' . $defesaAlvo . '.';

        if ($critico) {
            $mensagem .= ' Contra-ataque crítico!';
        }

        if ($reducaoFisica > 0) {
            $mensagem .= ' ' . $atacanteOriginal['nome'] . ' reduziu ' . $reducaoFisica . ' de dano físico.';
        }

        if (!empty($resultadoDano['morte_instantanea'])) {
            $mensagem .= ' ' . $atacanteOriginal['nome'] . ' sofreu morte instantânea.';
        } elseif (!empty($resultadoDano['entrou_morrendo'])) {
            $mensagem .= ' ' . $atacanteOriginal['nome'] . ' caiu inconsciente e está morrendo.';
        } elseif (($resultadoDano['estado_vida'] ?? '') === 'inconsciente') {
            $mensagem .= ' ' . $atacanteOriginal['nome'] . ' caiu inconsciente.';
        }

        return [
            'sucesso' => true,
            'critico' => $critico,
            'dano' => $danoFinal,
            'mensagem' => $mensagem
        ];
    }


    //Defesa
    private function resolverDefesa(int $totalAtaque, array $alvo): array
    {
        $reacao = $alvo['reacao_defensiva'] ?? null;
        $defesaBase = $this->calcularDefesaTotal($alvo);
        $posturaDefensiva = !empty($alvo['postura_defensiva']);
        $bonusPosturaDefensiva = $posturaDefensiva ? 3 : 0;

        $bonusLuta = $this->buscarBonusPericia((int)$alvo['id'], 'Luta');
        $bonusReflexos = $this->buscarBonusPericia((int)$alvo['id'], 'Reflexos');
        $bonusBloqueioEquip = $this->obterBonusBloqueioEquipamento($alvo);

        $defesaBloqueio = $defesaBase + $bonusLuta + $bonusBloqueioEquip;
        $defesaEsquiva = $defesaBase + $bonusReflexos;

        // POSTURA DEFENSIVA:
        // pega a melhor entre bloqueio e esquiva, e soma o bônus da postura
        if ($posturaDefensiva) {
            $melhorDefesaPostura = max($defesaBloqueio, $defesaEsquiva) + $bonusPosturaDefensiva;

            if ($totalAtaque < $melhorDefesaPostura) {
                return [
                    'tipo' => 'anulado',
                    'mensagem' => $alvo['nome'] . ' sustentou o golpe com sua postura defensiva.',
                    'defesa_usada' => $melhorDefesaPostura
                ];
            }

            if ($melhorDefesaPostura >= ($totalAtaque - 3)) {
                return [
                    'tipo' => 'parcial',
                    'mensagem' => $alvo['nome'] . ' absorveu parte do impacto com sua postura defensiva.',
                    'defesa_usada' => $melhorDefesaPostura
                ];
            }

            return [
                'tipo' => 'nenhuma',
                'mensagem' => '',
                'defesa_usada' => $melhorDefesaPostura
            ];
        }

        // Sem reação ativa: só compara contra defesa base
        if (!$reacao || !is_array($reacao)) {
            if ($totalAtaque < $defesaBase) {
                return [
                    'tipo' => 'anulado',
                    'mensagem' => $alvo['nome'] . ' evitou o golpe com sua defesa.',
                    'defesa_usada' => $defesaBase
                ];
            }

            return [
                'tipo' => 'nenhuma',
                'mensagem' => '',
                'defesa_usada' => $defesaBase
            ];
        }

        $tipo = $reacao['tipo'] ?? '';


        if ($tipo === 'contra_ataque') {
            if ($defesaBase > $totalAtaque) {
                return [
                    'tipo' => 'anulado',
                    'mensagem' => $alvo['nome'] . ' leu o golpe e abriu espaço para um contra-ataque.',
                    'defesa_usada' => $defesaBase,
                    'gera_contra_ataque' => true
                ];
            }

            return [
                'tipo' => 'nenhuma',
                'mensagem' => '',
                'defesa_usada' => $defesaBase,
                'gera_contra_ataque' => false
            ];
        }

        if ($tipo === 'bloqueio') {
            if ($defesaBloqueio >= $totalAtaque) {
                return [
                    'tipo' => 'anulado',
                    'mensagem' => $alvo['nome'] . ' bloqueou completamente o ataque.',
                    'defesa_usada' => $defesaBloqueio
                ];
            }

            if ($defesaBloqueio >= ($totalAtaque - 3)) {
                return [
                    'tipo' => 'parcial',
                    'mensagem' => $alvo['nome'] . ' bloqueou parte do impacto.',
                    'defesa_usada' => $defesaBloqueio
                ];
            }

            return [
                'tipo' => 'nenhuma',
                'mensagem' => '',
                'defesa_usada' => $defesaBloqueio
            ];
        }

        if ($tipo === 'esquiva') {
            if ($defesaEsquiva >= $totalAtaque) {
                return [
                    'tipo' => 'anulado',
                    'mensagem' => $alvo['nome'] . ' esquivou completamente do ataque.',
                    'defesa_usada' => $defesaEsquiva
                ];
            }

            if ($defesaEsquiva >= ($totalAtaque - 3)) {
                return [
                    'tipo' => 'parcial',
                    'mensagem' => $alvo['nome'] . ' esquivou parcialmente do golpe.',
                    'defesa_usada' => $defesaEsquiva
                ];
            }

            return [
                'tipo' => 'nenhuma',
                'mensagem' => '',
                'defesa_usada' => $defesaEsquiva
            ];
        }

        if ($totalAtaque < $defesaBase) {
            return [
                'tipo' => 'anulado',
                'mensagem' => $alvo['nome'] . ' evitou o golpe com sua defesa.',
                'defesa_usada' => $defesaBase
            ];
        }

        return [
            'tipo' => 'nenhuma',
            'mensagem' => '',
            'defesa_usada' => $defesaBase
        ];
    }


    //dano aplicado
    private function aplicarDano(int $personagemId, int $vidaAtual, int $dano, string $origem = 'ataque'): array
    {
        $personagem = $this->buscarPersonagemPorId($personagemId);

        if (!$personagem) {
            throw new Exception('Personagem não encontrado para aplicar dano.');
        }

        if (!empty($personagem['morto'])) {
            return [
                'vida_anterior' => (int)$personagem['vida_atual'],
                'vida_atual' => (int)$personagem['vida_atual'],
                'dano' => 0,
                'morte_instantanea' => false,
                'entrou_morrendo' => false,
                'morreu' => true,
                'estado_vida' => 'morto'
            ];
        }

        $vidaAnterior = (int)$personagem['vida_atual'];
        $vidaMax = max(1, (int)$personagem['vida_max']);
        $limiteMorteInstantanea = (int) ceil($vidaMax * 0.9);

        if ($dano >= $limiteMorteInstantanea) {
            $vidaNova = max(0, $vidaAnterior - $dano);

            $stmt = $this->pdo->prepare("
            UPDATE personagens
            SET vida_atual = ?
            WHERE id = ?
        ");
            $stmt->execute([$vidaNova, $personagemId]);

            $this->marcarComoMorto($personagemId, 'Morte instantânea por ' . $origem);

            return [
                'vida_anterior' => $vidaAnterior,
                'vida_atual' => $vidaNova,
                'dano' => $dano,
                'morte_instantanea' => true,
                'entrou_morrendo' => false,
                'morreu' => true,
                'estado_vida' => 'morto'
            ];
        }

        $vidaNova = $vidaAnterior - $dano;

        if ($vidaNova < 0) {
            $vidaNova = 0;
        }

        $stmt = $this->pdo->prepare("
        UPDATE personagens
        SET vida_atual = ?
        WHERE id = ?
    ");
        $stmt->execute([$vidaNova, $personagemId]);

        $estadoAntes = trim((string)($personagem['estado_vida'] ?? 'saudavel'));
        $morrendoAntes = (int)($personagem['morrendo'] ?? 0);

        $estado = $this->recalcularEstadoVida($personagemId);

        $entrouMorrendoAgora = (
            $vidaNova <= 0 &&
            $morrendoAntes === 0 &&
            !empty($estado['morrendo'])
        );

        return [
            'vida_anterior' => $vidaAnterior,
            'vida_atual' => $vidaNova,
            'dano' => $dano,
            'morte_instantanea' => false,
            'entrou_morrendo' => $entrouMorrendoAgora,
            'morreu' => !empty($estado['morto']),
            'estado_vida' => $estado['estado_vida'] ?? $estadoAntes,
            'turnos_restantes_morte' => (int)($estado['turnos_restantes_morte'] ?? 0)
        ];
    }

    private function recalcularEstadoVida(int $personagemId): array
    {
        $stmt = $this->pdo->prepare("
        SELECT id, vida_atual, vida_max, estado_vida, inconsciente, morrendo, morto, turnos_restantes_morte
        FROM personagens
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$personagemId]);

        $personagem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$personagem) {
            throw new Exception('Personagem não encontrado para recalcular estado de vida.');
        }

        $vidaAtual = (int)$personagem['vida_atual'];
        $vidaMax = max(1, (int)$personagem['vida_max']);

        $morto = (int)$personagem['morto'];
        $morrendo = (int)$personagem['morrendo'];
        $turnosRestantes = (int)$personagem['turnos_restantes_morte'];

        $estadoVida = 'saudavel';
        $inconsciente = 0;

        if ($morto === 1) {
            $estadoVida = 'morto';
            $inconsciente = 1;
            $morrendo = 0;
            $turnosRestantes = 0;
        } elseif ($vidaAtual <= 0) {
            $estadoVida = 'inconsciente';
            $inconsciente = 1;

            if ($morrendo === 0) {
                $morrendo = 1;
                $turnosRestantes = 4;
            }
        } else {
            $percentualVida = ($vidaAtual / $vidaMax) * 100;

            if ($percentualVida > 70) {
                $estadoVida = 'saudavel';
            } elseif ($percentualVida > 40) {
                $estadoVida = 'ferido';
            } elseif ($percentualVida > 15) {
                $estadoVida = 'grave';
            } else {
                $estadoVida = 'critico';
            }

            $inconsciente = 0;
            $morrendo = 0;
            $turnosRestantes = 0;
        }

        $stmt = $this->pdo->prepare("
        UPDATE personagens
        SET estado_vida = ?,
            inconsciente = ?,
            morrendo = ?,
            morto = ?,
            turnos_restantes_morte = ?
        WHERE id = ?
    ");
        $stmt->execute([
            $estadoVida,
            $inconsciente,
            $morrendo,
            $morto,
            $turnosRestantes,
            $personagemId
        ]);

        return [
            'estado_vida' => $estadoVida,
            'inconsciente' => $inconsciente,
            'morrendo' => $morrendo,
            'morto' => $morto,
            'turnos_restantes_morte' => $turnosRestantes
        ];
    }

    // Morto
    private function marcarComoMorto(int $personagemId, ?string $causa = null): void
    {
        $stmt = $this->pdo->prepare("
        UPDATE personagens
        SET estado_vida = 'morto',
            inconsciente = 1,
            morrendo = 0,
            morto = 1,
            turnos_restantes_morte = 0,
            causa_morte = ?
        WHERE id = ?
    ");
        $stmt->execute([$causa, $personagemId]);
    }

    // Morrendo
    public function processarMorrendo(int $personagemId): array
    {
        $stmt = $this->pdo->prepare("
        SELECT id, nome, morrendo, morto, turnos_restantes_morte
        FROM personagens
        WHERE id = ?
        LIMIT 1
    ");
        $stmt->execute([$personagemId]);

        $personagem = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$personagem) {
            throw new Exception('Personagem não encontrado para processar morrendo.');
        }

        if ((int)$personagem['morto'] === 1) {
            return [
                'status' => 'morto',
                'mensagem' => $personagem['nome'] . ' já está morto.'
            ];
        }

        if ((int)$personagem['morrendo'] !== 1) {
            return [
                'status' => 'estavel',
                'mensagem' => $personagem['nome'] . ' não está morrendo.'
            ];
        }

        $turnosRestantes = (int)$personagem['turnos_restantes_morte'] - 1;

        if ($turnosRestantes <= 0) {
            $this->marcarComoMorto($personagemId, 'Não resistiu aos ferimentos');

            return [
                'status' => 'morto',
                'mensagem' => $personagem['nome'] . ' não resistiu aos ferimentos e morreu.'
            ];
        }

        $stmt = $this->pdo->prepare("
        UPDATE personagens
        SET turnos_restantes_morte = ?
        WHERE id = ?
    ");
        $stmt->execute([$turnosRestantes, $personagemId]);

        return [
            'status' => 'morrendo',
            'mensagem' => $personagem['nome'] . ' está morrendo. Turnos restantes até a morte: ' . $turnosRestantes . '.',
            'turnos_restantes_morte' => $turnosRestantes
        ];
    }

    // Pode agir
    public function podeAgir(array $personagem): array
    {
        if (!empty($personagem['morto'])) {
            return [
                'pode_agir' => false,
                'mensagem' => $personagem['nome'] . ' está morto e não pode agir.'
            ];
        }

        if (!empty($personagem['inconsciente'])) {
            return [
                'pode_agir' => false,
                'mensagem' => $personagem['nome'] . ' está inconsciente e não pode agir.'
            ];
        }

        if (!empty($personagem['morrendo'])) {
            return [
                'pode_agir' => false,
                'mensagem' => $personagem['nome'] . ' está morrendo e não pode agir.'
            ];
        }

        return [
            'pode_agir' => true,
            'mensagem' => null
        ];
    }

    // Bloqueio
    public function assumirBloqueio(array $personagem): array
    {
        $defesaBase = $this->calcularDefesaTotal($personagem);
        $bonusLuta = $this->buscarBonusPericia((int)$personagem['id'], 'Luta');
        $bonusBloqueioEquip = $this->obterBonusBloqueioEquipamento($personagem);

        $defesaTotal = $defesaBase + $bonusLuta + $bonusBloqueioEquip;

        return [
            'sucesso' => true,
            'mensagem' => $personagem['nome']
                . ' entrou em bloqueio. Defesa de bloqueio atual: '
                . $defesaTotal . '.'
        ];
    }

    public function assumirEsquiva(array $personagem): array
    {
        $defesaBase = $this->calcularDefesaTotal($personagem);
        $bonusReflexos = $this->buscarBonusPericia((int)$personagem['id'], 'Reflexos');

        $defesaTotal = $defesaBase + $bonusReflexos;

        return [
            'sucesso' => true,
            'mensagem' => $personagem['nome']
                . ' entrou em esquiva. Defesa de esquiva atual: '
                . $defesaTotal . '.'
        ];
    }
}
