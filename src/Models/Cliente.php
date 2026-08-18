<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Cliente extends Model
{
    protected string $table = 'clientes';
    protected array $fillable = ['nome', 'email', 'telefone', 'data_nascimento', 'pontos_saldo', 'ativo'];

    public function findByEmail(string $email): ?array
    {
        return $this->firstWhere(['email' => $email]);
    }

    /**
     * Anonimização (LGPD — direito ao esquecimento). Em vez de apagar a linha
     * (o que quebraria a integridade de pedidos históricos), removemos os dados
     * pessoais e marcamos o cliente como inativo, preservando estatísticas.
     */
    public function anonimizar(int $id): void
    {
        $hash = substr(sha1((string) $id . random_bytes(8)), 0, 10);
        $this->update($id, [
            'nome' => 'Cliente Anonimizado',
            'email' => "anon_{$hash}@anonimizado.local",
            'telefone' => null,
            'data_nascimento' => null,
            'ativo' => 0,
        ]);
    }
}
