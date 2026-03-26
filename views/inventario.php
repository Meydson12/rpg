<?php
require_once __DIR__ . '/../config/bootstrap.php';

$playerId = 1;

$stmt = $pdo->prepare("
    SELECT nome, atributos_for
    FROM personagens
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$playerId]);
$player = $stmt->fetch();

if (!$player) {
    die('Player não encontrado.');
}

$capacidade = (int)$player['atributos_for'] * 6;

$stmt = $pdo->prepare("
    SELECT 
        i.id,
        i.personagem_id,
        i.objeto_id,
        i.quantidade,
        i.observacoes,
        o.nome,
        o.slug,
        o.tipo,
        o.descricao_base
    FROM inventario i
    INNER JOIN objetos o ON o.id = i.objeto_id
    WHERE i.personagem_id = ?
    ORDER BY o.nome ASC
");
$stmt->execute([$playerId]);
$itens = $stmt->fetchAll();

$totalItens = 0;
foreach ($itens as $item) {
    $totalItens += (int)$item['quantidade'];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="shortcut icon" href="../imagens/imagem_cortada_circular (1).png" type="image/x-icon">
    <title>Inventário</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #eee;
            padding: 20px;
        }

        .box {
            background: #1b1b1b;
            border: 1px solid #333;
            padding: 15px;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #333;
            padding: 10px;
            text-align: left;
        }

        a {
            color: #6cb6ff;
        }
    </style>
</head>

<body>

    <div class="box">
        <h1>Inventário de <?= htmlspecialchars($player['nome']) ?></h1>
        <p><strong>Capacidade:</strong> <?= $totalItens ?>/<?= $capacidade ?> objetos</p>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Tipo</th>
                    <th>Quantidade</th>
                    <th>Observações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($itens): ?>
                    <?php foreach ($itens as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['nome']) ?></td>
                            <td><?= htmlspecialchars($item['tipo']) ?></td>
                            <td><?= (int)$item['quantidade'] ?></td>
                            <td><?= htmlspecialchars($item['observacoes'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">Nenhum item no inventário.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p><a href="jogo.php">Voltar ao jogo</a></p>
    </div>

</body>

</html>