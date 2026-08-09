import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { Alert, AlertDescription, AlertTitle } from "../../components/ui/alert";
import { Badge } from "../../components/ui/badge";
import { Button } from "../../components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "../../components/ui/card";
import { EmptyState } from "../../components/ui/empty-state";
import { Skeleton, SkeletonList } from "../../components/ui/skeleton";
import { StatCard } from "../../components/ui/stat-card";
import { Timeline } from "../../components/ui/timeline";

/**
 * Plan 09 step 96. Admin-only design gallery route showcasing every
 * fluid UI primitive so future refits have a single canonical reference
 * instead of divergently re-inventing variant grammar.
 *
 * Feature-gated by `VITE_LARA_DESIGN_GALLERY=1` at build time. When the
 * flag is absent the route renders a disabled-state EmptyState rather
 * than throwing, so a production bundle cannot accidentally expose the
 * gallery without an operator flip.
 *
 * URL is `/admin/design` (not `/admin/_design` from the plan) because
 * underscore-prefixed segments in TanStack file-based routing are
 * pathless layouts, not leaf pages.
 */
export const Route = createFileRoute("/_authenticated/admin/design")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Design Gallery | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: DesignGalleryPage,
});

const DESIGN_GALLERY_FLAG = "1";

export function isDesignGalleryEnabled(): boolean {
  return import.meta.env.VITE_LARA_DESIGN_GALLERY === DESIGN_GALLERY_FLAG;
}

function DesignGalleryPage() {
  if (isDesignGalleryEnabled() === false) {
    return (
      <>
        <PageHeader
          title="Design gallery"
          description="Fluid UI primitive showcase (Plan 09 step 96)."
        />
        <div className="mt-6" data-testid="design-gallery-disabled">
          <EmptyState
            preset="box"
            headline="Design gallery disabled"
            body="Set VITE_LARA_DESIGN_GALLERY=1 at build time to enable this route."
          />
        </div>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Design gallery"
        description="Every fluid UI primitive in one place. Ubuntu headings, Poppins body."
      />
      <div className="mt-6 flex flex-col gap-10" data-testid="design-gallery-enabled">
        <ButtonsSection />
        <BadgesSection />
        <CardsSection />
        <AlertsSection />
        <SkeletonsSection />
        <EmptyStateSection />
        <StatCardsSection />
        <TimelineSection />
      </div>
    </>
  );
}

function GallerySection(props: {
  id: string;
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section aria-labelledby={`gallery-${props.id}`} className="flex flex-col gap-3">
      <div>
        <h2 id={`gallery-${props.id}`} className="text-xl font-semibold">
          {props.title}
        </h2>
        {props.description ? (
          <p className="text-sm text-muted-foreground">{props.description}</p>
        ) : null}
      </div>
      <div className="rounded-lg border border-border bg-card p-5">{props.children}</div>
    </section>
  );
}

function ButtonsSection() {
  return (
    <GallerySection id="buttons" title="Buttons" description="Variant + intent grammar.">
      <div className="flex flex-wrap gap-3">
        <Button intent="primary">Primary</Button>
        <Button intent="neutral">Neutral</Button>
        <Button intent="destructive">Destructive</Button>
        <Button variant="outline" intent="neutral">
          Outline
        </Button>
        <Button variant="ghost" intent="neutral">
          Ghost
        </Button>
        <Button variant="link" intent="primary">
          Link
        </Button>
        <Button size="sm">Small</Button>
        <Button size="lg">Large</Button>
        <Button disabled>Disabled</Button>
      </div>
    </GallerySection>
  );
}

function BadgesSection() {
  return (
    <GallerySection id="badges" title="Badges" description="Status and lineage chips.">
      <div className="flex flex-wrap gap-2">
        <Badge>Default</Badge>
        <Badge intent="accent">Accent</Badge>
        <Badge intent="info">Info</Badge>
        <Badge intent="success">Success</Badge>
        <Badge intent="warning">Warning</Badge>
        <Badge intent="destructive">Destructive</Badge>
        <Badge intent="neutral">Neutral</Badge>
      </div>
    </GallerySection>
  );
}

function CardsSection() {
  return (
    <GallerySection id="cards" title="Cards" description="Surface primitive with header/content.">
      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Card title</CardTitle>
            <CardDescription>Short description slot.</CardDescription>
          </CardHeader>
          <CardContent>Body content.</CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Second card</CardTitle>
            <CardDescription>For comparison.</CardDescription>
          </CardHeader>
          <CardContent>More body content.</CardContent>
        </Card>
      </div>
    </GallerySection>
  );
}

function AlertsSection() {
  return (
    <GallerySection id="alerts" title="Alerts" description="Inline status surfaces.">
      <div className="flex flex-col gap-3">
        <Alert>
          <AlertTitle>Neutral alert</AlertTitle>
          <AlertDescription>Informational context.</AlertDescription>
        </Alert>
        <Alert variant="destructive">
          <AlertTitle>Destructive alert</AlertTitle>
          <AlertDescription>Something needs attention.</AlertDescription>
        </Alert>
      </div>
    </GallerySection>
  );
}

function SkeletonsSection() {
  return (
    <GallerySection id="skeleton" title="Skeleton" description="Loading scaffolds.">
      <div className="flex flex-col gap-4">
        <Skeleton variant="title" />
        <Skeleton variant="text" />
        <SkeletonList rows={3} />
      </div>
    </GallerySection>
  );
}

function EmptyStateSection() {
  return (
    <GallerySection id="empty" title="Empty state" description="Presets with illustrations.">
      <div className="grid gap-4 md:grid-cols-2">
        <EmptyState preset="box" headline="Nothing here yet" body="Boxed preset." />
        <EmptyState preset="search" headline="No matches" body="Search preset." />
      </div>
    </GallerySection>
  );
}

function StatCardsSection() {
  return (
    <GallerySection id="stat" title="Stat cards" description="KPI tiles: ready, loading, error.">
      <div className="grid gap-4 md:grid-cols-3">
        <StatCard
          state="ready"
          label="Licenses"
          value="1,284"
          delta={{ direction: "up", label: "+3.2%" }}
        />
        <StatCard state="loading" label="Resellers" value="" />
        <StatCard state="error" label="Sessions" value="" errorMessage="Shard unavailable" />
      </div>
    </GallerySection>
  );
}

function TimelineSection() {
  return (
    <GallerySection id="timeline" title="Timeline" description="Audit + activity history.">
      <Timeline
        ariaLabel="Sample timeline"
        entries={[
          {
            id: "1",
            tone: "success",
            title: "License issued",
            description: "LIC-000123 to Acme Reseller",
            timestamp: "2026-07-19T09:00:00Z",
          },
          {
            id: "2",
            tone: "warning",
            title: "Quota near limit",
            description: "Standard tier at 92% capacity",
            timestamp: "2026-07-19T09:15:00Z",
          },
          {
            id: "3",
            tone: "danger",
            title: "License revoked",
            description: "Manual revocation by SuperAdmin",
            timestamp: "2026-07-19T10:02:00Z",
          },
        ]}
      />
    </GallerySection>
  );
}
