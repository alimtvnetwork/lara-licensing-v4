<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Closed-set of Backup/Restore feature flag IDs.
 *
 * Normative source: spec/26-backup-restore/25-migration-and-rollout.md
 * §"Feature Flags (closed set)". Adding a value here requires a matching
 * seed row in the `FeatureFlags` migration and a spec version bump.
 *
 * No magic strings: consumers reference `BrFlagId::ExportEnabled->value`
 * rather than the literal 'br.export.enabled'.
 */
enum BrFlagId: string
{
    case ExportEnabled          = 'br.export.enabled';
    case ImportEnabled          = 'br.import.enabled';
    case SnapshotsEnabled       = 'br.snapshots.enabled';
    case RolesUiEnabled         = 'br.roles-ui.enabled';
    case AuditChainVerifyEnabled = 'br.audit-chain-verify.enabled';
    case AuditPseudonymiseEnabled = 'br.audit-pseudonymise.enabled';
    case KillSwitch             = 'br.kill-switch';
}
