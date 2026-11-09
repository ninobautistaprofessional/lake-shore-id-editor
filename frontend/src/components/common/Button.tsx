/**
 * Lake Shore Colleges ID Management System
 * Reusable Button Component
 */

import * as React from 'react';

interface ButtonProps {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  loading?: boolean;
  children: any;
  disabled?: boolean;
  className?: string;
  [key: string]: any;
}

export const Button = ({
  variant = 'primary',
  size = 'md',
  loading = false,
  children,
  disabled,
  className = '',
  ...props
}: ButtonProps) => {
  const baseClasses = 'btn';
  const variantClasses = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    danger: 'btn-danger',
    ghost: 'btn-ghost',
  };
  const sizeClasses = {
    sm: 'btn-sm',
    md: 'btn-md',
    lg: 'btn-lg',
  };

  return React.createElement(
    'button',
    {
      ...props,
      className: `${baseClasses} ${variantClasses[variant]} ${sizeClasses[size]} ${className}`,
      disabled: disabled || loading,
    },
    loading ? React.createElement('span', { className: 'btn-spinner' }) : children,
  );
};