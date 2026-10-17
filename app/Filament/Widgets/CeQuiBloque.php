<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookingResource;
use App\Filament\Resources\CommandeResource;
use App\Filament\Resources\ProductResource;
use App\Models\Booking;
use App\Models\Commande;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ce qui attend une action, maintenant.
 *
 * Un tableau de bord qui ne montre que des totaux laisse chercher dans les
 * listes ce qu'il faut traiter. Ces quatre compteurs disent au contraire ou
 * aller, et chacun MENE a la liste filtree correspondante : un nombre sur
 * lequel on ne peut pas cliquer oblige a refaire le filtre a la main.
 *
 * Zero n'est pas cache : une absence de compteur laisserait deduire « rien a
 * faire » d'un vide, et on ne saurait pas si le widget a simplement echoue.
 */
class CeQuiBloque extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Ce qui attend une action';

    public static function canView(): bool
    {
        return auth()->user()?->can('View:Commande') ?? false;
    }

    /** Payees, donc a preparer, mais pas encore acceptees par le restaurant. */
    public function commandesNonAcceptees(): int
    {
        return Commande::query()
            ->whereNotNull('paied_at')
            ->whereNull('accepted_at')
            ->nonAnnulee()
            ->count();
    }

    /**
     * En attente de paiement : elles expirent, et le client perd sa commande.
     *
     * Le scope existant porte deja la definition — on ne la recopie pas ici.
     */
    public function commandesEnAttenteDePaiement(): int
    {
        return Commande::query()->nonReglee()->nonExpiree()->count();
    }

    /**
     * Les plats suivis tombes a zero.
     *
     * Un plat dont le stock n'est pas suivi n'est jamais epuise : c'est
     * l'etat de presque tout le catalogue, et le compter ferait un nombre
     * enorme et faux.
     */
    public function platsEpuises(): int
    {
        return Product::query()
            ->whereNotNull('stock_quantity')
            ->where('stock_quantity', '<=', 0)
            ->count();
    }

    /** Reservations de location dont l'acompte n'est pas encore regle. */
    public function reservationsNonReglees(): int
    {
        return Booking::query()->where('status', Booking::STATUS_PENDING_PAYMENT)->count();
    }

    protected function getStats(): array
    {
        $aPreparer = $this->commandesNonAcceptees();
        $aPayer = $this->commandesEnAttenteDePaiement();
        $epuises = $this->platsEpuises();
        $reservations = $this->reservationsNonReglees();

        return [
            Stat::make('Commandes à accepter', (string) $aPreparer)
                ->description($aPreparer ? 'Payées, le restaurant n’a pas confirmé' : 'Rien en attente')
                ->descriptionIcon('heroicon-m-clock')
                ->color($aPreparer ? 'danger' : 'success')
                ->url(CommandeResource::getUrl('index')),

            Stat::make('Paiements en attente', (string) $aPayer)
                ->description($aPayer ? 'Elles expireront si rien n’est payé' : 'Rien en attente')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color($aPayer ? 'warning' : 'success')
                ->url(CommandeResource::getUrl('index')),

            Stat::make('Plats épuisés', (string) $epuises)
                ->description($epuises ? 'Stock suivi tombé à zéro' : 'Aucun plat suivi épuisé')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($epuises ? 'warning' : 'success')
                ->url(ProductResource::getUrl('index')),

            Stat::make('Réservations non réglées', (string) $reservations)
                ->description($reservations ? 'Acompte pas encore réglé' : 'Rien en attente')
                ->descriptionIcon('heroicon-m-key')
                ->color($reservations ? 'warning' : 'success')
                ->url(BookingResource::getUrl('index')),
        ];
    }
}
