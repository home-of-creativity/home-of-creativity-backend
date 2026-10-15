import { describe, expect, it } from "vitest";
import { paperStatusIndex } from "./paperStatusIndex";

describe("paperStatusIndex", () => {
  it("marks a posted invoice as the posted step, not draft", () => {
    expect(paperStatusIndex("invoice", "posted", "not_paid")).toBe(1);
  });

  it("marks a paid invoice as the paid step", () => {
    expect(paperStatusIndex("invoice", "posted", "paid")).toBe(2);
    expect(paperStatusIndex("invoice", "posted", "in_payment")).toBe(2);
  });

  it("marks a draft invoice as draft", () => {
    expect(paperStatusIndex("invoice", "draft", "not_paid")).toBe(0);
    expect(paperStatusIndex("invoice", undefined, undefined)).toBe(0);
  });

  it("marks a sent quotation past the first step", () => {
    expect(paperStatusIndex("quotation", "draft")).toBe(0);
    expect(paperStatusIndex("quotation", "sent")).toBe(1);
    expect(paperStatusIndex("quotation", "sale")).toBe(1);
  });
});
