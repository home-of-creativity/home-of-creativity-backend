import os
import threading
import time
from pathlib import Path

import httpx
from dotenv import load_dotenv
from telegram import Update
from telegram.ext import Application, CommandHandler, ContextTypes

from telegram_http import run_application, start_heartbeat, telegram_request

load_dotenv(Path(__file__).resolve().parents[1] / ".env")
load_dotenv()

_local_api = (os.environ.get("HOC_LOCAL_API_URL") or "http://127.0.0.1:8000").rstrip("/")
_origin = (os.environ.get("HOC_API_URL") or _local_api).rstrip("/")
if "trycloudflare.com" in _origin:
    _origin = _local_api
API_URL = _origin if _origin.endswith("/api") else f"{_origin}/api"
BOT_SECRET = os.environ.get("TELEGRAM_DEV_BOT_SECRET", "change-me-dev")
API_TIMEOUT = httpx.Timeout(20.0)
DEV_IDS = {
    item.strip()
    for item in (os.environ.get("TELEGRAM_DEV_IDS") or "").split(",")
    if item.strip()
}


def api_headers() -> dict[str, str]:
    return {
        "Accept": "application/json",
        "X-Webhook-Secret": BOT_SECRET,
    }


async def request_json(method: str, path: str) -> httpx.Response:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        return await client.request(method, f"{API_URL}{path}", headers=api_headers())


def allowed(update: Update) -> bool:
    user = update.effective_user
    return user is not None and str(user.id) in DEV_IDS


async def ping(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    if not allowed(update):
        await update.message.reply_text("هذا البوت لمطوري Home of Creativity فقط.")
        return
    response = await request_json("GET", "/bot/dev/ping")
    if response.status_code >= 400:
        await update.message.reply_text("تعذر الوصول إلى واجهة المطور.")
        return
    await update.message.reply_text("pong")


async def status(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    if not allowed(update):
        await update.message.reply_text("هذا البوت لمطوري Home of Creativity فقط.")
        return
    response = await request_json("GET", "/bot/dev/status")
    if response.status_code >= 400:
        await update.message.reply_text("تعذر قراءة حالة السيرفر.")
        return
    data = response.json().get("data") or {}
    state = "يعمل" if data.get("up") else "Server Down"
    await update.message.reply_text(f"{state}\n{data.get('health_url', '')}")


async def reply_text(update: Update, path: str, failure: str) -> None:
    if update.message is None:
        return
    if not allowed(update):
        await update.message.reply_text("هذا البوت لمطوري Home of Creativity فقط.")
        return
    response = await request_json("GET", path)
    if response.status_code >= 400:
        await update.message.reply_text(failure)
        return
    text = (response.json().get("data") or {}).get("text") or failure
    await update.message.reply_text(text)


async def bots(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await reply_text(update, "/bot/dev/bots", "تعذر قراءة حالة البوتات.")


async def queue(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await reply_text(update, "/bot/dev/queue", "تعذر قراءة الطابور.")


async def digest(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    await reply_text(update, "/bot/dev/digest", "تعذر قراءة الملخص.")


def watch_scheduler() -> None:
    beat = Path(__file__).resolve().parents[1] / "storage" / "app" / "dev-beats" / "scheduler"
    flag = beat.parent / "scheduler-down"
    token = (os.environ.get("TELEGRAM_DEV_BOT_TOKEN") or "").strip()
    chat_id = (os.environ.get("TELEGRAM_DEV_CHAT_ID") or "").strip()
    time.sleep(150)
    while True:
        try:
            age = time.time() - beat.stat().st_mtime
        except OSError:
            age = 9999
        down = age > 150
        flagged = flag.exists()
        if token and chat_id and down and not flagged:
            flag.parent.mkdir(parents=True, exist_ok=True)
            flag.write_text("1", encoding="utf-8")
            _send(token, chat_id, "Scheduler Down: no heartbeat")
        elif token and chat_id and not down and flagged:
            flag.unlink(missing_ok=True)
            _send(token, chat_id, "Scheduler up")
        time.sleep(120)


def _send(token: str, chat_id: str, text: str) -> None:
    try:
        httpx.post(
            f"https://api.telegram.org/bot{token}/sendMessage",
            data={"chat_id": chat_id, "text": text, "disable_web_page_preview": True},
            timeout=8,
        )
    except httpx.HTTPError:
        return


def main() -> None:
    token = (os.environ.get("TELEGRAM_DEV_BOT_TOKEN") or "").strip()
    if token == "":
        raise SystemExit("TELEGRAM_DEV_BOT_TOKEN is empty")
    application = (
        Application.builder()
        .token(token)
        .request(telegram_request())
        .get_updates_request(telegram_request(long_polling=True))
        .build()
    )
    application.add_handler(CommandHandler("ping", ping))
    application.add_handler(CommandHandler("status", status))
    application.add_handler(CommandHandler("start", status))
    application.add_handler(CommandHandler("bots", bots))
    application.add_handler(CommandHandler("queue", queue))
    application.add_handler(CommandHandler("digest", digest))
    start_heartbeat("dev")
    threading.Thread(target=watch_scheduler, name="hoc-scheduler-watch", daemon=True).start()
    run_application(application, port=8447, url_path="dev")


if __name__ == "__main__":
    main()
