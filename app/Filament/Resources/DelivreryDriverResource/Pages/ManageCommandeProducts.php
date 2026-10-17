<?php

namespace App\Filament\Resources\DelivreryDriverResource\Pages;

use App\Filament\Resources\CommandeResource;
use App\Filament\Resources\DelivreryDriverResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les courses d'un livreur.
 *
 * Demande du proprietaire le 17 octobre : « ameliore egalement ici ».
 *
 * CETTE PAGE ETAIT DU CODE GENERE, JAMAIS REPRIS. Elle affichait une colonne
 * `title` — que la table `commandes` n'a pas, donc une colonne vide — et
 * proposait un formulaire qui modifiait ce meme champ inexistant.
 *
 * Elle offrait surtout QUATRE ACTIONS DANGEREUSES ET SANS SENS ICI :
 * « Creer » une commande depuis la fiche d'un livreur, en « Associer » une
 * existante, la « Dissocier » — ce qui laisserait une livraison sans
 * livreur, en pleine course — et la « Supprimer ». Aucune de ces quatre
 * n'appartient a cet ecran : une commande se cree par un client qui paie, et
 * se reattribue depuis la commande elle-meme.
 *
 * La page est donc EN LECTURE. Elle repond a une seule question : qu'a fait
 * ce livreur ? Le bouton de chaque ligne ouvre la commande, la ou elle
 * s'administre vraiment.
 */
class ManageCommandeProducts extends ManageRelatedRecords
{
    protected static string $resource = DelivreryDriverResource::class;

    protected static string $relationship = 'commandes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationLabel(): string
    {
        return 'Ses courses';
    }

    public function getTitle(): string
    {
        return 'Les courses de '.trim(
            ($this->getRecord()->user?->name ?? '').' '.($this->getRecord()->user?->last_name ?? '')
        );
    }

    public function table(Table $table): Table
    {
        return $table
            // Le statut et le client sont lus avec la liste : sinon, vingt
            // courses font quarante requetes.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['status', 'user', 'town']))
            ->columns([
                TextColumn::make('refernce')
                    ->label('Référence')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('user.name')
                    ->label('Client')
                    ->formatStateUsing(fn ($record): string => trim(
                        ($record->user?->name ?? '').' '.($record->user?->last_name ?? '')
                    ) ?: '—')
                    ->description(fn ($record): ?string => $record->recipient_phone ?: $record->user?->phone)
                    ->searchable(['name', 'last_name']),

                TextColumn::make('adresse_delivery')
                    ->label('Livrée à')
                    ->description(fn ($record): ?string => $record->town?->title)
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status.title')
                    ->label('Statut')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('global_price')
                    ->label('Total')
                    ->money('USD')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Commandée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                /*
                 * Ce qu'on vient chercher sur cet ecran : a-t-il livre, et
                 * quand. Une course sans date de livraison est encore en
                 * cours — ou n'est jamais arrivee.
                 */
                TextColumn::make('delivery_at')
                    ->label('Livrée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Pas encore')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status_id')
                    ->label('Statut')
                    ->relationship('status', 'title')
                    ->preload(),

                Filter::make('livrees')
                    ->label('Livrées seulement')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('delivery_at')),

                Filter::make('annulees')
                    ->label('Annulées')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('cancel_at')),
            ])
            /*
             * AUCUNE action de creation, d'association ni de suppression :
             * voir l'en-tete de cette classe. La seule action utile mene a
             * la commande, la ou elle s'administre.
             */
            ->recordActions([
                Action::make('ouvrir')
                    ->label('Ouvrir la commande')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record): string => CommandeResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading('Aucune course')
            ->emptyStateDescription('Ce livreur n’a encore accepté aucune commande.');
    }
}
