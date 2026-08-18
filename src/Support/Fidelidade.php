<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Regras do programa de fidelidade (centralizadas para facilitar testes e
 * eventual ajuste pela área de negócio).
 *
 *  - Acúmulo: 1 ponto por R$ 1,00 gasto (parte inteira do total pago).
 *  - Desconto progressivo conforme o saldo de pontos do cliente.
 */
final class Fidelidade
{
    /** Faixas de desconto: saldo mínimo de pontos => percentual de desconto. */
    private const FAIXAS = [
        600 => 0.15,
        300 => 0.10,
        100 => 0.05,
    ];

    /** Resgate: cada ponto vale R$ 0,10; resgate mínimo de 100 pontos. */
    public const VALOR_PONTO = 0.10;
    public const RESGATE_MINIMO = 100;

    /** Valor em reais correspondente ao resgate de N pontos. */
    public static function valorResgate(int $pontos): float
    {
        return round($pontos * self::VALOR_PONTO, 2);
    }

    /** Pontos creditados por um valor gasto. */
    public static function pontosPorValor(float $valor): int
    {
        return (int) floor($valor);
    }

    /** Percentual de desconto (0..1) ao qual o saldo dá direito. */
    public static function descontoPercentual(int $saldoPontos): float
    {
        foreach (self::FAIXAS as $minimo => $percentual) {
            if ($saldoPontos >= $minimo) {
                return $percentual;
            }
        }
        return 0.0;
    }

    /** Valor do desconto, em reais, aplicável a um subtotal. */
    public static function valorDesconto(float $subtotal, int $saldoPontos): float
    {
        return round($subtotal * self::descontoPercentual($saldoPontos), 2);
    }

    /** Descrição legível da faixa atual (para a resposta da API). */
    public static function faixaDescricao(int $saldoPontos): string
    {
        $pct = self::descontoPercentual($saldoPontos);
        return $pct > 0
            ? sprintf('%d%% de desconto', (int) round($pct * 100))
            : 'Sem desconto (acumule mais pontos)';
    }
}
