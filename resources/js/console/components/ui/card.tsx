import { HTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('rounded-lg border bg-white shadow-sm', className)} {...props} />;
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('border-b px-4 py-3', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h3 className={cn('text-sm font-semibold text-gray-900', className)} {...props} />;
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('p-4', className)} {...props} />;
}
