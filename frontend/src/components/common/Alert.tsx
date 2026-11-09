/**
 * Lake Shore Colleges ID Management System
 * Reusable Alert / Notification Component
 */

import * as React from 'react';

interface AlertProps {
  type: 'success' | 'error' | 'warning' | 'info';
  message: React.ReactNode;
  onDismiss?: () => void;
  dismissible?: boolean;
}

export const Alert = ({
  type,
  message,
  onDismiss,
  dismissible = false,
}: AlertProps) => {
  const typeClasses = {
    success: 'alert-success',
    error: 'alert-error',
    warning: 'alert-warning',
    info: 'alert-info',
  };

  return React.createElement(
    'div',
    { className: `alert ${typeClasses[type]}`, role: 'alert' },
    dismissible && onDismiss
      ? React.createElement(
          'button',
          { className: 'alert-dismiss', onClick: onDismiss, 'aria-label': 'Dismiss' },
          '\u00d7',
        )
      : null,
    React.createElement('span', null, message),
  );
};