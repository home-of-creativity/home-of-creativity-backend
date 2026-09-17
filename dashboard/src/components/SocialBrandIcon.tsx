export function SocialBrandIcon({
  platform,
  className = "social-brand-icon",
}: {
  platform: string;
  className?: string;
}) {
  if (platform === "instagram") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="#E4405F">
        <path d="M7.2 3h9.6A4.2 4.2 0 0 1 21 7.2v9.6A4.2 4.2 0 0 1 16.8 21H7.2A4.2 4.2 0 0 1 3 16.8V7.2A4.2 4.2 0 0 1 7.2 3Zm0 1.7A2.5 2.5 0 0 0 4.7 7.2v9.6a2.5 2.5 0 0 0 2.5 2.5h9.6a2.5 2.5 0 0 0 2.5-2.5V7.2a2.5 2.5 0 0 0-2.5-2.5H7.2Zm9.05 1.4a1.05 1.05 0 1 1 0 2.1 1.05 1.05 0 0 1 0-2.1ZM12 7.6A4.4 4.4 0 1 1 7.6 12 4.4 4.4 0 0 1 12 7.6Zm0 1.7A2.7 2.7 0 1 0 14.7 12 2.7 2.7 0 0 0 12 9.3Z" />
      </svg>
    );
  }

  if (platform === "facebook") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="#1877F2">
        <path d="M14.6 8.4h2.3V5.2h-2.3c-2.7 0-4.4 1.6-4.4 4.4v1.8H8.2V14h2v6.8h3.2V14h2.4l.5-2.6h-2.9V9.8c0-.9.4-1.4 1.2-1.4Z" />
      </svg>
    );
  }

  if (platform === "threads") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="currentColor">
        <path d="M16.3 11.2c-.2-1.3-1-2.2-2.4-2.6.3-.2.6-.4.8-.7.5-.6.8-1.4.7-2.2-.2-1.7-1.8-2.9-3.8-2.9-2.4 0-4 1.5-4.1 3.6h1.9c.1-1 .9-1.8 2.2-1.8 1.1 0 1.8.6 1.9 1.5.1.7-.2 1.3-.9 1.7l-.7.3c-3.1.9-4.6 2.3-4.5 4.7.1 2.1 1.7 3.6 4 3.7 1.6.1 2.8-.4 3.7-1.5.4.8.6 1.6.6 2.4 0 1.8-1.2 2.8-3.3 2.8-1.8 0-3-.8-3.6-2.3l-1.8.7c.8 2.3 2.8 3.5 5.4 3.5 3.2 0 5.2-1.7 5.2-4.6 0-1.4-.4-2.7-1.3-4.3Zm-4.2 3.6c-1.3 0-2.2-.8-2.2-1.9 0-1.4.9-2.3 3.1-2.9.7.3 1.2.8 1.4 1.5.2.7 0 1.4-.4 1.9-.5.7-1.2 1.4-1.9 1.4Z" />
      </svg>
    );
  }

  if (platform === "linkedin") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="#0A66C2">
        <path d="M6.5 9.3H3.8V20h2.7V9.3ZM5.15 4A1.6 1.6 0 1 0 5.16 7.2 1.6 1.6 0 0 0 5.15 4ZM20.2 20h-2.7v-5.6c0-1.6-.6-2.5-1.9-2.5-1 0-1.5.7-1.8 1.3-.1.2-.1.6-.1.9V20h-2.7s.04-8.8 0-10.7h2.7v1.7c.4-.7 1.4-1.9 3.4-1.9 2.4 0 4.2 1.6 4.2 5V20Z" />
      </svg>
    );
  }

  if (platform === "x") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="currentColor">
        <path d="M14.6 10.4 20.7 3h-1.7l-5.2 6.3L9.5 3H4.3l6.4 9.8L4.3 21h1.7l5.6-6.8L14.6 21h5.2l-5.2-10.6ZM11.3 13.3l-.7-1-5.2-7.8h2.2l4.2 6.3.7 1 5.5 8.2h-2.2l-4.5-6.7Z" />
      </svg>
    );
  }

  if (platform === "tiktok") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="currentColor">
        <path d="M14.2 3v11.1a3.4 3.4 0 1 1-2.9-3.36V8.4a6.1 6.1 0 1 0 5.6 6.05V8.55A7.2 7.2 0 0 0 21 9.8V6.7a7.2 7.2 0 0 1-4.1-1.7A7.3 7.3 0 0 1 14.2 3Z" />
      </svg>
    );
  }

  if (platform === "pinterest") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="#E60023">
        <path d="M12 3.2A8.8 8.8 0 0 0 8.4 20c.1-.7.4-1.8.7-2.6l1.3-4.9s-.3-.7-.3-1.7c0-1.6.9-2.8 2.1-2.8 1 0 1.5.7 1.5 1.6 0 1-.6 2.4-.9 3.8-.3 1.1.5 2 1.6 2 1.9 0 3.2-2.4 3.2-5.3 0-2.2-1.5-3.8-4.2-3.8-3.1 0-5 2.3-5 4.8 0 .9.3 1.8.7 2.4.1.1.1.2.1.3l-.3 1.1c0 .2-.1.3-.3.2-1.3-.5-1.9-1.9-1.9-3.5 0-2.6 2.2-5.8 6.6-5.8 3.5 0 5.8 2.5 5.8 5.2 0 3.6-2 6.2-4.9 6.2-1 0-1.9-.5-2.2-1.1l-.6 2.3c-.2.8-.8 1.8-1.2 2.4A8.8 8.8 0 1 0 12 3.2Z" />
      </svg>
    );
  }

  if (platform === "youtube") {
    return (
      <svg viewBox="0 0 24 24" className={className} width="16" height="16" aria-hidden fill="#FF0000">
        <path d="M21.6 7.6a2.6 2.6 0 0 0-1.8-1.9C18.2 5.3 12 5.3 12 5.3s-6.2 0-7.8.4A2.6 2.6 0 0 0 2.4 7.6 27 27 0 0 0 2 12a27 27 0 0 0 .4 4.4 2.6 2.6 0 0 0 1.8 1.9c1.6.4 7.8.4 7.8.4s6.2 0 7.8-.4a2.6 2.6 0 0 0 1.8-1.9A27 27 0 0 0 22 12a27 27 0 0 0-.4-4.4ZM10.2 15.2V8.8L15.6 12l-5.4 3.2Z" />
      </svg>
    );
  }

  return null;
}
