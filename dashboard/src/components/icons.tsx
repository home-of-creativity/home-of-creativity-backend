import type { SVGProps } from "react";

type IconProps = SVGProps<SVGSVGElement>;

const base = {
  viewBox: "0 0 24 24",
  width: 20,
  height: 20,
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.7,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

export function IconOverview(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" />
      <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" />
    </svg>
  );
}

export function IconRequests(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M6 3.5h9l3.5 3.5V20a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" />
      <path d="M15 3.5V7a1 1 0 0 0 1 1h3.5" />
      <path d="M8.25 12.5h7.5M8.25 15.75h5" />
    </svg>
  );
}

export function IconEmployees(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <circle cx="9" cy="8" r="3.1" />
      <path d="M3.75 19c.65-3 2.7-4.6 5.25-4.6s4.6 1.6 5.25 4.6" />
      <circle cx="16.7" cy="7.6" r="2.3" />
      <path d="M15.6 14.5c2.1.2 3.6 1.6 4.15 4" />
    </svg>
  );
}

export function IconClients(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <rect x="3.5" y="8" width="17" height="11" rx="1.5" />
      <path d="M8.5 8V6.3A2.3 2.3 0 0 1 10.8 4h2.4a2.3 2.3 0 0 1 2.3 2.3V8" />
      <path d="M3.5 12.5h17" />
    </svg>
  );
}

export function IconProjects(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M3.5 6.2a1.2 1.2 0 0 1 1.2-1.2h4.4l1.7 2h8.5a1.2 1.2 0 0 1 1.2 1.2v9.6a1.2 1.2 0 0 1-1.2 1.2H4.7a1.2 1.2 0 0 1-1.2-1.2Z" />
    </svg>
  );
}

export function IconReels(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <rect x="7" y="3.5" width="10" height="17" rx="2.2" />
      <path d="M10.5 10.2 15 12.5l-4.5 2.3Z" />
    </svg>
  );
}

export function IconCategories(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M11.3 3.5h4.7a1.5 1.5 0 0 1 1.5 1.5v4.7a1.5 1.5 0 0 1-.44 1.06l-8 8a1.5 1.5 0 0 1-2.12 0l-4.7-4.7a1.5 1.5 0 0 1 0-2.12l8-8A1.5 1.5 0 0 1 11.3 3.5Z" />
      <circle cx="14.5" cy="8.5" r="1.15" fill="currentColor" stroke="none" />
    </svg>
  );
}

export function IconContact(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M7 4.5h10A2.5 2.5 0 0 1 19.5 7v7A2.5 2.5 0 0 1 17 16.5H10l-4.5 3v-3H7A2.5 2.5 0 0 1 4.5 14V7A2.5 2.5 0 0 1 7 4.5Z" />
      <path d="M8.5 9.5h7M8.5 12.5h4.5" />
    </svg>
  );
}

export function IconPricing(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M4.5 7.5h15" />
      <path d="M7.5 4.5v3" />
      <path d="M16.5 4.5v3" />
      <rect x="4.5" y="7.5" width="15" height="12" rx="1.8" />
      <path d="M8.5 13.5h7" />
      <path d="M8.5 16.5h4.5" />
    </svg>
  );
}

export function IconLanguage(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <circle cx="12" cy="12" r="8.25" />
      <path d="M3.8 12h16.4M12 3.75c2.2 2.4 3.4 5.2 3.4 8.25S14.2 18.85 12 21.25c-2.2-2.4-3.4-5.2-3.4-8.25S9.8 6.15 12 3.75Z" />
    </svg>
  );
}

export function IconLogout(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M10 4.5H6.5A2 2 0 0 0 4.5 6.5v11A2 2 0 0 0 6.5 19.5H10" />
      <path d="M10.5 12H19.5" />
      <path d="M16.25 8.75 19.5 12l-3.25 3.25" />
    </svg>
  );
}

export function IconSocial(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <circle cx="6.5" cy="12" r="2.2" />
      <circle cx="17.5" cy="6.5" r="2.2" />
      <circle cx="17.5" cy="17.5" r="2.2" />
      <path d="M8.4 11.1 15.4 7.6M8.4 12.9 15.4 16.4" />
    </svg>
  );
}

export function IconQr(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <rect x="3.5" y="3.5" width="7" height="7" rx="1.2" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="1.2" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1.2" />
      <path d="M14 14h2.2v2.2H14zM17.3 14H19v2.2h-1.7zM14 17.3h2.2V19H14zM17.3 17.3H19V19h-1.7z" />
    </svg>
  );
}

export function IconArticles(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M5 4.5h9a2 2 0 0 1 2 2V19a1 1 0 0 0 1 1H7.5A2.5 2.5 0 0 1 5 17.5Z" />
      <path d="M16 7.5h1.5a2 2 0 0 1 2 2v8a2.5 2.5 0 0 1-2.5 2.5" />
      <path d="M8 8.5h5M8 11.5h5M8 14.5h3" />
    </svg>
  );
}

export function IconClose(props: IconProps) {
  return (
    <svg {...base} {...props}>
      <path d="M6.5 6.5 17.5 17.5" />
      <path d="M17.5 6.5 6.5 17.5" />
    </svg>
  );
}
