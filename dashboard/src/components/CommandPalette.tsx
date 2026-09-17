import { Command } from "cmdk";
import { useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { copy, type Locale } from "../i18n";

export type CommandItem = {
  id: string;
  label: string;
  hint?: string;
  to: string;
  icon?: React.ReactNode;
  group: string;
};

export function CommandPalette({
  items,
  locale,
  t,
  open,
  setOpen,
}: {
  items: CommandItem[];
  locale: Locale;
  t: (c: { ar: string; en: string }) => string;
  open: boolean;
  setOpen: (next: boolean) => void;
}) {
  const navigate = useNavigate();

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setOpen(!open);
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, setOpen]);

  const groups = Array.from(new Set(items.map((item) => item.group)));

  return (
    <Command.Dialog
      open={open}
      onOpenChange={setOpen}
      label={t(copy.commandPaletteLabel)}
      className="command-palette"
      contentClassName="command-palette-content"
      dir={locale === "ar" ? "rtl" : "ltr"}
    >
      <div className="command-palette-input-row">
        <Command.Input placeholder={t(copy.commandPalettePlaceholder)} autoFocus />
      </div>
      <Command.List>
        <Command.Empty>{t(copy.noSearchResults)}</Command.Empty>
        {groups.map((group) => (
          <Command.Group key={group} heading={group}>
            {items
              .filter((item) => item.group === group)
              .map((item) => (
                <Command.Item
                  key={item.id}
                  value={`${item.label} ${item.hint ?? ""}`}
                  onSelect={() => {
                    navigate(item.to);
                    setOpen(false);
                  }}
                >
                  {item.icon}
                  <span>{item.label}</span>
                </Command.Item>
              ))}
          </Command.Group>
        ))}
      </Command.List>
    </Command.Dialog>
  );
}
