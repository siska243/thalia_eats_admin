# L'ordonnanceur en production — à vérifier avant de déployer ce lot

Ce lot ajoute une tâche planifiée, `expirations:traiter`, qui tourne **toutes
les minutes** et éteint les réservations et les commandes jamais réglées au
bout du délai de `config/expiration.php` (15 minutes par défaut).

## Bonne nouvelle : rien à installer, en principe

L'image Docker lance déjà le planificateur. Il est déclaré dans
`deploy/supervisord.conf`, programme `scheduler` :

    [program:scheduler]
    command=php /var/www/html/artisan schedule:work
    user=www-data

`schedule:work` tourne au premier plan et déclenche lui-même les tâches chaque
minute : **aucune ligne de cron n'est nécessaire** tant que ce programme
tourne. Il n'y a donc normalement rien à faire de ton côté.

## Ce qu'il faut vérifier après le déploiement

Une seule commande, sur le serveur :

    docker exec thalia-eats-backend supervisorctl status scheduler

Tu dois lire `scheduler RUNNING`. Et pour voir que la nouvelle tâche est bien
connue :

    docker exec -u www-data thalia-eats-backend php artisan schedule:list

`expirations:traiter` doit y apparaître avec `* * * * *`.

Pour voir ce qu'elle ferait, sans rien écrire :

    docker exec -u www-data thalia-eats-backend php artisan expirations:traiter --simuler

## Si et seulement si `scheduler` n'est pas RUNNING

Alors, et alors seulement, il faut une entrée cron sur l'hôte. À coller dans
le crontab de l'utilisateur qui pilote Docker, ouvert par `crontab -e` :

    * * * * * docker exec -u www-data thalia-eats-backend php /var/www/html/artisan schedule:run >> /var/log/thalia-scheduler.log 2>&1

Une seule ligne, l'étoile cinq fois : cron appelle Laravel chaque minute, et
c'est Laravel qui décide quelles tâches sont dues. Ne mets **jamais** une
entrée cron par commande : les fréquences sont déclarées dans
`app/Console/Kernel.php`, et une seconde liste dans le crontab divergerait.

Si tu ajoutes cette ligne, coupe d'abord le programme `scheduler` dans
`deploy/supervisord.conf`, sinon les deux tournent en parallèle. Les tâches
portent `withoutOverlapping()`, donc rien ne serait exécuté deux fois, mais
deux ordonnanceurs pour un même service sont deux endroits à surveiller.

## Le délai

Il se change sans livraison, dans `.env.production` :

    EXPIRATION_MINUTES=15

Puis `docker exec thalia-eats-backend php artisan config:cache` et un
redémarrage du conteneur. La valeur vaut pour les deux modules, location et
restauration.
