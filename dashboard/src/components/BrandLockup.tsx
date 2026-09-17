type Props = {
  compact?: boolean;
  inverted?: boolean;
};

export function BrandLockup({ compact = false, inverted = false }: Props) {
  const src = `${import.meta.env.BASE_URL}hummingbird.svg`;

  return (
    <span className={compact ? "brand-with-logo is-compact" : "brand-with-logo"}>
      <img className={inverted ? "brand-logo is-inverted" : "brand-logo"} src={src} alt="" width={40} height={28} aria-hidden />
      <span className="brand">
        HOME <span>of</span> CREATIVITY
      </span>
    </span>
  );
}
