# Après `certbot --apache`

Certbot crée `thalia-eats-le-ssl.conf` tout seul, avec les bons chemins de
certificats. **Trois lignes y manquent**, à ajouter à la main dans le
`<VirtualHost *:443>` qu'il a écrit :

```apache
RequestHeader set X-Forwarded-Proto "https"
RequestHeader set X-Forwarded-Port "443"
RemoteIPHeader X-Forwarded-For
```

Sans elles, Laravel voit du `http://` derrière votre TLS : les liens de
paiement signés deviennent invalides à l'ouverture, et l'administration
Filament sert ses assets en contenu mixte.

Certbot recopie déjà `ProxyPass`, `ProxyPassReverse` et `LimitRequestBody`
depuis le vhost 80.

Puis, comme toujours sur cette machine :

```bash
sudo apache2ctl configtest      # doit dire : Syntax OK
sudo apache2ctl -S              # « default server » ne doit PAS être thalia
sudo systemctl reload apache2   # reload, jamais restart
```

## Pourquoi jamais `restart`

`reload` recharge la configuration sans couper les connexions, et **refuse de
s'appliquer** si la configuration est invalide — les applications en place
continuent de tourner sur l'ancienne. `restart` arrête Apache d'abord : si la
nouvelle configuration ne passe pas, il ne redémarre pas, et toutes les
applications du serveur tombent en même temps.
