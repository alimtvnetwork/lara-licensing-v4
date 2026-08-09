/**
 * Identity metadata for a demo user.
 * Mirrored from backend/database/seeders/DemoLoginSeeder.php and E2EFixturesSeeder.php.
 */
export interface DemoIdentity {
  readonly id: string;
  readonly label: string;
  readonly email: string;
  readonly role: "admin" | "reseller" | "portal";
  readonly description: string;
}

export const DEMO_IDENTITIES: ReadonlyArray<DemoIdentity> = [
  {
    id: "admin",
    label: "Super Admin",
    email: "admin@demo.lara.test",
    role: "admin",
    description: "Full platform control, audit logs, and settings.",
  },
  {
    id: "reseller",
    label: "Reseller",
    email: "reseller@demo.lara.test",
    role: "reseller",
    description: "Manage licenses, view quotas, and issued serials.",
  },
  {
    id: "portal",
    label: "End User",
    email: "user@licensingportal.local",
    role: "portal",
    description: "Product downloads and personal license lookup.",
  },
];
