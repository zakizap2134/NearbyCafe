const root = `${import.meta.env.BASE_URL}api/index.php`;
let hostEpoch;
export const getHostEpoch = () => hostEpoch;
export class ApiError extends Error {
  constructor(message, status = 0, code = "NETWORK", reference = "") {
    super(message);
    this.status = status;
    this.code = code;
    this.reference = reference;
  }
}
export async function api(
  route,
  { token, body, signal, query = {}, timeout = 12000 } = {},
) {
  let response;
  try {
    response = await fetch(
      `${root}?${new URLSearchParams({ ...query, route })}`,
      {
        method: body === undefined ? "GET" : "POST",
        cache: "no-store",
        signal: signal ?? AbortSignal.timeout(timeout),
        headers: {
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          ...(body === undefined ? {} : { "Content-Type": "application/json" }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      },
    );
  } catch (error) {
    if (error.name === "AbortError") throw error;
    throw new ApiError(
      "Cannot reach the local host. Check that the host and shop Wi-Fi are running.",
    );
  }
  const result = await response.json().catch(() => null);
  if (!result)
    throw new ApiError(
      "The host returned an unreadable response. Ask the owner to check the server.",
      response.status,
      "INVALID_RESPONSE",
    );
  if (!response.ok || !result.ok)
    throw new ApiError(
      result.error?.message ?? "Request failed.",
      response.status,
      result.error?.code,
      result.error?.reference,
    );
  if (route === "health") hostEpoch = result.data.epoch;
  return result.data;
}
const key = "venuevue.rebuild.session";
export function readToken() {
  try {
    return sessionStorage.getItem(key) || "";
  } catch {
    return "";
  }
}
export function storeToken(token) {
  try {
    if (token) sessionStorage.setItem(key, token);
    else sessionStorage.removeItem(key);
  } catch {
    /* Current tab still works; refresh requires sign-in when storage is blocked. */
  }
}
