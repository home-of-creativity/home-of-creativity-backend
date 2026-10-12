import { useCallback } from "react";
import { canAbility } from "../api";
import { useAuth } from "../auth";

/** `can("ops.finance.expense_create")`: whether the signed-in staff member may run that operation. */
export function useCan() {
  const { user } = useAuth();
  return useCallback((ability: string) => canAbility(user, ability), [user]);
}
