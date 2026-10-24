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
}
