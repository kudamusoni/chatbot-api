<?php

namespace App\Enums;

enum CatalogMappingField: string
{
    case TITLE = 'title';
    case PRICE = 'price';
    case CURRENCY = 'currency';
    case SOURCE = 'source';
    case DESCRIPTION = 'description';
    case SOLD_AT = 'sold_at';

    public function key(): string
    {
        return $this->value;
    }

    /** @return array<int, string> */
    public static function allKeys(): array
    {
        return array_map(fn (self $field) => $field->key(), self::cases());
    }

    /** @return array<int, string> */
    public static function requiredKeys(): array
    {
        return [
            self::TITLE->key(),
            self::PRICE->key(),
            self::CURRENCY->key(),
            self::SOURCE->key(),
        ];
    }

    /** @return array<int, string> */
    public static function optionalKeys(): array
    {
        return [
            self::DESCRIPTION->key(),
            self::SOLD_AT->key(),
        ];
    }
}
