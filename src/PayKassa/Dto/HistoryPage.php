<?php

declare(strict_types=1);

namespace Al5dy\PayKassaWoo\PayKassa\Dto;

use Al5dy\PayKassaWoo\PayKassa\Exception\InvalidResponseException;

final class HistoryPage
{
    /** @param list<HistoryCandidate> $candidates */
    private function __construct(public readonly array $candidates, public readonly int $page_count, public readonly string $fingerprint)
    {
    }

    /** @param array<string, mixed> $response */
    public static function from_response(array $response, int $page): self
    {
        $data = $response['data'] ?? null;
        $count = is_array($data) ? ($data['page_count'] ?? null) : null;
        if (is_string($count) && preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $count)) {
            $count = (int) $count;
        }
        $list = is_array($data) ? ($data['list'] ?? null) : null;
        if (
            false !== ($response['error'] ?? null) || ! is_int($count) || $count < 0 || $count > 10000
            || ! is_array($list) || ! array_is_list($list) || count($list) > 1000
            || $page < 0 || ($count > 0 && $page >= $count) || (0 === $count && (0 !== $page || array() !== $list))
            || ($page + 1 < $count && array() === $list)
        ) {
            throw new InvalidResponseException('Invalid history pagination envelope.');
        }
        $candidates = array();
        foreach ($list as $row) {
            if (! is_array($row)) {
                throw new InvalidResponseException('Invalid history record.');
            }
            $candidates[] = HistoryCandidate::from_array($row);
        }
        // Persist no raw rows or tokens, even when detecting changing pages.
        return new self($candidates, $count, hash('sha256', (string) json_encode($list)));
    }
}
