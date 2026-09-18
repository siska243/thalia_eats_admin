<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Filament\Resources\BookingResource\RelationManagers;
use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Vehicle;
use App\Models\PaimentMethod;
use App\Services\Rental\BookingPricing;
use App\Services\Rental\BookingWorkflow;
use App\Services\Rental\VehicleAvailability;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Location';

    protected static ?string $label = 'Réservation';

    protected static ?string $pluralLabel = 'Réservations';

    protected static ?int $navigationSort = 3;

    /** Les réservations à traiter sautent aux yeux depuis le menu. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()
            ->where('status', Booking::STATUS_CONFIRMED)
            ->whereNull('chauffeur_id')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('La course')
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label('Client')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),

                    Select::make('vehicle_id')
                        ->label('Véhicule')
                        ->relationship('vehicle', 'plate_number')
                        ->getOptionLabelFromRecordUsing(fn (Vehicle $record) => "{$record->name} — {$record->plate_number}")
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->required(),

                    DateTimePicker::make('starts_at')
                        ->label('Début')
                        ->seconds(false)
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->live(onBlur: true)
                        ->required(),

                    DateTimePicker::make('ends_at')
                        ->label('Fin')
                        ->seconds(false)
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->live(onBlur: true)
                        ->required()
                        ->after('starts_at')
                        /*
                         * La disponibilite est verifiee ici, a la saisie.
                         *
                         * Sans cette regle, l'administration pourrait creer un
                         * chevauchement que tout le reste du module interdit :
                         * deux clients sur le meme vehicule a la meme heure,
                         * decouverts le jour de la course.
                         */
                        ->rule(function (Get $get, ?Booking $record) {
                            return function (string $attribute, $value, callable $fail) use ($get, $record) {
                                $vehicle = Vehicle::find($get('vehicle_id'));
                                $startsAt = $get('starts_at');

                                if (! $vehicle || ! $startsAt || ! $value) {
                                    return;
                                }

                                $free = app(VehicleAvailability::class)->isVehicleAvailable(
                                    $vehicle,
                                    Carbon::parse($startsAt),
                                    Carbon::parse($value),
                                    $record,
                                );

                                if (! $free) {
                                    $fail('Ce véhicule est déjà pris sur ce créneau.');
                                }
                            };
                        }),

                    /*
                     * Le montant est affiche, jamais saisi.
                     *
                     * Il est recalcule par le serveur a l'enregistrement : un
                     * total tape a la main dans l'administration divergerait du
                     * prix annonce au client.
                     */
                    Placeholder::make('quote')
                        ->label('Montant')
                        ->columnSpanFull()
                        ->content(function (Get $get): string {
                            $vehicle = Vehicle::find($get('vehicle_id'));
                            $startsAt = $get('starts_at');
                            $endsAt = $get('ends_at');

                            if (! $vehicle || ! $startsAt || ! $endsAt) {
                                return 'Choisissez un véhicule et un créneau.';
                            }

                            try {
                                $quote = app(BookingPricing::class)->quote(
                                    $vehicle,
                                    Carbon::parse($startsAt),
                                    Carbon::parse($endsAt),
                                );
                            } catch (\InvalidArgumentException) {
                                return 'La fin doit suivre le début.';
                            }

                            $code = $quote->currency->code;

                            return sprintf(
                                '%s h × %s %s = %s %s — acompte %s %s, solde %s %s en espèces',
                                rtrim(rtrim(number_format($quote->durationHours, 2, ',', ' '), '0'), ','),
                                number_format($quote->hourlyRate, 2, ',', ' '), $code,
                                number_format($quote->total, 2, ',', ' '), $code,
                                number_format($quote->deposit, 2, ',', ' '), $code,
                                number_format($quote->balance, 2, ',', ' '), $code,
                            );
                        }),
                ]),

            Section::make('Lieux')
                ->columns(2)
                ->schema([
                    TextInput::make('pickup_location')
                        ->label('Prise en charge')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('dropoff_location')
                        ->label('Destination')
                        ->maxLength(255)
                        ->helperText('Facultative : le client peut ne pas la préciser.'),
                ]),

            Section::make('Affectation')
                ->columns(2)
                ->schema([
                    Select::make('chauffeur_id')
                        ->label('Chauffeur')
                        ->native(false)
                        ->searchable()
                        /*
                         * La liste ne montre que les chauffeurs reellement
                         * affectables : permis valide, en service, et libres
                         * sur ce creneau. Proposer les autres reviendrait a
                         * laisser choisir une erreur.
                         */
                        ->options(function (Get $get, ?Booking $record): array {
                            $startsAt = $get('starts_at');
                            $endsAt = $get('ends_at');

                            if (! $startsAt || ! $endsAt) {
                                return Chauffeur::query()->assignable()->pluck('full_name', 'id')->all();
                            }

                            return app(VehicleAvailability::class)
                                ->availableChauffeurs(Carbon::parse($startsAt), Carbon::parse($endsAt), $record)
                                ->pluck('full_name', 'id')
                                ->all();
                        })
                        ->helperText('Seuls les chauffeurs en service, au permis valide et libres sur ce créneau.'),

                    Select::make('status')
                        ->label('Statut')
                        ->native(false)
                        /*
                         * Une reservation nait en attente de paiement, meme
                         * creee depuis l'administration : c'est le paiement
                         * qui retient le vehicule, pas la saisie.
                         */
                        ->default(Booking::STATUS_PENDING_PAYMENT)
                        ->options([
                            Booking::STATUS_PENDING_PAYMENT => 'En attente de paiement',
                            Booking::STATUS_CONFIRMED => 'Confirmée',
                            Booking::STATUS_IN_PROGRESS => 'En cours',
                            Booking::STATUS_COMPLETED => 'Terminée',
                            Booking::STATUS_CANCELLED => 'Annulée',
                            Booking::STATUS_LOST => 'Ratée (à rembourser)',
                            Booking::STATUS_EXPIRED => 'Expirée',
                        ])
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('vehicle.plate_number')
                    ->label('Véhicule')
                    ->formatStateUsing(fn (Booking $record) => $record->vehicle?->name)
                    ->searchable(),

                TextColumn::make('starts_at')
                    ->label('Créneau')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (Booking $record) => 'jusqu\'à ' . $record->ends_at?->format('H:i'))
                    ->sortable(),

                TextColumn::make('chauffeur.full_name')
                    ->label('Chauffeur')
                    ->placeholder('à affecter')
                    ->color(fn (Booking $record) => $record->chauffeur_id ? null : 'warning')
                    ->searchable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, Booking $record) => number_format((float) $state, 2, ',', ' ') . ' ' . $record->currency?->code)
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Booking::STATUS_CONFIRMED, Booking::STATUS_IN_PROGRESS => 'success',
                        Booking::STATUS_COMPLETED => 'gray',
                        Booking::STATUS_PENDING_PAYMENT => 'warning',
                        Booking::STATUS_LOST => 'danger',
                        Booking::STATUS_CANCELLED, Booking::STATUS_EXPIRED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('starts_at', 'desc')
            ->actionsPosition(\Filament\Tables\Enums\ActionsPosition::BeforeColumns)
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options(static::statusLabels()),

                Tables\Filters\Filter::make('unassigned')
                    ->label('Sans chauffeur')
                    ->query(fn (Builder $query) => $query
                        ->where('status', Booking::STATUS_CONFIRMED)
                        ->whereNull('chauffeur_id')),

                Tables\Filters\Filter::make('to_refund')
                    ->label('À rembourser')
                    ->query(fn (Builder $query) => $query
                        ->where('status', Booking::STATUS_LOST)
                        ->whereNull('refunded_at')),

                Tables\Filters\Filter::make('pickup_locked')
                    ->label('Code bloqué')
                    ->query(fn (Builder $query) => $query->whereNotNull('pickup_code_locked_at')),
            ])
            ->actions([
                /*
                 * Le parcours courant, en un clic chacun.
                 *
                 * L'administrateur garde par ailleurs le droit de tout
                 * modifier a la main depuis le formulaire : ces actions ne
                 * restreignent rien, elles evitent d'avoir a se souvenir
                 * quels champs vont ensemble. Encaisser un solde sans laisser
                 * de ligne de paiement, par exemple, priverait le
                 * rapprochement de fin de journee de sa seule trace.
                 */
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('payDeposit')
                        ->label("Encaisser l'acompte")
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn (Booking $record) => $record->status === Booking::STATUS_PENDING_PAYMENT)
                        ->form([
                            Select::make('paiment_method_id')
                                ->label('Moyen de paiement')
                                ->options(fn () => PaimentMethod::query()->where('is_active', true)->pluck('title', 'id')->all())
                                ->native(false)
                                ->required(),
                            TextInput::make('reference')->label('Référence'),
                            TextInput::make('phone')->label('Numéro débité')->tel(),
                        ])
                        ->modalDescription(fn (Booking $record) => "Acompte de {$record->deposit} {$record->currency?->code}. Le créneau est vérifié : si un autre client a payé avant, la réservation passe en « ratée » et un remboursement est calculé.")
                        ->action(function (Booking $record, array $data) {
                            $confirmed = app(BookingWorkflow::class)->payDeposit(
                                $record,
                                (int) $data['paiment_method_id'],
                                $data['reference'] ?? null,
                                $data['phone'] ?? null,
                            );

                            \Filament\Notifications\Notification::make()
                                ->title($confirmed ? 'Réservation confirmée' : 'Créneau déjà pris')
                                ->body($confirmed
                                    ? 'Le code de prise en charge a été généré.'
                                    : "Un autre client avait payé avant. La réservation passe en « ratée », avec un remboursement à effectuer.")
                                ->status($confirmed ? 'success' : 'warning')
                                ->send();
                        }),

                    Tables\Actions\Action::make('startRide')
                        ->label('Démarrer la course')
                        ->icon('heroicon-o-play')
                        ->requiresConfirmation()
                        ->modalDescription("À utiliser quand le chauffeur ne peut pas le faire depuis son application.")
                        ->visible(fn (Booking $record) => $record->status === Booking::STATUS_CONFIRMED)
                        ->action(fn (Booking $record) => app(BookingWorkflow::class)->startRide($record)),

                    Tables\Actions\Action::make('collectBalance')
                        ->label('Encaisser le solde')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(fn (Booking $record) => "Solde de {$record->balance} {$record->currency?->code}, remis en espèces au chauffeur. La course passe en « terminée ».")
                        ->visible(fn (Booking $record) => $record->status === Booking::STATUS_IN_PROGRESS)
                        ->action(fn (Booking $record) => app(BookingWorkflow::class)->collectBalance($record)),

                    Tables\Actions\Action::make('cancelBooking')
                        ->label('Annuler')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->form([
                            Textarea::make('reason')->label("Motif")->rows(2),
                        ])
                        ->modalDescription("Le remboursement dû est calculé sur ce qui a réellement été payé. Le virement, lui, reste à faire à la main.")
                        ->visible(fn (Booking $record) => in_array($record->status, [Booking::STATUS_PENDING_PAYMENT, Booking::STATUS_CONFIRMED], true))
                        ->action(fn (Booking $record, array $data) => app(BookingWorkflow::class)->cancel($record, $data['reason'] ?? null)),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button(),

                Tables\Actions\Action::make('unlockPickupCode')
                    ->label('Débloquer le code')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription("Le chauffeur a épuisé ses essais. Vérifiez avec le client avant de débloquer.")
                    ->visible(fn (Booking $record) => $record->isPickupCodeLocked())
                    ->action(fn (Booking $record) => app(BookingWorkflow::class)->unlockPickupCode($record)),

                Tables\Actions\Action::make('markRefunded')
                    ->label('Marquer remboursée')
                    ->icon('heroicon-o-banknotes')
                    ->requiresConfirmation()
                    ->modalDescription("À cocher une fois le virement réellement effectué : cette application ne rembourse pas elle-même.")
                    ->visible(fn (Booking $record) => $record->refund_amount > 0 && $record->refunded_at === null)
                    ->action(fn (Booking $record) => app(BookingWorkflow::class)->markRefunded($record)),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * Le chiffrage d'une reservation depuis les donnees du formulaire.
     *
     * Appele a la creation comme a la modification : le montant ne vient
     * jamais du formulaire, il est refait par le serveur a partir du vehicule
     * et du creneau. Un total saisi a la main divergerait du prix annonce au
     * client.
     */
    public static function quoteFor(array $data): \App\Services\Rental\BookingQuote
    {
        return app(BookingPricing::class)->quote(
            Vehicle::findOrFail($data['vehicle_id']),
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
        );
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            Booking::STATUS_PENDING_PAYMENT => 'En attente de paiement',
            Booking::STATUS_CONFIRMED => 'Confirmée',
            Booking::STATUS_IN_PROGRESS => 'En cours',
            Booking::STATUS_COMPLETED => 'Terminée',
            Booking::STATUS_CANCELLED => 'Annulée',
            Booking::STATUS_LOST => 'Ratée',
            Booking::STATUS_EXPIRED => 'Expirée',
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
