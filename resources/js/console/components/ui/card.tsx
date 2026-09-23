import { HTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn('rounded-2xl border border-console-border bg-console-surface shadow-[0_1px_2px_rgba(28,31,55,0.04)]', className)}
            {...props}
        />
    );
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex items-center justify-between border-b border-console-border px-5 py-4', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h3 className={cn('text-sm font-semibold text-console-text', className)} {...props} />;
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('p-5', className)} {...props} />;
}
