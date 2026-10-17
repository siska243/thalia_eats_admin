<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DelivreryDriverResource\Pages\CreateDelivreryDriver;
use App\Filament\Resources\DelivreryDriverResource\Pages\EditDelivreryDriver;
use App\Filament\Resources\DelivreryDriverResource\Pages\ListDelivreryDrivers;
use App\Filament\Resources\DelivreryDriverResource\Pages\ManageCommandeProducts;
use App\Models\DelivreryDriver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DelivreryDriverResource extends Resource
{
    protected static ?int $navigationSort = 1;

    protected static ?string $model = DelivreryDriver::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user';

    protected static string|\UnitEnum|null $navigationGroup = 'Livraison';

    protected static ?string $modelLabel = 'Livreur';

    protected static ?string $pluralModelLabel = 'Livreurs';

    protected static ?string $navigationLabel = 'Livreurs';

    /*
     * Les onglets EN HAUT, et non sur le cote.
     *
     * Demande du proprietaire le 17 octobre : « tu mets le tab en haut ».
     * C'est deja ce que fait la fiche restaurant, et deux fiches qui
     * rangent leur navigation a deux endroits differents obligent a la
     * chercher.
     */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /**
     * La fiche d'un livreur.
     *
     * Demande du proprietaire le 17 octobre : « ameliore egalement cette
     * page ». Elle tenait dans une seule section sans titre, avec les
     * libelles automatiques en anglais (« Birth date », « Id card »,
     * « Contract »), et rien ne disait a quoi servait l'interrupteur.
     *
     * Trois sections nommees, dans l'ordre ou on remplit : qui c'est, ce
     * qu'on a de lui, et s'il tourne. Les libelles sont en francais, comme
     * le reste de l'administration.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Le livreur')
                    ->description('Le compte auquel cette fiche est rattachée. Tout le reste vient de lui.')
                    ->icon('heroicon-o-user')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Compte utilisateur')
                            ->relationship('user', 'name')
                            ->getOptionLabelFromRecordUsing(
                                fn ($record): string => trim("{$record->name} {$record->last_name}").
                                    ($record->phone ? " · {$record->phone}" : ''),
                            )
                            ->searchable(['name', 'last_name', 'email', 'phone'])
                            ->preload()
                            ->native(false)
                            // Un livreur sans compte n'a ni nom, ni telephone,
                            // ni moyen de se connecter : la fiche ne sert a
                            // rien. Le champ etait pourtant facultatif.
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Un compte ne peut porter qu’une seule fiche livreur.'),

                        DatePicker::make('birth_date')
                            ->label('Date de naissance')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            // Une date de naissance dans le futur est une
                            // faute de frappe, pas une donnee.
                            ->maxDate(now()),
                    ]),

                Section::make('Pièces et contrat')
                    ->description('Ce que vous détenez sur lui, et qui l’engage.')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        TextInput::make('id_card')
                            ->label('Numéro de pièce d’identité')
                            ->required()
                            ->maxLength(255)
                            // Deux livreurs sous la meme piece, c'est soit
                            // une saisie en double, soit une usurpation.
                            ->unique(ignoreRecord: true),

                        Textarea::make('contract')
                            ->label('Contrat et remarques')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Activité')
                    ->icon('heroicon-o-bolt')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('En service')
                            ->required()
                            ->helperText('Éteint, il ne reçoit plus de nouvelles demandes de course. Les courses déjà acceptées ne sont pas touchées.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             * Le compte et le compteur de courses sont charges AVEC la
             * liste : sans cela, afficher le telephone de quinze livreurs
             * ferait quinze requetes, et leur nombre de courses quinze de
             * plus.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('user.town')
                ->withCount('commandes'))
            ->columns([
                TextColumn::make('user.name')
                    ->label('Livreur')
                    ->formatStateUsing(fn ($record): string => trim(
                        ($record->user?->name ?? '').' '.($record->user?->last_name ?? '')
                    ) ?: '—')
                    ->description(fn ($record): ?string => $record->user?->email)
                    ->searchable(['name', 'last_name'])
                    ->sortable(),

                /*
                 * LE TELEPHONE EN PREMIER apres le nom : c'est la colonne
                 * qu'on vient chercher quand une course se passe mal, et
                 * elle obligeait a ouvrir la fiche.
                 */
                TextColumn::make('user.phone')
                    ->label('Téléphone')
                    ->icon('heroicon-m-phone')
                    ->copyable()
                    ->copyMessage('Numéro copié')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('user.town.title')
                    ->label('Commune')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('id_card')
                    ->label('Pièce d’identité')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('birth_date')
                    ->label('Naissance')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('commandes_count')
                    ->label('Courses')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('En service'),

                TextColumn::make('created_at')
                    ->label('Inscrit le')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('En service')
                    ->placeholder('Tous')
                    ->trueLabel('En service')
                    ->falseLabel('Arrêtés'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun livreur')
            ->emptyStateDescription('Créez une fiche pour rattacher un compte existant au service de livraison.')
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([

            EditDelivreryDriver::class,
            ManageCommandeProducts::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDelivreryDrivers::route('/'),
            'create' => CreateDelivreryDriver::route('/create'),
            'edit' => EditDelivreryDriver::route('/{record}/edit'),
            'commande' => ManageCommandeProducts::route('/{record}/commandes'),
        ];
    }
}
