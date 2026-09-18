<?php

namespace App\Wrappers;

use libphonenumber\PhoneNumberUtil;

class LibPhoneNumber
{
    public function __construct(private $phoneNumber, private $code = 'CD')
    {

        $this->code = $code;
        $this->phoneNumber = $phoneNumber;
    }

    public function instance(): PhoneNumberUtil
    {

        return PhoneNumberUtil::getInstance();

    }

    public function numberProto(): \libphonenumber\PhoneNumber|\libphonenumber\NumberParseException
    {
        try {
            return $this->instance()->parse($this->phoneNumber, $this->code);

        } catch (\libphonenumber\NumberParseException $e) {
            return $e;
        }
    }

    /**
     * numberProto() RENVOIE l'exception de parsing au lieu de la lever : sans
     * le garde ci-dessous, isValidNumber() recevait un objet du mauvais type
     * et declenchait une TypeError — que « catch (Exception) » ne rattrape pas,
     * puisque TypeError est une Error. Un numero mal tape rendait donc un 500
     * au lieu d'un message de validation, sur le chemin de commande vivant.
     *
     * Le garde est ici, dans la seule definition de « ce numero est valide »,
     * et pas dans chacun des quatre appelants : trois d'entre eux etaient
     * exposes, et le quatrieme ne l'etait que parce que quelqu'un y avait
     * pense. Une garantie posee a la racine ne peut pas etre oubliee par le
     * cinquieme appelant a venir.
     */
    public function checkValidationNumber(): bool
    {
        $proto = $this->numberProto();

        if (! $proto instanceof \libphonenumber\PhoneNumber) {
            return false;
        }

        return $this->instance()->isValidNumber($proto);
    }

    public function phoneInternational(): string
    {
        return $this->instance()->format($this->numberProto(), \libphonenumber\PhoneNumberFormat::INTERNATIONAL);

    }

    public function phoneNational(): string
    {

        return $this->instance()->format($this->numberProto(), \libphonenumber\PhoneNumberFormat::NATIONAL);

    }

    public function phoneE164(): string
    {

        return $this->instance()->format($this->numberProto(), \libphonenumber\PhoneNumberFormat::E164);

    }
}
