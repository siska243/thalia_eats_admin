{{--
    Le suivi des courses, sur une carte OpenStreetMap.

    Deux modes : une course precise (le chemin normal, depuis le bouton
    « Suivre » d'une ligne de reservation ou de commande), ou la vue
    d'ensemble pour analyser et exporter.

    Pas de cle d'API : Leaflet et les tuiles OSM se chargent sans compte. La
    carte n'est construite qu'une fois ; le rafraichissement ne redessine que
    la ligne, pour ne pas redemander les tuiles toutes les quinze secondes.
--}}
<x-filament-panels::page>
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @endpush

    @if ($this->modeCourse())

        {{ $this->identite }}

        <x-filament::section>
            <x-slot name="heading">Trajet</x-slot>

            <x-slot name="description">
                @if ($this->trace['en_cours'] ?? false)
                    Course en cours : la carte se met à jour toute seule.
                @else
                    Course terminée : le trajet est figé, rien n'est plus interrogé.
                @endif
            </x-slot>

            @if ($this->trace['en_cours'] ?? false)
                <x-slot name="afterHeader">
                    <x-filament::badge color="success">En direct</x-filament::badge>
                </x-slot>
            @endif

            @if ($this->trace)
                <div
                    @if ($this->trace['en_cours'] ?? false) wire:poll.15s="rafraichir" @endif
                    x-data="{
                        trace: @entangle('trace'),
                        carte: null,
                        couches: [],
                        /*
                         * Une pastille lisible sans fichier d'image : Leaflet
                         * sert ses icones par defaut depuis un CDN, et toutes
                         * les courses se ressemblaient. Ici, la couleur et la
                         * lettre disent qui est qui.
                         */
                        pastille(couleur, lettre) {
                            return L.divIcon({
                                className: '',
                                // Guillemets SIMPLES : l'attribut HTML qui porte
                                // ce code est delimite par des doubles, et HTML ne
                                // connait pas l'echappement par antislash. Un guillemet
                                // double y fermait l'attribut, la page devenait
                                // invalide, et Livewire voyait plusieurs racines.
                                html: `<div style='background:${couleur};width:28px;height:28px;border-radius:9999px;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);color:#fff;font:700 13px/22px sans-serif;text-align:center'>${lettre}</div>`,
                                iconSize: [28, 28],
                                iconAnchor: [14, 14],
                            });
                        },
                        dessiner() {
                            const points = (this.trace?.points ?? []).map(p => [p.lat, p.lng]);

                            if (! this.carte || points.length === 0) {
                                return;
                            }

                            this.couches.forEach(c => this.carte.removeLayer(c));
                            this.couches = [];

                            const ligne = L.polyline(points, { weight: 4, color: '#2563eb' }).addTo(this.carte);
                            this.couches.push(ligne);

                            const chauffeur = this.trace?.chauffeur;
                            const client = this.trace?.client;

                            if (chauffeur) {
                                this.couches.push(
                                    L.marker([chauffeur.lat, chauffeur.lng], { icon: this.pastille('#2563eb', 'C') })
                                        .addTo(this.carte)
                                        .bindTooltip('Chauffeur, derniere position', { direction: 'top' })
                                );
                            }

                            // Le client n'est pas toujours localise : une
                            // commande dont l'adresse n'a pas pu etre geocodee
                            // n'en a pas. On ne pose alors aucun repere plutot
                            // qu'un repere faux.
                            if (client) {
                                this.couches.push(
                                    L.marker([client.lat, client.lng], { icon: this.pastille('#16a34a', 'K') })
                                        .addTo(this.carte)
                                        .bindTooltip(client.libelle ?? 'Client', { direction: 'top' })
                                );
                            }

                            const bornes = client
                                ? L.latLngBounds(points.concat([[client.lat, client.lng]]))
                                : ligne.getBounds();

                            this.carte.fitBounds(bornes, { padding: [32, 32] });
                        },
                    }"
                    x-init="
                        const demarrer = () => {
                            carte = L.map($refs.carte).setView([-4.3276, 15.3136], 12);
                            // Les tuiles OpenStreetMap se chargent sans cle. Leur
                            // politique d'usage impose l'attribution et interdit
                            // le telechargement en masse : la carte n'est batie
                            // qu'une fois, et seul le trace est redessine.
                            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                maxZoom: 18,
                                attribution: '&copy; les contributeurs OpenStreetMap',
                            }).addTo(carte);
                            dessiner();
                        };

                        if (window.L) { demarrer(); } else {
                            const script = document.createElement('script');
                            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                            script.onload = demarrer;
                            document.head.appendChild(script);
                        }

                        $watch('trace', () => dessiner());
                    "
                >
                    <div wire:ignore x-ref="carte" style="height: 28rem" class="rounded-lg"></div>

                    {{-- Sans legende, deux pastilles de couleur ne veulent
                         rien dire : l'administrateur devait deviner. --}}
                    <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                        <span class="flex items-center gap-2">
                            <span class="inline-block h-3 w-3 rounded-full" style="background:#2563eb"></span>
                            Chauffeur, dernière position
                        </span>
                        @if ($this->trace['client'] ?? false)
                            <span class="flex items-center gap-2">
                                <span class="inline-block h-3 w-3 rounded-full" style="background:#16a34a"></span>
                                Client — {{ $this->trace['client']['libelle'] }}
                            </span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">
                                Position du client inconnue pour cette course.
                            </span>
                        @endif
                    </div>
                </div>
            @else
                <x-filament::empty-state
                    icon="heroicon-o-map-pin"
                    heading="Aucune position relevée"
                    description="Le livreur ou le chauffeur n'a transmis aucune position pour cette course : il n'y a pas de trajet à tracer."
                />
            @endif
        </x-filament::section>

    @else

        <x-filament::section>
            <x-slot name="heading">Période et type</x-slot>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="suivi-du" class="text-sm font-medium">Du</label>
                    <input id="suivi-du" type="date" wire:model.live="du"
                           class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </div>
                <div>
                    <label for="suivi-au" class="text-sm font-medium">Au</label>
                    <input id="suivi-au" type="date" wire:model.live="au"
                           class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </div>
                <div>
                    <label for="suivi-type" class="text-sm font-medium">Type de course</label>
                    <select id="suivi-type" wire:model.live="type"
                            class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                        <option value="toutes">Toutes</option>
                        <option value="repas">Livraison de repas</option>
                        <option value="location">Location de véhicule</option>
                    </select>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Courses suivies sur la période</x-slot>

            <x-slot name="description">
                L'export reprend exactement ces filtres.
            </x-slot>

            @forelse ($this->courses as $course)
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 last:border-0 dark:border-white/5">
                    <div class="text-sm">
                        <span class="font-medium">{{ $course['reference'] }}</span>
                        <x-filament::badge class="ml-1 inline-flex" :color="$course['type'] === 'repas' ? 'info' : 'warning'">
                            {{ $course['type'] === 'repas' ? 'Repas' : 'Location' }}
                        </x-filament::badge>
                        <div class="text-xs text-gray-500">
                            {{ $course['debut']->format('d/m/Y H:i') }}
                            → {{ $course['fin']->format('H:i') }}
                            · {{ $course['releves'] }} relevé(s)
                        </div>
                    </div>

                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="gray"
                        icon="heroicon-m-map-pin"
                        :href="\App\Filament\Pages\SuiviDesCourses::getUrl(['course' => $course['cle']])"
                    >
                        Suivre
                    </x-filament::button>
                </div>
            @empty
                <x-filament::empty-state
                    icon="heroicon-o-magnifying-glass"
                    heading="Aucune course suivie sur cette période"
                    description="Élargissez la période, ou changez le type de course."
                />
            @endforelse
        </x-filament::section>

    @endif
</x-filament-panels::page>
