<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CommandeResource;
use App\Models\Commande;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Les cinq dernieres commandes.
 *
 * Elles ferment la boucle du tableau de bord : les compteurs disent COMBIEN,
 * cette liste dit LESQUELLES. Chaque ligne ouvre la commande — un tableau de
 * bord d'ou l'on ne peut pas agir oblige a refaire le chemin par le menu.
 *
 * Cinq et non vingt : la liste complete a son propre ecran, et la repeter ici
 * ferait deux listes a tenir.
 */
class DernieresCommandes extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('View:Commande') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Dernières commandes')
            ->query(
                Commande::query()
                    ->with(['user', 'status'])
                    ->latest('created_at')
                    ->limit(5)
            )
            // La pagination n'a pas de sens sur cinq lignes figees, et elle
            // ajouterait une barre vide sous le tableau.
            ->paginated(false)
            ->columns([
                TextColumn::make('refernce')
                    ->label('Référence')
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('Client')
                    ->placeholder('—'),

                TextColumn::make('global_price')
                    ->label('Total')
                    ->money('USD'),

                TextColumn::make('status.title')
                    ->label('Statut')
                    ->badge(),

                TextColumn::make('paied_at')
                    ->label('Payée')
                    // Un tiret, pas une date vide : « pas encore payée » est
                    // une information, et le vide se lit comme une panne.
                    ->placeholder('pas encore')
                    ->dateTime('d/m H:i'),

                TextColumn::make('created_at')
                    ->label('Créée')
                    ->since(),
            ])
            ->recordActions([
                Action::make('ouvrir')
                    ->label('Ouvrir')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Commande $record) => CommandeResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Aucune commande')
            ->emptyStateDescription('Les commandes apparaîtront ici dès la première.');
    }
}
