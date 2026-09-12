import { Lottie } from "lottie-react";
import loadingAnimation from "../assets/loading.json";

type LoadingLottieProps = {
  variant?: "inline" | "page";
  label?: string;
};

export function LoadingLottie({ variant = "inline", label }: LoadingLottieProps) {
  return (
    <div
      className={`loading-lottie loading-lottie--${variant}`}
      role="status"
      aria-live="polite"
      aria-label={label}
    >
      <Lottie src={loadingAnimation} loop autoplay className="loading-lottie__player" aria-hidden />
      {label ? <span className="loading-lottie__label">{label}</span> : null}
    </div>
  );
}
