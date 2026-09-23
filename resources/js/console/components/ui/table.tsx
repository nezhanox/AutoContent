import { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
    return (
        <div className="w-full overflow-x-auto">
            <table className={cn('w-full text-left text-sm', className)} {...props} />
        </div>
    );
}

export function TableHead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return (
        <thead
            className={cn('border-b border-console-border text-xs font-medium text-console-text-muted', className)}
            {...props}
        />
    );
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('divide-y divide-console-border', className)} {...props} />;
}

export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('transition-colors hover:bg-console-bg/60', className)} {...props} />;
}

export function TableHeadCell({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return <th className={cn('px-3 py-3 font-medium', className)} {...props} />;
}

export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-3 py-3 text-console-text', className)} {...props} />;
}
