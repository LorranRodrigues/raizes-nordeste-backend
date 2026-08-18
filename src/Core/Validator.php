<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validador de entrada simples, baseado em regras textuais.
 *
 * Uso:
 *   $dados = Validator::make($request->all(), [
 *       'nome'  => 'required|string|max:120',
 *       'email' => 'required|email',
 *       'papel' => 'required|in:ATENDENTE,COZINHEIRO,GERENTE,MATRIZ',
 *   ]);
 *
 * Validação consistente protege o sistema (segurança/robustez) e devolve
 * mensagens claras por campo (HTTP 422), facilitando o consumo pelos canais.
 */
final class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    private function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Valida e devolve apenas os campos com regra. Lança HttpException 422
     * com os erros por campo caso algo falhe.
     */
    public static function make(array $data, array $rules): array
    {
        $validator = new self($data, $rules);
        return $validator->validate();
    }

    private function validate(): array
    {
        $validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $isRequired = in_array('required', $rules, true);
            $isPresent = array_key_exists($field, $this->data) && $value !== '' && $value !== null;

            if (!$isPresent) {
                if ($isRequired) {
                    $this->errors[$field][] = 'O campo é obrigatório.';
                }
                continue; // não valida demais regras de campo ausente opcional
            }

            // min/max contam comprimento para strings e valor para números.
            // O tipo é definido pela presença de 'numeric'/'integer' nas regras.
            $tratarComoNumero = in_array('numeric', $rules, true) || in_array('integer', $rules, true);

            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule, $tratarComoNumero);
            }

            $validated[$field] = $value;
        }

        if ($this->errors !== []) {
            // Converte para o formato padronizado de detalhes: [{ field, issue }].
            $details = [];
            foreach ($this->errors as $field => $issues) {
                foreach ($issues as $issue) {
                    $details[] = ['field' => $field, 'issue' => $issue];
                }
            }
            throw HttpException::unprocessable('Dados inválidos.', $details);
        }

        return $validated;
    }

    private function applyRule(string $field, mixed $value, string $rule, bool $tratarComoNumero = false): void
    {
        [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

        switch ($name) {
            case 'required':
            case 'string': // aceita qualquer escalar como string
                break;

            case 'email':
                if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field][] = 'E-mail inválido.';
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->errors[$field][] = 'Deve ser um número.';
                }
                break;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->errors[$field][] = 'Deve ser um número inteiro.';
                }
                break;

            case 'boolean':
                if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    $this->errors[$field][] = 'Deve ser verdadeiro ou falso.';
                }
                break;

            case 'date':
                $d = \DateTime::createFromFormat('Y-m-d', (string) $value);
                if (!$d || $d->format('Y-m-d') !== $value) {
                    $this->errors[$field][] = 'Data inválida (use AAAA-MM-DD).';
                }
                break;

            case 'min':
                if ($tratarComoNumero) {
                    if ((float) $value < (float) $arg) {
                        $this->errors[$field][] = "Valor mínimo: {$arg}.";
                    }
                } elseif (mb_strlen((string) $value) < (int) $arg) {
                    $this->errors[$field][] = "Mínimo de {$arg} caractere(s).";
                }
                break;

            case 'max':
                if ($tratarComoNumero) {
                    if ((float) $value > (float) $arg) {
                        $this->errors[$field][] = "Valor máximo: {$arg}.";
                    }
                } elseif (mb_strlen((string) $value) > (int) $arg) {
                    $this->errors[$field][] = "Máximo de {$arg} caractere(s).";
                }
                break;

            case 'in':
                $options = explode(',', (string) $arg);
                if (!in_array((string) $value, $options, true)) {
                    $this->errors[$field][] = 'Valor não permitido. Opções: ' . implode(', ', $options) . '.';
                }
                break;

            default:
                // regra desconhecida é ignorada silenciosamente
                break;
        }
    }
}
