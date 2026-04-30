<?php
/**
 * Konfiguracja SMS Proxy.
 * Skopiuj ten plik do config.php i uzupełnij wartości.
 *
 *   cp config.example.php config.php
 *   chmod 640 config.php
 */

return [
    // Dane logowania do callapi.pl (skrypt loguje się przed każdą wysyłką).
    'callapi_username' => 'twoj-login',
    'callapi_password' => 'twoje-haslo',

    // Domyślny nadawca jeśli klient nie poda ?from=...
    'default_from'     => 'MyApp',

    // Whitelista IP. Pusta tablica = brak ograniczeń (NIEZALECANE).
    // Wpisy obsługują wildcards (fnmatch):
    //   '192.168.1.5'   - dokładny IP
    //   '192.168.1.*'   - cała /24
    //   '10.*.*.*'      - cała /8
    //   '172.16.*'      - skrótowo (fnmatch traktuje * "zachłannie")
    'allowed_ips'      => [
        '127.0.0.1',
        '::1',
        // '192.168.1.*',
    ],

    // Maksymalna długość treści SMS.
    'max_text_length'  => 700,
];
