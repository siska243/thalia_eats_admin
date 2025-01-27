<?php

namespace App\Enums;


enum MobilePermissions :string
{
    case AccepOrder = 'accept-order';
    case DeclineOrder='decline-order';

    case AddOrder='add-order';

    case Delivery="delivery";

    public function getLabel(): string
    {
        return match ($this) {
            self::AccepOrder => __('Accept order'),
            self::DeclineOrder => __('Decline order'),
            self::Delivery => __('Delivery'),
            self::AddOrder => __('Delivery'),

            default => str($this->value)->replace('_', ' ')->ucfirst()->toString(),
        };
    }


    public static function getOptions(): array{
        return  collect(self::cases())->mapWithKeys(fn(self $type): array
        => [$type->value => $type->getLabel()])->toArray();
    }

    public static function getOptionFilter(): array{
        return  collect(self::cases())->mapWithKeys(fn(self $type): array
        => [$type->value => $type->getLabel()])->toArray();
    }

}
