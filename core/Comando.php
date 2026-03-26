<?php

class Comando
{
    private string $comandoBruto;
    private array $comandosValidos = ['talk', 'move', 'atk', 'def', 'ma', 'item', 'debug', 'status', 'turno'];

    public function __construct(string $comandoBruto)
    {
        $this->comandoBruto = trim($comandoBruto);
    }

    public function interpretar(): array
    {
        if ($this->comandoBruto === '') {
            return [
                'valido' => false,
                'erro' => 'Nenhum comando foi enviado.',
                'comando_bruto' => ''
            ];
        }

        if ($this->comandoBruto[0] !== '/') {
            return [
                'valido' => false,
                'erro' => 'O comando precisa começar com barra (/).',
                'comando_bruto' => $this->comandoBruto
            ];
        }

        preg_match('/^\/([a-zA-Z]+)\s*(.*)$/', $this->comandoBruto, $matches);

        if (!$matches) {
            return [
                'valido' => false,
                'erro' => 'Formato de comando inválido.',
                'comando_bruto' => $this->comandoBruto
            ];
        }

        $acao = strtolower(trim($matches[1]));
        $descricao = trim($matches[2] ?? '');

        $descricao = trim($descricao, '*');
        $descricao = trim($descricao);

        if (!in_array($acao, $this->comandosValidos, true)) {
            return [
                'valido' => false,
                'erro' => 'Comando não reconhecido.',
                'acao' => $acao,
                'descricao' => $descricao,
                'comando_bruto' => $this->comandoBruto
            ];
        }

        return [
            'valido' => true,
            'acao' => $acao,
            'descricao' => $descricao,
            'comando_bruto' => $this->comandoBruto
        ];
    }
}
