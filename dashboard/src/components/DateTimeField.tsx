import * as Popover from "@radix-ui/react-popover";
import { CalendarDays } from "lucide-react";
import { useState } from "react";
import { DayPicker } from "react-day-picker";
import "react-day-picker/style.css";

function splitValue(value: string): { date: string; time: string } {
  const [date = "", time = "00:00"] = value.split("T");
  return { date, time };
}

function toDate(dateStr: string): Date | undefined {
  if (!dateStr) return undefined;
  const [y, m, d] = dateStr.split("-").map(Number);
  if (!y || !m || !d) return undefined;
  return new Date(y, m - 1, d);
}

function fromDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

export function DateTimeField({
  value,
  onChange,
  disabled,
  placeholder,
  locale = "en",
}: {
  value: string;
  onChange: (next: string) => void;
  disabled?: boolean;
  placeholder?: string;
  locale?: "ar" | "en";
}) {
  const [open, setOpen] = useState(false);
  const { date, time } = splitValue(value);
  const selectedDate = toDate(date);

  const displayLabel = value
    ? new Intl.DateTimeFormat(locale === "ar" ? "ar" : "en", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(new Date(value))
    : placeholder ?? "";

  return (
    <Popover.Root open={open} onOpenChange={(next) => !disabled && setOpen(next)}>
      <Popover.Trigger asChild>
        <button type="button" className="field datetime-trigger" disabled={disabled}>
          <CalendarDays size={15} aria-hidden="true" />
          <span className={value ? "" : "muted"}>{displayLabel || placeholder}</span>
        </button>
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content className="datetime-popover" sideOffset={8} align="start">
          <DayPicker
            mode="single"
            selected={selectedDate}
            defaultMonth={selectedDate}
            onSelect={(next) => {
              if (!next) return;
              onChange(`${fromDate(next)}T${time}`);
            }}
          />
          <div className="datetime-time-row">
            <input
              type="time"
              className="field"
              value={time}
              onChange={(event) => {
                const nextDate = date || fromDate(new Date());
                onChange(`${nextDate}T${event.target.value}`);
              }}
            />
            <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)}>
              {locale === "ar" ? "تم" : "Done"}
            </button>
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
