#---php artisan-----#
ARTISAN = php artisan
PHP_ARTISAN_SERVE = /usr/bin/php artisan serve
MAKE_CONTROLLER=$(ARTISAN) make:controller
MIGRATE = $(ARTISAN) migrate
MODEL = $(ARTISAN) make:model -m
MIGRATION = $(ARTISAN) make:migration
FILAMENT_RESOURCE= $(ARTISAN) make:filament-resource --generate
FILAMENT_USER=$(ARTISAN) make:filament-user
RESOURCE=$(ARTISAN) make:resource
RESOURCE_COLLECTION=$(ARTISAN) make:resource --collection
STORAGE_LINK=$(ARTISAN) storage:link
COMPOSER=composer
#------------#


## === 📦  NPM ===================================================

i: ##  install a dependancy
	$(COMPOSER) install
.PHONY: s

s: ##  run serve
	$(PHP_ARTISAN_SERVE)
.PHONY: s

c: ## make controller
	$(MAKE_CONTROLLER)
.PHONY: m-c

model: ## make model with migration
	$(MODEL)
.PHONY: model

m: ## migrate
	$(MIGRATE)
.PHONY: m

m-m: ## make migration
	$(MIGRATION)
.PHONY: m-m

f-r: ## create a filament resource
	$(FILAMENT_RESOURCE)
.PHONY: f-r

f-u: ## create a filament user
	$(FILAMENT_USER)
.PHONY: f-u

r: ## generate a resource
	$(RESOURCE)
.PHONY: r

r-c: ## create a resource collection.
	$(RESOURCE_COLLECTION)
.PHONY: r-c

s-l: ## storage link.
	$(STORAGE_LINK)
.PHONY: s-l

req: ## storage link.
	$(ARTISAN) make:request
.PHONY: req

#---------------------------------------------#
