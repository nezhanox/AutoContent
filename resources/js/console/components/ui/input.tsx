import { InputHTMLAttributes, forwardRef } from 'react';
import { cn } from '../../lib/utils';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
    ({ className, ...props }, ref) => (
        <input
            ref={ref}
            className={cn(
                'w-full rounded-lg border border-console-border bg-white px-3 py-2 text-sm text-console-text placeholder:text-console-text-muted focus:border-console-accent focus:outline-none focus:ring-2 focus:ring-console-accent-soft',
                className,
            )}
            {...props}
        />
    ),
);
Input.displayName = 'Input';
