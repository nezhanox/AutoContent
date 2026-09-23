import { HTMLAttributes } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../lib/utils';

const badgeVariants = cva('inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium', {
    variants: {
        variant: {
            default: 'bg-console-accent-soft text-console-accent',
            success: 'bg-console-success-soft text-console-success',
            warning: 'bg-console-warning-soft text-console-warning',
            danger: 'bg-console-danger-soft text-console-danger',
        },
    },
    defaultVariants: {
        variant: 'default',
    },
});

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
