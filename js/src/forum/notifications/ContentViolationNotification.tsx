import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

export default class ContentViolationNotification extends Notification {
  icon() {
    return 'fas fa-exclamation-triangle';
  }

  href() {
    // Link to forum home page
    return app.route('index');
  }

  content() {
    return app.translator.trans(
      'ghostchu-openai-content-audit.forum.notifications.content_violation_text'
    );
  }

  excerpt() {
    const notification = this.attrs.notification;
    
    // Get content from notification attributes
    const content = notification.attribute('content');
    
    if (content) {
      const conclusion = content.conclusion || '';
      const contentType = content.contentType || '';
      const confidence = content.confidence ? (parseFloat(content.confidence) * 100).toFixed(1) + '%' : '';
      
      // Build excerpt text
      let excerptText = '';
      if (contentType) {
        const typeKey = 'ghostchu-openai-content-audit.email.content_type.' + contentType;
        excerptText += app.translator.trans(typeKey, {}, contentType) + ': ';
      }
      if (conclusion) {
        excerptText += conclusion;
      }
      if (confidence) {
        excerptText += ' (' + confidence + ')';
      }
      
      // Truncate to a reasonable length
      return excerptText.length > 200 
        ? excerptText.substring(0, 200) + '...' 
        : excerptText;
    }
    
    return '';
  }
}
