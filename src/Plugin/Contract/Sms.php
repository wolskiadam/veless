<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Zdolność: SMS. Wtyczka potrafi wysłać wiadomość SMS (np. SMSAPI).
 */
interface Sms
{
    /**
     * @param string $phone numer w formacie międzynarodowym bez „+" (np. 48600100200)
     * @return array{ok:bool,message:string,id?:string}
     */
    public function sendSms(string $phone, string $text): array;
}
