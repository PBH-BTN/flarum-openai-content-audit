import app from 'flarum/common/app';

app.initializers.add('ghostchu-openai-content-audit-common', () => {
  console.log('[ghostchu/openai-content-audit] Hello, forum and admin!');
});
