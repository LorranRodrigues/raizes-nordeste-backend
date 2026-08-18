<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Simulação de um provedor de pagamento externo (PSP).
 *
 * Em produção, esta classe faria uma chamada HTTP ao gateway real (PIX, cartão).
 * Aqui ela apenas devolve uma referência de transação, mantendo a aplicação
 * desacoplada: o sistema da rede solicita o pagamento e aguarda a confirmação
 * assíncrona via webhook — exatamente o desenho descrito no estudo de caso.
 */
final class GatewaySimulado
{
    /**
     * "Inicia" uma cobrança no provedor e devolve a referência da transação.
     * O resultado real (aprovado/recusado) chega depois, pelo webhook.
     */
    public static function solicitarCobranca(string $metodo, float $valor, string $codigoPedido): array
    {
        return [
            'gateway_ref' => 'PSP-' . strtoupper(bin2hex(random_bytes(6))),
            'status' => 'PENDENTE',
            'metodo' => $metodo,
            'valor' => $valor,
            'pedido' => $codigoPedido,
        ];
    }

    /**
     * Assinatura HMAC que o gateway enviaria no header do webhook, permitindo
     * à aplicação verificar a autenticidade da notificação.
     */
    public static function assinarPayload(string $payloadJson): string
    {
        $secret = (require __DIR__ . '/../../config/config.php')['payment']['webhook_secret'];
        return hash_hmac('sha256', $payloadJson, $secret);
    }

    public static function assinaturaValida(string $payloadJson, string $assinaturaRecebida): bool
    {
        return hash_equals(self::assinarPayload($payloadJson), $assinaturaRecebida);
    }
}
