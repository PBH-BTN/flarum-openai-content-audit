import app from 'flarum/admin/app';

export { default as extend } from './extend';

app.initializers.add('ghostchu-openai-content-audit', () => {
  // Register settings for the extension
  app.registry.for('ghostchu-openai-content-audit');
  
  // API Configuration
  app.registry.registerSetting({
    type: 'text',
    setting: 'ghostchu.openaicontentaudit.api_endpoint',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_endpoint'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_endpoint_help'),
    default: 'https://api.openai.com/v1'
  });
  
  app.registry.registerSetting({
    type: 'text',
    setting: 'ghostchu.openaicontentaudit.api_key',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_key'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_key_help'),
  });
  
  app.registry.registerSetting({
    type: 'text',
    setting: 'ghostchu.openaicontentaudit.model',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.model'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.model_help'),
    default: 'gpt-4o'
  });
  
  app.registry.registerSetting({
    type: 'number',
    setting: 'ghostchu.openaicontentaudit.temperature',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.temperature'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.temperature_help'),
    min: 0,
    max: 2,
    step: 0.1,
    default: '0.3'
  });
  
  app.registry.registerSetting({
    type: 'textarea',
    setting: 'ghostchu.openaicontentaudit.system_prompt',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_prompt'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_prompt_help'),
  });
  
  app.registry.registerSetting({
    type: 'number',
    setting: 'ghostchu.openaicontentaudit.confidence_threshold',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.confidence_threshold'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.confidence_threshold_help'),
    min: 0,
    max: 1,
    step: 0.05,
    default: '0.7'
  });
  
  app.registry.registerSetting({
    type: 'switch',
    setting: 'ghostchu.openaicontentaudit.pre_approve_enabled',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.pre_approve_enabled'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.pre_approve_enabled_help'),
  });
  
  app.registry.registerSetting({
    type: 'switch',
    setting: 'ghostchu.openaicontentaudit.download_images',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.download_images'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.download_images_help'),
  });
  
  app.registry.registerSetting({
    type: 'number',
    setting: 'ghostchu.openaicontentaudit.suspend_days',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.suspend_days'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.suspend_days_help'),
    min: 1,
    default: '7'
  });
  
  app.registry.registerSetting({
    type: 'text',
    setting: 'ghostchu.openaicontentaudit.default_display_name',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_display_name'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_display_name_help'),
    default: 'User'
  });
  
  app.registry.registerSetting({
    type: 'text',
    setting: 'ghostchu.openaicontentaudit.default_bio',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_bio'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_bio_help'),
    default: ''
  });
  
  // Message Notification Settings
  app.registry.registerSetting({
    type: 'switch',
    setting: 'ghostchu.openaicontentaudit.send_message_notification',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.send_message_notification'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.send_message_notification_help'),
    default: 'true'
  });
  
  app.registry.registerSetting({
    type: 'number',
    setting: 'ghostchu.openaicontentaudit.system_user_id',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_user_id'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_user_id_help'),
    min: 1,
    default: '1'
  });
  
  app.registry.registerSetting({
    type: 'textarea',
    setting: 'ghostchu.openaicontentaudit.message_template',
    label: app.translator.trans('ghostchu-openai-content-audit.admin.settings.message_template'),
    help: app.translator.trans('ghostchu-openai-content-audit.admin.settings.message_template_help'),
  });
  
  // Register permissions
  app.registry.registerPermission(
    {
      icon: 'fas fa-shield-alt',
      label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.view_audit_logs'),
      permission: 'ghostchu-openai-content-audit.viewAuditLogs',
    },
    'moderate',
    100
  );
  
  app.registry.registerPermission(
    {
      icon: 'fas fa-shield-alt',
      label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.view_full_audit_logs'),
      permission: 'ghostchu-openai-content-audit.viewFullAuditLogs',
    },
    'moderate',
    95
  );
  
  app.registry.registerPermission(
    {
      icon: 'fas fa-redo',
      label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.retry_audit'),
      permission: 'ghostchu-openai-content-audit.retryAudit',
    },
    'moderate',
    90
  );
  
  app.registry.registerPermission(
    {
      icon: 'fas fa-user-check',
      label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.bypass_audit'),
      permission: 'ghostchu-openai-content-audit.bypassAudit',
    },
    'moderate',
    85
  );
  
  app.registry.registerPermission(
    {
      icon: 'fas fa-user-check',
      label: app.translator.trans('ghostchu-openai-content-audit.admin.permissions.bypass_pre_approve'),
      permission: 'ghostchu-openai-content-audit.bypassPreApprove',
    },
    'start',
    100
  );
});
