<?php

declare(strict_types=1);

/**
 *
 * Uso:
 *   php cli/criar-funcionario.php "Nome" email@dominio senha PAPEL [unidade_id]
 *   PAPEL ∈ {ATENDENTE, COZINHEIRO, GERENTE, MATRIZ}
 */

require __DIR__ . '/../bootstrap.php';

use App\Models\Funcionario;

if ($argc < 5) {
    fwrite(STDERR, "Uso: php cli/criar-funcionario.php \"Nome\" email senha PAPEL [unidade_id]\n");
    exit(1);
}

[, $nome, $email, $senha, $papel] = $argv;
$unidadeId = isset($argv[5]) ? (int) $argv[5] : null;

$model = new Funcionario();
if ($model->findByEmail($email) !== null) {
    fwrite(STDERR, "Já existe funcionário com este e-mail.\n");
    exit(1);
}

$id = $model->create([
    'unidade_id' => $unidadeId,
    'nome' => $nome,
    'email' => $email,
    'senha_hash' => password_hash($senha, PASSWORD_BCRYPT),
    'papel' => $papel,
    'ativo' => 1,
]);

echo "Funcionário criado com id {$id} (papel {$papel}).\n";
