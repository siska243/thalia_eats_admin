<?php
namespace App\Helpers;

class Utils
{
    public static function getIcon(string $name, ?string $color)
    {
        if($color || $name) return null;
        return view('custom-component.icon-api', ["icon" => $name, "color" => $color])->render();
    }
}
