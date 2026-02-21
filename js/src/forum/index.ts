import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserControls from 'flarum/forum/utils/UserControls';
import PostControls from 'flarum/forum/utils/PostControls';
import Button from 'flarum/common/components/Button';
import User from 'flarum/common/models/User';
import Post from 'flarum/common/models/Post';
import ItemList from 'flarum/common/utils/ItemList';

export { default as extend } from './extend';

app.initializers.add('ghostchu-openai-content-audit', () => {
  console.log('[ghostchu/openai-content-audit] Hello, forum!');

  // Add manual audit options to user controls
  extend(UserControls, 'moderationControls', function (items: ItemList, user: User) {
    if (!app.session.user || !app.forum.attribute('canManualAudit')) {
      return;
    }

    items.add(
      'audit-username',
      Button.component(
        {
          icon: 'fas fa-robot',
          onclick: () => {
            if (confirm(app.translator.trans('ghostchu-openai-content-audit.forum.confirm_audit_username'))) {
              manualAudit('user_profile_username', Number(user.id()), ['username', 'display_name']);
            }
          },
        },
        app.translator.trans('ghostchu-openai-content-audit.forum.audit_username')
      )
    );

    if (user.avatarUrl()) {
      items.add(
        'audit-avatar',
        Button.component(
          {
            icon: 'fas fa-robot',
            onclick: () => {
              if (confirm(app.translator.trans('ghostchu-openai-content-audit.forum.confirm_audit_avatar'))) {
                manualAudit('user_profile_avatar', Number(user.id()), ['avatar_url']);
              }
            },
          },
          app.translator.trans('ghostchu-openai-content-audit.forum.audit_avatar')
        )
      );
    }

    // Check if cover exists (sycho/flarum-profile-cover)
    const userCover = (user as any).cover;
    if (userCover && userCover()) {
      items.add(
        'audit-cover',
        Button.component(
          {
            icon: 'fas fa-robot',
            onclick: () => {
              if (confirm(app.translator.trans('ghostchu-openai-content-audit.forum.confirm_audit_cover'))) {
                manualAudit('user_profile_cover', Number(user.id()), ['cover']);
              }
            },
          },
          app.translator.trans('ghostchu-openai-content-audit.forum.audit_cover')
        )
      );
    }
  });

  // Add manual audit options to post controls
  extend(PostControls, 'moderationControls', function (items: ItemList, post: Post) {
    if (!app.session.user || !app.forum.attribute('canManualAudit')) {
      return;
    }

    items.add(
      'audit-post',
      Button.component(
        {
          icon: 'fas fa-robot',
          onclick: () => {
            if (confirm(app.translator.trans('ghostchu-openai-content-audit.forum.confirm_audit_post'))) {
              const contentType = post.number() === 1 ? 'discussion' : 'post';
              const contentId = contentType === 'discussion' ? Number(post.discussion().id()) : Number(post.id());
              manualAudit(contentType, contentId, []);
            }
          },
        },
        app.translator.trans('ghostchu-openai-content-audit.forum.audit_post')
      )
    );
  });

  /**
   * Queue a manual audit request.
   */
  function manualAudit(contentType: string, contentId: number, fields: string[]) {
    app.alerts.clear();
    
    app.request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/manual-audit',
      body: {
        contentType,
        contentId,
        fields,
      },
    }).then(
      () => {
        app.alerts.show(
          { type: 'success' },
          app.translator.trans('ghostchu-openai-content-audit.forum.audit_queued')
        );
      },
      (error: any) => {
        app.alerts.show(
          { type: 'error' },
          app.translator.trans('ghostchu-openai-content-audit.forum.audit_failed') + ': ' + (error.message || error)
        );
      }
    );
  }
});
