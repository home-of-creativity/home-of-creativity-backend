import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, type FieldValues, type UseFormProps } from "react-hook-form";
import type { z } from "zod";

export function useZodForm<TSchema extends z.ZodObject<Record<string, z.ZodTypeAny>>>(
  schema: TSchema,
  options?: Omit<UseFormProps<z.infer<TSchema> & FieldValues>, "resolver">,
) {
  return useForm<z.infer<TSchema> & FieldValues>({
    resolver: zodResolver(schema) as never,
    mode: "onBlur",
    ...options,
  });
}
