<?php

namespace App\Audit;

enum AuditAction: string
{
    case TenantCreated = 'tenant.created';
    case TenantSettingsUpdated = 'tenant.settings_updated';
    case MemberRoleChanged = 'member.role_changed';
    case IntegrationCreated = 'integration.created';
    case IntegrationSecretCreated = 'integration.secret_created';
    case IntegrationSecretChanged = 'integration.secret_changed';
    case IntegrationVerified = 'integration.verified';
    case IntegrationDeleted = 'integration.deleted';
    case SecretDecrypted = 'secret.decrypted';
    case DataKeyCreated = 'vault.data_key_created';
    case DataKeyRotated = 'vault.data_key_rotated';
    case DataKeysRewrapped = 'vault.data_keys_rewrapped';
    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case PlatformLogin = 'platform.login';
    case PlatformLoginFailed = 'platform.login_failed';
    case PlatformLogout = 'platform.logout';
    case PlatformTwoFactorEnabled = 'platform.two_factor_enabled';
    case BreakGlassGranted = 'platform.break_glass_granted';
    case SuppressionCreated = 'consent.suppression_created';
    case SuppressionLifted = 'consent.suppression_lifted';
    case IntegrationScopeChanged = 'integration.scope_changed';
    case IntegrationSyncStarted = 'integration.sync_started';
    case IntegrationSyncCompleted = 'integration.sync_completed';
    case IntegrationSyncFailed = 'integration.sync_failed';
    case PushTestRecipientAdded = 'push.test_recipient_added';
    case PushTestRecipientRemoved = 'push.test_recipient_removed';
    case PushSendRequested = 'push.send_requested';
    case PushSent = 'push.sent';
    case PushNotSent = 'push.not_sent';
    case PushFailed = 'push.failed';
    case PushBlocked = 'push.blocked';
    case PushIdentityMappingChanged = 'push.identity_mapping_changed';
    case EmailTestRecipientAdded = 'email.test_recipient_added';
    case EmailTestRecipientRemoved = 'email.test_recipient_removed';
    case EmailSenderIdentitySaved = 'email.sender_identity_saved';
    case EmailSendRequested = 'email.send_requested';
    case EmailSent = 'email.sent';
    case EmailBlocked = 'email.blocked';
    case EmailDeferred = 'email.deferred';
    case EmailFailed = 'email.failed';
    case EmailAccountChecked = 'email.account_checked';
    case EmailUnsubscribed = 'email.unsubscribed';
    case EmailTemplateVersionCreated = 'email.template_version_created';
    case EmailAudienceSnapshotCreated = 'email.audience_snapshot_created';
    case SegmentCreated = 'segment.created';
    case SegmentSnapshotCreated = 'segment.snapshot_created';
    case AutomationCreated = 'automation.created';
    case AutomationChanged = 'automation.changed';
    case AutomationPublished = 'automation.published';
    case AutomationPaused = 'automation.paused';
    case AutomationResumed = 'automation.resumed';
    case AutomationArchived = 'automation.archived';
    case AutomationDryRun = 'automation.dry_run';
    case AutomationRunCancelled = 'automation.run_cancelled';
}
