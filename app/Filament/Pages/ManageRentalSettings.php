<?php

namespace App\Filament\Pages;

use App\Settings\RentalSettings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

/**
 * Les reglages de la location, modifiables sans deploiement.
 *
 * Ces valeurs apparaissent dans le calcul du prix, dans l'ecran client, dans
 * l'administration et dans les messages WhatsApp. Les figer dans le code
 * obligerait a livrer une version pour changer un pourcentage.
 */
class ManageRentalSettings extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Location';

    protected static ?string $title = 'Réglages de la location';

    protected static ?string $navigationLabel = 'Réglages';

    protected static ?int $navigationSort = 4;

    protected static string $settings = RentalSettings::class;

    public function form(Form $form): Form
    {
        return $form->schema([
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
                        ->helperText("Au-delà, le code se bloque et seul un administrateur peut le débloquer."),
                ]),
        ]);
    }
}
