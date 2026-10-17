<?php

namespace App\Filament\Widgets;

use App\Models\Commande;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Support\Carbon;

/**
 * Les commandes payees des trente derniers jours.
 *
 * Trente jours et non l'annee : on cherche ici une tendance qu'on peut encore
 * corriger, pas un bilan. Et le pas est le JOUR, parce que c'est l'unite dans
 * laquelle ce commerce se pilote.
 *
 * Seules les commandes PAYEES comptent : un panier abandonne n'est pas une
 * vente, et les melanger ferait lire une activite qui n'a pas eu lieu.
 *
 * `flowframe/laravel-trend` est deja installe et sert aux tableaux de bord
 * restaurateur et livreur — on ne reecrit pas le regroupement par date.
 */
class CommandesParJour extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Commandes payées, 30 derniers jours';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:Commande') ?? false;
    }

    protected function getData(): array
    {
        $donnees = Trend::query(Commande::query()->whereNotNull('paied_at'))
            ->dateColumn('paied_at')
            ->between(start: now()->subDays(29)->startOfDay(), end: now()->endOfDay())
            ->perDay()
            ->count();

        return [
            'datasets' => [[
                'label' => 'Commandes payées',
                'data' => $donnees->map(fn (TrendValue $valeur) => $valeur->aggregate)->all(),
                'borderColor' => '#F99D1C',
                'backgroundColor' => 'rgba(249, 157, 28, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $donnees
                ->map(fn (TrendValue $valeur) => Carbon::parse($valeur->date)->format('d/m'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
