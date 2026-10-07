/**
 * The phone's location, for branches that only take QR orders from inside the shop.
 * The server does the real check; this only gets the point (and remembers it for a
 * couple of minutes so a second order doesn't ask again).
 */

export type PhoneLocation = { lat: number; lng: number; accuracy: number };

export type LocationProblem = "denied" | "unavailable" | "timeout" | "insecure" | "unsupported";

export class LocationError extends Error {
  constructor(public problem: LocationProblem) {
    super(problem);
  }
}

let last: { at: number; value: PhoneLocation } | null = null;
const FRESH_MS = 2 * 60_000;

export function getPhoneLocation(): Promise<PhoneLocation> {
  if (last && Date.now() - last.at < FRESH_MS) return Promise.resolve(last.value);

  // Browsers only share location on https (and localhost).
  if (typeof window !== "undefined" && !window.isSecureContext) return Promise.reject(new LocationError("insecure"));
  if (typeof navigator === "undefined" || !navigator.geolocation) return Promise.reject(new LocationError("unsupported"));

  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const value = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: Math.round(pos.coords.accuracy) };
        last = { at: Date.now(), value };
        resolve(value);
      },
      (err) => {
        reject(new LocationError(err.code === err.PERMISSION_DENIED ? "denied" : err.code === err.TIMEOUT ? "timeout" : "unavailable"));
      },
      { enableHighAccuracy: true, timeout: 12_000, maximumAge: 60_000 },
    );
  });
}

/** Forget the remembered point (e.g. after the server said "too far", ask the phone again). */
export function forgetPhoneLocation() {
  last = null;
}
