<?php

namespace App\Filament\Resources;

use App\Enums\Device;
use App\Enums\MobilePermissions;
use App\Enums\TypeUser;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Rawilk\FilamentPasswordInput\Password;

class UserResource extends Resource
{
    protected static ?int $navigationSort = 1;

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $label = 'Nos utilisateurs';

    protected static string|\UnitEnum|null $navigationGroup = 'Clients et partenaires';

    /**
     * La fiche d'un utilisateur.
     *
     * Demande du proprietaire le 17 octobre : « ameliore la page, visuellement
     * c'est pas bon ». Quatre defauts, dont deux qui ne se voient pas.
     *
     * 1. TOUT TENAIT DANS UNE SEULE SECTION SANS TITRE : l'identite, l'adresse,
     *    le type de compte, les roles, les permissions et le mot de passe, en
     *    vrac sur deux colonnes. Quatre sections nommees desormais, dans
     *    l'ordre ou on les remplit.
     *
     * 2. LE CHAMP `password` ETAIT DECLARE DEUX FOIS, sous le meme nom : une
     *    fois en haut (cache a l'edition) et une fois dans « Permissions ».
     *    Deux champs pour une meme cle d'etat se marchent dessus. Il n'en
     *    reste qu'un, dans sa propre section.
     *
     * 3. LE MOT DE PASSE VIVAIT DANS « PERMISSIONS », auxquelles il n'a rien a
     *    voir.
     *
     * 4. DES STYLES ECRITS A LA MAIN (`text-primary-600`, une couleur de
     *    bordure en dur) luttaient contre le theme : ils divergent a la
     *    premiere mise a jour, et le mode sombre s'en apercoit en premier.
     *    Retires, Filament sait colorer ses propres champs.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identité')
                    ->description('Ce que le client, le livreur ou le restaurateur voit de lui-même.')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Prénom')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('last_name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Adresse électronique')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Deux comptes sur la meme adresse rendraient la
                            // connexion imprevisible. `ignoreRecord` : sa
                            // propre adresse ne doit pas se refuser elle-meme.
                            ->unique(ignoreRecord: true),

                        TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(255)
                            ->helperText('C\'est par là que le livreur ou le chauffeur le joint.'),
                    ]),

                Section::make('Où le livrer')
                    ->description('Son adresse habituelle. Elle se complète aussi depuis l\'application.')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->collapsed(fn (string $operation): bool => $operation === 'create')
                    ->collapsible()
                    ->schema([
                        TextInput::make('principal_adresse')
                            ->label('Adresse principale')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('town_id')
                            ->label('Commune')
                            ->relationship('town', 'title')
                            ->searchable()
                            ->preload()
                            ->native(false),
                    ]),

                Section::make('Accès')
                    ->description('Ce que ce compte a le droit de faire. Les rôles décident, pas le type.')
                    ->icon('heroicon-o-key')
                    ->columns(2)
                    ->schema([
                        Select::make('type_user')
                            ->label('Type de compte')
                            /*
                             * Les valeurs viennent de l'enumeration, et non
                             * de chaines ecrites ici : le formulaire
                             * proposait `drivers` et `interne`, que
                             * l'application ne connait pas — elle n'ecrit
                             * que `client`, `delivery` et `restaurant`.
                             *
                             * Les deux anciennes valeurs restent AFFICHABLES,
                             * parce que deux comptes les portent deja en
                             * base : les retirer laisserait leur champ vide
                             * sans qu'on sache pourquoi. On cesse d'en
                             * produire, on ne reecrit pas l'existant.
                             */
                            ->options(array_merge(
                                TypeUser::getOptions(),
                                ['drivers' => 'Livreur (ancienne valeur)', 'interne' => 'Interne (ancienne valeur)'],
                            ))
                            ->native(false)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Indicatif. Ce sont les rôles ci-dessous qui ouvrent les écrans.'),

                        Select::make('roles')
                            ->label('Rôles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->native(false)
                            ->helperText('Ce qui décide réellement de ce que ce compte voit.'),

                        Select::make('devices')
                            ->label('Appareils autorisés')
                            ->options(Device::getOptions())
                            ->multiple()
                            ->preload()
                            ->native(false),

                        Select::make('mobile_permissions')
                            ->label('Permissions mobiles')
                            ->options(MobilePermissions::getOptions())
                            ->multiple()
                            ->preload()
                            ->native(false),
                    ]),

                Section::make('Mot de passe')
                    ->description('À la création il est obligatoire. Ensuite il ne se change que si vous le demandez.')
                    ->icon('heroicon-o-lock-closed')
                    ->columns(2)
                    ->schema([
                        /*
                         * UN SEUL champ `password`, et non deux comme avant.
                         * A la creation il est demande ; a l'edition il
                         * attend l'interrupteur, pour qu'enregistrer la
                         * fiche ne reecrive pas un mot de passe qu'on n'a
                         * pas voulu toucher.
                         */
                        Toggle::make('changePassword')
                            ->label('Changer le mot de passe')
                            ->hiddenOn('create')
                            ->live()
                            ->columnSpanFull(),

                        Password::make('password')
                            ->label('Mot de passe')
                            ->password()
                            ->confirmed()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('changePassword'))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('changePassword')),

                        Password::make('password_confirmation')
                            ->label('Confirmer le mot de passe')
                            ->password()
                            ->autocomplete('new-password')
                            // Jamais enregistre : il ne sert qu'a comparer.
                            ->dehydrated(false)
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('changePassword')),
                    ]),
            ]);
    }

    /**
     * La liste des utilisateurs.
     *
     * Demande du proprietaire le 17 octobre : « ameliore egalement ici ».
     *
     * Quatre manques. Les libelles etaient AUTOMATIQUES et en anglais
     * (« Name », « Phone », « Email verified at »), le NOM DE FAMILLE
     * n'apparaissait nulle part — seul le prenom —, il n'y avait AUCUN
     * FILTRE sur un ecran qui grandit a chaque inscription, et la liste
     * s'ouvrait sur les plus ANCIENS comptes alors qu'on vient y chercher
     * les derniers.
     *
     * `email_verified_at` etait une date brute, repliee par defaut : on
     * veut savoir si le compte est confirme, pas a quelle seconde.
     */
    public static function table(Table $table): Table
    {
        return $table
            // La commune et les roles avec la liste : sans cela, vingt-cinq
            // utilisateurs font cinquante requetes.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['town', 'roles']))
            ->columns([
                TextColumn::make('name')
                    ->label('Utilisateur')
                    ->formatStateUsing(fn ($record): string => trim("{$record->name} {$record->last_name}"))
                    ->description(fn ($record): ?string => $record->email)
                    ->searchable(['name', 'last_name', 'email'])
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('Téléphone')
                    ->icon('heroicon-m-phone')
                    ->copyable()
                    ->copyMessage('Numéro copié')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('roles.name')
                    ->label('Rôles')
                    ->badge()
                    ->placeholder('Aucun')
                    ->toggleable(),

                TextColumn::make('type_user')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('town.title')
                    ->label('Commune')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * Confirme ou non, et non la seconde de confirmation : on
                 * vient verifier qu'un compte est utilisable, pas lire un
                 * horodatage.
                 */
                IconColumn::make('email_verified_at')
                    ->label('Confirmé')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Inscrit le')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            // Les derniers inscrits en premier : c'est eux qu'on vient voir.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('roles')
                    ->label('Rôle')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),

                SelectFilter::make('type_user')
                    ->label('Type de compte')
                    ->options(fn (): array => array_merge(
                        TypeUser::getOptions(),
                        ['drivers' => 'Livreur (ancienne valeur)', 'interne' => 'Interne (ancienne valeur)'],
                    )),

                TernaryFilter::make('email_verified_at')
                    ->label('Compte confirmé')
                    ->placeholder('Tous')
                    ->trueLabel('Confirmés')
                    ->falseLabel('En attente')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun utilisateur')
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

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
