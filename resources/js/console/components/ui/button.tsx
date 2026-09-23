import { ButtonHTMLAttributes, forwardRef } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../../lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 rounded-lg text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-console-accent text-white hover:bg-console-accent-hover',
                outline: 'border border-console-border bg-white text-console-text hover:bg-console-bg',
                ghost: 'text-console-text-muted hover:bg-console-bg hover:text-console-text',
                destructive: 'bg-console-danger text-white hover:bg-red-700',
            },
            size: {
                default: 'h-10 px-4',
                sm: 'h-8 px-3 text-xs',
                icon: 'h-9 w-9',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof buttonVariants> {}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, ...props }, ref) => (
        <button ref={ref} className={cn(buttonVariants({ variant, size }), className)} {...props} />
    ),
);
Button.displayName = 'Button';
