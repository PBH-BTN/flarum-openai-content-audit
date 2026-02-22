import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

interface ContentViolationData {
  contentType: string;
  confidence: string;
  conclusion: string;
}

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
    const content = notification.content<ContentViolationData>();
    
    if (content) {
      const conclusion = content.conclusion || '';
      const contentType = content.contentType || '';
      
      // Build excerpt text
      let excerptText = '';
      if (contentType) {
        // Try to get translation from email.content_type first, fallback to raw type
        const typeKey = 'ghostchu-openai-content-audit.email.content_type.' + contentType;
        const translatedType = app.translator.trans(typeKey);
        
        // Flarum translator returns an array if the key is not found or has components
        // We check if it's a string and not equal to the key itself
        const typeStr = typeof translatedType === 'string' && translatedType !== typeKey 
          ? translatedType 
          : (Array.isArray(translatedType) && translatedType.length > 0 && typeof translatedType[0] === 'string' && translatedType[0] !== typeKey ? translatedType[0] : contentType);
          
        excerptText += typeStr + ': ';
      }
      if (conclusion) {
        excerptText += conclusion;
      }
      
      // Truncate to a reasonable length
      return excerptText.length > 200 
        ? excerptText.substring(0, 200) + '...' 
        : excerptText;
    }
    
    return '';
  }
}
