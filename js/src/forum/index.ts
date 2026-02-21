import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import PostComponent from 'flarum/forum/components/Post';

export { default as extend } from './extend';

app.initializers.add('ghostchu-openai-content-audit', () => {
  console.log('[ghostchu/openai-content-audit] Hello, forum!');

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
