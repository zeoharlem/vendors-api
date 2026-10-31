<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Dtos;

use InvalidArgumentException;

final readonly class CheckoutDto
{
    public function __construct(
        public array $shippingAddress,
        public ?array $billingAddress = null,
        public ?string $customerNote = null,
    ) {
        $this->validate();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            shippingAddress: $data['shipping_address'] ?? [],
            billingAddress: $data['billing_address'] ?? null,
            customerNote: isset($data['customer_note'])
            ? trim((string) $data['customer_note'])
            : null,
        );
    }

    private function validate(): void
    {
        $required = [
            'recipient_name',
            'phone',
            'address_line_1',
            'city',
            'state',
            'country',
        ];

        foreach ($required as $field) {
            if (
                !isset($this->shippingAddress[$field]) ||
                !is_string($this->shippingAddress[$field]) ||
                trim($this->shippingAddress[$field]) === ''
            ) {
                throw new InvalidArgumentException(
                    "Shipping address field '{$field}' is required."
                );
            }
        }

        if (strlen($this->shippingAddress['recipient_name']) > 150) {
            throw new InvalidArgumentException(
                'Recipient name cannot exceed 150 characters.'
            );
        }

        if (strlen($this->shippingAddress['phone']) > 30) {
            throw new InvalidArgumentException(
                'Phone number cannot exceed 30 characters.'
            );
        }

        if (strlen($this->shippingAddress['address_line_1']) > 255) {
            throw new InvalidArgumentException(
                'Address line 1 cannot exceed 255 characters.'
            );
        }

        if (
            $this->customerNote !== null &&
            strlen($this->customerNote) > 2000
        ) {
            throw new InvalidArgumentException(
                'Customer note cannot exceed 2000 characters.'
            );
        }

        if (
            $this->billingAddress !== null &&
            !is_array($this->billingAddress)
        ) {
            throw new InvalidArgumentException(
                'Billing address must be an object.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'shipping_address' => $this->shippingAddress,
            'billing_address' => $this->billingAddress,
            'customer_note' => $this->customerNote,
        ];
    }
}