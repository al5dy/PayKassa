<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa\Dto;

/** Discovery hints only. A history row is never payment evidence. */
final class HistoryCandidate
{
    private function __construct(public readonly int $order_id, private readonly string $verification_token)
    {
    }

    /**
     * The official SDK does not specify history row fields. If a deployment
     * supplies an order_id/private_hash, use them only to request documented
     * SCI verification. Missing fields must not be fabricated from a snapshot.
     *
     * @param array<string, mixed> $row
     */
    public static function from_array(array $row): self
    {
        $id = $row['order_id'] ?? null;
        $order_id = (is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id)
            ? filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) : false;
        $token = $row['private_hash'] ?? '';
        return new self(false === $order_id ? 0 : $order_id, is_string($token) && preg_match('/^[a-f0-9]{32,128}$/iD', $token) ? $token : '');
    }

    public function verification_token(): string
    {
        return $this->verification_token;
    }

    public function can_verify(): bool
    {
        return $this->order_id > 0 && '' !== $this->verification_token;
    }
}
