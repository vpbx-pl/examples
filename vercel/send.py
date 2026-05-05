import base64, hashlib, hmac, json, jwt, requests

key = "HeEJME58fZZv86CEDVprNpFHYJatSRrqntcaS3tpCn2juGFh5pYNGxGNbJ5wv8pj"
body = json.dumps({
    "event_type": "cdr-pbx",
    "from": "0048224723555",
    "to": "447960290155",
    "time_answered": 2,
    "time_dialed": 9,
    "call_id": "1777037370.358186",
    "status": "ANSWER",
}).encode()

payload_hash = base64.b64encode(hmac.new(key.encode(), body, hashlib.sha256).digest()).decode()
token = jwt.encode({"hash": payload_hash}, key, algorithm="HS256")

r = requests.post(
    "https://vercel-delta-tan.vercel.app",
    data=body,
    headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
)
print(r.status_code, r.text)
