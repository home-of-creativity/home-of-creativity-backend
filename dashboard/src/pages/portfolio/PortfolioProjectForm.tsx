import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { z } from "zod";
import { FileDropzone } from "../../components/FileDropzone";
import { FormPage } from "../../components/FormPage";
import { FormSection } from "../../components/FormSection";
import { LoadingLottie } from "../../components/LoadingLottie";
import { useZodForm } from "../../lib/useZodForm";
import { api, type PortfolioCategory, type PortfolioProject } from "../../api";
import { copy, type Locale } from "../../i18n";
import { categoryLabel } from "./utils";

const urlField = (t: (c: { ar: string; en: string }) => string) => z.union([z.literal(""), z.string().trim().url(t(copy.invalidUrl))]);

export function PortfolioProjectForm({ locale, t }: { locale: Locale; t: (c: { ar: string; en: string }) => string }) {
  const navigate = useNavigate();
  const params = useParams();
  const editingId = params.id ? Number(params.id) : null;

  const [categories, setCategories] = useState<PortfolioCategory[]>([]);
  const [image, setImage] = useState<File | null>(null);
  const [currentImageUrl, setCurrentImageUrl] = useState<string | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [galleryFiles, setGalleryFiles] = useState<File[]>([]);
  const [existingGallery, setExistingGallery] = useState<NonNullable<PortfolioProject["images"]>>([]);
  const [removeGalleryIds, setRemoveGalleryIds] = useState<number[]>([]);
  const [loading, setLoading] = useState(Boolean(editingId));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const schema = useMemo(
    () =>
      z.object({
        category_id: z.string().trim().min(1, t(copy.fieldRequired)),
        title_en: z.string().trim().min(1, t(copy.fieldRequired)),
        title_ar: z.string().trim().min(1, t(copy.fieldRequired)),
        summary_en: z.string().trim(),
        summary_ar: z.string().trim(),
        website_url: urlField(t),
        social: z.object({
          instagram: urlField(t),
          facebook: urlField(t),
          linkedin: urlField(t),
          x: urlField(t),
          tiktok: urlField(t),
          youtube: urlField(t),
        }),
        sort_order: z.string().trim(),
        is_published: z.boolean(),
        featured: z.boolean(),
      }),
    [t],
  );

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useZodForm(schema, {
    defaultValues: {
      category_id: "",
      title_en: "",
      title_ar: "",
      summary_en: "",
      summary_ar: "",
      website_url: "",
      social: { instagram: "", facebook: "", linkedin: "", x: "", tiktok: "", youtube: "" },
      sort_order: "",
      is_published: true,
      featured: false,
    },
  });

  useEffect(() => {
    api
      .portfolioCategories()
      .then((res) => {
        setCategories(res.data);
        if (!editingId && !watch("category_id")) setValue("category_id", String(res.data[0]?.id ?? ""));
      })
      .catch(() => setCategories([]));
  }, [editingId, setValue, watch]);

  useEffect(() => {
    if (!editingId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    api
      .portfolioProjects(1)
      .then((res) => res.data.find((row) => row.id === editingId))
      .then((item) => {
        if (!item) {
          setError(t(copy.savePortfolioFailed));
          return;
        }
        reset({
          category_id: String(item.category_id),
          title_en: item.title_en,
          title_ar: item.title_ar,
          summary_en: item.summary_en ?? "",
          summary_ar: item.summary_ar ?? "",
          website_url: item.website_url ?? "",
          social: {
            instagram: item.social_links?.instagram ?? "",
            facebook: item.social_links?.facebook ?? "",
            linkedin: item.social_links?.linkedin ?? "",
            x: item.social_links?.x ?? "",
            tiktok: item.social_links?.tiktok ?? "",
            youtube: item.social_links?.youtube ?? "",
          },
          sort_order: String(item.sort_order),
          is_published: item.is_published,
          featured: item.featured,
        });
        setCurrentImageUrl(item.image_url);
        setExistingGallery(item.images ?? []);
      })
      .catch(() => setError(t(copy.savePortfolioFailed)))
      .finally(() => setLoading(false));
  }, [editingId, reset, t]);

  async function onValid(values: {
    category_id: string;
    title_en: string;
    title_ar: string;
    summary_en: string;
    summary_ar: string;
    website_url: string;
    social: Record<string, string>;
    sort_order: string;
    is_published: boolean;
    featured: boolean;
  }) {
    setError("");
    setBusy(true);
    const payload = new FormData();
    payload.set("category_id", values.category_id);
    payload.set("title_en", values.title_en.trim());
    payload.set("title_ar", values.title_ar.trim());
    payload.set("summary_en", values.summary_en.trim());
    payload.set("summary_ar", values.summary_ar.trim());
    payload.set("website_url", values.website_url.trim());
    for (const [platform, value] of Object.entries(values.social)) {
      payload.set(`social_links[${platform}]`, value.trim());
    }
    payload.set("sort_order", values.sort_order.trim() || "0");
    payload.set("is_published", values.is_published ? "1" : "0");
    payload.set("featured", values.featured ? "1" : "0");
    if (image) payload.set("image", image);
    for (const file of galleryFiles) payload.append("gallery[]", file);
    for (const id of removeGalleryIds) payload.append("remove_gallery_ids[]", String(id));

    try {
      if (editingId) await api.updatePortfolioProject(editingId, payload);
      else await api.createPortfolioProject(payload);
      toast.success(t(copy.saveSuccess));
      navigate("/projects");
    } catch (err) {
      const message = err instanceof Error ? err.message : t(copy.savePortfolioFailed);
      setError(message);
      toast.error(message);
      setBusy(false);
    }
  }

  if (loading) return <LoadingLottie variant="page" label={t(copy.loading)} />;

  return (
    <FormPage
      eyebrow={t(copy.portfolioTabProjects)}
      title={editingId ? t(copy.edit) : t(copy.addPortfolioProject)}
      backTo="/projects"
      backLabel={t(copy.portfolioTabProjects)}
      onSubmit={handleSubmit(onValid)}
      submitLabel={t(copy.savePortfolioProject)}
      cancelLabel={t(copy.cancel)}
      error={error}
      busy={busy}
      wide
    >
      <FormSection title={t(copy.title)}>
        <label className="field-label">
          {t(copy.category)}
          <select className={errors.category_id ? "field has-error" : "field"} {...register("category_id")}>
            <option value="">{t(copy.category)}</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {categoryLabel(category, locale)}
              </option>
            ))}
          </select>
          {errors.category_id ? <p className="field-error">{errors.category_id.message}</p> : null}
        </label>
        <label className="field-label">
          {t(copy.sortOrder)}
          <input className="field" type="number" min={0} {...register("sort_order")} />
        </label>
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
          {t(copy.summaryEn)}
          <textarea className="field" rows={2} {...register("summary_en")} />
        </label>
        <label className="field-label">
          {t(copy.summaryAr)}
          <textarea className="field" rows={2} {...register("summary_ar")} />
        </label>
        <label className="field-label field-span">
          {t(copy.website)}
          <input className={errors.website_url ? "field has-error" : "field"} type="url" dir="ltr" placeholder="https://" {...register("website_url")} />
          {errors.website_url ? <p className="field-error">{errors.website_url.message}</p> : null}
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("is_published")} />
          {t(copy.published)}
        </label>
        <label className="checkbox-row">
          <input type="checkbox" {...register("featured")} />
          {t(copy.featured)}
        </label>
      </FormSection>

      <FormSection title={t(copy.socialLinks)}>
        <input className="field field-span" type="url" dir="ltr" placeholder={t(copy.instagram)} {...register("social.instagram")} />
        <input className="field field-span" type="url" dir="ltr" placeholder={t(copy.facebook)} {...register("social.facebook")} />
        <input className="field field-span" type="url" dir="ltr" placeholder={t(copy.linkedin)} {...register("social.linkedin")} />
        <input className="field field-span" type="url" dir="ltr" placeholder={t(copy.xTwitter)} {...register("social.x")} />
        <input className="field field-span" type="url" dir="ltr" placeholder={t(copy.tiktok)} {...register("social.tiktok")} />
        <input className="field field-span" type="url" dir="ltr" placeholder={t(copy.youtube)} {...register("social.youtube")} />
      </FormSection>

      <div className="form-page-media">
        <FormSection title={t(copy.coverImage)}>
          <label className="field-label field-span">
            {t(copy.coverImage)}
            <FileDropzone
              accept={{ "image/png": [".png"], "image/jpeg": [".jpg", ".jpeg"], "image/webp": [".webp"] }}
              hint={t(copy.dropzoneHint)}
              activeHint={t(copy.dropzoneActive)}
              onFiles={(files) => {
                const file = files[0] ?? null;
                setImage(file);
                setImagePreview(file ? URL.createObjectURL(file) : null);
              }}
            />
          </label>
          {(imagePreview ?? currentImageUrl) ? (
            <div className="form-media-preview">
              <img src={imagePreview ?? currentImageUrl ?? ""} alt="" className="form-media-preview-img project-thumb" />
              <p className="muted">{imagePreview ? t(copy.replaceImage) : t(copy.currentImage)}</p>
            </div>
          ) : null}
        </FormSection>

        <FormSection title={t(copy.projectGallery)}>
          <label className="field-label field-span">
            {t(copy.projectGallery)}
            <FileDropzone
              accept={{ "image/png": [".png"], "image/jpeg": [".jpg", ".jpeg"], "image/webp": [".webp"] }}
              multiple
              hint={t(copy.dropzoneHintMultiple)}
              activeHint={t(copy.dropzoneActive)}
              onFiles={(files) => setGalleryFiles(files)}
            />
          </label>
          {existingGallery.length > 0 ? (
            <div className="gallery-preview-grid field-span">
              {existingGallery.map((item) => {
                const marked = removeGalleryIds.includes(item.id);
                return (
                  <label key={item.id} className={marked ? "gallery-preview-item is-marked" : "gallery-preview-item"}>
                    <img src={item.image_url ?? ""} alt="" referrerPolicy="no-referrer" className="gallery-preview-thumb" />
                    <input
                      type="checkbox"
                      checked={marked}
                      onChange={() =>
                        setRemoveGalleryIds((prev) => (prev.includes(item.id) ? prev.filter((id) => id !== item.id) : [...prev, item.id]))
                      }
                    />
                    <span>{t(copy.removeGalleryImage)}</span>
                  </label>
                );
              })}
            </div>
          ) : null}
          {galleryFiles.length > 0 ? (
            <p className="muted field-span">
              {t(copy.addGalleryImages)}: {galleryFiles.length}
            </p>
          ) : null}
        </FormSection>
      </div>
    </FormPage>
  );
}
