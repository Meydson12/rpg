<?php
require_once __DIR__ . '/../config/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$playerId = 1;

if (empty($_SESSION['combate_npc_id'])) {
    header('Location: jogo.php');
    exit;
}

$npcId = (int)$_SESSION['combate_npc_id'];

$controller = new CombateController($pdo, $playerId);
$controller->prepararInicioCombate($npcId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->processarAcao($_POST['acao'] ?? '', $npcId);
    $controller->limparCombateSeNpcDerrotado($npcId);

    if (empty($_SESSION['combate_npc_id'])) {
        header('Location: jogo.php');
        exit;
    }

    header('Location: combate.php');
    exit;
}

$player = $controller->getPlayer();
$npc = $controller->getNpc($npcId);
$logs = $controller->buscarLogsRecentes(10);
$turnoAtual = $controller->getTurnoAtual();
$nomeTurnoAtual = $turnoAtual === 'player' ? 'Jogador' : 'Inimigo';
$corTurnoAtual = $turnoAtual === 'player' ? '#4caf50' : '#ff9800';
$iniciativa = $controller->getResumoIniciativa();
$posturaPlayer = !empty($_SESSION['postura_defensiva_player']);
$posturaNpc = !empty($_SESSION['postura_defensiva_npc']);
$reacaoPlayer = $_SESSION['reacao_defensiva_player'] ?? null;
$reacaoNpc = $_SESSION['reacao_defensiva_npc'] ?? null;

function nomeReacao(?string $reacao): string
{
    return match ($reacao) {
        'bloqueio' => 'Bloqueio',
        'esquiva' => 'Esquiva',
        'contra_ataque' => 'Contra-ataque',
        default => 'Nenhuma',
    };
}

if (!$npc || !empty($npc['morto'])) {
    unset($_SESSION['combate_npc_id']);
    header('Location: jogo.php');
    exit;
}

function corEstadoVida(string $estado): string
{
    return match ($estado) {
        'saudavel' => '#4caf50',
        'ferido' => '#cddc39',
        'grave' => '#ff9800',
        'critico' => '#ff5722',
        'inconsciente' => '#9e9e9e',
        'morrendo' => '#e53935',
        'morto' => '#f44336',
        default => '#ffffff',
    };
}

function nomeEstadoVida(string $estado): string
{
    return match ($estado) {
        'saudavel' => 'Saudável',
        'ferido' => 'Ferido',
        'grave' => 'Grave',
        'critico' => 'Crítico',
        'inconsciente' => 'Inconsciente',
        'morrendo' => 'Morrendo',
        'morto' => 'Morto',
        default => ucfirst($estado),
    };
}

$estadoPlayer = (string)($player['estado_vida'] ?? 'saudavel');
$estadoNpc = (string)($npc['estado_vida'] ?? 'saudavel');
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="shortcut icon" href="../imagens/imagem_cortada_circular (1).png" type="image/x-icon">
    <title>Combate</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #eee;
            margin: 0;
            padding: 20px;
            font-size: 14px;
        }

        h1 {
            font-size: 22px;
            margin-bottom: 10px;
        }

        h2 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        h3 {
            font-size: 16px;
            margin-bottom: 6px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .box {
            background: #1f1f1f;
            border: 1px solid #333;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 8px;
        }

        .turno-box {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
        }

        .iniciativa-box {
            font-size: 14px;
            line-height: 1.6;
        }

        .iniciativa-linha {
            margin-bottom: 6px;
        }

        .iniciativa-linha:last-child {
            margin-bottom: 0;
        }

        .turno-valor {
            display: inline-block;
            margin-top: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            background: #181818;
            border: 1px solid #2d2d2d;
        }

        .status-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 12px;
            align-items: start;
        }

        .combatente {
            background: #181818;
            border: 1px solid #2d2d2d;
            border-radius: 8px;
            padding: 12px;
        }

        .versus {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            color: #7db3ff;
            min-width: 60px;
        }

        .recursos {
            font-size: 15px;
            margin: 10px 0;
            line-height: 1.5;
        }

        .estado-vida {
            font-weight: bold;
        }

        .indicador-combate {
            margin-top: 6px;
            padding: 6px 8px;
            background: #141414;
            border: 1px solid #2a2a2a;
            border-radius: 6px;
            font-size: 13px;
        }

        .indicador-combate strong {
            color: #7db3ff;
        }

        .atributos {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 10px;
        }

        .atributo {
            background: #141414;
            border: 1px solid #2a2a2a;
            border-radius: 6px;
            padding: 8px;
            text-align: center;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            background: #0f0f0f;
            color: #fff;
            border: 1px solid #444;
            border-radius: 6px;
            box-sizing: border-box;
        }

        button {
            margin-top: 10px;
            padding: 10px 16px;
            background: #2d6cdf;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        button:hover {
            background: #2459b8;
        }

        a {
            color: #7db3ff;
            text-decoration: none;
        }

        a:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .log-container {
            max-height: 280px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .log {
            border-left: 3px solid #2d6cdf;
            padding: 6px 0 6px 10px;
            margin-bottom: 6px;
            background: #181818;
            border-radius: 4px;
            font-size: 14px;
        }

        .log:last-child {
            margin-bottom: 0;
        }

        .tipo-log {
            color: #7db3ff;
        }

        .topo-combate {
            display: grid;
            grid-template-columns: 1fr 320px 1fr;
            gap: 12px;
            align-items: start;
        }

        .coluna-centro {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .combatente {
            background: #181818;
            border: 1px solid #2d2d2d;
            border-radius: 8px;
            padding: 12px;
        }

        .vs-box {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            color: #7db3ff;
        }

        @media (max-width: 900px) {
            .topo-combate {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Combate</h1>

        <div class="topo-combate">
            <div class="combatente">
                <h2>Jogador</h2>
                <p><strong><?= htmlspecialchars($player['nome']) ?></strong></p>
                <p><strong>Classe:</strong> <?= htmlspecialchars($player['classe']) ?> | <strong>Nível:</strong> <?= (int)$player['nivel'] ?></p>

                <div class="recursos">
                    <div><strong>Vida:</strong> <?= (int)$player['vida_atual'] ?> / <?= (int)$player['vida_max'] ?></div>
                    <div><strong>Mana:</strong> <?= (int)$player['mana_atual'] ?> / <?= (int)$player['mana_max'] ?></div>
                    <div>
                        <strong>Estado:</strong>
                        <span class="estado-vida" style="color: <?= htmlspecialchars(corEstadoVida($estadoPlayer)) ?>;">
                            <?= htmlspecialchars(nomeEstadoVida($estadoPlayer)) ?>
                        </span>
                    </div>
                    <?php if (!empty($player['morrendo'])): ?>
                        <div><strong>Turnos até a morte:</strong> <?= (int)$player['turnos_restantes_morte'] ?></div>
                    <?php endif; ?>

                    <div class="indicador-combate">
                        <div><strong>Postura:</strong> <?= $posturaPlayer ? 'Defensiva' : 'Nenhuma' ?></div>
                        <div><strong>Reação:</strong> <?= htmlspecialchars(nomeReacao($reacaoPlayer)) ?></div>
                    </div>
                </div>

                <div class="atributos">
                    <div class="atributo"><strong>FOR</strong><br><?= (int)$player['atributos_for'] ?></div>
                    <div class="atributo"><strong>AGI</strong><br><?= (int)$player['atributos_agi'] ?></div>
                    <div class="atributo"><strong>VIG</strong><br><?= (int)$player['atributos_vi'] ?></div>
                </div>
            </div>

            <div class="coluna-centro">
                <div class="box turno-box">
                    <div>Turno atual</div>
                    <div class="turno-valor" style="color: <?= htmlspecialchars($corTurnoAtual) ?>;">
                        <?= htmlspecialchars($nomeTurnoAtual) ?>
                    </div>
                </div>

                <div class="box vs-box">
                    VS
                </div>

                <?php if ($iniciativa): ?>
                    <div class="box iniciativa-box">
                        <h2>Iniciativa</h2>

                        <div class="iniciativa-linha">
                            <strong><?= htmlspecialchars($player['nome']) ?>:</strong>
                            total <?= (int)$iniciativa['player']['total'] ?>
                            (maior dado: <?= (int)$iniciativa['player']['maior_dado'] ?>,
                            bônus: <?= (int)$iniciativa['player']['bonus'] ?>)
                        </div>

                        <div class="iniciativa-linha">
                            <strong><?= htmlspecialchars($npc['nome']) ?>:</strong>
                            total <?= (int)$iniciativa['npc']['total'] ?>
                            (maior dado: <?= (int)$iniciativa['npc']['maior_dado'] ?>,
                            bônus: <?= (int)$iniciativa['npc']['bonus'] ?>)
                        </div>

                        <div class="iniciativa-linha">
                            <strong>Começou:</strong>
                            <?= htmlspecialchars($iniciativa['primeiro'] === 'player' ? $player['nome'] : $npc['nome']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="combatente">
                <h2>Inimigo</h2>
                <p><strong><?= htmlspecialchars($npc['nome']) ?></strong></p>
                <p><strong>Classe:</strong> <?= htmlspecialchars($npc['classe']) ?> | <strong>Nível:</strong> <?= (int)$npc['nivel'] ?></p>

                <div class="recursos">
                    <div><strong>Vida:</strong> <?= (int)$npc['vida_atual'] ?> / <?= (int)$npc['vida_max'] ?></div>
                    <div><strong>Mana:</strong> <?= (int)$npc['mana_atual'] ?> / <?= (int)$npc['mana_max'] ?></div>
                    <div>
                        <strong>Estado:</strong>
                        <span class="estado-vida" style="color: <?= htmlspecialchars(corEstadoVida($estadoNpc)) ?>;">
                            <?= htmlspecialchars(nomeEstadoVida($estadoNpc)) ?>
                        </span>
                    </div>
                    <?php if (!empty($npc['morrendo'])): ?>
                        <div><strong>Turnos até a morte:</strong> <?= (int)$npc['turnos_restantes_morte'] ?></div>
                    <?php endif; ?>

                    <div class="indicador-combate">
                        <div><strong>Postura:</strong> <?= $posturaNpc ? 'Defensiva' : 'Nenhuma' ?></div>
                        <div><strong>Reação:</strong> <?= htmlspecialchars(nomeReacao($reacaoNpc)) ?></div>
                    </div>
                </div>

                <div class="atributos">
                    <div class="atributo"><strong>FOR</strong><br><?= (int)$npc['atributos_for'] ?></div>
                    <div class="atributo"><strong>AGI</strong><br><?= (int)$npc['atributos_agi'] ?></div>
                    <div class="atributo"><strong>VIG</strong><br><?= (int)$npc['atributos_vi'] ?></div>
                </div>
            </div>
        </div>



        <div class="box">
            <h2>Ação</h2>
            <p>
                <?php if ($turnoAtual === 'player'): ?>
                    É a sua vez. Use <strong>/atk</strong>, <strong>/def postura</strong>, <strong>/def bloqueio</strong>, <strong>/def esquiva</strong> ou <strong>/def contra-ataque</strong>.
                <?php else: ?>
                    É a vez do inimigo. Use <strong>/turno</strong> para avançar.
                <?php endif; ?>
            </p>
            <form method="POST">
                <input
                    type="text"
                    name="acao"
                    placeholder="<?= $turnoAtual === 'player'
                                        ? '/atk *ataco com minha espada* ou /def contra-ataque'
                                        : '/turno para avançar a vez do inimigo' ?>"
                    required>
                <button type="submit">
                    <?= $turnoAtual === 'player' ? 'Executar ação' : 'Avançar turno do inimigo' ?>
                </button>
            </form>
        </div>



        <div class="box">
            <h2>Log do combate</h2>
            <div class="log-container">
                <?php if (!$logs): ?>
                    <p>Nenhuma ação registrada ainda.</p>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log">
                            <strong class="tipo-log"><?= htmlspecialchars($log['tipo_memoria']) ?>:</strong><br>
                            <?= nl2br(htmlspecialchars($log['conteudo'])) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="box">
            <p><a href="jogo.php">Voltar ao jogo</a></p>
        </div>
    </div>
</body>

</html>