import { describe, it, expect, beforeEach } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import api from "../lib/api";

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("API client CSRF retry", () => {
  beforeEach(() => {
    server.resetHandlers();
  });

  it("refreshes CSRF cookie once and retries when a mutation returns 419", async () => {
    let csrfCalls = 0;
    let postCalls = 0;

    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => {
        csrfCalls += 1;
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${API}/examiner/classes`, () => {
        postCalls += 1;
        if (postCalls === 1) {
          return HttpResponse.json({ message: "CSRF token mismatch." }, { status: 419 });
        }
        return HttpResponse.json({ message: "Unauthenticated." }, { status: 401 });
      }),
    );

    await expect(api.post("/examiner/classes", { name: "Test" })).rejects.toMatchObject({
      response: { status: 401 },
    });
    expect(csrfCalls).toBe(1);
    expect(postCalls).toBe(2);
  });
});
