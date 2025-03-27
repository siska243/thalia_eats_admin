<?php

namespace App\Wrappers;

use libphonenumber\PhoneNumberUtil;

class LibPhoneNumber
{
    public function __construct(private $phoneNumber,private $code="CD"){

        $this->code = $code;
        $this->phoneNumber = $phoneNumber;
    }

    /**
     * @return PhoneNumberUtil
     */
    public function instance(): PhoneNumberUtil
    {

        return PhoneNumberUtil::getInstance();

    }

    public function numberProto():\libphonenumber\PhoneNumber|\libphonenumber\NumberParseException
    {
        try {
            return $this->instance()->parse($this->phoneNumber, $this->code);

        } catch (\libphonenumber\NumberParseException $e) {
            return $e;
        }
    }

    public function checkValidationNumber():bool
    {
        return $this->instance()->isValidNumber($this->numberProto());
    }

    public function phoneInternational():string
    {
        return $this->instance()->format($this->numberProto(), \libphonenumber\PhoneNumberFormat::INTERNATIONAL);

    }

    public function phoneNational():string
    {

        return $this->instance()->format($this->numberProto(), \libphonenumber\PhoneNumberFormat::NATIONAL);

    }

    public function phoneE164():string
    {

        return $this->instance()->format($this->numberProto(), \libphonenumber\PhoneNumberFormat::E164);

    }
}
