<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\HttpException;

/**
 * Token de acesso assinado (HMAC-SHA256), no estilo de um JWT compacto,
 * implementado sem dependências externas (PHP puro / XAMPP).
 *
 * Formato:  base64url(payloadJson) . base64url(assinatura)
 *
 * A assinatura garante integridade: o cliente não consegue forjar papel ou
 * id sem conhecer o segredo do servidor. Atende ao requisito de segurança e
 * controle de acesso por papéis.
 */
final class Token
{
    public static function issue(array $claims): string
    {
        $config = (require __DIR__ . '/../../config/config.php')['auth'];
        $claims['iat'] = time();
        $claims['exp'] = time() + (int) $config['token_ttl'];

        $payload = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_UNICODE));
        $signature = self::sign($payload, $config['secret']);

        return $payload . '.' . $signature;
    }

    /** Valida assinatura e expiração; devolve as claims ou lança 401. */
    public static function verify(string $token): array
    {
        $config = (require __DIR__ . '/../../config/config.php')['auth'];

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw HttpException::unauthorized('Token malformado.');
        }
        [$payload, $signature] = $parts;

        $expected = self::sign($payload, $config['secret']);
        if (!hash_equals($expected, $signature)) {
            throw HttpException::unauthorized('Assinatura do token inválida.');
        }

        $claims = json_decode(self::base64UrlDecode($payload), true);
        if (!is_array($claims)) {
            throw HttpException::unauthorized('Conteúdo do token inválido.');
        }
        if (($claims['exp'] ?? 0) < time()) {
            throw HttpException::unauthorized('Token expirado. Faça login novamente.');
        }

        return $claims;
    }

    private static function sign(string $payload, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
