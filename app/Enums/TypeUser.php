<?php

namespace App\Enums;


enum TypeUser :string
{
    case Client = 'client';
    case Delivery='delivery';

    case Restaurant='restaurant';

    public function getLabel(): string
    {
        return match ($this) {
            self::Client => __('Mobile'),
            self::Delivery => __('Web'),

            default => str($this->value)->replace('_', ' ')->ucfirst()->toString(),
        };
    }


    public static function getOptions(): array{
        return  collect(self::cases())
        ->mapWithKeys(fn(self $type): array
        => [$type->value => $type->getLabel()])->toArray();
    }

}
