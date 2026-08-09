import { createFileRoute } from "@tanstack/react-router";

import { AdminShell } from "../../components/admin/admin-shell";

export const Route = createFileRoute("/_authenticated/admin")({
  head: () => ({
    meta: [
      { title: "Admin Console | Licensing Portal" },
      { name: "description", content: "Licensing Portal administrative console." },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: AdminShell,
});
