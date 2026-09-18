<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Models\BookingPayment;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Les paiements d'une reservation.
 *
 * Une ligne par tentative : un client qui se trompe de numero, reessaie et
 * reussit en laisse trois. L'administrateur voit tout et peut tout corriger —
 * marquer une transaction payee, en saisir une encaissee au comptoir, ou
 * rattraper une ligne que le prestataire n'a jamais confirmee.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Paiements';

    protected static ?string $modelLabel = 'paiement';

    protected static ?string $pluralModelLabel = 'paiements';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->label('Nature')
                ->options([
                    BookingPayment::KIND_DEPOSIT => 'Acompte',
                    BookingPayment::KIND_BALANCE => 'Solde',
                ])
                ->native(false)
                ->required(),

            TextInput::make('amount')
                ->label('Montant')
                ->numeric()
                ->step(0.01)
                ->required(),

            Select::make('currency_id')
                ->label('Devise')
                ->relationship('currency', 'title')
                ->native(false)
                ->preload()
                ->required(),

            Select::make('paiment_method_id')
                ->label('Moyen')
                ->relationship('paimentMethod', 'title')
                ->native(false)
                ->preload(),

            Select::make('status_payement_id')
                ->label('Statut')
                ->relationship('statusPayement', 'name')
                ->native(false)
                ->preload(),

            TextInput::make('channel')
                ->label('Canal')
                ->helperText('Ce que renvoie le prestataire : mpesa, orange, airtel, card…'),

            TextInput::make('phone')
                ->label('Numéro débité')
                ->tel(),

            TextInput::make('reference')
                ->label('Notre référence')
                ->helperText('Celle que nous émettons.'),

            TextInput::make('provider_reference')
                ->label('Référence du prestataire')
                ->helperText('Celle que FlexPay confirme. Les deux ne se confondent pas : un rapprochement bancaire a besoin des deux.'),

            Select::make('recorded_by')
                ->label('Encaissé par')
                ->relationship('recordedBy', 'full_name')
                ->native(false)
                ->searchable()
                ->helperText('Le chauffeur, pour un solde remis en espèces.'),

            DateTimePicker::make('paid_at')
                ->label('Payé le')
                ->seconds(false)
                ->native(false)
                ->displayFormat('d/m/Y H:i'),

            DateTimePicker::make('failed_at')
                ->label('Échoué le')
                ->seconds(false)
                ->native(false)
                ->displayFormat('d/m/Y H:i'),

            TextInput::make('failure_reason')
                ->label("Motif de l'échec")
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('kind')
                    ->label('Nature')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === BookingPayment::KIND_DEPOSIT ? 'Acompte' : 'Solde')
                    ->color(fn (string $state) => $state === BookingPayment::KIND_DEPOSIT ? 'warning' : 'success'),

                TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state, BookingPayment $record) => number_format((float) $state, 2, ',', ' ') . ' ' . $record->currency?->code),

                TextColumn::make('paimentMethod.title')
                    ->label('Moyen')
                    ->placeholder('—'),

                TextColumn::make('provider_reference')
                    ->label('Réf. prestataire')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('recordedBy.full_name')
                    ->label('Encaissé par')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('paid_at')
                    ->label('Payé le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('non payé')
                    ->color(fn (BookingPayment $record) => $record->isPaid() ? 'success' : 'danger')
                    ->description(fn (BookingPayment $record) => $record->failure_reason),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Saisir un paiement')
                    ->mutateDataUsing(function (array $data): array {
                        $data['currency_id'] ??= $this->getOwnerRecord()->currency_id;

                        return $data;
                    }),
            ])
            ->recordActions([
                /*
                 * L'administrateur peut declarer une transaction payee : un
                 * webhook perdu ou un encaissement au comptoir ne doivent pas
                 * laisser une reservation coincee.
                 */
                Action::make('markPaid')
                    ->label('Marquer payé')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (BookingPayment $record) => ! $record->isPaid())
                    ->action(fn (BookingPayment $record) => $record->update([
                        'paid_at' => now(),
                        'failed_at' => null,
                        'failure_reason' => null,
                    ])),

                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
