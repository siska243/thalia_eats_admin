<?php

namespace App\Enums;


enum ActionOrderEnum :string
{
    case Accept = 'accept';
    case Decline='decline';



    public function getLabel(): string
    {
        return match ($this) {
            self::Accept => __('Accept'),
            self::Decline => __('Decline'),

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
