<?php
namespace App\Helpers;

class Utils
{
    public static function getIcon(string $name, ?string $color)
    {
        if(!$name) return null;
        return view('custom-component.icon-api', ["icon" => $name, "color" => $color ? $color :"#f5f5f6"])->render();
    }
}
