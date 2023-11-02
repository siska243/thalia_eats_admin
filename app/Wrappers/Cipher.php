<?php

namespace App\Wrappers;

class Cipher
{
    public static $encrypt_method = "AES-256-CBC";
    public static $secret_key = 'emmanuel_simisi';
    public static $secret_iv = 'emmanuel_simisi_siska';


    /**
     * @param string|null $text
     * @return false|string
     */
    public static function Encrypt(string $text = null)
    {
        $text = "$text";
        $key = hash('sha256', self::$secret_key);
        $iv = substr(hash('sha256', self::$secret_iv), 0, 16);
        $output = openssl_encrypt($text, self::$encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
        return $output;
    }
    /**
     * @param string|null $text
     * @return false|string
     */
    public static function  Decrypt(string $text = null)
    {
        $key = hash('sha256', self::$secret_key);
        $iv = substr(hash('sha256', self::$secret_iv), 0, 16);
        $output = openssl_decrypt(base64_decode($text), self::$encrypt_method, $key, 0, $iv);
        return $output;
    }
}
