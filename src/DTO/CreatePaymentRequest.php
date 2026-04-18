<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreatePaymentRequest
{
    #[Assert\NotBlank(message: "reference_id is required")]
    #[Assert\Type("string")]
    public ?string $reference_id = null;

    #[Assert\NotBlank(message: "amount is required")]
    #[Assert\Type("numeric")]
    #[Assert\Positive]
    public $amount = null;
}
