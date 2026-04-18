<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class TransferRequest
{
    #[Assert\NotBlank(message: 'sender_id is required')]
    #[Assert\Positive]
    public ?int $sender_id = null;

    #[Assert\NotBlank(message: 'recipient_id is required')]
    #[Assert\Positive]
    public ?int $recipient_id = null;

    #[Assert\NotBlank(message: 'amount is required')]
    #[Assert\Positive]
    public ?float $amount = null;

    public ?string $idempotency_key = null;
}
