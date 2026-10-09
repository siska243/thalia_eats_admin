{{--
    Le suivi des courses, sur une carte OpenStreetMap.

    Pas de cle d'API : Leaflet et les tuiles OSM se chargent sans compte. La
    carte n'est construite qu'une fois ; le rafraichissement ne redessine que
    la ligne, pour ne pas redemander les tuiles toutes les quinze secondes.
--}}
<x-filament-panels::page>
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @endpush

    <x-filament::section>
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

    <div class="grid gap-4 lg:grid-cols-3">
        <x-filament::section class="lg:col-span-1">
            <x-slot name="heading">Courses suivies</x-slot>

            @forelse ($this->courses as $course)
                <button type="button"
                        wire:click="$set('course', '{{ $course['cle'] }}')"
                        @class([
                            'w-full rounded-lg px-3 py-2 text-left text-sm',
                            'bg-primary-50 dark:bg-primary-500/10' => $this->course === $course['cle'],
                            'hover:bg-gray-50 dark:hover:bg-white/5' => $this->course !== $course['cle'],
                        ])>
                    <span class="font-medium">{{ $course['reference'] }}</span>
                    <span class="text-gray-500">
                        · {{ $course['type'] === 'repas' ? 'Repas' : 'Location' }}
                    </span>
                    <br />
                    <span class="text-xs text-gray-500">
                        {{ $course['debut']->format('d/m/Y H:i') }}
                        → {{ $course['fin']->format('H:i') }}
                        · {{ $course['releves'] }} relevé(s)
                    </span>
                </button>
            @empty
                <p class="text-sm text-gray-500">
                    Aucune course suivie sur cette période.
                </p>
            @endforelse
        </x-filament::section>

        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Trajet</x-slot>

            @if ($this->trace)
                <x-slot name="description">
                    {{ $this->trace['debut'] }} → {{ $this->trace['fin'] }}
                    · {{ $this->trace['duree_minutes'] }} min
                    · {{ number_format($this->trace['distance_km'], 2, ',', ' ') }} km parcourus
                    @if ($this->trace['en_cours'])
                        · <span class="font-medium text-primary-600">course en cours</span>
                    @endif
                </x-slot>
            @endif

            <div
                @if (($this->trace['en_cours'] ?? false)) wire:poll.15s="rafraichir" @endif
                x-data="{
                    trace: @entangle('trace'),
                    carte: null,
                    ligne: null,
                    marqueur: null,
                    dessiner() {
                        const points = (this.trace?.points ?? []).map(p => [p.lat, p.lng]);

                        if (! this.carte || points.length === 0) {
                            return;
                        }

                        if (this.ligne) { this.carte.removeLayer(this.ligne); }
                        if (this.marqueur) { this.carte.removeLayer(this.marqueur); }

                        this.ligne = L.polyline(points, { weight: 4 }).addTo(this.carte);
                        this.marqueur = L.marker(points[points.length - 1]).addTo(this.carte);
                        this.carte.fitBounds(this.ligne.getBounds(), { padding: [24, 24] });
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
            </div>

            @unless ($this->trace)
                <p class="pt-3 text-sm text-gray-500">
                    Choisissez une course à gauche pour voir son trajet.
                </p>
            @endunless
        </x-filament::section>
    </div>
</x-filament-panels::page>
