import app from 'flarum/forum/app';
import { override, extend } from 'flarum/common/extend';
import PostComponent from 'flarum/forum/components/Post';
import NotificationGrid from 'flarum/forum/components/NotificationGrid';
import ContentViolationNotification from './notifications/ContentViolationNotification';

export { default as extend } from './extend';

app.initializers.add('ghostchu-openai-content-audit', () => {
  console.log('[ghostchu/openai-content-audit] Hello, forum!');

  // Register notification components
  app.notificationComponents.contentViolation = ContentViolationNotification;

  // Add notification settings to NotificationGrid
  extend(NotificationGrid.prototype, 'notificationTypes', function (items) {
    items.add('contentViolation', {
      name: 'contentViolation',
      icon: 'fas fa-exclamation-triangle',
      label: app.translator.trans('ghostchu-openai-content-audit.forum.settings.notify_content_violation_label'),
    });
  });

  // Override flagReason to display openai-audit flag details
  override(PostComponent.prototype, 'flagReason', function (original, flag) {
    if (flag.type() === 'openai-audit') {
      const reason = flag.reason();
      const detail = flag.reasonDetail();

      return [
        reason || app.translator.trans('ghostchu-openai-content-audit.forum.flag.default_reason'),
        detail && m('span', { className: 'Post-flagged-detail' }, detail),
      ];
    }

    return original(flag);
  });
});
