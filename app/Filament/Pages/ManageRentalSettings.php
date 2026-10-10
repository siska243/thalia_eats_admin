<?php

namespace App\Filament\Pages;

use App\Settings\RentalSettings;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Les reglages de la location, modifiables sans deploiement.
 *
 * Ces valeurs apparaissent dans le calcul du prix, dans l'ecran client, dans
 * l'administration et dans les messages WhatsApp. Les figer dans le code
 * obligerait a livrer une version pour changer un pourcentage.
 */
class ManageRentalSettings extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Location';

    protected static ?string $title = 'Réglages de la location';

    protected static ?string $navigationLabel = 'Réglages';

    protected static ?int $navigationSort = 4;

    protected static string $settings = RentalSettings::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Paiement')
                ->columns(2)
                ->schema([
                    TextInput::make('deposit_percentage')
                        ->label('Acompte')
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->helperText('Part réglée en ligne à la réservation. Le solde est encaissé en espèces par le chauffeur.'),

                    TextInput::make('refund_percentage')
                        ->label('Remboursement')
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required()
                        ->helperText("Part rendue quand une réservation est annulée, ou ratée parce qu'un autre client a payé avant. Le montant est calculé et enregistré ; le virement reste manuel."),
                ]),

            Section::make('Course')
                ->columns(2)
                ->schema([
                    TextInput::make('minimum_hours')
                        ->label('Durée minimale facturée')
                        ->suffix('heures')
                        ->numeric()
                        ->minValue(0.5)
                        ->step(0.5)
                        ->required()
                        ->helperText('Toute heure entamée est due.'),

                    TextInput::make('pickup_code_max_attempts')
                        ->label('Essais sur le code de prise en charge')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->required()
                        ->helperText('Au-delà, le code se bloque et seul un administrateur peut le débloquer.'),
                ]),

            Section::make('Durée de location')
                ->description("Les bornes proposées par l'application, et refusées par l'API au-delà. Une course de 24 heures est une journée : le plafond horaire ne dépasse pas 23.")
                ->columns(2)
                ->schema([
                    TextInput::make('hours_min')
                        ->label('Durée minimale à l\'heure')
                        ->suffix('heures')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(23)
                        ->live()
                        ->required()
                        ->helperText('Sous cette durée, la réservation à l\'heure est refusée.'),

                    TextInput::make('hours_max')
                        ->label('Durée maximale à l\'heure')
                        ->suffix('heures')
                        ->numeric()
                        ->integer()
                        // Jamais sous le minimum, et jamais au-dessus de 23 :
                        // 24 heures sont une journee, qui se facture au tarif
                        // journalier.
                        ->minValue(fn (Get $get): int => max(1, (int) $get('hours_min')))
                        ->maxValue(23)
                        ->required()
                        ->helperText('Au-delà, le client doit réserver à la journée. 23 au maximum.'),

                    TextInput::make('days_min')
                        ->label('Durée minimale à la journée')
                        ->suffix('jours')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->live()
                        ->required()
                        ->helperText('Sous cette durée, la réservation à la journée est refusée.'),

                    TextInput::make('days_max')
                        ->label('Durée maximale à la journée')
                        ->suffix('jours')
                        ->numeric()
                        ->integer()
                        ->minValue(fn (Get $get): int => max(1, (int) $get('days_min')))
                        ->required()
                        ->helperText('Au-delà, la location se négocie de la main à la main.'),

                    TextInput::make('lead_time_minutes')
                        ->label('Délai de prévenance')
                        ->suffix('minutes')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->helperText("Délai minimal entre la réservation et le départ. L'API refuse un départ passé ou trop proche. Le back-office, lui, reste libre : une course prise au comptoir peut partir tout de suite."),
                ]),

            Section::make('Marque et contact')
                ->columns(2)
                ->schema([
                    TextInput::make('brand_food')
                        ->label('Nom de la restauration')
                        ->required()
                        ->maxLength(255)
                        ->helperText("Affiché par l'application pour l'univers des repas."),

                    TextInput::make('brand_rental')
                        ->label('Nom de la location')
                        ->required()
                        ->maxLength(255)
                        ->helperText("Affiché par l'application pour l'univers des véhicules."),

                    TextInput::make('whatsapp_number')
                        ->label('Numéro WhatsApp')
                        ->tel()
                        ->maxLength(255)
                        ->helperText('Au format international, par exemple +243900000000. Laissé vide, le bouton WhatsApp ne sert à rien.'),

                    Textarea::make('out_of_zone_message')
                        ->label('Message hors zone')
                        ->rows(3)
                        ->required()
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->helperText('Montré quand la commune de prise en charge n\'est pas desservie, et renvoyé tel quel par l\'API à la réservation refusée.'),
                ]),
        ]);
    }
}
