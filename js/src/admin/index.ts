import app from 'flarum/admin/app';
import AuditSettingsPage from './components/AuditSettingsPage';

export { default as extend } from './extend';

app.initializers.add('ghostchu-openai-content-audit', () => {
  // Register custom settings page
  app.extensionData
    .for('ghostchu-openai-content-audit')
    .registerPage(AuditSettingsPage)
    
    // Register permissions
    .registerPermission(
      {
        icon: 'fas fa-shield-alt',
        label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.view_audit_logs'),
        permission: 'ghostchu-openai-content-audit.viewAuditLogs',
      },
      'moderate',
      100
    )
    
    .registerPermission(
      {
        icon: 'fas fa-shield-alt',
        label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.view_full_audit_logs'),
        permission: 'ghostchu-openai-content-audit.viewFullAuditLogs',
      },
      'moderate',
      95
    )
    
    .registerPermission(
      {
        icon: 'fas fa-redo',
        label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.retry_audit'),
        permission: 'ghostchu-openai-content-audit.retryAudit',
      },
      'moderate',
      90
    )
    
    .registerPermission(
      {
        icon: 'fas fa-user-check',
        label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.bypass_audit'),
        permission: 'ghostchu-openai-content-audit.bypassAudit',
      },
      'moderate',
      85
    )
    
    .registerPermission(
      {
        icon: 'fas fa-user-check',
        label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.bypass_pre_approve'),
        permission: 'ghostchu-openai-content-audit.bypassPreApprove',
      },
      'start',
      100
    );
});

