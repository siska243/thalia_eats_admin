<?php
namespace App\Helpers;

class Utils
{
    public static function getIcon(string $name, string $color)
    {
        return view('custom-component.icon-api', ["icon" => $name, "color" => $color])->render();
    }
}
