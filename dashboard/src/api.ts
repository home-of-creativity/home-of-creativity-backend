const API_URL = import.meta.env.VITE_API_URL ?? "http://127.0.0.1:8000/api";
const TOKEN_KEY = "hoc-staff-token";

export function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

export type User = {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
};

export type Client = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  telegram_user_id: string | null;
  odoo_partner_id?: string | null;
  odoo_url?: string | null;
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
  gemini_status?: string | null;
  gemini_error?: string | null;
  quotation_amount?: string | null;
  quotation_notes?: string | null;
  briefs?: Brief[];
  files?: RequestFile[];
  quotations?: Quotation[];
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

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers = new Headers(init?.headers);
  headers.set("Accept", "application/json");
  if (init?.body) headers.set("Content-Type", "application/json");
  const token = getToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const response = await fetch(`${API_URL}${path}`, { ...init, headers });
  if (response.status === 401) {
    setToken(null);
  }
  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.message ?? `HTTP ${response.status}`);
  }
  return response.json() as Promise<T>;
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
    return request<Envelope<{ clients: number; requests: number; by_status: Record<string, number> }>>(
      "/admin/overview",
    );
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
  sendQuotation(id: number, amount: number, notes?: string) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/quotation`, {
      method: "POST",
      body: JSON.stringify({ amount, notes }),
    });
  },
  confirmPayment(id: number, payment_method: "receipt" | "cash") {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/confirm-payment`, {
      method: "POST",
      body: JSON.stringify({ payment_method }),
    });
  },
  retryGemini(id: number) {
    return request<Envelope<ServiceRequest>>(`/admin/requests/${id}/retry-gemini`, { method: "POST" });
  },
  async receiptBlob(requestId: number, fileId: number) {
    const headers = new Headers({ Accept: "application/octet-stream" });
    const token = getToken();
    if (token) headers.set("Authorization", `Bearer ${token}`);
    const response = await fetch(`${API_URL}/admin/requests/${requestId}/files/${fileId}/receipt`, { headers });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.blob();
  },
  clients(page = 1) {
    return request<Paginated<Client>>(`/admin/clients${queryString({ page })}`);
  },
  createClient(payload: { name: string; email?: string; phone?: string; telegram_user_id?: string }) {
    return request<Envelope<Client>>("/admin/clients", {
      method: "POST",
      body: JSON.stringify(payload),
    });
  },
  odooStatus() {
    return request<Envelope<{ configured: boolean; url: string | null }>>("/admin/odoo/status");
  },
  syncOdooPartners() {
    return request<Envelope<{ synced: number; created: number; updated: number }>>("/admin/odoo/sync-partners", {
      method: "POST",
    });
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
};
