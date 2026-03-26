<?php
require_once __DIR__ . '/../config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <link rel="shortcut icon" href="../imagens/imagem_cortada_circular (1).png" type="image/x-icon">
    <title>Mapa</title>
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

        ul {
            line-height: 1.8;
        }

        a {
            color: #6cb6ff;
        }
    </style>
</head>

<body>

    <div class="box">
        <h1>Mapa do Mundo</h1>

        <ul>
            <li>Terra
                <ul>
                    <li>América do Sul
                        <ul>
                            <li>Brasil
                                <ul>
                                    <li>Roraima
                                        <ul>
                                            <li>Boa Vista
                                                <ul>
                                                    <li>Centro</li>
                                                    <li>São Francisco</li>
                                                    <li>Asa Branca</li>
                                                    <li>Outras regiões</li>
                                                </ul>
                                            </li>
                                        </ul>
                                    </li>
                                    <li>Bahia
                                        <ul>
                                            <li>Xique-Xique</li>
                                        </ul>
                                    </li>
                                </ul>
                            </li>
                            <li>Reinos ocultos
                                <ul>
                                    <li>Kaelthan</li>
                                    <li>Reino de Freya</li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>

        <p><a href="jogo.php">Voltar ao jogo</a></p>
    </div>

</body>

</html>