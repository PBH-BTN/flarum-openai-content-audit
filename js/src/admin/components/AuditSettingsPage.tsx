import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

export default class AuditSettingsPage extends ExtensionPage {
  oninit(vnode: any) {
    super.oninit(vnode);
  }

  content() {
    return (
      <div className="OpenAIContentAuditSettingsPage">
        <div className="container">
          <div className="OpenAIContentAuditSettingsPage-section">
            <h3>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_section')}</h3>
            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_endpoint')}</label>
              <input
                className="FormControl"
                type="text"
                bidi={this.setting('ghostchu.openaicontentaudit.api_endpoint')}
                placeholder="https://api.openai.com/v1"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_endpoint_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_key')}</label>
              <input
                className="FormControl"
                type="password"
                bidi={this.setting('ghostchu.openaicontentaudit.api_key')}
                placeholder="sk-..."
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.api_key_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.model')}</label>
              <input
                className="FormControl"
                type="text"
                bidi={this.setting('ghostchu.openaicontentaudit.model')}
                placeholder="gpt-4o"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.model_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.temperature')}</label>
              <input
                className="FormControl"
                type="number"
                min="0"
                max="2"
                step="0.1"
                bidi={this.setting('ghostchu.openaicontentaudit.temperature')}
                placeholder="0.3"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.temperature_help')}
              </p>
            </div>
          </div>

          <div className="OpenAIContentAuditSettingsPage-section">
            <h3>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.audit_section')}</h3>
            
            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_prompt')}</label>
              <textarea
                className="FormControl"
                rows={10}
                bidi={this.setting('ghostchu.openaicontentaudit.system_prompt')}
                placeholder={app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_prompt_placeholder')}
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.system_prompt_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.confidence_threshold')}</label>
              <input
                className="FormControl"
                type="number"
                min="0"
                max="1"
                step="0.05"
                bidi={this.setting('ghostchu.openaicontentaudit.confidence_threshold')}
                placeholder="0.7"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.confidence_threshold_help')}
              </p>
            </div>
          </div>

          <div className="OpenAIContentAuditSettingsPage-section">
            <h3>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.behavior_section')}</h3>
            
            <div className="Form-group">
              <label className="checkbox">
                <input
                  type="checkbox"
                  bidi={this.setting('ghostchu.openaicontentaudit.pre_approve_enabled')}
                />
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.pre_approve_enabled')}
              </label>
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.pre_approve_enabled_help')}
              </p>
            </div>

            <div className="Form-group">
              <label className="checkbox">
                <input
                  type="checkbox"
                  bidi={this.setting('ghostchu.openaicontentaudit.download_images')}
                />
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.download_images')}
              </label>
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.download_images_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.suspend_days')}</label>
              <input
                className="FormControl"
                type="number"
                min="1"
                max="365"
                bidi={this.setting('ghostchu.openaicontentaudit.suspend_days')}
                placeholder="7"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.suspend_days_help')}
              </p>
            </div>
          </div>

          <div className="OpenAIContentAuditSettingsPage-section">
            <h3>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_section')}</h3>
            
            <div className="Form-group">
              <label className="checkbox">
                <input
                  type="checkbox"
                  bidi={this.setting('ghostchu-openai-content-audit.upload_audit_enabled')}
                />
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_enabled')}
              </label>
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_enabled_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_image_max_size')}</label>
              <input
                className="FormControl"
                type="number"
                min="1"
                max="100"
                bidi={this.setting('ghostchu-openai-content-audit.upload_audit_image_max_size')}
                placeholder="10"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_image_max_size_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_text_max_size')}</label>
              <input
                className="FormControl"
                type="number"
                min="1"
                max="1024"
                bidi={this.setting('ghostchu-openai-content-audit.upload_audit_text_max_size')}
                placeholder="64"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.upload_audit_text_max_size_help')}
              </p>
            </div>
          </div>

          <div className="OpenAIContentAuditSettingsPage-section">
            <h3>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.defaults_section')}</h3>
            
            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_display_name')}</label>
              <input
                className="FormControl"
                type="text"
                bidi={this.setting('ghostchu.openaicontentaudit.default_display_name')}
                placeholder="User"
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_display_name_help')}
              </p>
            </div>

            <div className="Form-group">
              <label>{app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_bio')}</label>
              <input
                className="FormControl"
                type="text"
                bidi={this.setting('ghostchu.openaicontentaudit.default_bio')}
                placeholder=""
              />
              <p className="helpText">
                {app.translator.trans('ghostchu-openai-content-audit.admin.settings.default_bio_help')}
              </p>
            </div>
          </div>

          <div className="Form-group">
            {Button.component(
              {
                type: 'submit',
                className: 'Button Button--primary',
                loading: this.loading,
              },
              app.translator.trans('core.admin.basics.submit_button')
            )}
          </div>
        </div>
      </div>
    );
  }
}
