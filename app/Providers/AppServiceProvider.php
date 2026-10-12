<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Facades\Health;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Model::unguard();

        // Les uploads de l'admin (disque « uploads_image ») finissent dans
        // public/images. En prod, public/images et storage/ sont DEUX volumes
        // montes separes : les fichiers temporaires Livewire (disque par defaut
        // « local » = volume storage) ne pouvaient pas etre transferes vers
        // uploads_image (volume images), et comme ce disque est en
        // 'throw' => false l'echec etait avale : le nom du fichier etait
        // enregistre en base sans que le fichier ne soit ecrit. En posant le
        // disque temporaire sur « uploads_image », temp et final sont sur le
        // meme volume et le transfert est fiable.
        config(['livewire.temporary_file_upload.disk' => 'uploads_image']);

        Health::checks([
            OptimizedAppCheck::new(),
            DebugModeCheck::new(),
            EnvironmentCheck::new(),
        ]);
    }
}
