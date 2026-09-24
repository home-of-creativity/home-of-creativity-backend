import os
from urllib.parse import urlparse

import httpx


def init_sentry() -> None:
    dsn = (os.environ.get("SENTRY_DSN") or "").strip()
    if dsn == "":
        return
    import sentry_sdk

    sentry_sdk.init(dsn=dsn, send_default_pii=False, traces_sample_rate=0.0)
from telegram.request import HTTPXRequest
from telegram.ext import Application


def run_application(
    application: Application,
    *,
    webhook_url: str | None = None,
    port: int = 8444,
    url_path: str = "telegram",
    secret_token: str | None = None,
) -> None:
    from telegram import Update

    init_sentry()
    public = (webhook_url or os.environ.get("TELEGRAM_WEBHOOK_URL") or "").strip().rstrip("/")
    listen_port = int(os.environ.get("TELEGRAM_WEBHOOK_PORT") or port)
    path = url_path.strip().strip("/")

    if public:
        webhook = f"{public}/{path}"
        print(f"Telegram webhook: {webhook} (listen 127.0.0.1:{listen_port})", flush=True)
        application.run_webhook(
            listen="127.0.0.1",
            port=listen_port,
            url_path=path,
            webhook_url=webhook,
            secret_token=secret_token or None,
            allowed_updates=Update.ALL_TYPES,
            drop_pending_updates=False,
            bootstrap_retries=5,
        )
        return

    application.run_polling(allowed_updates=Update.ALL_TYPES, drop_pending_updates=False)


def _telegram_proxy() -> str | None:
    raw = (os.environ.get("TELEGRAM_PROXY") or "").strip()
    if raw == "":
        return None

    parsed = urlparse(raw if "://" in raw else f"http://{raw}")
    host = (parsed.hostname or "").lower()
    scheme = (parsed.scheme or "http").lower()
    port = parsed.port
    if host == "" or host.endswith("trycloudflare.com"):
        return None
    # Laravel / Vite / Next / webhook listeners are not HTTP CONNECT proxies.
    if host in {"127.0.0.1", "localhost", "::1"} and port in {3000, 5173, 8000, 8444, 8445, 8446, 8447}:
        return None
    if scheme not in {"http", "https", "socks5", "socks5h"}:
        return None
    return raw if "://" in raw else f"http://{raw}"


def telegram_request(*, long_polling: bool = False) -> HTTPXRequest:
    read_timeout = 60.0 if long_polling else 30.0
    transport = httpx.AsyncHTTPTransport(
        local_address="0.0.0.0",
        retries=3,
        proxy=_telegram_proxy(),
        http1=True,
        http2=False,
    )
    return HTTPXRequest(
        connect_timeout=20.0,
        read_timeout=read_timeout,
        write_timeout=30.0,
        pool_timeout=10.0,
        http_version="1.1",
        httpx_kwargs={
            "trust_env": False,
            "transport": transport,
        },
    )


def start_heartbeat(name: str) -> None:
    import threading
    import time
    from pathlib import Path

    path = Path(__file__).resolve().parents[1] / "storage" / "app" / "dev-beats" / name

    def loop() -> None:
        while True:
            try:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("", encoding="utf-8")
            except OSError:
                pass
            time.sleep(180)

    threading.Thread(target=loop, name=f"hoc-beat-{name}", daemon=True).start()
