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
}
