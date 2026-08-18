<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Cliente;
use App\Models\Consentimento;
use App\Models\PontoFidelidade;
use App\Support\Fidelidade;

/**
 * Clientes e programa de fidelidade, com tratamento de dados pessoais (LGPD):
 *  - cadastro exige consentimento explícito de finalidade FIDELIDADE;
 *  - acesso a dados pessoais é auditado;
 *  - suporta anonimização (esquecimento) e exportação (portabilidade).
 */
final class ClienteController extends Controller
{
    /** Cadastro (via app). Requer consentimento explícito (LGPD art. 8º). */
    public function store(Request $request): void
    {
        $dados = Validator::make($request->all(), [
            'nome' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'telefone' => 'string|max:20',
            'data_nascimento' => 'date',
            'consentimento_fidelidade' => 'required|boolean',
        ]);

        if (!filter_var($dados['consentimento_fidelidade'], FILTER_VALIDATE_BOOL)) {
            throw HttpException::unprocessable(
                'É necessário consentir com o uso de dados para participar do programa de fidelidade.'
            );
        }

        $model = new Cliente();
        if ($model->findByEmail($dados['email']) !== null) {
            throw HttpException::conflict('Já existe cliente com este e-mail.');
        }

        $id = $model->create([
            'nome' => $dados['nome'],
            'email' => $dados['email'],
            'telefone' => $dados['telefone'] ?? null,
            'data_nascimento' => $dados['data_nascimento'] ?? null,
        ]);

        // Registra o consentimento inicial (trilha imutável).
        (new Consentimento())->create([
            'cliente_id' => $id,
            'finalidade' => 'FIDELIDADE',
            'concedido' => 1,
            'versao_termo' => '1.0',
            'ip_origem' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $this->audit($request, 'CLIENTE_CADASTRO', 'clientes', $id, 'Cadastro com consentimento FIDELIDADE');

        Response::success($model->find($id), 201);
    }

    /** Consulta de cliente — acesso a dado pessoal é auditado (LGPD). */
    public function show(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);
        $cliente = (new Cliente())->findOrFail((int) $request->param('id'));

        $this->audit($request, 'ACESSO_DADOS_PESSOAIS', 'clientes', $cliente['id'], 'Consulta de cadastro de cliente');

        $cliente['fidelidade'] = [
            'saldo' => (int) $cliente['pontos_saldo'],
            'faixa' => Fidelidade::faixaDescricao((int) $cliente['pontos_saldo']),
            'desconto_atual' => Fidelidade::descontoPercentual((int) $cliente['pontos_saldo']),
        ];
        Response::success($cliente);
    }

    public function update(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);
        $model = new Cliente();
        $atual = $model->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), [
            'nome' => 'string|max:120',
            'telefone' => 'string|max:20',
            'data_nascimento' => 'date',
        ]);
        if ($dados === []) {
            throw HttpException::unprocessable('Nenhum campo para atualizar.');
        }
        $model->update($atual['id'], $dados);
        $this->audit($request, 'CLIENTE_ATUALIZAR', 'clientes', $atual['id'], 'Atualização de cadastro', $atual, $dados);

        Response::success($model->find($atual['id']));
    }

    /** Extrato de fidelidade (saldo + movimentações). */
    public function fidelidade(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);
        $cliente = (new Cliente())->findOrFail((int) $request->param('id'));
        $saldo = (int) $cliente['pontos_saldo'];

        Response::success([
            'cliente_id' => $cliente['id'],
            'saldo' => $saldo,
            'faixa' => Fidelidade::faixaDescricao($saldo),
            'desconto_percentual' => Fidelidade::descontoPercentual($saldo),
            'extrato' => (new PontoFidelidade())->extrato((int) $cliente['id']),
        ]);
    }

    /**
     * Resgate simples de pontos: debita pontos do saldo e gera um valor de
     * desconto (voucher). Cada ponto vale R$ 0,10; resgate mínimo de 100 pontos.
     */
    public function resgatar(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);
        $cliente = (new Cliente())->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), [
            'pontos' => 'required|integer|min:' . Fidelidade::RESGATE_MINIMO,
        ]);
        $pontos = (int) $dados['pontos'];
        $saldo = (int) $cliente['pontos_saldo'];

        if ($pontos > $saldo) {
            throw HttpException::conflict(
                "Saldo insuficiente para resgate (saldo: {$saldo}, solicitado: {$pontos}).",
                'SALDO_INSUFICIENTE'
            );
        }

        $valor = Fidelidade::valorResgate($pontos);
        (new PontoFidelidade())->lancar(
            (int) $cliente['id'],
            'DEBITO',
            $pontos,
            null,
            "Resgate de {$pontos} pontos (R$ {$valor})"
        );
        $this->audit($request, 'FIDELIDADE_RESGATE', 'clientes', $cliente['id'], "Resgate de {$pontos} pontos", ['saldo' => $saldo], ['saldo' => $saldo - $pontos]);

        Response::success([
            'cliente_id' => $cliente['id'],
            'pontos_resgatados' => $pontos,
            'valor_voucher' => $valor,
            'saldo_atual' => $saldo - $pontos,
        ]);
    }

    /** Atualiza/registra consentimento por finalidade (LGPD). */
    public function registrarConsentimento(Request $request): void
    {
        $model = new Cliente();
        $cliente = $model->findOrFail((int) $request->param('id'));

        $dados = Validator::make($request->all(), [
            'finalidade' => 'required|in:FIDELIDADE,MARKETING,PERSONALIZACAO',
            'concedido' => 'required|boolean',
        ]);

        (new Consentimento())->create([
            'cliente_id' => $cliente['id'],
            'finalidade' => $dados['finalidade'],
            'concedido' => filter_var($dados['concedido'], FILTER_VALIDATE_BOOL) ? 1 : 0,
            'versao_termo' => '1.0',
            'ip_origem' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $this->audit($request, 'CONSENTIMENTO', 'clientes', $cliente['id'], 'Consentimento ' . $dados['finalidade'], null, $dados);

        Response::success((new Consentimento())->statusAtual((int) $cliente['id']));
    }

    /** Status atual + histórico de consentimentos. */
    public function consentimentos(Request $request): void
    {
        $this->authorize($request, ['ATENDENTE', 'GERENTE', 'MATRIZ']);
        $cliente = (new Cliente())->findOrFail((int) $request->param('id'));
        $model = new Consentimento();
        Response::success([
            'status_atual' => $model->statusAtual((int) $cliente['id']),
            'historico' => $model->historico((int) $cliente['id']),
        ]);
    }

    /** Exportação dos dados pessoais (LGPD — direito à portabilidade). */
    public function exportarDados(Request $request): void
    {
        $this->authorize($request, ['GERENTE', 'MATRIZ']);
        $cliente = (new Cliente())->findOrFail((int) $request->param('id'));
        $this->audit($request, 'EXPORTACAO_DADOS', 'clientes', $cliente['id'], 'Exportação de dados pessoais (portabilidade)');

        Response::success([
            'dados_cadastrais' => $cliente,
            'consentimentos' => (new Consentimento())->historico((int) $cliente['id']),
            'fidelidade' => (new PontoFidelidade())->extrato((int) $cliente['id']),
            'gerado_em' => date('c'),
        ]);
    }

    /** Anonimização (LGPD — direito ao esquecimento). Operação sensível. */
    public function anonimizar(Request $request): void
    {
        $this->authorize($request, ['GERENTE', 'MATRIZ']);
        $model = new Cliente();
        $cliente = $model->findOrFail((int) $request->param('id'));

        $model->anonimizar((int) $cliente['id']);
        $this->audit($request, 'ANONIMIZACAO', 'clientes', $cliente['id'], 'Cliente anonimizado a pedido (LGPD)');

        Response::success(['anonimizado' => true]);
    }
}
