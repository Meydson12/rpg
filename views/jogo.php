<?php
require_once __DIR__ . '/../config/bootstrap.php';

$playerId = 1;
$controller = new PlayerController($pdo, $playerId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->processarComando($_POST['comando'] ?? '');
    header('Location: jogo.php');
    exit;
}

$player = $controller->getPlayer()->getDados();
$memorias = $controller->getMemoriasRecentes(10);
$sugestoes = $controller->getSugestoes();
$cenaAtual = $controller->getCenaAtualEstruturada();

$estadoVida = $player['estado_vida'] ?? 'saudavel';

$coresEstado = [
    'saudavel' => '#4caf50',
    'ferido' => '#cddc39',
    'grave' => '#ff9800',
    'critico' => '#ff5722',
    'inconsciente' => '#9e9e9e',
    'morrendo' => '#e53935',
    'morto' => '#b71c1c',
];

$nomesEstado = [
    'saudavel' => 'Saudável',
    'ferido' => 'Ferido',
    'grave' => 'Grave',
    'critico' => 'Crítico',
    'inconsciente' => 'Inconsciente',
    'morrendo' => 'Morrendo',
    'morto' => 'Morto',
];

$corEstadoAtual = $coresEstado[$estadoVida] ?? '#ffffff';
$nomeEstadoAtual = $nomesEstado[$estadoVida] ?? ucfirst($estadoVida);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="shortcut icon" href="../imagens/imagem_cortada_circular (1).png" type="image/x-icon">
    <title>Jogo</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #eee;
            margin: 0;
            padding: 0;
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
            display: flex;
            min-height: 100vh;
        }


        .main {
            flex: 3;
            padding: 20px;
            border-right: 1px solid #333;
        }

        .side {
            flex: 1;
            padding: 20px;
            background: #181818;
        }

        .box {
            background: #1f1f1f;
            border: 1px solid #333;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 8px;
        }

        .box h2,
        .box h3 {
            margin-top: 0;
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

        ul {
            padding-left: 18px;
            margin-bottom: 0;
        }

        .status-topo {
            margin-bottom: 15px;
        }

        .recursos {
            font-size: 16px;
            margin: 10px 0 10px 0;
            line-height: 1.5;
        }

        .estado-vida {
            font-weight: bold;
        }

        .status {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-top: 10px;
        }

        .status div {
            background: #181818;
            border: 1px solid #2d2d2d;
            padding: 8px;
            border-radius: 6px;
            text-align: center;
        }

        .cena-box-interna {
            background: #181818;
            border: 1px solid #2d2d2d;
            border-radius: 6px;
            padding: 12px;
            max-height: 260px;
            overflow-y: auto;
        }

        .cena-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .cena-coluna {
            background: #141414;
            border: 1px solid #2a2a2a;
            border-radius: 6px;
            padding: 10px;
        }

        .cena-coluna h3 {
            margin-top: 0;
            margin-bottom: 8px;
            color: #7db3ff;
            font-size: 15px;
        }

        .cena-bloco {
            margin-bottom: 14px;
        }

        .cena-bloco:last-child {
            margin-bottom: 0;
        }

        .cena-texto {
            white-space: pre-line;
            line-height: 1.4;
            margin: 0;
            padding-right: 6px;
        }

        .log-container {
            max-height: 260px;
            overflow-y: auto;
            padding-right: 6px;
        }

        .memoria {
            border-left: 3px solid #2d6cdf;
            padding: 6px 0 6px 10px;
            margin-bottom: 6px;
            background: #181818;
            border-radius: 4px;
            font-size: 14px;
        }

        .memoria:last-child {
            margin-bottom: 0;
        }

        .tipo-memoria {
            color: #7db3ff;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="main">
            <h1>RPG - Mundo Vivo</h1>

            <div class="box">
                <div class="status-topo">
                    <h2><?= htmlspecialchars($player['nome']) ?></h2>
                    <p><strong>Classe:</strong> <?= htmlspecialchars($player['classe']) ?> | <strong>Nível:</strong> <?= (int)$player['nivel'] ?> | <strong>XP:</strong> <?= (int)$player['xp'] ?></p>
                </div>

                <div class="recursos">
                    <div><strong>Vida:</strong> <?= (int)$player['vida_atual'] ?> / <?= (int)$player['vida_max'] ?></div>
                    <div><strong>Mana:</strong> <?= (int)$player['mana_atual'] ?> / <?= (int)$player['mana_max'] ?></div>
                    <div>
                        <strong>Estado:</strong>
                        <span class="estado-vida" style="color: <?= htmlspecialchars($corEstadoAtual) ?>;">
                            <?= htmlspecialchars($nomeEstadoAtual) ?>
                        </span>
                    </div>
                    <?php if (!empty($player['morrendo'])): ?>
                        <div><strong>Turnos até a morte:</strong> <?= (int)$player['turnos_restantes_morte'] ?></div>
                    <?php endif; ?>
                </div>

                <div class="status">
                    <div><strong>FOR</strong><br><?= (int)$player['atributos_for'] ?></div>
                    <div><strong>INT</strong><br><?= (int)$player['atributos_int'] ?></div>
                    <div><strong>PRE</strong><br><?= (int)$player['atributos_pre'] ?></div>
                    <div><strong>AGI</strong><br><?= (int)$player['atributos_agi'] ?></div>
                    <div><strong>VIG</strong><br><?= (int)$player['atributos_vi'] ?></div>
                </div>
            </div>

            <div class="box">
                <h2>Cena atual</h2>
                <div class="cena-box-interna">
                    <div class="cena-grid">
                        <div class="cena-coluna">
                            <div class="cena-bloco">
                                <h3>Local</h3>
                                <div class="cena-texto">
                                    <strong><?= htmlspecialchars($cenaAtual['local'] ?? 'Local desconhecido') ?></strong>
                                </div>
                            </div>

                            <?php if (!empty($cenaAtual['descricao'])): ?>
                                <div class="cena-bloco">
                                    <h3>Descrição</h3>
                                    <div class="cena-texto">
                                        <?= htmlspecialchars($cenaAtual['descricao']) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="cena-bloco">
                                <h3>NPCs presentes</h3>
                                <div class="cena-texto">
                                    <?php if (!empty($cenaAtual['npcs'])): ?>
                                        <?php foreach ($cenaAtual['npcs'] as $npc): ?>
                                            - <?= htmlspecialchars($npc) ?><br>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        - Nenhum NPC visível no momento.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="cena-coluna">
                            <div class="cena-bloco">
                                <h3>Objetos visíveis</h3>
                                <div class="cena-texto">
                                    <?php if (!empty($cenaAtual['objetos'])): ?>
                                        <?php foreach ($cenaAtual['objetos'] as $objeto): ?>
                                            - <?= htmlspecialchars($objeto) ?><br>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        - Nada de interessante aqui.
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="cena-bloco">
                                <h3>Locais próximos</h3>
                                <div class="cena-texto">
                                    <?php if (!empty($cenaAtual['locais_proximos'])): ?>
                                        <?php foreach ($cenaAtual['locais_proximos'] as $local): ?>
                                            - <?= htmlspecialchars($local) ?><br>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        - Nenhum caminho disponível no momento.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
            </div>
        </div>

        <div class="box">
            <h2>Digite sua ação</h2>
            <form method="POST">
                <input
                    type="text"
                    name="comando"
                    placeholder='/talk *falo com o guarda* ou /atk *ataco com espada*'
                    required>
                <button type="submit">Enviar</button>
            </form>
        </div>

        <div class="box">
            <h2>Log recente</h2>
            <div class="log-container">
                <?php if (!$memorias): ?>
                    <p>Nenhuma ação registrada ainda.</p>
                <?php else: ?>
                    <?php foreach ($memorias as $memoria): ?>
                        <div class="memoria">
                            <strong class="tipo-memoria"><?= htmlspecialchars($memoria['tipo_memoria']) ?>:</strong><br>
                            <?= nl2br(htmlspecialchars($memoria['conteudo'])) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="side">
        <div class="box">
            <h3>Navegação</h3>
            <p><a href="jogo.php">Jogo</a></p>
            <p><a href="inventario.php">Inventário</a></p>
            <p><a href="mapa.php">Mapa</a></p>
            <p><a href="combate.php">Combate</a></p>
        </div>

        <div class="box">
            <h3>Sugestões</h3>
            <ul>
                <?php foreach ($sugestoes as $sugestao): ?>
                    <li><?= htmlspecialchars($sugestao) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    </div>
</body>


</html>