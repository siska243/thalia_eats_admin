<?php
namespace App\Filament\Resources\PreviewResource\Api;

use App\Filament\Resources\PreviewResource\Api\Handlers\CreateHandler;
use App\Filament\Resources\PreviewResource\Api\Handlers\UpdateHandler;
use App\Filament\Resources\PreviewResource\Api\Handlers\DeleteHandler;
use App\Filament\Resources\PreviewResource\Api\Handlers\PaginationHandler;
use App\Filament\Resources\PreviewResource\Api\Handlers\DetailHandler;
use Rupadana\ApiService\ApiService;
use App\Filament\Resources\ProductResource;
use Illuminate\Routing\Router;


class PreviewApiService extends ApiService
{
    protected static string | null $resource = ProductResource::class;

    public static function handlers() : array
    {
        return [
            CreateHandler::class,
            UpdateHandler::class,
            DeleteHandler::class,
            PaginationHandler::class,
            DetailHandler::class
        ];

    }
}
