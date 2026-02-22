import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';

export default class ContentViolationNotification extends Notification {
  icon() {
    return 'fas fa-exclamation-triangle';
  }

  href() {
    // Link to forum home page
    // In the future, this could link to audit log details page
    return app.route('index');
  }

  content() {
    return app.translator.trans(
      'ghostchu-openai-content-audit.forum.notifications.content_violation_text',
      { user: this.attrs.notification.fromUser() }
    );
  }

  excerpt() {
    const notification = this.attrs.notification;
    const subject = notification.subject();
    
    if (subject && subject.data) {
      // Try to get conclusion from the subject data
      const data = subject.data.attributes || subject.data;
      const conclusion = data.conclusion || '';
      
      // Truncate to a reasonable length
      return conclusion.length > 200 
        ? conclusion.substring(0, 200) + '...' 
        : conclusion;
    }
    
    return '';
  }
}
