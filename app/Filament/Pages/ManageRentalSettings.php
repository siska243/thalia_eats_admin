<?php

namespace App\Filament\Pages;

use App\Settings\RentalSettings;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
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
