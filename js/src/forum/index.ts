import app from 'flarum/forum/app';

export { default as extend } from './extend';

app.initializers.add('ghostchu-openai-content-audit', () => {
  console.log('[ghostchu/openai-content-audit] Hello, forum!');
});
