<?php

namespace App\Tenancy;

/**
 * Tenant permissions. Each case is registered as a Gate ability and resolved
 * against the role of the current tenant membership.
 *
 * | Permission            | owner | admin | marketer | viewer |
 * |-----------------------|-------|-------|----------|--------|
 * | tenant.view           |   x   |   x   |    x     |   x    |
 * | tenant.settings.update|   x   |   x   |          |        |
 * | tenant.sending_mode   |   x   |       |          |        |
 * | members.view          |   x   |   x   |    x     |        |
 * | members.manage        |   x   |   x*  |          |        |
 * | integrations.view     |   x   |   x   |    x     |        |
 * | integrations.manage   |   x   |   x   |          |        |
 * | customers.view        |   x   |   x   |    x     |   x    |
 * | audit.view            |   x   |   x   |          |        |
 *
 * (*) admins cannot grant or change the owner role.
 */
enum Permission: string
{
    case TenantView = 'tenant.view';
    case SettingsUpdate = 'tenant.settings.update';
    case SendingModeUpdate = 'tenant.sending_mode';
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';
    case CustomersView = 'customers.view';
    case AuditView = 'audit.view';
    case AutomationsView = 'automations.view';
    case AutomationsManage = 'automations.manage';
    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';
    // ready / schedule / pause / cancel / archive
    case CampaignsPublish = 'campaigns.publish';
    case ContentManage = 'content.manage';
    case ContentPublish = 'content.publish';
    case FormsManage = 'forms.manage';
    // Aggregate lead reports only (no addresses).
    case LeadsView = 'leads.view';
}
