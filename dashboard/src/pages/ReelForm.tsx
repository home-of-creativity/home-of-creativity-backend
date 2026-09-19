import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { ConfirmAction } from "../components/ConfirmAction";
import { FileDropzone } from "../components/FileDropzone";
import { FormPage } from "../components/FormPage";
import { FormSection } from "../components/FormSection";
import { LoadingLottie } from "../components/LoadingLottie";
import { startUploadToast } from "../components/UploadToast";
import { useZodForm } from "../lib/useZodForm";
import { api } from "../api";
import { copy, type Locale } from "../i18n";

const maxVideoBytes = 512 * 1024 * 1024;

export function ReelForm({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [video, setVideo] = useState<File | null>(null);
  const [poster, setPoster] = useState<File | null>(null);
  const [currentVideoUrl, setCurrentVideoUrl] = useState<string | null>(null);
  const [currentPosterUrl, setCurrentPosterUrl] = useState<string | null>(null);
  const [videoPreview, setVideoPreview] = useState<string | null>(null);
  const [posterPreview, setPosterPreview] = useState<string | null>(null);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [saving, setSaving] = useState(false);
  const [removingPoster, setRemovingPoster] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        title_en: z.string().trim().min(1, t(copy.fieldRequired)),
        title_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        sort_order: z.string().trim(),
        is_published: z.boolean(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: { title_en: "", title_ar: "", sort_order: "", is_published: true },
  });

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .landingReels(1)
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.savePortfolioFailed));
          return;
        }
        reset({
          title_en: item.title_en,
          title_ar: item.title_ar,
          sort_order: String(item.sort_order),
          is_published: item.is_published,
        });
        setCurrentVideoUrl(item.video_url);
        setCurrentPosterUrl(item.poster_url);
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function clearPoster() {
    setError("");
    if (posterPreview) {
      URL.revokeObjectURL(posterPreview);
      setPoster(null);
      setPosterPreview(null);
      return;
    }
    if (editingId && currentPosterUrl) {
      setRemovingPoster(true);
      try {
        await api.deleteLandingReelPoster(editingId);
        setCurrentPosterUrl(null);
        toast.success(t(copy.coverImageRemoved));
      } catch (err) {
        const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
        setError(message);
        toast.error(message);
      } finally {
        setRemovingPoster(false);
      }
    }
  }

  async function onValid(values: { title_en: string; title_ar: string; sort_order: string; is_published: boolean }) {
    setError("");
    if (editingId === null && !video) {
      setError(t(copy.reelVideoRequired));
      return;
    }
    if (video && video.size > maxVideoBytes) {
      setError(t(copy.reelFileTooLarge));
      return;
    }
    const payload = new FormData();
    payload.set("title_en", values.title_en.trim());
    payload.set("title_ar", values.title_ar.trim());
    payload.set("sort_order", values.sort_order.trim() || "0");
    payload.set("is_published", values.is_published ? "1" : "0");
    if (video) payload.set("video", video);
    if (poster) payload.set("poster", poster);
    else if (editingId && !currentPosterUrl) payload.set("remove_poster", "1");

    document.querySelectorAll("video").forEach((el) => el.pause());

    setSaving(true);
    const upload = video || poster ? startUploadToast(t, locale) : null;
    try {
      if (editingId) await api.updateLandingReel(editingId, payload, upload?.onProgress);
      else await api.createLandingReel(payload, upload?.onProgress);
      upload ? upload.done() : toast.success(t(copy.saveSuccess));
      navigate("/reels");
    } catch (err) {
      const message = err instanceof Error ? err.message : "";
      const network = err instanceof TypeError || /failed to fetch|networkerror|load failed|api_unreachable/i.test(message);
      const finalMessage = network ? t(copy.reelSaveNetworkFailed) : message || t(copy.savePortfolioFailed);
      setError(finalMessage);
      upload ? upload.fail(finalMessage) : toast.error(finalMessage);
      setSaving(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <FormPage
      eyebrow={t(copy.reelsTitle)}
      title={editingId ? t(copy.edit) : t(copy.addReel)}
      backTo="/reels"
      backLabel={t(copy.reelsTitle)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={saving ? t(copy.reelSaving) : t(copy.saveReel)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={saving}
      wide
    >
      <FormSection title={t(copy.title)} description={t(copy.reelsLede)}>
        <label className="field-label">
          {t(copy.titleEn)}
          <input className={errors.title_en ? "field has-error" : "field"} {...register("title_en")} />
          {errors.title_en ? <p className="field-error">{errors.title_en.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.titleAr)}
          <input className={errors.title_ar ? "field has-error" : "field"} {...register("title_ar")} />
          {errors.title_ar ? <p className="field-error">{errors.title_ar.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.sortOrder)}
          <input className="field" type="number" min={0} {...register("sort_order")} />
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("is_published")} />
          {t(copy.published)}
        </label>
      </FormSection>

      <div className="form-page-media">
        <FormSection title={t(copy.reelVideo)}>
          <label className="field-label field-span">
            {t(copy.reelVideo)}
            <FileDropzone
              accept={{ "video/mp4": [".mp4"], "video/webm": [".webm"], "video/quicktime": [".mov"] }}
              hint={t(copy.dropzoneHint)}
              activeHint={t(copy.dropzoneActive)}
              onFiles={(files) => {
                const file = files[0] ?? null;
                setVideo(file);
                setVideoPreview(file ? URL.createObjectURL(file) : null);
              }}
            />
          </label>
          {(videoPreview ?? currentVideoUrl) ? (
            <div className="form-media-preview">
              <video src={videoPreview ?? currentVideoUrl ?? ""} className="form-media-preview-img project-thumb" controls muted playsInline preload="metadata" />
              <p className="muted">{videoPreview ? t(copy.replaceVideo) : t(copy.currentVideo)}</p>
            </div>
          ) : null}
        </FormSection>

        <FormSection title={t(copy.coverImage)}>
          <label className="field-label field-span">
            {t(copy.coverImage)}
            <FileDropzone
              accept={{ "image/png": [".png"], "image/jpeg": [".jpg", ".jpeg"], "image/webp": [".webp"] }}
              hint={t(copy.dropzoneHint)}
              activeHint={t(copy.dropzoneActive)}
              onFiles={(files) => {
                const file = files[0] ?? null;
                setPoster(file);
                setPosterPreview(file ? URL.createObjectURL(file) : null);
              }}
            />
          </label>
          {(posterPreview ?? currentPosterUrl) ? (
            <div className="form-media-preview">
              <img src={posterPreview ?? currentPosterUrl ?? ""} alt="" className="form-media-preview-img project-thumb" />
              <p className="muted">{posterPreview ? t(copy.replaceImage) : t(copy.currentImage)}</p>
              <ConfirmAction
                label={t(copy.removeCoverImage)}
                yesLabel={t(copy.delete)}
                noLabel={t(copy.cancel)}
                disabled={saving || removingPoster}
                onConfirm={() => void clearPoster()}
              />
            </div>
          ) : null}
        </FormSection>
      </div>
    </FormPage>
  );
}

export default ReelForm;
