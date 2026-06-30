<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange;

/**
 * Holds mutable CommerceML parsing state formerly stored in exchange globals.
 */
final class ExchangeState
{
    /** @var array<int, array<string, mixed>> */
    public array $groups = [];

    public int $groupDepth = 0;

    public int $groupOrder = 0;

    /** @var array<string, mixed> */
    public array $property = [];

    public int $propertyOrder = 0;

    /** @var array<string, array<string, mixed>> */
    public array $requisiteProperties = [];

    /** @var array<string, mixed> */
    public array $product = [];

    /** @var array<int, array<string, mixed>> */
    public array $subproducts = [];

    public bool $isMoysklad = false;

    /** @var array<int, array<string, mixed>> */
    public array $priceTypes = [];

    /** @var array<string, mixed> */
    public array $offer = [];

    /** @var array<string, mixed> */
    public array $price = [];

    /** @var array<string, mixed>|null */
    public ?array $priceType = null;

    /** @var array<int, array<string, mixed>> */
    public array $suboffers = [];

    /** @var array<string, mixed> */
    public array $document = [];
}
