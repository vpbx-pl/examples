# SMS Proxy (PHP + callapi.pl)

Prosty skrypt PHP, który udostępnia endpoint **HTTP GET** do wysyłania SMS-ów
przez API serwisu [callapi.pl](https://callapi.pl/docs/sms/). Przeznaczony do
uruchamiania **lokalnie** (na tej samej maszynie co skrypty wywołujące) - jako
prosty most między cron-ami, monitoringami, home-automation itp. a callapi.pl.

> **Uwaga:** skrypt nie ma własnej autoryzacji (nie ma tokena, hasła, basic-auth).
> Cała ochrona opiera się na tym, że nasłuchuje **tylko na 127.0.0.1** i ma
> whitelistę IP w `config.php`. Nie wystawiaj go na publiczny interfejs.

## Jak to działa

Klient wykonuje żądanie GET:

```
GET /sms.php?to=48123456789&text=Witaj&from=MyApp
```

Co robi skrypt:

1. sprawdza whitelistę IP (`allowed_ips`),
2. waliduje numer odbiorcy / nadawcę / długość treści,
3. loguje się do callapi.pl przez `POST /api/v1/login`
   używając `callapi_username` + `callapi_password` z `config.php`,
4. wysyła `POST /api/v1/sms` z nagłówkiem `Authorization: Bearer <jwt>`,
5. zwraca odpowiedź API jako JSON.

Skrypt loguje się przy każdym żądaniu (brak cache tokena, brak logu na
dysku - prosty stateless flow).

### Parametry

| Parametr | Wymagany | Opis                                                                                            |
|----------|----------|-------------------------------------------------------------------------------------------------|
| `to`     | tak      | Numer odbiorcy w formacie **E.164 bez `+`**, same cyfry, np. `48123456789` (a NIE `+48123456789`) |
| `text`   | tak      | Treść wiadomości (UTF-8). Limit z `max_text_length`                                              |
| `from`   | nie      | Nadawca SMS-a; domyślnie `default_from` z `config.php`. Format weryfikuje callapi.pl              |

### Odpowiedź

Czysty tekst (`Content-Type: text/plain`):

| Status HTTP | Body                                       |
|-------------|--------------------------------------------|
| 200         | `OK <sms_id>` (UUID przydzielony przez callapi.pl) |
| 400         | `Error: <opis błędu walidacji>`            |
| 403         | `Error: forbidden`                         |
| 500         | `Error: <opis błędu konfiguracji>`         |
| 502         | `Error: <opis błędu callapi>`              |

Przykładowe sprawdzenie w bashu:

```bash
out=$(curl -fsS "http://127.0.0.1:8080/sms.php?to=48123456789&text=Test&from=MyApp")
case "$out" in
    "OK "*) echo "wyslane, id=${out#OK }" ;;
    *)      echo "blad: $out" ;;
esac
```

## Instalacja

```bash
# 1. Skopiuj pliki na serwer (np. do /var/www/sms-proxy)
sudo mkdir -p /var/www/sms-proxy
sudo cp sms.php config.example.php .htaccess /var/www/sms-proxy/

# 2. Utwórz konfigurację
sudo cp /var/www/sms-proxy/config.example.php /var/www/sms-proxy/config.php
sudo nano /var/www/sms-proxy/config.php   # wstaw callapi_username, callapi_password
                                          # i upewnij się, że allowed_ips = ['127.0.0.1', '::1']

# 3. Uprawnienia (config tylko do odczytu dla www-data)
sudo chown -R root:www-data /var/www/sms-proxy
sudo chmod 750 /var/www/sms-proxy
sudo chmod 640 /var/www/sms-proxy/config.php
```

Wymagane: PHP 7.4+ z rozszerzeniami `curl` i `mbstring`.

```bash
# Debian / Ubuntu (apt) - dopasuj wersję PHP do swojego systemu
sudo apt install php8.3-curl php8.3-mbstring
sudo systemctl restart php8.3-fpm

# RHEL / Rocky / Alma / Fedora (dnf/yum)
sudo dnf install php-mbstring php-curl
sudo systemctl restart php-fpm
```

Sprawdzenie: `php -m | grep -E 'curl|mbstring'` powinno wypisać oba.

## Wdrożenie na Apache (lokalnie)

Plik `.htaccess` w paczce blokuje dostęp do wszystkich plików `.php` poza `sms.php`
oraz do plików konfiguracyjnych. Aby `.htaccess` działał, w VirtualHost musi być
`AllowOverride All`.

`/etc/apache2/sites-available/sms-proxy.conf`:

```apache
# Nasłuch tylko na localhost
Listen 127.0.0.1:8080

<VirtualHost 127.0.0.1:8080>
    ServerName  localhost
    DocumentRoot /var/www/sms-proxy

    <Directory /var/www/sms-proxy>
        Options -Indexes -ExecCGI
        AllowOverride All
        Require ip 127.0.0.1
    </Directory>

    # Blokada plików pomocniczych nawet bez .htaccess
    <FilesMatch "^(config\.php|config\.example\.php|README\.md|\.htaccess)$">
        Require all denied
    </FilesMatch>

    ErrorLog  ${APACHE_LOG_DIR}/sms-proxy-error.log
    CustomLog ${APACHE_LOG_DIR}/sms-proxy-access.log combined
</VirtualHost>
```

Aktywacja:

```bash
sudo a2ensite sms-proxy
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Sprawdź firewallem, że port 8080 nie jest dostępny z zewnątrz:

```bash
sudo ufw status         # albo: ss -ltnp | grep 8080
```

## Wdrożenie na Nginx z PHP-FPM (lokalnie)

`/etc/nginx/sites-available/sms-proxy`:

```nginx
server {
    # Nasłuch TYLKO na localhost
    listen 127.0.0.1:8080;
    server_name localhost;
    root /var/www/sms-proxy;

    # Dodatkowy bezpiecznik na poziomie nginx
    allow 127.0.0.1;
    deny  all;

    # Tylko sms.php może być wykonywane
    location = /sms.php {
        include        snippets/fastcgi-php.conf;
        fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
    }

    # Wszystko inne - 404
    location / {
        return 404;
    }

    # Jawna blokada plików konfiguracji
    location ~* /(config\.php|config\.example\.php|\.htaccess|README\.md)$ {
        deny all;
        return 404;
    }

    access_log /var/log/nginx/sms-proxy-access.log;
    error_log  /var/log/nginx/sms-proxy-error.log;
}
```

Aktywacja:

```bash
sudo ln -s /etc/nginx/sites-available/sms-proxy /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## Bezpieczeństwo - rekomendacje

1. **Tylko localhost.** Web-serwer powinien nasłuchiwać wyłącznie na
   `127.0.0.1` (`Listen 127.0.0.1:8080` w Apache, `listen 127.0.0.1:8080;`
   w Nginx). Tak `sms.php` jest niedostępne z zewnątrz nawet jeśli zapomnisz
   o whitelist IP.

2. **Whitelista IP w skrypcie.** Drugi bezpiecznik. W `config.php`:
   ```php
   'allowed_ips' => ['127.0.0.1', '::1'],
   ```
   Wpisy obsługują wildcards, więc można wpisać podsieć:
   ```php
   'allowed_ips' => ['127.0.0.1', '::1', '192.168.1.*', '10.*.*.*'],
   ```
   Dla podsieci spoza granicy oktetu (np. `/22`, `/20`) wildcard nie wystarczy -
   wpisz konkretne IP albo rozbij na kilka wpisów.

   Pusta tablica = brak filtra na poziomie skryptu - **nie zostawiaj jej pustej**.

3. **Firewall.** Upewnij się, że port web-serwera nie jest puszczony na świat.
   `ufw status` / `iptables -L` / `ss -ltnp`. Jeśli już musisz wystawić go
   poza maszynę, użyj VPN-a / SSH tunnel-a / WireGuard-a, a nie publicznego TCP.

4. **Wymuszenie metody GET (Nginx, opcjonalne).**
   ```nginx
   if ($request_method !~ ^(GET|HEAD)$) { return 405; }
   ```

5. **Rate-limiting.** Zabezpieczenie przed pętlą w skrypcie wywołującym, która
   wypaliłaby kredyty callapi.

   Nginx:
   ```nginx
   limit_req_zone $binary_remote_addr zone=sms:10m rate=10r/m;

   location = /sms.php {
       limit_req zone=sms burst=5 nodelay;
       include        snippets/fastcgi-php.conf;
       fastcgi_pass   unix:/run/php/php8.2-fpm.sock;
   }
   ```

6. **Uprawnienia plików.** `config.php` zawiera login i hasło do callapi:
   ```bash
   sudo chmod 640 /var/www/sms-proxy/config.php
   sudo chown root:www-data /var/www/sms-proxy/config.php
   ```

7. **Aktualizacje.** Pilnuj aktualizacji PHP i web-serwera (`unattended-upgrades`).

## Przykłady użycia

```bash
# curl - z lokalnego skryptu
curl "http://127.0.0.1:8080/sms.php?to=48123456789&text=Witaj%20Adam&from=MyApp"

# wget
wget -qO- "http://127.0.0.1:8080/sms.php?to=48123456789&text=Test"

# Z poprawnym URL-encode polskich znaków
curl --get "http://127.0.0.1:8080/sms.php" \
     --data-urlencode "to=48123456789" \
     --data-urlencode "text=Cześć, to test ąęłóźż" \
     --data-urlencode "from=MyApp"
```

W skryptach pamiętaj o URL-encode treści (spacje -> `%20`, polskie znaki UTF-8
też muszą być zakodowane). **Numer odbiorcy podajesz bez `+`**, np.
`48123456789` (nie `+48123456789`).

## Licencja

MIT
