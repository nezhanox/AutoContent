import { SelectHTMLAttributes, forwardRef } from 'react';
import { cn } from '../../lib/utils';

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, ...props }, ref) => (
        <select
            ref={ref}
            className={cn(
                'w-full rounded-lg border border-console-border bg-white px-3 py-2 text-sm text-console-text focus:border-console-accent focus:outline-none focus:ring-2 focus:ring-console-accent-soft',
                className,
            )}
            {...props}
        />
    ),
);
Select.displayName = 'Select';
