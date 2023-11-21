<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RestaurantResource\Pages;
use App\Filament\Resources\RestaurantResource\RelationManagers;
use App\Models\Restaurant;
use Filament\Forms\Components\{DatePicker, FileUpload, KeyValue, TextInput, Textarea, MarkdownEditor, Repeater, Section, Select, TimePicker};
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $label = "Nos restaurants";
    protected static ?string $navigationGroup = "Thalia eats";
    protected static ?int $navigationSort =2;
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Information de base')->schema([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->native(false)
                    ->searchable()->label('Responsable du restaurant'),
                TextInput::make('name')
                    ->required()
                    ->label('Nom du restaurant')
                    ->maxLength(255),
                TextInput::make('adresse')
                    ->required()
                    ->maxLength(65535),
                TextInput::make('reference')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(255),
                TextInput::make('whatsapp')
                ->tel()
                    ->required(false)
                    ->maxLength(255)
                ])->columns(2)
                ,
                Section::make('Information supplementaire')->schema([
                Repeater::make('openHours')->schema([
                    Select::make('day')
                    ->options([
                        'lundi'=>'Lundi',
                        'mardi'=>'Mardi',
                        'mercredi'=>'Mercredi',
                        'jeudi'=>'Jeudi',
                        'vendredi'=>"Vendredi",
                        "samedi"=>"Samedi",
                        'dimanche'=>"Dimanche"
                    ])->native(false)->required()->label('Le jour'),
                    TimePicker::make('startAt')->required()->label('Heure d\'ouverture'),
                    TimePicker::make('endAt')->required()->label('Heure de fermeture')
                ])->columns(3),
                FileUpload::make('banniere')
                ->disk('uploads_image')
                    ->acceptedFileTypes(['image/*'])->columnSpanFull(),
                ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('is_active'),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                ImageColumn::make('banniere')->disk('uploads_image'),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
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
            'index' => Pages\ListRestaurants::route('/'),
            'create' => Pages\CreateRestaurant::route('/create'),
            'edit' => Pages\EditRestaurant::route('/{record}/edit'),
        ];
    }

    public static function toSlug($string):String
    {
        return Str::slug($string);
    }
}
