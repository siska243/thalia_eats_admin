<?php

namespace App\Console\Commands;

use App\Models\TrackOrder;
use App\Settings\SuiviSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Supprime les releves de position trop anciens.
 *
 * Les traces servent a comprendre comment ameliorer le service, pas a
 * constituer un historique des deplacements des livreurs et des chauffeurs.
 * Passe le delai de conservation, elles n'ont plus de raison d'exister.
 *
 * Elles doivent avoir ete extraites avant : l'ecran d'administration exporte
 * les releves en tableur, et c'est la condition posee a cette purge. Une
 * purge qui detruit ce qu'on voulait analyser serait un contresens — d'ou
 * l'option `--simuler`, qui dit ce qui partira au prochain passage.
 */
class PurgerLesRelevesDePosition extends Command
{
    protected $signature = 'suivi:purger
                            {--simuler : Affiche ce qui serait supprime sans rien ecrire}';

    protected $description = 'Supprime les releves de position au-dela du delai de conservation';

    public function handle(SuiviSettings $settings): int
    {
        $jours = $settings->retention_days;
        $limite = now()->subDays($jours);

        $requete = TrackOrder::query()->where('created_at', '<', $limite);
        $total = $requete->count();

        if ($total === 0) {
            $this->info("Aucun releve de plus de {$jours} jours.");

            return self::SUCCESS;
        }

        if ($this->option('simuler')) {
            $this->comment("[simulation] {$total} releve(s) anterieur(s) au {$limite->toDateString()} seraient supprimes.");

            return self::SUCCESS;
        }

        // Par lots : la table grandit vite, et un `delete` unique sur des
        // centaines de milliers de lignes tient le verrou trop longtemps.
        $supprimes = 0;

        do {
            $lot = $requete->clone()->limit(1000)->delete();
            $supprimes += $lot;
        } while ($lot > 0);

        Log::info('Releves de position purges', [
            'jours_conserves' => $jours,
            'anterieurs_au' => $limite->toDateTimeString(),
            'supprimes' => $supprimes,
        ]);

        $this->info("{$supprimes} releve(s) supprime(s).");

        return self::SUCCESS;
    }
}
