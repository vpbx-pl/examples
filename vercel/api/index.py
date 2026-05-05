import base64
import hashlib
import hmac
import json
import logging
import os
import sys
import traceback

import jwt
import requests
from flask import Flask, jsonify, request


API_URL = "https://api.vpbx.pl/api/v1"

logging.basicConfig(stream=sys.stdout, level=logging.INFO)
log = logging.getLogger("vpbx-webhook")

app = Flask(__name__)


def verify_signature(authorization_header: str, raw_body: bytes, secret: str) -> dict:
    if not authorization_header or not authorization_header.lower().startswith("bearer "):
        raise ValueError("Missing or malformed Authorization header")

    token = authorization_header.split(" ", 1)[1]

    try:
        decoded = jwt.decode(token, secret, algorithms=["HS256", "HS512"])
    except jwt.InvalidTokenError as e:
        raise ValueError(f"Invalid JWT: {e}")

    expected_hash = base64.b64encode(
        hmac.new(secret.encode("utf-8"), raw_body, hashlib.sha256).digest()
    ).decode("ascii")

    if not hmac.compare_digest(expected_hash, decoded.get("hash", "")):
        raise ValueError("Payload hash mismatch")

    return decoded


def send_sms(to_number: str, text: str, from_name: str, username: str, password: str) -> dict:
    token_resp = requests.post(
        f"{API_URL}/login",
        json={"username": username, "password": password},
        timeout=10,
    )
    token_resp.raise_for_status()
    token = token_resp.json()["token"]

    sms_resp = requests.post(
        f"{API_URL}/sms",
        json={"from": from_name, "to": to_number, "text": text},
        headers={"Authorization": f"Bearer {token}"},
        timeout=10,
    )
    sms_resp.raise_for_status()
    return sms_resp.json()


@app.errorhandler(Exception)
def on_exception(e):
    log.exception("Unhandled exception")
    return jsonify({"error": type(e).__name__, "detail": str(e), "trace": traceback.format_exc()}), 500


@app.route("/", defaults={"path": ""}, methods=["GET", "POST"])
@app.route("/<path:path>", methods=["GET", "POST"])
def catch_all(path):
    log.info("Request method=%s path=/%s", request.method, path)

    if request.method == "GET":
        return jsonify({"ok": True, "path": f"/{path}"}), 200

    signing_key = os.environ.get("VPBX_SIGNING_KEY")
    api_username = os.environ.get("VPBX_API_USERNAME")
    api_password = os.environ.get("VPBX_API_PASSWORD")
    notify_number = os.environ.get("NOTIFY_NUMBER")
    sms_sender = os.environ.get("SMS_SENDER", "callapi.pl")

    required = [
        ("VPBX_API_USERNAME", api_username),
        ("VPBX_API_PASSWORD", api_password),
        ("NOTIFY_NUMBER", notify_number),
    ]
    if os.environ.get("SKIP_SIGNATURE") != "1":
        required.append(("VPBX_SIGNING_KEY", signing_key))
    missing = [name for name, value in required if not value]
    if missing:
        log.error("Missing env vars: %s", missing)
        return jsonify({"error": "Server not configured", "missing": missing}), 500

    raw_body = request.get_data() or b""
    log.info("Body bytes=%d", len(raw_body))

    if os.environ.get("SKIP_SIGNATURE") == "1":
        log.warning("SKIP_SIGNATURE=1 -- accepting webhook without signature verification")
    else:
        auth_header = (
            request.headers.get("Authorization")
            or request.headers.get("X-Authorization")
            or request.headers.get("X-Forwarded-Authorization")
            or ""
        )
        try:
            verify_signature(auth_header, raw_body, signing_key)
        except ValueError as e:
            log.warning("Signature check failed: %s (auth header present=%s)", e, bool(auth_header))
            return jsonify({"error": str(e), "headers_seen": list(request.headers.keys())}), 401

    try:
        event = json.loads(raw_body.decode("utf-8"))
    except (json.JSONDecodeError, UnicodeDecodeError) as e:
        log.warning("JSON decode failed: %s", e)
        return jsonify({"error": "Invalid JSON"}), 400

    log.info("Event: type=%s status=%s call_id=%s", event.get("event_type"), event.get("status"), event.get("call_id"))

    if event.get("event_type") != "cdr-pbx" or event.get("status") != "ANSWER":
        return jsonify({"skipped": True}), 200

    text = (
        f"Polaczenie od {event.get('from')} do {event.get('to')} odebrane "
        f"po {event.get('time_answered')}s (dzwonilo {event.get('time_dialed')}s). "
        f"Call-ID: {event.get('call_id')}"
    )

    try:
        result = send_sms(notify_number, text, sms_sender, api_username, api_password)
    except requests.RequestException as e:
        log.error("SMS API error: %s", e)
        return jsonify({"error": f"SMS API error: {e}"}), 502

    log.info("SMS sent: %s", result.get("sms_id"))
    return jsonify({"sent": True, "sms_id": result.get("sms_id")}), 200
