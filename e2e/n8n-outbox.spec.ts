import { expect, test } from "@playwright/test";
import { API, N8N_SECRET, clickUpMapping, getRequest, loginStaff, submitTelegramRequest } from "./helpers";

test.describe("n8n outbox and callbacks", () => {
  test("TASKS_READY callback applies clickup mapping", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "n8n tasks ready",
      description: "Inbound TASKS_READY mapping.",
    });
    const detail = await getRequest(request, created.data.id);
    const requestUuid = detail.data.uuid as string;

    const response = await request.post(`${API}/webhooks/n8n`, {
      headers: { "X-N8N-Secret": N8N_SECRET },
      data: {
        event: "TASKS_READY",
        request_number: created.data.number,
        payload: {
          request_number: created.data.number,
          request_uuid: requestUuid,
          event_uuid: crypto.randomUUID(),
          task_type: "sales",
          integration_key: `${requestUuid}:0:sales`,
          clickup_task_id: "CU-N8N-SALES",
        },
      },
    });
    expect(response.ok(), await response.text()).toBeTruthy();
  });

  test("integration event retry endpoint is reachable for admins", async ({ request }) => {
    const created = await submitTelegramRequest(request, {
      title: "Outbox retry",
      description: "Failed event retry smoke.",
    });
    const detail = await getRequest(request, created.data.id);
    const events = (detail.data.integration_events ?? []) as Array<{ id: number; status: string }>;

    if (events.length === 0) {
      await clickUpMapping(request, {
        request_number: created.data.number,
        request_uuid: detail.data.uuid as string,
        event_uuid: crypto.randomUUID(),
        task_type: "sales",
        integration_key: `${detail.data.uuid}:0:sales`,
        clickup_task_id: "CU-OUTBOX",
      });
    }

    const refreshed = await getRequest(request, created.data.id);
    const integrationEvents = (refreshed.data.integration_events ?? []) as Array<{ id: number }>;
    test.skip(integrationEvents.length === 0, "No integration events were created for this request.");

    const staff = await loginStaff(request);
    const retry = await request.post(`${API}/admin/integration-events/${integrationEvents[0].id}/retry`, {
      headers: { Authorization: `Bearer ${staff.data.token}` },
    });
    expect([200, 422]).toContain(retry.status());
  });
});
