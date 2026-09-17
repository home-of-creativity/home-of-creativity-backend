import * as TabsPrimitive from "@radix-ui/react-tabs";
import type { ReactNode } from "react";

export function Tabs({
  value,
  onValueChange,
  items,
  ariaLabel,
}: {
  value: string;
  onValueChange: (value: string) => void;
  items: { value: string; label: ReactNode }[];
  ariaLabel: string;
}) {
  return (
    <TabsPrimitive.Root value={value} onValueChange={onValueChange}>
      <TabsPrimitive.List className="tabs" aria-label={ariaLabel}>
        {items.map((item) => (
          <TabsPrimitive.Trigger key={item.value} value={item.value} className="tab">
            {item.label}
          </TabsPrimitive.Trigger>
        ))}
      </TabsPrimitive.List>
    </TabsPrimitive.Root>
  );
}
