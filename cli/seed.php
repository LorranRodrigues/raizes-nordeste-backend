<?php

declare(strict_types=1);

/**
 *
 * Uso:  php cli/seed.php
 * Pré-requisito: schema já aplicado (database/schema.sql).
 */

require __DIR__ . '/../bootstrap.php';

use App\Core\Database;

$db = Database::connection();
$senha = password_hash('senha123', PASSWORD_BCRYPT);

echo "Limpando tabelas...\n";
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'auditoria', 'pontos_fidelidade', 'pagamentos', 'pedido_itens', 'pedidos',
    'unidade_produtos', 'produtos', 'categorias', 'consentimentos_lgpd',
    'clientes', 'funcionarios', 'unidades', 'regioes',
] as $t) {
    $db->exec("TRUNCATE TABLE `{$t}`");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Inserindo regiões e unidades...\n";
$db->exec("INSERT INTO regioes (id, nome) VALUES
    (1,'Nordeste'), (2,'Sudeste')");

$db->exec("INSERT INTO unidades (id, regiao_id, nome, tipo, cidade, estado, ativa) VALUES
    (1,1,'Raízes Recife - Centro','COMPLETA','Recife','PE',1),
    (2,1,'Raízes Caruaru','REDUZIDA','Caruaru','PE',1),
    (3,2,'Raízes São Paulo - Paulista','COMPLETA','São Paulo','SP',1)");

echo "Inserindo equipe (senha de todos: senha123)...\n";
$stmt = $db->prepare(
    "INSERT INTO funcionarios (unidade_id, nome, email, senha_hash, papel)
     VALUES (:u,:n,:e,:s,:p)"
);
$equipe = [
    [null, 'Dona Francisca (Matriz)', 'matriz@raizes.com', 'MATRIZ'],
    [1, 'Carlos Gerente', 'gerente.recife@raizes.com', 'GERENTE'],
    [1, 'Ana Atendente', 'atendente.recife@raizes.com', 'ATENDENTE'],
    [1, 'João Cozinheiro', 'cozinha.recife@raizes.com', 'COZINHEIRO'],
    [3, 'Beatriz Gerente', 'gerente.sp@raizes.com', 'GERENTE'],
];
foreach ($equipe as [$u, $n, $e, $p]) {
    $stmt->execute(['u' => $u, 'n' => $n, 'e' => $e, 's' => $senha, 'p' => $p]);
}

echo "Inserindo catálogo (categorias e produtos)...\n";
$db->exec("INSERT INTO categorias (id, nome, descricao) VALUES
    (1,'Tapiocas','Tapiocas recheadas'),
    (2,'Cuscuz','Cuscuz nordestino'),
    (3,'Bebidas','Sucos e refrescos regionais'),
    (4,'Cafés','Café da manhã')");

$db->exec("INSERT INTO produtos (id, categoria_id, nome, descricao, preco_base, sazonal) VALUES
    (1,1,'Tapioca de Carne de Sol','Com queijo coalho',18.90,0),
    (2,1,'Tapioca de Frango','Com catupiry',16.50,0),
    (3,2,'Cuscuz Recheado','Carne de sol e queijo',19.90,0),
    (4,3,'Suco de Caju','Natural',9.00,0),
    (5,3,'Suco de Umbu','Natural',9.50,0),
    (6,4,'Café com Manteiga de Garrafa','Tradicional',7.50,0),
    (7,2,'Canjica Junina','Especial período junino',12.00,1),
    (8,1,'Tapioca de Bolo de Macaxeira','Sazonal junino',14.00,1)");

echo "Montando cardápios por unidade (preços/estoques locais)...\n";
$cardapio = [
    [1, 1, 19.90, 1, 50], [1, 2, null, 1, 40], [1, 3, null, 1, 30], [1, 4, null, 1, 60],
    [1, 5, null, 1, 40], [1, 6, null, 1, 80], [1, 7, null, 1, 25], [1, 8, null, 1, 20],
    [2, 1, null, 1, 20], [2, 3, null, 1, 15], [2, 4, null, 1, 30], [2, 7, null, 1, 10],
    [3, 1, 23.90, 1, 35], [3, 2, 21.00, 1, 30], [3, 4, 12.00, 1, 40], [3, 6, 9.90, 1, 50],
];
$up = $db->prepare(
    "INSERT INTO unidade_produtos (unidade_id, produto_id, preco_local, disponivel, estoque)
     VALUES (:u,:p,:pl,:d,:e)"
);
foreach ($cardapio as [$u, $p, $pl, $d, $e]) {
    $up->execute(['u' => $u, 'p' => $p, 'pl' => $pl, 'd' => $d, 'e' => $e]);
}

echo "Inserindo clientes e consentimentos...\n";
$db->exec("INSERT INTO clientes (id, nome, email, telefone, data_nascimento, pontos_saldo) VALUES
    (1,'Maria Santos','maria.santos@email.com','81988887777','1992-06-24',120),
    (2,'José Oliveira','jose.oliveira@email.com','81977776666','1985-03-10',0)");
$db->exec("INSERT INTO consentimentos_lgpd (cliente_id, finalidade, concedido) VALUES
    (1,'FIDELIDADE',1),(1,'MARKETING',1),
    (2,'FIDELIDADE',1),(2,'MARKETING',0)");
$db->exec("INSERT INTO pontos_fidelidade (cliente_id, tipo, pontos, descricao) VALUES
    (1,'CREDITO',120,'Pontos acumulados em pedidos anteriores')");

echo "Gerando pedidos pagos de exemplo (para relatórios)...\n";
$pedido = $db->prepare(
    "INSERT INTO pedidos (codigo, unidade_id, cliente_id, funcionario_id, canal, status, subtotal, desconto, total)
     VALUES (:c,:u,:cl,:f,:ca,:st,:sub,:desc,:tot)"
);
$item = $db->prepare(
    "INSERT INTO pedido_itens (pedido_id, produto_id, nome_produto, preco_unitario, quantidade, subtotal)
     VALUES (:pe,:pr,:nm,:pu,:q,:sb)"
);
$pag = $db->prepare(
    "INSERT INTO pagamentos (pedido_id, metodo, valor, status, gateway_ref)
     VALUES (:pe,:m,:v,'APROVADO',:ref)"
);

$exemplos = [
    ['RN-DEMO-0001', 1, 1, 3, 'APP', [[1, 'Tapioca de Carne de Sol', 19.90, 2]], 'PIX'],
    ['RN-DEMO-0002', 1, 2, 3, 'BALCAO', [[3, 'Cuscuz Recheado', 19.90, 1], [4, 'Suco de Caju', 9.00, 1]], 'CARTAO_CREDITO'],
    ['RN-DEMO-0003', 3, null, 5, 'TOTEM', [[1, 'Tapioca de Carne de Sol', 23.90, 1]], 'CARTAO_DEBITO'],
];
foreach ($exemplos as $i => [$cod, $u, $cl, $f, $canal, $itens, $metodo]) {
    $sub = 0.0;
    foreach ($itens as [, , $pu, $q]) {
        $sub += $pu * $q;
    }
    $pedido->execute([
        'c' => $cod, 'u' => $u, 'cl' => $cl, 'f' => $f, 'ca' => $canal,
        'st' => 'ENTREGUE', 'sub' => $sub, 'desc' => 0, 'tot' => $sub,
    ]);
    $pid = (int) $db->lastInsertId();
    foreach ($itens as [$pr, $nm, $pu, $q]) {
        $item->execute(['pe' => $pid, 'pr' => $pr, 'nm' => $nm, 'pu' => $pu, 'q' => $q, 'sb' => $pu * $q]);
    }
    $pag->execute(['pe' => $pid, 'm' => $metodo, 'v' => $sub, 'ref' => 'PSP-DEMO-' . ($i + 1)]);
}

echo "\nSeed concluído com sucesso!\n";
echo "Login da matriz:   matriz@raizes.com / senha123\n";
echo "Login do gerente:  gerente.recife@raizes.com / senha123\n";
echo "Login atendente:   atendente.recife@raizes.com / senha123\n";
