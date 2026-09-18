<?php

namespace App\Wrappers;

use libphonenumber\PhoneNumber;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
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

    public function numberProto():PhoneNumber|NumberParseException
    {
        try {
            return $this->instance()->parse($this->phoneNumber, $this->code);

        } catch (NumberParseException $e) {
            return $e;
        }
    }

    public function checkValidationNumber():bool
    {
        return $this->instance()->isValidNumber($this->numberProto());
    }

    public function phoneInternational():string
    {
        return $this->instance()->format($this->numberProto(), PhoneNumberFormat::INTERNATIONAL);

    }

    public function phoneNational():string
    {

        return $this->instance()->format($this->numberProto(), PhoneNumberFormat::NATIONAL);

    }

    public function phoneE164():string
    {

        return $this->instance()->format($this->numberProto(), PhoneNumberFormat::E164);

    }
}
