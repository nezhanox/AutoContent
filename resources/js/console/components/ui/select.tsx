import { SelectHTMLAttributes, forwardRef } from 'react';
import { cn } from '../../lib/utils';

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, ...props }, ref) => (
        <select
            ref={ref}
            className={cn('w-full rounded-md border border-gray-300 px-3 py-2 text-sm', className)}
            {...props}
        />
    ),
);
Select.displayName = 'Select';
