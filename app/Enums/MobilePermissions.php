<?php

namespace App\Enums;


enum MobilePermissions :string
{
    case Scan = 'accept-order';
    case ScanMany='decline-order';

    case ScanCMRGroupage="delivery";

    public function getLabel(): string
    {
        return match ($this) {
            self::Scan => __('Accept order'),
            self::ScanMany => __('Decline order'),
            self::ScanCMRGroupage => __('Delivery'),

            default => str($this->value)->replace('_', ' ')->ucfirst()->toString(),
        };
    }


    public static function getOptions(): array{
        return  collect(self::cases())->mapWithKeys(fn(self $type): array
        => [$type->value => $type->getLabel()])->toArray();
    }

    public static function getOptionFilter(): array{
        return  collect(self::cases())->filter(fn(self $type)=>$type==self::Scan)->mapWithKeys(fn(self $type): array
        => [$type->value => $type->getLabel()])->toArray();
    }

}
