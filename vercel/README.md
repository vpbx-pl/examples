# Webhook CDR -> SMS (Vercel)

Przykladowa funkcja serverless dla [Vercel](https://vercel.com) napisana w Pythonie.
Odbiera podpisany webhook `cdr-pbx` z VPBX.PL i wysyla SMS z powiadomieniem
o odebranym polaczeniu, uzywajac [SMS API callapi.pl](https://callapi.pl/docs/sms/).

## Co robi

1. Przyjmuje POST z JSON-em w formacie:

   ```json
   {
     "event_type": "cdr-pbx",
     "from": "48600000000",
     "to": "48501000000",
     "time_answered": 2,
     "time_dialed": 9,
     "call_id": "1777037370.358186",
     "status": "ANSWER"
   }
   ```

2. Weryfikuje podpis webhooka (JWT z naglowka `Authorization: Bearer ...` + HMAC-SHA256
   pelnego body, zgodnie z [przykladem](../signed_callback/php/check_webhook_signature.php)).
3. Jezeli `event_type == "cdr-pbx"` i `status == "ANSWER"`, loguje sie do API VPBX
   (`POST /api/v1/login`) i wysyla SMS (`POST /api/v1/sms`) na numer z `NOTIFY_NUMBER`.
   Tresc SMS zawiera pola `from`, `to`, `time_answered`, `time_dialed`, `call_id`
   z webhooka.

## Struktura

```
vercel/
├── api/
│   └── index.py         # aplikacja Flask (WSGI) obslugujaca /webhook i /api/webhook
├── requirements.txt     # zaleznosci (flask, requests, PyJWT)
├── vercel.json          # rewrite /webhook -> /api/webhook
└── .env.example         # wymagane zmienne srodowiskowe
```

Vercel uruchamia `api/index.py` jako aplikacje WSGI (`app = Flask(__name__)`),
dlatego plik nie korzysta z `BaseHTTPRequestHandler`.

## Wymagane konto

1. Konto na [VPBX.PL](https://ssl.vpbx.pl).
2. Konto API w zakladce [Dostep do API -> Konta OAuth](https://ssl.vpbx.pl/apiaccounts/view).
3. Wlaczone podpisywanie webhookow w zakladce [Konfiguracja API](https://ssl.vpbx.pl/apiaccounts/settings)
   - zanotuj wygenerowany klucz podpisu.

## Deploy na Vercel

```bash
cd vercel
vercel                # deploy preview
vercel --prod         # deploy produkcyjny
```

Nastepnie w panelu projektu na Vercel (Settings -> Environment Variables) ustaw:

| Zmienna             | Opis                                                       |
|---------------------|------------------------------------------------------------|
| `VPBX_SIGNING_KEY`  | Klucz podpisu webhooka z zakladki Konfiguracja API         |
| `VPBX_API_USERNAME` | Login konta API                                            |
| `VPBX_API_PASSWORD` | Haslo konta API                                            |
| `NOTIFY_NUMBER`     | Numer odbiorcy SMS w formacie E.164 (np. `48500000000`)    |
| `SMS_SENDER`        | Nadawca SMS (opcjonalne, domyslnie `callapi.pl`)           |
| `SKIP_SIGNATURE`    | Ustaw na `1`, aby pominac weryfikacje podpisu (tylko testy) |

Po deployu w ustawieniach centrali w VPBX.PL skonfiguruj URL webhooka jako:

```
https://<twoj-projekt>.vercel.app/webhook
```

## Test lokalny

```bash
cd vercel
pip install -r requirements.txt
vercel dev
```

Wygeneruj testowy podpisany request (PyJWT + HMAC):

```python
import base64, hashlib, hmac, json, jwt, requests

key = "twoj-klucz-podpisu"
body = json.dumps({
    "event_type": "cdr-pbx",
    "from": "48222222222",
    "to": "48333333333",
    "time_answered": 2,
    "time_dialed": 9,
    "call_id": "1777037370.358186",
    "status": "ANSWER",
}).encode()

payload_hash = base64.b64encode(hmac.new(key.encode(), body, hashlib.sha256).digest()).decode()
token = jwt.encode({"hash": payload_hash}, key, algorithm="HS256")

r = requests.post(
    "http://localhost:3000/webhook",
    data=body,
    headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
)
print(r.status_code, r.text)
```

## Odpowiedzi endpointu

| Kod | Sytuacja                                                       |
|-----|----------------------------------------------------------------|
| 200 | SMS wyslany (`{"sent": true, "sms_id": "..."}`) lub zdarzenie pominiete (`{"skipped": true}`) |
| 400 | Niepoprawny JSON                                               |
| 401 | Niepoprawny lub brakujacy podpis                               |
| 500 | Brakuje zmiennych srodowiskowych                               |
| 502 | Blad komunikacji z API VPBX                                    |
