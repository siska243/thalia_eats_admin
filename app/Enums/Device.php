<?php

namespace App\Enums;


enum Device :string
{
    case Mobile = 'mobile';
    case Web='web';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mobile => __('Mobile'),
            self::Web => __('Web'),

            default => str($this->value)->replace('_', ' ')->ucfirst()->toString(),
        };
    }


    public static function getOptions(): array{
        return  collect(self::cases())
        ->mapWithKeys(fn(self $type): array
        => [$type->value => $type->getLabel()])->toArray();
    }

}
