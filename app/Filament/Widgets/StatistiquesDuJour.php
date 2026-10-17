<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Commande;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Les chiffres du jour, et leur comparaison avec hier.
 *
 * Un chiffre seul ne dit rien : « 12 commandes » est bon ou mauvais selon ce
 * qu'il y en avait hier. La comparaison est donc portee par chaque carte, et
 * c'est elle qu'on lit, pas le nombre.
 *
 * Les RECETTES ne comptent que ce qui est paye. Une commande en attente de
 * paiement n'est pas un encaissement, et l'y ajouter ferait croire a de
 * l'argent qui n'est pas arrive.
 */
class StatistiquesDuJour extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    /**
     * `define_via_gate` est a false dans `config/filament-shield.php` :
     * super_admin ne contourne RIEN. Sans ce garde, le widget montrerait a
     * tout compte du panneau ce que les ecrans lui cachent.
     */
    public static function canView(): bool
    {
        return auth()->user()?->can('View:Commande') ?? false;
    }

    public function recettesDuJour(?CarbonInterface $jour = null): float
    {
        return (float) Commande::query()
            ->whereNotNull('paied_at')
            ->whereDate('paied_at', $jour ?? today())
            ->sum('global_price');
    }

    private function commandesDu(CarbonInterface $jour): int
    {
        return Commande::query()->whereDate('created_at', $jour)->count();
    }

    /**
     * « +3 » ou « -2 » par rapport a hier, et rien quand c'est egal.
     *
     * Ecrire « 0 » ferait chercher ce qui a change : l'absence de variation
     * se lit mieux en ne disant rien.
     */
    private function ecart(float $aujourdhui, float $hier): ?string
    {
        $delta = $aujourdhui - $hier;

        if (abs($delta) < 0.01) {
            return null;
        }

        return ($delta > 0 ? '+' : '−').rtrim(rtrim(number_format(abs($delta), 2, ',', ' '), '0'), ',')
            .' par rapport à hier';
    }

    protected function getStats(): array
    {
        $hier = today()->subDay();

        $commandes = $this->commandesDu(today());
        $commandesHier = $this->commandesDu($hier);

        $recettes = $this->recettesDuJour();
        $recettesHier = $this->recettesDuJour($hier);

        $courses = Booking::query()->whereDate('created_at', today())->count();
        $clients = User::query()->whereDate('created_at', today())->count();

        return [
            Stat::make('Commandes du jour', (string) $commandes)
                ->description($this->ecart($commandes, $commandesHier) ?? 'Comme hier')
                ->descriptionIcon($commandes >= $commandesHier
                    ? 'heroicon-m-arrow-trending-up'
                    : 'heroicon-m-arrow-trending-down')
                ->color($commandes >= $commandesHier ? 'success' : 'warning'),

            Stat::make('Recettes du jour', number_format($recettes, 2, ',', ' ').' $')
                ->description($this->ecart($recettes, $recettesHier) ?? 'Comme hier')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($recettes >= $recettesHier ? 'success' : 'warning'),

            Stat::make('Courses de location', (string) $courses)
                ->description('Réservations créées aujourd’hui')
                ->descriptionIcon('heroicon-m-truck')
                ->color('info'),

            Stat::make('Nouveaux clients', (string) $clients)
                ->description('Comptes créés aujourd’hui')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info'),
        ];
    }
}
