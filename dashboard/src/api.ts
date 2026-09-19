function resolveApiUrl() {
  if (import.meta.env.DEV) {
    return "/api";
  }
  return (import.meta.env.VITE_API_URL ?? "https://api.hoc.agency/api").replace(/\/$/, "");
}

const API_URL = resolveApiUrl();
const TOKEN_KEY = "hoc-staff-token";

export function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

export type SocialAbility = "accounts" | "create" | "approve" | "engage";

export type User = {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
  social_permissions?: SocialAbility[] | null;
  social_abilities?: SocialAbility[];
};

export type OpsSettings = {
  sham_cash_qr: boolean;
  sham_cash_qr_updated_at?: string | null;
};

export type SocialLinktreeProfile = {
  display_name: string;
  bio: string;
  theme: "cream" | "purple" | "dark";
};

export function canSocial(user: User | null | undefined, ability: SocialAbility) {
  return Boolean(user?.social_abilities?.includes(ability));
}

export type SocialStaff = User;

export type SocialAccount = {
  id: number;
  platform: string;
  name: string;
  handle: string | null;
  page_id: string | null;
  facebook_page_id?: string | null;
  has_token: boolean;
  is_active: boolean;
  connection_status: string;
  last_error: string | null;
  connected_by?: { id: number; name: string } | null;
  created_at?: string | null;
};

export type SocialPostMedia = {
  id: number;
  url: string | null;
  original_name: string;
  mime: string | null;
  kind: string;
  sort_order: number;
};

export type SocialActivity = {
  id: number;
  action: string;
  user?: { id: number; name: string } | null;
  metadata?: Record<string, unknown> | null;
  created_at: string | null;
};

export type SocialPostAccount = {
  id: number;
  platform: string;
  name: string;
  handle: string | null;
  is_active: boolean;
  publish_status: string;
  external_id: string | null;
  published_at?: string | null;
  last_error: string | null;
};

export type SocialPost = {
  id: number;
  body: string;
  placement?: string;
  status: string;
  scheduled_at: string | null;
  published_at: string | null;
  approved_at: string | null;
  last_error: string | null;
  is_editable?: boolean;
  is_deletable?: boolean;
  created_by?: { id: number; name: string } | null;
  updated_by?: { id: number; name: string } | null;
  approved_by?: { id: number; name: string } | null;
  accounts?: SocialPostAccount[];
  media?: SocialPostMedia[];
  activities?: SocialActivity[];
  created_at?: string | null;
};

export type SocialInboxReply = {
  id: number;
  body: string;
  sent_at: string | null;
  last_error: string | null;
  user?: { id: number; name: string } | null;
};

export type SocialInboxItem = {
  id: number;
  kind: string;
  external_id: string;
  source_external_id?: string | null;
  source_body?: string | null;
  source_permalink?: string | null;
  source_preview_url?: string | null;
  source_media_type?: string | null;
  source_post?: { id: number; body: string; placement?: string | null } | null;
  author_name: string;
  author_handle: string | null;
  body: string;
  occurred_at: string | null;
  is_replied: boolean;
  account?: { id: number; platform: string; name: string } | null;
  replies?: SocialInboxReply[];
};

export type Client = {
  id: number;
  name: string;
  company_name?: string | null;
  company_activity?: string | null;
  email: string | null;
  phone: string | null;
  telegram_user_id: string | null;
  telegram_url?: string | null;
  odoo_partner_id?: string | null;
  odoo_lead_id?: string | null;
  odoo_stage_name?: string | null;
  odoo_url?: string | null;
  odoo_lead_url?: string | null;
  odoo_live?: { stage?: string | null; name?: string | null } | null;
  requests_count?: number;
};

export type OdooQuotation = {
  id: number;
  name: string;
  partner_name: string | null;
  amount_total: number;
  state: string;
  client_order_ref: string | null;
  origin: string | null;
  date_order: string | null;
  odoo_url: string;
};

export type OdooInvoice = {
  id: number;
  name: string;
  partner_name: string | null;
  amount_total: number;
  state: string;
  payment_state: string;
  invoice_origin: string | null;
  ref: string | null;
  invoice_date: string | null;
  odoo_url: string;
};

export type Brief = {
  department: string;
  type?: string | null;
  brief: string | null;
  clickup_task_id: string | null;
};

export type RequestFile = {
  id: number;
  kind: string;
  original_name: string;
  path?: string | null;
};

export type Quotation = {
  id: number;
  version: number;
  amount: string;
  notes?: string | null;
  sent_at?: string | null;
};

export type QuotationDecision = {
  id: number;
  decision: "approved" | "rejected";
  reason?: string | null;
  created_at?: string | null;
};

export type ClickUpTask = {
  task_type: string;
  clickup_task_id?: string | null;
  clickup_url?: string | null;
  integration_key: string;
};

export type ServiceRequest = {
  id: number;
  uuid?: string;
  number: string;
  title: string;
  description: string;
  status: string;
  source: string;
  work_type?: string | null;
  execution_status?: string | null;
  execution_status_label?: string | null;
  odoo_quotation_id?: string | null;
  odoo_invoice_id?: string | null;
  odoo_quotation_url?: string | null;
  odoo_invoice_url?: string | null;
  odoo_quotation_live?: OdooQuotation | null;
  odoo_invoice_live?: OdooInvoice | null;
  gemini_status?: string | null;
  gemini_error?: string | null;
  quotation_amount?: string | null;
  quotation_notes?: string | null;
  billing_period?: string | null;
  payment_plan?: string | null;
  requires_full_payment?: boolean;
  allows_renewal?: boolean;
  amount_total?: string | number | null;
  amount_paid?: string | number | null;
  amount_remaining?: string | number | null;
  paid_percent?: number | null;
  remaining_percent?: number | null;
  expected_due?: string | number | null;
  subscription_starts_at?: string | null;
  subscription_ends_at?: string | null;
  google_drive_folder_id?: string | null;
  google_drive_folder_url?: string | null;
  receipt_reupload_required?: boolean;
  receipt_reupload_reason?: string | null;
  can_renew?: boolean;
  paid_at?: string | null;
  briefs?: Brief[];
  files?: RequestFile[];
  quotations?: Quotation[];
  quotation_decisions?: QuotationDecision[];
  clickup_tasks?: ClickUpTask[];
  client?: Client;
  created_at: string | null;
};

export type Employee = {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  email: string | null;
  telegram_user_id: string | null;
  telegram_username: string | null;
  clickup_user_id: string | null;
  odoo_employee_id?: string | null;
  odoo_url?: string | null;
  profession: string;
  status: "pending" | "approved" | "rejected";
  notes: string | null;
  is_active: boolean;
};

export type ClickUpMember = {
  id: string;
  name: string;
  email: string | null;
};

export type ShowcaseClient = {
  id: number;
  name: string;
  logo_path: string | null;
  logo_url: string | null;
  website_url: string | null;
  sort_order: number;
  is_published: boolean;
};

export type PortfolioCategory = {
  id: number;
  slug: string;
  name_en: string;
  name_ar: string;
  sort_order: number;
  is_published: boolean;
  projects_count?: number;
};

export type PortfolioProjectImage = {
  id: number;
  image_path: string;
  image_url: string | null;
  alt_en: string | null;
  alt_ar: string | null;
  sort_order: number;
  featured: boolean;
};

export type PortfolioSocialLinks = Partial<
  Record<"instagram" | "facebook" | "linkedin" | "x" | "tiktok" | "youtube", string>
>;

export type PricingCategory = {
  id: number;
  slug: string;
  name_en: string;
  name_ar: string;
  lead_en: string | null;
  lead_ar: string | null;
  sort_order: number;
  is_published: boolean;
  requires_full_payment?: boolean;
  allows_renewal?: boolean;
  subcategories_count?: number;
};

export type PricingSubcategory = {
  id: number;
  category_id: number;
  slug: string;
  name_en: string;
  name_ar: string;
  lead_en: string | null;
  lead_ar: string | null;
  one_time: boolean;
  lead_in_box: boolean;
  lead_note_en: string | null;
  lead_note_ar: string | null;
  sort_order: number;
  is_published: boolean;
  packages_count?: number;
  category?: PricingCategory;
};

export type PricingPackagePrices = {
  monthly: number;
  quarterly: number;
  semiannual: number;
  yearly: number;
};

export type PricingPackageReach = {
  adBudgetUsd: number;
  adCreditUsd: number;
  estimatedReach: { en: string; ar: string };
  goal: { en: string; ar: string };
};

export type PricingPackage = {
  id: number;
  subcategory_id: number;
  slug: string;
  name_en: string;
  name_ar: string;
  subtitle_en: string;
  subtitle_ar: string;
  price_usd: number | null;
  prices: PricingPackagePrices | null;
  features: Array<{ en: string; ar: string }>;
  reach: PricingPackageReach | null;
  featured: boolean;
  badge_en: string | null;
  badge_ar: string | null;
  sort_order: number;
  is_published: boolean;
  allows_partial_payment?: boolean | null;
  subcategory?: PricingSubcategory;
};

export type ContactChannel = {
  id: number;
  kind: "mobile" | "whatsapp" | "social" | "location";
  region: string | null;
  platform: string | null;
  value: string;
  value_ar: string | null;
  digits: string | null;
  url: string | null;
  sort_order: number;
  is_published: boolean;
};

export type PortfolioProject = {
  id: number;
  category_id: number;
  category?: PortfolioCategory;
  title_en: string;
  title_ar: string;
  summary_en: string | null;
  summary_ar: string | null;
  website_url: string | null;
  social_links: PortfolioSocialLinks;
  image_path: string | null;
  image_url: string | null;
  images?: PortfolioProjectImage[];
  sort_order: number;
  is_published: boolean;
  featured: boolean;
};

export type LandingReel = {
  id: number;
  title_en: string;
  title_ar: string;
  video_path: string;
  video_url: string | null;
  poster_path: string | null;
  poster_url: string | null;
  sort_order: number;
  is_published: boolean;
};

export type PageMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
};

export type Paginated<T> = {
  data: T[];
  meta: PageMeta;
  message?: string;
};

type Envelope<T> = { data: T; message?: string };

function queryString(params: Record<string, string | number | undefined>) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === "") continue;
    search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : "";
}

function apiErrorMessage(body: Record<string, unknown>, status: number) {
  if (typeof body.message === "string" && body.message.length > 0) {
    return body.message;
  }
  if (body.errors && typeof body.errors === "object") {
    const lines = Object.values(body.errors as Record<string, string[]>)
      .flat()
      .filter(Boolean);
    if (lines.length > 0) return lines.join(" ");
  }
  return `HTTP ${status}`;
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers = new Headers(init?.headers);
  headers.set("Accept", "application/json");
  if (init?.body && !(init.body instanceof FormData)) {
    headers.set("Content-Type", "application/json");
  }
  const token = getToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);

  let response: Response;
  try {
    response = await fetch(`${API_URL}${path}`, { ...init, headers });
  } catch (err) {
    if (err instanceof TypeError) {
      throw new Error("api_unreachable");
    }
    throw err;
  }
  if (response.status === 401) {
    setToken(null);
  }
  if (!response.ok) {
    const body = (await response.json().catch(() => ({}))) as Record<string, unknown>;
    throw new Error(apiErrorMessage(body, response.status));
  }
  return response.json() as Promise<T>;
}

export type UploadProgress = {
  loaded: number;
  total: number;
  phase: "uploading" | "processing";
};

function parseXhrJson(raw: string): Record<string, unknown> {
  if (!raw) return {};
  try {
    return JSON.parse(raw) as Record<string, unknown>;
  } catch {
    return {};
  }
}

async function submitForm<T>(
  path: string,
  method: "POST" | "PUT",
  form: FormData,
  onProgress?: (progress: UploadProgress) => void,
) {
  if (method === "PUT") {
    form.set("_method", "PUT");
  }
  if (!onProgress) {
    return request<T>(path, { method: "POST", body: form });
  }

  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", `${API_URL}${path}`);
    xhr.responseType = "text";
    xhr.setRequestHeader("Accept", "application/json");
    const token = getToken();
    if (token) xhr.setRequestHeader("Authorization", `Bearer ${token}`);

    xhr.upload.onprogress = (event) => {
      onProgress({
        loaded: event.loaded,
        total: event.lengthComputable ? event.total : 0,
        phase: "uploading",
      });
    };
    xhr.upload.onload = () => {
      onProgress({ loaded: 0, total: 0, phase: "processing" });
    };

    xhr.onload = () => {
      if (xhr.status === 401) setToken(null);
      const body = parseXhrJson(xhr.responseText);
      if (xhr.status < 200 || xhr.status >= 300) {
        reject(new Error(apiErrorMessage(body, xhr.status)));
        return;
      }
      resolve(body as T);
    };
    xhr.onerror = () => reject(new Error("api_unreachable"));
    xhr.onabort = () => reject(new Error("api_unreachable"));
    xhr.send(form);
  });
}

export const api = {
  login(email: string, password: string) {
    return request<Envelope<{ token: string; user: User }>>("/auth/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
  },
  me() {
    return request<Envelope<User>>("/auth/me");
  },
  logout() {
    return request<Envelope<null>>("/auth/logout", { method: "POST" });
  },
  overview() {
    return request<Envelope<{
      clients: number;
      requests: number;
      pending_employees?: number;
      by_status: Record<string, number>;
      recent?: ServiceRequest[];
    }>>("/admin/overview");
  },
  requests(status?: string, page = 1) {
    return request<Paginated<ServiceRequest>>(`/admin/requests${queryString({ status, page })}`);
  },
  request(id: string) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}`);
  },
  updateStatus(id: number, status: string) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}`, {
      method: "PATCH",
      body: JSON.stringify({ status }),
    });
  },
  sendQuotation(
    id: number,
    payload:
      | { lines: Array<{ title: string; amount: number; units?: number; notes?: string }>; requires_full_payment?: boolean }
      | { amount: number; notes?: string; requires_full_payment?: boolean },
  ) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/quotation`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  confirmPayment(id: number, payment_method: "receipt" | "cash", amount: number) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/confirm-payment`, {
      method: "POST",
      body: JSON.stringify({ payment_method, amount }),
    });
  },
  retryGemini(id: number) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/retry-gemini`, { method: "POST" });
  },
  reRequestReceipt(id: number, reason?: string) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/re-request-receipt`, {
      method: "POST",
      body: JSON.stringify({ reason }),
    });
  },
  renewRequest(id: number) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/renew`, { method: "POST" });
  },
  opsSettings() {
    return request<Envelope<OpsSettings>>("/admin/ops-settings");
  },
  shamCashQrBlob() {
    const headers = new Headers({ Accept: "image/*" });
    const token = getToken();
    if (token) headers.set("Authorization", `Bearer ${token}`);
    return fetch(`${API_URL}/admin/ops-settings/sham-cash-qr`, { headers }).then(async (response) => {
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      return response.blob();
    });
  },
  uploadShamCashQr(file: File) {
    const form = new FormData();
    form.append("file", file);
    return submitForm<Envelope<OpsSettings>>("/admin/ops-settings/sham-cash-qr", "POST", form);
  },
  socialProfile() {
    return request<Envelope<SocialLinktreeProfile>>("/admin/ops-settings/social-profile");
  },
  updateSocialProfile(payload: SocialLinktreeProfile) {
    return request<Envelope<SocialLinktreeProfile>>("/admin/ops-settings/social-profile", {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  async receiptBlob(requestId: number, fileId: number) {
    const headers = new Headers({ Accept: "application/octet-stream" });
    const token = getToken();
    if (token) headers.set("Authorization", `Bearer ${token}`);
    const response = await fetch(`${API_URL}/admin/requests/${requestId}/files/${fileId}/receipt`, { headers });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.blob();
  },
  clients(page = 1, search?: string) {
    return request<Paginated<Client>>(`/admin/clients${queryString({ page, search: search || undefined })}`);
  },
  createClient(payload: { name: string; email?: string; phone?: string; telegram_user_id?: string; company_name?: string }) {
    return request<Envelope<Client>>("/admin/clients", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updateClient(
    id: number,
    payload: { name: string; email?: string | null; phone?: string | null; telegram_user_id?: string | null; company_name?: string | null },
  ) {
    return request<Envelope<Client>>(`/admin/clients/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  deleteClient(id: number) {
    return request<Envelope<null>>(`/admin/clients/${id}`, { method: "DELETE" });
  },
  odooStatus() {
    return request<Envelope<{ configured: boolean; url: string | null }>>("/admin/odoo/status");
  },
  importOdooCrmClientsExcel(file: File) {
    const form = new FormData();
    form.append("file", file);
    return submitForm<
      Envelope<{
        parsed: number;
        skipped: number;
        created_in_odoo: number;
        duplicates: number;
        failed: number;
        imported: number;
        created: number;
        updated: number;
        crm_leads: number;
        partners: number;
      }>
    >("/admin/odoo/import-crm-clients/excel", "POST", form);
  },
  odooQuotations() {
    return request<{ data: OdooQuotation[] }>("/admin/odoo/quotations");
  },
  odooInvoices() {
    return request<{ data: OdooInvoice[] }>("/admin/odoo/invoices");
  },
  employees() {
    return request<{ data: Employee[] }>("/admin/employees");
  },
  clickupMembers() {
    return request<{ data: ClickUpMember[] }>("/admin/clickup/members");
  },
  createEmployee(payload: Partial<Employee> & { name: string; profession: string }) {
    return request<Envelope<Employee>>("/admin/employees", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updateEmployee(id: number, payload: Partial<Employee>) {
    return request<Envelope<Employee>>(`/admin/employees/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  approveEmployee(id: number, payload: { profession: string; clickup_user_id?: string | null }) {
    return request<Envelope<Employee>>(`/admin/employees/${id}/approve`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  rejectEmployee(id: number) {
    return request<Envelope<Employee>>(`/admin/employees/${id}/reject`, { method: "POST" });
  },
  deleteEmployee(id: number) {
    return request<Envelope<null>>(`/admin/employees/${id}`, { method: "DELETE" });
  },
  portfolioCategories() {
    return request<{ data: PortfolioCategory[] }>("/admin/portfolio/categories");
  },
  createPortfolioCategory(payload: {
    slug: string;
    name_en: string;
    name_ar: string;
    sort_order?: number;
    is_published?: boolean;
  }) {
    return request<Envelope<PortfolioCategory>>("/admin/portfolio/categories", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updatePortfolioCategory(
    id: number,
    payload: Partial<{
      slug: string;
      name_en: string;
      name_ar: string;
      sort_order: number;
      is_published: boolean;
    }>,
  ) {
    return request<Envelope<PortfolioCategory>>(`/admin/portfolio/categories/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  deletePortfolioCategory(id: number) {
    return request<Envelope<null>>(`/admin/portfolio/categories/${id}`, { method: "DELETE" });
  },
  deleteAllPortfolioCategories() {
    return request<Envelope<{ deleted: boolean }>>("/admin/portfolio/categories/bulk", { method: "DELETE" });
  },
  movePortfolioCategory(id: number, direction: "up" | "down") {
    return request<{ data: PortfolioCategory[]; message?: string }>(`/admin/portfolio/categories/${id}/move`, {
      method: "POST",
      body: JSON.stringify({ direction }),
    });
  },
  showcaseClients(page = 1) {
    return request<Paginated<ShowcaseClient>>(`/admin/portfolio/clients${queryString({ page })}`);
  },
  createShowcaseClient(form: FormData) {
    return submitForm<Envelope<ShowcaseClient>>("/admin/portfolio/clients", "POST", form);
  },
  updateShowcaseClient(id: number, form: FormData) {
    return submitForm<Envelope<ShowcaseClient>>(`/admin/portfolio/clients/${id}`, "PUT", form);
  },
  deleteShowcaseClient(id: number) {
    return request<Envelope<null>>(`/admin/portfolio/clients/${id}`, { method: "DELETE" });
  },
  deleteAllShowcaseClients() {
    return request<Envelope<{ deleted: number }>>("/admin/portfolio/clients/bulk", { method: "DELETE" });
  },
  portfolioProjects(page = 1) {
    return request<Paginated<PortfolioProject>>(`/admin/portfolio/projects${queryString({ page })}`);
  },
  createPortfolioProject(form: FormData) {
    return submitForm<Envelope<PortfolioProject>>("/admin/portfolio/projects", "POST", form);
  },
  updatePortfolioProject(id: number, form: FormData) {
    return submitForm<Envelope<PortfolioProject>>(`/admin/portfolio/projects/${id}`, "PUT", form);
  },
  deletePortfolioProject(id: number) {
    return request<Envelope<null>>(`/admin/portfolio/projects/${id}`, { method: "DELETE" });
  },
  deleteAllPortfolioProjects() {
    return request<Envelope<{ deleted: number }>>("/admin/portfolio/projects/bulk", { method: "DELETE" });
  },
  landingReels(page = 1) {
    return request<Paginated<LandingReel>>(`/admin/reels${queryString({ page })}`);
  },
  createLandingReel(form: FormData, onProgress?: (progress: UploadProgress) => void) {
    return submitForm<Envelope<LandingReel>>("/admin/reels", "POST", form, onProgress);
  },
  updateLandingReel(id: number, form: FormData, onProgress?: (progress: UploadProgress) => void) {
    return submitForm<Envelope<LandingReel>>(`/admin/reels/${id}`, "PUT", form, onProgress);
  },
  deleteLandingReel(id: number) {
    return request<Envelope<null>>(`/admin/reels/${id}`, { method: "DELETE" });
  },
  deleteAllLandingReels() {
    return request<Envelope<{ deleted: number }>>("/admin/reels/bulk", { method: "DELETE" });
  },
  pricingCategories() {
    return request<{ data: PricingCategory[] }>("/admin/pricing/categories");
  },
  createPricingCategory(payload: {
    slug: string;
    name_en: string;
    name_ar: string;
    lead_en?: string | null;
    lead_ar?: string | null;
    sort_order?: number;
    is_published?: boolean;
    requires_full_payment?: boolean;
    allows_renewal?: boolean;
  }) {
    return request<Envelope<PricingCategory>>("/admin/pricing/categories", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updatePricingCategory(id: number, payload: Partial<{
    slug: string;
    name_en: string;
    name_ar: string;
    lead_en: string | null;
    lead_ar: string | null;
    sort_order: number;
    is_published: boolean;
    requires_full_payment?: boolean;
    allows_renewal?: boolean;
  }>) {
    return request<Envelope<PricingCategory>>(`/admin/pricing/categories/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  deletePricingCategory(id: number) {
    return request<Envelope<null>>(`/admin/pricing/categories/${id}`, { method: "DELETE" });
  },
  deleteAllPricingCategories() {
    return request<Envelope<{ deleted: boolean }>>("/admin/pricing/categories/bulk", { method: "DELETE" });
  },
  movePricingCategory(id: number, direction: "up" | "down") {
    return request<{ data: PricingCategory[] }>(`/admin/pricing/categories/${id}/move`, {
      method: "POST",
      body: JSON.stringify({ direction }),
    });
  },
  pricingSubcategories(categoryId?: number) {
    return request<{ data: PricingSubcategory[] }>(
      `/admin/pricing/subcategories${queryString({ category_id: categoryId })}`,
    );
  },
  createPricingSubcategory(payload: {
    category_id: number;
    slug: string;
    name_en: string;
    name_ar: string;
    lead_en?: string | null;
    lead_ar?: string | null;
    one_time?: boolean;
    lead_in_box?: boolean;
    lead_note_en?: string | null;
    lead_note_ar?: string | null;
    sort_order?: number;
    is_published?: boolean;
  }) {
    return request<Envelope<PricingSubcategory>>("/admin/pricing/subcategories", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updatePricingSubcategory(id: number, payload: Partial<{
    category_id: number;
    slug: string;
    name_en: string;
    name_ar: string;
    lead_en: string | null;
    lead_ar: string | null;
    one_time: boolean;
    lead_in_box: boolean;
    lead_note_en: string | null;
    lead_note_ar: string | null;
    sort_order: number;
    is_published: boolean;
  }>) {
    return request<Envelope<PricingSubcategory>>(`/admin/pricing/subcategories/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  deletePricingSubcategory(id: number) {
    return request<Envelope<null>>(`/admin/pricing/subcategories/${id}`, { method: "DELETE" });
  },
  movePricingSubcategory(id: number, direction: "up" | "down") {
    return request<{ data: PricingSubcategory[] }>(`/admin/pricing/subcategories/${id}/move`, {
      method: "POST",
      body: JSON.stringify({ direction }),
    });
  },
  pricingPackages(filters?: { categoryId?: number; subcategoryId?: number }) {
    return request<{ data: PricingPackage[] }>(
      `/admin/pricing/packages${queryString({
        category_id: filters?.categoryId,
        subcategory_id: filters?.subcategoryId,
      })}`,
    );
  },
  createPricingPackage(payload: Record<string, unknown>) {
    return request<Envelope<PricingPackage>>("/admin/pricing/packages", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updatePricingPackage(id: number, payload: Record<string, unknown>) {
    return request<Envelope<PricingPackage>>(`/admin/pricing/packages/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  deletePricingPackage(id: number) {
    return request<Envelope<null>>(`/admin/pricing/packages/${id}`, { method: "DELETE" });
  },
  movePricingPackage(id: number, direction: "up" | "down") {
    return request<{ data: PricingPackage[] }>(`/admin/pricing/packages/${id}/move`, {
      method: "POST",
      body: JSON.stringify({ direction }),
    });
  },
  contactChannels() {
    return request<{ data: ContactChannel[] }>("/admin/contact");
  },
  createContactChannel(payload: Partial<ContactChannel> & { kind: ContactChannel["kind"]; value: string }) {
    return request<Envelope<ContactChannel>>("/admin/contact", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updateContactChannel(id: number, payload: Partial<ContactChannel>) {
    return request<Envelope<ContactChannel>>(`/admin/contact/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  deleteContactChannel(id: number) {
    return request<Envelope<null>>(`/admin/contact/${id}`, { method: "DELETE" });
  },
  moveContactChannel(id: number, direction: "up" | "down") {
    return request<{ data: ContactChannel[] }>(`/admin/contact/${id}/move`, {
      method: "POST",
      body: JSON.stringify({ direction }),
    });
  },
  socialAccounts() {
    return request<{
      data: SocialAccount[];
      facebook_configured?: boolean;
      facebook_error?: string | null;
      facebook_pages_found?: number;
      threads_configured?: boolean;
      threads_error?: string | null;
      threads_oauth_configured?: boolean;
      threads_redirect_uri?: string | null;
      linkedin_oauth_configured?: boolean;
      linkedin_redirect_uri?: string | null;
      linkedin_error?: string | null;
    }>("/admin/social/accounts");
  },
  threadsConnect() {
    return request<{ data: { authorize_url: string; redirect_uri: string } }>("/admin/social/threads/connect");
  },
  linkedinConnect() {
    return request<{ data: { authorize_url: string; redirect_uri: string } }>("/admin/social/linkedin/connect");
  },
  createSocialAccount(payload: Partial<SocialAccount> & { platform: string; name: string; access_token?: string }) {
    return request<Envelope<SocialAccount>>("/admin/social/accounts", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  updateSocialAccount(id: number, payload: Partial<SocialAccount> & { access_token?: string }) {
    return request<Envelope<SocialAccount>>(`/admin/social/accounts/${id}`, {
      method: "PUT",
      body: JSON.stringify(payload),
    });
  },
  toggleSocialAccount(id: number) {
    return request<Envelope<SocialAccount>>(`/admin/social/accounts/${id}/toggle`, { method: "POST" });
  },
  deleteSocialAccount(id: number) {
    return request<Envelope<null>>(`/admin/social/accounts/${id}`, { method: "DELETE" });
  },
  socialPosts(params?: { status?: string; page?: number; from?: string; to?: string; per_page?: number; account_id?: number; placement?: string }) {
    return request<Paginated<SocialPost>>(
      `/admin/social/posts${queryString({
        status: params?.status,
        page: params?.page,
        from: params?.from,
        to: params?.to,
        per_page: params?.per_page,
        account_id: params?.account_id,
        placement: params?.placement,
      })}`,
    );
  },
  socialPost(id: number) {
    return request<Envelope<SocialPost>>(`/admin/social/posts/${id}`);
  },
  createSocialPost(form: FormData, onProgress?: (progress: UploadProgress) => void) {
    return submitForm<Envelope<SocialPost>>("/admin/social/posts", "POST", form, onProgress);
  },
  updateSocialPost(id: number, form: FormData, onProgress?: (progress: UploadProgress) => void) {
    return submitForm<Envelope<SocialPost>>(`/admin/social/posts/${id}`, "PUT", form, onProgress);
  },
  deleteSocialPost(id: number) {
    return request<Envelope<null>>(`/admin/social/posts/${id}`, { method: "DELETE" });
  },
  approveSocialPost(id: number) {
    return request<Envelope<SocialPost>>(`/admin/social/posts/${id}/approve`, { method: "POST" });
  },
  publishSocialPost(id: number) {
    return request<Envelope<SocialPost>>(`/admin/social/posts/${id}/publish`, { method: "POST" });
  },
  socialInbox(params?: { kind?: string; account_id?: number; page?: number }) {
    return request<Paginated<SocialInboxItem>>(
      `/admin/social/inbox${queryString({
        kind: params?.kind,
        account_id: params?.account_id,
        page: params?.page,
      })}`,
    );
  },
  replySocialInbox(id: number, body: string) {
    return request<Envelope<SocialInboxItem>>(`/admin/social/inbox/${id}/reply`, {
      method: "POST",
      body: JSON.stringify({ body }),
    });
  },
  syncSocialInbox() {
    return request<Envelope<{ imported: number }>>("/admin/social/inbox/sync", { method: "POST" });
  },
  socialStaff() {
    return request<{ data: SocialStaff[] }>("/admin/social/staff");
  },
  updateSocialStaff(id: number, social_permissions: SocialAbility[] | null) {
    return request<Envelope<SocialStaff>>(`/admin/social/staff/${id}`, {
      method: "PUT",
      body: JSON.stringify({ social_permissions }),
    });
  },
};
