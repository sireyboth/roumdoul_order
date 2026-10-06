"use client";

import { useEffect, useRef, useState } from "react";
import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { loadSession } from "./staff";

/**
 * Instant updates through Laravel Reverb. Events carry no data, only "something
 * changed", so a screen simply re-fetches through its normal API. Without a key
 * (or when Reverb is down) nothing happens and the screens keep polling.
 */

const KEY = process.env.NEXT_PUBLIC_REVERB_KEY ?? "";

let echo: Echo<"reverb"> | null = null;

function getEcho(): Echo<"reverb"> | null {
  if (typeof window === "undefined" || !KEY) return null;
  if (echo) return echo;

  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME || "http";
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT || 8080);

  echo = new Echo({
    broadcaster: "reverb",
    key: KEY,
    Pusher,
    // Same machine the page came from, so phones on the shop Wi-Fi reach it too.
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST || window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    // Private (staff) channels: sign in through this site's proxy with the staff token.
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        fetch("/api/staff/broadcasting/auth", {
          method: "POST",
          headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${loadSession()?.token ?? ""}` },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then(async (res) => (res.ok ? callback(null, await res.json()) : callback(new Error(`auth ${res.status}`), null)))
          .catch((e: Error) => callback(e, null));
      },
    }),
  });

  return echo;
}

/**
 * Calls `onChange` whenever the channel says something changed. Returns true while
 * connected, so callers can poll slowly instead of every few seconds.
 * `channel` is "branch.12" (private, staff) or a table channel from the session API (public).
 */
export function useLive(channel: string | null, isPrivate: boolean, onChange: () => void): boolean {
  const [connected, setConnected] = useState(false);
  const latest = useRef(onChange);

  useEffect(() => {
    latest.current = onChange;
  }, [onChange]);

  useEffect(() => {
    const client = channel ? getEcho() : null;
    if (!client || !channel) return;

    const subscription = isPrivate ? client.private(channel) : client.channel(channel);
    subscription.listen(".changed", () => latest.current());

    const connection = client.connector.pusher.connection;
    const update = () => setConnected(connection.state === "connected");
    connection.bind("state_change", update);
    const first = window.setTimeout(update, 0);

    return () => {
      window.clearTimeout(first);
      connection.unbind("state_change", update);
      client.leave(channel);
    };
  }, [channel, isPrivate]);

  return connected;
}
