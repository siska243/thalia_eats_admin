<?php

namespace Tests\Unit;

use App\Helpers\ImageUrl;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Une chaine vide passait le test `$this->image ? … : null` des Resources et
 * produisait l'adresse `/images/`, que l'application chargeait pour recevoir
 * un 404. Le cas se traite une fois, dans le helper.
 */
class ImageUrlTest extends TestCase
{
    private function requete(): Request
    {
        return Request::create('http://127.0.0.1:8000/api/rental/vehicles');
    }

    #[Test]
    public function un_nom_de_fichier_donne_une_url_absolue(): void
    {
        $this->assertSame(
            'http://127.0.0.1:8000/images/vehicles/photo.jpeg',
            ImageUrl::make($this->requete(), 'vehicles/photo.jpeg')
        );
    }

    #[Test]
    public function une_chaine_vide_ou_nulle_ne_donne_pas_d_url(): void
    {
        $this->assertNull(ImageUrl::make($this->requete(), ''));
        $this->assertNull(ImageUrl::make($this->requete(), '   '));
        $this->assertNull(ImageUrl::make($this->requete(), null));
    }

    #[Test]
    public function un_slash_en_trop_ne_double_pas_dans_l_adresse(): void
    {
        $this->assertSame(
            'http://127.0.0.1:8000/images/vehicles/photo.jpeg',
            ImageUrl::make($this->requete(), '/vehicles/photo.jpeg')
        );
    }
}
