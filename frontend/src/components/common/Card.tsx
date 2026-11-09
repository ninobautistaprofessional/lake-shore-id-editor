/**
 * Lake Shore Colleges ID Management System
 * Reusable Card / Panel Component
 */

// @ts-ignore React type declarations are unavailable in this project setup.
import * as React from 'react';

type ReactNode = any;

interface CardProps {
  title?: string;
  children: ReactNode;
  className?: string;
  action?: ReactNode;
}

export const Card = ({ title, children, className = '', action }: CardProps) =>
  React.createElement(
    'div',
    { className: `card ${className}` },
    (title || action) &&
      React.createElement(
        'div',
        { className: 'card-header' },
        React.createElement('h2', null, title),
        action,
      ),
    React.createElement('div', { className: 'card-body' }, children),
  );