import { UploadCloud } from "lucide-react";
import { useCallback } from "react";
import { useDropzone, type Accept } from "react-dropzone";

export function FileDropzone({
  accept,
  onFiles,
  multiple,
  disabled,
  hint,
  activeHint,
  className,
}: {
  accept?: Accept;
  onFiles: (files: File[]) => void;
  multiple?: boolean;
  disabled?: boolean;
  hint: string;
  activeHint?: string;
  className?: string;
}) {
  const onDrop = useCallback(
    (accepted: File[]) => {
      onFiles(accepted);
    },
    [onFiles],
  );

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    accept,
    multiple,
    disabled,
    onDrop,
  });

  const rootProps = getRootProps();

  return (
    <div
      {...rootProps}
      className={["dropzone", isDragActive ? "is-active" : "", disabled ? "is-disabled" : "", className].filter(Boolean).join(" ")}
    >
      <input {...getInputProps()} />
      <UploadCloud size={20} aria-hidden="true" />
      <p>{isDragActive ? activeHint ?? hint : hint}</p>
    </div>
  );
}
