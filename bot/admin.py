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

from telegram_http import run_application, start_heartbeat, telegram_request

load_dotenv(Path(__file__).resolve().parents[1] / ".env")
load_dotenv()

WAITING_EMAIL = 1
WAITING_NAME = 2
WAITING_DEPTS = 3
WAITING_ASSIGN_DEPT = 4
WAITING_ASSIGN_TASK = 5
WAITING_ASSIGN_MEMBER = 6
WAITING_TASKS_DEPT = 7
WAITING_DUE_HOURS = 8
WAITING_EXPENSE_AMOUNT = 9
WAITING_EXPENSE_NOTE = 10

BTN_GUEST = "👤 إضافة ضيف"
BTN_DEPTS = "🏢 الأقسام"
BTN_TASKS = "📋 المهام"
BTN_ASSIGN = "🎯 إسناد ClickUp"
BTN_CLIENTS = "👥 العملاء"
BTN_OPS = "🧩 العمليات"
BTN_FINANCE = "💰 المالية"
BTN_OVERVIEW = "📊 الملخص"
STATUS_CODES = {"todo": "to do", "prog": "in progress", "done": "complete"}
PRIORITY_AR = {"1": "عاجلة", "2": "عالية", "3": "عادية", "4": "منخفضة"}

_local_api = (os.environ.get("HOC_LOCAL_API_URL") or "http://127.0.0.1:8000").rstrip("/")
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
            [KeyboardButton(BTN_TASKS), KeyboardButton(BTN_ASSIGN)],
            [KeyboardButton(BTN_CLIENTS), KeyboardButton(BTN_OPS)],
            [KeyboardButton(BTN_FINANCE), KeyboardButton(BTN_OVERVIEW)],
            [KeyboardButton(BTN_GUEST), KeyboardButton(BTN_DEPTS)],
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
        "غرفة عمليات الأدمن.\nالمهام والإسناد والعملاء والعمليات والمالية.",
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
    context.user_data["task_department"] = department
    lines = []
    rows: list[list[InlineKeyboardButton]] = []
    for task in tasks[:12]:
        assignees = ", ".join(item.get("name") or item.get("id") or "" for item in (task.get("assignees") or [])) or "—"
        due = task.get("due_date") or "—"
        status = task.get("status") or "—"
        lines.append(f"• {task.get('name')}\n  الحالة: {status}\n  المسند: {assignees}\n  التسليم: {due}")
        task_id = str(task.get("id") or "")
        if task_id and len(task_id) <= 48:
            rows.append([InlineKeyboardButton(str(task.get("name") or task_id)[:40], callback_data=f"topen:{task_id}")])
    await query.message.reply_text("\n\n".join(lines), reply_markup=InlineKeyboardMarkup(rows) if rows else admin_keyboard())
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


def task_action_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        [
            [
                InlineKeyboardButton("to do", callback_data="tstat:todo"),
                InlineKeyboardButton("تنفيذ", callback_data="tstat:prog"),
                InlineKeyboardButton("إكمال", callback_data="tstat:done"),
            ],
            [
                InlineKeyboardButton("عاجلة", callback_data="tpri:1"),
                InlineKeyboardButton("عالية", callback_data="tpri:2"),
                InlineKeyboardButton("عادية", callback_data="tpri:3"),
            ],
            [
                InlineKeyboardButton("موعد بالساعات", callback_data="tdue:1"),
                InlineKeyboardButton("إسناد عضو", callback_data="tass:1"),
            ],
        ]
    )


async def on_open_task(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return
    if not await ensure_admin(update):
        return
    task_id = query.data.split(":", 1)[1]
    context.user_data["open_task"] = task_id
    await query.answer()
    await query.message.reply_text(
        f"تحكم بالمهمة `{escape(task_id)}`:\nحالة / أولوية / موعد / إسناد",
        reply_markup=task_action_keyboard(),
    )


async def on_task_status(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return
    if not await ensure_admin(update):
        return
    code = query.data.split(":", 1)[1]
    status = STATUS_CODES.get(code)
    task_id = context.user_data.get("open_task")
    if not status or not task_id:
        await query.answer("اختر مهمة أولاً.", show_alert=True)
        return
    response = await request_json(
        "POST",
        "/bot/admin/task",
        json={"telegram_user_id": str(user.id), "task_id": task_id, "status": status},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return
    await query.answer("تم تحديث الحالة.")
    await query.message.reply_text(f"صارت الحالة: {status}", reply_markup=admin_keyboard())


async def on_task_priority(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return
    if not await ensure_admin(update):
        return
    priority = query.data.split(":", 1)[1]
    task_id = context.user_data.get("open_task")
    if not task_id:
        await query.answer("اختر مهمة أولاً.", show_alert=True)
        return
    response = await request_json(
        "POST",
        "/bot/admin/task",
        json={"telegram_user_id": str(user.id), "task_id": task_id, "priority": int(priority)},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return
    await query.answer("تم تحديث الأولوية.")
    await query.message.reply_text(
        f"الأولوية: {PRIORITY_AR.get(priority, priority)}",
        reply_markup=admin_keyboard(),
    )


async def due_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    if not context.user_data.get("open_task"):
        await query.answer("اختر مهمة أولاً.", show_alert=True)
        return ConversationHandler.END
    await query.answer()
    await query.message.reply_text("كم ساعة حتى التسليم؟")
    return WAITING_DUE_HOURS


async def capture_due_hours(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return WAITING_DUE_HOURS
    raw = update.message.text.strip()
    if not raw.isdigit() or int(raw) < 1:
        await update.message.reply_text("أدخل عدد ساعات صحيحاً.")
        return WAITING_DUE_HOURS
    task_id = context.user_data.get("open_task")
    response = await request_json(
        "POST",
        "/bot/admin/task",
        json={"telegram_user_id": str(user.id), "task_id": task_id, "due_hours": int(raw)},
    )
    if response.status_code >= 400:
        detail = response.json().get("message", response.text)
        await update.message.reply_text(f"تعذر تحديث الموعد: {escape(str(detail))}", reply_markup=admin_keyboard())
        return ConversationHandler.END
    await update.message.reply_text(f"تم ضبط التسليم بعد {raw} ساعة.", reply_markup=admin_keyboard())
    return ConversationHandler.END


async def task_assign_from_card(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    user = update.effective_user
    if query is None or user is None or query.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    task_id = context.user_data.get("open_task")
    department = context.user_data.get("task_department") or context.user_data.get("assign_department")
    if not task_id or not department:
        await query.answer("افتح مهمة من قائمة القسم أولاً.", show_alert=True)
        return ConversationHandler.END
    context.user_data["assign_task"] = task_id
    context.user_data["assign_department"] = department
    await query.answer()
    response = await request_json(
        "GET",
        "/bot/admin/members",
        params={"telegram_user_id": str(user.id), "department": department},
    )
    members = response.json().get("data") or [] if response.status_code < 400 else []
    if not members:
        await query.message.reply_text("لا يوجد أعضاء في هذا القسم.", reply_markup=admin_keyboard())
        return ConversationHandler.END
    rows = []
    for member in members[:12]:
        member_id = str(member.get("id") or "")
        name = str(member.get("name") or member.get("email") or member_id)[:40]
        rows.append([InlineKeyboardButton(name, callback_data=f"amem:{member_id}")])
    await query.message.reply_text("اختر المسند من أعضاء القسم:", reply_markup=InlineKeyboardMarkup(rows))
    return WAITING_ASSIGN_MEMBER


async def show_overview(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    if not await ensure_admin(update):
        return
    response = await request_json("GET", "/bot/admin/overview", params={"telegram_user_id": str(user.id)})
    if response.status_code >= 400:
        await update.message.reply_text(f"تعذر جلب الملخص: {escape(str(response.text))}", reply_markup=admin_keyboard())
        return
    data = response.json().get("data") or {}
    text = "\n".join(
        [
            "ملخص العمليات",
            f"عملاء: {data.get('clients', 0)}",
            f"طلبات مفتوحة: {data.get('open_requests', 0)}",
            f"بانتظار الدفع: {data.get('awaiting_payment', 0)}",
            f"قيد التنفيذ: {data.get('in_progress', 0)}",
            f"بانتظار المراجعة: {data.get('ready_for_review', 0)}",
            f"إيراد مستلم: {data.get('revenue_paid', 0)} USD",
            f"إيراد مفتوح: {data.get('revenue_open', 0)} USD",
            f"مصاريف: {data.get('expenses', 0)} USD",
            f"الصافي: {data.get('net', 0)} USD",
        ]
    )
    await update.message.reply_text(text, reply_markup=admin_keyboard())


async def list_clients(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    if not await ensure_admin(update):
        return
    response = await request_json("GET", "/bot/admin/clients", params={"telegram_user_id": str(user.id)})
    if response.status_code >= 400:
        await update.message.reply_text(f"تعذر جلب العملاء: {escape(str(response.text))}", reply_markup=admin_keyboard())
        return
    items = response.json().get("data") or []
    if not items:
        await update.message.reply_text("لا يوجد عملاء ظاهرون.", reply_markup=admin_keyboard())
        return
    rows = []
    lines = []
    for item in items[:15]:
        lines.append(
            f"• {item.get('name') or '—'} — {item.get('company_name') or '—'}\n"
            f"  {item.get('latest_status_label') or 'بدون طلب'} · مفتوح {item.get('open_count', 0)}"
        )
        rows.append(
            [InlineKeyboardButton(str(item.get("name") or item.get("id"))[:32], callback_data=f"copen:{item.get('id')}")]
        )
    await update.message.reply_text("\n\n".join(lines), reply_markup=InlineKeyboardMarkup(rows))


async def on_open_client(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return
    if not await ensure_admin(update):
        return
    client_id = query.data.split(":", 1)[1]
    response = await request_json(
        "GET",
        f"/bot/admin/clients/{client_id}",
        params={"telegram_user_id": str(user.id)},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return
    item = response.json().get("data") or {}
    lines = [
        f"{item.get('name') or '—'} — {item.get('company_name') or '—'}",
        item.get("phone") or "",
        item.get("telegram_url") or "",
        "",
        "الطلبات:",
    ]
    for req in item.get("requests") or []:
        lines.append(
            f"• #{req.get('display_number')}: {req.get('title')}\n"
            f"  {req.get('status_label')} · مدفوع {req.get('amount_paid', 0)} / متبقّي {req.get('amount_remaining', 0)}"
        )
    await query.answer()
    await query.message.reply_text("\n".join(line for line in lines if line is not None), reply_markup=admin_keyboard())


async def list_operations(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    if not await ensure_admin(update):
        return
    response = await request_json("GET", "/bot/admin/operations", params={"telegram_user_id": str(user.id)})
    if response.status_code >= 400:
        await update.message.reply_text(f"تعذر جلب العمليات: {escape(str(response.text))}", reply_markup=admin_keyboard())
        return
    items = response.json().get("data") or []
    if not items:
        await update.message.reply_text("لا توجد عمليات.", reply_markup=admin_keyboard())
        return
    rows = []
    lines = []
    for item in items[:12]:
        ops = item.get("operations") or []
        summary = "، ".join(
            f"{op.get('department') or 'قسم'}→{op.get('employee_name') or '—'}" for op in ops[:4]
        ) or "بدون خطة"
        lines.append(f"• #{item.get('display_number')} {item.get('title')}\n  {item.get('status_label')} · {summary}")
        rows.append(
            [
                InlineKeyboardButton(f"#{item.get('display_number')}", callback_data=f"oopen:{item.get('display_number')}"),
                InlineKeyboardButton("إعادة تخطيط", callback_data=f"oplan:{item.get('display_number')}"),
            ]
        )
    await update.message.reply_text("\n\n".join(lines), reply_markup=InlineKeyboardMarkup(rows))


async def on_open_operation(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return
    if not await ensure_admin(update):
        return
    ref = query.data.split(":", 1)[1]
    await query.answer()
    user = update.effective_user
    if user is None:
        return
    response = await request_json("GET", "/bot/admin/operations", params={"telegram_user_id": str(user.id)})
    items = response.json().get("data") or [] if response.status_code < 400 else []
    item = next((row for row in items if str(row.get("display_number")) == ref or str(row.get("number")) == ref), None)
    if item is None:
        await query.message.reply_text("الطلب غير موجود في القائمة الحالية.", reply_markup=admin_keyboard())
        return
    await query.message.reply_text(format_operation(item), reply_markup=admin_keyboard())


async def on_rebuild_plan(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.data is None or user is None or query.message is None:
        return
    if not await ensure_admin(update):
        return
    ref = query.data.split(":", 1)[1]
    response = await request_json(
        "POST",
        "/bot/admin/operations/rebuild",
        json={"telegram_user_id": str(user.id), "request_number": ref},
    )
    if response.status_code >= 400:
        await api_error_alert(query, response)
        return
    await query.answer("تم إعادة التخطيط.")
    await query.message.reply_text(format_operation(response.json().get("data") or {}), reply_markup=admin_keyboard())


def format_operation(item: dict[str, Any]) -> str:
    lines = [
        f"#{item.get('display_number')} — {item.get('title')}",
        f"{item.get('client_name') or '—'} · {item.get('status_label') or item.get('status')}",
        f"المصدر: {item.get('source') or '—'}",
        "",
        "العمليات:",
    ]
    for operation in item.get("operations") or []:
        lines.append(
            f"• {operation.get('department')} — {operation.get('employee_name') or 'غير مسند'} — "
            f"{operation.get('priority_label') or ''} — {operation.get('hours') or 0} ساعة"
        )
        if operation.get("brief"):
            lines.append(f"  {operation['brief'][:180]}")
    if not item.get("operations"):
        lines.append("لا توجد خطة بعد. اضغط إعادة تخطيط.")
    return "\n".join(lines)


async def show_finance(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    response = await request_json("GET", "/bot/admin/finance", params={"telegram_user_id": str(user.id)})
    if response.status_code >= 400:
        await update.message.reply_text(f"تعذر جلب المالية: {escape(str(response.text))}", reply_markup=admin_keyboard())
        return ConversationHandler.END
    data = response.json().get("data") or {}
    context.user_data["expense_categories"] = data.get("categories") or []
    lines = [
        "المالية (USD)",
        f"إيراد مستلم: {data.get('revenue_paid', 0)}",
        f"إيراد مفتوح: {data.get('revenue_open', 0)}",
        f"مصاريف: {data.get('expenses', 0)}",
        f"الصافي: {data.get('net', 0)}",
        "",
        "آخر إيرادات:",
    ]
    for invoice in data.get("invoices") or []:
        lines.append(f"• +{invoice.get('amount')} — {invoice.get('title') or invoice.get('request_number')}")
    lines.append("")
    lines.append("آخر مصاريف:")
    for expense in data.get("expense_rows") or []:
        lines.append(f"• -{expense.get('amount')} — {expense.get('category')} {expense.get('note') or ''}")
    rows = [[InlineKeyboardButton("إضافة مصروف", callback_data="exp:new")]]
    await update.message.reply_text("\n".join(lines), reply_markup=InlineKeyboardMarkup(rows))
    return ConversationHandler.END


async def expense_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.message is None:
        return ConversationHandler.END
    if not await ensure_admin(update):
        return ConversationHandler.END
    await query.answer()
    await query.message.reply_text("مبلغ المصروف بالدولار:")
    return WAITING_EXPENSE_AMOUNT


async def capture_expense_amount(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or not update.message.text:
        return WAITING_EXPENSE_AMOUNT
    raw = update.message.text.strip().replace(",", "")
    try:
        amount = float(raw)
        if amount <= 0:
            raise ValueError
    except ValueError:
        await update.message.reply_text("أدخل مبلغاً أكبر من صفر.")
        return WAITING_EXPENSE_AMOUNT
    context.user_data["expense_amount"] = amount
    categories = context.user_data.get("expense_categories") or ["رواتب", "إعلانات", "برامج", "مكتب", "تنقل", "أخرى"]
    rows = [[InlineKeyboardButton(name, callback_data=f"ecat:{index}")] for index, name in enumerate(categories)]
    await update.message.reply_text("اختر بند المصروف:", reply_markup=InlineKeyboardMarkup(rows))
    return WAITING_EXPENSE_NOTE


async def on_expense_category(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    index = int(query.data.split(":", 1)[1])
    categories = context.user_data.get("expense_categories") or ["رواتب", "إعلانات", "برامج", "مكتب", "تنقل", "أخرى"]
    context.user_data["expense_category"] = categories[index] if 0 <= index < len(categories) else "أخرى"
    await query.message.reply_text("ملاحظة المصروف (أو اكتب -):")
    return WAITING_EXPENSE_NOTE


async def capture_expense_note(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return WAITING_EXPENSE_NOTE
    if context.user_data.get("expense_category") is None:
        return WAITING_EXPENSE_NOTE
    note = update.message.text.strip()
    if note == "-":
        note = ""
    response = await request_json(
        "POST",
        "/bot/admin/expenses",
        json={
            "telegram_user_id": str(user.id),
            "amount": context.user_data.get("expense_amount"),
            "category": context.user_data.get("expense_category"),
            "note": note or None,
        },
    )
    context.user_data.pop("expense_amount", None)
    context.user_data.pop("expense_category", None)
    if response.status_code >= 400:
        detail = response.json().get("message", response.text)
        await update.message.reply_text(f"تعذر حفظ المصروف: {escape(str(detail))}", reply_markup=admin_keyboard())
        return ConversationHandler.END
    finance = (response.json().get("data") or {}).get("finance") or {}
    await update.message.reply_text(
        f"تم تسجيل المصروف. الصافي الآن {finance.get('net', 0)} USD.",
        reply_markup=admin_keyboard(),
    )
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
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_OVERVIEW}$"), show_overview))
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_CLIENTS}$"), list_clients))
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_OPS}$"), list_operations))
    application.add_handler(CallbackQueryHandler(on_open_task, pattern=r"^topen:"))
    application.add_handler(CallbackQueryHandler(on_task_status, pattern=r"^tstat:"))
    application.add_handler(CallbackQueryHandler(on_task_priority, pattern=r"^tpri:"))
    application.add_handler(CallbackQueryHandler(on_open_client, pattern=r"^copen:"))
    application.add_handler(CallbackQueryHandler(on_open_operation, pattern=r"^oopen:"))
    application.add_handler(CallbackQueryHandler(on_rebuild_plan, pattern=r"^oplan:"))
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
    application.add_handler(
        ConversationHandler(
            entry_points=[CallbackQueryHandler(due_start, pattern=r"^tdue:")],
            states={
                WAITING_DUE_HOURS: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_due_hours)],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[CallbackQueryHandler(task_assign_from_card, pattern=r"^tass:")],
            states={
                WAITING_ASSIGN_MEMBER: [CallbackQueryHandler(on_assign_member, pattern=r"^amem:")],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("finance", show_finance),
                MessageHandler(filters.Regex(f"^{BTN_FINANCE}$"), show_finance),
                CallbackQueryHandler(expense_start, pattern=r"^exp:"),
            ],
            states={
                WAITING_EXPENSE_AMOUNT: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_expense_amount)],
                WAITING_EXPENSE_NOTE: [
                    CallbackQueryHandler(on_expense_category, pattern=r"^ecat:"),
                    MessageHandler(filters.TEXT & ~filters.COMMAND, capture_expense_note),
                ],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )

    start_heartbeat("admin")
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
