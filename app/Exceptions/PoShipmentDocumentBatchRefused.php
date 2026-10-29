<?php

namespace App\Exceptions;

class PoShipmentDocumentBatchRefused extends BusinessRuleException
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $rowsState
     * @param  array<string, mixed>  $projection
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $rows = [],
        public readonly array $rowsState = [],
        public readonly array $projection = [],
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'success' => false,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'rows' => $this->rows,
            'rows_state' => $this->rowsState,
            'projection' => $this->projection,
        ];
    }
}
