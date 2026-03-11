import Extend from 'flarum/common/extenders';
import AuditLog from './models/AuditLog';

export default [
  // Register the AuditLog model with Flarum's data store.
  // Using the Extender pattern (Flarum 2.x recommended) rather than
  // directly assigning app.store.models['audit-logs'] in an initializer.
  new Extend.Store({
    models: {
      'audit-logs': AuditLog,
    },
  }),
];
