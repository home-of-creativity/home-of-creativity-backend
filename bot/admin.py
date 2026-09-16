import os
from html import escape
from pathlib import Path
from typing import Any

import httpx
from dotenv import load_dotenv
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, KeyboardButton, ReplyKeyboardMarkup, Update
from telegram.error import NetworkError, TimedOut
from telegram.ext import (
    Application,
    CallbackQueryHandler,
    CommandHandler,
    ContextTypes,
    ConversationHandler,
    MessageHandler,
    filters,
)

from telegram_http import run_application, telegram_request

load_dotenv(Path(__file__).resolve().parents[1] / ".env")
load_dotenv()

WAITING_EMAIL = 1
WAITING_NAME = 2
WAITING_DEPTS = 3
WAITING_ASSIGN_DEPT = 4
WAITING_ASSIGN_TASK = 5
WAITING_ASSIGN_MEMBER = 6
WAITING_TASKS_DEPT = 7

BTN_GUEST = "👤 إضافة ضيف"
BTN_DEPTS = "🏢 الأقسام"
BTN_TASKS = "📋 المهام"
BTN_ASSIGN = "🎯 توزيع مهمة"

_local_api = (os.environ.get("HOC_LOCAL_API_URL") or "http://127.0.0.1:8001").rstrip("/")
_origin = (os.environ.get("HOC_API_URL") or _local_api).rstrip("/")
if "trycloudflare.com" in _origin:
    _origin = _local_api
API_URL = _origin if _origin.endswith("/api") else f"{_origin}/api"
BOT_SECRET = os.environ.get("TELEGRAM_ADMIN_BOT_SECRET", "change-me-admin")
API_TIMEOUT = httpx.Timeout(30.0)


def api_headers() -> dict[str, str]:
    return {
        "Accept": "application/json",
        "X-Webhook-Secret": BOT_SECRET,
    }


def admin_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        [
            [KeyboardButton(BTN_GUEST), KeyboardButton(BTN_DEPTS)],
            [KeyboardButton(BTN_TASKS), KeyboardButton(BTN_ASSIGN)],
        ],
        resize_keyboard=True,
    )


async def api_error_alert(query, response: httpx.Response) -> None:
    try:
        detail = response.json().get("message", response.text)
    except Exception:
        detail = response.text or f"HTTP {response.status_code}"
    await query.answer(str(detail)[:200], show_alert=True)


async def request_json(method: str, path: str, *, params: dict | None = None, json: dict | None = None) -> httpx.Response:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        return await client.request(
            method,
            f"{API_URL}{path}",
            headers=api_headers(),
            params=params,
            json=json,
        )


async def ensure_admin(update: Update) -> bool:
    user = update.effective_user
    message = update.message or (update.callback_query.message if update.callback_query else None)
    if user is None:
        return False
    response = await request_json("GET", "/bot/admin/me", params={"telegram_user_id": str(user.id)})
    if response.status_code < 400:
        return True
    if message:
        await message.reply_text("هذا البوت للأدمن المعتمدين فقط. أضف معرفك إلى TELEGRAM_ADMIN_IDS.")
    return False


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    if not await ensure_admin(update):
        return
    await update.message.reply_text(
        "بوت إدارة ClickUp.\nإضافة ضيف تحتاج إيميل أولاً.",
        reply_markup=admin_keyboard(),
    )


async def list_departments(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    if not await ensure_admin(update):
        return
    response = await request_json("GET", "/bot/admin/departments", params={"telegram_user_id": str(user.id)})
    if response.status_code >= 400:
        await update.message.reply_text(f"تعذر جلب الأقسام: {escape(str(response.text))}", reply_markup=admin_keyboard())
        return
    items = response.json().get("data") or []
    if not items:
        await update.message.reply_text("لا توجد أقسام مربوطة بقوائم ClickUp.", reply_markup=admin_keyboard())
        return
    lines = [f"• {item.get('name')} (`{item.get('id')}`)" for item in items]
    await update.message.reply_text("\n".join(lines), reply_markup=admin_keyboard())


def department_keyboard(items: list[dict[str, Any]], prefix: str) -> InlineKeyboardMarkup:
    rows = []
    for item in items:
        rows.append([InlineKeyboardButton(str(item.get("name") or item.get("id")), callback_data=f"{prefix}:{item.get('id')}")])
    return InlineKeyboardMarkup(rows)


def guest_dept_keyboard(departments: list[dict[str, Any]], selected: list[str]) -> InlineKeyboardMarkup:
    rows = []
    for item in departments:
        dept_id = str(item.get("id"))
        mark = "✅ " if dept_id in selected else ""
        rows.append([InlineKeyboardButton(f"{mark}{item.get('name')}", callback_data=f"gdep:{dept_id}")])
    rows.append([InlineKeyboardButton("تم", callback_data="gdep:done")])
    return InlineKeyboardMarkup(rows)


async def guest_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    context.user_data.pop("guest_email", None)
    context.user_data.pop("guest_name", None)
    context.user_data["guest_departments"] = []
    await update.message.reply_text("أدخل إيميل الضيف (إلزامي لدعوة ClickUp):")
    return WAITING_EMAIL


async def capture_guest_email(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or not update.message.text:
        return WAITING_EMAIL
    email = update.message.text.strip()
    if "@" not in email or "." not in email.split("@")[-1]:
        await update.message.reply_text("أدخل إيميلاً صحيحاً قبل المتابعة.")
        return WAITING_EMAIL
    context.user_data["guest_email"] = email
    await update.message.reply_text("ما اسم الضيف؟")
    return WAITING_NAME


async def capture_guest_name(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return ConversationHandler.END
    context.user_data["guest_name"] = update.message.text.strip()
    response = await request_json("GET", "/bot/admin/departments", params={"telegram_user_id": str(user.id)})
    departments = response.json().get("data") or [] if response.status_code < 400 else []
    context.user_data["all_departments"] = departments
    if not departments:
        return await submit_guest(update, context)
    await update.message.reply_text(
        "اختر قسماً أو أكثر ثم اضغط تم:",
        reply_markup=guest_dept_keyboard(departments, []),
    )
    return WAITING_DEPTS


async def on_guest_department(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None:
        return ConversationHandler.END
    await query.answer()
    dept_id = query.data.split(":", 1)[1]
    if dept_id == "done":
        return await submit_guest(update, context)
    selected: list[str] = list(context.user_data.get("guest_departments") or [])
    if dept_id in selected:
        selected.remove(dept_id)
    else:
        selected.append(dept_id)
    context.user_data["guest_departments"] = selected
    departments = context.user_data.get("all_departments") or []
    try:
        await query.edit_message_reply_markup(reply_markup=guest_dept_keyboard(departments, selected))
    except Exception:
        pass
    return WAITING_DEPTS


async def submit_guest(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    target = update.message or (update.callback_query.message if update.callback_query else None)
    if user is None or target is None:
        return ConversationHandler.END

    payload = {
        "telegram_user_id": str(user.id),
        "email": context.user_data.get("guest_email"),
        "name": context.user_data.get("guest_name"),
        "departments": context.user_data.get("guest_departments") or [],
    }
    response = await request_json("POST", "/bot/admin/guests", json=payload)
    if response.status_code >= 400:
        detail = response.json().get("message", response.text) if response.headers.get("content-type", "").startswith("application/json") else response.text
        await target.reply_text(f"تعذر الدعوة: {escape(str(detail))}", reply_markup=admin_keyboard())
        return ConversationHandler.END

    await target.reply_text("تم إرسال دعوة الضيف إلى ClickUp.", reply_markup=admin_keyboard())
    return ConversationHandler.END


async def tasks_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    response = await request_json("GET", "/bot/admin/departments", params={"telegram_user_id": str(user.id)})
    items = response.json().get("data") or [] if response.status_code < 400 else []
    if not items:
        await update.message.reply_text("لا توجد أقسام.", reply_markup=admin_keyboard())
        return ConversationHandler.END
    await update.message.reply_text("اختر القسم لعرض مهامه:", reply_markup=department_keyboard(items, "tdep"))
    return WAITING_TASKS_DEPT


async def on_tasks_department(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    department = query.data.split(":", 1)[1]
    response = await request_json(
        "GET",
        "/bot/admin/tasks",
        params={"telegram_user_id": str(user.id), "department": department},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return ConversationHandler.END
    tasks = response.json().get("data") or []
    if not tasks:
        await query.message.reply_text("لا توجد مهام في هذا القسم.", reply_markup=admin_keyboard())
        return ConversationHandler.END
    lines = []
    for task in tasks[:20]:
        assignees = ", ".join(item.get("name") or item.get("id") or "" for item in (task.get("assignees") or [])) or "—"
        due = task.get("due_date") or "—"
        lines.append(f"• {task.get('name')}\n  المسند: {assignees}\n  التسليم: {due}")
        if task.get("url"):
            lines[-1] += f"\n  {task['url']}"
    await query.message.reply_text("\n\n".join(lines), reply_markup=admin_keyboard())
    return ConversationHandler.END


async def assign_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    response = await request_json("GET", "/bot/admin/departments", params={"telegram_user_id": str(user.id)})
    items = response.json().get("data") or [] if response.status_code < 400 else []
    if not items:
        await update.message.reply_text("لا توجد أقسام.", reply_markup=admin_keyboard())
        return ConversationHandler.END
    await update.message.reply_text("اختر القسم:", reply_markup=department_keyboard(items, "adep"))
    return WAITING_ASSIGN_DEPT


async def on_assign_department(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    department = query.data.split(":", 1)[1]
    context.user_data["assign_department"] = department
    response = await request_json(
        "GET",
        "/bot/admin/tasks",
        params={"telegram_user_id": str(user.id), "department": department},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return ConversationHandler.END
    tasks = response.json().get("data") or []
    if not tasks:
        await query.message.reply_text("لا توجد مهام في هذا القسم.", reply_markup=admin_keyboard())
        return ConversationHandler.END
    rows = []
    for task in tasks[:12]:
        task_id = str(task.get("id") or "")
        name = str(task.get("name") or task_id)[:40]
        rows.append([InlineKeyboardButton(name, callback_data=f"atsk:{task_id}")])
    await query.message.reply_text("اختر المهمة:", reply_markup=InlineKeyboardMarkup(rows))
    return WAITING_ASSIGN_TASK


async def on_assign_task(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    task_id = query.data.split(":", 1)[1]
    context.user_data["assign_task"] = task_id
    department = context.user_data.get("assign_department")
    response = await request_json(
        "GET",
        "/bot/admin/members",
        params={"telegram_user_id": str(user.id), "department": department},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return ConversationHandler.END
    members = response.json().get("data") or []
    if not members:
        await query.message.reply_text("لا يوجد أعضاء في هذا القسم.", reply_markup=admin_keyboard())
        return ConversationHandler.END
    rows = []
    for member in members[:12]:
        member_id = str(member.get("id") or "")
        name = str(member.get("name") or member.get("email") or member_id)[:40]
        rows.append([InlineKeyboardButton(name, callback_data=f"amem:{member_id}")])
    await query.message.reply_text("اختر المسند من أعضاء القسم فقط:", reply_markup=InlineKeyboardMarkup(rows))
    return WAITING_ASSIGN_MEMBER


async def on_assign_member(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    assignee_id = query.data.split(":", 1)[1]
    response = await request_json(
        "POST",
        "/bot/admin/assign",
        json={
            "telegram_user_id": str(user.id),
            "department": context.user_data.get("assign_department"),
            "task_id": context.user_data.get("assign_task"),
            "assignee_id": assignee_id,
        },
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return ConversationHandler.END
    await query.message.reply_text("تم توزيع المهمة على عضو القسم.", reply_markup=admin_keyboard())
    return ConversationHandler.END


async def cancel(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message:
        await update.message.reply_text("تم الإلغاء.", reply_markup=admin_keyboard())
    return ConversationHandler.END


def main() -> None:
    token = os.environ.get("TELEGRAM_ADMIN_BOT_TOKEN", "")
    if not token:
        raise RuntimeError("TELEGRAM_ADMIN_BOT_TOKEN is missing.")

    application = (
        Application.builder()
        .token(token)
        .request(telegram_request())
        .get_updates_request(telegram_request(long_polling=True))
        .build()
    )
    application.add_handler(CommandHandler("start", start))
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_DEPTS}$"), list_departments))
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("guest", guest_start),
                MessageHandler(filters.Regex(f"^{BTN_GUEST}$"), guest_start),
            ],
            states={
                WAITING_EMAIL: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_guest_email)],
                WAITING_NAME: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_guest_name)],
                WAITING_DEPTS: [CallbackQueryHandler(on_guest_department, pattern=r"^gdep:")],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("tasks", tasks_start),
                MessageHandler(filters.Regex(f"^{BTN_TASKS}$"), tasks_start),
            ],
            states={
                WAITING_TASKS_DEPT: [CallbackQueryHandler(on_tasks_department, pattern=r"^tdep:")],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("assign", assign_start),
                MessageHandler(filters.Regex(f"^{BTN_ASSIGN}$"), assign_start),
            ],
            states={
                WAITING_ASSIGN_DEPT: [CallbackQueryHandler(on_assign_department, pattern=r"^adep:")],
                WAITING_ASSIGN_TASK: [CallbackQueryHandler(on_assign_task, pattern=r"^atsk:")],
                WAITING_ASSIGN_MEMBER: [CallbackQueryHandler(on_assign_member, pattern=r"^amem:")],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )

    try:
        run_application(
            application,
            port=8446,
            url_path="admin-bot",
            secret_token=BOT_SECRET,
        )
    except TimedOut as exc:
        raise SystemExit("انتهت مهلة الاتصال بـ api.telegram.org.") from exc
    except NetworkError as exc:
        raise SystemExit(f"تعذر الوصول إلى api.telegram.org ({exc}).") from exc


if __name__ == "__main__":
    main()
