<?php
namespace App\Helpers;

class Utils
{
    /**
     * Le parametre etait type string non nullable alors que la premiere ligne
     * du corps teste justement son absence : un statut sans icone provoquait
     * donc une TypeError, et toute reponse contenant une commande repondait
     * 500. Le garde n'a jamais pu s'executer.
     */
    public static function getIcon(?string $name, ?string $color)
    {
        if(!$name) return null;
        return view('custom-component.icon-api', ["icon" => $name, "color" => $color ? $color :"#f5f5f6"])->render();
    }
}
